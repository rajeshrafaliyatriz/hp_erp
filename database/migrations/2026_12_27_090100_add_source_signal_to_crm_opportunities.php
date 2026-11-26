<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Which signal an opportunity was made from (S20: a signal's "Convert to opportunity").
 *
 *   source_company_opportunity_id   BIGINT UNSIGNED NULL   the signal (g2g_company_opportunities.id) the opportunity was converted from. A loose pointer, no foreign key.
 *                                                          It is what makes the conversion idempotent (one opportunity per signal) and lets the signal show
 *                                                          "Opportunity: <name>"; nothing already on the table can say which signal an opportunity came from.
 *
 * Indexed with the tenant first (`sub_institute_id`, column), like every other link: every read of an opportunity is tenant-scoped.
 *
 * ADDITIVE: one nullable column at the end of the table and one index. Every existing row keeps NULL. Guarded by information_schema checks (never
 * ColumnExists::has: its bound SHOW COLUMNS ... LIKE ? form fails on the live MariaDB 10.1, see DB-RULES.md), so a second run does nothing and a host
 * that lacks the table skips it. down() removes the index and the column up() added. The code works without it: until it has run, "Convert to opportunity"
 * answers 503 and the signal list shows no opportunity link.
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate):
 *   php artisan migrate --path=database/migrations/2026_12_27_090100_add_source_signal_to_crm_opportunities.php
 */
return new class extends Migration
{
    private const COLUMN = 'source_company_opportunity_id';

    private const INDEX = 'crm_opps_tenant_source_signal';

    private function isMariaDb(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function hasColumn(): bool
    {
        if ($this->isMariaDb()) {
            return DB::table('information_schema.COLUMNS')->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'crm_opportunities')->where('COLUMN_NAME', self::COLUMN)->exists();
        }

        return Schema::hasColumn('crm_opportunities', self::COLUMN);
    }

    private function hasIndex(): bool
    {
        if ($this->isMariaDb()) {
            return DB::table('information_schema.STATISTICS')->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'crm_opportunities')->where('INDEX_NAME', self::INDEX)->exists();
        }

        return Schema::hasIndex('crm_opportunities', self::INDEX);
    }

    public function up(): void
    {
        if (! Schema::hasTable('crm_opportunities')) {
            return;
        }
        if (! $this->hasColumn()) {
            if ($this->isMariaDb()) {
                DB::statement('ALTER TABLE `crm_opportunities` ADD COLUMN `'.self::COLUMN.'` BIGINT UNSIGNED NULL');
            } else {
                Schema::table('crm_opportunities', fn (Blueprint $t) => $t->unsignedBigInteger(self::COLUMN)->nullable());
            }
        }
        if (! $this->hasIndex()) {
            if ($this->isMariaDb()) {
                DB::statement('ALTER TABLE `crm_opportunities` ADD INDEX `'.self::INDEX.'` (`sub_institute_id`, `'.self::COLUMN.'`)');
            } else {
                Schema::table('crm_opportunities', fn (Blueprint $t) => $t->index(['sub_institute_id', self::COLUMN], self::INDEX));
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('crm_opportunities')) {
            return;
        }
        if ($this->hasIndex()) {
            if ($this->isMariaDb()) {
                DB::statement('ALTER TABLE `crm_opportunities` DROP INDEX `'.self::INDEX.'`');
            } else {
                Schema::table('crm_opportunities', fn (Blueprint $t) => $t->dropIndex(self::INDEX));
            }
        }
        if ($this->hasColumn()) {
            if ($this->isMariaDb()) {
                DB::statement('ALTER TABLE `crm_opportunities` DROP COLUMN `'.self::COLUMN.'`');
            } else {
                Schema::table('crm_opportunities', fn (Blueprint $t) => $t->dropColumn(self::COLUMN));
            }
        }
    }
};
