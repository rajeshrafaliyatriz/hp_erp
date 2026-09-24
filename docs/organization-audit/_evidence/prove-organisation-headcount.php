<?php
/**
 * EVIDENCE — the organisation profile counts its people instead of being told.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE FIELD POISONED ITSELF ON EVERY SAVE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `org_details.employee_count` is a free-text VARCHAR holding a self-declared
 * band. Nothing on the screen could set it - the field was read-only in the edit
 * panel - and the save sent `String(org.totalEmployees)`, which for a null value
 * is the literal four-character string "null". The next load parsed that as NaN
 * and rendered a dash, so the field re-broke itself every time it was saved.
 *
 * Measured on live before the fix:
 *
 *   Scholar Clone       12 people, stored "1-10"
 *   Triz High School     5 people, stored "51-200"
 *   Healthcare         108 people, stored "201-500"
 *   seven others        NULL
 *
 * Wrong for every organisation that had a value, absent for the rest. Reported
 * as "total employees are not showing".
 *
 * ── SO THE ASSERTION IS THAT IT MATCHES REALITY ────────────────────────────
 *
 * Not "a number is returned" - the broken version returned one too, sometimes.
 * The count has to equal the staff records, for every organisation, using the
 * same definition the dashboards already use.
 *
 * Runs on DEV inside a transaction that is always rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/docs/organization-audit/_evidence/prove-organisation-headcount.php';"
 */

use Illuminate\Support\Facades\DB;

DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

\Illuminate\Support\Facades\Mail::fake();

$correct = 0;
$wrong = 0;

$ok = function (string $m) use (&$correct) { printf("  CORRECT  %s\n", $m); $correct++; };
$bad = function (string $m) use (&$wrong) { printf("  WRONG    %s\n", $m); $wrong++; };

DB::beginTransaction();

try {
    $call = function (string $token) use ($kernel) {
        $request = \Illuminate\Http\Request::create('/api/organization/profile', 'GET', [
            'type' => 'API', 'token' => $token,
        ]);
        $request->headers->set('Accept', 'application/json');

        return json_decode($kernel->handle($request)->getContent(), true);
    };

    // ── 1. THE COUNT MATCHES THE STAFF RECORDS, FOR EVERY ORGANISATION ──────
    echo "══ 1. every organisation's headcount equals its staff records ══\n";

    $checked = 0;
    $mismatched = [];

    foreach ($db->table('school_setup')->orderBy('id')->get(['id', 'SchoolName']) as $org) {
        $profileId = $db->table('tbluserprofilemaster')
            ->where('sub_institute_id', $org->id)->whereNotNull('role_key')->value('id');

        if (!$profileId) {
            continue; // No profile to authenticate with; not this test's subject.
        }

        $viewerId = $db->table('tbluser')->insertGetId([
            'user_profile_id' => $profileId,
            'sub_institute_id' => $org->id,
            'first_name' => 'Count', 'last_name' => 'Viewer' . $org->id,
            'email' => 'count.viewer' . $org->id . '@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('CountCheck123'),
            'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $token = \App\Models\auth\tbluserModel::find($viewerId)->createToken('Count laptop')->plainTextToken;

        // The definition four other controllers already use.
        $expected = $db->table('tbluser')
            ->where('sub_institute_id', $org->id)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->count();

        $reported = $call($token)['data']['identity']['employee_count'] ?? null;
        $checked++;

        if ((int) $reported !== (int) $expected) {
            $mismatched[] = sprintf('%s: reported %s, actual %d', $org->SchoolName, var_export($reported, true), $expected);
        }
    }

    $checked > 0
        ? $ok("checked $checked organisations")
        : $bad('no organisations were checked - the loop found nothing');

    empty($mismatched)
        ? $ok('and every one reports its real staff count')
        : $bad(count($mismatched) . ' disagree: ' . implode('; ', array_slice($mismatched, 0, 4)));

    // ── 2. IT FOLLOWS THE DATA, NOT A STORED VALUE ──────────────────────────
    //
    // The count has to be computed, not read. Hiring somebody must change it
    // without anybody editing the organisation profile.
    echo "\n══ 2. hiring somebody changes it, with no edit to the profile ══\n";

    $tenant = 6;
    $profileId = $db->table('tbluserprofilemaster')
        ->where('sub_institute_id', $tenant)->where('role_key', 'employee')->value('id');

    $viewerId = $db->table('tbluser')->insertGetId([
        'user_profile_id' => $profileId, 'sub_institute_id' => $tenant,
        'first_name' => 'Head', 'last_name' => 'Counter',
        'email' => 'head.counter@example.test',
        'password' => \Illuminate\Support\Facades\Hash::make('CountCheck123'),
        'status' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $token = \App\Models\auth\tbluserModel::find($viewerId)->createToken('Count laptop')->plainTextToken;

    $before = (int) ($call($token)['data']['identity']['employee_count'] ?? -1);

    $newHire = $db->table('tbluser')->insertGetId([
        'user_profile_id' => $profileId, 'sub_institute_id' => $tenant,
        'first_name' => 'New', 'last_name' => 'Hire',
        'email' => 'new.hire@example.test',
        'password' => \Illuminate\Support\Facades\Hash::make('CountCheck123'),
        'status' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $after = (int) ($call($token)['data']['identity']['employee_count'] ?? -1);

    $after === $before + 1
        ? $ok("hiring one person moved the count $before -> $after")
        : $bad("the count did not follow: $before -> $after");

    /*
     * And suspending somebody removes them. `status = 1` is the definition the
     * dashboards use, so a suspended account must not still be counted as staff.
     */
    $db->table('tbluser')->where('id', $newHire)->update(['status' => 0]);

    (int) ($call($token)['data']['identity']['employee_count'] ?? -1) === $before
        ? $ok('and suspending them removes them again')
        : $bad('a suspended account is still counted as an employee');

    // ── 3. THE POISONED COLUMN IS NO LONGER WRITTEN ─────────────────────────
    //
    // The save used to send the string "null". The screen now sends nothing for
    // this field at all, so the column cannot be re-poisoned even where it is
    // already wrong.
    echo "\n══ 3. saving no longer writes the string \"null\" ══\n";

    $adminProfile = $db->table('tbluserprofilemaster')
        ->where('sub_institute_id', $tenant)->where('role_key', 'administrator')->value('id');

    $adminId = $db->table('tbluser')->insertGetId([
        'user_profile_id' => $adminProfile, 'sub_institute_id' => $tenant,
        'first_name' => 'Org', 'last_name' => 'Admin',
        'email' => 'org.admin.count@example.test',
        'password' => \Illuminate\Support\Facades\Hash::make('CountCheck123'),
        'status' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $adminToken = \App\Models\auth\tbluserModel::find($adminId)->createToken('Admin laptop')->plainTextToken;

    // A save carrying no employee_count, which is what the screen now sends.
    $save = \Illuminate\Http\Request::create('/api/organization/profile', 'POST', [
        'type' => 'API', 'token' => $adminToken,
        'legal_name' => 'Evidence Legal Name',
        'organization_type' => 'LLP',
        'udyam_registration_no' => 'UDYAM-GJ-01-0001234',
    ]);
    $save->headers->set('Accept', 'application/json');
    $saveStatus = $kernel->handle($save)->getStatusCode();

    $saveStatus === 200
        ? $ok('the profile saves through the API endpoint')
        : $bad('the save returned HTTP ' . $saveStatus);

    $stored = $db->table('org_details')->where('sub_institute_id', $tenant)->first();

    ($stored->employee_count ?? '') !== 'null'
        ? $ok('and employee_count was not set to the string "null"')
        : $bad('the save wrote the literal string "null" again');

    // ── 4. THE NEW REGISTRATION FIELDS ROUND-TRIP ───────────────────────────
    echo "\n══ 4. the two new registration fields are stored and returned ══\n";

    ($stored->organization_type ?? null) === 'LLP'
        ? $ok('organization_type is stored - no longer a constant in a component')
        : $bad('organization_type was not saved: ' . var_export($stored->organization_type ?? null, true));

    ($stored->udyam_registration_no ?? null) === 'UDYAM-GJ-01-0001234'
        ? $ok('and the Udyam registration number is stored')
        : $bad('udyam_registration_no was not saved: ' . var_export($stored->udyam_registration_no ?? null, true));

    /*
     * A closed list, because this authorises nothing but it does label the
     * organisation on every screen. Free text is how institute_type ended up
     * holding the value "50" for one live tenant.
     */
    $bogus = \Illuminate\Http\Request::create('/api/organization/profile', 'POST', [
        'type' => 'API', 'token' => $adminToken,
        'legal_name' => 'Evidence Legal Name',
        'organization_type' => 'Not A Real Legal Form',
    ]);
    $bogus->headers->set('Accept', 'application/json');

    $kernel->handle($bogus)->getStatusCode() === 422
        ? $ok('an organisation type outside the list is refused with 422')
        : $bad('any string can be stored as the legal form');

    $profileBody = $call($adminToken);

    is_array($profileBody['data']['organisation_types'] ?? null)
        ? $ok('and the screen is served the list rather than hardcoding it')
        : $bad('organisation_types is not returned: the dropdown would be hardcoded again');
} finally {
    DB::rollBack();
    echo "\n(rolled back - no account or profile change kept)\n";
}

printf("\n%d CORRECT, %d WRONG\n", $correct, $wrong);
