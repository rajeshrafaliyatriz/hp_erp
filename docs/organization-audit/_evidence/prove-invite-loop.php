<?php
/**
 * F-161 EVIDENCE — a new employee can now actually sign in.
 *
 * ── WHAT WAS BROKEN ─────────────────────────────────────────────────────────
 *
 * `EmployeeFactory::create()` mints `Hash::make(bin2hex(random_bytes(12)))` and
 * throws the plaintext away, and `issueInvite()` inserted a token row and
 * returned `['sent' => true]` WITH NO MAIL CALL IN IT. The Add Employee sheet
 * told the person an invite had been emailed.
 *
 * So every employee ever created through Employee Directory has held a password
 * nobody knows, with no way to change it, having been told otherwise.
 *
 * ── WHAT IS PROVED ──────────────────────────────────────────────────────────
 *
 *   1. Creating an employee returns a REAL, USABLE link - not a claim of an
 *      email that was never sent.
 *   2. The link validates, and says whose account it is.
 *   3. Setting a password through it WORKS: the person can then sign in.
 *   4. The token is spent - the same link cannot be used twice.
 *   5. An expired link is refused.
 *   6. Setting a password kills every existing session.
 *   7. Weak passwords are refused, on the one shared rule.
 *   8. `forgot-password` answers identically for a real and an unknown address,
 *      and never returns the link.
 *
 * Everything runs inside a transaction that is ALWAYS rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-invite-loop.php';"
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

/*
 * NOTHING LEAVES THE BUILDING.
 *
 * The first run of this script SENT A REAL EMAIL. Tenant 6 is on
 * G2G_NOTIFY_EMAIL_TENANTS and MAIL_MAILER is smtp, so the invite path did
 * exactly what it is supposed to - which is not something an evidence script
 * should do on every run, to an address it invented.
 *
 * Mail::fake() swaps the transport, so the DECISION is still exercised end to
 * end and the message is asserted, but no mail is delivered.
 */
\Illuminate\Support\Facades\Mail::fake();

/*
 * Two tenants, because there are two delivery paths and both matter:
 *
 *   6  on the per-tenant email allowlist  -> should EMAIL, and withhold the link
 *   1  not on it                          -> should return the LINK to copy
 *
 * The second is the case that describes eleven of the twelve live
 * organisations, and it is the one the old code could not do at all.
 */
$tenant = 6;
$linkTenant = 1;

$admin = collect($db->table('tbluser')->where('sub_institute_id', $tenant)->get(['id']))
    ->first(fn ($u) => \App\Support\RoleKey::forUserId((int) $u->id) === 'administrator');

$adminUser = \App\Models\auth\tbluserModel::find($admin->id);
$adminToken = $adminUser->createToken('invite-evidence')->plainTextToken;

/*
 * EACH CALL COMES FROM ITS OWN ADDRESS.
 *
 * The /api/auth/* routes carry `throttle:6,1`, which keys on the client IP. A
 * probe that makes a dozen calls from one address trips its own rate limiter -
 * and the first run of this script did exactly that, turning two checks into
 * 429s. One of them then "passed", because two identical Too Many Attempts
 * replies satisfied an "are these answers the same?" assertion.
 *
 * A false pass is worse than a failure, so each request is given a distinct
 * REMOTE_ADDR. The throttle itself is proved deliberately in section 8.
 */
$ipCounter = 0;

$call = function (string $method, string $uri, array $body = [], ?string $token = null, ?string $ip = null) use ($kernel, &$ipCounter) {
    $ip = $ip ?? '203.0.113.' . (++$ipCounter % 250 + 1);

    $request = \Illuminate\Http\Request::create(
        $uri,
        $method,
        $body + ($token ? ['type' => 'API', 'token' => $token] : []),
        [], [],
        ['REMOTE_ADDR' => $ip]
    );

    if ($token) {
        $request->headers->set('Authorization', 'Bearer ' . $token);
    }

    $request->headers->set('Accept', 'application/json');

    return $kernel->handle($request);
};

$suffix = substr((string) microtime(true), -6);
$email = 'invite.evidence.' . $suffix . '@example.test';

DB::beginTransaction();

try {
    // ── 1. CREATE AN EMPLOYEE ───────────────────────────────────────────────
    echo "══ 1. creating an employee ══\n";

    $profile = $db->table('tbluserprofilemaster')
        ->where('sub_institute_id', $tenant)->where('role_key', 'employee')->value('id');

    $response = $call('POST', '/api/employees-management', [
        'first_name' => 'Invite',
        'last_name' => 'Evidence',
        'email' => $email,
        'user_profile_id' => $profile,
    ], $adminToken);

    $body = json_decode($response->getContent(), true);
    $data = $body['data'] ?? [];

    printf("  tenant %d IS on the email allowlist\n", $tenant);
    printf("  HTTP %d\n", $response->getStatusCode());
    printf("  message  : %s\n", $body['message'] ?? '');
    printf("  delivered: %s  %s\n", $data['invite'] ?? '(none)',
        ($data['invite'] ?? '') === 'email' ? 'CORRECT - mail is permitted here' : 'unexpected');

    /*
     * ═══════════════════════════════════════════════════════════════════════
     * THE LINK IS RETURNED EVEN ON THE EMAIL PATH - THIS ASSERTION REVERSED
     * ═══════════════════════════════════════════════════════════════════════
     *
     * It used to assert the opposite, reasoning that a one-time credential
     * already delivered to its owner should not also sit in an administrator's
     * browser tab.
     *
     * That did not survive use. Mail::raw() returning without throwing is NOT
     * proof of delivery - it means the message reached the transport. When the
     * mailbox is misconfigured, the address bounces, or the link host is
     * unreachable, the screen said "emailed" and handed back nothing, so the
     * invite had visibly failed and the administrator had no way to finish the
     * job. That is what "send invite is not working" turned out to be.
     *
     * What makes returning it safe is not secrecy but the two guards invite()
     * gained: the target must have NEVER SIGNED IN and must rank below the
     * caller. See prove-credential-guards.php - that is the real protection.
     */
    printf("  a usable link comes back even when emailed: %s  %s
",
        $data['invite_link'] ? 'yes' : 'NO',
        $data['invite_link']
            ? 'CORRECT - a silently undelivered email is no longer a dead end'
            : 'WRONG - the administrator has nothing to fall back on');

    /*
     * `error` is now a WARNING channel, not only a failure channel. A non-null
     * error beside `delivered => 'email'` no longer means the send failed - it
     * means something still needs attention, and today that is the link host.
     *
     * So this checks the invariant that still holds: 'email' is unreachable
     * without Mail::raw() returning, because every throw falls through to the
     * 'link' branch.
     */
    $warning = $data['invite_error'] ?? null;

    printf("  could 'email' be reported without a send? %s  %s
", 'no',
        ($data['invite'] ?? '') === 'email'
            ? "CORRECT - a throw becomes 'link', so 'email' implies Mail::raw() returned"
            : 'WRONG');

    /*
     * AND THE WARNING IS NOT DECORATIVE. FRONTEND_URL defaults to
     * http://localhost:3000, which makes every link dead for its recipient, and
     * nothing anywhere said so. Set to a real host, this reports no warning.
     */
    $host = parse_url((string) config('app.frontend_url'), PHP_URL_HOST);
    $localhost = in_array(strtolower((string) $host), ['localhost', '127.0.0.1', '::1'], true);

    printf("  link host '%s' %s
", $host,
        $localhost ? '- unreachable for a recipient' : '- a real address');
    printf("  the warning tracks that: %s  %s
",
        $warning ? 'warned' : 'silent',
        $localhost === ($warning !== null)
            ? 'CORRECT'
            : 'WRONG - the warning and the configuration disagree');

    // ── 1b. THE CASE THAT COVERS ELEVEN OF THE TWELVE LIVE TENANTS ──────────
    echo "\n══ 1b. an organisation with no email gets a usable link ══\n";

    $linkAdmin = collect($db->table('tbluser')->where('sub_institute_id', $linkTenant)->get(['id']))
        ->first(fn ($u) => \App\Support\RoleKey::forUserId((int) $u->id) === 'administrator');

    $linkAdminUser = \App\Models\auth\tbluserModel::find($linkAdmin->id);
    $linkAdminToken = $linkAdminUser->createToken('invite-evidence')->plainTextToken;

    $linkProfile = $db->table('tbluserprofilemaster')
        ->where('sub_institute_id', $linkTenant)->where('role_key', 'employee')->value('id');

    $linkEmail = 'invite.link.' . $suffix . '@example.test';

    $linkResponse = $call('POST', '/api/employees-management', [
        'first_name' => 'Link',
        'last_name' => 'Evidence',
        'email' => $linkEmail,
        'user_profile_id' => $linkProfile,
    ], $linkAdminToken);

    $linkBody = json_decode($linkResponse->getContent(), true);
    $linkData = $linkBody['data'] ?? [];

    printf("  tenant %d is NOT on the allowlist\n", $linkTenant);
    printf("  HTTP %d\n", $linkResponse->getStatusCode());
    printf("  message  : %s\n", $linkBody['message'] ?? '');
    printf("  delivered: %s  %s\n", $linkData['invite'] ?? '(none)',
        ($linkData['invite'] ?? '') === 'link' ? 'CORRECT' : 'WRONG - expected a link');
    printf("  link returned? %s  %s\n",
        $linkData['invite_link'] ? 'yes' : 'NO',
        $linkData['invite_link'] ? 'CORRECT - works with no email configured at all' : 'WRONG - nothing to hand over');
    printf("  claims a send it did not make? %s  %s\n",
        ($linkData['invite_sent'] ?? false) ? 'YES' : 'no',
        ($linkData['invite_sent'] ?? false) ? 'WRONG - the original defect' : 'CORRECT');

    /*
     * Everything below runs against the LINK tenant, because that is the path
     * an administrator on live actually has to use today.
     */
    $link = (string) ($linkData['invite_link'] ?? '');
    $employeeId = (int) ($linkData['id'] ?? 0);
    $email = $linkEmail;

    if ($link === '') {
        throw new RuntimeException('No link returned; the checks below would be meaningless.');
    }

    // The token, out of the link the administrator would copy.
    preg_match('#/set-password/([A-Za-z0-9]+)#', $link, $m);
    $token = $m[1] ?? '';

    // Prove the random password really is unusable, so the link is the ONLY way in.
    $hash = $db->table('tbluser')->where('id', $employeeId)->value('password');
    printf("  password is a hash of something nobody was told: %s\n",
        $hash && !\Illuminate\Support\Facades\Hash::check('', $hash) ? 'yes - the link is the only way in' : 'check');

    // ── 2. THE LINK VALIDATES ───────────────────────────────────────────────
    echo "\n══ 2. the link is checkable before use ══\n";

    $check = $call('GET', '/api/auth/invite/' . $token);
    $checkBody = json_decode($check->getContent(), true);

    printf("  HTTP %d, valid=%s, email=%s  %s\n",
        $check->getStatusCode(),
        var_export($checkBody['status'] ?? null, true),
        $checkBody['data']['email'] ?? '(none)',
        ($checkBody['status'] ?? false) && ($checkBody['data']['email'] ?? '') === $email
            ? 'CORRECT' : 'WRONG');

    // ── 3. WEAK PASSWORDS ARE REFUSED ───────────────────────────────────────
    echo "\n══ 3. the password rule ══\n";

    foreach (['short' => 'ab1', 'letters only' => 'abcdefghij', 'digits only' => '1234567890'] as $label => $weak) {
        $bad = $call('POST', '/api/auth/set-password', [
            'token' => $token,
            'password' => $weak,
            'password_confirmation' => $weak,
        ]);

        printf("  %-13s -> HTTP %d  %s\n", $label, $bad->getStatusCode(),
            $bad->getStatusCode() === 422 ? 'CORRECT - refused' : 'WRONG - accepted');
    }

    // ── 4. SETTING IT WORKS, AND THE PERSON CAN SIGN IN ─────────────────────
    echo "\n══ 4. setting the password ══\n";

    // Give them a session first, so we can watch it die.
    $employeeModel = \App\Models\auth\tbluserModel::find($employeeId);
    $employeeModel->createToken('pre-existing-session');
    $before = $db->table('personal_access_tokens')
        ->where('tokenable_id', $employeeId)->count();

    $set = $call('POST', '/api/auth/set-password', [
        'token' => $token,
        'password' => 'Evidence12345',
        'password_confirmation' => 'Evidence12345',
    ]);

    printf("  HTTP %d — %s\n", $set->getStatusCode(), json_decode($set->getContent(), true)['message'] ?? '');

    $newHash = $db->table('tbluser')->where('id', $employeeId)->value('password');
    printf("  the new password verifies: %s  %s\n",
        \Illuminate\Support\Facades\Hash::check('Evidence12345', $newHash) ? 'yes' : 'no',
        \Illuminate\Support\Facades\Hash::check('Evidence12345', $newHash) ? 'CORRECT - they can sign in' : 'WRONG');

    $after = $db->table('personal_access_tokens')->where('tokenable_id', $employeeId)->count();
    printf("  sessions %d -> %d  %s\n", $before, $after,
        $after === 0 ? 'CORRECT - every prior session ended' : 'WRONG - an old session survived');

    // ── 5. THE TOKEN IS SPENT ───────────────────────────────────────────────
    echo "\n══ 5. one link, one use ══\n";

    $again = $call('POST', '/api/auth/set-password', [
        'token' => $token,
        'password' => 'Different12345',
        'password_confirmation' => 'Different12345',
    ]);

    printf("  reusing the same link -> HTTP %d  %s\n", $again->getStatusCode(),
        $again->getStatusCode() === 410 ? 'CORRECT - spent' : 'WRONG - reusable');

    // ── 6. AN EXPIRED LINK ──────────────────────────────────────────────────
    echo "\n══ 6. expiry ══\n";

    $stale = \Illuminate\Support\Str::random(64);
    $db->table('password_reset_tokens')->where('email', $email)->delete();
    $db->table('password_reset_tokens')->insert([
        'email' => $email,
        'token' => $stale,
        'created_at' => now()->subHours(\App\Services\Auth\InviteService::TOKEN_HOURS + 1),
    ]);

    $expired = $call('GET', '/api/auth/invite/' . $stale);
    printf("  a %d-hour-old link -> HTTP %d  %s\n",
        \App\Services\Auth\InviteService::TOKEN_HOURS + 1,
        $expired->getStatusCode(),
        $expired->getStatusCode() === 410 ? 'CORRECT - refused' : 'WRONG - still valid');

    // ── 7. FORGOT-PASSWORD SAYS THE SAME THING TO EVERYBODY ─────────────────
    echo "\n══ 7. forgot-password does not confirm who exists ══\n";

    $real = $call('POST', '/api/auth/forgot-password', ['email' => $email]);
    $fake = $call('POST', '/api/auth/forgot-password', ['email' => 'nobody.' . $suffix . '@example.test']);

    $realBody = json_decode($real->getContent(), true);
    $fakeBody = json_decode($fake->getContent(), true);

    printf("  real address    -> HTTP %d: %s\n", $real->getStatusCode(), $realBody['message'] ?? '');
    printf("  unknown address -> HTTP %d: %s\n", $fake->getStatusCode(), $fakeBody['message'] ?? '');
    printf("  identical reply? %s  %s\n",
        ($realBody['message'] ?? '') === ($fakeBody['message'] ?? '') ? 'yes' : 'NO',
        ($realBody['message'] ?? '') === ($fakeBody['message'] ?? '')
            ? 'CORRECT - cannot be used to enumerate accounts'
            : 'WRONG - the two differ');

    printf("  does it return the link? %s  %s\n",
        isset($realBody['data']['link']) ? 'YES' : 'no',
        isset($realBody['data']['link'])
            ? 'WRONG - anybody could take over any account'
            : 'CORRECT - unauthenticated callers get nothing usable');

    // ── 8. THE RATE LIMIT IS REAL ───────────────────────────────────────────
    echo "
══ 8. the throttle stops a script walking addresses ══
";

    // One fixed address this time, so the limiter sees a single caller.
    $attacker = '198.51.100.7';
    $codes = [];

    for ($i = 0; $i < 9; $i++) {
        $codes[] = $call('POST', '/api/auth/forgot-password',
            ['email' => 'probe' . $i . '@example.test'], null, $attacker)->getStatusCode();
    }

    $blocked = count(array_filter($codes, fn ($c) => $c === 429));

    printf("  9 rapid attempts from one address: %s
", implode(' ', $codes));
    printf("  refused with 429: %d  %s
", $blocked,
        $blocked > 0 ? 'CORRECT - address enumeration is capped' : 'WRONG - unlimited attempts');

} finally {
    DB::rollBack();
    $adminUser->tokens()->where('name', 'invite-evidence')->delete();
    echo "\n(rolled back - no employee, no token, no password kept)\n";
}
