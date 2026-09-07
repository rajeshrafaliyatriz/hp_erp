<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portable column check.
     *
     * Schema::hasColumn() asks information_schema for `generation_expression`,
     * which exists only on MySQL >= 5.7.6 / MariaDB >= 10.2. One deployment
     * runs MariaDB 10.1.48, where that call dies with
     * "1054 Unknown column 'generation_expression'" before the migration's own
     * logic is ever reached. SHOW COLUMNS has existed in every release.
     */
    private function hasColumnPortable(string $table, string $column): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->exists();
    }

    public function up(): void
    {
        if (Schema::hasTable('tbluser') && $this->hasColumnPortable('tbluser', 'plain_password')) {
            DB::table('tbluser')->whereNotNull('plain_password')->update(['plain_password' => null]);
        }
    }

    public function down(): void
    {
        // Cleared passwords cannot and must not be restored.
    }
};
