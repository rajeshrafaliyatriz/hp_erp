<?php
/**
 * F-159 EVIDENCE — the reporting line can finally be set, and cannot be set wrong.
 *
 * ── WHAT WAS BROKEN ─────────────────────────────────────────────────────────
 *
 * The Reporting Manager picker existed in both the add sheet and the edit tab,
 * and it always sent a real user id. It wrote `supervisor_opt` - a VARCHAR(191)
 * whose live data is the string "Subordinate" on 71 rows. Nothing writes
 * `reporting_manager_id`, which is the column that
 *
 *   - the leave "Team" scope resolves,
 *   - approval routing walks,
 *   - and the `reporting_coverage` readiness gate measures.
 *
 * Hence 0 of 299 people on live having a manager, and that gate reading 0%.
 *
 * ── WHAT IS PROVED ──────────────────────────────────────────────────────────
 *
 *   1. Setting a manager through PUT /api/employees-management/{id} lands in
 *      reporting_manager_id.
 *   2. A manager from ANOTHER TENANT is refused.
 *   3. Somebody cannot be their own manager.
 *   4. A LOOP is refused - A -> B, then B -> A.
 *   5. The readiness gate's coverage actually moves as a result.
 *
 * Everything runs inside one transaction that is ALWAYS rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-reporting-line.php';"
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

$tenant = 6;

$admin = collect($db->table('tbluser')->where('sub_institute_id', $tenant)->get(['id']))
    ->first(fn ($u) => \App\Support\RoleKey::forUserId((int) $u->id) === 'administrator');

$adminUser = \App\Models\auth\tbluserModel::find($admin->id);
$token = $adminUser->createToken('reporting-line-evidence')->plainTextToken;

$put = function (int $id, array $body) use ($kernel, $token) {
    $request = \Illuminate\Http\Request::create("/api/employees-management/{$id}", 'PUT', $body + [
        'type' => 'API',
        'token' => $token,
    ]);
    $request->headers->set('Authorization', 'Bearer ' . $token);
    $request->headers->set('Accept', 'application/json');

    return $kernel->handle($request);
};

/** Two colleagues in the same tenant, and one outsider. */
$pair = $db->table('tbluser')->where('sub_institute_id', $tenant)
    ->whereNull('deleted_at')->where('status', 1)
    ->orderBy('id')->limit(2)->pluck('id')->all();

$outsider = $db->table('tbluser')->where('sub_institute_id', '!=', $tenant)
    ->whereNull('deleted_at')->value('id');

[$a, $b] = $pair;

printf("employee A #%d, employee B #%d, outsider #%d\n\n", $a, $b, $outsider);

DB::beginTransaction();

try {
    $before = $db->table('tbluser')->where('sub_institute_id', $tenant)
        ->whereNotNull('reporting_manager_id')->where('reporting_manager_id', '>', 0)->count();

    // ── 1. THE HAPPY PATH ───────────────────────────────────────────────────
    $response = $put($a, ['reporting_manager_id' => $b]);
    $stored = (int) $db->table('tbluser')->where('id', $a)->value('reporting_manager_id');

    printf("set A's manager to B      -> HTTP %d, stored reporting_manager_id=%d  %s\n",
        $response->getStatusCode(), $stored,
        $stored === (int) $b ? 'CORRECT — the column everything reads' : 'WRONG');

    // supervisor_opt must be untouched: it is not this field.
    $sup = $db->table('tbluser')->where('id', $a)->value('supervisor_opt');
    printf("   supervisor_opt is now  : %s  %s\n", var_export($sup, true),
        $sup !== (string) $b ? 'CORRECT — the legacy column was not written' : 'WRONG — still writing the type column');

    // ── 2. ANOTHER TENANT'S MANAGER ─────────────────────────────────────────
    $response = $put($a, ['reporting_manager_id' => $outsider]);
    $body = json_decode($response->getContent(), true);

    printf("\nmanager from another org  -> HTTP %d  %s\n", $response->getStatusCode(),
        $response->getStatusCode() === 422 ? 'CORRECT — refused' : 'WRONG — accepted a cross-tenant manager');
    printf("   %s\n", $body['message'] ?? '');

    // ── 3. SELF ─────────────────────────────────────────────────────────────
    $response = $put($a, ['reporting_manager_id' => $a]);
    $body = json_decode($response->getContent(), true);

    printf("\nreports to themselves     -> HTTP %d  %s\n", $response->getStatusCode(),
        $response->getStatusCode() === 422 ? 'CORRECT — refused' : 'WRONG — accepted');
    printf("   %s\n", $body['message'] ?? '');

    // ── 4. A LOOP ───────────────────────────────────────────────────────────
    // A already reports to B. Now try to make B report to A.
    $response = $put($b, ['reporting_manager_id' => $a]);
    $body = json_decode($response->getContent(), true);

    printf("\nA->B already set; now B->A -> HTTP %d  %s\n", $response->getStatusCode(),
        $response->getStatusCode() === 422 ? 'CORRECT — the loop is refused' : 'WRONG — a cycle was created');
    printf("   %s\n", $body['message'] ?? '');

    // ── 5. DOES THE GATE MOVE? ──────────────────────────────────────────────
    $after = $db->table('tbluser')->where('sub_institute_id', $tenant)
        ->whereNotNull('reporting_manager_id')->where('reporting_manager_id', '>', 0)->count();

    $total = $db->table('tbluser')->where('sub_institute_id', $tenant)->count();

    printf("\npeople with a manager: %d -> %d of %d\n", $before, $after, $total);

    app(\App\Services\Readiness\ReadinessGateRecomputer::class)->recompute($tenant);

    $gate = $db->table('tenant_readiness_gate')
        ->where('sub_institute_id', $tenant)->where('gate_key', 'reporting_coverage')
        ->first(['value', 'remedy']);

    printf("reporting_coverage gate now reads %s%%  %s\n",
        $gate->value,
        (float) $gate->value > 0 ? 'CORRECT — the gate can move at last' : 'still 0%');
    printf("   remedy: %s\n", $gate->remedy);
} finally {
    DB::rollBack();
    $adminUser->tokens()->where('name', 'reporting-line-evidence')->delete();
    echo "\n(rolled back — no reporting line kept, no gate row changed)\n";
}
