<?php

namespace App\Console\Commands;

use App\Services\Documents\Extraction\TextExtractionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Copy every live `staff_document` row into `document_library`, once.
 *
 * ── WHY A COMMAND, NOT A MIGRATION ─────────────────────────────────────────
 *
 * A schema migration runs once, silently, at deploy time, and is awkward to
 * re-run safely. This is a DATA move across an unknown number of live rows
 * on three different databases (see the "G2G database hosts" note in this
 * project's own operating history), and it needs to be inspectable,
 * re-runnable, and safe to run again after new staff_document rows have
 * appeared mid-migration. A console command, following the same dry-run-by-
 * default convention as `jobroles:backfill-ids`, is that.
 *
 * ── IDEMPOTENT BY CONSTRUCTION ──────────────────────────────────────────────
 *
 * Every row this command writes carries `source_system = 'staff_document'`,
 * `source_table = 'staff_document'`, `source_id = <the old row's id>`, and
 * `document_library` has a UNIQUE constraint on that triple
 * (`document_library_source_unique`). Re-running this command only ever
 * inserts staff_document rows that have not been copied yet - it is safe to
 * run on a schedule until `staff_document` is fully retired, and safe to run
 * twice by accident.
 *
 * ── WHAT "FROZEN" MEANS AFTER THIS RUNS ─────────────────────────────────────
 *
 * `staff_document` itself is untouched - nothing is deleted or altered on
 * it. "Frozen" describes the APPLICATION: EmployeeDocumentController,
 * PayrollController's payslip path and OfferLetterFiler have all been
 * repointed at document_library (see their own docblocks), so no NEW row
 * lands in staff_document from this point forward. This command only
 * catches up what already existed before that cutover.
 *
 * ── FILE PATH RESOLUTION ────────────────────────────────────────────────────
 *
 * `staff_document.file_path` has only been recorded since 2026-09-24 (see
 * that migration's docblock); older rows carry only `file_name` and the
 * folder has to be found by trying every convention this table's writers
 * ever used, in the same order `EmployeeDocumentController::objectPath()`
 * did before it was retired. A row whose file cannot be found anywhere is
 * still copied (metadata is still useful - search, listing) with a warning
 * recorded rather than being silently skipped.
 *
 *   php artisan documents:backfill-staff-documents
 *   php artisan documents:backfill-staff-documents --execute
 *   php artisan documents:backfill-staff-documents --execute --tenant=6
 */
class DocumentsBackfillStaffDocuments extends Command
{
    protected $signature = 'documents:backfill-staff-documents
        {--execute        : Actually write the rows. Without this nothing is changed.}
        {--database=       : Connection to run against (default: the app default). E.g. --database=live.}
        {--tenant=         : Restrict to one sub_institute_id.}
        {--limit=          : Stop after this many rows (for a first trial run).}
        {--reprocess-text : Re-run extraction on already-backfilled rows that have no extracted_text yet (e.g. after an extractor was added), instead of copying new rows.}';

    protected $description = 'Copy staff_document rows into document_library (idempotent; dry-run by default).';

    private const LEGACY_FOLDERS = [
        'public/hp_staff_document/',
        'public/staff_document/',
    ];

    public function handle(): int
    {
        if ($this->option('reprocess-text')) {
            return $this->reprocessText();
        }

        $db = $this->connection();
        $execute = (bool) $this->option('execute');
        $tenant = $this->option('tenant') ? (int) $this->option('tenant') : null;
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->line('');
        $this->info($execute ? 'WRITING document_library rows' : 'DRY RUN - nothing will be written');
        $this->line('  connection: ' . $db->getName() . ' (' . $db->getDatabaseName() . ')');
        $this->line('');

        $alreadyIds = $db->table('document_library')
            ->where('source_system', 'staff_document')
            ->pluck('source_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $query = $db->table('staff_document as d')
            ->leftJoin('student_document_type as t', 't.id', '=', 'd.document_type_id')
            ->whereNull('d.deleted_at')
            ->when($tenant, fn ($q) => $q->where('d.sub_institute_id', $tenant))
            ->when($alreadyIds !== [], fn ($q) => $q->whereNotIn('d.id', $alreadyIds))
            ->orderBy('d.id')
            ->select([
                'd.id', 'd.user_id', 'd.document_type_id', 'd.document_title', 'd.file_name',
                'd.file_path', 'd.mime_type', 'd.file_size', 'd.sub_institute_id',
                'd.created_by', 'd.created_at', 't.document_type',
            ]);

        if ($limit) {
            $query->limit($limit);
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->line('Nothing to backfill - every staff_document row is already copied.');

            return self::SUCCESS;
        }

        $this->line(sprintf('  %s row(s) to copy', number_format($rows->count())));
        $this->line('');

        $disk = Storage::disk((string) config('documents.disk', 'digitalocean'));
        $extractor = new TextExtractionManager();

        $copied = 0;
        $missingFile = 0;

        foreach ($rows as $row) {
            $path = $this->resolvePath($disk, $row);
            $extension = strtolower(pathinfo((string) $row->file_name, PATHINFO_EXTENSION) ?: '');

            if ($path === null) {
                $missingFile++;
            }

            $documentType = $this->mapDocumentType((int) $row->document_type_id, $row->document_type);

            $extractedText = null;
            $size = $row->file_size ? (int) $row->file_size : null;
            $checksum = null;

            if ($execute && $path !== null) {
                try {
                    $bytes = $disk->get($path);
                    $size = $size ?: strlen($bytes);
                    $checksum = hash('sha256', $bytes);

                    if ($extractor->isTextBearing($extension)) {
                        $local = tempnam(sys_get_temp_dir(), 'backfill_');
                        file_put_contents($local, $bytes);
                        $extractedText = $extractor->extract($local, $extension) ?: null;
                        @unlink($local);
                    }
                } catch (\Throwable $e) {
                    // The row is still copied without content/checksum - a
                    // document that cannot be read right now is better kept
                    // searchable by title than dropped from the backfill.
                }
            }

            if ($execute) {
                $db->table('document_library')->insert([
                    'sub_institute_id' => (int) $row->sub_institute_id,
                    'owner_id' => (int) $row->user_id,
                    'title' => $row->document_title ?: 'Untitled',
                    'original_file_name' => $row->file_name,
                    'mime_type' => $row->mime_type,
                    'size' => $size,
                    'checksum_sha256' => $checksum,
                    'storage_path' => $path,
                    'current_version' => 1,
                    'category' => 'personnel',
                    'document_type' => $documentType,
                    'extracted_text' => $extractedText,
                    'visibility' => 'private',
                    'processing_status' => 'done',
                    'warnings' => $path === null ? json_encode(['file_missing_at_backfill' => true]) : null,
                    'source_system' => 'staff_document',
                    'source_table' => 'staff_document',
                    'source_id' => (int) $row->id,
                    'created_by' => $row->created_by,
                    'created_at' => $row->created_at ?: now(),
                    'updated_at' => now(),
                ]);
            }

            $copied++;
        }

        $this->line('');
        $this->line(sprintf('  %s %s', $execute ? 'copied' : 'would copy', number_format($copied)));

        if ($missingFile > 0) {
            $this->warn(sprintf('  %s row(s) have no file at any known path - copied with a warning, not skipped.', number_format($missingFile)));
        }

        if (!$execute) {
            $this->line('  Re-run with --execute to write these rows.');
        }

        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Re-run extraction on backfilled rows still missing `extracted_text`.
     *
     * Separate from the main copy pass (which never revisits an id already
     * in document_library - that is what keeps it idempotent): this exists
     * for the case where an extractor was added or fixed AFTER a backfill
     * ran (e.g. PlainTextExtractor did not exist yet when .txt offer-letter
     * stand-ins were first copied), so those rows are the ones reprocessed,
     * not re-copied.
     */
    private function reprocessText(): int
    {
        $db = $this->connection();
        $execute = (bool) $this->option('execute');
        $tenant = $this->option('tenant') ? (int) $this->option('tenant') : null;
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->line('');
        $this->info($execute ? 'RE-EXTRACTING text' : 'DRY RUN - nothing will be written');
        $this->line('  connection: ' . $db->getName() . ' (' . $db->getDatabaseName() . ')');
        $this->line('');

        $query = $db->table('document_library')
            ->where('source_system', 'staff_document')
            ->whereNull('extracted_text')
            ->whereNotNull('storage_path')
            ->when($tenant, fn ($q) => $q->where('sub_institute_id', $tenant))
            ->orderBy('id');

        if ($limit) {
            $query->limit($limit);
        }

        $rows = $query->get(['id', 'storage_path', 'original_file_name']);

        if ($rows->isEmpty()) {
            $this->line('Nothing to reprocess.');

            return self::SUCCESS;
        }

        $disk = Storage::disk((string) config('documents.disk', 'digitalocean'));
        $extractor = new TextExtractionManager();
        $updated = 0;
        $stillEmpty = 0;

        foreach ($rows as $row) {
            $extension = strtolower(pathinfo((string) $row->original_file_name, PATHINFO_EXTENSION) ?: pathinfo((string) $row->storage_path, PATHINFO_EXTENSION));

            if (!$extractor->isTextBearing($extension) || !$disk->exists($row->storage_path)) {
                $stillEmpty++;

                continue;
            }

            try {
                $local = tempnam(sys_get_temp_dir(), 'reprocess_');
                file_put_contents($local, $disk->get($row->storage_path));
                $text = $extractor->extract($local, $extension);
                @unlink($local);
            } catch (\Throwable $e) {
                $stillEmpty++;

                continue;
            }

            if ($text === '') {
                $stillEmpty++;

                continue;
            }

            if ($execute) {
                $db->table('document_library')->where('id', $row->id)->update(['extracted_text' => $text]);
            }

            $updated++;
        }

        $this->line('');
        $this->line(sprintf('  %s %s row(s) with text', $execute ? 'updated' : 'would update', number_format($updated)));
        if ($stillEmpty > 0) {
            $this->line(sprintf('  %s row(s) still have no extractable text (no extractor for their type, or genuinely empty).', number_format($stillEmpty)));
        }
        if (!$execute) {
            $this->line('  Re-run with --execute to write these.');
        }
        $this->line('');

        return self::SUCCESS;
    }

    private function connection()
    {
        return $this->option('database')
            ? DB::connection($this->option('database'))
            : DB::connection();
    }

    private function resolvePath($disk, object $row): ?string
    {
        if (!empty($row->file_path) && $disk->exists($row->file_path)) {
            return $row->file_path;
        }

        if (empty($row->file_name)) {
            return null;
        }

        foreach (self::LEGACY_FOLDERS as $folder) {
            $candidate = $folder . $row->file_name;
            if ($disk->exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** student_document_type's name, mapped onto config('documents.types') - or 'other' if unmapped. */
    private function mapDocumentType(int $typeId, ?string $legacyName): string
    {
        // The two names already known by code (PayrollController hardcodes
        // 56 for payslip; OfferLetterFiler resolved 'offer' by name).
        if ($typeId === 56) {
            return 'payslip';
        }

        $legacyName = strtolower(trim((string) $legacyName));

        $map = [
            'offer' => 'offer_letter',
            'resume' => 'resume',
            'certificate' => 'certificate',
            'id proof' => 'id_proof',
            'identity proof' => 'id_proof',
        ];

        return $map[$legacyName] ?? 'other';
    }
}
