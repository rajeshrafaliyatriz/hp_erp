<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Which Product Master row (product line) a CRM catalogue SKU belongs to (Task 1).
 *
 *   product_master_id   BIGINT UNSIGNED NULL   the Product Master row (g2g_product_master.id, a product line)
 *                                              that groups this sellable SKU. A loose pointer, no foreign key.
 *
 * Indexed with the organisation first, `(sub_institute_id, product_master_id)`, because every read of a product is organisation-scoped.
 *
 * ADDITIVE: one nullable column and one index. Guarded by information_schema checks, so a second run does nothing and a host that
 * lacks the table skips it. down() removes the index and the column up() added, only if they are there.
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate):
 *   php artisan migrate --path=database/migrations/2026_12_11_160000_add_product_master_id_to_crm_products.php
 *   php artisan migrate --database=db3 --path=database/migrations/2026_12_11_160000_add_product_master_id_to_crm_products.php
 */
return new class extends Migration
{
    private const TABLE = 'crm_products';

    private const COLUMN = 'product_master_id';

    private const INDEX = 'crm_products_tenant_product';

    private function conn(): \Illuminate\Database\Connection
    {
        return DB::connection($this->getConnection());
    }

    private function schema()
    {
        return Schema::connection($this->getConnection());
    }

    private function isMariaDb(): bool
    {
        return in_array($this->conn()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function hasColumn(): bool
    {
        if ($this->isMariaDb()) {
            return $this->conn()->table('information_schema.COLUMNS')->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', self::TABLE)->where('COLUMN_NAME', self::COLUMN)->exists();
        }

        return $this->schema()->hasColumn(self::TABLE, self::COLUMN);
    }

    private function hasIndex(): bool
    {
        if ($this->isMariaDb()) {
            return $this->conn()->table('information_schema.STATISTICS')->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', self::TABLE)->where('INDEX_NAME', self::INDEX)->exists();
        }

        return $this->schema()->hasIndex(self::TABLE, self::INDEX);
    }

    public function up(): void
    {
        if (! $this->schema()->hasTable(self::TABLE)) {
            return;
        }

        if (! $this->hasColumn()) {
            if ($this->isMariaDb()) {
                $this->conn()->statement('ALTER TABLE `' . self::TABLE . '` ADD COLUMN `' . self::COLUMN . '` BIGINT UNSIGNED NULL');
            } else {
                $this->schema()->table(self::TABLE, fn (Blueprint $t) => $t->unsignedBigInteger(self::COLUMN)->nullable());
            }
        }

        if (! $this->hasIndex()) {
            if ($this->isMariaDb()) {
                $this->conn()->statement('ALTER TABLE `' . self::TABLE . '` ADD INDEX `' . self::INDEX . '` (`sub_institute_id`, `' . self::COLUMN . '`)');
            } else {
                $this->schema()->table(self::TABLE, fn (Blueprint $t) => $t->index(['sub_institute_id', self::COLUMN], self::INDEX));
            }
        }
    }

    public function down(): void
    {
        if (! $this->schema()->hasTable(self::TABLE)) {
            return;
        }

        if ($this->hasIndex()) {
            if ($this->isMariaDb()) {
                $this->conn()->statement('ALTER TABLE `' . self::TABLE . '` DROP INDEX `' . self::INDEX . '`');
            } else {
                $this->schema()->table(self::TABLE, fn (Blueprint $t) => $t->dropIndex(self::INDEX));
            }
        }

        if ($this->hasColumn()) {
            if ($this->isMariaDb()) {
                $this->conn()->statement('ALTER TABLE `' . self::TABLE . '` DROP COLUMN `' . self::COLUMN . '`');
            } else {
                $this->schema()->table(self::TABLE, fn (Blueprint $t) => $t->dropColumn(self::COLUMN));
            }
        }
    }
};
