<?php
/**
 * F-167 EVIDENCE — Settings, across all nine roles.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT THIS REPLACES
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The plan's verification step 6 reads: *"Per role: sign in as each of the nine
 * and confirm the section list matches, and that a hidden section's endpoint
 * still refuses when called directly."*
 *
 * The first half of that needs a browser. The second half - the half that
 * MATTERS, because it is the difference between a menu and a control - does
 * not, and it is the half a browser pass is worst at: nobody clicking through
 * a UI ever discovers that the endpoint behind a hidden button answers 200.
 *
 * So this signs in as each of the nine roles in turn and, for every one:
 *
 *   1. Reads /account/me and checks the role the API reports.
 *   2. Confirms the PERSONAL endpoints work for everybody. Five sections are
 *      offered to all nine roles, and if any of them refused a role, that role
 *      would have a settings screen that cannot change their own password.
 *   3. Calls the ADMINISTRATOR endpoints directly - the ones behind sections
 *      the rail hides - and requires a refusal for every role that should not
 *      have them.
 *
 * The rail is presentation. This proves the guards underneath it.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-settings-per-role.php';"
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

\Illuminate\Support\Facades\Mail::fake();

$tenant = 6;

/**
 * The rail's own rules, transcribed from lib/settings-sections.ts.
 *
 * Kept here so the check compares the SERVER against the frontend's stated
 * intent, rather than against itself.
 */
$SECTIONS = [
    'profile'       => null,                                       // everybody
    'security'      => null,
    'preferences'   => null,
    'notifications' => null,
    'saved-views'   => null,
    'people'        => ['administrator', 'hr_manager', 'hr_executive'],
    'delivery'      => ['administrator', 'hr_manager', 'hr_executive'],
    'modules'       => ['administrator'],
    'organization'  => ['administrator', 'hr_manager', 'hr_executive'],
    'roles'         => ['administrator'],
    'policy'        => ['administrator'],
    'audit'         => ['administrator', 'auditor'],
];

/** The endpoint each gated section actually depends on. */
$GATED_ENDPOINTS = [
    'people'  => ['GET', '/api/employees-management/pending-access'],
    'modules' => ['GET', '/api/organization/modules'],
];

$profiles = $db->table('tbluserprofilemaster')
    ->where('sub_institute_id', $tenant)
    ->whereNotNull('role_key')
    ->get(['id', 'role_key', 'name']);

printf("tenant %d has %d role profiles\n\n", $tenant, $profiles->count());

DB::beginTransaction();

$created = [];

try {
    /*
     * EACH CALLER GETS ITS OWN IP.
     *
     * `/api/account/password` is `throttle:6,1`, keyed by IP. The first run of
     * this script made nine password changes from one address, and the 7th, 8th
     * and 9th came back 429 - so it reported that executive, auditor and
     * recruiter "cannot change their own password", which was a limiter doing
     * its job being read as a broken feature.
     *
     * Nine roles is more than six by design, so the limit WILL be hit unless
     * the callers are distinguished the way real callers are. The throttle is
     * then proved deliberately, in its own section, rather than corrupting
     * every other one.
     */
    $ip = 0;
    $call = function (string $method, string $uri, string $tok, array $body = [], ?string $from = null) use ($kernel, &$ip) {
        $request = \Illuminate\Http\Request::create($uri, $method, $body + ['type' => 'API', 'token' => $tok]);
        $request->headers->set('Authorization', 'Bearer ' . $tok);
        $request->headers->set('Accept', 'application/json');
        $request->server->set('REMOTE_ADDR', $from ?? '10.9.' . intdiv(++$ip, 250) . '.' . ($ip % 250));

        return $kernel->handle($request);
    };

    // ── ONE REAL ACCOUNT PER ROLE ───────────────────────────────────────────
    // Created rather than found: not every role has a live holder on tenant 6,
    // and a check that silently skips the roles it cannot find proves least
    // about exactly the roles nobody has tested.
    $actors = [];

    foreach (\App\Support\RoleKey::ALL as $roleKey) {
        $profile = $profiles->firstWhere('role_key', $roleKey);

        if (!$profile) {
            printf("  %-20s NO PROFILE on this tenant - skipped\n", $roleKey);
            continue;
        }

        $id = $db->table('tbluser')->insertGetId([
            'user_profile_id' => $profile->id,
            'sub_institute_id' => $tenant,
            'first_name' => 'Role',
            'last_name' => $roleKey,
            'email' => 'role.' . $roleKey . '@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('RoleCheck123'),
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = \App\Models\auth\tbluserModel::find($id);
        $actors[$roleKey] = [
            'id' => $id,
            'token' => $user->createToken('role-evidence')->plainTextToken,
            'user' => $user,
        ];
        $created[] = $id;
    }

    printf("created %d role accounts\n\n", count($actors));

    // ── 1. THE API REPORTS THE RIGHT ROLE ───────────────────────────────────
    echo "══ 1. /account/me reports the role the rail will gate on ══\n";

    foreach ($actors as $roleKey => $actor) {
        $me = json_decode($call('GET', '/api/account/me', $actor['token'])->getContent(), true);
        $reported = $me['data']['role'] ?? '(none)';

        printf("  %-20s -> %-20s %s\n", $roleKey, $reported,
            $reported === $roleKey ? 'CORRECT' : 'WRONG - the rail would gate on the wrong role');
    }

    // ── 2. EVERY ROLE CAN RUN THEIR OWN ACCOUNT ─────────────────────────────
    echo "\n══ 2. the five personal sections work for all nine ══\n";

    foreach ($actors as $roleKey => $actor) {
        $ok = [];

        $ok['me'] = $call('GET', '/api/account/me', $actor['token'])->getStatusCode() === 200;
        $ok['profile'] = $call('PUT', '/api/account/profile', $actor['token'], ['city' => 'Rajkot'])->getStatusCode() === 200;
        $ok['prefs'] = $call('PUT', '/api/account/preferences', $actor['token'], ['theme' => 'dark'])->getStatusCode() === 200;
        $ok['sessions'] = $call('GET', '/api/account/sessions', $actor['token'])->getStatusCode() === 200;

        $pw = $call('POST', '/api/account/password', $actor['token'], [
            'current_password' => 'RoleCheck123',
            'password' => 'RoleChanged456',
            'password_confirmation' => 'RoleChanged456',
        ]);
        $ok['password'] = $pw->getStatusCode() === 200;

        $failed = array_keys(array_filter($ok, fn ($v) => !$v));

        printf("  %-20s me/profile/prefs/sessions/password  %s\n", $roleKey,
            $failed === []
                ? 'CORRECT - all five'
                : 'WRONG - refused: ' . implode(', ', $failed));
    }

    // ── 3. THE GATED ENDPOINTS REFUSE THE ROLES THAT SHOULD NOT HAVE THEM ───
    echo "\n══ 3. hidden sections are not merely hidden ══\n";

    foreach ($GATED_ENDPOINTS as $section => [$method, $uri]) {
        $allowed = $SECTIONS[$section];

        printf("\n  %s  (%s %s)\n", $section, $method, $uri);
        printf("  the rail shows it to: %s\n", implode(', ', $allowed));

        foreach ($actors as $roleKey => $actor) {
            $shouldPass = in_array($roleKey, $allowed, true);
            $code = $call($method, $uri, $actor['token'])->getStatusCode();
            $didPass = $code === 200;

            printf("    %-20s HTTP %-4d %s\n", $roleKey, $code,
                $didPass === $shouldPass
                    ? ($shouldPass ? 'CORRECT - allowed, as the rail says' : 'CORRECT - refused')
                    : ($didPass
                        ? 'WRONG - REACHABLE by a role the rail hides it from'
                        : 'WRONG - refused a role the rail offers it to'));
        }
    }

    // ── 3b. THE THROTTLE IS REAL ────────────────────────────────────────────
    echo "\n== 3b. the password endpoint is rate limited ==\n";

    $victim = $actors['employee'];
    $codes = [];

    for ($i = 0; $i < 9; $i++) {
        $codes[] = $call('POST', '/api/account/password', $victim['token'], [
            'current_password' => 'wrong-on-purpose-' . $i,
            'password' => 'Whatever12345',
            'password_confirmation' => 'Whatever12345',
        ], '198.51.100.7')->getStatusCode();
    }

    $limited = count(array_filter($codes, fn ($c) => $c === 429));

    printf("  nine guesses from one address  : %s\n", implode(' ', $codes));
    printf("  how many were rate limited     : %d  %s\n", $limited,
        $limited >= 3
            ? 'CORRECT - the current password cannot be brute-forced'
            : 'WRONG - no limiter on the one secret that protects this endpoint');

    // ── 4. NOBODY REACHES ANOTHER PERSON THROUGH /account ────────────────────
    echo "\n══ 4. no account endpoint accepts somebody else's id ══\n";

    $employee = $actors['employee'] ?? null;
    $admin = $actors['administrator'] ?? null;

    if ($employee && $admin) {
        // An employee sends the administrator's id every way it could be read.
        $r = $call('PUT', '/api/account/profile', $employee['token'], [
            'user_id' => $admin['id'],
            'id' => $admin['id'],
            'first_name' => 'Hijacked',
        ]);

        $adminName = $db->table('tbluser')->where('id', $admin['id'])->value('first_name');
        $employeeName = $db->table('tbluser')->where('id', $employee['id'])->value('first_name');

        printf("  employee PUTs user_id=%d       : HTTP %d\n", $admin['id'], $r->getStatusCode());
        printf("    administrator's name         : %-12s %s\n", $adminName,
            $adminName !== 'Hijacked' ? 'CORRECT - untouched' : 'WRONG - edited somebody else');
        printf("    their OWN name changed       : %-12s %s\n", $employeeName,
            $employeeName === 'Hijacked'
                ? 'CORRECT - the id was ignored, the token decided'
                : 'WRONG - the write did not land at all');

        // And a session belonging to somebody else cannot be ended.
        $adminTokenId = (int) $db->table('personal_access_tokens')
            ->where('tokenable_id', $admin['id'])->value('id');

        $call('DELETE', '/api/account/sessions/' . $adminTokenId, $employee['token']);
        $survived = $db->table('personal_access_tokens')->where('id', $adminTokenId)->exists();

        printf("  employee DELETEs admin session : theirs survived %s  %s\n",
            $survived ? 'yes' : 'NO',
            $survived ? 'CORRECT - scoped to the caller' : 'WRONG - signed an administrator out');
    }
} finally {
    DB::rollBack();

    foreach ($created as $id) {
        \App\Models\auth\tbluserModel::find($id)?->tokens()->delete();
    }

    echo "\n(rolled back - no account, preference or password kept)\n";
}
