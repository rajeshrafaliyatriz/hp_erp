<?php

namespace App\Console\Commands;

use App\Services\Platform\ApprovalEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The escalation sweep for every workflow point `ApprovalEngine` enforces —
 * attendance regularisation, offer, offboarding clearance, mobility transfer,
 * competency mapping review, task execution approval — in ONE pass, not one
 * command per domain.
 *
 * Mirrors `leave:escalate`'s own shape (dry-run via a rolled-back transaction,
 * same table/counts reporting), simplified for the reason
 * `ApprovalEngine::escalateOverdue()`'s own note gives: none of these six
 * domains carry leave's tenant-wide settings-path fallback, so there is no
 * `--tenant` filter to refuse a malformed one for — the sweep is already one
 * pass across every tenant and every enforced point together, keyed off each
 * step's own `sla_hours`/`on_breach`.
 *
 *   php artisan approvals:escalate            every enforced point, every tenant
 *   php artisan approvals:escalate --dry-run  report what would happen, write nothing
 *
 * Scheduled hourly in routes/console.php, wrapped in TaskRunLedger::track()
 * like every other entry there.
 */
class EscalateOverdueApprovals extends Command
{
    protected $signature = 'approvals:escalate
                            {--dry-run : Report what would escalate without writing}';

    protected $description = 'Escalate platform-enforced approvals (attendance, offers, offboarding, '
        . 'mobility, competency, task execution) that have waited longer than their step allows';

    private const DRY_RUN_SENTINEL = '__approvals_escalate_dry_run__';

    public function handle(ApprovalEngine $engine): int
    {
        if ($this->option('dry-run')) {
            $this->info('Dry run - nothing will be written.');
        }

        $escalated = $this->option('dry-run')
            ? $this->preview($engine)
            : $engine->escalateOverdue();

        if ($escalated === []) {
            $this->info('Nothing overdue.');

            return self::SUCCESS;
        }

        $this->table(
            ['step', 'subject', 'tenant', 'action', 'from', 'to', 'waiting since'],
            array_map(fn ($row) => [
                $row['step_id'],
                $row['subject_type'] . ' #' . $row['subject_id'],
                $row['sub_institute_id'],
                $row['action'] ?? 'escalate',
                $row['from'],
                $row['to'] ?? '—',
                $row['waiting_since'],
            ], $escalated)
        );

        $counts = [];

        foreach ($escalated as $row) {
            $action = $row['action'] ?? 'escalate';
            $counts[$action] = ($counts[$action] ?? 0) + 1;
        }

        $this->info(
            count($escalated) . ' breached step(s): '
            . implode(', ', array_map(
                fn ($action, $n) => "{$n} {$action}",
                array_keys($counts),
                array_values($counts)
            ))
        );

        if (($counts['auto_skipped'] ?? 0) > 0) {
            $this->warn(
                $counts['auto_skipped'] . ' step(s) were due to be decided automatically and were not. '
                . 'Set platform_services.auto_decisions_enabled to allow it, or change those steps to escalate.'
            );
        }

        return self::SUCCESS;
    }

    /**
     * What escalateOverdue() would do, without doing it — the real code path
     * inside a transaction that is deliberately rolled back, the same
     * technique `leave:escalate`'s own preview() uses, so this can never drift
     * from the rules it is previewing.
     */
    private function preview(ApprovalEngine $engine): array
    {
        $result = [];

        try {
            DB::transaction(function () use ($engine, &$result) {
                $result = $engine->escalateOverdue();

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
