<?php

namespace App\Console\Commands;

use App\Services\Lms\EnrolmentWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give every existing assignment the enrolment it should always have had.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * Assigning a course wrote `lms_assignments`; the learner's course list reads
 * `lms_course_enroll`; nothing joined them. Measured on live 2026-09-02: 58
 * assignment rows, and 58 of them had no matching enrolment. Every course
 * anybody had ever assigned was invisible to the person it was assigned to.
 *
 * The code path is fixed. This is the history.
 *
 * ── WHY A COMMAND AND NOT A MIGRATION ───────────────────────────────────────
 *
 * It writes live customer rows. A migration would run once, silently, with no
 * dry run and no way to look before leaping. This is dry-run BY DEFAULT, prints
 * exactly what it would do, and is safe to run repeatedly - which matters,
 * because the gap keeps growing until the fix is deployed and this may need
 * running again afterwards.
 *
 *   php artisan lms:backfill-enrolments                      # dry run, dev
 *   php artisan lms:backfill-enrolments --database=live      # dry run, live
 *   php artisan lms:backfill-enrolments --database=live --execute
 *
 * Expected on live at the time of writing: 58 creates. A different number
 * means the data moved - stop and re-measure rather than pressing on.
 */
class LmsBackfillEnrolments extends Command
{
    protected $signature = 'lms:backfill-enrolments
        {--execute : Actually write. Without this nothing changes.}
        {--database= : Connection to work on (default: the app default).}
        {--tenant= : Restrict to one sub_institute_id.}
        {--details : List every row, not just the counts.}';

    protected $description = 'Create the missing lms_course_enroll row for each existing assignment.';

    public function handle(EnrolmentWriter $enrolments): int
    {
        $connection = $this->option('database') ?: config('database.default');
        $execute = (bool) $this->option('execute');
        $tenant = $this->option('tenant');

        $this->info(sprintf(
            'Connection: %s   Mode: %s%s',
            $connection,
            $execute ? 'EXECUTE (will write)' : 'DRY RUN (nothing will change)',
            $tenant ? "   Tenant: {$tenant}" : '',
        ));

        $orphans = DB::connection($connection)
            ->table('lms_assignments as a')
            ->leftJoin('lms_course_enroll as e', function ($join) {
                $join->on('e.user_id', '=', 'a.user_id')
                     ->on('e.course_id', '=', 'a.course_id')
                     ->whereNull('e.deleted_at');
            })
            ->whereNull('a.deleted_at')
            ->whereNull('e.id')
            ->when($tenant, fn ($q) => $q->where('a.sub_institute_id', $tenant))
            ->select('a.id', 'a.user_id', 'a.course_id', 'a.sub_institute_id', 'a.approval_status')
            ->orderBy('a.id')
            ->get();

        if ($orphans->isEmpty()) {
            $this->info('Nothing to do - every assignment already has an enrolment.');
            return self::SUCCESS;
        }

        $created = 0;
        $skippedOrphan = 0;
        $rows = [];

        foreach ($orphans as $row) {
            $tenantId = (int) $row->sub_institute_id;

            /*
             * A pending REQUEST becomes a pending enrolment, not an approved
             * one. Backfilling somebody straight to 'enrolled' would grant
             * access an admin has not yet given.
             */
            $status = ($row->approval_status ?? 'approved') === 'pending' ? 'pending' : 'enrolled';

            // Does the pair still resolve inside its own tenant? An assignment
            // whose user or course has since been deleted or moved is reported,
            // never invented.
            $resolvable = DB::connection($connection)->table('sub_std_map')
                    ->where('id', $row->course_id)->where('sub_institute_id', $tenantId)
                    ->whereNull('deleted_at')->exists()
                && DB::connection($connection)->table('tbluser')
                    ->where('id', $row->user_id)->where('sub_institute_id', $tenantId)->exists();

            if (! $resolvable) {
                $skippedOrphan++;
                $rows[] = [$row->id, $row->user_id, $row->course_id, $tenantId, 'skip-orphan'];
                continue;
            }

            $rows[] = [$row->id, $row->user_id, $row->course_id, $tenantId, 'create (' . $status . ')'];

            if ($execute) {
                /*
                 * The writer, not a raw insert - so the backfill obeys exactly
                 * the same rules as a live assignment: tenant checks, no
                 * downgrade, always stamping sub_institute_id.
                 */
                DB::connection($connection)->transaction(function () use ($enrolments, $connection, $row, $tenantId, $status) {
                    // ->on($connection) is load-bearing: without it the writer
                    // uses the DEFAULT connection and a --database=live run
                    // reads and writes dev while reporting success.
                    $enrolments->on($connection)->ensureEnrolment(
                        (int) $row->user_id,
                        (int) $row->course_id,
                        $tenantId,
                        $status,
                    );
                });
            }

            $created++;
        }

        if ($this->option('details')) {
            $this->table(['assignment', 'user', 'course', 'tenant', 'action'], $rows);
        }

        $this->newLine();
        $this->info(sprintf(
            '%s: %d   skip-orphan: %d   examined: %d',
            $execute ? 'created' : 'would create',
            $created,
            $skippedOrphan,
            $orphans->count(),
        ));

        if (! $execute) {
            $this->comment('Dry run. Re-run with --execute to write.');
        }

        return self::SUCCESS;
    }
}
