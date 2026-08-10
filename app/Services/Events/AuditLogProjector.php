<?php

namespace App\Services\Events;

use Illuminate\Support\Facades\DB;

/**
 * PROJECTOR — g2g_audit_log. (`05-data-flow-contracts.md` §2.0, kind = P)
 *
 * PURE. It writes ONLY its own projection and touches nothing outside the
 * database. That is what makes it replayable: same events in, same rows out.
 *
 * It must never invoke a reactor. Where a projection should cause a reaction, the
 * projector emits a NEW event and the reactor subscribes to that - so replay stops
 * at the projector boundary, because reactors do not run on replay.
 *
 * IDEMPOTENT BY CONSTRUCTION: g2g_audit_log has a UNIQUE on event_id, so
 * projecting the same event twice cannot duplicate a row. Its delivery ledger is
 * CLEARED on rebuild (projector, not reactor).
 */
class AuditLogProjector
{
    public const CONSUMER = 'audit_log_projector';

    /** Every business event is audited: "who did what" is the whole point. */
    public function handles(string $type): bool
    {
        return true;
    }

    /**
     * Project one event. Safe to call repeatedly.
     */
    public function project(object $event): void
    {
        DB::table('g2g_audit_log')->updateOrInsert(
            ['event_id' => (int) $event->id],
            [
                'sub_institute_id' => (int) $event->sub_institute_id,
                'type'             => $event->type,
                'entity_type'      => $event->entity_type,
                'entity_id'        => $event->entity_id,
                'actor_id'         => $event->actor_id,          // NULL = SYSTEM
                'acting_for_id'    => $event->acting_for_id,
                'detail'           => $event->payload,
                'occurred_at'      => $event->occurred_at,
            ]
        );

        DB::table('g2g_event_delivery')->updateOrInsert(
            ['event_id' => (int) $event->id, 'consumer' => self::CONSUMER],
            ['status' => 'done', 'attempts' => DB::raw('attempts + 1'), 'completed_at' => now()]
        );
    }

    /**
     * Project everything not yet delivered to this consumer.
     */
    public function catchUp(int $limit = 500): int
    {
        $events = DB::table('g2g_event as e')
            ->leftJoin('g2g_event_delivery as d', function ($join) {
                $join->on('d.event_id', '=', 'e.id')->where('d.consumer', '=', self::CONSUMER);
            })
            ->whereNull('d.id')
            ->orderBy('e.occurred_at')
            ->orderBy('e.id')
            ->limit($limit)
            ->get(['e.*']);

        foreach ($events as $event) {
            $this->project($event);
        }

        return $events->count();
    }

    /**
     * REBUILD, per §6.1. Truncate the projection, clear THIS PROJECTOR's ledger
     * rows only, then re-derive. Reactor ledgers are never touched here - they
     * are permanent and survive every rebuild.
     */
    public function rebuild(): int
    {
        DB::table('g2g_audit_log')->truncate();
        DB::table('g2g_event_delivery')->where('consumer', self::CONSUMER)->delete();

        $done = 0;
        while (($n = $this->catchUp()) > 0) {
            $done += $n;
        }

        return $done;
    }
}
