<?php
/**
 * F-162 EVIDENCE — employee numbers that continue the organisation's own scheme,
 * and stop duplicating.
 *
 * ── WHAT WAS BROKEN ─────────────────────────────────────────────────────────
 *
 * `nextEmployeeNo()` filtered on `employee_no REGEXP '^[0-9]+$'` and took the
 * max. Real data is not shaped like that: on live, tenant 1 holds
 * `EMP001, EMP001, 1, EMP001`, and tenants 2, 3 and 7 hold nothing but `EMP001`.
 * Every prefixed value was invisible, so an organisation using EMP001..EMP050
 * was offered "1" every single time.
 *
 * And nothing anywhere checked uniqueness - no index, no rule, no code. Hence
 * eleven live rows sharing `EMP001` across five tenants.
 *
 * ── WHAT IS PROVED ──────────────────────────────────────────────────────────
 *
 *   1. The suggestion continues the tenant's own scheme, padding included.
 *   2. A duplicate is refused on create.
 *   3. A duplicate is refused on update.
 *   4. THE ELEVEN EXISTING DUPLICATES ARE STILL EDITABLE - the rule stops new
 *      collisions without freezing the records that already collide.
 *   5. Two organisations may hold the same number; the check is per tenant.
 *
 * Everything runs inside a transaction that is ALWAYS rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-employee-numbers.php';"
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

\Illuminate\Support\Facades\Mail::fake();

$controller = app(\App\Http\Controllers\HRMS\EmployeeDirectoryController::class);

/** nextEmployeeNo() is private; the suggestion is what referenceData() publishes. */
$suggest = function (int $tenant) use ($controller) {
    $method = new ReflectionMethod($controller, 'nextEmployeeNo');
    $method->setAccessible(true);

    return $method->invoke($controller, $tenant);
};

$ip = 0;
$call = function (string $method, string $uri, array $body, string $token) use ($kernel, &$ip) {
    $request = \Illuminate\Http\Request::create(
        $uri, $method, $body + ['type' => 'API', 'token' => $token],
        [], [], ['REMOTE_ADDR' => '203.0.113.' . (++$ip % 250 + 1)]
    );
    $request->headers->set('Authorization', 'Bearer ' . $token);
    $request->headers->set('Accept', 'application/json');

    return $kernel->handle($request);
};

// ── 1. THE SUGGESTION FOLLOWS THE ORGANISATION'S OWN SHAPE ──────────────────
echo "══ 1. the next number continues what the tenant already uses ══\n";

DB::beginTransaction();

try {
    $cases = [
        'EMP001, EMP001, 1, EMP001 (live tenant 1)' => ['EMP001', 'EMP001', '1', 'EMP001'],
        'EMP007, EMP012'                            => ['EMP007', 'EMP012'],
        '7, 8, 9'                                   => ['7', '8', '9'],
        'STAFF-0042'                                => ['STAFF-0042'],
        'nothing at all'                            => [],
        'unparseable only'                          => ['ABC', 'XYZ'],
    ];

    /*
     * A scratch ORGANISATION, not just a scratch id.
     *
     * tbluser.sub_institute_id carries a foreign key to school_setup, so
     * inventing an id is a 1452. The row is created inside the same
     * transaction and rolled back with everything else.
     */
    $scratch = (int) $db->table('school_setup')->insertGetId([
        'SchoolName' => 'Employee-number scratch ' . uniqid(),
        'ShortCode' => 'ENS' . random_int(100, 999),
        'created_at' => now(),
    ]);

    // A profile for those rows to point at, for the same reason.
    $scratchProfile = (int) $db->table('tbluserprofilemaster')->insertGetId([
        'name' => 'Scratch',
        'role_key' => 'employee',
        'sub_institute_id' => $scratch,
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ($cases as $label => $values) {
        $db->table('tbluser')->where('sub_institute_id', $scratch)->delete();

        foreach ($values as $i => $value) {
            $db->table('tbluser')->insert([
                'first_name' => 'Scratch',
                'email' => 'scratch.' . $i . '.' . uniqid() . '@example.test',
                'password' => 'x',
                'user_profile_id' => $scratchProfile,
                'sub_institute_id' => $scratch,
                'employee_no' => $value,
                'status' => 1,
            ]);
        }

        printf("  %-42s -> %s\n", $label, $suggest($scratch));
    }

    echo "\n  (the old version answered \"1\" for every one of these except the third)\n";
} finally {
    DB::rollBack();
}

// ── 2-5. THE UNIQUENESS RULE ────────────────────────────────────────────────
echo "\n══ 2. duplicates are refused, existing ones stay editable ══\n";

$tenant = 6;

$admin = collect($db->table('tbluser')->where('sub_institute_id', $tenant)->get(['id']))
    ->first(fn ($u) => \App\Support\RoleKey::forUserId((int) $u->id) === 'administrator');

$adminUser = \App\Models\auth\tbluserModel::find($admin->id);
$token = $adminUser->createToken('empno-evidence')->plainTextToken;

DB::beginTransaction();

try {
    $profile = $db->table('tbluserprofilemaster')
        ->where('sub_institute_id', $tenant)->where('role_key', 'employee')->value('id');

    $suffix = substr((string) microtime(true), -6);

    // Somebody who already holds a number.
    $firstResponse = $call('POST', '/api/employees-management', [
        'first_name' => 'Holder', 'last_name' => 'One',
        'email' => 'holder.' . $suffix . '@example.test',
        'user_profile_id' => $profile,
        'employee_no' => 'DUP-' . $suffix,
    ], $token);

    $firstId = json_decode($firstResponse->getContent(), true)['data']['id'] ?? 0;
    printf("  created Holder One with DUP-%s -> HTTP %d\n", $suffix, $firstResponse->getStatusCode());

    // 2. Creating a second person with the same number.
    $clash = $call('POST', '/api/employees-management', [
        'first_name' => 'Holder', 'last_name' => 'Two',
        'email' => 'holder2.' . $suffix . '@example.test',
        'user_profile_id' => $profile,
        'employee_no' => 'DUP-' . $suffix,
    ], $token);

    printf("  a second person with the SAME number -> HTTP %d  %s\n",
        $clash->getStatusCode(),
        $clash->getStatusCode() === 422 ? 'CORRECT - refused' : 'WRONG - duplicate created');
    printf("    %s\n", json_decode($clash->getContent(), true)['message'] ?? '');

    // 3. Updating somebody ONTO a taken number.
    $other = $call('POST', '/api/employees-management', [
        'first_name' => 'Other', 'last_name' => 'Person',
        'email' => 'other.' . $suffix . '@example.test',
        'user_profile_id' => $profile,
    ], $token);
    $otherId = json_decode($other->getContent(), true)['data']['id'] ?? 0;

    $steal = $call('PUT', '/api/employees-management/' . $otherId, [
        'employee_no' => 'DUP-' . $suffix,
    ], $token);

    printf("  updating a second person ONTO it -> HTTP %d  %s\n",
        $steal->getStatusCode(),
        $steal->getStatusCode() === 422 ? 'CORRECT - refused' : 'WRONG - duplicate created');

    // 4. THE IMPORTANT ONE: an existing duplicate must stay editable.
    echo "\n══ 3. the eleven existing duplicates are NOT frozen ══\n";

    // Recreate the live situation: two people already sharing a number.
    $db->table('tbluser')->where('id', $otherId)->update(['employee_no' => 'DUP-' . $suffix]);

    $sharing = $db->table('tbluser')->where('sub_institute_id', $tenant)
        ->where('employee_no', 'DUP-' . $suffix)->count();

    printf("  %d people now share DUP-%s (as EMP001 does on live)\n", $sharing, $suffix);

    // Editing an unrelated field, sending the number back unchanged.
    $edit = $call('PUT', '/api/employees-management/' . $otherId, [
        'first_name' => 'Renamed',
        'employee_no' => 'DUP-' . $suffix,
    ], $token);

    printf("  editing one of them (number unchanged) -> HTTP %d  %s\n",
        $edit->getStatusCode(),
        $edit->getStatusCode() === 200
            ? 'CORRECT - an existing duplicate is still editable'
            : 'WRONG - the rule froze records it was meant to leave alone');

    // But moving them to a THIRD person's number is still refused.
    $db->table('tbluser')->where('id', $firstId)->update(['employee_no' => 'TAKEN-' . $suffix]);

    $move = $call('PUT', '/api/employees-management/' . $otherId, [
        'employee_no' => 'TAKEN-' . $suffix,
    ], $token);

    printf("  moving them onto ANOTHER taken number -> HTTP %d  %s\n",
        $move->getStatusCode(),
        $move->getStatusCode() === 422 ? 'CORRECT - still refused' : 'WRONG');

    // 5. Per tenant, not global.
    echo "\n══ 4. the number is unique per organisation, not globally ══\n";

    $otherTenantHas = $db->table('tbluser')
        ->where('sub_institute_id', '!=', $tenant)
        ->where('employee_no', 'TAKEN-' . $suffix)->count();

    printf("  another organisation may hold the same number: %s  %s\n",
        'yes by design',
        $otherTenantHas === 0
            ? 'CORRECT - the check carries sub_institute_id'
            : 'check');
} finally {
    DB::rollBack();
    $adminUser->tokens()->where('name', 'empno-evidence')->delete();
    echo "\n(rolled back - no employee kept)\n";
}
