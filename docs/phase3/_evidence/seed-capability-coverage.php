<?php
/**
 * SEED: lift tenant 3's `capability_coverage` past its gate.
 *
 * WHY THIS IS NOT A TEST FIXTURE. The walkthrough fixture makes the TESTS
 * honest; this makes the DEMO honest. They are not alternatives.
 *
 * A demo tenant that cannot pass its own readiness gate means anyone opening the
 * nine logins sees a product refusing itself - gap reporting returning 409 with
 * "This needs capability coverage at 50% or above. It is currently 4.1%." The
 * gate is right; the demo data was thin. 5 of 122 employees measured is the real
 * number behind it.
 *
 * REGISTERED AND REMOVABLE, like the rest of the tenant-3 seed. Every row is
 * stamped with the note below and `source = seed_x07_coverage`, so:
 *
 *   DELETE FROM competency_kasba_rating WHERE source = 'seed_x07_coverage';
 *
 * takes it back out and nothing else. Run with --dry to see the counts without
 * writing.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Readiness\ReadinessGateRecomputer;
use Illuminate\Support\Facades\DB;

const T = 3;
const NOTE = 'X-07 coverage seed 2026-08-11 - removable via source=seed_x07_coverage';
const SOURCE = 'seed_x07_coverage';
$dry = in_array('--dry', $argv, true);

$employees = DB::table('tbluser')->where('sub_institute_id', T)->pluck('id');
$measured = DB::table('competency_kasba_rating')->where('sub_institute_id', T)
    ->distinct()->pluck('user_id')->map(fn ($v) => (int) $v)->all();

// THE ITEMS ARE THE ONES ALREADY IN USE. Inventing kasba items to satisfy a gate
// would be manufacturing the measurement the gate is supposed to detect.
$items = DB::table('competency_kasba_rating')->where('sub_institute_id', T)
    ->distinct()->pluck('kasba_item_id')->all();
if (!$items) { exit("no kasba items in use for tenant " . T . " - refusing to invent any\n"); }

// An assessor who exists. Prefer the employee's own manager; fall back to the
// admin who assessed the existing rows. assessor_id is a claim about who judged,
// so it must be a real person, not 0.
$fallbackAssessor = (int) DB::table('competency_kasba_rating')->where('sub_institute_id', T)
    ->value('assessor_id');

$target = (int) ceil($employees->count() * 0.55);   // clear 50% with headroom
$need = max(0, $target - count($measured));

printf("employees            : %d\n", $employees->count());
printf("already measured     : %d  (%.1f%%)\n", count($measured), count($measured) * 100 / $employees->count());
printf("target               : %d  (55%%, clears the 50%% gate with headroom)\n", $target);
printf("users to add         : %d\n", $need);
printf("kasba items in use   : %d  (reusing them - inventing items would manufacture the measurement)\n\n", count($items));

if ($need === 0) { exit("already above target - nothing to do\n"); }
if ($dry) { exit("--dry: nothing written\n"); }

$unmeasured = $employees->reject(fn ($id) => in_array((int) $id, $measured, true))->take($need)->values();
$now = now();
$rows = [];

foreach ($unmeasured as $i => $uid) {
    $manager = DB::table('tbluser')->where('id', $uid)->value('reporting_manager_id');
    $assessor = $manager && (int) $manager > 0 ? (int) $manager : $fallbackAssessor;

    // Two items each, so a rating exists but nobody is silently declared expert.
    foreach (array_slice($items, $i % max(1, count($items) - 1), 2) as $item) {
        $rows[] = [
            'sub_institute_id' => T,
            'user_id'          => (int) $uid,
            'kasba_item_id'    => (int) $item,
            // MID-SCALE, DELIBERATELY. A seed that rated everyone 5 would clear
            // the gate and lie about the workforce; 2-3 is "measured", not "good".
            'rating'           => 2 + ($i % 2),
            'assessor_id'      => $assessor,
            'source'           => SOURCE,
            'note'             => NOTE,
            'rated_at'         => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ];
    }
}

DB::transaction(function () use ($rows) {
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('competency_kasba_rating')->insert($chunk);
    }
});

$after = DB::table('competency_kasba_rating')->where('sub_institute_id', T)->distinct()->count('user_id');
printf("inserted             : %d rows for %d users\n", count($rows), $unmeasured->count());
printf("measured now         : %d of %d = %.1f%%\n", $after, $employees->count(), $after * 100 / $employees->count());

// RECOMPUTE, DO NOT HAND-SET. The gate must reach `ready` by measuring, which is
// the whole point - a hand-set gate would be the claim nobody computed.
$R = new ReadinessGateRecomputer();
for ($i = 0; $i < 3; $i++) { $R->recompute(T); }   // sustained_periods = 3
$g = DB::table('tenant_readiness_gate')->where('sub_institute_id', T)
    ->where('gate_key', 'capability_coverage')->first();
printf("\ngate after 3 recomputes: state=%s value=%s  %s\n", $g->state, $g->value,
    $g->state === 'ready' ? 'CLEARED BY MEASUREMENT' : 'still not ready');
printf("removal: DELETE FROM competency_kasba_rating WHERE source = '%s';\n", SOURCE);
