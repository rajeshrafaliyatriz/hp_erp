<?php

namespace App\Services\Events;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE REPLAY RUNNER — `05-data-flow-contracts.md` §6.2, as code.
 *
 * §6.2 exists because replay is both the property that makes the event store
 * worth having AND the single most destructive command in the system: step 1
 * truncates a table users are reading. Written down it is routine; undocumented
 * it is the outage.
 *
 * THE RUNNER REFUSES TO START without three explicit arguments - projector list,
 * recorded store max(id), replay mode ON. **No defaults**, because every default
 * here is a way to lose data quietly.
 *
 * WHAT THIS CLASS DOES NOT DO, deliberately:
 *   - it is NEVER automatic. No schedule, no deploy hook, no self-healing job.
 *     Every run is a human decision with a change record.
 *   - it never targets more than ONE projection per run: failures must be
 *     attributable.
 *   - it never touches a REACTOR ledger. Those rows are permanent.
 *
 * ROLLBACK IS RESTORE, NOT REPLAY. If the projector is what went wrong, running
 * it again reproduces the same result. That is why precondition 4 is a backup and
 * why this class has no rollback() method - offering one would imply replay could
 * undo itself.
 */
class ReplayRunner
{
    /** @var array<string, class-string> the ONLY replayable consumers */
    private const PROJECTORS = [
        AuditLogProjector::CONSUMER   => AuditLogProjector::class,
        TaskStatusProjector::CONSUMER => TaskStatusProjector::class,
    ];

    /** projection table per projector - the truncation target */
    private const TABLES = [
        AuditLogProjector::CONSUMER   => 'g2g_audit_log',
        TaskStatusProjector::CONSUMER => 'task_status_history',
    ];

    /**
     * STEP 1-2 — DRY RUN into a SHADOW table. Store untouched, live untouched.
     *
     * @return array{shadow:int, live:int, verdict:string}
     */
    public function dryRun(string $consumer, int $storeMaxId): array
    {
        $this->assertReplayable($consumer);

        $live   = self::TABLES[$consumer];
        $shadow = $live . '_shadow';

        DB::statement("DROP TABLE IF EXISTS `$shadow`");
        DB::statement("CREATE TABLE `$shadow` LIKE `$live`");

        // Re-derive into the shadow by pointing the projector's writes at it.
        // Same store max(id) as step 0, so the comparison is against a fixed
        // horizon rather than a moving one.
        $this->rebuildInto($consumer, $shadow, $storeMaxId);

        $shadowCount = (int) DB::table($shadow)->count();
        $liveCount   = (int) DB::table($live)->count();

        // §6.2 step 2's four outcomes. "Empty or short" and "unexpected" both STOP.
        $verdict = match (true) {
            $shadowCount === 0 && $liveCount > 0 => 'STOP — shadow is EMPTY. Usually a filter or a tenant scope silently excluding events.',
            $shadowCount < $liveCount            => 'STOP — shadow is SHORT. Investigate before proceeding.',
            $shadowCount === $liveCount          => 'PROCEED — identical row count; the projector is sound.',
            default                              => 'REVIEW — shadow has MORE rows than live. Proceed only if this is the intended fix; paste the diff into the change record.',
        };

        return ['shadow' => $shadowCount, 'live' => $liveCount, 'verdict' => $verdict];
    }

    /**
     * STEP 4-5 — EXECUTE, then VERIFY.
     *
     * @return array{rebuilt:int, projector_ledger_before:int, projector_ledger_after:int, reactor_ledger_before:int, reactor_ledger_after:int, reactor_ledger_intact:bool}
     */
    public function execute(string $consumer, int $storeMaxId, bool $replayMode): array
    {
        // The three refusals. No defaults.
        if (!$replayMode) {
            throw new \InvalidArgumentException('Replay mode must be ON. The runner does not default it.');
        }
        if ($storeMaxId <= 0) {
            throw new \InvalidArgumentException('A recorded store max(id) is required. The runner does not default it.');
        }
        $this->assertReplayable($consumer);

        $reactors = EventCatalogue::reactors();

        // 5b's counter, taken BEFORE. This is the safeguard that catches a
        // Reactor mistyped as a Projector.
        $reactorBefore = $this->reactorLedgerCount();
        $projBefore    = (int) DB::table('g2g_event_delivery')->where('consumer', $consumer)->count();

        ReplayMode::enable();

        try {
            // §6.1 steps 1-4, in order.
            DB::table(self::TABLES[$consumer])->truncate();                       // 1
            DB::table('g2g_event_delivery')->where('consumer', $consumer)->delete(); // 2 - THIS projector only
            $rebuilt = $this->rebuildInto($consumer, self::TABLES[$consumer], $storeMaxId); // 3-4
        } finally {
            ReplayMode::disable();
        }

        $reactorAfter = $this->reactorLedgerCount();
        $projAfter    = (int) DB::table('g2g_event_delivery')->where('consumer', $consumer)->count();

        return [
            'rebuilt'                 => $rebuilt,
            'projector_ledger_before' => $projBefore,
            'projector_ledger_after'  => $projAfter,
            'reactor_ledger_before'   => $reactorBefore,
            'reactor_ledger_after'    => $reactorAfter,
            'reactor_ledger_intact'   => $reactorBefore === $reactorAfter,
        ];
    }

    /** Reactor ledger rows across every reactor in the catalogue. */
    public function reactorLedgerCount(): int
    {
        $consumers = array_map(
            fn ($c) => strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $c)),
            EventCatalogue::reactors()
        );

        return (int) DB::table('g2g_event_delivery')->whereIn('consumer', $consumers)->count();
    }

    private function assertReplayable(string $consumer): void
    {
        if (!isset(self::PROJECTORS[$consumer])) {
            throw new \InvalidArgumentException(
                "[$consumer] is not a registered projector. Replay targets projections only; "
                . 'a justified independent writer or a reactor is never a target (§6.2 precondition 3).'
            );
        }
    }

    /**
     * Re-derive one projector's output into $table, up to $storeMaxId.
     */
    private function rebuildInto(string $consumer, string $table, int $storeMaxId): int
    {
        $class     = self::PROJECTORS[$consumer];
        $projector = app($class);
        $types = [];
        foreach (EventCatalogue::SHIPPED as $type => $consumers) {
            foreach ($consumers as $name => $kind) {
                if (strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name)) === $consumer) {
                    $types[] = $type;
                }
            }
        }

        $events = DB::table('g2g_event')
            ->where('id', '<=', $storeMaxId)
            ->when($types !== [], fn ($q) => $q->whereIn('type', $types))
            ->orderBy('occurred_at')->orderBy('id')
            ->get();

        $n = 0;
        foreach ($events as $event) {
            // The projector writes straight into $table. For a dry run that is
            // the shadow, and the LIVE TABLE IS NEVER TOUCHED - not even
            // transiently, and no delivery-ledger row is written either.
            $projector->project($event, $table);
            $n++;
        }

        return $n;
    }
}
