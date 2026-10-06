<?php

namespace App\Services\Events\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gives a REACTOR the catch-up loop every PROJECTOR already had.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * The event layer splits into projectors (pure, replayable) and reactors (they
 * touch the world — enrol people, send mail, issue certificates). `events:project`
 * drains the projectors. NOTHING drained the reactors: measured across both
 * databases, `g2g_event_delivery` held zero rows for any reactor consumer, and
 * `g2g_notification` held zero rows against 14 templates. Not one notification
 * has ever been sent by this system, and no certification renewal has ever fired.
 *
 * The cause was small: `catchUp()` existed on all three projectors and on none of
 * the four reactors, and nothing anywhere called `->dispatch()` on a reactor. The
 * handlers were complete the whole time.
 *
 * ── WHY IT FILTERS BY TYPE, WHEN AuditLogProjector::catchUp() DOES NOT ──────
 *
 * The projector selects every undelivered event and no-ops on the ones it does
 * not handle — but it only writes a ledger row for the ones it DOES handle, so
 * the rest are re-selected on every run forever. With `limit`, a large enough
 * block of unhandled events at the head of the queue starves everything behind
 * it permanently.
 *
 * That is latent there and would be acute here: a reactor handles a handful of
 * types out of the whole store, so the unhandled majority IS the queue. Filtering
 * on `whereIn('e.type', ...)` means the scan only ever sees work this consumer
 * can actually do, and the limit bounds real work rather than skipped rows.
 *
 * ── WHY A FAILURE IS RECORDED RATHER THAN RETRIED ───────────────────────────
 *
 * `whereNull('d.id')` treats "has any ledger row" as delivered, so writing a
 * `failed` row on an exception stops that event re-firing on the next run. That
 * is the deliberate choice for a reactor: `dispatch()` may already have enrolled
 * somebody or sent a mail before it threw, and an automatic retry of a partial
 * side effect is worse than a stalled one a human can see in the ledger. A
 * projector can safely retry because it is pure; a reactor cannot.
 *
 * Consumers using this trait must define `CONSUMER`, a `handles(string): bool`,
 * a `dispatch(object): void`, and either a `HANDLES` or a `NOTIFIES` const.
 */
trait DrivesFromEventStore
{
    /**
     * The event types this consumer will act on.
     *
     * NotificationDispatcher names its list NOTIFIES rather than HANDLES — it is
     * a list of things worth telling somebody about, not a list of things to do —
     * so both spellings are accepted rather than renaming a const to suit a loop.
     */
    public function handledTypes(): array
    {
        $class = static::class;

        foreach (['HANDLES', 'NOTIFIES'] as $name) {
            if (defined("{$class}::{$name}")) {
                return (array) constant("{$class}::{$name}");
            }
        }

        return [];
    }

    /**
     * Dispatch every event of a handled type that this consumer has not seen.
     *
     * Returns the number of events dispatched — NOT the number that produced a
     * side effect. A `skipped` ledger row (no recipient, no course mapped) counts
     * as handled here, because it is: the consumer looked and there was nothing
     * to do, which is a different fact from never having looked.
     */
    public function catchUp(int $limit = 500): int
    {
        $types = $this->handledTypes();

        if ($types === []) {
            return 0;
        }

        $events = DB::table('g2g_event as e')
            ->leftJoin('g2g_event_delivery as d', function ($join) {
                $join->on('d.event_id', '=', 'e.id')
                    ->where('d.consumer', '=', static::CONSUMER);
            })
            ->whereNull('d.id')
            ->whereIn('e.type', $types)
            ->orderBy('e.occurred_at')
            ->orderBy('e.id')
            ->limit(max(1, $limit))
            ->get(['e.*']);

        $handled = 0;

        foreach ($events as $event) {
            try {
                $this->dispatch($event);
                $handled++;
            } catch (\Throwable $e) {
                // See the docblock: record, do not retry. Without this row the
                // same event re-fires on every run and a partial side effect is
                // repeated indefinitely.
                DB::table('g2g_event_delivery')->updateOrInsert(
                    ['event_id' => (int) $event->id, 'consumer' => static::CONSUMER],
                    [
                        'status'       => 'failed',
                        'attempts'     => DB::raw('attempts + 1'),
                        'last_error'   => mb_substr($e->getMessage(), 0, 250),
                        'completed_at' => null,
                    ]
                );

                Log::error('Reactor dispatch failed', [
                    'consumer' => static::CONSUMER,
                    'event_id' => $event->id,
                    'type'     => $event->type,
                    'error'    => $e->getMessage(),
                    'file'     => $e->getFile() . ':' . $e->getLine(),
                ]);
            }
        }

        return $handled;
    }

    /** Events of a handled type this consumer has not seen yet. */
    public function pendingCount(): int
    {
        $types = $this->handledTypes();

        if ($types === []) {
            return 0;
        }

        return (int) DB::table('g2g_event as e')
            ->leftJoin('g2g_event_delivery as d', function ($join) {
                $join->on('d.event_id', '=', 'e.id')
                    ->where('d.consumer', '=', static::CONSUMER);
            })
            ->whereNull('d.id')
            ->whereIn('e.type', $types)
            ->count();
    }
}
