<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * When somebody actually earned a badge.
 *
 * ── WHY THIS TABLE HAS TO EXIST ─────────────────────────────────────────────
 *
 * `lms_achievements` is a DEFINITION table — title, description,
 * `criteria_type`, `criteria_value`. It has no user column and no tenant
 * column, and there is no table anywhere linking a person to a badge.
 *
 * So `getUserAchievements` recomputes every criterion on every request and, for
 * anything it finds satisfied, reports:
 *
 *     $earnedDate = now()->format('d/m/Y');
 *
 * The date a badge was earned is therefore always TODAY. A learner who
 * completed five courses in January sees "earned 05/09/2026" in September, and
 * the day after the criterion stops holding — a rolling "5 courses this month"
 * window, say — the badge silently disappears as though it had never been
 * awarded. An achievement that can be un-earned by the passage of time is not
 * an achievement.
 *
 * This records the award once, with the date it happened and what caused it.
 * The live computation stays, because it is what drives PROGRESS toward a badge
 * not yet earned; it simply stops being the source of truth for one already won.
 *
 * ── source_event_id ─────────────────────────────────────────────────────────
 *
 * Awards are made from the `course.completed` event, which now fires. Carrying
 * the event id makes a badge traceable to the completion that produced it, and
 * makes the award idempotent: the same event cannot award the same badge twice.
 *
 * ── LIVE CONSTRAINTS ────────────────────────────────────────────────────────
 *
 * MariaDB 10.1.48: named indexes, identifiers under 64 characters, no `json`,
 * no FKs (nothing in this schema uses them), existence checked against
 * information_schema because Schema::hasTable() throws there.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_05_100400_create_lms_user_achievement.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_05_100400_create_lms_user_achievement.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->tableExists('lms_user_achievement')) {
            DB::statement(
                'CREATE TABLE `lms_user_achievement` (
                    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT(20) UNSIGNED NOT NULL,
                    `user_id` BIGINT(20) UNSIGNED NOT NULL,
                    `achievement_id` BIGINT(20) UNSIGNED NOT NULL,
                    `earned_at` TIMESTAMP NULL,
                    `source_event_id` BIGINT(20) UNSIGNED NULL,
                    `note` VARCHAR(191) NULL,
                    `created_at` TIMESTAMP NULL,
                    `updated_at` TIMESTAMP NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_lua_tenant_user_achievement`
                        (`sub_institute_id`, `user_id`, `achievement_id`),
                    KEY `idx_lua_user` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        /*
         * Badge definitions are global today (`lms_achievements` has no tenant
         * column and one row, shared by everyone). A tenant that wants its own
         * badges needs somewhere to say so; NULL keeps meaning "available to
         * every organisation", so the existing row is unaffected.
         */
        if ($this->tableExists('lms_achievements')
            && ! $this->columnExists('lms_achievements', 'sub_institute_id')) {
            DB::statement(
                'ALTER TABLE `lms_achievements`
                    ADD COLUMN `sub_institute_id` BIGINT(20) UNSIGNED NULL AFTER `id`'
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS `lms_user_achievement`');

        if ($this->columnExists('lms_achievements', 'sub_institute_id')) {
            DB::statement('ALTER TABLE `lms_achievements` DROP COLUMN `sub_institute_id`');
        }
    }

    /** information_schema directly - live is MariaDB 10.1, where Schema::hasTable() throws. */
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
};
