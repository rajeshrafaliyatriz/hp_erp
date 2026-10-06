<?php
/**
 * F-136 EVIDENCE — the disciplinary write path is tenant-scoped.
 *
 * Unlike the compliance READ leak, this one cannot be demonstrated safely
 * against real rows: proving it would mean writing into, or deleting, another
 * organisation's disciplinary record. So the whole run happens inside ONE
 * transaction that is ALWAYS rolled back, and it works on a row this script
 * creates itself.
 *
 * Three things are checked, all as a tenant-1 caller against a tenant-3 row:
 *
 *   1. store()   — a record is filed into the CALLER'S tenant, whatever
 *                  sub_institute_id the request asked for.
 *   2. update()  — another tenant's record is not modified.
 *   3. destroy() — another tenant's record is not soft-deleted.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-disciplinary-tenant.php';"
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');

$attackerTenant = 1;
$victimTenant = 3;

$attacker = $db->table('tbluser')
    ->where('sub_institute_id', $attackerTenant)
    ->whereNotNull('user_profile_id')
    ->first(['id', 'first_name']);

printf("Caller: user #%d (%s) of tenant %d\n", $attacker->id, $attacker->first_name, $attackerTenant);

$user = \App\Models\auth\tbluserModel::find($attacker->id);
$token = $user->createToken('disc-evidence')->plainTextToken;

$make = function (array $params) use ($token) {
    $request = \Illuminate\Http\Request::create('/x', 'POST', $params + [
        'type' => 'API',
        'token' => $token,
    ]);
    $request->headers->set('Authorization', 'Bearer ' . $token);

    return $request;
};

$controller = app(\App\Http\Controllers\settings\discliplinaryManagementController::class);

DB::beginTransaction();

try {
    // ── 1. A record the victim owns, created directly so the test is honest ──
    $victimRowId = $db->table('discliplinary_management')->insertGetId([
        'sub_institute_id' => $victimTenant,
        'description' => 'VICTIM ROW - must not be touched',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    printf("\nVictim row #%d created in tenant %d\n", $victimRowId, $victimTenant);

    // ── 2. store(): ask to file into the victim's tenant ────────────────────
    $controller->store($make([
        'sub_institute_id' => $victimTenant,      // the caller's request
        'description' => 'Filed by a tenant-1 caller',
        'reported_by' => 9999,                    // a caller-supplied "author"
    ]));

    $created = $db->table('discliplinary_management')
        ->where('description', 'Filed by a tenant-1 caller')
        ->first(['sub_institute_id', 'created_by']);

    if ($created) {
        printf("\nstore()   asked for tenant %d -> written to tenant %s  %s\n",
            $victimTenant,
            $created->sub_institute_id,
            (int) $created->sub_institute_id === $attackerTenant ? 'CORRECT' : 'LEAK');
        printf("          created_by = %s (request said 9999)  %s\n",
            var_export($created->created_by, true),
            (int) $created->created_by !== 9999 ? 'CORRECT — the actor, not the claim' : 'CALLER-CONTROLLED');
    } else {
        echo "\nstore()   wrote nothing\n";
    }

    // ── 3. update(): try to rewrite the victim's row ────────────────────────
    $controller->update($make([
        'sub_institute_id' => $victimTenant,
        'description' => 'REWRITTEN BY ANOTHER TENANT',
        'reported_by' => 9999,
    ]), $victimRowId);

    $after = $db->table('discliplinary_management')->where('id', $victimRowId)
        ->first(['description', 'deleted_at']);

    printf("\nupdate()  victim row title is now: \"%s\"  %s\n",
        $after->description,
        $after->description === 'VICTIM ROW - must not be touched' ? 'CORRECT — untouched' : 'LEAK');

    // ── 4. destroy(): try to delete the victim's row ────────────────────────
    $controller->destroy($make(['sub_institute_id' => $victimTenant]), $victimRowId);

    $afterDelete = $db->table('discliplinary_management')->where('id', $victimRowId)
        ->first(['deleted_at']);

    printf("destroy() victim row deleted_at = %s  %s\n",
        var_export($afterDelete->deleted_at, true),
        $afterDelete->deleted_at === null ? 'CORRECT — still there' : 'LEAK');
} finally {
    DB::rollBack();
    $user->tokens()->where('name', 'disc-evidence')->delete();
    echo "\n(rolled back — no row created, changed or deleted)\n";
}
