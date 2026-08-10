<?php

namespace App\Services\Events;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * REACTOR — the first one. (`05-data-flow-contracts.md` §2.0, kind = R)
 *
 * IMPURE: it sends. Every dispatch is externally visible and cannot be undone by
 * truncating a table, which is the whole reason reactors are a separate category.
 *
 * TWO PROPERTIES THAT DEFINE A REACTOR, both enforced here rather than documented:
 *
 *   1. IT NEVER RUNS ON REPLAY. dispatch() calls ReplayMode::assertNotReplaying()
 *      before anything else, and that THROWS. A rebuild that reaches this line
 *      has a bug worse than the one being fixed.
 *
 *   2. ITS DISPATCH LEDGER IS PERMANENT. Rows in g2g_event_delivery for a reactor
 *      are NEVER cleared by a rebuild - they are the record that a real email was
 *      really sent. Clearing them would make the system willing to send it again.
 *
 * This class does not yet send anything: X-06 (the notification service and its
 * terminology tables, Q-F1) is a separate plan item. What is real here is the
 * dispatch discipline - the ledger, the replay guard, and the idempotency - which
 * is what slice 4 exists to prove.
 */
class NotificationDispatcher
{
    public const CONSUMER = 'notification_dispatcher';

    public function handles(string $type): bool
    {
        return in_array($type, [
            'task.rejected', 'assessment.completed', 'certification.issued',
            'certification.expiring', 'employee.offboarded',
            'development_plan.approved', 'readiness_gate.changed', 'rights.changed',
            'capability.flag_raised',
        ], true);
    }

    /**
     * @throws \RuntimeException if called while replaying
     */
    public function dispatch(object $event): void
    {
        // FIRST LINE. Before any work, before any ledger write.
        ReplayMode::assertNotReplaying(self::CONSUMER);

        // Idempotent per (event, consumer): a retry after a partial failure must
        // not send twice. The unique key is what enforces it, not this check.
        $already = DB::table('g2g_event_delivery')
            ->where('event_id', (int) $event->id)
            ->where('consumer', self::CONSUMER)
            ->where('status', 'done')
            ->exists();

        if ($already) {
            return;
        }

        // X-06 will put a real send here. The ledger row below is the durable
        // part, and it is what a rebuild must not erase.
        Log::channel('single')->info('notification.dispatch', [
            'event_id' => $event->id,
            'type'     => $event->type,
            'tenant'   => $event->sub_institute_id,
        ]);

        DB::table('g2g_event_delivery')->updateOrInsert(
            ['event_id' => (int) $event->id, 'consumer' => self::CONSUMER],
            ['status' => 'done', 'attempts' => DB::raw('attempts + 1'), 'completed_at' => now()]
        );
    }
}
