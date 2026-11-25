<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Where a lead came from in Prospecting (S20: Signal -> Prospecting -> Lead -> Convert Lead).
 *
 *   gtm_account_id   BIGINT UNSIGNED NULL   the Prospecting account (gtm_accounts.id) the lead was qualified from. A loose pointer, no foreign key.
 *   gtm_contact_id   BIGINT UNSIGNED NULL   the PERSON (gtm_contacts.id) the lead was qualified for. One lead per person: this is what makes
 *                                           "Qualify as lead" idempotent per (account, person), which nothing already on the table can say.
 *
 * Each column gets an index led by the tenant (`sub_institute_id`, column), like every other lead link: every read of a lead is tenant-scoped.
 *
 * ADDITIVE: two nullable columns at the end of the table and two indexes. Every existing row keeps NULL in both (the leads that came straight from a
 * signal are not changed). Guarded by information_schema checks (never ColumnExists::has: its bound SHOW COLUMNS ... LIKE ? form fails on the live
 * MariaDB 10.1, see DB-RULES.md), so a second run does nothing and a host that lacks the table skips it. down() removes the two indexes and the two
 * columns up() added. The code works without it: until it has run, "Qualify as lead" answers 503 and an account's `leads` list is empty.
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate):
 *   php artisan migrate --path=database/migrations/2026_12_27_090000_add_prospecting_links_to_crm_leads.php
 */
return new class extends Migration
{
    /** @var array<string, string> column => the index that starts with the tenant */
    private const COLUMNS = ['gtm_account_id' => 'crm_leads_tenant_gtm_account', 'gtm_contact_id' => 'crm_leads_tenant_gtm_contact'];

    private function isMariaDb(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function hasColumn(string $column): bool
    {
        if ($this->isMariaDb()) {
            return DB::table('information_schema.COLUMNS')->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'crm_leads')->where('COLUMN_NAME', $column)->exists();
        }

        return Schema::hasColumn('crm_leads', $column);
    }

    private function hasIndex(string $index): bool
    {
        if ($this->isMariaDb()) {
            return DB::table('information_schema.STATISTICS')->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'crm_leads')->where('INDEX_NAME', $index)->exists();
        }

        return Schema::hasIndex('crm_leads', $index);
    }

    public function up(): void
    {
        if (! Schema::hasTable('crm_leads')) {
            return;
        }

        foreach (self::COLUMNS as $column => $index) {
            if (! $this->hasColumn($column)) {
                if ($this->isMariaDb()) {
                    DB::statement("ALTER TABLE `crm_leads` ADD COLUMN `{$column}` BIGINT UNSIGNED NULL");
                } else {
                    Schema::table('crm_leads', fn (Blueprint $t) => $t->unsignedBigInteger($column)->nullable());
                }
            }
            if (! $this->hasIndex($index)) {
                if ($this->isMariaDb()) {
                    DB::statement("ALTER TABLE `crm_leads` ADD INDEX `{$index}` (`sub_institute_id`, `{$column}`)");
                } else {
                    Schema::table('crm_leads', fn (Blueprint $t) => $t->index(['sub_institute_id', $column], $index));
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('crm_leads')) {
            return;
        }

        foreach (self::COLUMNS as $column => $index) {
            if ($this->hasIndex($index)) {
                if ($this->isMariaDb()) {
                    DB::statement("ALTER TABLE `crm_leads` DROP INDEX `{$index}`");
                } else {
                    Schema::table('crm_leads', fn (Blueprint $t) => $t->dropIndex($index));
                }
            }
            if ($this->hasColumn($column)) {
                if ($this->isMariaDb()) {
                    DB::statement("ALTER TABLE `crm_leads` DROP COLUMN `{$column}`");
                } else {
                    Schema::table('crm_leads', fn (Blueprint $t) => $t->dropColumn($column));
                }
            }
        }
    }
};
