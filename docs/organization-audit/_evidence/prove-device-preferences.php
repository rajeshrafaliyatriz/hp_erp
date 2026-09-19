<?php
/**
 * F-171 EVIDENCE — preferences that belong to a device, not just an account.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE COMPLAINT THIS ANSWERS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * "When any user refreshes the tab all settings vanish - but it should be saved
 * based on their personal setup, based on their devices."
 *
 * Two halves. The vanishing was an interaction bug, fixed on the frontend. This
 * script is about the other half: a laptop and a phone should be able to disagree
 * about the theme, and clearing site data on one of them must not drop somebody
 * back to factory defaults.
 *
 * ── THE RULE BEING PROVED ───────────────────────────────────────────────────
 *
 *     device row  →  account row  →  the product's default
 *
 * and only THREE keys are device-scoped. A date format is a property of the
 * person; making it per-machine would mean setting it again on every laptop.
 *
 * ── WHAT IS PROVED ──────────────────────────────────────────────────────────
 *
 *   1. Two devices hold different themes at the same time, for one person.
 *   2. A device with no opinion inherits the account default - which is what
 *      makes CLEARING SITE DATA survivable rather than destructive.
 *   3. Account-scoped keys ignore the device entirely: setting a date format on
 *      one laptop changes it on all of them.
 *   4. Notification opt-outs are never device-scoped, and `wantsEmail()` - which
 *      a background job calls with no device at all - reads the right rows.
 *   5. "Use on all my devices" promotes without disturbing a device that has
 *      its own answer.
 *   6. "Forget this browser" falls back to the account default and refuses to
 *      run against the account scope itself.
 *   7. A malformed or absent device id degrades to the account scope, so an old
 *      client keeps working.
 *   8. One person's device rows are invisible to another person.
 *
 * Everything runs inside a transaction that is ALWAYS rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-device-preferences.php';"
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

\Illuminate\Support\Facades\Mail::fake();

$tenant = 6;

$people = collect($db->table('tbluser')->where('sub_institute_id', $tenant)->where('status', 1)
    ->whereNotNull('email')->limit(2)->get(['id', 'email']));

$me = $people[0];
$other = $people[1];

$LAPTOP = '01JBXQZ9LAPTOPAAAAAAAAAAAA';
$PHONE = '01JBXQZ9PHONEBBBBBBBBBBBBB';

printf("acting as #%d (%s); the other person is #%d\n", $me->id, $me->email, $other->id);
printf("devices: laptop=%s phone=%s\n\n", substr($LAPTOP, -8), substr($PHONE, -8));

DB::beginTransaction();

try {
    $meUser = \App\Models\auth\tbluserModel::find($me->id);
    $otherUser = \App\Models\auth\tbluserModel::find($other->id);

    $token = $meUser->createToken('device-ev')->plainTextToken;
    $otherToken = $otherUser->createToken('device-ev')->plainTextToken;

    $call = function (string $method, string $uri, array $body = [], ?string $tok = null) use ($kernel, $token) {
        $tok = $tok ?? $token;
        $request = \Illuminate\Http\Request::create($uri, $method, $body + ['type' => 'API', 'token' => $tok]);
        $request->headers->set('Authorization', 'Bearer ' . $tok);
        $request->headers->set('Accept', 'application/json');

        return $kernel->handle($request);
    };

    $themeOn = function (?string $device) use ($call) {
        $body = json_decode($call('GET', '/api/account/me', $device ? ['device_id' => $device] : [])->getContent(), true);

        return $body['data']['preferences']['theme'] ?? '(none)';
    };

    // Start clean so every reading below is caused by this script.
    $db->table('user_preferences')->where('user_id', $me->id)->delete();
    $db->table('user_preferences')->where('user_id', $other->id)->delete();

    // ── 1. TWO DEVICES, TWO ANSWERS ─────────────────────────────────────────
    echo "══ 1. one person, two devices, two different themes ══\n";

    $call('PUT', '/api/account/preferences', ['device_id' => $LAPTOP, 'theme' => 'dark']);
    $call('PUT', '/api/account/preferences', ['device_id' => $PHONE, 'theme' => 'light']);

    printf("  laptop sees            : %-8s %s\n", $themeOn($LAPTOP),
        $themeOn($LAPTOP) === 'dark' ? 'CORRECT' : 'WRONG');
    printf("  phone sees             : %-8s %s\n", $themeOn($PHONE),
        $themeOn($PHONE) === 'light' ? 'CORRECT - the two do not overwrite each other' : 'WRONG');

    $rows = $db->table('user_preferences')->where('user_id', $me->id)->where('pref_key', 'theme')->count();
    printf("  theme rows stored      : %-8d %s\n", $rows,
        $rows === 2 ? 'CORRECT - one per device' : 'WRONG - expected 2');

    // ── 2. A THIRD DEVICE, AND THE CLEARED-DATA CASE ────────────────────────
    echo "\n══ 2. a device with no opinion falls back to the account default ══\n";

    printf("  a brand-new device sees: %-8s %s\n", $themeOn('01JBXQZ9BRANDNEWCCCCCCCCCC'),
        $themeOn('01JBXQZ9BRANDNEWCCCCCCCCCC') === 'system'
            ? 'CORRECT - the product default, since no account default is set yet' : 'WRONG');

    // Now set an account default and re-ask.
    $call('POST', '/api/account/preferences/promote', ['device_id' => $PHONE]);

    printf("  after promoting phone  : new device sees %-8s %s\n", $themeOn('01JBXQZ9BRANDNEWCCCCCCCCCC'),
        $themeOn('01JBXQZ9BRANDNEWCCCCCCCCCC') === 'light'
            ? 'CORRECT - inherits the account default' : 'WRONG');

    /*
     * THE CLEARED-SITE-DATA CASE, which is the one the customer actually hit.
     * A browser whose storage was wiped sends NO device id. It must land on the
     * account default, not on a factory default.
     */
    printf("  cleared browser (no id): %-8s %s\n", $themeOn(null),
        $themeOn(null) === 'light'
            ? 'CORRECT - falls back to the account default, not to factory'
            : 'WRONG - a data clear reset them');

    // ── 5. PROMOTION DOES NOT DISTURB A DEVICE WITH ITS OWN ANSWER ──────────
    echo "\n══ 3. promoting does not overwrite a device that has chosen ══\n";

    printf("  laptop still           : %-8s %s\n", $themeOn($LAPTOP),
        $themeOn($LAPTOP) === 'dark'
            ? 'CORRECT - its own row still wins' : 'WRONG - promotion clobbered it');

    // ── 3. ACCOUNT-SCOPED KEYS IGNORE THE DEVICE ────────────────────────────
    echo "\n══ 4. a date format is a property of the person, not the laptop ══\n";

    $call('PUT', '/api/account/preferences', ['device_id' => $LAPTOP, 'date_format' => 'yyyy-mm-dd']);

    $fmt = fn (?string $d) => json_decode($call('GET', '/api/account/me', $d ? ['device_id' => $d] : [])
        ->getContent(), true)['data']['preferences']['date_format'];

    printf("  set on the laptop      : laptop=%s phone=%s no-device=%s\n", $fmt($LAPTOP), $fmt($PHONE), $fmt(null));
    printf("  every device agrees    : %s\n",
        $fmt($LAPTOP) === 'yyyy-mm-dd' && $fmt($PHONE) === 'yyyy-mm-dd' && $fmt(null) === 'yyyy-mm-dd'
            ? 'CORRECT - stored once, at the account scope' : 'WRONG - it was scoped to a device');

    $scoped = $db->table('user_preferences')->where('user_id', $me->id)
        ->where('pref_key', 'date_format')->pluck('device_id')->all();

    printf("  and its device_id is   : '%s'  %s\n", implode("','", $scoped),
        $scoped === [''] ? 'CORRECT - the account scope' : 'WRONG');

    // ── 4. NOTIFICATIONS ARE ACCOUNT-WIDE, AND wantsEmail AGREES ────────────
    echo "\n══ 5. muting an event on one device mutes it everywhere ══\n";

    $call('PUT', '/api/account/preferences', [
        'device_id' => $LAPTOP,
        'notify_events' => ['task.rejected' => false],
    ]);

    $muted = fn (?string $d) => json_decode($call('GET', '/api/account/me', $d ? ['device_id' => $d] : [])
        ->getContent(), true)['data']['preferences']['notify_events']['task.rejected'];

    printf("  laptop=%s phone=%s  %s\n", var_export($muted($LAPTOP), true), var_export($muted($PHONE), true),
        $muted($LAPTOP) === false && $muted($PHONE) === false
            ? 'CORRECT - being emailed is about the person' : 'WRONG - one device disagrees');

    /*
     * `wantsEmail()` is what NotificationSender calls, from a job, with no device
     * in scope at all. If the mute had been stored per device it would read the
     * wrong rows and email somebody who opted out.
     */
    $prefs = app(\App\Services\Account\UserPreferences::class);

    printf("  wantsEmail() (no device): %s  %s\n",
        var_export($prefs->wantsEmail((int) $me->id, 'task.rejected'), true),
        $prefs->wantsEmail((int) $me->id, 'task.rejected') === false
            ? 'CORRECT - the send path honours it' : 'WRONG - the job would email them anyway');

    // ── 6. FORGETTING A DEVICE ──────────────────────────────────────────────
    echo "\n══ 6. a browser can be told to follow the account again ══\n";

    $r = $call('DELETE', '/api/account/preferences/device', ['device_id' => $LAPTOP]);

    printf("  forget the laptop      : HTTP %d, it now sees %-8s %s\n",
        $r->getStatusCode(), $themeOn($LAPTOP),
        $themeOn($LAPTOP) === 'light' ? 'CORRECT - back to the account default' : 'WRONG');

    printf("  phone unaffected       : %-8s %s\n", $themeOn($PHONE),
        $themeOn($PHONE) === 'light' ? 'CORRECT' : 'WRONG');

    $r = $call('DELETE', '/api/account/preferences/device', []);
    $stillThere = $db->table('user_preferences')->where('user_id', $me->id)->where('device_id', '')->count();

    printf("  forget with NO device  : HTTP %d, account rows kept: %d  %s\n",
        $r->getStatusCode(), $stillThere,
        $r->getStatusCode() === 422 && $stillThere > 0
            ? 'CORRECT - refused; it would have wiped the defaults' : 'WRONG');

    // ── 7. A MALFORMED DEVICE ID DEGRADES SAFELY ────────────────────────────
    echo "\n══ 7. a bad or missing device id is treated as absent, not rejected ══\n";

    foreach ([
        'too long' => str_repeat('x', 60),
        'has spaces' => 'my laptop',
        'has punctuation' => "dark'; DROP TABLE--",
    ] as $why => $bad) {
        $r = $call('GET', '/api/account/me', ['device_id' => $bad]);
        $t = json_decode($r->getContent(), true)['data']['preferences']['theme'] ?? '(none)';
        $isDevice = json_decode($r->getContent(), true)['data']['device_scope']['is_device'] ?? null;

        printf("  %-16s -> HTTP %d, theme %-8s device-scoped=%-5s %s\n", $why, $r->getStatusCode(), $t,
            var_export($isDevice, true),
            $r->getStatusCode() === 200 && $isDevice === false
                ? 'CORRECT - account scope' : 'WRONG');
    }

    // ── 8. TENANCY / OWNERSHIP ──────────────────────────────────────────────
    echo "\n══ 8. one person's device rows are invisible to another ══\n";

    $call('PUT', '/api/account/preferences', ['device_id' => $LAPTOP, 'theme' => 'dark']);

    // The colleague sends the SAME device id - a shared machine, or a guess.
    $theirs = json_decode($call('GET', '/api/account/me', ['device_id' => $LAPTOP], $otherToken)
        ->getContent(), true)['data']['preferences']['theme'];

    printf("  colleague on the same device id sees: %-8s %s\n", $theirs,
        $theirs === 'system'
            ? 'CORRECT - rows are keyed by user first; a device id names a machine, not a person'
            : 'WRONG - read across accounts');

    // ── 9. THE SESSION LIST NAMES THE DEVICE ────────────────────────────────
    echo "
══ 9. signing in names the token after the device, not 'api-token' ══
";

    /*
     * All 4,955 access tokens on live are called `api-token`, so the "Where you
     * are signed in" list - whose entire job is to let somebody recognise a
     * session and end the ones they do not know - was a wall of identical rows.
     * Nothing to audit, nothing to act on.
     */
    $db->table('tbluser')->where('id', $me->id)
        ->update(['password' => \Illuminate\Support\Facades\Hash::make('DeviceCheck123')]);

    $agents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36' => 'Chrome on Windows 10 or 11',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile Safari/604.1' => 'Safari on iPhone',
    ];

    foreach ($agents as $ua => $expected) {
        $req = \Illuminate\Http\Request::create('/login', 'GET', [
            'email' => $me->email,
            'password' => 'DeviceCheck123',
            'type' => 'API',
        ]);
        $req->headers->set('User-Agent', $ua);
        $req->headers->set('Accept', 'application/json');

        $code = $kernel->handle($req)->getStatusCode();

        $newest = $db->table('personal_access_tokens')
            ->where('tokenable_id', $me->id)
            ->orderByDesc('id')
            ->value('name');

        printf("  sign-in HTTP %d -> token named '%s'
    %s
", $code, $newest,
            $newest === $expected
                ? 'CORRECT - the session list can tell these apart'
                : "WRONG - expected '" . $expected . "'");
    }

    $names = $db->table('personal_access_tokens')->where('tokenable_id', $me->id)
        ->distinct()->pluck('name');

    printf("  distinct names on this account : %d  %s
", $names->count(),
        $names->count() >= 2 ? 'CORRECT - no longer one label for everything' : 'WRONG');

} finally {
    DB::rollBack();

    foreach ([$me->id, $other->id] as $id) {
        \App\Models\auth\tbluserModel::find($id)?->tokens()->where('name', 'device-ev')->delete();
    }

    echo "\n(rolled back - no preference or token kept)\n";
}
