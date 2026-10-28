<?php

namespace App\Console\Commands;

use App\Services\Events\EventRecorder;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Emit `learning.assignment_due_soon` (and, audit-only, `learning.assignment_
 * overdue`) for `lms_assignments` crossing a reminder window.
 *
 * ── THE GAP THIS FILLS ──────────────────────────────────────────────────────
 *
 * `lms_assignments.due_date` has existed since the table's first migration and
 * nothing has ever read it against today's date. CapabilityGapResponder (X-16)
 * is the first writer to fill it with any regularity; without this sweep a
 * learner who never opens My Learning again would simply drift past their due
 * date with nobody told.
 *
 * ── MODELLED ON ScanCertificationExpiry, NOT ScanDepartmentProcessDueSteps ──
 *
 * ScanCertificationExpiry emits into g2g_event and relies on the unique
 * idempotency_key to make a daily re-sweep free - exactly the shape this
 * command needs, since lms_assignments carries no idempotency column of its
 * own. Three pieces of its reasoning are carried over verbatim:
 *
 *   `<= window`, not `== window` - a missed scheduler day is caught up on the
 *   next run, with the idempotency key keeping it to one emission.
 *
 *   TIGHTEST UNMET WINDOW ONLY - emitting both T-7 and T-1 today would
 *   announce a warning that was due six days ago, i.e. backdated history in
 *   an append-only store.
 *
 *   SET THE CONNECTION BEFORE ANY QUERY - a scan of live must not append
 *   events to dev.
 *
 * ── WHY "DUE SOON" SHIPS BUT "OVERDUE" DOES NOT (YET) ───────────────────────
 *
 * `learning.assignment_overdue` is projected into g2g_audit_log only - it is
 * deliberately NOT added to NotificationDispatcher::NOTIFIES here. This is the
 * same discipline `task.overdue` was held to: that event stayed
 * DEFERRED_INDEFINITELY after being measured at 91.9% overdue, because a
 * notification that fires thousands of times on day one teaches people to
 * ignore the channel. `lms_assignments.due_date` population and the real
 * overdue count must be measured on live before this is switched on - that is
 * a product decision this command does not make for itself.
 *
 * ── WHAT "COMPLETE" MEANS, AND WHY IT IS lms_course_enroll, NOT THIS TABLE ──
 *
 * `lms_assignments.progress`/`status` are written at assignment time and not
 * reliably kept in step afterwards - LearningAssigner's own header records
 * that 52 of 58 live assignment rows were stranded with no matching enrolment
 * at all. `lms_course_enroll.status = 'completed'` is the signal My Learning
 * itself reads, so it is the one this sweep trusts to mean "done".
 */
class ScanLearningAssignmentsDue extends Command
{
    protected $signature = 'learning:scan-due-assignments
                            {--windows=7,1 : Comma-separated day thresholds, used when no tenant override applies}
                            {--limit=1000 : Maximum assignments examined per run}
                            {--database= : Connection to sweep; omit for the default}
                            {--dry-run : Report what would be emitted, write nothing}';

    protected $description = 'Emit learning.assignment_due_soon / learning.assignment_overdue for lms_assignments crossing a reminder window.';

    public function __construct(private readonly EventRecorder $recorder)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Set BEFORE any query - see the class header.
        $connection = (string) ($this->option('database') ?? '');
        if ($connection !== '') {
            DB::setDefaultConnection($connection);
            $this->line("  using connection: {$connection}");
        }

        $dry = (bool) $this->option('dry-run');
        $today = now()->toDateString();
        $limit = max(1, (int) $this->option('limit'));
        $windows = $this->windows((string) $this->option('windows'));

        if ($windows === []) {
            $this->error('No valid windows given.');

            return self::FAILURE;
        }

        $rows = DB::table('lms_assignments as a')
            ->leftJoin('lms_course_enroll as e', function ($join) {
                $join->on('e.user_id', '=', 'a.user_id')
                    ->on('e.course_id', '=', 'a.course_id')
                    ->whereNull('e.deleted_at');
            })
            ->leftJoin('sub_std_map as c', 'c.id', '=', 'a.course_id')
            ->whereNull('a.deleted_at')
            ->whereNotNull('a.due_date')
            ->where('a.approval_status', 'approved')
            // Nothing a scheduler can sensibly act on without a tenant.
            ->whereNotNull('a.sub_institute_id')
            // Not already finished, by the signal My Learning itself trusts.
            ->where(function ($q) {
                $q->whereNull('e.status')->orWhere('e.status', '!=', 'completed');
            })
            ->orderBy('a.due_date')
            ->limit($limit)
            ->get([
                'a.id', 'a.sub_institute_id', 'a.user_id', 'a.course_id', 'a.due_date', 'a.assignment_type',
                'c.display_name as course_name',
            ]);

        $dueSoon = 0;
        $overdue = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $tenant = (int) $row->sub_institute_id;

            if ($tenant <= 0) {
                $skipped++;
                continue;
            }

            $days = (int) floor((strtotime((string) $row->due_date) - strtotime($today)) / 86400);

            if ($days < 0) {
                $skipped += $this->emitOverdue($row, $tenant, -$days, $dry) ? 0 : 1;
                $overdue += $dry ? 0 : 1;
                continue;
            }

            // Tightest window this assignment has already crossed.
            $applicable = array_filter($windows, fn ($w) => $days <= $w);

            if ($applicable === []) {
                continue;
            }

            $window = min($applicable);

            if ($this->emitDueSoon($row, $tenant, $days, $window, $dry)) {
                $dueSoon++;
            } else {
                $skipped++;
            }
        }

        $this->info(sprintf(
            '%s %d assignment(s) examined, %d due-soon emitted, %d overdue emitted, %d already known.',
            $dry ? 'Dry run:' : 'Scanned:',
            $rows->count(),
            $dueSoon,
            $overdue,
            $skipped
        ));

        if (($dueSoon > 0 || $overdue > 0) && !$dry) {
            Log::channel('single')->info('learning.due_date_scan', [
                'examined' => $rows->count(),
                'due_soon' => $dueSoon,
                'overdue' => $overdue,
            ]);
        }

        return self::SUCCESS;
    }

    private function emitDueSoon(object $row, int $tenant, int $days, int $window, bool $dry): bool
    {
        if ($dry) {
            $this->line(sprintf(
                '    would emit  assignment #%-6d T-%-3d (%d day(s) left)  %s',
                $row->id,
                $window,
                $days,
                $row->course_name
            ));

            return true;
        }

        $key = 'learning.assignment_due_soon:' . $tenant . ':' . (int) $row->id . ':' . $window;

        return $this->emit('learning.assignment_due_soon', $row, $tenant, $key, [
            'assignment_id' => (int) $row->id,
            'user_id' => (int) $row->user_id,
            'course_id' => (int) $row->course_id,
            // `course_name`, matching the notification template's
            // {payload.course_name} placeholder exactly - see
            // ScanCertificationExpiry's own note on why the key naming is
            // load-bearing, not cosmetic.
            'course_name' => $row->course_name,
            'due_date' => (string) $row->due_date,
            'days_remaining' => $days,
            'window_days' => $window,
            'assignment_type' => $row->assignment_type,
        ]);
    }

    /** Audit-only - see the class header on why this does not notify yet. */
    private function emitOverdue(object $row, int $tenant, int $daysOverdue, bool $dry): bool
    {
        if ($dry) {
            $this->line(sprintf(
                '    would emit  assignment #%-6d OVERDUE by %d day(s)  %s',
                $row->id,
                $daysOverdue,
                $row->course_name
            ));

            return true;
        }

        $key = 'learning.assignment_overdue:' . $tenant . ':' . (int) $row->id . ':overdue';

        return $this->emit('learning.assignment_overdue', $row, $tenant, $key, [
            'assignment_id' => (int) $row->id,
            'user_id' => (int) $row->user_id,
            'course_id' => (int) $row->course_id,
            'course_name' => $row->course_name,
            'due_date' => (string) $row->due_date,
            'days_overdue' => $daysOverdue,
            'assignment_type' => $row->assignment_type,
        ]);
    }

    private function emit(string $type, object $row, int $tenant, string $key, array $payload): bool
    {
        try {
            $this->recorder->record(
                $type,
                $tenant,
                'lms_assignment',
                (int) $row->id,
                // No actor: the passage of time is not somebody's action.
                null,
                $payload,
                null,
                $key
            );

            return true;
        } catch (QueryException $e) {
            /*
             * uq_event_idem is unique on both databases, so a re-run of a
             * window already emitted lands here. That is the mechanism
             * working, not a failure - the daily sweep is SUPPOSED to
             * re-examine every assignment and emit nothing new for one it
             * has already flagged at this window.
             */
            if ($this->isDuplicateKey($e)) {
                return false;
            }

            throw $e;
        }
    }

    /** @return int[] */
    private function windows(string $raw): array
    {
        $parsed = array_filter(
            array_map(fn ($p) => (int) trim($p), explode(',', $raw)),
            fn ($n) => $n > 0
        );

        rsort($parsed);

        return array_values(array_unique($parsed));
    }

    /** MySQL/MariaDB 1062, surfaced through PDO as SQLSTATE 23000. */
    private function isDuplicateKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
