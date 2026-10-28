<?php

namespace App\Services\Events;

use App\Domain\Gtm\GtmEvents;
use App\Domain\Gtm\Workflows\WorkflowEngine;
use App\Services\Events\Concerns\DrivesFromEventStore;
use Illuminate\Support\Facades\DB;

/**
 * REACTOR - runs GTM workflows when a triggerable gtm.* event is recorded.
 *
 * This is the real consumer that makes those events more than log lines: each one is delivered here by the existing
 * `events:react` command (it is listed in ReactEvents::REACTORS), so GTM has no event loop or scheduler of its own.
 * The engine does the work; this class only adapts the event store's contract to it.
 *
 * Like every reactor it touches the world (it can change records and run agents), so its delivery ledger is PERMANENT and
 * a replay must never reach it (ReplayMode::assertNotReplaying is the first line of dispatch()). An event caused by a
 * workflow action is acknowledged and ignored, which is what prevents loops.
 */
class GtmWorkflowRunner
{
    use DrivesFromEventStore;

    public const CONSUMER = 'gtm_workflow_runner';

    /** The event types delivered to this consumer: exactly the ones GtmEvents marks triggerable. */
    public function handledTypes(): array
    {
        return GtmEvents::triggerable();
    }

    public function handles(string $type): bool
    {
        return in_array($type, GtmEvents::triggerable(), true);
    }

    /** Events of a handled type for ONE tenant that this consumer has not seen yet. */
    public function pendingCountForTenant(int $tenant, int $afterEventId = 0): int
    {
        return (int) $this->undeliveredQuery($tenant, $afterEventId)->count();
    }

    /**
     * The tenant-scoped counterpart of catchUp(): the same ledger, the same dispatch(), the same failed-is-recorded-not-retried rule,
     * but it can only ever SEE this tenant's events - another tenant's events are never selected, so never delivered.
     * $afterEventId lets a caller ignore everything recorded up to and including that event id (a test or a staged roll-out).
     * Returns the number of events dispatched.
     */
    public function catchUpForTenant(int $tenant, int $limit = 200, int $afterEventId = 0): int
    {
        $handled = 0;
        foreach ($this->undeliveredQuery($tenant, $afterEventId)->limit(max(1, $limit))->get(['e.*']) as $event) {
            try {
                $this->dispatch($event);
                $handled++;
            } catch (\Throwable $e) {
                DB::table('g2g_event_delivery')->updateOrInsert(
                    ['event_id' => (int) $event->id, 'consumer' => self::CONSUMER],
                    ['status' => 'failed', 'attempts' => DB::raw('attempts + 1'), 'last_error' => mb_substr($e->getMessage(), 0, 250), 'completed_at' => null]
                );
                \Illuminate\Support\Facades\Log::error('Reactor dispatch failed', ['consumer' => self::CONSUMER, 'event_id' => $event->id, 'type' => $event->type, 'tenant' => $tenant, 'error' => $e->getMessage()]);
            }
        }

        return $handled;
    }

    private function undeliveredQuery(int $tenant, int $afterEventId = 0): \Illuminate\Database\Query\Builder
    {
        return DB::table('g2g_event as e')
            ->leftJoin('g2g_event_delivery as d', fn ($j) => $j->on('d.event_id', '=', 'e.id')->where('d.consumer', '=', self::CONSUMER))
            ->whereNull('d.id')->where('e.sub_institute_id', $tenant)->where('e.id', '>', $afterEventId)->whereIn('e.type', GtmEvents::triggerable())
            ->orderBy('e.occurred_at')->orderBy('e.id');
    }
    public function dispatch(object $event): void
    {
        ReplayMode::assertNotReplaying(self::CONSUMER);

        if (! $this->handles((string) $event->type)) {
            return;
        }
        $done = DB::table('g2g_event_delivery')->where('event_id', (int) $event->id)->where('consumer', self::CONSUMER)->where('status', 'done')->exists();
        if ($done) {
            return;
        }

        $r = app(WorkflowEngine::class)->handleEvent($event);

        DB::table('g2g_event_delivery')->updateOrInsert(
            ['event_id' => (int) $event->id, 'consumer' => self::CONSUMER],
            ['status' => $r['ignored'] || $r['matched'] === 0 ? 'skipped' : 'done', 'attempts' => DB::raw('attempts + 1'), 'last_error' => null, 'completed_at' => now()]
        );
    }
}
