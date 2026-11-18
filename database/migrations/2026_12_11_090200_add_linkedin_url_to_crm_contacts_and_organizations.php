<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A LinkedIn address on a contact and on an organisation (slice 4 of the agentic CRM, Docs/agentic-crm/slices/S04-contact-facts-and-consent.md): the one
 * social link the plan gives a column of its own, because the Contacts and Organizations screens will show it. Every other social link stays in the facts
 * ledger until a screen needs it. The column is written when a person ACCEPTS a linkedin_url fact, and only when it is blank; nothing here ever requests
 * the address (decision D6: no LinkedIn fetching).
 *
 * `linkedin_url` VARCHAR(300) NULL on crm_contacts and crm_organizations. ADDITIVE: one nullable column at the end of each table, nothing existing is
 * touched, every row stays NULL. (The email columns the plan also widens to 191 were widened by 2026_12_09_090100 already: dev shows varchar(191) on both
 * tables, so this file does not repeat it.) Guarded by an information_schema check, not ColumnExists::has (its bound SHOW COLUMNS ... LIKE ? form fails on
 * the live MariaDB 10.1.48, see DB-RULES.md), so running it twice does nothing and a host that lacks one of the tables skips it. down() drops only the
 * column up() added.
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate):
 *   php artisan migrate --path=database/migrations/2026_12_11_090200_add_linkedin_url_to_crm_contacts_and_organizations.php
 */
return new class extends Migration
{
    private const TABLES = ['crm_contacts', 'crm_organizations'];

    private function has(string $table): bool
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return DB::table('information_schema.COLUMNS')->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', $table)->where('COLUMN_NAME', 'linkedin_url')->exists();
        }

        return Schema::hasColumn($table, 'linkedin_url');
    }

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || $this->has($table)) {
                continue;
            }
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::statement("ALTER TABLE `{$table}` ADD COLUMN `linkedin_url` VARCHAR(300) NULL");

                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('linkedin_url', 300)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! $this->has($table)) {
                continue;
            }
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::statement("ALTER TABLE `{$table}` DROP COLUMN `linkedin_url`");

                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('linkedin_url');
            });
        }
    }
};
