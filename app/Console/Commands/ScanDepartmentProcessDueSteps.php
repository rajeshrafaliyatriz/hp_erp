<?php

namespace App\Console\Commands;

use App\Http\Controllers\HRMS\DepartmentProcessRunController;
use App\Services\Events\EventRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Auto-complete a `wait_delay` step once its due_at has elapsed, and record
 * `process.step.due_soon` for an in-progress task/approval step closing in
 * on its own due_at.
 *
 * Shaped like ScanCertificationExpiry on purpose: a daily/periodic scan over
 * current state rather than a timer per run (a queued delayed job per
 * wait_delay step would work too, but would mean one scheduled job in the
 * queue per in-flight step across every tenant - a table scan here is
 * cheaper and, being idempotent on `status`, catches up fine after a missed
 * cycle the same way the certification sweep does).
 *
 * Delegates the actual advancement to
 * DepartmentProcessRunController::completeWaitStep() rather than
 * duplicating the engine's step-activation/cascade logic here - see that
 * controller's docblock for why a run only ever executes its published
 * snapshot.
 */
class ScanDepartmentProcessDueSteps extends Command
{
    protected $signature = 'process-runs:scan-due
                            {--limit=500 : Maximum run-steps examined per run}';

    protected $description = 'Auto-complete elapsed wait_delay steps and flag soon-due steps across in-flight department process runs.';

    public function __construct(
        private readonly DepartmentProcessRunController $runs,
        private readonly EventRecorder $events,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $elapsedWaits = DB::table('department_process_run_steps as rs')
            ->join('department_process_runs as r', 'r.id', '=', 'rs.run_id')
            ->where('r.status', 'running')
            ->where('rs.step_type_snapshot', 'wait_delay')
            ->where('rs.status', 'in_progress')
            ->whereNotNull('rs.due_at')
            ->where('rs.due_at', '<=', now())
            ->limit($limit)
            ->get(['rs.id', 'rs.run_id', 'rs.step_node_key']);

        $completed = 0;
        foreach ($elapsedWaits as $row) {
            if ($this->runs->completeWaitStep((int) $row->run_id, $row->step_node_key)) {
                $completed++;
            }
        }

        $flagged = $this->flagDueSoon($limit);

        $this->info(sprintf(
            'Scanned: %d elapsed wait step(s) found, %d completed. %d step(s) flagged due soon.',
            $elapsedWaits->count(), $completed, $flagged
        ));

        return self::SUCCESS;
    }

    /**
     * One `process.step.due_soon` event per step crossing into its last 24
     * hours - at most once per step, guarded by checking for a prior
     * emission rather than a unique-key retry (department_process_run_events
     * carries no idempotency column, unlike g2g_event).
     */
    private function flagDueSoon(int $limit): int
    {
        $soon = now()->addHours(24);

        $candidates = DB::table('department_process_run_steps as rs')
            ->join('department_process_runs as r', 'r.id', '=', 'rs.run_id')
            ->where('r.status', 'running')
            ->where('rs.status', 'in_progress')
            ->whereIn('rs.step_type_snapshot', ['task', 'approval', 'milestone', 'decision'])
            ->whereNotNull('rs.due_at')
            ->where('rs.due_at', '<=', $soon)
            ->where('rs.due_at', '>', now())
            ->limit($limit)
            ->get(['rs.id', 'rs.run_id', 'rs.sub_institute_id', 'rs.step_node_key', 'rs.due_at']);

        $flagged = 0;
        foreach ($candidates as $row) {
            $already = DB::table('department_process_run_events')
                ->where('run_step_id', $row->id)
                ->where('event_type', 'process.step.due_soon')
                ->exists();

            if ($already) {
                continue;
            }

            DB::table('department_process_run_events')->insert([
                'run_id' => $row->run_id, 'run_step_id' => $row->id, 'sub_institute_id' => $row->sub_institute_id,
                'event_type' => 'process.step.due_soon', 'actor_user_id' => null,
                'payload' => json_encode(['step_node_key' => $row->step_node_key, 'due_at' => (string) $row->due_at]),
                'created_at' => now(),
            ]);

            $this->events->record(
                'process.step.due_soon', (int) $row->sub_institute_id, 'department_process_run', (int) $row->run_id, null,
                ['step_node_key' => $row->step_node_key, 'due_at' => (string) $row->due_at]
            );

            $flagged++;
        }

        return $flagged;
    }
}
