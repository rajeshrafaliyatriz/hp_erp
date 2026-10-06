<?php
/**
 * F-166 EVIDENCE — an invite cannot be turned into an account takeover.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT AN ADVERSARIAL REVIEW FOUND, AND THIS PINS DOWN
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Making the invite REAL - it now returns a live set-password link when the
 * organisation has no SMTP, which is ten of eleven - turned an endpoint that
 * previously did nothing into a credential-minting one. And its only check was
 * "is the target in my tenant".
 *
 * `RequireProfile` cannot close this. It compares the CALLER's role to the
 * route's list and never looks at the target, and `profile:admin,hr` admits
 * hr_executive. So any HR user could POST the administrator's id, be handed a
 * working link, and take that account - password set, OTP cleared, every one of
 * its sessions deleted.
 *
 * The same shape existed on `user_profile_id`: it is in WRITABLE and was applied
 * with only an exists-and-same-tenant check, so an hr_executive could set their
 * own profile to the administrator profile.
 *
 * ── WHAT IS PROVED ──────────────────────────────────────────────────────────
 *
 *   1. An invite for somebody who has already signed in is REFUSED.
 *   2. An invite aimed UPWARDS (hr -> administrator) is refused.
 *   3. An invite for a genuinely stranded junior account still works, and still
 *      returns a link - the guards did not break the feature.
 *   4. Nobody can change their own role through the directory.
 *   5. Nobody can grant a role at or above their own.
 *   6. `last_login` is now WRITTEN on sign-in, so "has never signed in" can
 *      actually become false. It never could before: no code path wrote it.
 *
 * Everything runs inside a transaction that is ALWAYS rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-credential-guards.php';"
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

\Illuminate\Support\Facades\Mail::fake();

$tenant = 6;

/*
 * PROFILES SCOPED TO THIS TENANT, not the first row with a matching role_key.
 *
 * The first version of this script took profile id 1 ("Admin", tenant 1), and
 * `checkReferences` refused it with "User profile does not belong to this
 * organisation" - so the role guard was never reached and the assertion passed
 * on a pre-existing check instead of the new one. A guard that is never
 * exercised is not a guard that works.
 */
$profiles = $db->table('tbluserprofilemaster')
    ->where('sub_institute_id', $tenant)
    ->whereNotNull('role_key')
    ->get(['id', 'role_key', 'name']);
$profileFor = fn (string $key) => optional($profiles->firstWhere('role_key', $key))->id;

$adminProfile = $profileFor('administrator');
$hrProfile = $profileFor('hr_executive') ?? $profileFor('hr_manager');
$employeeProfile = $profileFor('employee');

printf("profiles: administrator=%s hr=%s employee=%s\n", $adminProfile, $hrProfile, $employeeProfile);

DB::beginTransaction();

try {
    $make = function (string $tag, int $profileId, ?string $lastLogin) use ($db, $tenant) {
        return $db->table('tbluser')->insertGetId([
            'user_profile_id' => $profileId,
            'sub_institute_id' => $tenant,
            'first_name' => 'Guard',
            'last_name' => $tag,
            'email' => 'guard.' . $tag . '@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('irrelevant'),
            'status' => 1,
            'last_login' => $lastLogin,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    /*
     * The admin fixture has NEVER signed in, deliberately.
     *
     * With a last_login set, invite() refuses at the "already signed in" check
     * and the RANK guard is never reached - the assertion would pass without
     * ever testing the thing it names. Leaving it null forces the refusal to
     * come from the privilege comparison.
     */
    $adminId = $make('admin', $adminProfile, null);
    $hrId = $make('hr', $hrProfile, now()->subDay()->format('Y-m-d H:i:s'));
    $strandedId = $make('stranded', $employeeProfile, null);
    $activeId = $make('active', $employeeProfile, now()->subHour()->format('Y-m-d H:i:s'));

    // RoleKey caches profile lookups per request; these are fresh rows so the
    // cache cannot be stale for them.
    $hrUser = \App\Models\auth\tbluserModel::find($hrId);
    $hrToken = $hrUser->createToken('guard-evidence')->plainTextToken;

    $call = function (string $method, string $uri, array $body = []) use ($kernel, $hrToken) {
        $request = \Illuminate\Http\Request::create($uri, $method, $body + ['type' => 'API', 'token' => $hrToken]);
        $request->headers->set('Authorization', 'Bearer ' . $hrToken);
        $request->headers->set('Accept', 'application/json');

        return $kernel->handle($request);
    };

    printf("acting as HR #%d (%s)\n\n", $hrId, \App\Support\RoleKey::forUserId($hrId));

    // ── 1 & 2. THE INVITE GUARDS ────────────────────────────────────────────
    echo "══ 1. an invite cannot re-credential an account somebody is using ══\n";

    $r = $call('POST', '/api/employees-management/' . $activeId . '/invite');
    $body = json_decode($r->getContent(), true);

    printf("  employee who has signed in     : HTTP %d  %s\n", $r->getStatusCode(),
        $r->getStatusCode() === 422 ? 'CORRECT - refused' : 'WRONG - link minted');
    printf("    and no link came back        : %s  %s\n",
        empty($body['data']['link']) ? 'yes' : 'NO - a link was returned',
        empty($body['data']['link']) ? 'CORRECT' : 'WRONG');

    echo "\n══ 2. an invite cannot be aimed upwards ══\n";

    $r = $call('POST', '/api/employees-management/' . $adminId . '/invite');
    $body = json_decode($r->getContent(), true);

    $why0 = json_decode($r->getContent(), true)['message'] ?? '';
    printf("  HR inviting the administrator  : HTTP %d\n    reason: %s\n    %s\n",
        $r->getStatusCode(), $why0,
        $r->getStatusCode() === 403
            ? 'CORRECT - refused BY THE RANK GUARD'
            : ($r->getStatusCode() === 200
                ? 'WRONG - HR can take the admin account'
                : 'WRONG - refused, but not by the rank guard; it never fired'));
    printf("    and no link came back        : %s  %s\n",
        empty($body['data']['link']) ? 'yes' : 'NO - a link was returned',
        empty($body['data']['link']) ? 'CORRECT' : 'WRONG');

    // The administrator's own token must survive an attempt.
    $adminUser = \App\Models\auth\tbluserModel::find($adminId);
    $adminUser->createToken('admin-session');
    $adminSessions = $db->table('personal_access_tokens')
        ->where('tokenable_id', $adminId)->count();

    printf("    admin sessions still alive   : %d  %s\n", $adminSessions,
        $adminSessions === 1 ? 'CORRECT - untouched' : 'WRONG');

    // ── 3. THE FEATURE STILL WORKS ──────────────────────────────────────────
    echo "\n══ 3. a genuinely stranded junior account is still invitable ══\n";

    $r = $call('POST', '/api/employees-management/' . $strandedId . '/invite');
    $body = json_decode($r->getContent(), true);

    printf("  never signed in, ranks below   : HTTP %d  %s\n", $r->getStatusCode(),
        $r->getStatusCode() === 200 ? 'CORRECT - the guards did not break the feature' : 'WRONG');
    printf("    delivery                     : %s\n", $body['data']['invite'] ?? '(none)');
    printf("    a usable link came back      : %s  %s\n",
        !empty($body['data']['link']) ? 'yes' : 'NO',
        !empty($body['data']['link']) || ($body['data']['invite'] ?? '') === 'email'
            ? 'CORRECT' : 'WRONG - nothing to pass on');

    // ── 4 & 5. THE ROLE-CHANGE GUARDS ───────────────────────────────────────
    echo "\n══ 4. nobody changes their own role, or grants one above their own ══\n";

    $before = $db->table('tbluser')->where('id', $hrId)->value('user_profile_id');

    $r = $call('PUT', '/api/employees-management/' . $hrId, [
        'first_name' => 'Guard',
        'email' => 'guard.hr@example.test',
        'user_profile_id' => $adminProfile,
        'sub_institute_id' => $tenant,
    ]);
    $after = $db->table('tbluser')->where('id', $hrId)->value('user_profile_id');

    $why = json_decode($r->getContent(), true)['message'] ?? '';
    printf("  HR promoting THEMSELVES        : HTTP %d, profile %s -> %s\n    reason: %s\n    %s\n",
        $r->getStatusCode(), $before, $after, $why,
        (int) $before === (int) $after && $r->getStatusCode() === 403
            ? 'CORRECT - refused BY THE ROLE GUARD'
            : ((int) $before === (int) $after
                ? 'WRONG - unchanged, but refused for another reason; the guard never fired'
                : 'WRONG - self-promotion'));

    $beforeEmp = $db->table('tbluser')->where('id', $strandedId)->value('user_profile_id');

    $r = $call('PUT', '/api/employees-management/' . $strandedId, [
        'first_name' => 'Guard',
        'email' => 'guard.stranded@example.test',
        'user_profile_id' => $adminProfile,
        'sub_institute_id' => $tenant,
    ]);
    $afterEmp = $db->table('tbluser')->where('id', $strandedId)->value('user_profile_id');

    $why2 = json_decode($r->getContent(), true)['message'] ?? '';
    printf("  HR minting a new administrator : HTTP %d, profile %s -> %s\n    reason: %s\n    %s\n",
        $r->getStatusCode(), $beforeEmp, $afterEmp, $why2,
        (int) $beforeEmp === (int) $afterEmp && $r->getStatusCode() === 403
            ? 'CORRECT - refused BY THE ROLE GUARD'
            : ((int) $beforeEmp === (int) $afterEmp
                ? 'WRONG - unchanged, but refused for another reason; the guard never fired'
                : 'WRONG - HR created an administrator'));

    // ── 6. last_login IS ACTUALLY WRITTEN ───────────────────────────────────
    echo "\n══ 5. signing in now records that it happened ══\n";

    $db->table('tbluser')->where('id', $strandedId)->update([
        'password' => \Illuminate\Support\Facades\Hash::make('KnownPass123'),
        'last_login' => null,
    ]);

    $loginRequest = \Illuminate\Http\Request::create('/login', 'GET', [
        'email' => 'guard.stranded@example.test',
        'password' => 'KnownPass123',
        'type' => 'API',
    ]);
    $loginRequest->headers->set('Accept', 'application/json');
    $loginResponse = $kernel->handle($loginRequest);

    $recorded = $db->table('tbluser')->where('id', $strandedId)->value('last_login');

    printf("  sign-in                        : HTTP %d\n", $loginResponse->getStatusCode());
    printf("  last_login after it            : %s  %s\n",
        $recorded ?: '(still null)',
        $recorded ? 'CORRECT - the column finally has a writer' : 'WRONG - still never written');

    // And they must therefore drop off the stranded list.
    $adminOfTenant = collect($db->table('tbluser')->where('sub_institute_id', $tenant)
        ->where('status', 1)->get(['id']))
        ->first(fn ($u) => \App\Support\RoleKey::forUserId((int) $u->id) === 'administrator');

    if ($adminOfTenant) {
        $adminTok = \App\Models\auth\tbluserModel::find($adminOfTenant->id)
            ->createToken('guard-list')->plainTextToken;

        $listRequest = \Illuminate\Http\Request::create(
            '/api/employees-management/pending-access', 'GET',
            ['type' => 'API', 'token' => $adminTok]
        );
        $listRequest->headers->set('Authorization', 'Bearer ' . $adminTok);
        $listRequest->headers->set('Accept', 'application/json');

        $list = json_decode($kernel->handle($listRequest)->getContent(), true);
        $stillListed = collect($list['data']['people'] ?? [])->contains('id', $strandedId);

        printf("  still on the stranded list     : %s  %s\n",
            $stillListed ? 'YES' : 'no',
            !$stillListed
                ? 'CORRECT - the list can now empty as people get in'
                : 'WRONG - it would never clear');
    }
} finally {
    DB::rollBack();
    \App\Models\auth\tbluserModel::whereIn('id', [$adminId ?? 0, $hrId ?? 0, $strandedId ?? 0, $activeId ?? 0])
        ->get()->each(fn ($u) => $u->tokens()->delete());
    if (isset($adminOfTenant) && $adminOfTenant) {
        \App\Models\auth\tbluserModel::find($adminOfTenant->id)?->tokens()->where('name', 'guard-list')->delete();
    }
    echo "\n(rolled back - no user, role change, token or login kept)\n";
}
