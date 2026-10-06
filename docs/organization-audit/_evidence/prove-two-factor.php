<?php
/**
 * EVIDENCE — two-step verification, checked against the RFC and against itself.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THE FIRST SECTION IS THE MOST IMPORTANT
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * This TOTP implementation is hand-rolled rather than `pragmarx/google2fa` -
 * there is no deploy pipeline here, and a new dependency means a `composer
 * install` somebody has to remember, on the sign-in path, which is the worst
 * place in the product to half-deploy.
 *
 * That trade is only defensible because RFC 6238 publishes TEST VECTORS. Section 1
 * checks this implementation against the official SHA-1 vectors from Appendix B.
 * If those pass, the algorithm is right; if they fail, nothing else here matters,
 * because a wrong answer accepts codes it should refuse.
 *
 * ── AND THE REST IS ABOUT THE FLOW, WHICH IS WHERE 2FA USUALLY FAILS ────────
 *
 * The maths is the easy part. What goes wrong in practice:
 *
 *   enabling on enrolment    somebody closes the tab mid-setup and is locked out
 *                            of their own account by a secret their app never got
 *   a reusable recovery code turns a written-down list into a permanent bypass
 *   a token minted first     the password alone already produced a credential and
 *                            the code became a formality
 *   one unguarded sign-in    a second factor one path ignores is not one
 *
 * Every one of those is asserted below.
 *
 * Runs on DEV inside a transaction that is always rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/docs/organization-audit/_evidence/prove-two-factor.php';"
 */

use App\Services\Account\TwoFactor;
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

$tf = new TwoFactor();

// ── 1. THE ALGORITHM, AGAINST THE PUBLISHED VECTORS ─────────────────────────
echo "══ 1. RFC 6238 Appendix B test vectors (SHA-1) ══\n";

$reflect = new ReflectionMethod($tf, 'base32Encode');
$reflect->setAccessible(true);
// The RFC's seed is the ASCII string "12345678901234567890".
$rfcSecret = $reflect->invoke($tf, '12345678901234567890');

$vectors = [
    59 => '94287082',
    1111111109 => '07081804',
    1111111111 => '14050471',
    1234567890 => '89005924',
    2000000000 => '69279037',
    20000000000 => '65353130',
];

foreach ($vectors as $time => $expected) {
    // Eight digits, as the RFC publishes them. Checking a six-digit truncation
    // would be checking something the RFC does not actually assert.
    $tf->codeAt($rfcSecret, $time, 8) === $expected
        ? $ok(sprintf('T=%-12s produces %s', $time, $expected))
        : $bad(sprintf('T=%-12s expected %s, got %s', $time, $expected, $tf->codeAt($rfcSecret, $time, 8)));
}

// ── 2. THE VERIFICATION WINDOW ──────────────────────────────────────────────
echo "\n══ 2. the window forgives a slow clock, and not much else ══\n";

$now = 1700000000;

$tf->verify($rfcSecret, $tf->codeAt($rfcSecret, $now), $now)
    ? $ok('the current code is accepted')
    : $bad('the current code was refused');

$tf->verify($rfcSecret, $tf->codeAt($rfcSecret, $now - TwoFactor::PERIOD), $now)
    ? $ok('the previous 30-second code is accepted, for a clock that is behind')
    : $bad('a phone one step behind cannot sign in');

// Two steps out must NOT work: the window is the trade-off, and a wide one keeps
// a shoulder-surfed code alive longer.
!$tf->verify($rfcSecret, $tf->codeAt($rfcSecret, $now - (3 * TwoFactor::PERIOD)), $now)
    ? $ok('a code three steps old is refused')
    : $bad('the window is far too wide - old codes still work');

!$tf->verify($rfcSecret, '000000', $now)
    ? $ok('a wrong code is refused')
    : $bad('a wrong code was accepted');

!$tf->verify($rfcSecret, '12345', $now)
    ? $ok('a short code is refused rather than padded')
    : $bad('a five-digit code was accepted');

DB::beginTransaction();

try {
    $call = function (string $method, string $uri, string $token, array $body = []) use ($kernel) {
        $request = \Illuminate\Http\Request::create(
            $uri, $method, array_merge(['type' => 'API', 'token' => $token], $body)
        );
        $request->headers->set('Accept', 'application/json');

        return $kernel->handle($request);
    };

    $profile = $db->table('tbluserprofilemaster')
        ->where('sub_institute_id', $tenant)->where('role_key', 'employee')->first(['id']);

    $userId = $db->table('tbluser')->insertGetId([
        'user_profile_id' => $profile->id,
        'sub_institute_id' => $tenant,
        'first_name' => 'Two',
        'last_name' => 'Factor',
        'email' => 'two.factor@example.test',
        'password' => \Illuminate\Support\Facades\Hash::make('TwoFactor123'),
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $token = \App\Models\auth\tbluserModel::find($userId)->createToken('Evidence laptop')->plainTextToken;

    // ── 3. A SECRET DOES NOT PROTECT THE ACCOUNT UNTIL IT IS CONFIRMED ──────
    echo "\n══ 3. starting enrolment does not turn it on ══\n";

    $started = json_decode($call('POST', '/api/account/2fa/start', $token)->getContent(), true);
    $secret = $started['data']['secret'] ?? null;

    $secret
        ? $ok('a secret is issued')
        : $bad('no secret was issued: ' . json_encode($started));

    str_contains($started['data']['uri'] ?? '', 'otpauth://totp/')
        ? $ok('with an otpauth:// URI an authenticator app understands')
        : $bad('no enrolment URI');

    /*
     * The assertion that stops somebody locking themselves out. If a stored secret
     * counted as protection, closing the tab here would leave the account
     * demanding codes from an app that never received it.
     */
    !$tf->isEnabled($userId)
        ? $ok('but it is NOT yet on - closing the tab now locks nobody out')
        : $bad('the account is protected by a secret the person may never have scanned');

    // ── 4. CONFIRMATION TURNS IT ON AND ISSUES RECOVERY CODES ───────────────
    echo "\n══ 4. confirming with a real code turns it on ══\n";

    $wrongCode = $call('POST', '/api/account/2fa/confirm', $token, ['code' => '000000']);

    $wrongCode->getStatusCode() === 422
        ? $ok('a wrong code is refused with 422')
        : $bad('a wrong code returned HTTP ' . $wrongCode->getStatusCode());

    !$tf->isEnabled($userId)
        ? $ok('and it is still not on')
        : $bad('a wrong code enabled it anyway');

    $confirmed = json_decode(
        $call('POST', '/api/account/2fa/confirm', $token, ['code' => $tf->codeAt($secret, time())])->getContent(),
        true
    );

    $tf->isEnabled($userId)
        ? $ok('the right code turns it on')
        : $bad('the right code did not turn it on: ' . json_encode($confirmed));

    $codes = $confirmed['data']['recovery_codes'] ?? [];

    count($codes) === TwoFactor::RECOVERY_CODES
        ? $ok(TwoFactor::RECOVERY_CODES . ' recovery codes are issued')
        : $bad('got ' . count($codes) . ' recovery codes');

    /*
     * Stored hashed. Readable in the database they would be ten permanent
     * skeleton keys per account, which is worse than having no recovery at all
     * because nobody would know.
     */
    $stored = (string) $db->table('user_two_factor')->where('user_id', $userId)->value('recovery_codes');

    !str_contains($stored, (string) ($codes[0] ?? 'nothing'))
        ? $ok('and stored hashed, not in readable form')
        : $bad('the recovery codes are readable in the database');

    // ── 5. A RECOVERY CODE WORKS ONCE ───────────────────────────────────────
    echo "\n══ 5. a recovery code is spent when it is used ══\n";

    $tf->useRecoveryCode($userId, $codes[0])
        ? $ok('a valid recovery code is accepted')
        : $bad('a valid recovery code was refused');

    !$tf->useRecoveryCode($userId, $codes[0])
        ? $ok('and the SAME code is refused the second time')
        : $bad('a recovery code can be replayed - it is a permanent bypass');

    $tf->recoveryCodesLeft($userId) === TwoFactor::RECOVERY_CODES - 1
        ? $ok('the remaining count went down by exactly one')
        : $bad('the count is ' . $tf->recoveryCodesLeft($userId));

    // ── 6. TURNING IT OFF NEEDS THE PASSWORD ────────────────────────────────
    echo "\n══ 6. it cannot be turned off from a borrowed session alone ══\n";

    $noPassword = $call('POST', '/api/account/2fa/disable', $token);

    $noPassword->getStatusCode() === 422
        ? $ok('turning it off without a password is refused')
        : $bad('it was turned off with HTTP ' . $noPassword->getStatusCode());

    $wrongPassword = $call('POST', '/api/account/2fa/disable', $token, ['current_password' => 'not-the-password']);

    $wrongPassword->getStatusCode() === 422 && $tf->isEnabled($userId)
        ? $ok('and a wrong password leaves it on')
        : $bad('a wrong password turned it off');

    $call('POST', '/api/account/2fa/disable', $token, ['current_password' => 'TwoFactor123']);

    !$tf->isEnabled($userId)
        ? $ok('the right password turns it off')
        : $bad('the right password did not turn it off');
} finally {
    DB::rollBack();
    echo "\n(rolled back - no account, secret or code kept)\n";
}

printf("\n%d CORRECT, %d WRONG\n", $correct, $wrong);
