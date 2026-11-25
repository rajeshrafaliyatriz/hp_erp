<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S16 (25 Nov 2026, contract Docs/agentic-crm/slices/S16-PRICING-CONTRACT.md section 4): a tax rate may be tied to a currency, so "GST 18%" is offered for rupee Sub-products only.
 *
 * ONE new nullable column, `currency` varchar(3), on crm_tax_rates: NULL means "fits any currency", which is what every existing row keeps (nothing is backfilled here: DB-RULES item 8,
 * data goes through `php artisan crm:seed-gst-rates`). Additive, guarded (the table must exist, the column must not) and repeatable on both hosts, which are not copies of each other.
 * MariaDB 10.1 safe: a plain ADD COLUMN, no JSON, no generated column, no expression default. The guard is Schema::hasColumn (LegacyMariaDbSchemaGrammar, DB-RULES "Checking whether a column exists").
 * The column carries the table's own character set and collation: it is three upper-case letters and is only ever compared with a catalogue code.
 *
 * down() drops only the column up() added, and only when it is there.
 */
return new class extends Migration
{
    private const TABLE = 'crm_tax_rates';

    private const COLUMN = 'currency';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` ADD COLUMN `' . self::COLUMN . '` VARCHAR(3) NULL DEFAULT NULL AFTER `percentage`');

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->string(self::COLUMN, 3)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` DROP COLUMN `' . self::COLUMN . '`');

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropColumn(self::COLUMN);
        });
    }
};
