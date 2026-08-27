<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Linking an ASSIGNED task to the JOB ROLE TASK it came from.
 *
 * ── THE GAP THIS CLOSES ─────────────────────────────────────────────────────
 *
 * There are two different "tasks" in this system and nothing joined them:
 *
 *   s_user_jobrole_task   the duty — what a role is expected to do. This is
 *                         where the execution model and the ESO live.
 *   task                  the work item — one person, one due date, a status.
 *                         This is what an employee actually opens.
 *
 * An employee looking at their assigned work had no way to reach the procedure
 * for it, because the row they were looking at did not know which duty it was
 * an instance of. Measured before this migration: `task` had no jobrole_task_id,
 * no catalogue_task_id, no link column of any kind.
 *
 * ── THE BACKFILL RESOLVES ONLY WHAT IS UNAMBIGUOUS ──────────────────────────
 *
 * Matching is on exact task text within the same tenant, and **only where
 * exactly one job role task matches**. Two candidates means the link is a
 * guess, and a guess here attaches the wrong procedure to somebody's work —
 * which is worse than no procedure at all. Ambiguous rows stay NULL and the
 * count is printed.
 *
 * Measured: 1,990 of 2,253 assigned tasks across all tenants share their title
 * with a job role task (88%). Tenant 6 is the outlier at 5%, because most of
 * its assigned work is ad-hoc rather than drawn from the role library.
 *
 * Going forward the assign form sets this directly, so the text match is a
 * one-time reconciliation and not the mechanism.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_27_200000_link_task_to_jobrole_task.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_27_200000_link_task_to_jobrole_task.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->columnExists('task', 'jobrole_task_id')) {
            DB::statement("
                ALTER TABLE `task`
                ADD COLUMN `jobrole_task_id` BIGINT UNSIGNED NULL
                    COMMENT 's_user_jobrole_task.id this work item is an instance of'
                    AFTER `skill_id`,
                ADD KEY `task_jobrole_task_index` (`jobrole_task_id`)
            ");
        }

        /*
         * No foreign key, deliberately. `s_user_jobrole_task` rows are soft
         * deleted and merged between roles; a hard FK would either block those
         * operations or cascade a delete into somebody's completed work history.
         * The same reasoning the department content tables use.
         */

        $resolved = DB::update("
            UPDATE `task` t
               SET t.jobrole_task_id = (
                     SELECT j.id FROM s_user_jobrole_task j
                      WHERE j.sub_institute_id = t.sub_institute_id
                        AND j.deleted_at IS NULL
                        AND TRIM(LOWER(j.task)) = TRIM(LOWER(t.task_title))
                      LIMIT 1
                   )
             WHERE t.jobrole_task_id IS NULL
               AND t.task_title IS NOT NULL AND t.task_title <> ''
               AND (
                     SELECT COUNT(*) FROM s_user_jobrole_task j2
                      WHERE j2.sub_institute_id = t.sub_institute_id
                        AND j2.deleted_at IS NULL
                        AND TRIM(LOWER(j2.task)) = TRIM(LOWER(t.task_title))
                   ) = 1
        ");

        $linked = (int) DB::table('task')->whereNotNull('jobrole_task_id')->count();
        $total  = (int) DB::table('task')->whereNull('deleted_at')->count();

        echo sprintf(
            "  linked %d work item(s) to a job role task — %d of %d now resolved (%.0f%%)\n",
            $resolved, $linked, $total, $total ? $linked / $total * 100 : 0
        );
    }

    public function down(): void
    {
        if ($this->columnExists('task', 'jobrole_task_id')) {
            DB::statement('ALTER TABLE `task` DROP COLUMN `jobrole_task_id`');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        ) !== [];
    }
};
