<?php

namespace App\Domain\Intelligence\Detectors\Platform;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Coverage;
use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Taxonomy;

/**
 * Event deliveries that failed, or that are still pending long after their event was recorded. Needs `view` on /platform-services/event-bus (the same row that
 * guards the Event bus screen; it is administrator-only by default because the stream carries actor ids).
 *
 * SOURCE (verified: the 2026_08_10 event-store migration and EventBusReader): `g2g_event_delivery` (event_id, consumer, status pending|done|failed|skipped,
 * attempts) joined to `g2g_event` (sub_institute_id, recorded_at). The delivery table has no tenant column, so it is reached ONLY through its event, and the
 * event's `sub_institute_id` is the caller's. `last_error` (free text), event payloads and actor ids are never selected.
 *
 * WHAT IT DOES NOT CLAIM. A delivery row exists only once a consumer has begun work on an event; an event no consumer has touched has no row. Those are not
 * counted, and the evidence says so. Consumer names are code identifiers and are shown only when they pass a strict character check.
 */
final class EventDeliveryDetector extends PlatformDetector
{
    private const LINK = '/platform-services/event-bus';
    private const MAX_NAMED = 5;

    public function id(): string
    {
        return 'platform.event_delivery';
    }

    public function title(): string
    {
        return 'Event delivery';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_PLATFORM_EVENT_BUS];
    }

    public function authorizes(IntelligenceCaller $caller): bool
    {
        return $caller->canAny([PlatformAccess::LINK_EVENT_BUS]);
    }

    protected function assess(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $asOf = $clock->asOf();
        if (! $this->canRead('g2g_event_delivery', ['id', 'event_id', 'consumer', 'status']) || ! $this->canRead('g2g_event', ['id', 'sub_institute_id', 'recorded_at'])) {
            return DetectorResult::dataUnavailable($this, $asOf, 'The event store is not available on this installation.');
        }

        $days = $this->thresholds->int('events_window_days');
        $since = $clock->dateTimeDaysAgo($days);
        $tenant = $caller->tenantId;
        $deliveries = fn () => $this->db->table('g2g_event_delivery as d')->join('g2g_event as e', 'e.id', '=', 'd.event_id')
            ->where('e.sub_institute_id', $tenant)->where('e.recorded_at', '>=', $since);

        $total = (int) $deliveries()->count();
        $coverage = Coverage::of($total, null, "event deliveries for events recorded in the last {$days} days", $this->thresholds->int('events_min_deliveries'));
        if (! $coverage->sufficient) {
            return DetectorResult::insufficient($this, $coverage, $asOf);
        }

        $failed = (int) $deliveries()->where('d.status', 'failed')->count();
        $pendingHours = $this->thresholds->int('events_pending_hours');
        $stuck = (int) $deliveries()->where('d.status', 'pending')->where('e.recorded_at', '<', $clock->hoursAgo($pendingHours))->count();

        $insights = [];
        $today = $clock->today();
        $notes = [Evidence::metric('Events with no delivery row yet (no consumer has started on them)', 'not counted')];

        if ($failed > 0) {
            $share = self::pct($failed, $total);
            $byConsumer = $deliveries()->where('d.status', 'failed')->selectRaw('d.consumer as consumer, COUNT(*) as n')->groupBy('d.consumer')
                ->orderByDesc('n')->orderBy('consumer')->limit(self::MAX_NAMED)->get();
            $ids = $deliveries()->where('d.status', 'failed')->orderBy('e.id')->limit(self::MAX_ENTITY_EVIDENCE)->pluck('e.id')->all();
            $evidence = [
                Evidence::metric('Deliveries in the window', $total, 'deliveries', 'g2g_event_delivery'),
                Evidence::metric('Failed deliveries', $failed, 'deliveries'),
                Evidence::metric('Share of deliveries that failed', $share, '%'),
            ];
            foreach ($byConsumer as $row) {
                $evidence[] = Evidence::metric('Failed for consumer ' . self::safeKey($row->consumer, '(unnamed)'), (int) $row->n, 'deliveries');
            }
            array_push($evidence, ...$notes, ...$this->entities($caller, 'Event with a failed delivery', 'event', $ids));
            $insights[] = new Insight(
                $this->key($caller, 'failed', $today), $this->id(), $this->domain(), 'event_delivery', $share >= $this->thresholds->float('events_failed_pct') ? 'high' : 'attention',
                sprintf('%d event deliver%s failed', $failed, $failed === 1 ? 'y' : 'ies'),
                sprintf('%d of %d deliveries (%s%%) for events in the last %d days are marked failed.', $failed, $total, $share, $days),
                'A failed delivery means a consumer (an audit projector, a workflow trigger, a notification) did not act on an event, so what it was meant to do did not happen.',
                $evidence, $coverage, $asOf, 'Open the Event bus, read the failures and fix the consumer before re-driving anything.', self::LINK, 'measured', $this->audience(), $this->surfaces(), null,
            );
        }
        if ($stuck > 0) {
            $insights[] = new Insight(
                $this->key($caller, 'pending', $today), $this->id(), $this->domain(), 'event_delivery', 'attention',
                sprintf('%d deliver%s still pending after %d hours', $stuck, $stuck === 1 ? 'y is' : 'ies are', $pendingHours),
                sprintf('%d deliveries are still pending more than %d hours after their event was recorded.', $stuck, $pendingHours),
                'Pending deliveries that do not move usually mean the consumer or the scheduler that drives it has stopped.',
                [
                    Evidence::metric('Deliveries in the window', $total, 'deliveries', 'g2g_event_delivery'),
                    Evidence::metric("Pending more than {$pendingHours} hours", $stuck, 'deliveries'),
                    ...$notes,
                ],
                $coverage, $asOf, 'Check that the event consumers are scheduled and running.', self::LINK, 'rule_based', $this->audience(), $this->surfaces(), null,
            );
        }

        return DetectorResult::ok($this, $insights, $coverage, $asOf);
    }
}
