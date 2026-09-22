<?php
/**
 * EVIDENCE — one person's account data is never another person's, on the server.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS, AND WHY IT PASSES
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * A live report: somebody edited their profile, signed out, signed in as a
 * colleague in the SAME organisation, and the colleague's profile screen showed
 * the first person's details.
 *
 * The fault was in the browser - `PreferencesProvider` fetched `/account/me` once
 * per page load and kept it across a sign-out. That is fixed in the frontend. This
 * file exists because that fix RESTS ON AN ASSUMPTION about the server: that
 * `/account/me` is strictly scoped to the token's owner, so refetching per user is
 * sufficient. If the server ever leaked across accounts, no amount of client-side
 * refetching would help.
 *
 * So this asserts the assumption rather than trusting it. Every check here is
 * expected to pass today; the value is that it will keep being run.
 *
 * ── THE TWO ACCOUNTS ARE IN THE SAME TENANT, DELIBERATELY ──────────────────
 *
 * Cross-TENANT isolation is already proven elsewhere (prove-idor.php and friends).
 * The reported bug was two people in ONE organisation - tenant 6, an employee and
 * an administrator - which is the harder case: every tenant-scoped guard in the
 * product passes both of them, so only per-USER scoping can tell them apart.
 *
 * Runs on DEV inside a transaction that is always rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/docs/organization-audit/_evidence/prove-account-isolation.php';"
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

        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode($response->getContent(), true),
        ];
    };

    $make = function (string $roleKey, string $label) use ($db, $tenant) {
        $profile = $db->table('tbluserprofilemaster')
            ->where('sub_institute_id', $tenant)->where('role_key', $roleKey)->first(['id']);

        if (!$profile) {
            return null;
        }

        $id = $db->table('tbluser')->insertGetId([
            'user_profile_id' => $profile->id,
            'sub_institute_id' => $tenant,
            'first_name' => 'Isolation',
            'last_name' => $label,
            'email' => 'isolation.' . $label . '@example.test',
            'mobile' => $label === 'one' ? '1111111111' : '2222222222',
            'password' => \Illuminate\Support\Facades\Hash::make('IsolationCheck123'),
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $id,
            'label' => $label,
            'token' => \App\Models\auth\tbluserModel::find($id)->createToken('Isolation laptop')->plainTextToken,
        ];
    };

    // An employee and an administrator, so no tenant-or-role guard can be what
    // separates them - only per-user scoping can.
    $one = $make('employee', 'one');
    $two = $make('administrator', 'two');

    if (!$one || !$two) {
        $bad("tenant $tenant lacks an employee or administrator profile - nothing below can run");
        DB::rollBack();
        printf("\n%d CORRECT, %d WRONG\n", $correct, $wrong);
        return;
    }

    // ── 1. EACH TOKEN SEES ITS OWN OWNER, AND NOBODY ELSE ───────────────────
    echo "══ 1. /account/me answers about the token's owner ══\n";

    $meOne = $call('GET', '/api/account/me', $one['token']);
    $meTwo = $call('GET', '/api/account/me', $two['token']);

    (int) ($meOne['body']['data']['profile']['id'] ?? 0) === $one['id']
        ? $ok('the first token returns the first account')
        : $bad('the first token returned id ' . var_export($meOne['body']['data']['profile']['id'] ?? null, true));

    (int) ($meTwo['body']['data']['profile']['id'] ?? 0) === $two['id']
        ? $ok('the second token returns the second account')
        : $bad('the second token returned id ' . var_export($meTwo['body']['data']['profile']['id'] ?? null, true));

    ($meOne['body']['data']['profile']['email'] ?? '') !== ($meTwo['body']['data']['profile']['email'] ?? '')
        ? $ok('and the two payloads carry different email addresses')
        : $bad('both tokens returned the same email - this is the reported bug, on the server');

    /*
     * There is no id parameter on this endpoint, so the only way to ask about
     * somebody else is to try. Asserted rather than assumed: a `user_id` the
     * controller happened to read would be the whole bug.
     */
    $spoofed = $call('GET', '/api/account/me', $one['token'], ['user_id' => $two['id'], 'id' => $two['id']]);

    (int) ($spoofed['body']['data']['profile']['id'] ?? 0) === $one['id']
        ? $ok('naming another user id in the request changes nothing')
        : $bad('a request parameter steered the subject - one account can read another');

    // ── 2. A WRITE LANDS ON THE WRITER'S ROW ONLY ───────────────────────────
    echo "\n══ 2. editing your profile edits only your own ══\n";

    $beforeTwo = $db->table('tbluser')->where('id', $two['id'])->first(['first_name', 'mobile', 'birthdate', 'address']);

    $call('PUT', '/api/account/profile', $one['token'], [
        'first_name' => 'Edited',
        'mobile' => '9999999999',
        'birthdate' => '1990-01-01',
        'address' => 'One Edited Street',
    ]);

    $afterOne = $db->table('tbluser')->where('id', $one['id'])->first(['first_name', 'mobile']);
    $afterTwo = $db->table('tbluser')->where('id', $two['id'])->first(['first_name', 'mobile', 'birthdate', 'address']);

    ($afterOne->first_name ?? null) === 'Edited' && ($afterOne->mobile ?? null) === '9999999999'
        ? $ok('the write reached the writer')
        : $bad('the write did not land: ' . json_encode($afterOne));

    /*
     * THE ASSERTION THE REPORT WAS ABOUT. Four fields, because the frontend bug
     * that prompted this could have carried exactly these - the photo-upload path
     * used to send every editable field from a form seeded with cached data.
     */
    $untouched = ($afterTwo->first_name ?? null) === ($beforeTwo->first_name ?? null)
        && ($afterTwo->mobile ?? null) === ($beforeTwo->mobile ?? null)
        && ($afterTwo->birthdate ?? null) === ($beforeTwo->birthdate ?? null)
        && ($afterTwo->address ?? null) === ($beforeTwo->address ?? null);

    $untouched
        ? $ok('and the other account is byte-for-byte unchanged')
        : $bad('the other account was modified: ' . json_encode($afterTwo) . ' was ' . json_encode($beforeTwo));

    // ── 3. PREFERENCES ARE PER-PERSON, INCLUDING THE IDENTITY FIELDS ────────
    //
    // display_name / pronouns / about are the exact values that appeared on the
    // wrong screen in the report, because they live in preferences rather than on
    // `tbluser` and the frontend cached them.
    echo "\n══ 3. display name, pronouns and about belong to one person ══\n";

    $call('PUT', '/api/account/preferences', $one['token'], ['display_name' => 'The First One']);

    $prefsOne = $call('GET', '/api/account/me', $one['token'])['body']['data']['preferences'] ?? [];
    $prefsTwo = $call('GET', '/api/account/me', $two['token'])['body']['data']['preferences'] ?? [];

    ($prefsOne['display_name'] ?? null) === 'The First One'
        ? $ok('the display name is stored for the person who set it')
        : $bad('the display name did not save: ' . var_export($prefsOne['display_name'] ?? null, true));

    ($prefsTwo['display_name'] ?? '') !== 'The First One'
        ? $ok('and the other account does NOT receive it')
        : $bad('one account is reading another account display name - this is the reported bug');

    /*
     * THE SAME DEVICE ID FOR BOTH. Two people on one shared browser send the same
     * `device_id`, so if any preference were keyed on the device alone rather than
     * on (user, device), they would share it. The only reason to believe otherwise
     * is to check.
     */
    $sharedDevice = '01SHAREDBROWSER0000000000';

    $call('PUT', '/api/account/preferences', $one['token'], ['theme' => 'dark', 'device_id' => $sharedDevice]);
    $call('PUT', '/api/account/preferences', $two['token'], ['theme' => 'light', 'device_id' => $sharedDevice]);

    $themeOne = $call('GET', '/api/account/me', $one['token'], ['device_id' => $sharedDevice])['body']['data']['preferences']['theme'] ?? null;
    $themeTwo = $call('GET', '/api/account/me', $two['token'], ['device_id' => $sharedDevice])['body']['data']['preferences']['theme'] ?? null;

    $themeOne === 'dark' && $themeTwo === 'light'
        ? $ok('a device-scoped preference is still per-person on a shared browser')
        : $bad("both got theme one=$themeOne two=$themeTwo - device-scoped preferences are shared between users");

    // ── 4. AND THE PHOTO IS NOT SHARED EITHER ───────────────────────────────
    echo "\n══ 4. the photo filename cannot collide between accounts ══\n";

    /*
     * `storeAvatar` names the file `<user id>_<24 random chars>.<ext>`. Both halves
     * matter: the id makes two people's photos distinguishable, the random part
     * makes two uploads by the SAME person distinguishable. Asserted on the naming
     * rule rather than by uploading, because a real upload needs the object store.
     */
    $source = file_get_contents((new ReflectionClass(\App\Http\Controllers\Api\Account\AccountController::class))->getFileName());

    str_contains($source, '$userId . \'_\' . \Illuminate\Support\Str::random(24)')
        ? $ok('the stored filename is prefixed with the owner id plus 24 random characters')
        : $bad('the avatar filename is not user-scoped - two accounts could overwrite each other');

    // ── 5. ENDING YOUR SESSIONS DOES NOT END ANYBODY ELSE'S ─────────────────
    echo "\n══ 5. signing out everywhere is scoped to one account ══\n";

    $othersTokens = $db->table('personal_access_tokens')
        ->where('tokenable_type', \App\Models\auth\tbluserModel::class)
        ->where('tokenable_id', $two['id'])
        ->count();

    $call('DELETE', '/api/account/sessions', $one['token']);

    $db->table('personal_access_tokens')
        ->where('tokenable_type', \App\Models\auth\tbluserModel::class)
        ->where('tokenable_id', $two['id'])
        ->count() === $othersTokens
        ? $ok('the other account keeps every one of its sessions')
        : $bad('one account signed another one out');
} finally {
    DB::rollBack();
    echo "\n(rolled back - no account, preference or token kept)\n";
}

printf("\n%d CORRECT, %d WRONG\n", $correct, $wrong);
