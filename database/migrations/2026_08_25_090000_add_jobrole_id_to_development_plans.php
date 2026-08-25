<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * KEYS A DEVELOPMENT PLAN TO ITS JOB ROLE BY ID.
 *
 * ── WHY ────────────────────────────────────────────────────────────────────
 *
 * `s_competency_development_plans` stores `jobrole` as TEXT. To compare a
 * person against their role's competency requirements, the plan's role must
 * reach `jobrole_competency_map`, which is keyed by `jobrole_id` - so every
 * read had to match on a name a tenant can edit. Renaming a job role silently
 * detached every plan that named it, and nothing anywhere would have reported
 * it.
 *
 * This is the same repair applied to `s_competency_frameworks` (jobrole_id,
 * 2026_08_24_090000) and to `s_user_jobrole_task`. Same rule, same failure mode
 * removed.
 *
 * ── THE BACKFILL RULE ──────────────────────────────────────────────────────
 *
 *     EXACTLY ONE MATCH RESOLVES; ANYTHING ELSE STAYS NULL AND IS REPORTED.
 *
 * Measured on live across all 164 plans before writing this:
 *
 *     name resolves to exactly one role in its tenant   159   97.0%
 *     name matches several roles - ambiguous              5    3.0%
 *     name matches nothing / plan names no role           0    0.0%
 *
 * The 5 ambiguous stay NULL. Picking one of several same-named roles would
 * attach a person's gap report to a role they may not hold - and the reader
 * would have no way to tell. `gaps()` falls back to the name lookup, which
 * applies the same rule, so an ambiguous plan reports "role not resolved"
 * rather than a confident answer about the wrong role.
 *
 * TENANT-SCOPED, unlike the shared task catalogue: `s_user_jobrole` is
 * tenant-owned, so a plan must never key to another organisation's role.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_25_090000_add_jobrole_id_to_development_plans.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_25_090000_add_jobrole_id_to_development_plans.php
 */
return new class extends Migration
{
    private const TABLE = 's_competency_development_plans';
    private const FK    = 'scdp_jobrole_id_foreign';

    public function up(): void
    {
        if (!$this->tableExists(self::TABLE) || !$this->tableExists('s_user_jobrole')) {
            return;
        }

        if (!$this->columnExists(self::TABLE, 'jobrole_id')) {
            DB::statement(
                'ALTER TABLE `' . self::TABLE . '`
                 ADD COLUMN `jobrole_id` BIGINT UNSIGNED NULL AFTER `jobrole`'
            );
        }

        if (!$this->indexExists(self::TABLE, 'idx_scdp_jobrole_id')) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` ADD INDEX `idx_scdp_jobrole_id` (`jobrole_id`)');
        }

        /*
         * ON DELETE SET NULL, not CASCADE. A development plan is a record of
         * what somebody agreed to work on; deleting the job role must not
         * delete their plan and its history. The plan keeps its `jobrole` text
         * so the name survives even when the row it pointed at does not.
         */
        if (!$this->foreignKeyExists(self::TABLE, self::FK)) {
            DB::statement(
                'ALTER TABLE `' . self::TABLE . '`
                 ADD CONSTRAINT `' . self::FK . '`
                 FOREIGN KEY (`jobrole_id`) REFERENCES `s_user_jobrole` (`id`)
                 ON DELETE SET NULL ON UPDATE CASCADE'
            );
        }

        $this->backfill();
    }

    /**
     * Name -> id, keying only what is certain. Idempotent: only rows with a
     * NULL `jobrole_id` are considered, so re-running picks up newly-created
     * roles and never overwrites a decision somebody made.
     */
    private function backfill(): void
    {
        $plans = DB::table(self::TABLE)
            ->whereNull('jobrole_id')
            ->whereNull('deleted_at')
            ->whereNotNull('jobrole')
            ->where('jobrole', '!=', '')
            ->get(['id', 'sub_institute_id', 'jobrole']);

        $keyed = 0;
        $ambiguous = 0;
        $unmatched = 0;

        foreach ($plans as $plan) {
            $ids = DB::table('s_user_jobrole')
                ->where('sub_institute_id', $plan->sub_institute_id)
                ->where('jobrole', $plan->jobrole)
                ->whereNull('deleted_at')
                ->limit(2)                    // two is enough to know it is ambiguous
                ->pluck('id');

            if ($ids->count() === 1) {
                DB::table(self::TABLE)->where('id', $plan->id)
                    ->update(['jobrole_id' => (int) $ids->first()]);
                $keyed++;
            } elseif ($ids->count() > 1) {
                $ambiguous++;              // stays NULL, on purpose
            } else {
                $unmatched++;
            }
        }

        // Printed rather than swallowed: a backfill that reports nothing leaves
        // the reader assuming it keyed everything.
        echo sprintf(
            "  jobrole_id backfill: %d keyed, %d ambiguous (left NULL), %d unmatched (left NULL)\n",
            $keyed,
            $ambiguous,
            $unmatched
        );
    }

    public function down(): void
    {
        if ($this->foreignKeyExists(self::TABLE, self::FK)) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` DROP FOREIGN KEY `' . self::FK . '`');
        }
        if ($this->indexExists(self::TABLE, 'idx_scdp_jobrole_id')) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` DROP INDEX `idx_scdp_jobrole_id`');
        }
        if ($this->columnExists(self::TABLE, 'jobrole_id')) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` DROP COLUMN `jobrole_id`');
        }
    }

    /* ----------------------------------------------------------------- *
     * Not Schema::* - Laravel 11 introspects with a query selecting
     * `generation_expression`, which live's MariaDB 10.1 lacks, so those
     * helpers throw against production while working on dev.
     * ----------------------------------------------------------------- */

    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }

    private function columnExists(string $table, string $column): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        ) !== [];
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        ) !== [];
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        return DB::select(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1",
            [$table, $constraint]
        ) !== [];
    }
};
