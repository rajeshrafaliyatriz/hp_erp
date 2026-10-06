<?php
/**
 * X-07b PROVEN BY A WRITE, per G-EVT-03 - not by a catalogue declaration.
 *   1. recompute produces ROWS for real tenants;
 *   2. it is idempotent - a second run updates, never duplicates;
 *   3. THE ASYMMETRY HOLDS: a ready gate that falls goes to at_risk, and this
 *      class can NEVER return blocked from ready or at_risk on its own.
 * (3) is exercised on the state machine directly, because the measured values
 * cannot be made to fall on demand without writing false data to a shared
 * database - which is the thing being guarded against, not a test technique.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Readiness\ReadinessGateRecomputer;
use Illuminate\Support\Facades\DB;

$R = new ReadinessGateRecomputer();

printf("rows before : %d\n", DB::table('tenant_readiness_gate')->count());
$R->recomputeAll();
$first = DB::table('tenant_readiness_gate')->count();
$R->recomputeAll();                                   // second pass
$second = DB::table('tenant_readiness_gate')->count();

printf("rows after 1st run : %d\n", $first);
printf("rows after 2nd run : %d   %s\n", $second,
    $first === $second ? 'IDEMPOTENT' : '*** DUPLICATED ***');

echo "\nWRITTEN STATE (tenants 3 and 7):\n";
printf("  NOTE: %s
", ReadinessGateRecomputer::FIRST_RUN_NOTE);
foreach ([3, 7] as $t) {
    foreach (DB::table('tenant_readiness_gate')->where('sub_institute_id', $t)->orderBy('gate_key')->get() as $g) {
        printf("  t%-2d %-21s %-8s %8s %-7s enable>=%-6s %s\n",
            $t, $g->gate_key, $g->state,
            $g->value === null ? 'NULL' : rtrim(rtrim($g->value, '0'), '.'),
            $g->unit, rtrim(rtrim($g->enable_threshold, '0'), '.'),
            substr((string) $g->remedy, 0, 42));
    }
}

$dropped = DB::table('tenant_readiness_gate')->where('gate_key', 'task_competency_link')->count();
printf("\ntask_competency_link rows (must be 0, the gate is dropped): %d\n", $dropped);

// ── THE ASYMMETRY, exercised directly ───────────────────────────────────────
echo "\nASYMMETRIC SWITCHING - can this class turn a feature OFF by itself?\n";
$m = new ReflectionMethod($R, 'nextState');
$m->setAccessible(true);
$spec = ['enable' => 80, 'disable' => 65];
$row  = (object) ['sustained_periods' => 3];

$cases = [
    ['ready',   50.0, 0, 'at_risk', 'READY falls below disable -> at_risk, FEATURE STAYS ON'],
    ['at_risk', 50.0, 0, 'at_risk', 'at_risk stays at_risk - only a human moves it to blocked'],
    ['at_risk', 90.0, 1, 'ready',   'at_risk recovers -> ready with NO acknowledgement'],
    ['blocked', 90.0, 1, 'blocked', 'blocked + 1 pass -> still blocked (sustained=3)'],
    ['blocked', 90.0, 3, 'ready',   'blocked + 3 passes -> ready. ON may be automatic'],
    ['ready',   70.0, 0, 'ready',   'between thresholds -> holds. Hysteresis, no flap'],
];
$fail = 0;
foreach ($cases as [$was, $val, $passes, $want, $label]) {
    $got = $m->invoke($R, $was, $val, $passes, $spec, $row);
    if ($got !== $want) $fail++;
    printf("  %-7s value %-5s passes %d -> %-8s %s  %s\n",
        $was, $val, $passes, $got, $got === $want ? ' ok' : '*** WANT ' . $want . ' ***', $label);
}

// THE ONE THAT MATTERS: no input reaches blocked from ready or at_risk.
$reached = [];
foreach (['ready', 'at_risk'] as $was) {
    foreach ([0.0, 10.0, 50.0, 64.9, 65.0, 79.9, 80.0, 100.0] as $v) {
        foreach ([0, 1, 3, 9] as $p) {
            if ($m->invoke($R, $was, $v, $p, $spec, $row) === 'blocked') $reached[] = "$was/$v/$p";
        }
    }
}
printf("\n  64 inputs from ready/at_risk reaching 'blocked': %d  %s\n",
    count($reached),
    $reached ? '*** ' . implode(' ', array_slice($reached, 0, 4)) . ' ***'
             : 'NONE - the class cannot disable a feature by itself');

// KNOWN-NEGATIVE: the sweep must be able to SEE a blocked outcome, or its zero
// means nothing. blocked/0.0/0 is the case that legitimately yields blocked.
printf("  known-negative (from 'blocked', value 0): %s  %s\n",
    $m->invoke($R, 'blocked', 0.0, 0, $spec, $row),
    $m->invoke($R, 'blocked', 0.0, 0, $spec, $row) === 'blocked'
        ? '<- the sweep CAN see blocked, so its zero is real' : '*** sweep is blind ***');

printf("\nVERDICT: %s\n", $fail === 0 && !$reached && $first === $second ? 'PASS' : '*** FAIL ***');
