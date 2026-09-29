<?php

namespace App\Services\Platform;

use App\Services\Events\EventCatalogue;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
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
 * ── ROUND 2: ONE NARROW WRITE, AND WHY IT DOES NOT BREAK THE RULE ABOVE ─────
 *
 * `replay()` is the one exception, and it is scoped tightly enough to still honour the
 * reasoning that kept this class read-only for a whole round: a PROJECTOR is pure, so
 * re-running it for ONE event it already covers is harmless — `AuditLogProjector`,
 * `TaskStatusProjector` and `CapabilityEvidenceProjector` all write with
 * `updateOrInsert()` keyed on the event, so calling `project($event)` twice produces
 * the same row, not a duplicate. `replay()` refuses anything else: a reactor is never
 * accepted, checked against `EventCatalogue`'s own declared kind rather than trusted
 * from the caller, so the refusal holds even if the endpoint is called directly. This
 * is NOT `ReplayRunner` (`app/Services/Events/ReplayRunner.php`) and must never be
 * confused with it — that class truncates and rebuilds a WHOLE projection table across
 * every tenant, is deliberately not exposed over HTTP, and exists for an operator
 * running a documented, backed-up procedure. `replay()` here touches exactly one row,
 * for one tenant's own event, and nothing else.
 *
 * ── ROUND 4: "LAST DRAIN PASS" IS NO LONGER PERMANENTLY UNAVAILABLE ─────────
 *
 * This class's own doc comment used to say `events:project` "writes no run ledger" —
 * true when it was written, false since `TaskRunLedger::track()` wrapped every entry in
 * `routes/console.php`, `events:project` included. `lastDrainPass()` reads that ledger
 * now. The class comment above (M6-era) is left as history of why the store existed
 * unread for as long as it did; only the summary tile's own honesty claim needed fixing.
 */
class EventBusReader
{
    private const EVENTS = 'g2g_event';
    private const DELIVERY = 'g2g_event_delivery';
    private const AUDIT = 'g2g_audit_log';
    private const RUNS = 'g2g_platform_task_runs';

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
     * `available: false` is the important field. A tile that cannot be computed says
     * so — showing `0` there would read as "nothing is behind", which is the opposite
     * of "we do not know", and an operations screen that invents a reassuring number is
     * worse than one that admits a gap. `last_drain_pass` was that gap for a whole
     * round (`TaskRunLedger` did not exist yet); it is now read from the same ledger
     * `Scheduler` shows, and stays honestly `available: false` only on an installation
     * where that ledger table itself is missing, or where `events:project` has never
     * completed a run.
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
                $this->lastDrainPassTile(),
            ],
        ];
    }

    /**
     * "Last drain pass" — read from the run ledger `TaskRunLedger` writes on every
     * `events:project` pass, not from anything this class records itself.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * WHY `events:project` ALONE, NOT ALSO `events:react`
     * ═══════════════════════════════════════════════════════════════════════
     *
     * "Drain" is the verb this tile's own label uses for taking the store from
     * append-only rows to something projected — that is what `events:project` does.
     * `events:react` runs issuing side effects (certificates, notifications) off
     * already-projected events; it is a separate concern the Consumers tab already
     * shows per-reactor, and folding it into one merged timestamp here would answer a
     * question ("when did the drain last run") with a number that might actually be
     * the reactor's, silently.
     *
     * `sub_institute_id` is never filtered — every entry in `routes/console.php` runs
     * with no `--tenant`, so every row this task writes is estate-wide by construction,
     * the same fact `TaskRunLedger::start()`'s own note relies on.
     *
     * @return array<string, mixed>
     */
    private function lastDrainPassTile(): array
    {
        if (! Schema::hasTable(self::RUNS)) {
            return $this->tile(
                'last_drain_pass',
                'Last drain pass',
                '—',
                'gray',
                false,
                null,
                'The task run ledger is not installed on this database.'
            );
        }

        $run = DB::table(self::RUNS)
            ->where('task_key', 'events:project')
            ->whereNotNull('finished_at')
            ->orderByDesc('started_at')
            ->first(['finished_at', 'status', 'duration_ms']);

        if ($run === null) {
            return $this->tile(
                'last_drain_pass',
                'Last drain pass',
                '—',
                'gray',
                false,
                null,
                'events:project has not completed a run yet.'
            );
        }

        $failed = $run->status === 'failed';

        return $this->tile(
            'last_drain_pass',
            'Last drain pass',
            Carbon::parse($run->finished_at)->diffForHumans(),
            $failed ? 'red' : 'gray',
            true,
            'g2g_platform_task_runs',
            $failed
                ? 'The last run failed. See the Scheduler console for the output.'
                : ('Took ' . number_format((int) $run->duration_ms) . 'ms.')
        );
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
     * A DISPLAY-ONLY threshold, not an enforced one.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * WHY "STUCK", NOT "GIVEN UP"
     * ═══════════════════════════════════════════════════════════════════════
     *
     * Neither `events:project` nor `events:react` caps retries anywhere in this
     * codebase — grepped for one before adding this and found nothing. Every failed
     * delivery is retried again on the next five- or ten-minute sweep, forever, with no
     * concept of giving up. So a row past this many attempts has NOT been abandoned by
     * anything; it is only distinguished here as "this has failed enough times that a
     * human should look at it", which is a claim about what is worth attention, not
     * about what the sweeper will do next. Calling it "given up" would be a second
     * false claim of exactly the kind this whole project has been removing.
     */
    private const STUCK_AFTER_ATTEMPTS = 5;

    /**
     * Failed deliveries, newest first, with the error the consumer reported.
     *
     * `$consumer` is an exact match against `g2g_event_delivery.consumer` — the ledger
     * key itself (`audit_log_projector`), which is what this table has always stored and
     * what every row on this page already shows, not the catalogue's class name.
     * `$search` is a `LIKE` over the error text. Both optional and both narrow rather
     * than replace the tenant scope, which is never a filter — it is always applied.
     *
     * Each row also carries `event_id` and `kind` — `kind` resolved from
     * `EventCatalogue::SHIPPED[type][consumer]` where the pairing is still declared
     * there, null where it is not (an old row whose type/consumer no longer appears
     * together in the catalogue). Both exist for exactly one purpose: letting the
     * screen offer Replay only where it could possibly succeed, on a row that already
     * IS one specific event-and-consumer pair — the server re-checks both anyway, see
     * `EventBusController::replay()`.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int, stuck_after: int}
     */
    public function failures(
        int $tenantId,
        int $page,
        int $limit,
        ?string $consumer = null,
        ?string $search = null
    ): array {
        $query = $this->deliveries($tenantId)->where(self::DELIVERY . '.status', 'failed');

        if ($consumer !== null && $consumer !== '') {
            $query->where(self::DELIVERY . '.consumer', $consumer);
        }

        if ($search !== null && $search !== '') {
            $query->where(self::DELIVERY . '.last_error', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%');
        }

        $total = (clone $query)->count();

        $rows = $query
            ->orderByDesc(self::EVENTS . '.occurred_at')
            ->forPage($page, $limit)
            ->get([
                self::DELIVERY . '.id',
                self::DELIVERY . '.event_id',
                self::DELIVERY . '.consumer',
                self::DELIVERY . '.attempts',
                self::DELIVERY . '.last_error',
                self::EVENTS . '.type',
                self::EVENTS . '.entity_type',
                self::EVENTS . '.entity_id',
                self::EVENTS . '.occurred_at',
            ]);

        return [
            'rows' => $rows->map(function ($row) {
                $catalogue = $this->catalogueEntryFor($row->type, $row->consumer);

                return [
                    'id' => (int) $row->id,
                    'event_id' => (int) $row->event_id,
                    'consumer' => $row->consumer,
                    'attempts' => (int) $row->attempts,
                    'stuck' => (int) $row->attempts >= self::STUCK_AFTER_ATTEMPTS,
                    'last_error' => $row->last_error,
                    'type' => $row->type,
                    'entity_type' => $row->entity_type,
                    'entity_id' => $row->entity_id === null ? null : (int) $row->entity_id,
                    'occurred_at' => $row->occurred_at,
                    // The catalogue CLASS name — what `replayEvent()` must send back as
                    // `consumer`, not the ledger key this row is otherwise keyed by. Null
                    // when the pairing is no longer declared, which correctly hides Replay
                    // for a row the catalogue can no longer vouch for.
                    'catalogue_consumer' => $catalogue['class'] ?? null,
                    'kind' => $catalogue['kind'] ?? null,
                ];
            })->all(),
            'total' => $total,
            'stuck_after' => self::STUCK_AFTER_ATTEMPTS,
        ];
    }

    /**
     * The catalogue's own class name and kind for a (type, ledger key) pair.
     *
     * `g2g_event_delivery.consumer` stores the LEDGER KEY (`audit_log_projector`);
     * `EventCatalogue::SHIPPED` is keyed by CLASS name (`AuditLogProjector`) per event
     * type. This walks that event type's declared consumers and reuses `ledgerKeyFor()`
     * — the same translation `consumers()` already relies on — to find whichever one
     * writes this ledger key, rather than guessing the class name from the key by
     * inverting a naming convention that has already broken this screen once (see
     * `ledgerKeyFor()`'s own note).
     *
     * @return array{class: string, kind: string}|null
     */
    private function catalogueEntryFor(string $type, string $ledgerKey): ?array
    {
        foreach (EventCatalogue::SHIPPED[$type] ?? [] as $class => $kind) {
            if ($this->ledgerKeyFor($class) === $ledgerKey) {
                return ['class' => $class, 'kind' => $kind];
            }
        }

        return null;
    }

    /**
     * One event, if it belongs to this tenant — the check `replay()` needs before
     * touching anything.
     */
    public function eventFor(int $tenantId, int $eventId): ?object
    {
        return $this->events($tenantId)->where(self::EVENTS . '.id', $eventId)->first();
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

    /**
     * Re-run one projector for one event. See the class note for why this is safe.
     *
     * `$event` must already have been confirmed to belong to the caller's tenant — via
     * `eventFor()` — and `$consumer` must already have been confirmed to be a
     * PROJECTOR-kind consumer of `$event->type` in `EventCatalogue::SHIPPED`. This
     * method does not re-check either, on purpose: those checks need request-shaped
     * error messages (404 vs 422, which field is wrong), which belong in the
     * controller, not buried in a service method whose contract would otherwise be
     * "trust me". `EventBusController::replay()` is the only caller and does both
     * before this is reached.
     *
     * @throws \RuntimeException if `$consumer` does not resolve to a real class — this
     *   one check stays here because it is not a request-validation question, it is a
     *   fact about whether the write can happen at all.
     */
    public function replay(object $event, string $consumer): void
    {
        $class = EventCatalogue::resolveConsumer($consumer);

        if ($class === null) {
            throw new \RuntimeException("The consumer \"$consumer\" does not resolve to a class.");
        }

        app($class)->project($event);
    }
}
