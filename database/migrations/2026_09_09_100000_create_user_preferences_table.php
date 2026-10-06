<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE FIRST PLACE A PERSON'S OWN CHOICES CAN LIVE.
 *
 *   php artisan migrate --path=database/migrations/2026_09_09_100000_create_user_preferences_table.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_09_100000_create_user_preferences_table.php
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THERE HAS NEVER BEEN ONE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * A survey of all 451 tables found no `user_preferences`, no `user_settings`,
 * no `notification_settings` - nothing scoped to a user and a choice. And
 * `tbluser` has 99 columns of which exactly two are personal settings: `image`
 * and `fcm_token`, the latter set on 0 of 2,373 rows.
 *
 * So six real preferences currently live in `localStorage`, per browser:
 *
 *     cm-audit:display-settings              columns + page size
 *     cm-competency-library:saved-views      named filter views
 *     hrit.leave-requests.hidden-columns     column visibility
 *     hrit.leave-reports.saved               saved report presets
 *     task-assign:last-used                  last department + job role
 *     gtg-sidebar-expand-on-first-open       one-shot, not a preference
 *
 * Every one is lost when somebody opens the product on a different machine.
 *
 * And a full dark palette has been authored at `app/globals.css:116` since
 * before any of this, with NOTHING that ever applies the class - no
 * `next-themes`, no provider, not one call to set it. A theme switch is about
 * twenty lines of work and has never had anywhere to store its answer.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * SHAPE, AND THE TWO TABLES IT IS NOT
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Key/value, modelled on `s_competency_settings` + `ManagesCompetencySettings`
 * - the cleanest settings store already in this codebase (defaults map, cast on
 * read, allow-list on write). Organisation-level values keep going to
 * `tenant_setting`, which already exists and is already used by `FlatCapRule`.
 *
 * It is deliberately NOT either of these:
 *
 *   hpbrain_settings   has exactly the right columns and exactly the right
 *                      three rows - `notification_preferences` for users 28 and
 *                      571 - and belongs to ANOTHER PRODUCT sharing this
 *                      database. Zero PHP consumers here. Building on it would
 *                      couple two products through a table neither owns.
 *   hpbrain_themes     same origin, zero rows, zero references anywhere.
 *
 * ── sub_institute_id IS DENORMALISED ON PURPOSE ─────────────────────────────
 *
 * The user already implies the tenant. It is carried anyway so a preference can
 * be cleaned up when an organisation is wound down, and so a tenant-scoped
 * query does not have to join `tbluser` to find its own rows - the same
 * reasoning behind `user_onboarding_status`.
 *
 * ── AGAINST LIVE ────────────────────────────────────────────────────────────
 *
 * MariaDB 10.1.48, InnoDB, ROW_FORMAT=Compact: 767-byte index prefix, 64-char
 * identifiers, no `json` type. The unique key is BIGINT(8) + VARCHAR(96) at
 * utf8mb4 = 8 + 384 = 392 bytes, comfortably inside the limit. `pref_value` is
 * TEXT and JSON is encoded into it by the caller, never a json column.
 *
 * Guarded on table existence, so re-running is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->tableExists('user_preferences')) {
            return;
        }

        DB::statement(
            'CREATE TABLE `user_preferences` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `sub_institute_id` BIGINT UNSIGNED NULL,
                `pref_key` VARCHAR(96) NOT NULL,
                `pref_value` TEXT NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `user_preferences_user_key_unique` (`user_id`, `pref_key`),
                KEY `user_preferences_tenant_idx` (`sub_institute_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        /*
         * A foreign key on user_id, matching user_onboarding_status - the
         * closest existing sibling. ON DELETE NO ACTION is the house
         * convention: a preference row is not a reason a user row cannot be
         * removed, and orphan cleanup is deliberate rather than silent.
         */
        DB::statement(
            'ALTER TABLE `user_preferences`
             ADD CONSTRAINT `user_preferences_user_id_foreign`
             FOREIGN KEY (`user_id`) REFERENCES `tbluser` (`id`)
             ON DELETE NO ACTION ON UPDATE NO ACTION'
        );
    }

    public function down(): void
    {
        if (!$this->tableExists('user_preferences')) {
            return;
        }

        /*
         * Dropped even when it holds rows, unlike the onboarding-capture tables.
         *
         * The difference is what the rows MEAN. An asset register or a signed
         * policy acknowledgement is a record somebody may have to produce years
         * later. A theme choice is not: losing it costs one person one click.
         */
        DB::statement('DROP TABLE `user_preferences`');
    }

    /** information_schema, not Schema::hasTable() - that throws on live's 10.1. */
    private function tableExists(string $table): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ));
    }
};
