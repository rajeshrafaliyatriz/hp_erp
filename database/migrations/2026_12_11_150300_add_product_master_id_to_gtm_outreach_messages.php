<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Which product an outreach message is about (slice 10 of the agentic CRM, docs/agentic-crm/slices/S10-outreach-gate.md).
 *
 *   product_master_id   BIGINT UNSIGNED NULL   the product or sub-product (g2g_product_master.id; a loose pointer, no foreign key). NULL on every existing row. The
 *                                              outreach gate reads it to know whose approved claims a draft may rest on; a draft with no pointer is still checked,
 *                                              by the product names its text mentions. Nothing is back-filled by this file.
 *
 * Indexed with the organisation, (sub_institute_id, product_master_id), like every other product pointer.
 *
 * ADDITIVE: one nullable column at the end of the table and one index. Guarded by information_schema checks (never ColumnExists::has: its bound SHOW COLUMNS ...
 * LIKE ? form fails on the live MariaDB 10.1, see DB-RULES.md), so a second run does nothing and a host that lacks the table skips it. down() removes the index
 * and the column up() added.
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate):
 *   php artisan migrate --path=database/migrations/2026_12_11_150300_add_product_master_id_to_gtm_outreach_messages.php
 */
return new class extends Migration
{
    private const TABLE = 'gtm_outreach_messages';

    private const COLUMN = 'product_master_id';

    private const INDEX = 'gtm_outreach_tenant_product';

    private function isMariaDb(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function hasColumn(): bool
    {
        if ($this->isMariaDb()) {
            return DB::table('information_schema.COLUMNS')->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', self::TABLE)->where('COLUMN_NAME', self::COLUMN)->exists();
        }

        return Schema::hasColumn(self::TABLE, self::COLUMN);
    }

    private function hasIndex(): bool
    {
        if ($this->isMariaDb()) {
            return DB::table('information_schema.STATISTICS')->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', self::TABLE)->where('INDEX_NAME', self::INDEX)->exists();
        }

        return Schema::hasIndex(self::TABLE, self::INDEX);
    }

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! $this->hasColumn()) {
            if ($this->isMariaDb()) {
                DB::statement('ALTER TABLE `gtm_outreach_messages` ADD COLUMN `product_master_id` BIGINT UNSIGNED NULL');
            } else {
                Schema::table(self::TABLE, fn (Blueprint $t) => $t->unsignedBigInteger(self::COLUMN)->nullable());
            }
        }
        if (! $this->hasIndex()) {
            if ($this->isMariaDb()) {
                DB::statement('ALTER TABLE `gtm_outreach_messages` ADD INDEX `gtm_outreach_tenant_product` (`sub_institute_id`, `product_master_id`)');
            } else {
                Schema::table(self::TABLE, fn (Blueprint $t) => $t->index(['sub_institute_id', self::COLUMN], self::INDEX));
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if ($this->hasIndex()) {
            if ($this->isMariaDb()) {
                DB::statement('ALTER TABLE `gtm_outreach_messages` DROP INDEX `gtm_outreach_tenant_product`');
            } else {
                Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropIndex(self::INDEX));
            }
        }
        if ($this->hasColumn()) {
            if ($this->isMariaDb()) {
                DB::statement('ALTER TABLE `gtm_outreach_messages` DROP COLUMN `product_master_id`');
            } else {
                Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropColumn(self::COLUMN));
            }
        }
    }
};
