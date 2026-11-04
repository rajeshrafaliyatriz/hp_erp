<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Which product a playbook is about (slice 10 of the agentic CRM, docs/agentic-crm/slices/S10-outreach-gate.md).
 *
 *   product_master_id   BIGINT UNSIGNED NULL   the product or sub-product (g2g_product_master.id; a loose pointer, no foreign key). NULL on every existing row:
 *                                              nothing is back-filled by this file (a data update is a separate command, dry run by default).
 *
 * `gtm_playbooks.sub_institute_id` is NULLABLE (NULL = a platform default playbook), so the index is (sub_institute_id, product_master_id) as for every other
 * product pointer: a platform default has no organisation and is found by the NULL branch of its own queries.
 *
 * ADDITIVE: one nullable column at the end of the table and one index. Guarded by information_schema checks (never ColumnExists::has: its bound SHOW COLUMNS ...
 * LIKE ? form fails on the live MariaDB 10.1, see DB-RULES.md), so a second run does nothing and a host that lacks the table skips it. down() removes the index
 * and the column up() added.
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate):
 *   php artisan migrate --path=database/migrations/2026_12_11_150200_add_product_master_id_to_gtm_playbooks.php
 */
return new class extends Migration
{
    private const TABLE = 'gtm_playbooks';

    private const COLUMN = 'product_master_id';

    private const INDEX = 'gtm_playbooks_tenant_product';

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
                DB::statement('ALTER TABLE `gtm_playbooks` ADD COLUMN `product_master_id` BIGINT UNSIGNED NULL');
            } else {
                Schema::table(self::TABLE, fn (Blueprint $t) => $t->unsignedBigInteger(self::COLUMN)->nullable());
            }
        }
        if (! $this->hasIndex()) {
            if ($this->isMariaDb()) {
                DB::statement('ALTER TABLE `gtm_playbooks` ADD INDEX `gtm_playbooks_tenant_product` (`sub_institute_id`, `product_master_id`)');
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
                DB::statement('ALTER TABLE `gtm_playbooks` DROP INDEX `gtm_playbooks_tenant_product`');
            } else {
                Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropIndex(self::INDEX));
            }
        }
        if ($this->hasColumn()) {
            if ($this->isMariaDb()) {
                DB::statement('ALTER TABLE `gtm_playbooks` DROP COLUMN `product_master_id`');
            } else {
                Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropColumn(self::COLUMN));
            }
        }
    }
};
