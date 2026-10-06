<?php

namespace App\Console\Commands;

use App\Services\Events\AuditLogProjector;
use App\Services\Events\CapabilityEvidenceProjector;
use App\Services\Events\TaskStatusProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Drain the event store into its projections.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * The event pipeline was built at both ends and never connected in the middle.
 * Measured before writing this:
 *
 *   g2g_event                 28 rows dev / 51 live  (44 of them task events)
 *   g2g_event_delivery        1 row, consumer 'audit_log_projector'
 *   g2g_audit_log             1 row  — a certification event, no task event ever
 *   competency_evidence       0 rows on both
 *   task_status_history       0 rows on both
 *
 * Every projector has a working `catchUp()`. Exactly ONE call site existed in the
 * whole application — `LmsGovernanceController` invoking the audit projector after
 * one specific action — so events raised anywhere else were recorded and then sat
 * unread forever.
 *
 * That single missing dispatch is why the Task Management audit screen shows six
 * rows frozen in August, and why competency evidence has never been raised by
 * anything. One command fixes both, because both were waiting on the same thing.
 *
 * ── WHY A COMMAND AND NOT A CALL IN A CONTROLLER ────────────────────────────
 *
 * The projectors are registered PURE in EventCatalogue precisely so they stay
 * replayable: they read the store and write a projection, and nothing else. A
 * controller calling one directly would couple a request to a projection and
 * break the replay contract the whole event design rests on.
 *
 * catchUp() is idempotent — it LEFT JOINs g2g_event_delivery and skips anything
 * already delivered to that consumer — so running this often, or twice at once,
 * cannot double-write.
 */
class ProjectEvents extends Command
{
    protected $signature = 'events:project
                            {--consumer= : Run one projector by its CONSUMER name}
                            {--limit=500 : Maximum events per projector per run}';

    protected $description = 'Deliver recorded events to their projections (evidence, audit log, task status history).';

    /**
     * The projectors this drains.
     *
     * CapabilityEvidenceProjector is deliberately included even though
     * ReplayRunner::PROJECTORS does not list it — that omission is part of the
     * same gap, and is why nothing has ever driven it.
     */
    private const PROJECTORS = [
        AuditLogProjector::class,
        CapabilityEvidenceProjector::class,
        TaskStatusProjector::class,
    ];

    public function handle(): int
    {
        $only = $this->option('consumer');
        $limit = max(1, (int) $this->option('limit'));
        $total = 0;
        $failed = 0;

        foreach (self::PROJECTORS as $class) {
            $consumer = $class::CONSUMER;

            if ($only !== null && $only !== '' && $only !== $consumer) {
                continue;
            }

            try {
                $done = (int) app($class)->catchUp($limit);
                $total += $done;
                $this->line(sprintf('  %-32s %d event(s)', $consumer, $done));
            } catch (\Throwable $e) {
                /*
                 * ONE FAILING PROJECTOR MUST NOT STOP THE OTHERS.
                 *
                 * They are independent consumers of the same store. Letting an
                 * exception in one abort the loop would mean a single bad event
                 * silently halts evidence AND audit AND status history — which is
                 * the shape of the outage this command exists to end.
                 */
                $failed++;
                $this->error(sprintf('  %-32s FAILED: %s', $consumer, $e->getMessage()));
                Log::error('Event projection failed', [
                    'consumer' => $consumer,
                    'error'    => $e->getMessage(),
                    'file'     => $e->getFile() . ':' . $e->getLine(),
                ]);
            }
        }

        // The backlog that remains, so a run that hits the limit says so rather
        // than looking complete.
        $pending = (int) DB::table('g2g_event')->count()
            - (int) DB::table('g2g_event_delivery')->distinct()->count('event_id');

        $this->info(sprintf('Projected %d event(s).%s', $total,
            $pending > 0 ? " {$pending} event(s) still undelivered to at least one consumer." : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
