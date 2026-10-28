<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which department a folder belongs to, for `visibility='department'` folders
 * - the exact twin of `document_library.department_id` (same nullable, no-FK
 * shape; departments are soft-deleted/merged elsewhere in this codebase, so
 * neither table enforces a real foreign key on this column). Without this,
 * a department's own folder tree has nowhere to record "this folder belongs
 * to department X" and `DocumentAccess`'s folder ACL has nothing to compare
 * a viewer's department against - folders have stayed owner-or-organization
 * only until now.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->tableExists('document_folders')) {
            return;
        }

        if (! $this->columnExists('document_folders', 'department_id')) {
            Schema::table('document_folders', function (Blueprint $table) {
                $table->unsignedBigInteger('department_id')->nullable()->index()->after('owner_id');
            });
        }
    }

    public function down(): void
    {
        if (! $this->tableExists('document_folders')) {
            return;
        }

        if ($this->columnExists('document_folders', 'department_id')) {
            Schema::table('document_folders', function (Blueprint $table) {
                $table->dropColumn('department_id');
            });
        }
    }

    /** information_schema directly - live is MariaDB 10.1, where Schema::hasTable()/hasColumn() throw. */
    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }

    private function columnExists(string $table, string $column): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        ) !== [];
    }
};
