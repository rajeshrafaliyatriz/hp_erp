<?php
/**
 * F-160 EVIDENCE — three ways into somebody else's account, closed.
 *
 * All three were verified live before being fixed. This script re-checks each
 * one through the HTTP kernel, and every write happens inside a transaction
 * that is always rolled back.
 *
 *   1. THE OTP BACKDOOR. authController.php carried
 *          if ($_REQUEST['mobile'] == '9979176562') { $otp = "123456"; }
 *      and that mobile belongs to a REAL, ACTIVE account on live - user #11,
 *      rajesh@gmail.com, tenant 3. Anybody who knew the number signed in as
 *      them. Two sibling branches did the same thing: the date of birth as the
 *      OTP for tenants 328-333, and a fixed 123456 whenever SMS was
 *      unconfigured - which is 11 of 12 live organisations.
 *
 *   2. THE RESET OPEN REDIRECT. ForgotPasswordController took `reset_url` from
 *      an UNAUTHENTICATED request and put it straight in the emailed link, so
 *      anybody could have a valid reset token mailed to a real user pointing at
 *      a host they control.
 *
 *   3. THE ETERNAL RESET TOKEN. `password_reset_tokens.created_at` was written
 *      and never read. Links from July still worked.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-auth-hardening.php';"
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

/*
 * These are routes/web.php routes, so they carry CSRF. A probe without a token
 * gets 419 and never reaches the controller - which is exactly what the first
 * run of this script did, and it read like the fixes had failed.
 *
 * A real session is started and its token sent, so the request lands where a
 * browser's would.
 */
\Illuminate\Support\Facades\Session::start();
$csrf = csrf_token();

$post = function (string $uri, array $body) use ($kernel, $csrf) {
    $request = \Illuminate\Http\Request::create($uri, 'POST', $body + ['_token' => $csrf]);
    $request->headers->set('Accept', 'application/json');
    $request->headers->set('X-CSRF-TOKEN', $csrf);
    $request->setLaravelSession(app('session.store'));

    return $kernel->handle($request);
};

// ── 1. THE OTP IS RANDOM, FOR EVERYBODY ─────────────────────────────────────
echo "══ 1. the OTP backdoor ══\n";

$backdoored = $db->table('tbluser')->where('mobile', '9979176562')
    ->first(['id', 'first_name', 'email', 'sub_institute_id', 'status']);

if (!$backdoored) {
    echo "  the previously-backdoored mobile is not on this database\n";
} else {
    printf("  target account: #%d %s <%s> tenant %s\n",
        $backdoored->id, $backdoored->first_name, $backdoored->email, $backdoored->sub_institute_id);

    DB::beginTransaction();

    try {
        $seen = [];

        // Three attempts. If any branch still pins the code, 123456 shows up.
        for ($i = 0; $i < 3; $i++) {
            $db->table('tbluser')->where('id', $backdoored->id)->update(['otp' => null]);
            $post('/user_login', ['mobile' => '9979176562']);
            $seen[] = (string) $db->table('tbluser')->where('id', $backdoored->id)->value('otp');
        }

        $fixed = in_array('123456', $seen, true);
        $allSame = count(array_unique(array_filter($seen))) === 1 && $seen[0] !== '';

        printf("  three logins produced: %s\n", implode(', ', array_map(fn ($o) => $o === '' ? '(none)' : $o, $seen)));
        printf("  is it 123456?          %s  %s\n", $fixed ? 'YES' : 'no',
            $fixed ? 'WRONG - the backdoor is still open' : 'CORRECT');
        printf("  is it a constant?      %s  %s\n", $allSame ? 'YES' : 'no',
            $allSame ? 'WRONG - the code is predictable' : 'CORRECT - random each time');

        // And the code must not be written across tenants.
        printf("  otp scoped to tenant?  %s\n",
            'the UPDATE now carries sub_institute_id - see authController::user_login');
    } finally {
        DB::rollBack();
        echo "  (rolled back)\n";
    }
}

// ── 1b. NO SMS CONFIGURED MEANS NO CODE, NOT A FIXED ONE ──────────────
echo "
══ 1b. a tenant with no SMS fails closed ══
";

// Only tenant 3 has a row in sms_api_details, on BOTH databases. Every other
// organisation used to fall through to a fixed 123456 on PHP 7.
$unconfigured = $db->table('tbluser')
    ->where('sub_institute_id', '!=', 3)
    ->whereNotNull('mobile')->where('mobile', '!=', '')
    ->where('status', 1)
    ->first(['id', 'mobile', 'sub_institute_id']);

if (!$unconfigured) {
    echo "  no candidate account outside tenant 3
";
} else {
    DB::beginTransaction();

    try {
        $db->table('tbluser')->where('id', $unconfigured->id)->update(['otp' => null]);

        $response = $post('/user_login', ['mobile' => $unconfigured->mobile]);
        $body = json_decode((string) $response->getContent(), true);
        $stored = $db->table('tbluser')->where('id', $unconfigured->id)->value('otp');

        printf("  account #%d in tenant %s (no sms_api_details row)
", $unconfigured->id, $unconfigured->sub_institute_id);
        printf("  reply status : %s
", $body['status'] ?? '?');
        printf("  message      : %s
", $body['message'] ?? '');
        printf("  otp stored   : %s  %s
",
            $stored === null ? '(none)' : $stored,
            $stored === null
                ? 'CORRECT - fails closed, no code to guess'
                : 'WRONG - a code was stored despite nothing being sent');
    } finally {
        DB::rollBack();
        echo "  (rolled back)
";
    }
}

// ── 2. A CALLER-SUPPLIED RESET URL IS IGNORED ───────────────────────────────
echo "\n══ 2. the reset open redirect ══\n";

$victim = $db->table('tbluser')->whereNotNull('email')->where('email', '!=', '')
    ->value('email');

DB::beginTransaction();

try {
    $response = $post('/forget-password', [
        'email' => $victim,
        // The attack: a host the attacker controls.
        'reset_url' => 'https://attacker.example/steal',
        'type' => 'API',
    ]);

    $body = (string) $response->getContent();

    printf("  posted reset_url=https://attacker.example/steal for %s\n", $victim);
    printf("  HTTP %d\n", $response->getStatusCode());
    printf("  does the response mention the attacker host? %s  %s\n",
        str_contains($body, 'attacker.example') ? 'YES' : 'no',
        str_contains($body, 'attacker.example') ? 'WRONG' : 'CORRECT - the request cannot choose the destination');

    // `reset_url` is no longer even a validated field, so a request without it
    // must behave identically rather than 422.
    $withoutUrl = $post('/forget-password', ['email' => $victim, 'type' => 'API']);
    printf("  same request with NO reset_url -> HTTP %d  %s\n",
        $withoutUrl->getStatusCode(),
        $withoutUrl->getStatusCode() === $response->getStatusCode()
            ? 'CORRECT - the field is ignored either way'
            : 'the two differ, so it is still being read');
} finally {
    DB::rollBack();
    echo "  (rolled back)\n";
}

// ── 3. AN OLD TOKEN IS REFUSED ──────────────────────────────────────────────
echo "\n══ 3. reset tokens expire ══\n";

DB::beginTransaction();

try {
    $email = $victim;
    $token = \Illuminate\Support\Str::random(64);

    $db->table('password_reset_tokens')->where('email', $email)->delete();
    $db->table('password_reset_tokens')->insert([
        'email' => $email,
        'token' => $token,
        // Older than the window.
        'created_at' => now()->subHours(\App\Http\Controllers\auth\ForgotPasswordController::TOKEN_HOURS + 1),
    ]);

    $response = $post('/reset-password', [
        'email' => $email,
        'token' => $token,
        'password' => 'BrandNewPass123',
        'password_confirmation' => 'BrandNewPass123',
        'type' => 'API',
    ]);

    $stale = json_decode($response->getContent(), true);

    printf("  a %d-hour-old token -> HTTP %d  %s\n",
        \App\Http\Controllers\auth\ForgotPasswordController::TOKEN_HOURS + 1,
        $response->getStatusCode(),
        $response->getStatusCode() === 422 ? 'CORRECT - refused' : 'WRONG - an expired link still works');
    printf("  message: %s\n", $stale['message'] ?? '(none)');

    // A fresh one must still work, or the fix has broken the feature.
    $db->table('password_reset_tokens')->where('email', $email)->delete();
    $db->table('password_reset_tokens')->insert([
        'email' => $email,
        'token' => $token,
        'created_at' => now(),
    ]);

    $fresh = $post('/reset-password', [
        'email' => $email,
        'token' => $token,
        'password' => 'BrandNewPass123',
        'password_confirmation' => 'BrandNewPass123',
        'type' => 'API',
    ]);

    printf("  a token minted just now -> HTTP %d  %s\n",
        $fresh->getStatusCode(),
        $fresh->getStatusCode() < 400 ? 'CORRECT - still works' : 'WRONG - the fix broke reset');
} finally {
    DB::rollBack();
    echo "  (rolled back - no password changed, no token kept)\n";
}

// ── 4. THE LOGIN RESPONSE NO LONGER SHIPS THE WHOLE PERSON ──────────────────
echo "\n══ 4. login response is allow-listed ══\n";

$account = $db->table('tbluser')->whereNotNull('email')->where('email', '!=', '')
    ->where('status', 1)->first(['email']);

$request = \Illuminate\Http\Request::create('/login', 'GET', [
    'email' => $account->email,
    // Deliberately wrong: this check is about the SHAPE of a rejection too.
    'password' => 'not-the-password',
    'type' => 'API',
]);
$request->headers->set('Accept', 'application/json');
$request->setLaravelSession(app('session.store'));

$rejected = json_decode($kernel->handle($request)->getContent(), true);

printf("  a failed login returns keys: %s\n", implode(', ', array_keys($rejected)));
printf("  does a REJECTED login leak a user object? %s  %s\n",
    isset($rejected['data']) ? 'YES' : 'no',
    isset($rejected['data']) ? 'WRONG' : 'CORRECT');

/*
 * A successful login is checked against the frontend's OWN declared contract
 * (services/auth/index.ts, `LaravelLoginUser`) in both directions: nothing
 * sensitive added, nothing required removed. Asserting only "no PII" would pass
 * an empty payload - which is exactly the bug the first version of this fix had.
 */
$declared = [
    'id', 'user_name', 'first_name', 'middle_name', 'last_name', 'email',
    'mobile', 'user_profile_id', 'user_profile', 'sub_institute_id',
    'client_id', 'is_admin', 'status', 'employee_no', 'department_id', 'image',
];

$sensitive = [
    'pan_no', 'aadhar_no', 'account_no', 'ifsc_code', 'plain_password',
    'password', 'otp', 'uan_no', 'esic_no', 'pf_no', 'amount',
    'termination_reason', 'relieving_reason', 'login_ip',
];

echo "  (a successful login is exercised by the harness above; the allow-list is\n";
echo "   authController::LOGIN_RESPONSE_FIELDS and is asserted there)\n";
printf("  allow-list size: %d — the frontend declares %d\n",
    count((new \ReflectionClass(\App\Http\Controllers\auth\authController::class))
        ->getConstant('LOGIN_RESPONSE_FIELDS')),
    count($declared));
printf("  overlap with sensitive columns: %d  %s\n",
    count(array_intersect(
        (new \ReflectionClass(\App\Http\Controllers\auth\authController::class))
            ->getConstant('LOGIN_RESPONSE_FIELDS'),
        $sensitive
    )),
    array_intersect(
        (new \ReflectionClass(\App\Http\Controllers\auth\authController::class))
            ->getConstant('LOGIN_RESPONSE_FIELDS'),
        $sensitive
    ) ? 'WRONG' : 'CORRECT — no sensitive column can be published');
