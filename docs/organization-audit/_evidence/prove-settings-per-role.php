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

/*
 * The rail's own rules, READ OUT OF lib/settings-sections.ts.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THIS WAS A TRANSCRIPTION, AND IT LIED
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * It used to be a hand-copied table, with a comment saying it was kept here so
 * the check compared the server against the frontend's stated intent. It did no
 * such thing: the frontend changed `organization` from ADMIN to ADMIN_ONLY - with
 * a comment in that very file explaining that HR got "a rail entry whose only
 * content was a refusal" - and this copy stayed as it was.
 *
 * So this script reported four WRONG results against a product that was already
 * correct: hr_manager and hr_executive "refused a role the rail offers it to" on
 * both the read and the write, when the rail had stopped offering it.
 *
 * A check that carries its own copy of the thing it is checking tests the copy.
 * The same mistake, in the same session, produced a phantom `PUT /account/password`
 * failure in check-settings-wiring.py and a phantom required-field failure in
 * check-settings-guards.sh. Three times is a pattern, so this reads the file.
 *
 * ── THE PARSE, AND WHY IT IS ALLOWED TO BE SIMPLE ───────────────────────────
 *
 * The section table is a flat literal - `id: 'x'` ... `roles: Y` per entry, with
 * `Y` one of `null`, `ADMIN`, `ADMIN_ONLY`, or a spread of one of those plus
 * extra keys. A regex is enough for that shape, and `assertParsed()` below makes
 * a parse that stops matching fail loudly instead of quietly approving nothing.
 */
$sectionsFile = 'C:/Users/MILAN/Downloads/g2gv0/lib/settings-sections.ts';

if (!is_readable($sectionsFile)) {
    echo "WRONG - cannot read $sectionsFile, so the rail's rules are unknown\n";
    return;
}

$ts = file_get_contents($sectionsFile);

// The two shared constants the entries refer to.
$named = [];
if (preg_match("/const ADMIN = \[([^\]]*)\]/", $ts, $m)) {
    $named['ADMIN'] = array_map(
        fn ($x) => trim($x, " '\"\n\r\t"),
        array_filter(explode(',', $m[1]), fn ($x) => trim($x) !== '')
    );
}
if (preg_match("/const ADMIN_ONLY = \[([^\]]*)\]/", $ts, $m)) {
    $named['ADMIN_ONLY'] = array_map(
        fn ($x) => trim($x, " '\"\n\r\t"),
        array_filter(explode(',', $m[1]), fn ($x) => trim($x) !== '')
    );
}

$SECTIONS = [];

// Each entry: `id: 'name',` ... then the first `roles: ...,` that follows it.
preg_match_all("/id:\s*'([a-z-]+)'/", $ts, $ids, PREG_OFFSET_CAPTURE);

foreach ($ids[1] as $idMatch) {
    [$sectionId, $offset] = $idMatch;
    $rest = substr($ts, $offset);

    /*
     * TO THE END OF THE LINE, NOT TO THE FIRST COMMA.
     *
     * This was `([^,\n]+)`, which stops at a comma - so `[...ADMIN_ONLY,
     * 'auditor']` was captured as `[...ADMIN_ONLY` and the auditor vanished.
     * The audit section then read as administrator-only, and this script would
     * have reported the auditor as "refused a role the rail offers it to" on the
     * one section that exists for them.
     */
    if (!preg_match("/roles:\s*([^\n]+)/", $rest, $rm)) {
        continue;
    }

    $expr = rtrim(trim($rm[1]), ',');

    if ($expr === 'null') {
        $SECTIONS[$sectionId] = null;                       // everybody
        continue;
    }

    $roles = [];

    // `[...ADMIN_ONLY, 'auditor']` and plain `ADMIN` / `ADMIN_ONLY` alike.
    foreach (['ADMIN_ONLY', 'ADMIN'] as $constant) {
        if (str_contains($expr, $constant)) {
            $roles = array_merge($roles, $named[$constant] ?? []);
            break;                                          // ADMIN_ONLY first
        }
    }

    foreach (preg_split("/[,\[\]]/", $expr) as $piece) {
        $piece = trim($piece, " '\".\n\r\t");
        if ($piece !== '' && !str_contains($piece, 'ADMIN')) {
            $roles[] = $piece;
        }
    }

    $SECTIONS[$sectionId] = array_values(array_unique($roles));
}

/*
 * A PARSE THAT MATCHES NOTHING MUST FAIL, NOT PASS.
 *
 * Twelve sections exist. If the literal is reshaped and the regex stops
 * matching, an empty or short table would make every "refused" result look
 * correct - the vacuous pass this directory exists to prevent.
 */
if (count($SECTIONS) < 12) {
    printf("WRONG - parsed only %d sections from settings-sections.ts, expected 12\n", count($SECTIONS));
    printf("        the parser has broken; nothing below would mean anything\n");
    return;
}

if (!isset($SECTIONS['organization'], $SECTIONS['audit']) || $SECTIONS['profile'] !== null) {
    echo "WRONG - the parsed table does not look right; check the parser\n";
    return;
}

/*
 * `audit` IS THE CANARY, and it earned the job.
 *
 * It is the only entry whose roles are a spread plus an extra key
 * (`[...ADMIN_ONLY, 'auditor']`), so it breaks first when the capture is too
 * narrow - which is exactly what happened: the regex stopped at the first comma
 * and silently dropped the auditor. Asserted explicitly, because a parser that
 * loses one role out of nine still parses twelve sections and sails past every
 * count-based guard above.
 */
if (!in_array('auditor', $SECTIONS['audit'] ?? [], true)) {
    echo "WRONG - the parse lost 'auditor' from the audit section; the capture is too narrow\n";
    return;
}

printf("read the rail's rules from settings-sections.ts: %d sections\n", count($SECTIONS));
foreach ($SECTIONS as $id => $allowed) {
    printf("  %-14s %s\n", $id, $allowed === null ? 'everybody' : implode(', ', $allowed));
}
echo "\n";

/*
 * The endpoint each gated section actually depends on.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THIS LIST HELD TWO OF THE SEVEN GATED SECTIONS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `people` and `modules` were checked. `delivery`, `organization`, `roles`,
 * `policy` and `audit` were not - five sections times nine roles, forty-five
 * role/endpoint pairs nothing had ever exercised. The header of this very file
 * says the endpoint behind a hidden button is what a browser pass is worst at
 * finding, and then it checked two of them.
 */
$GATED_ENDPOINTS = [
    'people'       => ['GET', '/api/employees-management/pending-access', []],
    'modules'      => ['GET', '/api/organization/modules', []],
    'delivery'     => ['GET', '/api/organization/delivery', []],
    'organization' => ['GET', '/api/organization/settings', []],
    'roles'        => ['GET', '/api/organization/roles', []],
    'audit'        => ['GET', '/api/organization/audit', []],
];

/*
 * The WRITES, which are where a missing role check actually costs something.
 *
 * `policy` is the one that matters most: `PasswordController::rule()` reads
 * `security.password_min_length` live on every password path in the product, so
 * whoever can write it sets the password rules for the whole organisation.
 */
$GATED_WRITES = [
    'organization' => ['PUT', '/api/organization/settings', ['currency' => 'USD']],
    'policy'       => ['PUT', '/api/organization/settings', ['password_require_symbol' => '0']],
    'delivery'     => ['PUT', '/api/organization/delivery', [
        'from_address' => 'probe@example.test',
        'server' => 'smtp.example.test',
        'port' => 465,
        'password' => 'probe-secret',
    ]],
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

    foreach ($GATED_ENDPOINTS as $section => [$method, $uri, $body]) {
        $allowed = $SECTIONS[$section];

        printf("\n  %s  (%s %s)\n", $section, $method, $uri);
        printf("  the rail shows it to: %s\n", implode(', ', $allowed));

        foreach ($actors as $roleKey => $actor) {
            $shouldPass = in_array($roleKey, $allowed, true);
            $code = $call($method, $uri, $actor['token'], $body)->getStatusCode();
            $didPass = $code === 200;

            printf("    %-20s HTTP %-4d %s\n", $roleKey, $code,
                $didPass === $shouldPass
                    ? ($shouldPass ? 'CORRECT - allowed, as the rail says' : 'CORRECT - refused')
                    : ($didPass
                        ? 'WRONG - REACHABLE by a role the rail hides it from'
                        : 'WRONG - refused a role the rail offers it to'));
        }
    }

    // ── 3a. AND THE WRITES BEHIND THOSE SECTIONS ────────────────────────────
    //
    // A read that leaks an organisation's currency is untidy. A WRITE that lets
    // any employee change the password policy for everybody is a different kind
    // of thing, and it is what this section exists for.
    //
    // Two sections share ONE endpoint: Organisation defaults and Security policy
    // are both `/api/organization/settings`, while the rail gates them
    // differently - HR may see the defaults, only an administrator the policy.
    // A single route cannot honour two answers, so the write is what matters.
    echo "\n== 3a. and the writes behind those sections ==\n";

    foreach ($GATED_WRITES as $section => [$method, $uri, $body]) {
        $allowed = $SECTIONS[$section];

        printf("\n  %s  (%s %s)\n", $section, $method, $uri);
        printf("  the rail shows it to: %s\n", implode(', ', $allowed));

        foreach ($actors as $roleKey => $actor) {
            $shouldPass = in_array($roleKey, $allowed, true);
            $code = $call($method, $uri, $actor['token'], $body)->getStatusCode();
            // A 422 refuses THIS BODY, not the role, so it still counts as
            // reaching the endpoint - which is the only question being asked.
            $didPass = in_array($code, [200, 422], true);

            printf("    %-20s HTTP %-4d %s\n", $roleKey, $code,
                $didPass === $shouldPass
                    ? ($shouldPass ? 'CORRECT - allowed, as the rail says' : 'CORRECT - refused')
                    : ($didPass
                        ? 'WRONG - CAN WRITE, and the rail hides this from them'
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
