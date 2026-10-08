<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a document lives in the folder tree. NULL = root (not inside any
 * folder) - the same nullable-means-no-parent convention `document_folders`
 * itself just established.
 *
 * `onDelete('NO ACTION')`, matching every other FK on this table: deleting a
 * folder must never cascade-delete the documents inside it.
 * `DocumentFolderController::destroy()` refuses to delete a non-empty folder
 * in the first place (see that table's migration), so this FK existing as
 * `NO ACTION` is a backstop, not the primary guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->tableExists('document_library') || ! $this->tableExists('document_folders')) {
            return;
        }

        if (! $this->columnExists('document_library', 'folder_id')) {
            Schema::table('document_library', function (Blueprint $table) {
                $table->unsignedBigInteger('folder_id')->nullable()->index()->after('owner_id');
                $table->foreign('folder_id')->references('id')->on('document_folders')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            });
        }
    }

    public function down(): void
    {
        if (! $this->tableExists('document_library')) {
            return;
        }

        if ($this->columnExists('document_library', 'folder_id')) {
            Schema::table('document_library', function (Blueprint $table) {
                $table->dropForeign(['folder_id']);
                $table->dropColumn('folder_id');
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
