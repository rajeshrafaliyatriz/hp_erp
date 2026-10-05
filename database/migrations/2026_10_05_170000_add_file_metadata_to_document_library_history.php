<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a `version` row fully describe the file it points to, for restore.
 *
 * `document_library_history`'s version columns (storage_path, checksum,
 * size) were enough to prove a version existed, but not enough to restore
 * one — `mime_type` and `original_file_name` live only on the live
 * `document_library` row today, so restoring an older version had no way to
 * know what to put back into them. Both are filled in going forward by
 * every version-writing path (initial upload, new version, restore itself);
 * older rows stay NULL, which `restoreVersion()` treats as "keep whatever
 * the live row currently has" rather than an error.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->tableExists('document_library_history')) {
            return;
        }

        Schema::table('document_library_history', function (Blueprint $table) {
            if (! $this->columnExists('document_library_history', 'mime_type')) {
                $table->string('mime_type', 191)->nullable()->after('size');
            }
            if (! $this->columnExists('document_library_history', 'original_file_name')) {
                $table->string('original_file_name', 255)->nullable()->after('mime_type');
            }
        });
    }

    public function down(): void
    {
        if (! $this->tableExists('document_library_history')) {
            return;
        }

        Schema::table('document_library_history', function (Blueprint $table) {
            if ($this->columnExists('document_library_history', 'original_file_name')) {
                $table->dropColumn('original_file_name');
            }
            if ($this->columnExists('document_library_history', 'mime_type')) {
                $table->dropColumn('mime_type');
            }
        });
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
