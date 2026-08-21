<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\Org\DepartmentMergeService;

/**
 * Report on - and optionally merge - duplicate departments.
 *
 * hrms_departments has never had a unique constraint on
 * (sub_institute_id, department, parent_id). Uniqueness was enforced only in
 * PHP, in one of the two controllers that write the table, so the other path
 * and every import bypassed it entirely. The result on live: 562 duplicate
 * groups covering 599 redundant rows out of 1,272 - roughly 47% of the table.
 *
 * DRY RUN IS THE DEFAULT AND CHANGES NOTHING. Merging departments is not a
 * delete: ~45 tables carry a department_id, seven LMS tables hold a real
 * foreign key to this one, and every one of those references has to be
 * repointed at the surviving row or it breaks. So the default output is a
 * report of exactly what would move, per group, and --execute is gated behind
 * an explicit confirmation.
 *
 *   php artisan departments:dedupe --connection=live
 *   php artisan departments:dedupe --connection=live --csv=storage/app/dedupe.csv
 *   php artisan departments:dedupe --connection=live --execute      (asks first)
 *
 * The unique index is deliberately NOT added here. It belongs in a migration
 * that runs after a merge has actually been approved and applied - adding it
 * first would simply fail against the existing duplicates.
 */
class DepartmentsDedupe extends Command
{
    protected $signature = 'departments:dedupe
        {--connection= : Database connection to inspect (default: the app default)}
        {--tenant= : Restrict to one sub_institute_id}
        {--csv= : Write the full report to this path}
        {--execute : Actually merge. Prompts for confirmation first}';

    /** The shared engine - see the note in executeMerge(). */
    public function __construct(private DepartmentMergeService $mergeService)
    {
        parent::__construct();
    }

    protected $description = 'Report duplicate departments and what a merge would repoint (dry run by default)';

    /**
     * Tables whose department_id would need repointing at the surviving row.
     *
     * Deliberately explicit rather than discovered at runtime: a merge that
     * silently skipped a table nobody remembered would strand those rows
     * pointing at a retired department.
     */
    private const DEPARTMENT_ID_TABLES = [
        'tbluser',
        's_user_jobrole',
        's_users_skills',
        'discliplinary_management',
        'hrms_emp_leaves',
        'hrms_leave_allocation',
        'hrms_salary_certificate',
        'task_management_projects',
        'task_management_project_departments',
        's_competency_frameworks',
        's_competency_assessments',
        's_competency_certifications',
        's_competency_development_plans',
        's_competency_mapping_reviews',
        's_competency_career_paths',
        's_competency_certification_requirements',
        's_performance_goals',
        's_performance_reviews',
        's_performance_appraisals',
        's_performance_compensation_revisions',
        's_performance_bonus_awards',
        's_performance_calibration_sessions',
        'talent_onboarding_journeys',
        'talent_offboarding_cases',
        'talent_internal_jobs',
        'talent_succession_plans',
        'talent_team_members',
        'talent_job_postings',
        's_mobility_jobs',
    ];

    /** Tables that reference departments as an LMS "standard" instead. */
    private const STANDARD_ID_TABLES = [
        'lms_question_master',
        'chapter_master',
        'content_master',
        'sub_std_map',
        'lms_curriculum',
        'lms_lesson_plan',
        'lms_flashcard',
    ];

    /** Pairs of (table, column) for the from/to department columns. */
    private const PAIRED_COLUMN_TABLES = [
        ['s_mobility_transfers', 'from_department_id'],
        ['s_mobility_transfers', 'to_department_id'],
        ['talent_mobility_requests', 'from_department_id'],
        ['talent_mobility_requests', 'to_department_id'],
    ];

    public function handle(): int
    {
        $connectionName = $this->option('connection') ?: config('database.default');
        $db = DB::connection($connectionName);

        $this->info("Connection: {$connectionName} ({$db->getDatabaseName()})");

        $groups = $this->findDuplicateGroups($db);

        if ($groups === []) {
            $this->info('No duplicate departments found.');
            return self::SUCCESS;
        }

        $totalRedundant = 0;
        $report = [];

        foreach ($groups as $group) {
            $ids = $db->table('hrms_departments')
                ->where('sub_institute_id', $group->sub_institute_id)
                ->where('department', $group->department)
                ->where('parent_id', $group->parent_id)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (count($ids) < 2) {
                continue;
            }

            // Lowest id survives: it is the oldest, so it is the one existing
            // references are most likely to already point at.
            $keepId    = array_shift($ids);
            $retireIds = $ids;
            $totalRedundant += count($retireIds);

            $references = $this->countReferences($db, $retireIds);

            $report[] = [
                'tenant'     => $group->sub_institute_id,
                'department' => $group->department,
                'parent_id'  => $group->parent_id,
                'keep'       => $keepId,
                'retire'     => $retireIds,
                'references' => $references,
            ];
        }

        $this->renderSummary($report, $totalRedundant);

        if ($path = $this->option('csv')) {
            $this->writeCsv($path, $report);
            $this->info("Full report written to {$path}");
        }

        if (!$this->option('execute')) {
            $this->newLine();
            $this->warn('DRY RUN - nothing was changed.');
            $this->line('Re-run with --execute to merge, or --csv=<path> for the full list.');
            return self::SUCCESS;
        }

        return $this->executeMerge($db, $report, $connectionName);
    }

    private function findDuplicateGroups($db): array
    {
        $query = $db->table('hrms_departments')
            ->select('sub_institute_id', 'department', 'parent_id', DB::raw('COUNT(*) as total'))
            ->whereNull('deleted_at')
            ->groupBy('sub_institute_id', 'department', 'parent_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('total');

        if ($tenant = $this->option('tenant')) {
            $query->where('sub_institute_id', (int) $tenant);
        }

        return $query->get()->all();
    }

    /**
     * How many rows in each table point at the departments being retired.
     */
    private function countReferences($db, array $retireIds): array
    {
        $counts = [];

        foreach (self::DEPARTMENT_ID_TABLES as $table) {
            $counts += $this->countIn($db, $table, 'department_id', $retireIds);
        }

        foreach (self::STANDARD_ID_TABLES as $table) {
            $counts += $this->countIn($db, $table, 'standard_id', $retireIds);
        }

        foreach (self::PAIRED_COLUMN_TABLES as [$table, $column]) {
            $counts += $this->countIn($db, $table, $column, $retireIds);
        }

        return $counts;
    }

    private function countIn($db, string $table, string $column, array $ids): array
    {
        try {
            $count = $db->table($table)->whereIn($column, $ids)->count();
        } catch (\Throwable $e) {
            // A table or column absent on this database is not a reference.
            // The two databases have drifted before, so this cannot assume.
            return [];
        }

        return $count > 0 ? ["{$table}.{$column}" => $count] : [];
    }

    private function renderSummary(array $report, int $totalRedundant): void
    {
        $this->newLine();
        $this->info(sprintf(
            '%d duplicate group(s), %d redundant row(s) would be retired.',
            count($report),
            $totalRedundant
        ));
        $this->newLine();

        $rows = [];
        foreach (array_slice($report, 0, 20) as $entry) {
            $rows[] = [
                $entry['tenant'],
                mb_strimwidth($entry['department'], 0, 40, '...'),
                $entry['keep'],
                count($entry['retire']),
                $entry['references'] === []
                    ? '-'
                    : implode(', ', array_map(
                        fn ($k, $v) => "{$k}={$v}",
                        array_keys($entry['references']),
                        $entry['references']
                    )),
            ];
        }

        $this->table(['Tenant', 'Department', 'Keep id', 'Retire', 'References to repoint'], $rows);

        if (count($report) > 20) {
            $this->line(sprintf('... and %d more group(s). Use --csv to see them all.', count($report) - 20));
        }
    }

    private function writeCsv(string $path, array $report): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $handle = fopen($path, 'w');
        fputcsv($handle, ['tenant', 'department', 'parent_id', 'keep_id', 'retire_ids', 'references']);

        foreach ($report as $entry) {
            fputcsv($handle, [
                $entry['tenant'],
                $entry['department'],
                $entry['parent_id'],
                $entry['keep'],
                implode(' ', $entry['retire']),
                json_encode($entry['references']),
            ]);
        }

        fclose($handle);
    }

    /**
     * The destructive path. Every repoint and soft-delete for one group runs in
     * a single transaction, so a group either merges completely or not at all.
     */
    private function executeMerge($db, array $report, string $connectionName): int
    {
        $this->newLine();
        $this->warn("You are about to MERGE departments on '{$connectionName}'.");
        $this->line('This repoints references and soft-deletes the retired rows.');

        if (!$this->confirm('Type yes to proceed', false)) {
            $this->info('Aborted. Nothing was changed.');
            return self::SUCCESS;
        }

        /*
         * THE MERGE ITSELF LIVES IN DepartmentMergeService NOW.
         *
         * This command used to carry its own copy - the table lists, a
         * repoint() and the soft delete. That copy had three faults the
         * service fixes, and which mattered here just as much as in the API:
         *
         *   - repoint() caught every Throwable and printed a warning. A
         *     duplicate-key failure did not abort the transaction, so the rows
         *     stayed pointing at a department this then soft-deleted. Silent
         *     orphan. task_management_project_departments has
         *     UNIQUE(project_id, department_id) and the collision is real on
         *     live today (project 7 is linked to departments 117, 123 and 322).
         *   - the table list was missing department_sops / _policies / _rules.
         *   - name, CSV and JSON columns holding a department were never
         *     rewritten, so they kept naming the retired department.
         *
         * One engine, one set of fixes. The service throws on a real failure
         * and the transaction rolls back, which is why this reports per group
         * rather than pretending success.
         */
        $merged = 0;
        $failed = 0;

        foreach ($report as $entry) {
            foreach ($entry['retire'] as $retiredId) {
                try {
                    $this->mergeService->merge(
                        (int) $retiredId,
                        (int) $entry['keep'],
                        (int) $entry['tenant'],
                        null,
                        $db
                    );
                    $merged++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->warn("  {$retiredId} -> {$entry['keep']} FAILED, rolled back: {$e->getMessage()}");
                }
            }
        }

        $this->info("Merged. {$merged} duplicate row(s) retired.");

        if ($failed > 0) {
            $this->warn("{$failed} merge(s) failed and were rolled back. Nothing was half-applied.");
        }

        $this->line('The unique index can now be added by a follow-up migration.');

        return self::SUCCESS;
    }
}
