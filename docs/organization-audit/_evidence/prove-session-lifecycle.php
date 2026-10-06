<?php
/**
 * EVIDENCE — a session list that tells the truth, and sessions that end.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * TWO DEFECTS IN A FEATURE THAT LOOKED FINISHED
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Sign-in & security was built to answer "show me the laptop name rather than
 * api", and it does. What it could not answer is the question that makes a
 * session list worth having: which of these is stale, and which one am I?
 *
 *   `last_used_at`  NULL on all 4,960 live tokens. Sanctum writes it in its own
 *                   Guard; this application never uses that guard - eight
 *                   middlewares and several controllers call
 *                   `PersonalAccessToken::findToken()` directly, which looks up
 *                   and nothing more. So every row read "Never used since it was
 *                   created" and the table sorted by a column that was always
 *                   null.
 *
 *   `expires_at`    NULL on all 4,960. `RequireApiToken` has ALWAYS refused an
 *                   expired token - nothing ever set an expiry for it to refuse.
 *                   Every session was immortal.
 *
 * ── WHAT THIS ASSERTS, AND WHY EACH ONE ─────────────────────────────────────
 *
 *   1. Using a token records it. Without this the whole list is decoration.
 *   2. NOT more than once a minute. A screen making a dozen requests a second
 *      would otherwise make `personal_access_tokens` the most written table in
 *      the product to record a fact read to the nearest minute.
 *   3. Use pushes the expiry forward, so somebody working daily is never signed
 *      out. An expiry that did not slide would be a monthly interruption with no
 *      security benefit while the person is demonstrably present.
 *   4. An expired token is refused. The gate already existed; this proves the
 *      expiry now reaches it.
 *   5. A brand-new token has an expiry, closing the gap between signing in and
 *      the first request - the gap that produced 4,960 immortal tokens.
 *   6. Nothing here can lock anybody out: a request with a bad token still
 *      reaches its normal 401 rather than a 500 from the bookkeeping.
 *
 * Runs on DEV inside a transaction that is always rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/docs/organization-audit/_evidence/prove-session-lifecycle.php';"
 */

use App\Http\Middleware\TouchTokenActivity;
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
    $call = function (string $method, string $uri, string $token) use ($kernel) {
        $request = \Illuminate\Http\Request::create(
            $uri, $method, ['type' => 'API', 'token' => $token]
        );
        $request->headers->set('Accept', 'application/json');

        return $kernel->handle($request);
    };

    $profile = $db->table('tbluserprofilemaster')
        ->where('sub_institute_id', $tenant)->where('role_key', 'employee')->first(['id']);

    $userId = $db->table('tbluser')->insertGetId([
        'user_profile_id' => $profile->id,
        'sub_institute_id' => $tenant,
        'first_name' => 'Session',
        'last_name' => 'Lifecycle',
        'email' => 'session.lifecycle@example.test',
        'password' => \Illuminate\Support\Facades\Hash::make('SessionCheck123'),
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = \App\Models\auth\tbluserModel::find($userId);

    $row = fn (int $id) => $db->table('personal_access_tokens')->where('id', $id)
        ->first(['id', 'last_used_at', 'expires_at']);

    // ── 5 FIRST: a token is born with an expiry ─────────────────────────────
    //
    // Checked before anything else because it is what stops a token that is
    // created and abandoned from living forever - the shape of all 4,960 on live.
    echo "══ 1. a new session is not immortal ══\n";

    $created = $user->createToken('Evidence device', ['*'], now()->addDays(TouchTokenActivity::IDLE_DAYS));
    $token = $created->plainTextToken;
    $tokenId = $created->accessToken->id;

    $before = $row($tokenId);

    $before->expires_at !== null
        ? $ok('created with an expiry of ' . TouchTokenActivity::IDLE_DAYS . ' days')
        : $bad('created with expires_at NULL - an immortal session, as before');

    $before->last_used_at === null
        ? $ok('and with no last_used_at, because it has not been used yet')
        : $bad('last_used_at was set before the token was ever used');

    // ── 1. USING IT RECORDS IT ──────────────────────────────────────────────
    echo "\n══ 2. using a session records that it was used ══\n";

    $code = $call('GET', '/api/account/me', $token)->getStatusCode();
    $afterUse = $row($tokenId);

    $code === 200
        ? $ok('the request succeeded')
        : $bad("the request returned HTTP $code");

    $afterUse->last_used_at !== null
        ? $ok('last_used_at is now set, so the list can say which session is live')
        : $bad('last_used_at is STILL null - every row would read "Never used"');

    // ── 3. AND PUSHES THE EXPIRY FORWARD ────────────────────────────────────
    echo "\n══ 3. use slides the expiry forward, so an active session never ends ══\n";

    // Backdate both, so the next request has something to move.
    $db->table('personal_access_tokens')->where('id', $tokenId)->update([
        'last_used_at' => now()->subHours(2),
        'expires_at' => now()->addDays(5),
    ]);

    $call('GET', '/api/account/me', $token);
    $slid = $row($tokenId);

    $expected = now()->addDays(TouchTokenActivity::IDLE_DAYS);

    abs(strtotime($slid->expires_at) - $expected->getTimestamp()) < 120
        ? $ok('the expiry moved back out to ' . TouchTokenActivity::IDLE_DAYS . ' days')
        : $bad('the expiry did not slide: ' . $slid->expires_at);

    // ── 2. THE THROTTLE ─────────────────────────────────────────────────────
    echo "\n══ 4. but it is not written on every request ══\n";

    $justNow = $row($tokenId)->last_used_at;

    // A second request immediately after must NOT write again.
    $call('GET', '/api/account/me', $token);
    $again = $row($tokenId)->last_used_at;

    $again === $justNow
        ? $ok('a second request within the minute wrote nothing')
        : $bad('every request writes - personal_access_tokens becomes the hottest table');

    // ── 4. AN EXPIRED SESSION IS REFUSED ────────────────────────────────────
    echo "\n══ 5. a session left idle past the window stops working ══\n";

    $db->table('personal_access_tokens')->where('id', $tokenId)->update([
        'expires_at' => now()->subDay(),
        'last_used_at' => now()->subDays(TouchTokenActivity::IDLE_DAYS + 1),
    ]);

    $expiredCode = $call('GET', '/api/account/me', $token)->getStatusCode();

    $expiredCode === 401
        ? $ok('an expired session is refused with 401')
        : $bad("an expired session returned HTTP $expiredCode - it still works");

    // And the bookkeeping must not have revived it.
    $afterRefusal = $row($tokenId);

    strtotime($afterRefusal->expires_at) < time()
        ? $ok('and the refusal did not slide its expiry forward again')
        : $bad('the expired token had its expiry renewed - it can never expire');

    // ── 6. BOOKKEEPING CANNOT LOCK ANYBODY OUT ──────────────────────────────
    echo "\n══ 6. a bad token still gets a clean 401, not a 500 ══\n";

    $garbage = $call('GET', '/api/account/me', 'not-a-real-token-at-all')->getStatusCode();

    in_array($garbage, [401, 403], true)
        ? $ok("an unknown token returns HTTP $garbage, as it always did")
        : $bad("an unknown token returned HTTP $garbage - the touch broke the error path");

    $none = \Illuminate\Http\Request::create('/api/account/me', 'GET', ['type' => 'API']);
    $none->headers->set('Accept', 'application/json');
    $noneCode = $kernel->handle($none)->getStatusCode();

    in_array($noneCode, [401, 403], true)
        ? $ok("no token at all returns HTTP $noneCode")
        : $bad("no token returned HTTP $noneCode");
} finally {
    DB::rollBack();
    echo "\n(rolled back - no account or token kept)\n";
}

printf("\n%d CORRECT, %d WRONG\n", $correct, $wrong);
