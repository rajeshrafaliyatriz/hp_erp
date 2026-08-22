<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two fixes to the task -> competency bridge, both about multi-tenancy.
 *
 * ══ 1. uq_jtcm FORBIDS WHAT THE FEATURE PROMISES ═══════════════════════════
 *
 * `jobrole_task_competency_map` maps a task from the SHARED catalogue
 * (`s_jobrole_task`) to a competency, per organisation. Its unique key was
 * created as:
 *
 *     UNIQUE (jobrole_task_id, competency_id)
 *
 * with no tenant column. So once ANY organisation maps catalogue task 7 to
 * competency 12, NO OTHER organisation can ever record that same pair - the
 * insert is refused by the database. JobroleTaskCompetencyMapController's own
 * header promises exactly the opposite, and `store()` calls `updateOrInsert`
 * on a THREE-column key (sub_institute_id, jobrole_task_id, competency_id)
 * that this index does not match, so the two disagree about what "already
 * exists" means.
 *
 * It has not bitten yet only because live holds 0 rows and dev's 121 all belong
 * to a single tenant. The first time two organisations overlap, one of them
 * gets an unexplained failure.
 *
 * WIDENING CANNOT FAIL ON EXISTING DATA. Adding a column to a unique key only
 * ever RELAXES it: any set of rows unique under (a, b) is necessarily unique
 * under (tenant, a, b). There is no duplicate check to run and no data to
 * clean - measured anyway on both databases, and both are clean.
 *
 * ══ 2. catalogue_task_id EXISTS IN NO MIGRATION ════════════════════════════
 *
 * `s_user_jobrole_task.catalogue_task_id` is the bridge from a tenant's own
 * task row back to the catalogue row it came from, and
 * JobroleTaskCompetencyMapController reads it to resolve either id. It was
 * created by a ONE-OFF SCRIPT that was never turned into a migration, so:
 *
 *   - a freshly migrated database does not have the column at all, and that
 *     read throws. That is precisely the "new organisation starting clean"
 *     case this whole body of work exists for;
 *   - the script ran on dev only. Population is 77,676 of 82,814 on dev and
 *     0 of 91,539 on live.
 *
 * This adds the column properly. IT DOES NOT BACKFILL. Recovering provenance
 * after the fact means matching on names, and the earlier attempt is the
 * argument against it: 80,064 rows keyed but 5,599 left NULL, of which 5,470
 * were ambiguous - the same name in more than one place, with no way to tell
 * which one was meant. Provenance is recorded at adoption time by the import
 * path, where it is known rather than guessed.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_22_120000_widen_jtcm_unique_key_and_formalise_catalogue_task_id.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_22_120000_widen_jtcm_unique_key_and_formalise_catalogue_task_id.php
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Widen the unique key ────────────────────────────────────────
        if ($this->tableExists('jobrole_task_competency_map')) {
            if ($this->indexExists('jobrole_task_competency_map', 'uq_jtcm')) {
                DB::statement('ALTER TABLE `jobrole_task_competency_map` DROP INDEX `uq_jtcm`');
            }

            if (!$this->indexExists('jobrole_task_competency_map', 'uq_jtcm')) {
                DB::statement(
                    'ALTER TABLE `jobrole_task_competency_map`
                     ADD UNIQUE `uq_jtcm` (`sub_institute_id`, `jobrole_task_id`, `competency_id`)'
                );
            }
        }

        // ── 2. Formalise the catalogue bridge column ───────────────────────
        if ($this->tableExists('s_user_jobrole_task')
            && !$this->columnExists('s_user_jobrole_task', 'catalogue_task_id')) {

            DB::statement(
                'ALTER TABLE `s_user_jobrole_task`
                 ADD COLUMN `catalogue_task_id` BIGINT UNSIGNED NULL'
            );
        }

        // The index is added separately: on dev the COLUMN already exists (from
        // the one-off script) but the INDEX may not, and the two are not
        // guaranteed to have arrived together.
        if ($this->tableExists('s_user_jobrole_task')
            && $this->columnExists('s_user_jobrole_task', 'catalogue_task_id')
            && !$this->indexExists('s_user_jobrole_task', 'idx_ujt_catalogue_task')) {

            DB::statement(
                'ALTER TABLE `s_user_jobrole_task`
                 ADD INDEX `idx_ujt_catalogue_task` (`catalogue_task_id`)'
            );
        }
    }

    public function down(): void
    {
        /*
         * The unique key is NOT narrowed back.
         *
         * Reversing it would re-forbid two organisations from mapping the same
         * catalogue task, and - unlike widening - narrowing CAN fail outright,
         * because rows that are legitimately distinct under the wider key
         * collide under the narrower one. A rollback that either restores a
         * tenancy bug or dies halfway is not a rollback.
         *
         * `catalogue_task_id` is likewise left in place: it holds 77,676
         * populated rows on dev that no other column records, and dropping it
         * would destroy them to undo an ADD COLUMN.
         */
    }

    /**
     * Not Schema::hasTable() / hasColumn().
     *
     * Laravel 11 introspects with a query selecting `generation_expression`,
     * which live's MariaDB 10.1 does not have, so those helpers throw there
     * while working on dev - the difference only appears against production.
     */
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
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
              LIMIT 1',
            [$table, $column]
        ) !== [];
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
              LIMIT 1',
            [$table, $index]
        ) !== [];
    }
};
