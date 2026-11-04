<?php

namespace App\Domain\Signals\Backfill;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The frame the three Signals data backfills share (slice 2 of the agentic CRM, DB-RULES.md rule 8): one organisation per run, one transaction
 * per organisation, and a dry run (the default) that reads and counts but writes nothing. A subclass says what to read and what to fill.
 *
 * It works on the application's DEFAULT connection. The commands switch that connection from --database before anything is read, because the
 * Product Master resolver and ColumnExists both read the default connection and cannot be pointed elsewhere.
 */
abstract class TenantBackfill
{
    protected const TABLE = 'g2g_company_opportunities';

    /** How many ids a report lists. The counts next to them are always complete. */
    public const IDS_SHOWN = 10;

    /**
     * @return array<string, mixed> the subclass's report, with `tenant` and `apply` in front
     *
     * @throws BackfillBlocked when the connection lacks a table or column the run needs (nothing is written)
     */
    final public function run(int $tenantId, bool $apply): array
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('A backfill runs for one organisation: give a positive sub_institute_id.');
        }
        if (! Schema::hasTable(self::TABLE)) {
            throw new BackfillBlocked(self::TABLE . ' does not exist on this connection.');
        }

        $db = DB::connection();
        if ($apply) {
            $db->beginTransaction();
        }
        try {
            $report = $this->process($db, $tenantId, $apply);
            if ($apply) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($apply) {
                $db->rollBack();
            }
            throw $e;
        }

        return ['tenant' => $tenantId, 'apply' => $apply] + $report;
    }

    /** @return list<int> every organisation that has signals on this connection (the shared tenant 0 is never a signal owner) */
    public function tenants(): array
    {
        if (! Schema::hasTable(self::TABLE)) {
            return [];
        }

        return DB::table(self::TABLE)->whereNotNull('sub_institute_id')->where('sub_institute_id', '>', 0)
            ->distinct()->orderBy('sub_institute_id')->pluck('sub_institute_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Reads this organisation's signals and, when $apply, fills them. Runs inside the organisation's transaction.
     *
     * @return array<string, mixed>
     */
    abstract protected function process(Connection $db, int $tenant, bool $apply): array;
}
