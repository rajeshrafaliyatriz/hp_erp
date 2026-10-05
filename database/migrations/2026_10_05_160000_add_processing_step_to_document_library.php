<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A SUB-STATE of `processing_status`, for a real (not simulated) upload
 * progress indicator.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY processing_status ALONE CANNOT DRIVE A STAGED PROGRESS BAR
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `processing_status` only ever holds pending/processing/done/failed (see the
 * document_library migration) - three words for "not started", "running",
 * "finished". A frontend polling that column can show a spinner and a
 * terminal state, and nothing in between - which is exactly what the
 * reference implementation (next_lms_erp / LMS K-12's IDMS upload modal)
 * does: one spinner, one swapped heading, a static sentence listing every
 * pipeline step at once because there is no signal to say which one is
 * actually running.
 *
 * `processing_step` is that signal: `ProcessDocumentPipelineJob` writes it as
 * it moves through `extracting_text` -> `ocr` -> `classifying` ->
 * `checking_duplicates` -> `done`, so a progress bar can show the step that
 * is ACTUALLY running right now, not a client-side guess timed to how long
 * the upload POST happened to take.
 *
 * NULLABLE, and `done`/`failed` are also valid values here (mirroring the
 * terminal states) - a document uploaded before this column existed, or one
 * whose extraction found nothing to enrich (empty text, so the job never
 * ran past the first step), is NULL rather than stuck on a fabricated value.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->tableExists('document_library')) {
            return;
        }

        if (! $this->columnExists('document_library', 'processing_step')) {
            Schema::table('document_library', function (Blueprint $table) {
                $table->string('processing_step', 30)->nullable()->after('processing_status');
            });
        }
    }

    public function down(): void
    {
        if (! $this->tableExists('document_library')) {
            return;
        }

        if ($this->columnExists('document_library', 'processing_step')) {
            Schema::table('document_library', function (Blueprint $table) {
                $table->dropColumn('processing_step');
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
