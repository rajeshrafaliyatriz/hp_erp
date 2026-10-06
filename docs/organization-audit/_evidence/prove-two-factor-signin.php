<?php
/**
 * EVIDENCE — signing in with two-step verification on, through the real /login.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS SEPARATELY FROM prove-two-factor.php
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * That file proves the ALGORITHM and the ENROLMENT: RFC 6238 vectors, the window,
 * single-use recovery codes, the password needed to turn it off. Every one of those
 * assertions passed while the feature was COMPLETELY BYPASSABLE, because none of
 * them drove a sign-in.
 *
 * ── THE BUG THIS FILE WAS WRITTEN FOR ──────────────────────────────────────
 *
 * The challenge was called immediately before `createToken`, which reads as
 * correct - no token until the code is right. But 230 lines earlier, the same
 * method runs:
 *
 *     session()->put('user_id', $user->id);
 *     session()->put('user_profile_id', $user->user_profile_id);
 *
 * and `authMiddleware::hasSession()` is, in full:
 *
 *     return session()->has('user_id') && session()->get('user_id');
 *
 * So the 401 "enter your code" went back with a session cookie that was already a
 * logged-in identity. Type the right password, ignore the code, open any Blade
 * route: in. `RequireHritRole` would have read the role off that same key and
 * authorised it.
 *
 * A token is not the only credential this controller hands out, so "no token" was
 * never the same claim as "not authenticated". THAT is what section 2 asserts, and
 * it is the assertion the other file cannot make because it never touches /login.
 *
 * ── WHY THE REAL ENDPOINT CAN BE DRIVEN HERE AT ALL ────────────────────────
 *
 * An earlier attempt at this gave up on a 419 and tested a private method by
 * reflection instead. That was wrong: `VerifyCsrfToken` exempts GET, and the
 * frontend signs in with `webClient.get('/login', ...)` - a GET. There was never a
 * CSRF obstacle on the path the product actually uses.
 *
 * Runs on DEV inside a transaction that is always rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/docs/organization-audit/_evidence/prove-two-factor-signin.php';"
 */

use App\Services\Account\TwoFactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

\Illuminate\Support\Facades\Mail::fake();

$tenant = 6;
$password = 'SignInCheck123';
$correct = 0;
$wrong = 0;

$ok = function (string $m) use (&$correct) { printf("  CORRECT  %s\n", $m); $correct++; };
$bad = function (string $m) use (&$wrong) { printf("  WRONG    %s\n", $m); $wrong++; };

$tf = new TwoFactor();

DB::beginTransaction();

try {
    /**
     * One sign-in attempt through the kernel, with a CLEAN session each time.
     *
     * The flush is not tidiness. Without it, the successful sign-in in section 3
     * would leave `user_id` in the store and section 2's "the session is empty"
     * assertion would be measuring the previous request - which is exactly the kind
     * of order-dependent pass that hides the bug this file exists for.
     */
    $signIn = function (array $extra = []) use ($kernel, $password) {
        Session::flush();

        $request = \Illuminate\Http\Request::create('/login', 'GET', array_merge([
            'email' => 'signin.check@example.test',
            'password' => $password,
            'type' => 'API',
        ], $extra));
        $request->headers->set('Accept', 'application/json');

        $response = $kernel->handle($request);

        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode($response->getContent(), true),
            // Read AFTER handling, so this is what the request left behind.
            'session_user_id' => Session::get('user_id'),
        ];
    };

    $profile = $db->table('tbluserprofilemaster')
        ->where('sub_institute_id', $tenant)->where('role_key', 'employee')->first(['id']);

    $userId = $db->table('tbluser')->insertGetId([
        'user_profile_id' => $profile->id,
        'sub_institute_id' => $tenant,
        'first_name' => 'SignIn',
        'last_name' => 'Check',
        'email' => 'signin.check@example.test',
        'password' => \Illuminate\Support\Facades\Hash::make($password),
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tokens = fn () => $db->table('personal_access_tokens')
        ->where('tokenable_type', \App\Models\auth\tbluserModel::class)
        ->where('tokenable_id', $userId)
        ->count();

    // ── 1. WITHOUT 2FA, THIS ACCOUNT CAN SIGN IN ────────────────────────────
    //
    // The control. Every assertion below is "and now it cannot", which means
    // nothing unless it could to begin with - a typo in the email would otherwise
    // make this whole file pass.
    echo "══ 1. the control: the same account signs in normally ══\n";

    $before = $signIn();

    $before['status'] === 200
        ? $ok('a correct password returns HTTP 200')
        : $bad('the baseline sign-in returned HTTP ' . $before['status'] . ': ' . json_encode($before['body']));

    $baselineWorked = (int) ($before['body']['status'] ?? 0) === 1;

    $baselineWorked
        ? $ok('and status 1, so this account and tenant are usable')
        : $bad('the baseline sign-in was rejected: ' . json_encode($before['body']['message'] ?? null));

    (string) $before['session_user_id'] === (string) $userId
        ? $ok('and the session carries user_id, which is what authMiddleware accepts')
        : $bad('the baseline sign-in left no user_id in the session - this file cannot measure anything');

    // ── 2. WITH 2FA ON, THE PASSWORD ALONE GETS NOTHING ─────────────────────
    echo "\n══ 2. enrolled: the right password alone is refused ══\n";

    $secret = $tf->beginEnrolment($userId, $tenant);
    $tf->confirmEnrolment($userId, $tf->codeAt($secret, time()));

    $tf->isEnabled($userId)
        ? $ok('two-step verification is on for this account')
        : $bad('enrolment did not take - nothing below is meaningful');

    $tokensBefore = $tokens();
    $challenged = $signIn();

    $challenged['status'] === 401
        ? $ok('the correct password now returns HTTP 401')
        : $bad('it returned HTTP ' . $challenged['status'] . ': ' . json_encode($challenged['body']));

    ($challenged['body']['two_factor_required'] ?? false) === true
        ? $ok('carrying two_factor_required, which is what the sign-in screen reads')
        : $bad('no two_factor_required flag: ' . json_encode($challenged['body']));

    $tokens() === $tokensBefore
        ? $ok('and no token was minted')
        : $bad('a token was minted for a sign-in that was challenged');

    /*
     * ── THE ASSERTION THIS FILE WAS WRITTEN FOR ─────────────────────────────
     *
     * Everything above passed while the feature was bypassable. This is the one
     * that did not: `user_id` in the session IS an authenticated web caller, by
     * `authMiddleware::hasSession()`'s own definition.
     */
    $challenged['session_user_id'] === null
        ? $ok('and the session holds NO user_id - so no Blade route will accept them either')
        : $bad('THE SESSION IS LOGGED IN: user_id=' . var_export($challenged['session_user_id'], true)
            . ' - the challenge can be ignored and every web route will accept this caller');

    // ── 3. A WRONG CODE IS REFUSED, A RIGHT ONE IS NOT ──────────────────────
    echo "\n══ 3. the code decides, and only the code ══\n";

    $wrongCode = $signIn(['two_factor_code' => '000000']);

    $wrongCode['status'] === 401 && $wrongCode['session_user_id'] === null
        ? $ok('a wrong code is refused and leaves the session empty')
        : $bad('a wrong code returned HTTP ' . $wrongCode['status']
            . ' with session user_id=' . var_export($wrongCode['session_user_id'], true));

    /*
     * A wrong PASSWORD with a right code must also fail. Two factors means both,
     * and a flow that checks the code and forgets to re-check the password has
     * turned the authenticator into the only credential.
     */
    Session::flush();
    $wrongPassword = \Illuminate\Http\Request::create('/login', 'GET', [
        'email' => 'signin.check@example.test',
        'password' => 'not-the-password',
        'type' => 'API',
        'two_factor_code' => $tf->codeAt($secret, time()),
    ]);
    $wrongPassword->headers->set('Accept', 'application/json');
    $wrongPasswordBody = json_decode($kernel->handle($wrongPassword)->getContent(), true);

    (int) ($wrongPasswordBody['status'] ?? 1) !== 1 && Session::get('user_id') === null
        ? $ok('a WRONG password with a RIGHT code is refused - both are still required')
        : $bad('a valid code signed in without the password: ' . json_encode($wrongPasswordBody));

    $accepted = $signIn(['two_factor_code' => $tf->codeAt($secret, time())]);

    !($accepted['body']['two_factor_required'] ?? false)
        ? $ok('the current code is accepted and the challenge is over')
        : $bad('a correct code was still challenged: ' . json_encode($accepted['body']));

    if ($baselineWorked) {
        (int) ($accepted['body']['status'] ?? 0) === 1
            ? $ok('and the sign-in completes, exactly as it did in section 1')
            : $bad('the code passed but the sign-in did not complete: ' . json_encode($accepted['body']['message'] ?? null));

        (string) $accepted['session_user_id'] === (string) $userId
            ? $ok('with the session logged in only NOW')
            : $bad('the session was not established after a correct code');
    }

    // ── 4. A RECOVERY CODE IS THE WAY BACK IN, ONCE ─────────────────────────
    echo "\n══ 4. a recovery code signs in, and then never again ══\n";

    $codes = $tf->reissueRecoveryCodes($userId);
    $recovery = $codes[0];

    $used = $signIn(['recovery_code' => $recovery]);

    !($used['body']['two_factor_required'] ?? false)
        ? $ok('a recovery code is accepted at sign-in')
        : $bad('a valid recovery code was refused: ' . json_encode($used['body']));

    /*
     * The SAME code again. A replayable recovery code turns a list somebody wrote
     * on paper into a permanent second password, which is worse than having no
     * recovery path at all because nobody would know.
     */
    $replayed = $signIn(['recovery_code' => $recovery]);

    $replayed['status'] === 401 && $replayed['session_user_id'] === null
        ? $ok('and the same code is refused the second time')
        : $bad('a recovery code was replayed at sign-in - it is a permanent bypass');

    $tf->recoveryCodesLeft($userId) === TwoFactor::RECOVERY_CODES - 1
        ? $ok('with exactly one code spent')
        : $bad('the remaining count is ' . $tf->recoveryCodesLeft($userId));

    // ── 5. AND TURNING IT OFF RESTORES THE ORIGINAL BEHAVIOUR ───────────────
    echo "\n══ 5. turned off, the password alone works again ══\n";

    $tf->disable($userId);
    $after = $signIn();

    !($after['body']['two_factor_required'] ?? false)
        ? $ok('no challenge once it is off')
        : $bad('it is still challenging after being disabled');

    if ($baselineWorked) {
        (int) ($after['body']['status'] ?? 0) === 1
            ? $ok('and the sign-in works as it did before any of this')
            : $bad('the account cannot sign in after disabling: ' . json_encode($after['body']['message'] ?? null));
    }
} finally {
    Session::flush();
    DB::rollBack();
    echo "\n(rolled back - no account, secret, code or token kept)\n";
}

printf("\n%d CORRECT, %d WRONG\n", $correct, $wrong);
