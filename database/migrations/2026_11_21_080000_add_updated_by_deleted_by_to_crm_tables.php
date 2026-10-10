<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `crm_organizations`, `crm_contacts`, `crm_campaigns` were created with
 * `created_by` but not `updated_by`/`deleted_by` - an oversight caught by a
 * real 500 (`Unknown column 'updated_by'`) while smoke-testing Organization
 * transfer-ownership. `crm_leads` already has both (inherited from the
 * pre-existing abandoned table); this brings the 3 new tables in line with
 * it and with every controller's own update()/destroy() methods, which
 * already assumed these columns existed.
 */
return new class extends Migration
{
    private const TABLES = ['crm_organizations', 'crm_contacts', 'crm_campaigns'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! $this->tableExists($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! $this->hasColumn($table, 'updated_by')) {
                    $t->unsignedBigInteger('updated_by')->nullable()->index();
                }
                if (! $this->hasColumn($table, 'deleted_by')) {
                    $t->unsignedBigInteger('deleted_by')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! $this->tableExists($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                if ($this->hasColumn($table, 'updated_by')) {
                    $t->dropColumn('updated_by');
                }
                if ($this->hasColumn($table, 'deleted_by')) {
                    $t->dropColumn('deleted_by');
                }
            });
        }
    }

    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }

    private function hasColumn(string $table, string $column): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        ) !== [];
    }
};
