<?php

namespace App\Console\Commands;

use App\Services\Documents\DocumentStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Permanently remove document_library rows that have sat in trash past the
 * retention window (config('documents.trash.purge_days'), 30 by default).
 *
 * ── WHY THIS EXISTS AT ALL ───────────────────────────────────────────────────
 *
 * `destroy()`/`destroyForEmployee()` only ever soft-delete (deleted_by +
 * deleted_at) - deliberately, so a trash view can offer restore. Nothing
 * ever turned that into a real deletion before this; without it, trash would
 * grow forever.
 *
 * ── WHAT "PERMANENT" ACTUALLY HAS TO CLEAN UP ────────────────────────────────
 *
 * `document_library_history` cascades automatically when the parent row is
 * deleted (onDelete('CASCADE'), see that migration). The storage OBJECTS
 * those history rows point at do not - a row with 4 versions has 4 separate
 * files on disk/DigitalOcean, and only the CURRENT one is named on the live
 * row's own storage_path. Every version's file is removed here before the
 * row goes, not just the current one.
 *
 * ── BOTH CONNECTIONS, BY DEFAULT ─────────────────────────────────────────────
 *
 * Trash accumulates on both `mysql` (default) and `live` - omitting
 * --database loops both rather than requiring the scheduler to remember to
 * run this twice.
 *
 *   php artisan documents:purge-trash
 *   php artisan documents:purge-trash --execute
 *   php artisan documents:purge-trash --execute --database=live
 *   php artisan documents:purge-trash --execute --days=60
 */
class DocumentsPurgeTrash extends Command
{
    protected $signature = 'documents:purge-trash
        {--execute  : Actually delete. Without this nothing is changed.}
        {--database= : Connection to run against. Omitted = both mysql and live, in turn.}
        {--days=    : Override config(documents.trash.purge_days) for this run.}';

    protected $description = 'Permanently delete document_library rows whose trash retention window has passed (dry-run by default).';

    public function handle(): int
    {
        $connections = $this->option('database')
            ? [$this->option('database')]
            : ['mysql', 'live'];

        $execute = (bool) $this->option('execute');
        $days = $this->option('days') !== null ? (int) $this->option('days') : (int) config('documents.trash.purge_days', 30);

        $this->line('');
        $this->info($execute ? 'PURGING trash' : 'DRY RUN - nothing will be deleted');
        $this->line('  retention: ' . $days . ' day(s)');
        $this->line('');

        $totalDeleted = 0;

        foreach ($connections as $name) {
            $totalDeleted += $this->purgeConnection($name, $days, $execute);
        }

        $this->line('');
        $this->line(sprintf('  %s %s document(s) total', $execute ? 'deleted' : 'would delete', number_format($totalDeleted)));

        if (!$execute) {
            $this->line('  Re-run with --execute to actually purge.');
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function purgeConnection(string $name, int $days, bool $execute): int
    {
        $db = DB::connection($name);
        $storage = new DocumentStorageService();

        $this->line('  [' . $name . '] ' . $db->getDatabaseName());

        $cutoff = now()->subDays($days);
        $deleted = 0;

        // Chunked, not a single get() - trash can grow large between runs,
        // and this must not hold the whole table in memory.
        $db->table('document_library')
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($db, $storage, $execute, &$deleted) {
                foreach ($rows as $row) {
                    $paths = $db->table('document_library_history')
                        ->where('document_id', $row->id)
                        ->whereNotNull('storage_path')
                        ->pluck('storage_path')
                        ->push($row->storage_path)
                        ->filter()
                        ->unique();

                    if ($execute) {
                        foreach ($paths as $path) {
                            try {
                                if ($storage->exists($path)) {
                                    $storage->delete($path);
                                }
                            } catch (\Throwable $e) {
                                report($e);
                            }
                        }

                        // document_library_history cascades automatically
                        // (onDelete('CASCADE')) - only the parent needs deleting.
                        $db->table('document_library')->where('id', $row->id)->delete();
                    }

                    $deleted++;
                }
            });

        $this->line('    ' . ($execute ? 'deleted' : 'would delete') . ' ' . number_format($deleted) . ' row(s)');

        return $deleted;
    }
}
