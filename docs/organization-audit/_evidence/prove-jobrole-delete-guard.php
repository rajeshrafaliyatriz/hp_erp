<?php
/**
 * EVIDENCE — a job role somebody holds cannot be deleted out from under them.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE DELETE EXISTED AND CHECKED NOTHING
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `DELETE /api/competency/library/jobroles/{id}` has always been tenant-scoped,
 * soft-deleting and audit-logged. What it never had was a reference check -
 * unlike department delete, which refuses with 409 when LMS tables point at it.
 *
 * Measured on live, tenant 6: 272 job roles, 21 of them held by a real person
 * through `tbluser.jobtitle_id`. Deleting one of those removed the row and left
 * those people with a job title resolving to nothing, on their own profile and
 * everywhere else it is read. Nobody was told.
 *
 * ── THE LINK IS `s_user_jobrole`, NOT `s_jobrole` ──────────────────────────
 *
 * Both tables have `id` and `jobrole`, and their ids overlap. Checking the wrong
 * one returns a confident wrong answer rather than an error - which is why the
 * holders query is asserted here, not assumed.
 *
 * Runs on DEV inside a transaction that is always rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/docs/organization-audit/_evidence/prove-jobrole-delete-guard.php';"
 */

use Illuminate\Support\Facades\DB;

DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

\Illuminate\Support\Facades\Mail::fake();

$tenant = 6;
$correct = 0;
$wrong = 0;

$ok = function (string $m) use (&$correct) { printf("  CORRECT  %s\n", $m); $correct++; };
$bad = function (string $m) use (&$wrong) { printf("  WRONG    %s\n", $m); $wrong++; };

DB::beginTransaction();

try {
    $call = function (string $method, string $uri, string $token, array $body = []) use ($kernel) {
        $request = \Illuminate\Http\Request::create(
            $uri, $method, array_merge(['type' => 'API', 'token' => $token], $body)
        );
        $request->headers->set('Accept', 'application/json');
        $response = $kernel->handle($request);

        return ['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)];
    };

    $adminProfile = $db->table('tbluserprofilemaster')
        ->where('sub_institute_id', $tenant)->where('role_key', 'administrator')->value('id');

    $adminId = $db->table('tbluser')->insertGetId([
        'user_profile_id' => $adminProfile,
        'sub_institute_id' => $tenant,
        'first_name' => 'Role', 'last_name' => 'Admin',
        'email' => 'role.admin@example.test',
        'password' => \Illuminate\Support\Facades\Hash::make('RoleCheck123'),
        'status' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $token = \App\Models\auth\tbluserModel::find($adminId)->createToken('Role laptop')->plainTextToken;

    $departmentId = (int) $db->table('hrms_departments')
        ->where('sub_institute_id', $tenant)->whereNull('deleted_at')->value('id');

    $makeRole = function (string $name) use ($db, $tenant, $departmentId) {
        return $db->table('s_user_jobrole')->insertGetId([
            'jobrole' => $name,
            'department_id' => $departmentId,
            'sub_institute_id' => $tenant,
            'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $unheld = $makeRole('Evidence Unheld Role');
    $held = $makeRole('Evidence Held Role');

    // Somebody holding the second role.
    $employeeProfile = $db->table('tbluserprofilemaster')
        ->where('sub_institute_id', $tenant)->where('role_key', 'employee')->value('id');

    $db->table('tbluser')->insertGetId([
        'user_profile_id' => $employeeProfile,
        'sub_institute_id' => $tenant,
        'first_name' => 'Holder', 'last_name' => 'One',
        'email' => 'holder.one@example.test',
        'jobtitle_id' => $held,
        'password' => \Illuminate\Support\Facades\Hash::make('RoleCheck123'),
        'status' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // ── 1. AN UNHELD ROLE DELETES ───────────────────────────────────────────
    //
    // The control. Every assertion below is "and this one does not", which proves
    // nothing unless deletion works to begin with - 251 of tenant 6's 272 roles
    // are in this state, so the common case must stay easy.
    echo "══ 1. a job role nobody holds still deletes ══\n";

    $deleted = $call('DELETE', '/api/competency/library/jobroles/' . $unheld, $token);

    $deleted['status'] === 200
        ? $ok('an unheld role deletes with 200')
        : $bad('an unheld role returned HTTP ' . $deleted['status'] . ': ' . json_encode($deleted['body']));

    $db->table('s_user_jobrole')->where('id', $unheld)->whereNotNull('deleted_at')->exists()
        ? $ok('and is soft-deleted, so it can be recovered')
        : $bad('the role was not soft-deleted');

    // ── 2. A HELD ROLE IS REFUSED, AND SAYS WHO ─────────────────────────────
    echo "\n══ 2. a job role somebody holds is refused ══\n";

    $refused = $call('DELETE', '/api/competency/library/jobroles/' . $held, $token);

    $refused['status'] === 409
        ? $ok('a held role is refused with 409')
        : $bad('expected 409, got HTTP ' . $refused['status'] . ': ' . json_encode($refused['body']));

    /*
     * The row must be untouched. A 409 that had already written `deleted_at`
     * would be the worst of both: an error message and a deleted role.
     */
    $db->table('s_user_jobrole')->where('id', $held)->whereNull('deleted_at')->exists()
        ? $ok('and the role is still there, not half-deleted')
        : $bad('the role was deleted despite the refusal');

    str_contains(strtolower((string) ($refused['body']['message'] ?? '')), 'hold')
        ? $ok('the message says somebody holds it')
        : $bad('the message does not explain why: ' . var_export($refused['body']['message'] ?? null, true));

    // Naming them is the difference between a refusal somebody can act on and a
    // wall. "1 person holds this" without a name means opening the directory and
    // searching by hand.
    in_array('Holder One', $refused['body']['data']['names'] ?? [], true)
        ? $ok('and names the person, so the admin knows who to move')
        : $bad('the holder is not named: ' . json_encode($refused['body']['data'] ?? null));

    // ── 3. THE IMPACT ENDPOINT AGREES WITH THE REFUSAL ──────────────────────
    //
    // The drawer asks this BEFORE offering the delete. If the two disagreed, the
    // dialog would promise something the write then refuses.
    echo "\n══ 3. the preview and the write tell the same story ══\n";

    $impact = $call('GET', '/api/competency/library/jobroles/' . $held . '/impact', $token);

    ($impact['body']['data']['can_delete'] ?? true) === false
        ? $ok('impact says the held role cannot be deleted')
        : $bad('impact disagrees with the refusal: ' . json_encode($impact['body']['data'] ?? null));

    (int) ($impact['body']['data']['holders'] ?? 0) === 1
        ? $ok('and counts exactly one holder')
        : $bad('holder count is ' . var_export($impact['body']['data']['holders'] ?? null, true));

    // ── 4. AND IT IS ALL TENANT-SCOPED ──────────────────────────────────────
    //
    // `tbluser` is a global table. An unscoped holder count would leak the size
    // of another organisation's team through a "cannot delete" message.
    echo "\n══ 4. another organisation's role is not visible at all ══\n";

    $otherTenant = (int) $db->table('school_setup')->where('id', '!=', $tenant)->value('id');
    $otherRole = $db->table('s_user_jobrole')->insertGetId([
        'jobrole' => 'Evidence Other Tenant Role',
        'sub_institute_id' => $otherTenant,
        'status' => 'Active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $cross = $call('GET', '/api/competency/library/jobroles/' . $otherRole . '/impact', $token);

    $cross['status'] === 404
        ? $ok("a role belonging to tenant $otherTenant answers 404")
        : $bad('impact leaked across tenants: HTTP ' . $cross['status'] . ' ' . json_encode($cross['body']));

    $crossDelete = $call('DELETE', '/api/competency/library/jobroles/' . $otherRole, $token);

    $crossDelete['status'] !== 200
        ? $ok('and deleting it is refused too')
        : $bad('one organisation deleted another organisation\'s job role');

    $db->table('s_user_jobrole')->where('id', $otherRole)->whereNull('deleted_at')->exists()
        ? $ok('with the other tenant\'s row untouched')
        : $bad('the other tenant\'s role was deleted');
} finally {
    DB::rollBack();
    echo "\n(rolled back - no account, role or holder kept)\n";
}

printf("\n%d CORRECT, %d WRONG\n", $correct, $wrong);
