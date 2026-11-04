<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Which product a GTM campaign is FOR, and which CRM campaign it is the same campaign as (slice 9 of the agentic CRM,
 * Docs/agentic-crm/slices/S09-campaigns-to-leads.md).
 *
 *   product_master_id   BIGINT UNSIGNED NULL   the Product Master row (g2g_product_master.id) the campaign sells. A loose pointer, no foreign key.
 *   crm_campaign_id     BIGINT UNSIGNED NULL   the CRM campaign (crm_campaigns.id) this GTM campaign is the same campaign as: a bridge, never a merge. Each
 *                                              table, screen and member list stays what it is. A loose pointer, no foreign key.
 *
 * Each column is indexed with the organisation first, `(sub_institute_id, column)`, because every read of a GTM campaign is organisation-scoped.
 *
 * ADDITIVE: two nullable columns at the end of the table and two indexes. Every existing row keeps NULL in both. Guarded by information_schema checks (never
 * ColumnExists::has: its bound SHOW COLUMNS ... LIKE ? form fails on the live MariaDB 10.1, see DB-RULES.md), so a second run does nothing and a host that
 * lacks the table skips it. down() removes the indexes and columns up() added, only if they are there.
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate):
 *   php artisan migrate --path=database/migrations/2026_12_11_140100_add_product_and_crm_campaign_to_gtm_campaigns.php
 */
return new class extends Migration
{
    private const TABLE = 'gtm_campaigns';

    /** @var array<string, string> column => the index that starts with the organisation */
    private const COLUMNS = [
        'product_master_id' => 'gtm_campaigns_tenant_product',
        'crm_campaign_id' => 'gtm_campaigns_tenant_crm_campaign',
    ];

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
