<?php
/**
 * SLICE 4 ACCEPTANCE — the four claims, RUN.
 *
 * Item 4 is the last unproven claim in the event model: that a reactor's dispatch
 * ledger is PERMANENT and survives a rebuild. "Specified" has been kept distinct
 * from "verified" through this whole build, so it is executed here, with counts
 * before and after, not summarised.
 *
 * Cleans up after itself: this is a shared database.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\Events\EventRecorder;
use App\Services\Events\TaskStatusProjector;
use App\Services\Events\AuditLogProjector;
use App\Services\Events\NotificationDispatcher;
use App\Services\Events\ReplayRunner;
use App\Services\Events\ReplayMode;

$rec   = app(EventRecorder::class);
$tsp   = app(TaskStatusProjector::class);
$alp   = app(AuditLogProjector::class);
$nd    = app(NotificationDispatcher::class);
$run   = app(ReplayRunner::class);

const TENANT = 7;
const TASK   = 990001;

function line(string $s = ''): void { echo $s . "\n"; }

/* ─────────────────────────────────────────────────────────────────────────
 * 1. task_status_history — and F2 REOPEN DETECTABLE
 * ───────────────────────────────────────────────────────────────────────── */
line('=== 1. task_status_history — the store\'s first consumer ===');

$transitions = [
    ['OPEN',      'COMPLETED', null,       null,       'ordinary completion'],
    ['COMPLETED', 'IN_PROGRESS', null,     null,       'REOPEN — out of terminal, into active'],
    ['IN_PROGRESS','IN_REVIEW', null,      'approved', 'approval granted (becomes terminal)'],
    ['IN_REVIEW', 'IN_PROGRESS','approved', null,      'REOPEN — approval withdrawn'],
];

foreach ($transitions as [$from, $to, $fromAppr, $toAppr, $label]) {
    $rec->record(
        type: 'task.status_changed',
        subInstituteId: TENANT,
        entityType: 'task',
        entityId: TASK,
        actorId: 198,
        payload: [
            'before' => ['status' => $from, 'approve_status' => $fromAppr],
            'after'  => ['status' => $to,   'approve_status' => $toAppr],
        ],
    );
}

$tsp->catchUp();

$rows = DB::table('task_status_history')->where('task_id', TASK)->orderBy('id')->get();
printf("  %-14s %-14s %-9s %-8s %s\n", 'from', 'to', 'terminal', 'active', 'is_reopen');
foreach ($rows as $r) {
    printf("  %-14s %-14s %-9s %-8s %s\n",
        $r->from_status . ($r->from_approve_status ? '/' . $r->from_approve_status : ''),
        $r->to_status . ($r->to_approve_status ? '/' . $r->to_approve_status : ''),
        $r->from_terminal ? 'yes' : 'no',
        $r->to_active ? 'yes' : 'no',
        $r->is_reopen ? '*** YES ***' : 'no');
}
$reopens = DB::table('task_status_history')->where('task_id', TASK)->where('is_reopen', 1)->count();
printf("\n  F2 reopens detected: %d  (expected 2)  -> %s\n\n", $reopens, $reopens === 2 ? 'PASS' : 'FAIL');

/* ─────────────────────────────────────────────────────────────────────────
 * 3. THE REACTOR — throw on replay. Show the exception.
 * ───────────────────────────────────────────────────────────────────────── */
line('=== 3. NotificationDispatcher — THROW-ON-REPLAY ===');

$evt = DB::table('g2g_event')->where('entity_id', TASK)->orderBy('id')->first();

/* live dispatch: writes a permanent ledger row */
$nd->dispatch($evt);
$reactorRows = DB::table('g2g_event_delivery')->where('consumer', NotificationDispatcher::CONSUMER)->count();
printf("  live dispatch      : OK, reactor ledger rows = %d\n", $reactorRows);

/* replay dispatch: must THROW */
ReplayMode::enable();
try {
    $nd->dispatch($evt);
    line('  replay dispatch    : *** NO EXCEPTION — SPEC VIOLATED ***');
} catch (\Throwable $e) {
    printf("  replay dispatch    : THREW %s\n", get_class($e));
    printf("                       \"%s\"\n", $e->getMessage());
} finally {
    ReplayMode::disable();
}
line();

/* ─────────────────────────────────────────────────────────────────────────
 * 2. §6.2 — the runner's REFUSALS, and the DRY RUN into a shadow table
 * ───────────────────────────────────────────────────────────────────────── */
line('=== 2. Replay operating procedure (§6.2) ===');

$storeMax = (int) DB::table('g2g_event')->max('id');
printf("  step 0 RECORD      : store max(id) = %d\n", $storeMax);

foreach ([
    ['replay mode OFF',        fn () => $run->execute(TaskStatusProjector::CONSUMER, $storeMax, false)],
    ['no store max(id)',       fn () => $run->execute(TaskStatusProjector::CONSUMER, 0, true)],
    ['a REACTOR as target',    fn () => $run->execute(NotificationDispatcher::CONSUMER, $storeMax, true)],
] as [$label, $call]) {
    try { $call(); printf("  refusal [%-18s]: *** ACCEPTED — SPEC VIOLATED ***\n", $label); }
    catch (\Throwable $e) { printf("  refusal [%-18s]: REFUSED — %s\n", $label, substr($e->getMessage(), 0, 62)); }
}

$liveBefore = DB::table('task_status_history')->count();
$dry = $run->dryRun(TaskStatusProjector::CONSUMER, $storeMax);
$liveAfter = DB::table('task_status_history')->count();
printf("\n  step 1 DRY RUN     : shadow=%d live=%d\n", $dry['shadow'], $dry['live']);
printf("  step 2 DIFF        : %s\n", $dry['verdict']);
printf("  live table touched : %s\n\n", $liveBefore === $liveAfter ? 'NO (' . $liveBefore . ' before and after)' : '*** YES — ' . $liveBefore . ' -> ' . $liveAfter . ' ***');

/* ─────────────────────────────────────────────────────────────────────────
 * 4. A REAL REBUILD — the last unproven claim
 * ───────────────────────────────────────────────────────────────────────── */
line('=== 4. REAL REBUILD — does the PERMANENT reactor ledger survive? ===');

$res = $run->execute(TaskStatusProjector::CONSUMER, $storeMax, true);

printf("  projector ledger   : %d before -> %d after   (CLEARED then re-derived)\n",
    $res['projector_ledger_before'], $res['projector_ledger_after']);
printf("  reactor ledger     : %d before -> %d after   %s\n",
    $res['reactor_ledger_before'], $res['reactor_ledger_after'],
    $res['reactor_ledger_intact'] ? '<- PERMANENT, SURVIVED' : '*** DESTROYED — SPEC VIOLATED ***');
printf("  rows re-derived    : %d\n", $res['rebuilt']);

$reopensAfter = DB::table('task_status_history')->where('task_id', TASK)->where('is_reopen', 1)->count();
printf("  F2 reopens after   : %d  (was %d)  -> %s\n", $reopensAfter, $reopens,
    $reopensAfter === $reopens ? 'projection identical after rebuild' : '*** DRIFTED ***');

line();
line('  VERDICT: ' . (
    $res['reactor_ledger_intact'] && $reopensAfter === $reopens
        ? 'PASS — projector ledger cleared and re-derived, reactor ledger permanent.'
        : '*** FAIL ***'
));

/* ─────────────────────────────────────────────────────────────────────────
 * cleanup — shared database
 * ───────────────────────────────────────────────────────────────────────── */
DB::statement('DROP TABLE IF EXISTS `task_status_history_shadow`');
$ids = DB::table('g2g_event')->where('entity_id', TASK)->pluck('id');
DB::table('g2g_event_delivery')->whereIn('event_id', $ids)->delete();
DB::table('task_status_history')->whereIn('event_id', $ids)->delete();
DB::table('g2g_audit_log')->whereIn('event_id', $ids)->delete();
DB::table('g2g_event')->whereIn('id', $ids)->delete();
line();
printf("  cleaned: events=%d history=%d audit=%d delivery=%d\n",
    DB::table('g2g_event')->count(), DB::table('task_status_history')->count(),
    DB::table('g2g_audit_log')->count(), DB::table('g2g_event_delivery')->count());
