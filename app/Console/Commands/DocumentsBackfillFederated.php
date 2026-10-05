<?php

namespace App\Console\Commands;

use App\Services\Documents\Federation\CompetencyEvidenceIndexer;
use App\Services\Documents\Federation\DocumentIndexer;
use App\Services\Documents\Federation\LmsCertificateIndexer;
use App\Services\Documents\Federation\OffboardingDocumentIndexer;
use App\Services\Documents\Federation\OnboardingDocumentIndexer;
use App\Services\Documents\Federation\TaskDocumentIndexer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Index every EXISTING row in the five federated silos into
 * `document_library`, once. New rows are kept current going forward by the
 * indexer calls each silo's own controller now makes on write (see
 * `OnboardingDocumentController`, `CertificationController`,
 * `TaskDocumentController`, `OffboardingController`, `LmsLearningController`)
 * - this command only catches up what already existed before those calls
 * were added, the same relationship `documents:backfill-staff-documents` has
 * to `EmployeeDocumentController`.
 *
 * Unlike that command, this one is CHEAP to run for real immediately: the
 * row counts across all five silos on this environment are in the tens, not
 * the hundreds, so there is no dry-run-by-default ceremony here - it always
 * writes. Safe to re-run: every indexer upserts on its own
 * (source_system, source_table, source_id) triple (see `DocumentIndexer`'s
 * docblock), so running this twice updates the same rows rather than
 * duplicating them.
 *
 *   php artisan documents:backfill-federated
 *   php artisan documents:backfill-federated --source=onboarding
 *   php artisan documents:backfill-federated --tenant=7
 *   php artisan documents:backfill-federated --database=live
 */
class DocumentsBackfillFederated extends Command
{
    protected $signature = 'documents:backfill-federated
        {--source=   : onboarding|competency|task|offboarding|lms (omit for all five)}
        {--tenant=   : Restrict to one sub_institute_id.}
        {--database= : Connection to run against (default: the app default).}';

    protected $description = 'Index existing onboarding/competency/task/offboarding/LMS-certificate rows into document_library.';

    public function handle(): int
    {
        if ($this->option('database')) {
            DB::setDefaultConnection($this->option('database'));
            $this->line('  connection: ' . $this->option('database'));
        }

        $tenant = $this->option('tenant') ? (int) $this->option('tenant') : null;
        $only = (string) ($this->option('source') ?? '');

        $indexer = new DocumentIndexer();

        $runners = [
            'onboarding' => fn () => (new OnboardingDocumentIndexer($indexer))->indexAll($tenant),
            'competency' => fn () => (new CompetencyEvidenceIndexer($indexer))->indexAll($tenant),
            'task' => fn () => (new TaskDocumentIndexer($indexer))->indexAll($tenant),
            'offboarding' => fn () => (new OffboardingDocumentIndexer($indexer))->indexAll($tenant),
            'lms' => fn () => (new LmsCertificateIndexer($indexer))->indexAll($tenant),
        ];

        $this->line('');
        $total = 0;

        foreach ($runners as $name => $run) {
            if ($only !== '' && $only !== $name) {
                continue;
            }

            try {
                $count = $run();
                $total += $count;
                $this->line(sprintf('  %-12s %d row(s) indexed', $name, $count));
            } catch (\Throwable $e) {
                $this->error(sprintf('  %-12s FAILED: %s', $name, $e->getMessage()));
            }
        }

        $this->line('');
        $this->info($total . ' row(s) indexed in total.');

        return self::SUCCESS;
    }
}
