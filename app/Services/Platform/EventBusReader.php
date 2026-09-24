<?php

namespace App\Services\Platform;

use App\Services\Events\EventCatalogue;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads the event store back.
 *
 * `EventRecorder` has been writing `g2g_event` and `g2g_event_delivery` since M6, and
 * `AuditLogProjector` and nine other consumers have been draining them every five
 * minutes. Nothing has ever read the ledger itself, so the product has been keeping an
 * operational record nobody could look at: when a consumer stalls, every screen
 * downstream of it goes quietly stale and the first symptom is somebody asking why a
 * number is wrong.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE TENANCY RULE — READ THIS BEFORE ADDING A QUERY
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `g2g_event_delivery` HAS NO `sub_institute_id`. Tenancy exists only on `g2g_event`.
 *
 * So a bare `DB::table('g2g_event_delivery')->count()` is not "a count for this
 * organisation" — it is a count for THE WHOLE ESTATE, handed to one tenant's
 * administrator. Every aggregate, page and filter over the delivery table must join
 * `g2g_event` and constrain `g2g_event.sub_institute_id`.
 *
 * `deliveries()` below is the single place that join is written; everything that needs
 * the ledger goes through it rather than building its own query. That is deliberate —
 * a rule enforced in one method is a rule, and a rule restated in six is a coincidence
 * waiting to be broken by the seventh.
 *
 * ── WHY EVERYTHING HERE IS A READ ───────────────────────────────────────────
 *
 * There is no replay, redrive or publish method, and there should not be. A projector
 * is pure and re-running it is harmless; a reactor enrols people on courses, issues
 * certificates and sends notifications, so replaying one does those things again. The
 * console commands `events:project` and `events:react` already separate the two for
 * exactly that reason. Putting a replay button on a screen would hand that distinction
 * to whoever clicks it.
 */
class EventBusReader
{
    private const EVENTS = 'g2g_event';
    private const DELIVERY = 'g2g_event_delivery';
    private const AUDIT = 'g2g_audit_log';

    /** Events for one tenant. The base of every read in this class. */
    private function events(int $tenantId): Builder
    {
        return DB::table(self::EVENTS)->where(self::EVENTS . '.sub_institute_id', $tenantId);
    }

    /**
     * The delivery ledger, joined to its events and constrained to one tenant.
     *
     * THE ONLY correct way to query that table. See the class note.
     */
    private function deliveries(int $tenantId): Builder
    {
        return DB::table(self::DELIVERY)
            ->join(self::EVENTS, self::EVENTS . '.id', '=', self::DELIVERY . '.event_id')
            ->where(self::EVENTS . '.sub_institute_id', $tenantId);
    }

    /**
     * The headline numbers.
     *
     * `available: false` is the important field. Five of these can be computed from
     * tables that exist; "when did the drain last run" cannot, because nothing records
     * a scheduled pass — `routes/console.php` registers `events:project` with no
     * `onSuccess`/`onFailure` hook and there is no run ledger. A tile that cannot be
     * computed says so. Showing `0` there would read as "nothing is behind", which is
     * the opposite of "we do not know", and an operations screen that invents a
     * reassuring number is worse than one that admits a gap.
     *
     * @return array<string, mixed>
     */
    public function summary(int $tenantId): array
    {
        if (! Schema::hasTable(self::EVENTS)) {
            return ['tiles' => [], 'installed' => false];
        }

        $since = now()->subDay();

        $events24h = (clone $this->events($tenantId))
            ->where('occurred_at', '>=', $since)
            ->count();

        $eventsTotal = $this->events($tenantId)->count();

        $byStatus = $this->deliveries($tenantId)
            ->select(self::DELIVERY . '.status', DB::raw('count(*) as total'))
            ->groupBy(self::DELIVERY . '.status')
            ->pluck('total', 'status')
            ->all();

        $pending = (int) ($byStatus['pending'] ?? 0);
        $failed = (int) ($byStatus['failed'] ?? 0);

        $auditRows = Schema::hasTable(self::AUDIT)
            ? DB::table(self::AUDIT)->where('sub_institute_id', $tenantId)->count()
            : null;

        return [
            'installed' => true,
            'generated_at' => now()->toIso8601String(),
            'tiles' => [
                $this->tile('events_24h', 'Events captured (24h)', number_format($events24h), 'gray', true, 'g2g_event'),
                $this->tile('events_total', 'Events captured (all time)', number_format($eventsTotal), 'gray', true, 'g2g_event'),
                $this->tile(
                    'deliveries_pending',
                    'Deliveries pending',
                    number_format($pending),
                    $pending > 0 ? 'amber' : 'gray',
                    true,
                    'g2g_event_delivery joined to g2g_event'
                ),
                $this->tile(
                    'deliveries_failed',
                    'Deliveries failed',
                    number_format($failed),
                    $failed > 0 ? 'red' : 'gray',
                    true,
                    'g2g_event_delivery joined to g2g_event'
                ),
                $auditRows === null
                    ? $this->tile('audit_rows', 'Audit rows projected', '—', 'gray', false, null, 'g2g_audit_log is not installed on this database.')
                    : $this->tile('audit_rows', 'Audit rows projected', number_format($auditRows), 'gray', true, 'g2g_audit_log'),
                $this->tile(
                    'last_drain_pass',
                    'Last drain pass',
                    '—',
                    'gray',
                    false,
                    null,
                    'Nothing records a scheduled run. events:project writes no run ledger, so this cannot be computed yet.'
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tile(
        string $key,
        string $label,
        string $value,
        string $tone,
        bool $available,
        ?string $source = null,
        ?string $hint = null
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'tone' => $tone,
            'available' => $available,
            'source' => $source,
            'hint' => $hint,
        ];
    }

    /**
     * One page of the event stream, newest first.
     *
     * The type filter is named `event_type`, NOT `type`. `type=API` is this product's
     * transport marker — every frontend service call carries it — so a parameter called
     * `type` arrives already occupied and silently filters nothing.
     * `OrganizationSettingsController::audit` hit this exact problem and documents it;
     * the column here really is called `type`, and the parameter still must not be.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function stream(int $tenantId, array $filters, int $page, int $limit): array
    {
        $query = $this->events($tenantId);

        if (! empty($filters['event_type'])) {
            $query->where('type', $filters['event_type']);
        }

        if (! empty($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }

        if (! empty($filters['from'])) {
            $query->where('occurred_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('occurred_at', '<=', $filters['to']);
        }

        $total = (clone $query)->count();

        $rows = $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->forPage($page, $limit)
            ->get([
                'id', 'event_uuid', 'type', 'entity_type', 'entity_id',
                'actor_id', 'correlation_id', 'occurred_at', 'recorded_at',
            ]);

        $deliveryByEvent = $this->deliveryCountsFor($rows->pluck('id')->all(), $tenantId);
        $actorNames = $this->actorNames($rows->pluck('actor_id')->filter()->unique()->all());

        return [
            'rows' => $rows->map(fn ($row) => [
                'id' => (int) $row->id,
                'event_uuid' => $row->event_uuid,
                'type' => $row->type,
                'entity_type' => $row->entity_type,
                'entity_id' => $row->entity_id === null ? null : (int) $row->entity_id,
                // NULL actor means SYSTEM — a real value, not "unknown". The store's own
                // migration says so, and rendering it as "Unknown" would turn a designed
                // fact into a gap.
                'actor_id' => $row->actor_id === null ? null : (int) $row->actor_id,
                'actor_name' => $row->actor_id === null ? null : ($actorNames[(int) $row->actor_id] ?? null),
                'correlation_id' => $row->correlation_id,
                'occurred_at' => $row->occurred_at,
                'recorded_at' => $row->recorded_at,
                'delivery' => $deliveryByEvent[(int) $row->id] ?? [
                    'done' => 0, 'pending' => 0, 'failed' => 0, 'skipped' => 0,
                ],
            ])->all(),
            'total' => $total,
        ];
    }

    /**
     * Delivery counts for a page of events, in one query rather than one per row.
     *
     * @param  array<int, mixed>  $eventIds
     * @return array<int, array<string, int>>
     */
    private function deliveryCountsFor(array $eventIds, int $tenantId): array
    {
        if ($eventIds === []) {
            return [];
        }

        $rows = $this->deliveries($tenantId)
            ->whereIn(self::DELIVERY . '.event_id', $eventIds)
            ->select(
                self::DELIVERY . '.event_id',
                self::DELIVERY . '.status',
                DB::raw('count(*) as total')
            )
            ->groupBy(self::DELIVERY . '.event_id', self::DELIVERY . '.status')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $id = (int) $row->event_id;
            $out[$id] ??= ['done' => 0, 'pending' => 0, 'failed' => 0, 'skipped' => 0];
            $out[$id][$row->status] = (int) $row->total;
        }

        return $out;
    }

    /**
     * Names for a set of actor ids.
     *
     * A missing row yields no entry rather than a placeholder: a deactivated user is a
     * real possibility, and "Unknown user" reads as a data fault when it is ordinary.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private function actorNames(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('tbluser')) {
            return [];
        }

        return DB::table('tbluser')
            ->whereIn('id', $ids)
            ->get(['id', 'first_name', 'middle_name', 'last_name'])
            ->mapWithKeys(function ($row) {
                $name = trim(implode(' ', array_filter([
                    trim((string) $row->first_name),
                    trim((string) $row->middle_name),
                    trim((string) $row->last_name),
                ])));

                // An id with no usable name is dropped rather than mapped to an empty
                // string, so the caller's `?? null` decides how to render it once.
                return $name === '' ? [] : [(int) $row->id => $name];
            })
            ->all();
    }

    /**
     * Every consumer the catalogue declares, with what the ledger says it has done.
     *
     * This is the screen the catalogue makes possible and that LMS K-12 has no
     * equivalent of: `EventCatalogue` already states which events exist, who consumes
     * each one, and whether that consumer is a projector or a reactor. Joining it to the
     * delivery counts turns a static declaration into "is this actually running".
     *
     * `resolves` is the honest column. A consumer named in the catalogue whose class no
     * longer exists is a configuration fault, and the catalogue can say so without the
     * screen guessing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function consumers(int $tenantId): array
    {
        $declared = [];

        foreach (EventCatalogue::SHIPPED as $event => $consumers) {
            foreach ($consumers as $name => $kind) {
                $declared[$name] ??= ['kind' => $kind, 'events' => []];
                $declared[$name]['events'][] = $event;
            }
        }

        $counts = $this->deliveries($tenantId)
            ->select(
                self::DELIVERY . '.consumer',
                self::DELIVERY . '.status',
                DB::raw('count(*) as total'),
                DB::raw('max(' . self::DELIVERY . '.completed_at) as last_completed_at')
            )
            ->groupBy(self::DELIVERY . '.consumer', self::DELIVERY . '.status')
            ->get();

        $ledger = [];

        foreach ($counts as $row) {
            $ledger[$row->consumer] ??= [
                'done' => 0, 'pending' => 0, 'failed' => 0, 'skipped' => 0,
                'last_completed_at' => null,
            ];
            $ledger[$row->consumer][$row->status] = (int) $row->total;

            if ($row->last_completed_at !== null) {
                $current = $ledger[$row->consumer]['last_completed_at'];
                if ($current === null || $row->last_completed_at > $current) {
                    $ledger[$row->consumer]['last_completed_at'] = $row->last_completed_at;
                }
            }
        }

        $out = [];

        foreach ($declared as $name => $meta) {
            $ledgerKey = $this->ledgerKeyFor($name);
            $seen = $ledgerKey === null ? null : ($ledger[$ledgerKey] ?? null);

            $out[] = [
                'name' => $name,
                'kind' => $meta['kind'],
                'kind_label' => $meta['kind'] === EventCatalogue::PROJECTOR ? 'Projector' : 'Reactor',
                'events' => $meta['events'],
                'resolves' => EventCatalogue::resolveConsumer($name) !== null,
                // Shown on screen as provenance. It is not the same string as `name`,
                // and somebody querying the ledger by hand needs this one.
                'ledger_key' => $ledgerKey,
                /*
                 * Null, not zero, when the ledger key could not be determined.
                 *
                 * A consumer whose class does not resolve has no counts to report, and
                 * "0 delivered" would be a claim about the data rather than about the
                 * lookup. `counts_available` lets the screen say which it is.
                 */
                'counts_available' => $ledgerKey !== null,
                'done' => $ledgerKey === null ? null : ($seen['done'] ?? 0),
                'pending' => $ledgerKey === null ? null : ($seen['pending'] ?? 0),
                'failed' => $ledgerKey === null ? null : ($seen['failed'] ?? 0),
                'skipped' => $ledgerKey === null ? null : ($seen['skipped'] ?? 0),
                // Null rather than zero: a consumer that has never run has no last-run
                // time, and an epoch or a 0 would both read as a date.
                'last_completed_at' => $seen['last_completed_at'] ?? null,
            ];
        }

        usort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * The name a consumer writes into the delivery ledger.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * IT IS NOT THE NAME THE CATALOGUE USES, AND THAT COST ME A WORKING SCREEN
     * ═══════════════════════════════════════════════════════════════════════
     *
     * `EventCatalogue::SHIPPED` is keyed by CLASS name — `AuditLogProjector`. The ledger
     * is keyed by each class's own `CONSUMER` constant — `audit_log_projector`. Joining
     * the two on the catalogue's name matches nothing, and the failure is silent in the
     * worst way: every consumer reports `done = 0`, so a perfectly healthy event bus
     * renders as one where nothing has ever been delivered. The first build of this
     * method did exactly that, against a database holding 145 delivered rows.
     *
     * So the constant is read from the class rather than guessed by transforming the
     * name. A `str_snake()` of the class name happens to produce the right string for
     * all nine consumers today, which is precisely what makes it the wrong thing to
     * rely on: the day one of them picks a different constant, the guess breaks quietly
     * and this comment is what would have prevented it.
     *
     * Returns null when the class does not resolve or declares no constant, so the
     * caller reports "unknown" rather than zero.
     */
    private function ledgerKeyFor(string $catalogueName): ?string
    {
        $class = EventCatalogue::resolveConsumer($catalogueName);

        if ($class === null || ! defined($class . '::CONSUMER')) {
            return null;
        }

        $key = constant($class . '::CONSUMER');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Failed deliveries, newest first, with the error the consumer reported.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function failures(int $tenantId, int $page, int $limit): array
    {
        $query = $this->deliveries($tenantId)->where(self::DELIVERY . '.status', 'failed');

        $total = (clone $query)->count();

        $rows = $query
            ->orderByDesc(self::EVENTS . '.occurred_at')
            ->forPage($page, $limit)
            ->get([
                self::DELIVERY . '.id',
                self::DELIVERY . '.consumer',
                self::DELIVERY . '.attempts',
                self::DELIVERY . '.last_error',
                self::EVENTS . '.type',
                self::EVENTS . '.entity_type',
                self::EVENTS . '.entity_id',
                self::EVENTS . '.occurred_at',
            ]);

        return [
            'rows' => $rows->map(fn ($row) => [
                'id' => (int) $row->id,
                'consumer' => $row->consumer,
                'attempts' => (int) $row->attempts,
                'last_error' => $row->last_error,
                'type' => $row->type,
                'entity_type' => $row->entity_type,
                'entity_id' => $row->entity_id === null ? null : (int) $row->entity_id,
                'occurred_at' => $row->occurred_at,
            ])->all(),
            'total' => $total,
        ];
    }

    /**
     * The catalogue itself: what events exist, who listens, and is it coherent.
     *
     * Costs no query at all — it is three `const` arrays and a self-check — and is the
     * most useful page in the console, because it answers the question the ledger
     * cannot: an event nobody consumes produces no delivery rows, so it is invisible in
     * every other view precisely when it is most wrong.
     *
     * @return array<string, mixed>
     */
    public function catalogue(): array
    {
        $shipped = [];

        foreach (EventCatalogue::SHIPPED as $event => $consumers) {
            $shipped[] = [
                'event' => $event,
                'consumers' => array_map(
                    fn ($name, $kind) => [
                        'name' => $name,
                        'kind' => $kind,
                        'kind_label' => $kind === EventCatalogue::PROJECTOR ? 'Projector' : 'Reactor',
                        'resolves' => EventCatalogue::resolveConsumer($name) !== null,
                    ],
                    array_keys($consumers),
                    array_values($consumers)
                ),
            ];
        }

        return [
            'shipped' => $shipped,
            'not_shipped' => array_keys(EventCatalogue::NOT_SHIPPED),
            'not_notified' => array_keys(EventCatalogue::NOT_NOTIFIED),
            // The catalogue's own consistency check. Reported rather than thrown: a
            // console that 500s because the catalogue has a problem is the one screen
            // that could have told you what the problem was.
            'problems' => EventCatalogue::assertInvariants(),
        ];
    }

    /**
     * The distinct event types this tenant has actually recorded.
     *
     * Read from the data rather than from the catalogue on purpose. The catalogue
     * declares 25 types; the store holds types that predate it and types added since,
     * and a filter offering only catalogued values would hide the rows most worth
     * finding.
     *
     * @return array<int, string>
     */
    public function eventTypes(int $tenantId): array
    {
        return $this->events($tenantId)
            ->select('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->all();
    }
}
