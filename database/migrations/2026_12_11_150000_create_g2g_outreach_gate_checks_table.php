<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The outreach gate's record of what it checked (slice 10 of the agentic CRM, docs/agentic-crm/slices/S10-outreach-gate.md).
 *
 * One row per CHECK per EVALUATION of one message: `run_id` (a UUID) groups the rows of one evaluation into a set, and the newest set of a message (the highest
 * id for that message and table) is its current one. A re-check adds rows and never changes old ones: the table is append-only history, which is how a person can
 * see why a draft was blocked yesterday and what changed today.
 *
 *   message_table   `gtm_outreach_messages` or `crm_emails`: the two tables a message can live in (a loose pointer with `message_id`; no foreign key).
 *   check_key       the check (banned_phrase, length, link_count, placeholder, unapproved_claim, recipient, consent, suppression, contact_status, unsubscribe_link, ...).
 *   passed          1 or 0. `detail` says why in words, at most 300 characters (the writer cuts it: live is not strict and would cut it silently).
 *
 * NEW TABLE, ADDITIVE, no foreign keys, tenant column BIGINT UNSIGNED NOT NULL. Nullable timestamps with explicit values (the first NOT NULL timestamp of a table
 * gets ON UPDATE on a host with explicit_defaults_for_timestamp off, which live has). Raw DDL on MariaDB with an explicit utf8mb4 charset (dev's database default is
 * latin1); the portable Schema call on sqlite, so the tests build the real table. No JSON column (live is MariaDB 10.1).
 *
 * Guarded: a second run does nothing. down() drops the table up() created.
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate):
 *   php artisan migrate --path=database/migrations/2026_12_11_150000_create_g2g_outreach_gate_checks_table.php
 */
return new class extends Migration
{
    private const TABLE = 'g2g_outreach_gate_checks';

    private function isMariaDb(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        if ($this->isMariaDb()) {
            DB::statement('CREATE TABLE `g2g_outreach_gate_checks` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                `message_id` BIGINT UNSIGNED NOT NULL,
                `message_table` VARCHAR(30) NOT NULL,
                `run_id` CHAR(36) NOT NULL,
                `check_key` VARCHAR(40) NOT NULL,
                `passed` TINYINT(1) NOT NULL DEFAULT 0,
                `detail` VARCHAR(300) NULL,
                `checked_at` TIMESTAMP NULL DEFAULT NULL,
                `created_at` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `g2g_gate_checks_message` (`sub_institute_id`, `message_table`, `message_id`),
                KEY `g2g_gate_checks_run` (`sub_institute_id`, `run_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

            return;
        }

        Schema::create(self::TABLE, function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('message_id');
            $t->string('message_table', 30);
            $t->char('run_id', 36);
            $t->string('check_key', 40);
            $t->boolean('passed')->default(false);
            $t->string('detail', 300)->nullable();
            $t->timestamp('checked_at')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['sub_institute_id', 'message_table', 'message_id'], 'g2g_gate_checks_message');
            $t->index(['sub_institute_id', 'run_id'], 'g2g_gate_checks_run');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
