<?php

namespace App\Domain\ProductMaster;

use App\Services\Events\EventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The audit lines of ONE write of the one-record writer (slice 15, P2-C, rule 6): every store the write changed gets one line, in the shape the CRM history already
 * reads (`changes`: a list of {field, oldValue, newValue}, the same as HasCrmAuditTrail::recordCrmAudit), filed under the store's own entity type so the History
 * tab of the CRM row and the Portfolio and Product Master histories each find theirs:
 *
 *   crm_products         crm.product.created / crm.product.updated
 *   g2g_product_offers   portfolio.offer.created / portfolio.offer.updated      (About edits were not audited before)
 *   g2g_product_master   product.master.created / product.master.updated
 *
 * How it works: the writer names every row the write may touch BEFORE it writes (watch) or right after it makes one (created); settle() reads each row again inside
 * the transaction and keeps the columns that differ (updated_at and updated_by are bookkeeping, as in recordCrmAudit); flush() hands the lines to the EventRecorder
 * and is registered to run AFTER the commit, so a line is never written for a write that was rolled back. A failure of the recorder is logged and swallowed: the event
 * is a claim about what the rows now look like, made once that is true, never a part of making it true. Reading the rows back (and not building the diff from what the
 * request said) means a copy the mirror wrote, or a column a database default filled, is in the line too.
 */
final class ProductWriteAudit
{
    private const TYPES = ['crm_products' => 'crm.product', 'g2g_product_offers' => 'portfolio.offer', 'g2g_product_master' => 'product.master'];

    /** The bookkeeping columns every write stamps, left out of a diff so they do not drown the real changes. */
    private const IGNORED = ['updated_at', 'updated_by'];

    /** @var array<string, array{table: string, id: int, before: ?array<string, mixed>, changes: list<array{field: string, oldValue: mixed, newValue: mixed}>}> */
    private array $rows = [];

    public function __construct(private readonly int $tenant, private readonly ?int $actor)
    {
    }

    /** A row that exists and may change in this write: its state now. A row that is not there is not watched. */
    public function watch(string $table, ?int $id): void
    {
        if ($id === null || $id <= 0 || isset($this->rows[$table . '#' . $id])) {
            return;
        }
        $before = $this->read($table, $id);
        if ($before !== null) {
            $this->rows[$table . '#' . $id] = ['table' => $table, 'id' => $id, 'before' => $before, 'changes' => []];
        }
    }

    /** A row this write has just made (it has no earlier state). */
    public function created(string $table, int $id): void
    {
        $this->rows[$table . '#' . $id] = ['table' => $table, 'id' => $id, 'before' => null, 'changes' => []];
    }

    /** Reads every watched row again and keeps what differs. Call it inside the transaction, after the last write. */
    public function settle(): void
    {
        foreach ($this->rows as $key => $row) {
            $after = $this->read($row['table'], $row['id']);
            $this->rows[$key]['changes'] = $after === null ? [] : self::diff($row['before'] ?? [], $after);
        }
    }

    /**
     * The lines that will be written: type, entity type, entity id and the changes. For a test and for flush().
     *
     * @return list<array{type: string, entityType: string, entityId: int, changes: list<array{field: string, oldValue: mixed, newValue: mixed}>}>
     */
    public function lines(): array
    {
        $lines = [];
        foreach ($this->rows as $row) {
            if ($row['changes'] !== []) {
                $lines[] = [
                    'type' => self::TYPES[$row['table']] . ($row['before'] === null ? '.created' : '.updated'),
                    'entityType' => $row['table'], 'entityId' => $row['id'], 'changes' => $row['changes'],
                ];
            }
        }

        return $lines;
    }

    /** Hands the lines to the event store. Never throws. */
    public function flush(): void
    {
        foreach ($this->lines() as $line) {
            try {
                app(EventRecorder::class)->record($line['type'], $this->tenant, $line['entityType'], $line['entityId'], $this->actor, ['changes' => $line['changes']]);
            } catch (\Throwable $e) {
                try {
                    Log::warning('A product change was saved but its audit line could not be written (' . $line['type'] . ' #' . $line['entityId'] . ').', ['error' => $e->getMessage()]);
                } catch (\Throwable) {
                    // Even logging must not reach the caller: the write is done.
                }
            }
        }
    }

    /**
     * Key by key: a column whose value differs gets one entry. A NULL equals only a NULL, anything else is compared as text, so 7 and '7' are the same value.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{field: string, oldValue: mixed, newValue: mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $column => $value) {
            if (in_array($column, self::IGNORED, true)) {
                continue;
            }
            $old = $before[$column] ?? null;
            $same = $old === null || $value === null ? $old === $value : (string) $old === (string) $value;
            if (! $same) {
                $changes[] = ['field' => (string) $column, 'oldValue' => $old, 'newValue' => $value];
            }
        }

        return $changes;
    }

    /** @return ?array<string, mixed> */
    private function read(string $table, int $id): ?array
    {
        $row = DB::table($table)->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }
}
