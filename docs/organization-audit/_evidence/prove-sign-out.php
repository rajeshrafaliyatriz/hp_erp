<?php
/**
 * EVIDENCE — signing out actually ends the session, on the server.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THERE WAS NO LOGOUT ENDPOINT IN THIS APPLICATION
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Not a broken one - none. `php artisan route:list | grep logout` returned nothing,
 * while `header.blade.php:242` rendered a link to `/logout` and
 * `footer.blade.php:27` navigated to it. Both 404ed.
 *
 * So "Sign out" cleared the browser and nothing else:
 *
 *   - the Sanctum token stayed valid for its full 30-day idle window, so anybody
 *     who recovered it from a shared machine was still authenticated;
 *   - the Laravel session was never invalidated, and `authMiddleware::hasSession()`
 *     is satisfied by `session('user_id')` alone - a key nothing removed.
 *
 * `AccountController::endSessions` even answers "That is this device. Use Sign out
 * instead" for an attempt to end the current session by id, which until now pointed
 * at an action that revoked nothing at all.
 *
 * ── THE TWO ASSERTIONS THAT MATTER ──────────────────────────────────────────
 *
 * Section 2: the token is dead afterwards - checked by USING it, not by reading the
 * table, because "the row is gone" and "the credential no longer works" are
 * different claims and only the second one protects anybody.
 *
 * Section 3: the person's OTHER devices survive. A sign-out that logs somebody out
 * everywhere is a different feature, and shipping it by accident would be a
 * regression dressed as security.
 *
 * Runs on DEV inside a transaction that is always rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/docs/organization-audit/_evidence/prove-sign-out.php';"
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

    $profileId = $db->table('tbluserprofilemaster')
        ->where('sub_institute_id', $tenant)->where('role_key', 'employee')->value('id');

    $userId = $db->table('tbluser')->insertGetId([
        'user_profile_id' => $profileId,
        'sub_institute_id' => $tenant,
        'first_name' => 'Sign',
        'last_name' => 'Out',
        'email' => 'sign.out@example.test',
        'password' => \Illuminate\Support\Facades\Hash::make('SignOutCheck123'),
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $model = \App\Models\auth\tbluserModel::find($userId);
    $laptop = $model->createToken('Laptop')->plainTextToken;
    $phone = $model->createToken('Phone')->plainTextToken;

    $tokens = fn () => $db->table('personal_access_tokens')
        ->where('tokenable_type', \App\Models\auth\tbluserModel::class)
        ->where('tokenable_id', $userId)
        ->count();

    // ── 1. THE ROUTE EXISTS AT ALL ──────────────────────────────────────────
    echo "══ 1. there is a sign-out endpoint ══\n";

    $before = $tokens();

    $out = $call('POST', '/api/account/logout', $laptop);

    $out['status'] === 200
        ? $ok('POST /api/account/logout answers 200')
        : $bad('it answered HTTP ' . $out['status'] . ': ' . json_encode($out['body']));

    // And the web one, which two Blade views have linked to all along.
    collect(app('router')->getRoutes()->getRoutes())
        ->contains(fn ($r) => $r->uri() === 'logout')
        ? $ok('and GET /logout exists, so the ERP header link no longer 404s')
        : $bad('GET /logout is still undeclared - header.blade.php:242 is a dead link');

    // ── 2. THE TOKEN IS DEAD, TESTED BY USING IT ────────────────────────────
    echo "\n══ 2. the revoked token no longer works ══\n";

    $tokens() === $before - 1
        ? $ok('exactly one token was removed')
        : $bad('token count went from ' . $before . ' to ' . $tokens());

    /*
     * The assertion that matters. Checking the table would only prove a row is gone;
     * this proves the CREDENTIAL is refused, which is the thing somebody is relying
     * on when they sign out of a borrowed computer.
     */
    $reuse = $call('GET', '/api/account/me', $laptop);

    $reuse['status'] === 401
        ? $ok('and reusing it is refused with 401')
        : $bad('the revoked token still works: HTTP ' . $reuse['status']);

    // ── 3. THE PERSON'S OTHER DEVICES ARE UNAFFECTED ────────────────────────
    //
    // Pulls against section 2. Both passing is what shows the revocation is scoped
    // to one session rather than being "sign out everywhere" under another name.
    echo "\n══ 3. signing out of a laptop does not sign out the phone ══\n";

    $stillIn = $call('GET', '/api/account/me', $phone);

    $stillIn['status'] === 200
        ? $ok('the other device is still signed in')
        : $bad('the phone was signed out too: HTTP ' . $stillIn['status']);

    (int) ($stillIn['body']['data']['profile']['id'] ?? 0) === $userId
        ? $ok('and still answers as the right person')
        : $bad('the other session resolves to somebody else');

    // ── 4. IT IS RECORDED ───────────────────────────────────────────────────
    echo "\n══ 4. the sign-out leaves a trace ══\n";

    $db->table('g2g_event')
        ->where('actor_id', $userId)
        ->where('type', 'account.signed_out')
        ->exists()
        ? $ok('recorded as account.signed_out')
        : $bad('signing out leaves no trace, so the security history has a gap');

    // ── 5. AND IT SUCCEEDS EVEN WHEN THERE IS NOTHING LEFT TO REVOKE ────────
    //
    // A second sign-out with a token already gone must not error: the session is
    // already over, which is what the caller asked for. An error here would leave a
    // client unable to complete a sign-out it is entitled to complete.
    echo "\n══ 5. signing out twice is not an error ══\n";

    $again = $call('POST', '/api/account/logout', $laptop);

    /*
     * 401, because the gate ahead of the controller refuses a dead token before the
     * method runs - which is the correct answer and not a failure of this feature:
     * the caller is already signed out. What must NOT happen is a 500.
     */
    in_array($again['status'], [200, 401], true)
        ? $ok('a repeat sign-out answers ' . $again['status'] . ', not a server error')
        : $bad('a repeat sign-out returned HTTP ' . $again['status']);

    $fresh = $call('POST', '/api/account/logout', $phone);

    $fresh['status'] === 200 && $tokens() === $before - 2
        ? $ok('and the second device can sign itself out too')
        : $bad('the second sign-out failed: HTTP ' . $fresh['status'] . ', ' . $tokens() . ' tokens left');
} finally {
    DB::rollBack();
    echo "\n(rolled back - no account, token or event kept)\n";
}

printf("\n%d CORRECT, %d WRONG\n", $correct, $wrong);
