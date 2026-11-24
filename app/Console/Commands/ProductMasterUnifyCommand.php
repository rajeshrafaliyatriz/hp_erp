<?php

namespace App\Console\Commands;

use App\Domain\ProductMaster\ProductIntegrity;
use App\Domain\ProductMaster\ProductMasterInvalid;
use App\Domain\ProductMaster\ProductMatchReport;
use App\Domain\ProductMaster\ProductMatchSuggester;
use App\Domain\ProductMaster\ProductMerger;
use App\Domain\ProductMaster\ProductUnifySnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The pair review for the owner (slice 15, track P2-D): which catalogue items and Portfolio Sub-products are the same product, and the merge of the pairs the owner confirms.
 *
 *   php artisan product-master:unify --tenant=6                     DRY RUN (the default): prints and saves the report; writes nothing, the session is made read-only
 *   php artisan product-master:unify --tenant=6 --map=pairs.json    checks every pair of the owner's file (the same refusals as the merge) and writes nothing
 *   php artisan product-master:unify --tenant=6 --map=pairs.json --apply
 *                                                                   merges ONLY the pairs in the file, one transaction per pair, after saving a snapshot of every row it will touch
 *   php artisan product-master:unify --tenant=6 --rollback=<snapshot.json>
 *                                                                   puts those rows back exactly as they were, removes the decisions the apply made, runs the checker
 *
 * A suggestion is never merged on its own: `--apply` without `--map` does nothing and says so. The map file is the owner's confirmation: JSON (a list of
 * {"item": <item master id>, "survivor": <Sub-product master id>, "name": "portfolio"|"catalogue"}, or {"pairs": [...]}) or CSV with the header item,survivor,name. The name is the
 * one the merged record keeps (the Portfolio's by default).
 *
 * Without --database only the dev host (or the sqlite test database) is used; any other connection must be named, and the command then runs on that connection alone.
 * Exit code: 0 done (or nothing to do), 1 a pair was refused or failed, the checker found an error, or an undo was refused, 2 the command could not run (bad option, refused host,
 * missing file).
 */
class ProductMasterUnifyCommand extends Command
{
    /** The pairs one run may merge: a map is a handful of decisions, not a batch job. */
    public const MAX_PAIRS = 200;

    protected $signature = 'product-master:unify
        {--tenant= : One organisation (sub_institute_id). Required with --apply and --rollback; every organisation with live catalogue items when omitted on a dry run}
        {--database= : Connection name. Without it only the dev connection is used; any other host must be named here}
        {--map= : JSON or CSV file of the pairs the owner confirmed (item master id, survivor master id, name to keep). Alone it only checks; with --apply it merges}
        {--apply : Merge the pairs named in --map (never a suggestion on its own)}
        {--rollback= : A snapshot file written by an --apply run: put every row it saved back and remove the decisions it made}
        {--report= : Where to save the report or summary (default: storage/app/product-master-unify/); never overwrites a file}';

    protected $description = 'Dry run by default: suggest which CRM catalogue items and Portfolio Sub-products are the same product. --apply --map merges only the pairs the owner confirmed; --rollback undoes a run.';

    /** @var list<string> every line printed, for the saved summary */
    private array $log = [];

    public function handle(): int
    {
        $explicit = $this->option('database');
        $explicit = is_string($explicit) && $explicit !== '' ? $explicit : null;

        $tenant = null;
        $tenantOption = $this->option('tenant');
        if ($tenantOption !== null && $tenantOption !== '') {
            if (! ctype_digit((string) $tenantOption) || (int) $tenantOption <= 0) {
                return $this->refuse('--tenant must be a whole number above 0 (an organisation id).');
            }
            $tenant = (int) $tenantOption;
        }

        $refusal = ProductMasterCheckCommand::refusal($explicit, (string) config('database.default'), (array) config('database.connections'));
        if ($refusal !== null) {
            return $this->refuse($refusal);
        }

        $apply = (bool) $this->option('apply');
        $rollback = $this->path('rollback');
        $map = $this->path('map');
        if ($apply && $rollback !== null) {
            return $this->refuse('--apply and --rollback are two different runs: do one, check, then the other.');
        }
        if ($rollback !== null && $map !== null) {
            return $this->refuse('--rollback restores a snapshot; it takes no --map.');
        }
        if ($apply && $map === null) {
            return $this->refuse('--apply needs --map=<file>: a suggestion is never merged on its own, only the pairs the owner confirmed in a file. Nothing was done.');
        }
        if (($apply || $rollback !== null) && $tenant === null && $rollback === null) {
            return $this->refuse('--apply needs --tenant=<organisation>: a merge is one organisation at a time.');
        }

        // The place the report or summary will go is checked BEFORE anything is read or written, so a merge is never done and then reported nowhere.
        $reportPath = $this->path('report');
        if ($reportPath !== null) {
            if (file_exists($reportPath)) {
                return $this->refuse("Refusing to overwrite {$reportPath}: delete the file first if you mean to replace it.");
            }
            $reportDir = dirname($reportPath);
            if ((! is_dir($reportDir) && ! @mkdir($reportDir, 0775, true) && ! is_dir($reportDir)) || ! is_writable($reportDir)) {
                return $this->refuse("The folder {$reportDir} cannot be written, so the report could not be saved there. Nothing was done.");
            }
        }

        // The audit event and every facade use the default connection: a named connection becomes the default for this run, and only for it. The command may be called in
        // the middle of another process (Artisan::call, a test), so the default is put back whatever happens.
        $previous = DB::getDefaultConnection();
        if ($explicit !== null) {
            DB::setDefaultConnection($explicit);
        }
        try {
            if ($rollback !== null) {
                return $this->undo($explicit, $tenant, $rollback, $reportPath);
            }
            if ($map !== null) {
                return $this->applyMap($explicit, (int) $tenant, $map, $apply, $reportPath);
            }

            return $this->dryRun($explicit, $tenant, $reportPath);
        } catch (\Throwable $e) {
            return $this->refuse('The command stopped: ' . $e->getMessage());
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    // ------------------------------------------------------------------ the dry run

    private function dryRun(?string $connection, ?int $tenant, ?string $reportPath): int
    {
        $integrity = new ProductIntegrity($connection, $tenant);
        $session = $integrity->lockSessionReadOnly();
        $info = $integrity->connectionInfo();

        $tenants = $tenant !== null ? [$tenant] : $this->tenantsWithItems($connection);
        $suggester = new ProductMatchSuggester($connection);
        $all = [];
        foreach ($tenants as $t) {
            $all[] = $suggester->suggest($t);
        }

        $text = ProductMatchReport::render($all, [
            'mode' => 'DRY RUN (nothing is written to any table)', 'connection' => $info['name'], 'driver' => $info['driver'], 'host' => $info['host'], 'database' => $info['database'],
            'session' => $session, 'generated' => now()->toIso8601String(),
        ]);
        $this->line($text);
        $path = $reportPath ?? $this->folder() . '/report-' . ($tenant !== null ? 't' . $tenant : 'all') . '-' . $this->stamp() . '.txt';
        $this->saveText($path, $text);
        $this->info('Report saved to ' . $path);
        if ($tenants === []) {
            $this->line('No organisation has a live catalogue item, so there is nothing to compare.');
        }

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function tenantsWithItems(?string $connection): array
    {
        $db = DB::connection($connection);
        if (! \Illuminate\Support\Facades\Schema::connection($db->getName())->hasTable('g2g_product_master')) {
            return [];
        }

        return $db->table('g2g_product_master')->where('kind', 'item')->where('sub_institute_id', '>', 0)->distinct()->orderBy('sub_institute_id')->pluck('sub_institute_id')->map(fn ($v) => (int) $v)->all();
    }

    // ------------------------------------------------------------------ check the map, or apply it

    private function applyMap(?string $connection, int $tenant, string $mapPath, bool $apply, ?string $reportPath): int
    {
        $pairs = self::readMap($mapPath, $problem);
        if ($pairs === null) {
            return $this->refuse($problem);
        }

        $integrity = new ProductIntegrity($connection, $tenant);
        if (! $apply) {
            $integrity->lockSessionReadOnly();   // checking a map writes nothing: the server is asked to refuse a write as well
        }
        $info = $integrity->connectionInfo();
        $this->say(sprintf('product-master:unify - %s', $apply ? 'APPLY (merging the pairs of the map)' : 'CHECK OF THE MAP (nothing is written)'));
        $this->say(sprintf('Connection %s (%s), host %s, database %s, organisation %d, map %s (%d pair(s))', $info['name'], $info['driver'], $info['host'] ?? '-', $info['database'] ?? '-', $tenant, $mapPath, count($pairs)));

        // 1. every pair is checked first, and one refusal stops the whole file before anything is written (not even the snapshot)
        $merger = new ProductMerger($connection);
        $plans = [];
        $refused = [];
        foreach ($pairs as $i => $p) {
            try {
                $plans[$i] = $merger->plan($tenant, $p['item'], $p['survivor'], $p['name']);
                $this->say(sprintf('  pair %d: item %d "%s" and Sub-product %d -> %s (name kept: %s, "%s")', $i + 1, $p['item'], $this->itemName($connection, $p['item']), $p['survivor'], $plans[$i]['alreadyMerged'] ? 'already merged, nothing to do' : 'ok to merge', $p['name'], (string) $plans[$i]['name']));
            } catch (ProductMasterInvalid $e) {
                $refused[] = sprintf('pair %d (item %d, Sub-product %d): %s', $i + 1, $p['item'], $p['survivor'], $e->getMessage());
            }
        }
        if ($refused !== []) {
            foreach ($refused as $line) {
                $this->say('  REFUSED ' . $line);
            }
            $this->say('Nothing was written: fix the map file and run it again.');
            $this->saveSummary($reportPath, $tenant, 'map-check');

            return self::FAILURE;
        }
        if (! $apply) {
            $this->say('Every pair can be merged. Nothing was written. Add --apply to merge them.');
            $this->saveSummary($reportPath, $tenant, 'map-check');

            return self::SUCCESS;
        }

        // 2. the snapshot of every row the run will touch, saved BEFORE the first write
        $snap = new ProductUnifySnapshot($connection);
        $snapshotPath = $this->folder() . '/snapshot-t' . $tenant . '-' . $this->stamp() . '.json';
        $snapshot = $snap->capture($tenant, array_values($plans), [
            'taken_at' => now()->toIso8601String(), 'connection' => $info['name'], 'host' => $info['host'], 'database' => $info['database'], 'map' => $mapPath, 'command' => 'product-master:unify --apply',
        ]);
        ProductUnifySnapshot::write($snapshotPath, $snapshot);
        $this->say('Snapshot of every row the run will touch saved to ' . $snapshotPath . ' (undo with --rollback=' . $snapshotPath . ')');

        // 3. one transaction per pair; a pair that fails leaves the others alone
        $failed = 0;
        $pairsOut = array_values($snapshot['pairs']);
        foreach ($pairs as $i => $p) {
            try {
                $r = $merger->merge(['sub_institute_id' => $tenant], $p['item'], $p['survivor'], $p['name']);
            } catch (ProductMasterInvalid $e) {
                $failed++;
                $pairsOut[$i]['result'] = 'failed';
                $this->say(sprintf('  FAILED  pair %d (item %d, Sub-product %d): %s', $i + 1, $p['item'], $p['survivor'], $e->getMessage()));
                ProductUnifySnapshot::write($snapshotPath, ['pairs' => $pairsOut] + $snapshot, true);

                continue;
            }
            $pairsOut[$i]['result'] = $r['result'];
            if ($r['result'] === 'merged') {
                $pairsOut[$i]['applied'] = true;
                $pairsOut[$i]['after'] = $snap->fingerprints($tenant, $pairsOut[$i]);
                $moved = array_sum($r['moved']);
                $this->say(sprintf('  MERGED  pair %d: item %d is now the husk of Sub-product %d; the record is named "%s"; %d pointer row(s) moved, %d claim(s) retired; audit: %s', $i + 1, $p['item'], $p['survivor'], $r['name'], $moved, $r['claims']['retired'], $r['audit'] ?? '-'));
            } else {
                $this->say(sprintf('  ALREADY pair %d: item %d was already merged into Sub-product %d; nothing written', $i + 1, $p['item'], $p['survivor']));
            }
            ProductUnifySnapshot::write($snapshotPath, ['pairs' => $pairsOut] + $snapshot, true);
        }

        // 4. the checker, the whole organisation
        $check = $integrity->check();
        $this->say(sprintf('Checker (organisation %d): %d error(s), %d warning(s).', $tenant, $check['errors'], $check['warnings']));
        foreach (array_slice($check['findings'], 0, 10) as $f) {
            $this->say('  [' . $f['code'] . '] ' . $f['message']);
        }
        $ok = $failed === 0 && $check['errors'] === 0;
        $this->say($ok ? 'RESULT: OK' : sprintf('RESULT: FAILED (%d pair(s) failed, %d checker error(s))', $failed, $check['errors']));
        $this->saveSummary($reportPath, $tenant, 'apply');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    // ------------------------------------------------------------------ the way back

    private function undo(?string $connection, ?int $tenant, string $snapshotPath, ?string $reportPath): int
    {
        $snapshot = ProductUnifySnapshot::read($snapshotPath, $problem);
        if ($snapshot === null) {
            return $this->refuse($problem);
        }
        $fileTenant = (int) $snapshot['tenant'];
        if ($tenant !== null && $tenant !== $fileTenant) {
            return $this->refuse("The snapshot was taken for organisation {$fileTenant} but --tenant asks for {$tenant}. Compare like with like.");
        }
        $tenant = $fileTenant;

        $integrity = new ProductIntegrity($connection, $tenant);
        $info = $integrity->connectionInfo();
        $this->say('product-master:unify - ROLLBACK of ' . $snapshotPath);
        $this->say(sprintf('Connection %s (%s), host %s, database %s, organisation %d', $info['name'], $info['driver'], $info['host'] ?? '-', $info['database'] ?? '-', $tenant));

        try {
            $done = (new ProductUnifySnapshot($connection))->restore($snapshot);
        } catch (ProductMasterInvalid $e) {
            $this->say('REFUSED: ' . $e->getMessage());
            foreach (array_slice($e->errors['rows'] ?? [], 0, 20) as $line) {
                $this->say('  ' . $line);
            }
            $this->say('Nothing was undone.');
            $this->saveSummary($reportPath, $tenant, 'rollback');

            return self::FAILURE;
        }

        $merger = new ProductMerger($connection);
        foreach ($done['undone'] as $u) {
            $this->say(sprintf('  UNDONE  item %d / Sub-product %d: %d row(s) put back exactly as they were; the decision row is gone', $u['itemMasterId'], $u['survivorMasterId'], $u['rows']));
            $merger->emit(ProductMerger::EVENT_UNDONE, $tenant, null, $u['survivorMasterId'], ['itemMasterId' => $u['itemMasterId'], 'survivorMasterId' => $u['survivorMasterId'], 'snapshot' => basename($snapshotPath), 'via' => 'command'], ProductMerger::EVENT_UNDONE . ':' . $tenant . ':' . $u['itemMasterId'] . ':' . $u['survivorMasterId'] . ':' . $this->stamp());
        }
        foreach ($done['skipped'] as $s) {
            $this->say(sprintf('  SKIPPED item %d / Sub-product %d: %s', $s['itemMasterId'], $s['survivorMasterId'], $s['why']));
        }
        $check = $integrity->check();
        $this->say(sprintf('Checker (organisation %d): %d error(s), %d warning(s).', $tenant, $check['errors'], $check['warnings']));
        foreach (array_slice($check['findings'], 0, 10) as $f) {
            $this->say('  [' . $f['code'] . '] ' . $f['message']);
        }
        $this->say($check['errors'] === 0 ? 'RESULT: OK' : sprintf('RESULT: FAILED (%d checker error(s))', $check['errors']));
        $this->saveSummary($reportPath, $tenant, 'rollback');

        return $check['errors'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    // ------------------------------------------------------------------ the map file

    /**
     * The pairs of a map file, or null with the reason in $problem. JSON (a list, or {"pairs": [...]}) or CSV with a header.
     *
     * @return ?list<array{item: int, survivor: int, name: string}>
     */
    public static function readMap(string $path, ?string &$problem = null): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            $problem = "The map file {$path} does not exist or cannot be read.";

            return null;
        }
        if (filesize($path) > 1_000_000) {
            $problem = "The map file {$path} is over 1 MB: a map is a handful of confirmed pairs.";

            return null;
        }
        $raw = trim((string) file_get_contents($path));
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $rows = [];
        if (str_starts_with($raw, '[') || str_starts_with($raw, '{')) {
            try {
                $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $problem = "The map file {$path} is not valid JSON: " . $e->getMessage();

                return null;
            }
            $rows = is_array($data) && isset($data['pairs']) && is_array($data['pairs']) ? $data['pairs'] : (is_array($data) ? $data : []);
        } else {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: []), fn (string $l): bool => $l !== ''));
            if (count($lines) < 2) {
                $problem = "The map file {$path} needs a header line (item,survivor,name) and at least one pair.";

                return null;
            }
            $head = array_map(fn ($h) => strtolower(trim((string) $h)), str_getcsv($lines[0], str_contains($lines[0], ';') ? ';' : ','));
            foreach (array_slice($lines, 1) as $line) {
                $cells = str_getcsv($line, str_contains($lines[0], ';') ? ';' : ',');
                $rows[] = array_combine($head, array_pad(array_slice($cells, 0, count($head)), count($head), '')) ?: [];
            }
        }

        if ($rows === []) {
            $problem = "The map file {$path} holds no pair.";

            return null;
        }
        if (count($rows) > self::MAX_PAIRS) {
            $problem = "The map file {$path} holds " . count($rows) . ' pairs; a run merges at most ' . self::MAX_PAIRS . '.';

            return null;
        }
        $pairs = [];
        $items = [];
        $survivors = [];
        foreach (array_values($rows) as $i => $row) {
            $n = $i + 1;
            if (! is_array($row)) {
                $problem = "Pair {$n} of {$path} is not an object.";

                return null;
            }
            $item = self::pick($row, ['item', 'itemmasterid', 'item_master_id']);
            $survivor = self::pick($row, ['survivor', 'survivormasterid', 'survivor_master_id']);
            $name = strtolower(trim((string) (self::pick($row, ['name', 'namekept', 'name_kept', 'keep']) ?? '')));
            if ($item === null || $survivor === null || ! preg_match('/^\d+$/', (string) $item) || ! preg_match('/^\d+$/', (string) $survivor) || (int) $item <= 0 || (int) $survivor <= 0) {
                $problem = "Pair {$n} of {$path} needs an item master id and a survivor master id (whole numbers above 0).";

                return null;
            }
            $name = $name === '' ? ProductMerger::NAME_PORTFOLIO : $name;
            if (! in_array($name, [ProductMerger::NAME_PORTFOLIO, ProductMerger::NAME_CATALOGUE], true)) {
                $problem = "Pair {$n} of {$path}: the name to keep is 'portfolio' or 'catalogue', not '{$name}'.";

                return null;
            }
            if ((int) $item === (int) $survivor) {
                $problem = "Pair {$n} of {$path}: an item cannot be merged with itself.";

                return null;
            }
            if (isset($items[(int) $item]) || isset($survivors[(int) $survivor])) {
                $problem = "Pair {$n} of {$path} repeats " . (isset($items[(int) $item]) ? "item {$item}" : "Sub-product {$survivor}") . ': a merge is one item into one Sub-product.';

                return null;
            }
            $items[(int) $item] = true;
            $survivors[(int) $survivor] = true;
            $pairs[] = ['item' => (int) $item, 'survivor' => (int) $survivor, 'name' => $name];
        }

        return $pairs;
    }

    /** @param array<string, mixed> $row @param list<string> $keys */
    private static function pick(array $row, array $keys): mixed
    {
        foreach ($row as $k => $v) {
            if (in_array(strtolower((string) $k), $keys, true) && $v !== null && $v !== '') {
                return is_scalar($v) ? $v : null;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ plumbing

    private function itemName(?string $connection, int $itemMasterId): string
    {
        return (string) (DB::connection($connection)->table('g2g_product_master')->where('id', $itemMasterId)->value('name') ?? '?');
    }

    private function path(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function folder(): string
    {
        return rtrim((string) config('product-master.unify_folder', storage_path('app/product-master-unify')), '/\\');
    }

    private function stamp(): string
    {
        return now()->format('Ymd-His') . '-' . bin2hex(random_bytes(2));
    }

    private function say(string $line): void
    {
        $this->log[] = $line;
        $this->line($line);
    }

    /** The summary of an apply, a check or an undo. Saving it never turns a merge that is done into an error: it is said, not thrown. */
    private function saveSummary(?string $reportPath, int $tenant, string $kind): void
    {
        $path = $reportPath ?? $this->folder() . '/' . $kind . '-t' . $tenant . '-' . $this->stamp() . '.txt';
        try {
            $this->saveText($path, implode("\n", $this->log) . "\n");
            $this->line('Summary saved to ' . $path);
        } catch (\Throwable $e) {
            $this->warn('The summary could not be saved (' . $e->getMessage() . '). What was done is printed above.');
        }
    }

    private function saveText(string $path, string $text): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("The folder {$dir} cannot be created.");
        }
        if (@file_put_contents($path, $text, LOCK_EX) === false) {
            throw new \RuntimeException("Could not write {$path}.");
        }
    }

    private function refuse(?string $message): int
    {
        $this->error((string) $message);

        return self::INVALID;
    }
}
