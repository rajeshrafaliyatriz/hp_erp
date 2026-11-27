<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The Outreach tab of Market Signals (27 Nov 2026): the people found for a signal's company, and people and companies added by hand, now carry the same facts a CRM contact does.
 *
 * g2g_signal_contacts (the contacts the research found) gains: where a row came from (`source`: found | manual), the rest of a contact's details (secondary email, mobile,
 * department, role, primary), the consent facts a person is entitled to (`consent_status`, `email_opt_out`, `do_not_call`, `suppressed_at`, `suppressed_reason`), an owner, who added
 * it, a note, two loose pointers (`gtm_contact_id`: the GTM contact made when a message was first written to the person; `lead_id`: the lead the person became) and `deleted_at`.
 * g2g_companies gains the same kind of facts for a company added by hand (`source`, phone, email, note, owner, `lead_id`, `created_by`) and `deleted_at`.
 *
 * ADDITIVE and idempotent: every column is checked first, nothing is dropped, retyped or renamed, existing rows keep source = 'found'. No foreign keys (loose pointers only) and no JSON column:
 * the live host is MariaDB 10.1.48. Raw DDL states what it needs; the existence check is the literal `SHOW COLUMNS ... LIKE` form, the one that works on both hosts (DB-RULES).
 *
 * DEV FIRST, then live ONLY AFTER THE OWNER'S GO:
 *   php artisan migrate --path=database/migrations/2026_12_29_090000_add_outreach_fields_to_signal_contacts_and_companies.php
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>> table => column => MariaDB definition */
    private const COLUMNS = [
        'g2g_signal_contacts' => [
            'source' => "VARCHAR(10) NOT NULL DEFAULT 'found'",
            'secondary_email' => 'VARCHAR(191) NULL',
            'mobile' => 'VARCHAR(40) NULL',
            'department' => 'VARCHAR(100) NULL',
            'contact_role' => 'VARCHAR(40) NULL',
            'is_primary' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'do_not_call' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'email_opt_out' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'consent_status' => "VARCHAR(20) NOT NULL DEFAULT 'unknown'",
            'suppressed_at' => 'DATETIME NULL',
            'suppressed_reason' => 'VARCHAR(191) NULL',
            'assigned_to' => 'BIGINT UNSIGNED NULL',
            'created_by' => 'BIGINT UNSIGNED NULL',
            'description' => 'TEXT NULL',
            'gtm_contact_id' => 'BIGINT UNSIGNED NULL',
            'lead_id' => 'BIGINT UNSIGNED NULL',
            'deleted_at' => 'DATETIME NULL',
        ],
        'g2g_companies' => [
            'source' => "VARCHAR(10) NOT NULL DEFAULT 'found'",
            'phone' => 'VARCHAR(40) NULL',
            'email' => 'VARCHAR(191) NULL',
            'description' => 'TEXT NULL',
            'assigned_to' => 'BIGINT UNSIGNED NULL',
            'created_by' => 'BIGINT UNSIGNED NULL',
            'lead_id' => 'BIGINT UNSIGNED NULL',
            'deleted_at' => 'DATETIME NULL',
        ],
    ];

    public function up(): void
    {
        $raw = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;   // the table's own migration has not run on this host: nothing to extend
            }
            foreach ($columns as $column => $definition) {
                if ($this->has($table, $column, $raw)) {
                    continue;
                }
                if ($raw) {
                    DB::statement("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
                } else {
                    Schema::table($table, fn (Blueprint $t) => $this->portable($t, $column));
                }
            }
        }
    }

    public function down(): void
    {
        // Removes only what up() added, and only the columns that exist. Rows added by hand lose their extra facts; found rows are unchanged.
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (array_keys($columns) as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
                }
            }
        }
    }

    private function has(string $table, string $column, bool $raw): bool
    {
        return $raw ? ! empty(DB::select("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'")) : Schema::hasColumn($table, $column);
    }

    /** The portable (sqlite, tests) form of each column. */
    private function portable(Blueprint $t, string $column): void
    {
        match ($column) {
            'source' => $t->string('source', 10)->default('found'),
            'consent_status' => $t->string('consent_status', 20)->default('unknown'),
            'is_primary', 'do_not_call', 'email_opt_out' => $t->boolean($column)->default(false),
            'suppressed_at', 'deleted_at' => $t->dateTime($column)->nullable(),
            'assigned_to', 'created_by', 'gtm_contact_id', 'lead_id' => $t->unsignedBigInteger($column)->nullable(),
            'description' => $t->text('description')->nullable(),
            'mobile', 'phone' => $t->string($column, 40)->nullable(),
            'department' => $t->string('department', 100)->nullable(),
            'contact_role' => $t->string('contact_role', 40)->nullable(),
            default => $t->string($column, 191)->nullable(),   // secondary_email, email, suppressed_reason
        };
    }
};
