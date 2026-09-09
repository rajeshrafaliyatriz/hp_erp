<?php
/**
 * F-164 EVIDENCE — the screen that finds everybody who cannot get in.
 *
 * ── WHAT THIS ANSWERS THAT NOTHING COULD ────────────────────────────────────
 *
 * Every account created through Employee Directory was unreachable: a random
 * password nobody knows, and an "invite" that wrote a token and returned
 * `['sent' => true]` with no mail call in it. So there is a population, on every
 * tenant, holding an account they have never been able to open - and no screen,
 * query or report in this product would name them.
 *
 * `GET /api/employees-management/pending-access` answers it from facts already
 * stored: `tbluser.last_login IS NULL`, joined to `password_reset_tokens`.
 *
 * ── WHAT IS PROVED ──────────────────────────────────────────────────────────
 *
 *   1. It lists people who have never signed in, and NOT people who have.
 *   2. The three states are computed correctly: never invited, invited, expired.
 *   3. It is scoped to the caller's tenant - another organisation's stranded
 *      people are invisible, even though the token table is global.
 *   4. An ordinary employee cannot call it at all.
 *   5. Deleted and suspended accounts are excluded.
 *
 * Everything runs inside a transaction that is ALWAYS rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-pending-access.php';"
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

$tenant = 6;
$hours = \App\Services\Auth\InviteService::TOKEN_HOURS;

$admin = collect($db->table('tbluser')->where('sub_institute_id', $tenant)->where('status', 1)->get(['id']))
    ->first(fn ($u) => \App\Support\RoleKey::forUserId((int) $u->id) === 'administrator');

$employee = collect($db->table('tbluser')->where('sub_institute_id', $tenant)->where('status', 1)->get(['id']))
    ->first(fn ($u) => \App\Support\RoleKey::forUserId((int) $u->id) === 'employee');

// A second tenant, so cross-tenant visibility can be tested rather than assumed.
$otherTenant = (int) $db->table('school_setup')->where('id', '!=', $tenant)->value('id');

printf("acting as administrator #%d on tenant %d; the other tenant is %d\n\n", $admin->id, $tenant, $otherTenant);

$adminUser = \App\Models\auth\tbluserModel::find($admin->id);
$employeeUser = \App\Models\auth\tbluserModel::find($employee->id);

DB::beginTransaction();

try {
    $adminToken = $adminUser->createToken('pending-evidence')->plainTextToken;
    $employeeToken = $employeeUser->createToken('pending-evidence-emp')->plainTextToken;

    $call = function (string $uri, string $tok) use ($kernel) {
        $request = \Illuminate\Http\Request::create($uri, 'GET', ['type' => 'API', 'token' => $tok]);
        $request->headers->set('Authorization', 'Bearer ' . $tok);
        $request->headers->set('Accept', 'application/json');

        return $kernel->handle($request);
    };

    // ── FIXTURES ────────────────────────────────────────────────────────────
    // Four people whose situations are known exactly, so the states can be
    // checked against a fact rather than against whatever live data happens to
    // hold today.
    // `user_profile_id` is NOT NULL with no default, like `password` and
    // `email` - the same structural fact that forces EmployeeFactory to mint a
    // throwaway credential. A fixture must supply one or the insert fails.
    $employeeProfile = (int) $db->table('tbluserprofilemaster')->where('role_key', 'employee')->value('id')
        ?: (int) $db->table('tbluserprofilemaster')->value('id');

    $make = function (int $tenantId, string $email, ?string $lastLogin, ?string $invitedAt) use ($db, $employeeProfile) {
        $id = $db->table('tbluser')->insertGetId([
            'user_profile_id' => $employeeProfile,
            'sub_institute_id' => $tenantId,
            'first_name' => 'Evidence',
            'last_name' => substr($email, 0, 8),
            'email' => $email,
            'password' => \Illuminate\Support\Facades\Hash::make('irrelevant'),
            'status' => 1,
            'last_login' => $lastLogin,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($invitedAt !== null) {
            $db->table('password_reset_tokens')->insert([
                'email' => $email,
                'token' => hash('sha256', $email . 'evidence'),
                'created_at' => $invitedAt,
            ]);
        }

        return $id;
    };

    $neverInvited = $make($tenant, 'ev.never@example.test', null, null);
    $invited      = $make($tenant, 'ev.invited@example.test', null, now()->subHours(2)->toDateTimeString());
    $expired      = $make($tenant, 'ev.expired@example.test', null, now()->subHours($hours + 5)->toDateTimeString());
    $hasSignedIn  = $make($tenant, 'ev.signedin@example.test', now()->subDay()->toDateTimeString(), null);
    $suspended    = $make($tenant, 'ev.suspended@example.test', null, null);
    $strangerElse = $make($otherTenant, 'ev.othertenant@example.test', null, null);

    $db->table('tbluser')->where('id', $suspended)->update(['status' => 0]);

    // ── 1 & 2. THE LIST, AND THE THREE STATES ───────────────────────────────
    echo "══ 1. who has never signed in, and what state their invite is in ══\n";

    $body = json_decode($call('/api/employees-management/pending-access', $adminToken)->getContent(), true);
    $people = collect($body['data']['people'] ?? []);
    $byId = $people->keyBy('id');

    $expect = [
        [$neverInvited, 'never_invited', 'has an account and no invite at all'],
        [$invited,      'invited',       'invited 2 hours ago, still valid'],
        [$expired,      'expired',       'invited ' . ($hours + 5) . ' hours ago'],
    ];

    foreach ($expect as [$id, $state, $why]) {
        $got = $byId[$id]['state'] ?? '(absent)';
        printf("  #%-7d %-28s -> %-14s %s\n", $id, $why, $got,
            $got === $state ? 'CORRECT' : 'WRONG - expected ' . $state);
    }

    printf("  #%-7d %-28s -> %-14s %s\n", $hasSignedIn, 'has signed in before', 'not listed',
        !$byId->has($hasSignedIn) ? 'CORRECT - not stranded' : 'WRONG - listed anyway');

    printf("  #%-7d %-28s -> %-14s %s\n", $suspended, 'suspended (status 0)', 'not listed',
        !$byId->has($suspended) ? 'CORRECT - no access to restore' : 'WRONG - listed anyway');

    // ── 3. TENANCY ──────────────────────────────────────────────────────────
    echo "\n══ 2. another organisation's stranded people are invisible ══\n";

    printf("  tenant %d's stranded user #%d appears? : %s  %s\n", $otherTenant, $strangerElse,
        $byId->has($strangerElse) ? 'YES' : 'no',
        $byId->has($strangerElse) ? 'WRONG - cross-tenant leak' : 'CORRECT');

    // The join is on an EMAIL, so prove the token table cannot pull one in.
    $db->table('password_reset_tokens')->insert([
        'email' => 'ev.othertenant@example.test',
        'token' => hash('sha256', 'cross-tenant'),
        'created_at' => now()->toDateTimeString(),
    ]);

    $again = json_decode($call('/api/employees-management/pending-access', $adminToken)->getContent(), true);
    $stillHidden = !collect($again['data']['people'])->contains('id', $strangerElse);

    printf("  ...even once they hold a token   : %s  %s\n",
        $stillHidden ? 'still hidden' : 'NOW VISIBLE',
        $stillHidden ? 'CORRECT - the employee side is filtered first' : 'WRONG');

    // ── 4. THE COUNTS MATCH THE ROWS ────────────────────────────────────────
    echo "\n══ 3. the counts are counted, not asserted ══\n";

    $counts = $body['data']['counts'];

    foreach (['never_invited', 'invited', 'expired'] as $state) {
        $fromRows = $people->where('state', $state)->count();
        printf("  %-14s reported %-4d rows %-4d  %s\n", $state, $counts[$state], $fromRows,
            $counts[$state] === $fromRows ? 'CORRECT' : 'WRONG');
    }

    printf("  %-14s reported %-4d rows %-4d  %s\n", 'total', $counts['total'], $people->count(),
        $counts['total'] === $people->count() ? 'CORRECT' : 'WRONG');

    // ── 5. AN EMPLOYEE CANNOT CALL IT ───────────────────────────────────────
    echo "\n══ 4. it is not open to everybody ══\n";

    $refused = $call('/api/employees-management/pending-access', $employeeToken);

    printf("  an ordinary employee calling it  : HTTP %d  %s\n", $refused->getStatusCode(),
        $refused->getStatusCode() !== 200
            ? 'CORRECT - refused, not merely hidden in the UI'
            : 'WRONG - anybody can read who cannot sign in');
} finally {
    DB::rollBack();
    $adminUser->tokens()->where('name', 'pending-evidence')->delete();
    $employeeUser->tokens()->where('name', 'pending-evidence-emp')->delete();
    echo "\n(rolled back - no user, token or login row kept)\n";
}
