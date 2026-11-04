<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Says who wrote a note (slice 7, thin, of the agentic CRM, Docs/agentic-crm/slices/S07-agent-gateway-thin.md): `created_via` is 'agent' for a note
 * the Organisation agent wrote, NULL for every note a person wrote (every row that exists today stays NULL, which means "a person"). Only the
 * organisation comments table in this first slice; the lead, contact and opportunity tables get the same column when their agents arrive.
 *
 * ADDITIVE: one nullable column at the end of the table's own columns, nothing existing is touched. Guarded by an information_schema check (not
 * ColumnExists::has: its bound SHOW COLUMNS ... LIKE ? form fails on the live MariaDB 10.1.48, see DB-RULES.md), so running it twice does nothing.
 * down() drops only what up() added.
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate):
 *   php artisan migrate --path=database/migrations/2026_12_10_090400_add_created_via_to_crm_organization_comments.php
 */
return new class extends Migration
{
    private function hasColumn(): bool
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return DB::table('information_schema.COLUMNS')->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'crm_organization_comments')->where('COLUMN_NAME', 'created_via')->exists();
        }

        return Schema::hasColumn('crm_organization_comments', 'created_via');
    }

    public function up(): void
    {
        if (! Schema::hasTable('crm_organization_comments') || $this->hasColumn()) {
            return;
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `crm_organization_comments` ADD COLUMN `created_via` VARCHAR(12) NULL AFTER `created_by`');

            return;
        }

        Schema::table('crm_organization_comments', function (Blueprint $table) {
            $table->string('created_via', 12)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('crm_organization_comments') || ! $this->hasColumn()) {
            return;
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `crm_organization_comments` DROP COLUMN `created_via`');

            return;
        }

        Schema::table('crm_organization_comments', function (Blueprint $table) {
            $table->dropColumn('created_via');
        });
    }
};
