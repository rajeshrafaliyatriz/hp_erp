<?php
/**
 * F-153 / F-154 EVIDENCE — readiness gates reach every tenant, and a passing
 * gate no longer switches a capability off while it settles.
 *
 * Four things are proved, all against the real services:
 *
 *   1. tenantsToRecompute() covers organisations that have NO gate rows. The old
 *      list came from tenant_readiness_gate, so the nightly job skipped exactly
 *      the tenants nobody had ever measured - permanently.
 *   2. A soft-deleted tenant is NOT recomputed.
 *   3. A gate that is `blocked` but MEETS its threshold is ALLOWED. It is
 *      warming up through its sustained period, not failing, and turning a
 *      capability off is never automatic.
 *   4. A gate genuinely below its threshold is still REFUSED, with its remedy.
 *
 * 3 and 4 use rows this script creates and always rolls back.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-readiness-covers-new-tenants.php';"
 *   PROBE_CONN=live php artisan tinker --execute="..."   (same, against live)
 */
$conn = getenv('PROBE_CONN') ?: 'mysql';
DB::setDefaultConnection($conn);
$db = DB::connection($conn);

printf("connection: %s\n\n", $conn);

$recomputer = app(\App\Services\Readiness\ReadinessGateRecomputer::class);
$enforcer   = app(\App\Services\Readiness\ReadinessGateEnforcer::class);

// ── 1. COVERAGE ─────────────────────────────────────────────────────────────
$old = $db->table('tenant_readiness_gate')->distinct()->pluck('sub_institute_id')
    ->map(fn ($i) => (int) $i)->sort()->values()->all();
$new = $recomputer->tenantsToRecompute();
$added = array_values(array_diff($new, $old));

printf("OLD list (tenant_readiness_gate) : %d tenant(s)\n", count($old));
printf("NEW list (registry + gates)      : %d tenant(s)\n", count($new));
printf("NEWLY COVERED                    : %s\n", $added ? implode(', ', $added) : '(none)');

foreach ($added as $t) {
    $name = $db->table('school_setup')->where('id', $t)->value('SchoolName') ?? '(no registry row)';
    $users = $db->table('tbluser')->where('sub_institute_id', $t)->count();
    printf("    tenant %-8d %-28s %d user(s)\n", $t, $name, $users);
}

// ── 2. A SOFT-DELETED TENANT IS NOT RECOMPUTED ──────────────────────────────
DB::beginTransaction();
try {
    $victim = $new[count($new) - 1];
    $db->table('school_setup')->where('id', $victim)->update(['deleted_at' => now()]);
    $afterDelete = $recomputer->tenantsToRecompute();

    printf("\nsoft-deleted tenant %d -> in list? %s  %s\n",
        $victim,
        in_array($victim, $afterDelete, true) ? 'yes' : 'no',
        in_array($victim, $afterDelete, true) ? 'WRONG' : 'CORRECT');
} finally {
    DB::rollBack();
}

// ── 3 & 4. THE WARM-UP ALLOWANCE ────────────────────────────────────────────
DB::beginTransaction();
try {
    $t = 999999;                                   // a tenant id nothing else uses
    $spec = \App\Services\Readiness\ReadinessGateRecomputer::GATES['capability_coverage'];

    $insert = function (float $value, int $passes) use ($db, $t, $spec) {
        $db->table('tenant_readiness_gate')->where('sub_institute_id', $t)->delete();
        $db->table('tenant_readiness_gate')->insert([
            'sub_institute_id' => $t,
            'gate_key' => 'capability_coverage',
            'state' => 'blocked',                  // the state a first computation writes
            'unit' => 'percent',
            'value' => $value,
            'enable_threshold' => $spec['enable'],
            'disable_threshold' => $spec['disable'],
            'sustained_periods' => 3,
            'consecutive_passes' => $passes,
            'warning_days' => 14,
            'remedy' => 'Measure capability',
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    printf("\nenable threshold for capability_coverage = %s%%\n", $spec['enable']);

    // Passing, but only 1 of 3 consecutive passes - the first-run case.
    $insert(78.95, 1);
    $r = $enforcer->check($t, 'capability_coverage');
    printf("  blocked, value 78.95%% (passes)     -> %-8s %s\n",
        $r['allowed'] ? 'ALLOWED' : 'REFUSED',
        $r['allowed'] ? 'CORRECT - settling, not failing' : 'WRONG - a passing tenant lost the feature');
    printf("      reason: %s\n", $r['reason'] ?? '(none)');

    // Genuinely short. Nothing about this changes.
    $insert(12.00, 0);
    $r = $enforcer->check($t, 'capability_coverage');
    printf("  blocked, value 12.00%% (short)      -> %-8s %s\n",
        $r['allowed'] ? 'ALLOWED' : 'REFUSED',
        !$r['allowed'] ? 'CORRECT - below the bar' : 'WRONG - a failing gate stopped blocking');
    printf("      remedy: %s\n", $r['remedy'] ?? '(none)');
} finally {
    DB::rollBack();
    echo "\n(rolled back - no gate row created or changed)\n";
}
