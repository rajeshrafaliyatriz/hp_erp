<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHERE A PERSONNEL DOCUMENT ACTUALLY IS, AND WHAT IT IS.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * `staff_document` KNEW ONLY A FILENAME
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The table has carried `file_name` since June 2025 and nothing else about the
 * object - no path, no mime type, no size. The folder was implied by convention,
 * and the convention was not kept: the Employee Directory writes
 * `public/hp_staff_document/` while payroll writes `public/staff_document/`. A
 * reader had to know which feature produced a row before it could find its file.
 *
 * Three columns fix that, and they are the same three `task_documents` already
 * uses for exactly this purpose:
 *
 *   file_path   where the object really is, so a download does not guess
 *   mime_type   what to send it back as, taken from the BYTES at upload
 *   file_size   so a list can say "2.4 MB" without fetching the object
 *
 * ── NULLABLE, BECAUSE EIGHT LIVE ROWS PREDATE THEM ─────────────────────────
 *
 * Backfilling a path for the existing rows would be guessing which of the two
 * folders each one went to, and guessing wrong turns a recoverable document into
 * a 404. Null means "the old convention" and the reader falls back to it, which
 * is true rather than invented.
 *
 * ── MariaDB 10.1 ON LIVE ───────────────────────────────────────────────────
 *
 * `Schema::hasColumn()` asks for `information_schema.columns.generation_expression`,
 * absent in 10.1, and throws. Raw SQL, as everywhere else here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->tablePresent('staff_document')) {
            return;
        }

        Schema::table('staff_document', function (Blueprint $table) {
            if (!$this->columnPresent('staff_document', 'file_path')) {
                $table->string('file_path', 512)->nullable()->after('file_name');
            }

            if (!$this->columnPresent('staff_document', 'mime_type')) {
                $table->string('mime_type', 128)->nullable()->after('file_path');
            }

            if (!$this->columnPresent('staff_document', 'file_size')) {
                // Unsigned: a negative byte count is not a thing, and the 20 MB cap
                // means an int is ample.
                $table->unsignedBigInteger('file_size')->nullable()->after('mime_type');
            }
        });
    }

    /** Drops the three columns. The objects themselves are untouched. */
    public function down(): void
    {
        if (!$this->tablePresent('staff_document')) {
            return;
        }

        Schema::table('staff_document', function (Blueprint $table) {
            foreach (['file_path', 'mime_type', 'file_size'] as $column) {
                if ($this->columnPresent('staff_document', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function tablePresent(string $table): bool
    {
        $found = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );

        return (int) ($found->n ?? 0) > 0;
    }

    private function columnPresent(string $table, string $column): bool
    {
        $found = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );

        return (int) ($found->n ?? 0) > 0;
    }
};
