<?php
/**
 * X-07c. The acknowledgement path, proven by writes against a DISPOSABLE tenant.
 *
 * Uses sub_institute_id 999999, which no account belongs to, so no real gate row
 * is created, modified or deleted. Cleaned up at the end and the cleanup is
 * verified - shared remote database.
 *
 * FOUR THINGS MUST HOLD:
 *   1. a ready gate cannot be acknowledged;
 *   2. an at_risk gate INSIDE its warning period cannot be acknowledged;
 *   3. once the period has elapsed, acknowledging writes WHO and WHEN and only
 *      then sets blocked;
 *   4. the warning period comes from the ROW. Change the column, change the
 *      answer - proving no constant in the code is deciding it.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Readiness\ReadinessGateAcknowledger;
use Illuminate\Support\Facades\DB;

const T = 999999;
$A = new ReadinessGateAcknowledger();

function seed(string $state, ?string $atRiskSince, ?int $warningDays): void
{
    DB::table('tenant_readiness_gate')->updateOrInsert(
        ['sub_institute_id' => T, 'gate_key' => 'reporting_coverage'],
        ['state' => $state, 'unit' => 'percent', 'value' => 50,
         'enable_threshold' => 80, 'disable_threshold' => 65,
         'at_risk_since' => $atRiskSince, 'warning_days' => $warningDays,
         'acknowledged_by' => null, 'acknowledged_at' => null,
         'created_at' => now(), 'updated_at' => now()]
    );
}

function row(): ?object
{
    return DB::table('tenant_readiness_gate')
        ->where('sub_institute_id', T)->where('gate_key', 'reporting_coverage')->first();
}

// 1. a ready gate
seed('ready', null, 14);
$r = $A->acknowledge(T, 'reporting_coverage', 6);
printf("  ready gate                       -> %-46s state=%s\n", $r['status'], row()->state);

// 2. at_risk, 3 days into a 14-day period
seed('at_risk', now()->subDays(3)->toDateTimeString(), 14);
$r = $A->acknowledge(T, 'reporting_coverage', 6);
printf("  at_risk, day 3 of 14             -> %-46s state=%s (%d days left)\n",
    $r['status'], row()->state, $r['days_remaining']);

// 3. at_risk, 20 days into a 14-day period
seed('at_risk', now()->subDays(20)->toDateTimeString(), 14);
$r = $A->acknowledge(T, 'reporting_coverage', 6);
$after = row();
printf("  at_risk, day 20 of 14            -> %-46s state=%s\n", $r['status'], $after->state);
printf("      acknowledged_by=%s  acknowledged_at=%s  %s\n",
    $after->acknowledged_by ?? 'NULL', $after->acknowledged_at ?? 'NULL',
    ($after->acknowledged_by && $after->acknowledged_at && $after->state === 'blocked')
        ? 'WHO and WHEN recorded with the block' : '*** ATTRIBUTION MISSING ***');

// 4. THE PERIOD IS A COLUMN. Same elapsed time, different configured period.
//    If a constant in the code were deciding, these two would agree.
seed('at_risk', now()->subDays(20)->toDateTimeString(), 30);
$r30 = $A->acknowledge(T, 'reporting_coverage', 6);
seed('at_risk', now()->subDays(20)->toDateTimeString(), 7);
$r7 = $A->acknowledge(T, 'reporting_coverage', 6);
printf("\n  20 days elapsed, warning_days=30 -> %-46s blocked=%s\n", $r30['status'], $r30['blocked'] ? 'yes' : 'no');
printf("  20 days elapsed, warning_days=7  -> %-46s blocked=%s\n", $r7['status'], $r7['blocked'] ? 'yes' : 'no');
printf("  %s\n", $r30['blocked'] === false && $r7['blocked'] === true
    ? 'THE ROW DECIDES, not a constant in the code'
    : '*** the code is overriding the column ***');

// 5. at_risk with the clock never started. warning_days is NOT NULL in the
//    schema, so THAT null is unreachable - but at_risk_since is nullable and a
//    row can reach at_risk without a start time. Refusing is the point: a
//    warning period that never began has not elapsed.
seed('at_risk', null, 14);
$r = $A->acknowledge(T, 'reporting_coverage', 6);
printf("  at_risk, at_risk_since=NULL      -> %-46s blocked=%s\n", $r['status'], $r['blocked'] ? 'yes' : 'no');

// ── THE INVARIANT, over the REAL rows ───────────────────────────────────────
DB::table('tenant_readiness_gate')->where('sub_institute_id', T)->delete();
$off = $A->assertDisableIsAttributable();
printf("\n  real gates blocked after at_risk with no acknowledgement: %d  %s\n",
    count($off), $off ? '*** ' . implode('; ', $off) . ' ***' : 'none');

// KNOWN-NEGATIVE: the invariant must be able to SEE an offender, or its zero is
// a blind check. Plant one, confirm it is caught, remove it.
seed('blocked', now()->subDays(30)->toDateTimeString(), 14);
$caught = $A->assertDisableIsAttributable();
DB::table('tenant_readiness_gate')->where('sub_institute_id', T)->delete();
printf("  known-negative (planted offender): %s\n",
    count($caught) === 1 ? 'CAUGHT - the invariant can discriminate' : '*** BLIND: ' . count($caught) . ' ***');

printf("\ncleanup: rows left for tenant %d = %d\n", T,
    DB::table('tenant_readiness_gate')->where('sub_institute_id', T)->count());
