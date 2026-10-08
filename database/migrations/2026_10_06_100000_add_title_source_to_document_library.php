<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where `document_library.title` actually came from, so the pipeline can
 * fill it in from AI only when nobody has deliberately set it.
 *
 * `title` is NOT NULL, unlike `document_type` (which is nullable, so
 * `ProcessDocumentPipelineJob::classify()` can just check `empty()` before
 * filling it in). Title can never be empty once uploads stop requiring one
 * (see the upload-flow work that relaxes `title` to optional) - some
 * placeholder must always be written, so "was this auto-generated" can't be
 * inferred from the string's shape. This column makes that provenance
 * explicit instead: 'user' (the uploader typed one - never overwritten),
 * 'filename' (fell back to the original filename), 'ai' (the classifier
 * filled it in afterward).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->tableExists('document_library')) {
            return;
        }

        if (! $this->columnExists('document_library', 'title_source')) {
            Schema::table('document_library', function (Blueprint $table) {
                $table->string('title_source', 10)->nullable()->after('title');
            });
        }
    }

    public function down(): void
    {
        if (! $this->tableExists('document_library')) {
            return;
        }

        if ($this->columnExists('document_library', 'title_source')) {
            Schema::table('document_library', function (Blueprint $table) {
                $table->dropColumn('title_source');
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
