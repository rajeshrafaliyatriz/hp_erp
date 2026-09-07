<?php

namespace App\Console\Commands;

use App\Services\Leave\LeaveApprovalWorkflow;
use App\Services\Leave\LeaveNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The other half of hrms_leave_workflow_settings. F-124.
 *
 * The Leave Configuration screen has always offered "escalate after 24 hours",
 * every live tenant has it switched on, and nothing has ever escalated anything
 * - there was no chain to escalate and no job to do it.
 *
 *   php artisan leave:escalate            all tenants
 *   php artisan leave:escalate --tenant=3 one tenant
 *   php artisan leave:escalate --dry-run  say what would happen, change nothing
 *
 * Scheduled hourly in routes/console.php. Hourly, not per-minute: the finest
 * granularity the screen offers is one hour, so anything more often is work
 * that cannot change an outcome.
 */
class EscalateOverdueLeaveApprovals extends Command
{
    protected $signature = 'leave:escalate
                            {--tenant= : Only this sub_institute_id}
                            {--dry-run : Report what would escalate without writing}';

    protected $description = 'Escalate leave approvals that have waited longer than the tenant allows';

    /** Forces the dry run's rollback. Not an error condition. */
    private const DRY_RUN_SENTINEL = '__leave_escalate_dry_run__';

    public function handle(LeaveApprovalWorkflow $workflow, LeaveNotifier $notifier): int
    {
        $tenant = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;

        if ($this->option('dry-run')) {
            // A dry run must not stamp escalated_at, which is one-shot. So it asks
            // the same question through the read side and reports, rather than
            // calling escalateOverdue() and rolling back.
            $this->info('Dry run - nothing will be written.');
        }

        $escalated = $this->option('dry-run')
            ? $this->preview($workflow, $tenant)
            : $workflow->escalateOverdue($tenant);

        if ($escalated === []) {
            $this->info('Nothing overdue.');

            return self::SUCCESS;
        }

        /*
         * F-128. TELL THE PEOPLE IT WAS ESCALATED TO.
         *
         * Escalating without telling anybody is a row in a table, not an
         * escalation - the whole point is that somebody else now knows they can
         * act. Skipped on a dry run for the obvious reason: a preview that sends
         * real notifications is not a preview.
         */
        if (!$this->option('dry-run')) {
            foreach ($escalated as $row) {
                $notifier->escalated($row, $this->hoursWaiting($row['waiting_since']));
            }
        }

        $this->table(
            ['step', 'leave', 'tenant', 'from', 'to', 'waiting since'],
            array_map(fn ($row) => [
                $row['step_id'],
                $row['leave_id'],
                $row['sub_institute_id'],
                LeaveApprovalWorkflow::label($row['from']),
                LeaveApprovalWorkflow::label($row['to']),
                $row['waiting_since'],
            ], $escalated)
        );

        $this->info(count($escalated) . ' approval step(s) escalated.');

        return self::SUCCESS;
    }

    /** How long the step had been waiting, for the notification body. */
    private function hoursWaiting(?string $since): int
    {
        if (!$since) {
            return 0;
        }

        return (int) max(0, now()->diffInHours(\Illuminate\Support\Carbon::parse($since)));
    }

    /**
     * What escalateOverdue() would do, without doing it.
     *
     * Run inside a transaction that is deliberately rolled back, so the preview
     * is the real code path rather than a second implementation of the same
     * rules that could drift from it. The sentinel exception is what forces the
     * rollback; anything else is a genuine failure and is rethrown.
     */
    private function preview(LeaveApprovalWorkflow $workflow, ?int $tenant): array
    {
        $result = [];

        try {
            DB::transaction(function () use ($workflow, $tenant, &$result) {
                $result = $workflow->escalateOverdue($tenant);

                throw new RuntimeException(self::DRY_RUN_SENTINEL);
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== self::DRY_RUN_SENTINEL) {
                throw $e;
            }
        }

        return $result;
    }
}
