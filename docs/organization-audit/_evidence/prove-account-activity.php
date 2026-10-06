<?php
/**
 * EVIDENCE — a person can see their own security history, and only their own.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT WAS MISSING
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `g2g_audit_log` has recorded organisation events since it was built, and every
 * screen that reads it is an administrator's. Nothing showed somebody the events
 * about THEMSELVES - no "password changed", no "a new device signed in". Those are
 * the events a person is best placed to recognise as wrong, and they were the ones
 * nobody could see.
 *
 * The account's own actions were not even recorded: changing a password, replacing
 * a photo and ending a session left no trace anywhere. `EventRecorder` existed;
 * `AccountController` never used it.
 *
 * ── THE ASSERTION THAT MATTERS MOST ─────────────────────────────────────────
 *
 * An activity feed is a history of one person, and the failure that would matter
 * is showing somebody else's. There is no id parameter - the subject is the
 * token's owner - so this checks that two accounts acting at the same moment see
 * strictly their own rows, which is the only way to know the isolation is real
 * rather than incidental.
 *
 * ── AND THAT RECORDING CANNOT BREAK THE ACTION ──────────────────────────────
 *
 * Every event is recorded AFTER the change is committed. A password is already
 * written by the time the recorder runs, so a failure there must not report
 * failure for something that succeeded. Asserted by checking the action's own
 * response, not only the audit row.
 *
 * Runs on DEV inside a transaction that is always rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/docs/organization-audit/_evidence/prove-account-activity.php';"
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

        return $kernel->handle($request);
    };

    $profile = $db->table('tbluserprofilemaster')
        ->where('sub_institute_id', $tenant)->where('role_key', 'employee')->first(['id']);

    $make = function (string $label) use ($db, $tenant, $profile) {
        $id = $db->table('tbluser')->insertGetId([
            'user_profile_id' => $profile->id,
            'sub_institute_id' => $tenant,
            'first_name' => 'Activity',
            'last_name' => $label,
            'email' => 'activity.' . $label . '@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('ActivityCheck123'),
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $id,
            'token' => \App\Models\auth\tbluserModel::find($id)->createToken('Evidence laptop')->plainTextToken,
        ];
    };

    $me = $make('mine');
    $other = $make('theirs');

    $entries = function (string $token) use ($call) {
        $body = json_decode($call('GET', '/api/account/activity', $token)->getContent(), true);

        return $body['data']['entries'] ?? null;
    };

    // ── 1. A SIGN-IN IS VISIBLE, WITH THE DEVICE ────────────────────────────
    echo "══ 1. the history includes signing in, and says from what ══\n";

    $mine = $entries($me['token']);

    is_array($mine) && count($mine) > 0
        ? $ok('the endpoint returns entries')
        : $bad('the endpoint returned nothing: ' . json_encode($mine));

    $signIn = collect($mine ?? [])->firstWhere('kind', 'sign_in');

    $signIn
        ? $ok('a sign-in entry is present')
        : $bad('no sign-in entry - the history is only half of one');

    ($signIn['device'] ?? null) === 'Evidence laptop'
        ? $ok('and it names the device rather than saying "api-token"')
        : $bad('the sign-in has no device name: ' . json_encode($signIn['device'] ?? null));

    // ── 2. THE ACCOUNT'S OWN ACTIONS ARE RECORDED ───────────────────────────
    echo "\n══ 2. changing a password leaves a trace, and still succeeds ══\n";

    $response = $call('POST', '/api/account/password', $me['token'], [
        'current_password' => 'ActivityCheck123',
        'password' => 'ActivityCheck456',
        'password_confirmation' => 'ActivityCheck456',
    ]);

    $response->getStatusCode() === 200
        ? $ok('the password change itself succeeded')
        : $bad('the change returned HTTP ' . $response->getStatusCode() . ' - recording broke the action');

    /*
     * `g2g_event`, not `g2g_audit_log`.
     *
     * The audit log is a PROJECTION built by the scheduled `events:project`
     * command, so it is empty at this instant however correct the recording is -
     * this assertion failed for exactly that reason and was measuring the
     * scheduler, not the product. `g2g_event` is what `EventRecorder` writes.
     */
    $recorded = $db->table('g2g_event')
        ->where('actor_id', $me['id'])
        ->where('type', 'account.password_changed')
        ->exists();

    $recorded
        ? $ok('and it was recorded as account.password_changed')
        : $bad('the password change left no trace, as before');

    // ── 3. AND ONLY THE OWNER SEES IT ───────────────────────────────────────
    //
    // The assertion that matters. Changing the password ended the other sessions
    // of THIS account, so a fresh token is minted to read the history back.
    echo "\n══ 3. one person's history, and never another's ══\n";

    $freshToken = \App\Models\auth\tbluserModel::find($me['id'])
        ->createToken('Evidence laptop')->plainTextToken;

    $mineNow = $entries($freshToken);
    $theirs = $entries($other['token']);

    $mineHasPassword = collect($mineNow ?? [])->contains(fn ($e) => $e['type'] === 'account.password_changed');
    $theirsHasPassword = collect($theirs ?? [])->contains(fn ($e) => $e['type'] === 'account.password_changed');

    $mineHasPassword
        ? $ok('the owner sees their own password change')
        : $bad('the owner cannot see their own event');

    !$theirsHasPassword
        ? $ok('the other account does NOT see it')
        : $bad('one account is reading the security history of another');

    is_array($theirs) && count($theirs) > 0
        ? $ok('and still sees its own entries, so nothing was over-filtered')
        : $bad('the other account sees nothing at all - the filter is too broad');

    // ── 4. THE LIMIT IS CLAMPED ─────────────────────────────────────────────
    echo "\n══ 4. a caller cannot ask for an unbounded response ══\n";

    $body = json_decode($call('GET', '/api/account/activity', $freshToken, ['limit' => 100000])->getContent(), true);
    $count = count($body['data']['entries'] ?? []);

    $count <= 200
        ? $ok("a request for 100000 returned $count - clamped")
        : $bad("a request for 100000 returned $count rows");

    $bodyLow = json_decode($call('GET', '/api/account/activity', $freshToken, ['limit' => -5])->getContent(), true);

    isset($bodyLow['data'])
        ? $ok('and a negative limit is handled rather than erroring')
        : $bad('a negative limit broke the endpoint');

    // ── 5. ENDING A SESSION IS RECORDED TOO ─────────────────────────────────
    echo "\n══ 5. ending sessions is recorded, with how many ══\n";

    \App\Models\auth\tbluserModel::find($me['id'])->createToken('Second device');
    $call('DELETE', '/api/account/sessions', $freshToken);

    $row = $db->table('g2g_event')
        ->where('actor_id', $me['id'])
        ->where('type', 'account.sessions_ended')
        ->orderByDesc('id')
        ->first(['payload']);

    if (!$row) {
        $bad('ending sessions left no trace');
    } else {
        $payload = json_decode((string) $row->payload, true);

        isset($payload['sessions_ended'])
            ? $ok('recorded, carrying how many devices were signed out')
            : $bad('recorded without the count: ' . (string) $row->payload);
    }
    // ── 6. THE NEW-DEVICE ALERT ─────────────────────────────────────────────
    //
    // The one security email worth sending, and the one most easily made useless:
    // an alert that fires on EVERY sign-in is an alert people filter, after which
    // it warns nobody about anything. So both halves are asserted - it fires for a
    // device never seen, and it stays silent for one already known.
    echo "
== 6. a new device is alerted; a familiar one is not ==
";

    $alerts = fn (int $userId) => $db->table('g2g_event')
        ->where('actor_id', $userId)
        ->where('type', 'account.new_device')
        ->count();

    /*
     * ── WHY THE RULE IS EXERCISED DIRECTLY AND NOT THROUGH /login ───────────
     *
     * `/login` is a WEB route. CSRF is skipped only for a request that is both
     * `type=API` AND carries a valid Sanctum token - and a sign-in, by definition,
     * has no token yet. Driving it through the kernel returns 419, which is the
     * framework working correctly and says nothing about this feature.
     *
     * So the DECISION is tested here - does a never-seen label alert, and does a
     * familiar one stay quiet - and the WIRING is asserted in
     * `check-settings-guards.sh`, which requires both sign-in paths to call
     * `alertOnNewDevice`. Between them they cover what one HTTP call would have,
     * without asserting anything about CSRF.
     */
    $invoke = function (int $userId, string $label) use ($tenant) {
        $controller = new \App\Http\Controllers\auth\authController();
        $method = new ReflectionMethod($controller, 'alertOnNewDevice');
        $method->setAccessible(true);
        $method->invoke($controller, $userId, $label, $tenant);
    };

    $mint = fn (int $userId, string $label) => \App\Models\auth\tbluserModel::find($userId)->createToken($label);

    $before = $alerts($me['id']);

    // A label this account has never carried. The token exists first, exactly as
    // it does at sign-in.
    $mint($me['id'], 'Safari on iPhone');
    $invoke($me['id'], 'Safari on iPhone');
    $afterNew = $alerts($me['id']);

    $afterNew > $before
        ? $ok('a device never seen before raises account.new_device')
        : $bad('a brand-new device raised no alert');

    // The SAME label again. Two tokens carry it now, so it is familiar.
    $mint($me['id'], 'Safari on iPhone');
    $invoke($me['id'], 'Safari on iPhone');

    $alerts($me['id']) === $afterNew
        ? $ok('and signing in again from it raises nothing')
        : $bad('every sign-in alerts - people filter these and miss the real one');

    /*
     * A first-ever device on a fresh account must not alert either. There is
     * nothing suspicious about somebody's first login, and mailing them about it
     * is how a product teaches people to ignore the alerts that matter.
     */
    $fresh = $make('firstever');
    $freshBefore = $alerts($fresh['id']);
    $invoke($fresh['id'], 'Evidence laptop');

    $alerts($fresh['id']) === $freshBefore
        ? $ok('and a brand-new account is not alerted about its first device')
        : $bad('the first ever sign-in alerts, which trains people to ignore these');



} finally {
    DB::rollBack();
    echo "\n(rolled back - no account, token or event kept)\n";
}

printf("\n%d CORRECT, %d WRONG\n", $correct, $wrong);
