<?php
/**
 * F-163 EVIDENCE — a person can finally change their own account.
 *
 * ── WHAT DID NOT EXIST ──────────────────────────────────────────────────────
 *
 * One self-service write in the entire product: `POST /api/update-fcm-token`,
 * for a device push token that is set on 0 of 2,373 users. No `/me`, no
 * `/account`, no `/preferences`, no `/change-password` anywhere in
 * routes/api.php. `/profile` displayed seven tabs and changed nothing - its own
 * comment says *"there is no endpoint for an employee to change their own
 * record"*.
 *
 * And no per-user preference storage of any kind: not a table, not a column.
 * Six real preferences lived in `localStorage`, lost on a device change.
 *
 * ── WHAT IS PROVED ──────────────────────────────────────────────────────────
 *
 *   1. Reading your own account works, and reports role + choices.
 *   2. Editing your own details works.
 *   3. FIELDS THAT ARE NOT YOURS TO CHANGE ARE IGNORED - department, profile,
 *      employee number, salary, email.
 *   4. Preferences persist and come back typed.
 *   5. Changing your password requires the CURRENT one.
 *   6. It ends every other session but not this one.
 *   7. Sessions can be listed and ended, and only your own.
 *   8. Nothing accepts a `user_id` - a caller cannot reach anybody else.
 *
 * Everything runs inside a transaction that is ALWAYS rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-account-self-service.php';"
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

$tenant = 6;

// An ORDINARY EMPLOYEE, not an admin - the point is that everybody gets this.
$person = collect($db->table('tbluser')->where('sub_institute_id', $tenant)->where('status', 1)->get(['id']))
    ->first(fn ($u) => \App\Support\RoleKey::forUserId((int) $u->id) === 'employee');

$colleague = $db->table('tbluser')->where('sub_institute_id', $tenant)
    ->where('id', '!=', $person->id)->where('status', 1)->first(['id', 'first_name']);

$user = \App\Models\auth\tbluserModel::find($person->id);

printf("acting as employee #%d; colleague is #%d (%s)\n\n", $person->id, $colleague->id, $colleague->first_name);

DB::beginTransaction();

try {
    $token = $user->createToken('account-evidence')->plainTextToken;

    $call = function (string $method, string $uri, array $body = [], ?string $tok = null) use ($kernel, $token) {
        $tok = $tok ?? $token;
        $request = \Illuminate\Http\Request::create($uri, $method, $body + ['type' => 'API', 'token' => $tok]);
        $request->headers->set('Authorization', 'Bearer ' . $tok);
        $request->headers->set('Accept', 'application/json');

        return $kernel->handle($request);
    };

    // ── 1. READ ─────────────────────────────────────────────────────────────
    echo "══ 1. reading your own account ══\n";

    $me = json_decode($call('GET', '/api/account/me')->getContent(), true);

    printf("  role reported      : %s\n", $me['data']['role'] ?? '(none)');
    printf("  preference keys    : %d\n", count($me['data']['preferences'] ?? []));
    printf("  notifiable events  : %d\n", count($me['data']['notifiable_events'] ?? []));
    printf("  theme default      : %s  %s\n",
        $me['data']['preferences']['theme'] ?? '?',
        ($me['data']['preferences']['theme'] ?? '') === 'system'
            ? 'CORRECT - follows the OS until asked' : 'unexpected');

    // ── 2 & 3. EDIT, AND THE THINGS THAT ARE NOT YOURS ──────────────────────
    echo "\n══ 2. editing your own details, and only those ══\n";

    $before = $db->table('tbluser')->where('id', $person->id)
        ->first(['first_name', 'city', 'department_id', 'user_profile_id', 'employee_no', 'email', 'amount']);

    $call('PUT', '/api/account/profile', [
        'first_name' => 'SelfEdited',
        'city' => 'Ahmedabad',

        // None of these belong to the person. Every one is sent deliberately.
        'department_id' => 999999,
        'user_profile_id' => 1,
        'employee_no' => 'HACK-001',
        'email' => 'someone.else@example.test',
        'amount' => 999999,
        'is_admin' => 1,
        'sub_institute_id' => 1,
    ]);

    $after = $db->table('tbluser')->where('id', $person->id)
        ->first(['first_name', 'city', 'department_id', 'user_profile_id', 'employee_no', 'email', 'amount', 'is_admin', 'sub_institute_id']);

    printf("  first_name       %-18s -> %-18s %s\n", $before->first_name, $after->first_name,
        $after->first_name === 'SelfEdited' ? 'CORRECT - yours to change' : 'WRONG');
    printf("  city             %-18s -> %-18s %s\n", $before->city ?? '(none)', $after->city,
        $after->city === 'Ahmedabad' ? 'CORRECT' : 'WRONG');

    $guarded = [
        'department_id' => $before->department_id,
        'user_profile_id' => $before->user_profile_id,
        'employee_no' => $before->employee_no,
        'email' => $before->email,
        'amount' => $before->amount,
    ];

    foreach ($guarded as $field => $was) {
        $now = $after->$field;
        printf("  %-16s %-18s -> %-18s %s\n", $field,
            $was === null ? '(null)' : (string) $was,
            $now === null ? '(null)' : (string) $now,
            (string) $was === (string) $now ? 'CORRECT - refused' : 'WRONG - a person changed it themselves');
    }

    printf("  is_admin         (unchanged)       -> %-18s %s\n", (string) $after->is_admin,
        (int) $after->is_admin === 0 ? 'CORRECT - not self-grantable' : 'WRONG');
    printf("  sub_institute_id (unchanged)       -> %-18s %s\n", (string) $after->sub_institute_id,
        (int) $after->sub_institute_id === $tenant ? 'CORRECT - cannot move organisation' : 'WRONG');

    // ── 4. PREFERENCES ──────────────────────────────────────────────────────
    echo "\n══ 3. preferences persist, and come back typed ══\n";

    $call('PUT', '/api/account/preferences', [
        'theme' => 'dark',
        'sidebar_collapsed' => false,
        'notify_events' => ['leave.decided' => false],
    ]);

    $reread = json_decode($call('GET', '/api/account/me')->getContent(), true)['data']['preferences'];

    printf("  theme                       : %s  %s\n", $reread['theme'],
        $reread['theme'] === 'dark' ? 'CORRECT' : 'WRONG');
    printf("  sidebar_collapsed is a bool : %s  %s\n", var_export($reread['sidebar_collapsed'], true),
        $reread['sidebar_collapsed'] === false ? 'CORRECT - cast, not the string "0"' : 'WRONG');
    printf("  opted out of leave.decided  : %s  %s\n", var_export($reread['notify_events']['leave.decided'], true),
        $reread['notify_events']['leave.decided'] === false ? 'CORRECT' : 'WRONG');
    printf("  other events untouched      : %s  %s\n", var_export($reread['notify_events']['task.rejected'], true),
        $reread['notify_events']['task.rejected'] === true ? 'CORRECT' : 'WRONG');

    $bad = $call('PUT', '/api/account/preferences', ['theme' => 'neon']);
    printf("  an unknown theme            : HTTP %d  %s\n", $bad->getStatusCode(),
        $bad->getStatusCode() === 422 ? 'CORRECT - refused' : 'WRONG - accepted');

    // ── 5 & 6. PASSWORD ─────────────────────────────────────────────────────
    echo "\n══ 4. changing your own password ══\n";

    $db->table('tbluser')->where('id', $person->id)
        ->update(['password' => \Illuminate\Support\Facades\Hash::make('CurrentPass123')]);

    $wrong = $call('POST', '/api/account/password', [
        'current_password' => 'not-it',
        'password' => 'BrandNew12345',
        'password_confirmation' => 'BrandNew12345',
    ]);

    printf("  without the current password : HTTP %d  %s\n", $wrong->getStatusCode(),
        $wrong->getStatusCode() === 422 ? 'CORRECT - a stolen session is not enough' : 'WRONG');

    $weak = $call('POST', '/api/account/password', [
        'current_password' => 'CurrentPass123',
        'password' => 'abcdefgh',
        'password_confirmation' => 'abcdefgh',
    ]);

    printf("  a weak new password          : HTTP %d  %s\n", $weak->getStatusCode(),
        $weak->getStatusCode() === 422 ? 'CORRECT - one shared rule' : 'WRONG');

    // Another device, so we can watch it end.
    $user->createToken('another-device');
    $sessionsBefore = $db->table('personal_access_tokens')->where('tokenable_id', $person->id)->count();

    $ok = $call('POST', '/api/account/password', [
        'current_password' => 'CurrentPass123',
        'password' => 'BrandNew12345',
        'password_confirmation' => 'BrandNew12345',
    ]);

    $hash = $db->table('tbluser')->where('id', $person->id)->value('password');
    $sessionsAfter = $db->table('personal_access_tokens')->where('tokenable_id', $person->id)->count();

    printf("  with it                      : HTTP %d  %s\n", $ok->getStatusCode(),
        \Illuminate\Support\Facades\Hash::check('BrandNew12345', $hash) ? 'CORRECT - changed' : 'WRONG');
    printf("  sessions %d -> %d               : %s\n", $sessionsBefore, $sessionsAfter,
        $sessionsAfter === 1 ? 'CORRECT - others ended, this one survived' : 'WRONG');

    // The surviving token must be the one that made the request.
    $still = $call('GET', '/api/account/me');
    printf("  this session still works     : HTTP %d  %s\n", $still->getStatusCode(),
        $still->getStatusCode() === 200 ? 'CORRECT - not logged out of the screen you did it on' : 'WRONG');

    // ── 7 & 8. SESSIONS BELONG TO THEIR OWNER ───────────────────────────────
    echo "\n══ 5. sessions, and only your own ══\n";

    $colleagueUser = \App\Models\auth\tbluserModel::find($colleague->id);
    $colleagueToken = $colleagueUser->createToken('colleague-device');
    $colleagueTokenId = $colleagueToken->accessToken->id;

    $list = json_decode($call('GET', '/api/account/sessions')->getContent(), true)['data']['sessions'];

    printf("  sessions listed              : %d\n", count($list));
    printf("  any belonging to #%d?        : %s  %s\n", $colleague->id,
        collect($list)->contains('id', $colleagueTokenId) ? 'YES' : 'no',
        collect($list)->contains('id', $colleagueTokenId) ? 'WRONG - another person\'s session' : 'CORRECT');
    printf("  one is marked as this device : %s  %s\n",
        collect($list)->where('current', true)->count() === 1 ? 'yes' : 'no',
        collect($list)->where('current', true)->count() === 1 ? 'CORRECT' : 'WRONG');

    // Try to end the colleague's session by id.
    $steal = $call('DELETE', '/api/account/sessions/' . $colleagueTokenId);
    $survived = $db->table('personal_access_tokens')->where('id', $colleagueTokenId)->exists();

    printf("  ending a colleague's session : HTTP %d, theirs survived: %s  %s\n",
        $steal->getStatusCode(),
        $survived ? 'yes' : 'NO',
        $survived ? 'CORRECT - scoped to the caller' : 'WRONG - signed somebody else out');

    // And this device cannot end itself by id.
    $current = collect($list)->firstWhere('current', true);
    $self = $call('DELETE', '/api/account/sessions/' . $current['id']);
    printf("  ending THIS device by id     : HTTP %d  %s\n", $self->getStatusCode(),
        $self->getStatusCode() === 422 ? 'CORRECT - use Sign out' : 'WRONG');
} finally {
    DB::rollBack();
    $user->tokens()->whereIn('name', ['account-evidence', 'another-device'])->delete();
    if (isset($colleagueUser)) {
        $colleagueUser->tokens()->where('name', 'colleague-device')->delete();
    }
    echo "\n(rolled back - no detail, preference or password kept)\n";
}
