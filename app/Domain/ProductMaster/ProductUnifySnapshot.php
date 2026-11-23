<?php

namespace App\Domain\ProductMaster;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * The rows `product-master:unify --apply` is about to change, saved in full BEFORE the first write, and the way back (`--rollback=<snapshot>`) (slice 15, track P2-D).
 *
 * A snapshot is one JSON file in a folder outside git (storage/app/product-master-unify/). For every pair of the owner's map it holds, in full (every column, so
 * `updated_at` too): the two master rows, the catalogue row, the Portfolio offer, every row at the husk's id in the nine pointer tables, and the decision row if the pair
 * already had one. After the pair is merged the command adds a fingerprint of each of those rows as the merge left it.
 *
 * Undoing is deliberately strict, because it overwrites rows:
 *   - a pair is only undone if it was APPLIED (it has fingerprints) and every row it touched is STILL exactly as the merge left it. A row somebody has changed since (a Sub-product
 *     renamed, a claim approved, a lead repointed) would be overwritten by the old values, so the whole undo is refused and the rows that changed are listed.
 *   - the rows are written back with every column, the decision row the merge made is deleted (or put back as it was), all in one transaction for the whole file;
 *   - and the transaction ends by comparing every restored row with the saved one: if one differs by a single byte it is taken back.
 * Rows that were never touched, and rows created after the merge (a new quote line for the catalogue row), are not looked at.
 *
 * Built on the query builder and an explicit connection, MariaDB 10.1 safe.
 */
final class ProductUnifySnapshot
{
    public const FORMAT = 'product-master-unify/1';

    /** Where the snapshot of a decision row lives in "touched" and "after". */
    public const DECISIONS = 'g2g_product_match_decisions';

    private const CHUNK = 400;

    private readonly Connection $db;

    public function __construct(private readonly ?string $connection = null)
    {
        $this->db = DB::connection($connection);
    }

    // ------------------------------------------------------------------ capture

    /**
     * The rows the plans will touch, read in full.
     *
     * @param list<array<string, mixed>> $plans the result of ProductMerger::plan() for each pair of the map (it holds `touched`, `decisionId`, `nameKept`, ...)
     * @param array<string, mixed> $meta what the command knows about this run (host, database, who, when, the map file)
     * @return array<string, mixed>
     */
    public function capture(int $tenant, array $plans, array $meta): array
    {
        $pairs = [];
        foreach ($plans as $plan) {
            $rows = [];
            foreach ($plan['touched'] as $table => $ids) {
                $rows[$table] = $this->fetch($table, array_values(array_unique(array_map('intval', $ids))));
            }
            $decision = $plan['decisionId'] !== null ? ($this->fetch(self::DECISIONS, [(int) $plan['decisionId']])[(int) $plan['decisionId']] ?? null) : null;
            $pairs[] = [
                'itemMasterId' => (int) $plan['itemMasterId'], 'survivorMasterId' => (int) $plan['survivorMasterId'], 'nameKept' => (string) $plan['nameKept'],
                'alreadyMerged' => (bool) $plan['alreadyMerged'], 'rows' => $rows, 'decisionBefore' => $decision,
                'applied' => false, 'result' => null, 'after' => null,
            ];
        }

        return ['format' => self::FORMAT, 'tenant' => $tenant, 'meta' => $meta, 'pairs' => $pairs];
    }

    /**
     * What the rows of a pair look like NOW, as fingerprints: table => id => md5 of the row, plus the decision row of the pair.
     *
     * @param array<string, mixed> $pair a pair of a snapshot
     * @return array<string, array<int|string, string>>
     */
    public function fingerprints(int $tenant, array $pair): array
    {
        $out = [];
        foreach ($pair['rows'] as $table => $rows) {
            $out[$table] = [];
            $now = $this->fetch($table, array_map('intval', array_keys($rows)));
            foreach (array_keys($rows) as $id) {
                $out[$table][(int) $id] = isset($now[(int) $id]) ? self::hash($now[(int) $id]) : 'missing';
            }
        }
        $d = $this->db->table(self::DECISIONS)->where('sub_institute_id', $tenant)->where('item_master_id', $pair['itemMasterId'])->where('survivor_master_id', $pair['survivorMasterId'])->first();
        $out[self::DECISIONS] = ['row' => $d !== null ? self::hash((array) $d) : 'missing'];

        return $out;
    }

    // ------------------------------------------------------------------ the file

    /**
     * Writes a snapshot. The first write refuses to overwrite a file (a snapshot is a baseline); the update that adds the fingerprints replaces it, atomically.
     *
     * @param array<string, mixed> $snapshot
     */
    public static function write(string $path, array $snapshot, bool $replace = false): void
    {
        if (! $replace && file_exists($path)) {
            throw new \RuntimeException("Refusing to overwrite {$path}: a snapshot is the way back.");
        }
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("The folder {$dir} cannot be created.");
        }
        $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) . "\n";
        $tmp = $path . '.tmp' . bin2hex(random_bytes(3));
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException("Could not write {$tmp}.");
        }
        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Could not move the snapshot into place at {$path}.");
        }
    }

    /** @return ?array<string, mixed> a snapshot, or null with the reason in $problem */
    public static function read(string $path, ?string &$problem = null): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            $problem = "The snapshot {$path} does not exist or cannot be read.";

            return null;
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $problem = "The snapshot {$path} is not valid JSON: " . $e->getMessage();

            return null;
        }
        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ! isset($data['tenant'], $data['pairs']) || ! is_array($data['pairs'])) {
            $problem = "The file {$path} is not a snapshot written by product-master:unify (format " . self::FORMAT . ').';

            return null;
        }

        return $data;
    }

    // ------------------------------------------------------------------ the way back

    /**
     * Puts every row of every APPLIED pair back as it was before the merge, all or nothing.
     *
     * @param array<string, mixed> $snapshot
     * @return array{undone: list<array{itemMasterId: int, survivorMasterId: int, rows: int}>, skipped: list<array{itemMasterId: int, survivorMasterId: int, why: string}>}
     *
     * @throws ProductMasterInvalid 409 a row was changed since the merge (listed), or a restored row differs from the saved one
     */
    public function restore(array $snapshot): array
    {
        $tenant = (int) $snapshot['tenant'];
        $todo = [];
        $skipped = [];
        $conflicts = [];
        foreach ($snapshot['pairs'] as $pair) {
            $label = ['itemMasterId' => (int) $pair['itemMasterId'], 'survivorMasterId' => (int) $pair['survivorMasterId']];
            if (empty($pair['applied']) || ! is_array($pair['after'] ?? null)) {
                $skipped[] = $label + ['why' => $pair['result'] === 'already_merged' ? 'it was already merged before this run, so this run did not make it' : 'this run did not merge it'];

                continue;
            }
            $now = $this->fingerprints($tenant, $pair);
            foreach ($pair['after'] as $table => $ids) {
                foreach ($ids as $id => $hash) {
                    if (($now[$table][$id] ?? 'missing') !== $hash) {
                        $conflicts[] = "pair {$label['itemMasterId']} -> {$label['survivorMasterId']}: {$table} row {$id} was changed after the merge";
                    }
                }
            }
            $todo[] = $pair;
        }
        if ($conflicts !== []) {
            throw new ProductMasterInvalid(['rows' => array_slice($conflicts, 0, 20)], 409, 'Nothing was undone: ' . count($conflicts) . ' row(s) changed after the merge, and the old values would overwrite those changes. First: ' . $conflicts[0]);
        }

        $undone = [];
        $this->db->transaction(function () use ($todo, $tenant, &$undone): void {
            foreach ($todo as $pair) {
                $n = 0;
                foreach ($pair['rows'] as $table => $rows) {
                    // Rows of the master table that held NO catalogue pointer before go first: they let go of the slot the others take back.
                    $order = array_keys($rows);
                    if ($table === 'g2g_product_master') {
                        usort($order, fn ($a, $b): int => (int) (($rows[$a]['crm_product_id'] ?? null) !== null) <=> (int) (($rows[$b]['crm_product_id'] ?? null) !== null));
                    }
                    foreach ($order as $id) {
                        $values = $rows[$id];
                        unset($values['id']);
                        $this->db->table($table)->where('id', (int) $id)->update($values);
                        $n++;
                    }
                }
                $q = $this->db->table(self::DECISIONS)->where('sub_institute_id', $tenant)->where('item_master_id', $pair['itemMasterId'])->where('survivor_master_id', $pair['survivorMasterId']);
                if ($pair['decisionBefore'] === null) {
                    $q->delete();
                } else {
                    $values = $pair['decisionBefore'];
                    unset($values['id']);
                    $q->update($values);
                }
                $n++;

                // the way back is byte for byte: every restored row is read again and compared with the saved one
                foreach ($pair['rows'] as $table => $rows) {
                    $now = $this->fetch($table, array_map('intval', array_keys($rows)));
                    foreach ($rows as $id => $saved) {
                        if (! isset($now[(int) $id]) || self::hash($now[(int) $id]) !== self::hash($saved)) {
                            throw new ProductMasterInvalid(['rows' => ["{$table} row {$id} differs from the saved row after it was put back"]], 409, "The undo was taken back: {$table} row {$id} differs from the saved row after it was put back.");
                        }
                    }
                }
                $undone[] = ['itemMasterId' => (int) $pair['itemMasterId'], 'survivorMasterId' => (int) $pair['survivorMasterId'], 'rows' => $n];
            }
        });

        return ['undone' => $undone, 'skipped' => $skipped];
    }

    // ------------------------------------------------------------------ plumbing

    /**
     * Full rows by id, as arrays, keyed by id.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function fetch(string $table, array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach ($this->db->table($table)->whereIn('id', $chunk)->get() as $row) {
                $out[(int) $row->id] = (array) $row;
            }
        }

        return $out;
    }

    /** A row as one fingerprint: every value as text (so 2 and 2.0 are one value), in column order. @param array<string, mixed> $row */
    public static function hash(array $row): string
    {
        ksort($row);

        return md5(json_encode(array_map(fn ($v) => $v === null ? null : (string) $v, $row), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
