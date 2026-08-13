<?php
/**
 * CapabilityEvidenceProjector. Kind P, so three things must hold:
 *   1. it projects - a handled event produces a row;
 *   2. it is IDEMPOTENT - re-projecting writes the same row, not a second one;
 *   3. it is PURE - it writes competency_evidence and its own ledger entry, and
 *      touches NOTHING else. Proven by counting every other table before and
 *      after, not by reading the class.
 * Cleans up after itself: shared remote database.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Events\CapabilityEvidenceProjector;
use Illuminate\Support\Facades\DB;

$P = new CapabilityEvidenceProjector();

// ── handled set, from the class, not restated ───────────────────────────────
foreach (['task.rejected', 'task.reopened', 'capability.flag_resolved',
          'certification.issued', 'course.completed'] as $t) {
    printf("  handles %-26s %s\n", $t, $P->handles($t) ? 'yes' : 'no');
}
echo "  (course.completed must be NO - it belongs to other consumers)\n\n";

// ── PURITY BASELINE: every table in the schema, counted ─────────────────────
$tables = [];
foreach (DB::select('SHOW TABLES') as $r) {
    $t = array_values((array) $r)[0];
    if (str_starts_with($t, 'hpbrain_')) continue;   // never read, never touched
    $tables[$t] = (int) DB::table($t)->count();
}
printf("purity baseline: %d tables counted\n\n", count($tables));

$evt = (object) [
    'id'               => 999000001,
    'type'             => 'task.rejected',
    'sub_institute_id' => 3,
    'entity_id'        => 1,
    'actor_id'         => 6,
    'occurred_at'      => now()->toDateTimeString(),
    'payload'          => json_encode(['after' => ['user_id' => 6, 'kasba_type' => 'skill', 'item_id' => 12]]),
];

$P->project($evt);
$after1 = DB::table('competency_evidence')->where('source_id', $evt->id)->get();
$P->project($evt);                                    // REPLAY
$after2 = DB::table('competency_evidence')->where('source_id', $evt->id)->get();

printf("  first  project -> %d row(s)\n", $after1->count());
printf("  replay project -> %d row(s)   %s\n", $after2->count(),
    $after2->count() === 1 ? 'IDEMPOTENT' : '*** DUPLICATED ***');

$row = $after2->first();
if ($row) {
    printf("  direction=%s outcome=%s kasba=%s item=%s recorded_by=%s tenant=%d\n",
        $row->direction, $row->outcome, $row->kasba_type ?? 'NULL',
        $row->item_id ?? 'NULL', $row->recorded_by ?? 'NULL', $row->sub_institute_id);
}

// HELD-NOT-GUESSED: an event that names no dimension must leave it NULL.
$bare = clone $evt;
$bare->id = 999000002;
$bare->payload = json_encode(['after' => ['user_id' => 6]]);
$P->project($bare);
$b = DB::table('competency_evidence')->where('source_id', $bare->id)->first();
printf("  event naming no kasba -> kasba_type=%s  %s\n",
    $b->kasba_type === null ? 'NULL' : $b->kasba_type,
    $b->kasba_type === null ? '(held, not guessed)' : '*** INVENTED A DIMENSION ***');

// ── PURITY VERDICT ──────────────────────────────────────────────────────────
$touched = [];
foreach ($tables as $t => $before) {
    $now = (int) DB::table($t)->count();
    if ($now !== $before) $touched[] = $t . ' (' . $before . '->' . $now . ')';
}
$allowed = ['competency_evidence', 'g2g_event_delivery'];
$illegal = array_values(array_filter($touched, function ($x) use ($allowed) {
    foreach ($allowed as $a) if (str_starts_with($x, $a . ' ')) return false;
    return true;
}));

printf("\n  tables whose count changed : %s\n", $touched ? implode(', ', $touched) : 'none');
printf("  OUTSIDE its projection     : %s\n", $illegal ? '*** ' . implode(', ', $illegal) . ' ***' : 'none - PURE');

// ── cleanup ─────────────────────────────────────────────────────────────────
DB::table('competency_evidence')->whereIn('source_id', [999000001, 999000002])->delete();
DB::table('g2g_event_delivery')->whereIn('event_id', [999000001, 999000002])->delete();
printf("\ncleaned up: competency_evidence back to %d rows\n",
    DB::table('competency_evidence')->count());
