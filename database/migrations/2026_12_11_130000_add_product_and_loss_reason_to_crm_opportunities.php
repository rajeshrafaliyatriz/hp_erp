<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * What an opportunity is FOR and WHY it was lost (slice 11 of the agentic CRM, Docs/agentic-crm/slices/S11-pipeline-and-forecast.md).
 *
 *   product_master_id   BIGINT UNSIGNED NULL   the product or sub-product the opportunity is about (g2g_product_master.id; a loose pointer, no foreign key).
 *                                              Visible to the organisation only: its own product or a shared one (the API checks it, as it does for a lead).
 *   loss_reason_code    VARCHAR(30) NULL       a coded reason for Closed Lost (the codes live in config/crm_sales.php). The existing free-text close_reason stays
 *                                              as it is; this is the form a report can count.
 *
 * product_master_id gets an index led by the tenant (`sub_institute_id`, `product_master_id`) because every read of an opportunity is tenant-scoped.
 * loss_reason_code gets none: it is a handful of values read per organisation, and a "lost by reason" report scans that organisation's closed deals anyway.
 *
 * ADDITIVE: two nullable columns at the end of the table and one index. Every existing row keeps NULL in both. Guarded by information_schema checks (never
 * ColumnExists::has: its bound SHOW COLUMNS ... LIKE ? form fails on the live MariaDB 10.1, see DB-RULES.md), so a second run does nothing and a host that
 * lacks the table skips it. down() removes the index and the two columns up() added.
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate):
 *   php artisan migrate --path=database/migrations/2026_12_11_130000_add_product_and_loss_reason_to_crm_opportunities.php
 */
return new class extends Migration
{
    private const TABLE = 'crm_opportunities';

    private const INDEX = 'crm_opportunities_tenant_product';

    private function isMariaDb(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function hasColumn(string $column): bool
    {
        if ($this->isMariaDb()) {
            return DB::table('information_schema.COLUMNS')->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', self::TABLE)->where('COLUMN_NAME', $column)->exists();
        }

        return Schema::hasColumn(self::TABLE, $column);
    }

    private function hasIndex(string $index): bool
    {
        if ($this->isMariaDb()) {
            return DB::table('information_schema.STATISTICS')->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', self::TABLE)->where('INDEX_NAME', $index)->exists();
        }

        return Schema::hasIndex(self::TABLE, $index);
    }

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! $this->hasColumn('product_master_id')) {
            if ($this->isMariaDb()) {
                DB::statement('ALTER TABLE `' . self::TABLE . '` ADD COLUMN `product_master_id` BIGINT UNSIGNED NULL');
            } else {
                Schema::table(self::TABLE, fn (Blueprint $t) => $t->unsignedBigInteger('product_master_id')->nullable());
            }
        }

        if (! $this->hasColumn('loss_reason_code')) {
            if ($this->isMariaDb()) {
                DB::statement('ALTER TABLE `' . self::TABLE . '` ADD COLUMN `loss_reason_code` VARCHAR(30) NULL');
            } else {
                Schema::table(self::TABLE, fn (Blueprint $t) => $t->string('loss_reason_code', 30)->nullable());
            }
        }

        if (! $this->hasIndex(self::INDEX)) {
            if ($this->isMariaDb()) {
                DB::statement('ALTER TABLE `' . self::TABLE . '` ADD INDEX `' . self::INDEX . '` (`sub_institute_id`, `product_master_id`)');
            } else {
                Schema::table(self::TABLE, fn (Blueprint $t) => $t->index(['sub_institute_id', 'product_master_id'], self::INDEX));
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if ($this->hasIndex(self::INDEX)) {
            if ($this->isMariaDb()) {
                DB::statement('ALTER TABLE `' . self::TABLE . '` DROP INDEX `' . self::INDEX . '`');
            } else {
                Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropIndex(self::INDEX));
            }
        }

        foreach (['product_master_id', 'loss_reason_code'] as $column) {
            if ($this->hasColumn($column)) {
                if ($this->isMariaDb()) {
                    DB::statement('ALTER TABLE `' . self::TABLE . '` DROP COLUMN `' . $column . '`');
                } else {
                    Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropColumn($column));
                }
            }
        }
    }
};
