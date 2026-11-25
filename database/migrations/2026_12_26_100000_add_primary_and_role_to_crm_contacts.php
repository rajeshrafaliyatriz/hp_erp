<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S18 (25 Nov 2026, contract Docs/agentic-crm/slices/S18-CONTACTS-INTO-ORGANIZATIONS-CONTRACT.md section 2): the people of a company get a Primary star and a Role.
 *
 *   is_primary    tinyint(1) NOT NULL DEFAULT 0   at most one live contact per company carries it (kept by App\Domain\Crm\Contacts\ContactPrimary: MariaDB 10.1 has no partial unique index)
 *   contact_role  varchar(40) NULL                a value of the contacts / contact_role picklist (Decision maker, Influencer, ...), seeded by `php artisan crm:seed-contact-roles`
 *   index crm_contacts_org_primary_idx (company, is_primary)   "the primary of this company" and the company list's people count
 *
 * ADDITIVE and guarded: each column and the index are added only when missing, so it is repeatable on both hosts (which are not copies of each other). MariaDB 10.1 safe: plain ADD COLUMN, no JSON,
 * no generated column, no expression default. The column guard is Schema::hasColumn (LegacyMariaDbSchemaGrammar, DB-RULES "Checking whether a column exists"); the index guard reads
 * information_schema.STATISTICS. Nothing is backfilled here (DB-RULES item 8): every existing contact keeps is_primary = 0 and contact_role NULL.
 *
 * down() drops only what up() added, and only when it is there.
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate):
 *   php artisan migrate --path=database/migrations/2026_12_26_100000_add_primary_and_role_to_crm_contacts.php
 */
return new class extends Migration
{
    private const TABLE = 'crm_contacts';

    private const INDEX = 'crm_contacts_org_primary_idx';

    private function isMariaDb(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function hasIndex(): bool
    {
        if ($this->isMariaDb()) {
            return DB::table('information_schema.STATISTICS')->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', self::TABLE)->where('INDEX_NAME', self::INDEX)->exists();
        }

        return collect(DB::select('PRAGMA index_list(' . self::TABLE . ')'))->contains(fn ($row) => ($row->name ?? null) === self::INDEX);
    }

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'is_primary')) {
            if ($this->isMariaDb()) {
                DB::statement('ALTER TABLE `' . self::TABLE . '` ADD COLUMN `is_primary` TINYINT(1) NOT NULL DEFAULT 0');
            } else {
                Schema::table(self::TABLE, function (Blueprint $table) {
                    $table->boolean('is_primary')->default(false);
                });
            }
        }

        if (! Schema::hasColumn(self::TABLE, 'contact_role')) {
            if ($this->isMariaDb()) {
                DB::statement('ALTER TABLE `' . self::TABLE . '` ADD COLUMN `contact_role` VARCHAR(40) NULL DEFAULT NULL');
            } else {
                Schema::table(self::TABLE, function (Blueprint $table) {
                    $table->string('contact_role', 40)->nullable();
                });
            }
        }

        // The same statement on both drivers (the company column is not quoted here on purpose: it is an existing, registered reference, not a new one).
        if (! $this->hasIndex() && Schema::hasColumn(self::TABLE, 'is_primary')) {
            DB::statement('CREATE INDEX ' . self::INDEX . ' ON ' . self::TABLE . ' (organization_id, is_primary)');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if ($this->hasIndex()) {
            DB::statement($this->isMariaDb() ? 'ALTER TABLE `' . self::TABLE . '` DROP INDEX `' . self::INDEX . '`' : 'DROP INDEX ' . self::INDEX);
        }

        foreach (['contact_role', 'is_primary'] as $column) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                continue;
            }
            if ($this->isMariaDb()) {
                DB::statement('ALTER TABLE `' . self::TABLE . '` DROP COLUMN `' . $column . '`');
            } else {
                Schema::table(self::TABLE, function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
