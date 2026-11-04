<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Which product a CRM campaign is FOR (slice 9 of the agentic CRM, Docs/agentic-crm/slices/S09-campaigns-to-leads.md).
 *
 *   product_master_id   BIGINT UNSIGNED NULL   the Product Master row (g2g_product_master.id, a product or a sub-product) the campaign sells. A loose pointer,
 *                                              no foreign key. The older `product_id` (a pointer at the catalogue item, crm_products.id) is left exactly as it
 *                                              is: this is the master's id, and `campaigns:backfill-links` fills it from `product_id` through the master's
 *                                              own `crm_product_id`.
 *
 * Indexed with the organisation first, `(sub_institute_id, product_master_id)`, because every read of a campaign is organisation-scoped.
 *
 * ADDITIVE: one nullable column at the end of the table and one index. Every existing row keeps NULL. Guarded by information_schema checks (never
 * ColumnExists::has: its bound SHOW COLUMNS ... LIKE ? form fails on the live MariaDB 10.1, see DB-RULES.md), so a second run does nothing and a host that
 * lacks the table skips it. down() removes the index and the column up() added, only if they are there.
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate):
 *   php artisan migrate --path=database/migrations/2026_12_11_140000_add_product_master_id_to_crm_campaigns.php
 */
return new class extends Migration
{
    private const TABLE = 'crm_campaigns';

    /** @var array<string, string> column => the index that starts with the organisation */
    private const COLUMNS = ['product_master_id' => 'crm_campaigns_tenant_product'];

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

        foreach (self::COLUMNS as $column => $index) {
            if (! $this->hasColumn($column)) {
                if ($this->isMariaDb()) {
                    DB::statement('ALTER TABLE `' . self::TABLE . "` ADD COLUMN `{$column}` BIGINT UNSIGNED NULL");
                } else {
                    Schema::table(self::TABLE, fn (Blueprint $t) => $t->unsignedBigInteger($column)->nullable());
                }
            }
            if (! $this->hasIndex($index)) {
                if ($this->isMariaDb()) {
                    DB::statement('ALTER TABLE `' . self::TABLE . "` ADD INDEX `{$index}` (`sub_institute_id`, `{$column}`)");
                } else {
                    Schema::table(self::TABLE, fn (Blueprint $t) => $t->index(['sub_institute_id', $column], $index));
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (self::COLUMNS as $column => $index) {
            if ($this->hasIndex($index)) {
                if ($this->isMariaDb()) {
                    DB::statement('ALTER TABLE `' . self::TABLE . "` DROP INDEX `{$index}`");
                } else {
                    Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropIndex($index));
                }
            }
            if ($this->hasColumn($column)) {
                if ($this->isMariaDb()) {
                    DB::statement('ALTER TABLE `' . self::TABLE . "` DROP COLUMN `{$column}`");
                } else {
                    Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropColumn($column));
                }
            }
        }
    }
};
