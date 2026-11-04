<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * What a lead is FOR and WHO it is about (slice 6 of the agentic CRM, Docs/agentic-crm/slices/S06-leads.md), and room for what it says.
 *
 *   product_master_id   BIGINT UNSIGNED NULL   the product or sub-product the lead is about (g2g_product_master.id; a loose pointer, no foreign key).
 *                                              Set when a signal is turned into a lead and its product resolves, and by `leads:backfill-links`.
 *   org_account_id      BIGINT UNSIGNED NULL   the organisation identity the lead is about (g2g_org_accounts.id; a loose pointer, no foreign key).
 *   description         TINYTEXT -> TEXT       defect D9: a TINYTEXT holds 255 BYTES, so the live database (not strict) silently cut a longer description off
 *                                              and the strict dev database refused it. A TEXT holds 65,535 bytes; the validators cap a description at 2,000
 *                                              characters. The column's own character set and collation are carried over, so no data changes.
 *
 * Each new column gets an index led by the tenant (`sub_institute_id`, column) because every read of a lead is tenant-scoped, and
 * crm_leads.sub_institute_id is nullable on older rows: every query this slice adds filters it explicitly.
 *
 * ADDITIVE: two nullable columns at the end of the table, two indexes, one widening. Every existing row keeps NULL in both new columns. Guarded by
 * information_schema checks (never ColumnExists::has: its bound SHOW COLUMNS ... LIKE ? form fails on the live MariaDB 10.1, see DB-RULES.md), so a second run
 * does nothing and a host that lacks the table skips it. down() removes the two indexes and the two columns up() added. It does NOT narrow the description
 * again: that could cut off text saved since.
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate):
 *   php artisan migrate --path=database/migrations/2026_12_11_100100_add_product_and_org_account_to_crm_leads.php
 */
return new class extends Migration
{
    /** @var array<string, string> column => the index that starts with the tenant */
    private const COLUMNS = ['product_master_id' => 'crm_leads_tenant_product', 'org_account_id' => 'crm_leads_tenant_org_account'];

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

        $this->widenDescription();
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
        // The description stays a TEXT on purpose: narrowing it could cut off what was saved since.
    }

    /** TINYTEXT to TEXT, only where it is still a TINYTEXT, keeping the column's own character set, collation and NULL-ness. The sqlite test database has no such limit. */
    private function widenDescription(): void
    {
        if (! $this->isMariaDb()) {
            return;
        }

        $column = DB::selectOne(
            'SELECT DATA_TYPE AS type, CHARACTER_SET_NAME AS charset, COLLATION_NAME AS collation, IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['crm_leads', 'description']
        );
        if ($column === null || strtolower((string) $column->type) !== 'tinytext') {
            return;
        }

        // Names read from the server, set as literals only after they are checked to be plain identifiers.
        $charset = preg_match('/^[A-Za-z0-9_]+$/', (string) $column->charset) ? " CHARACTER SET {$column->charset}" : '';
        $collation = preg_match('/^[A-Za-z0-9_]+$/', (string) $column->collation) ? " COLLATE {$column->collation}" : '';
        $null = strtoupper((string) $column->nullable) === 'YES' ? 'NULL' : 'NOT NULL';
        DB::statement("ALTER TABLE `crm_leads` MODIFY `description` TEXT{$charset}{$collation} {$null}");
    }
};
