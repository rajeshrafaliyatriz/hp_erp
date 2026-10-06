<?php
/**
 * F-170 EVIDENCE — Roles & access, the last section.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE HARDEST THING TO GET RIGHT HERE IS THE HONESTY
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `data_scope` is a real column, written for every provisioned role by
 * `StandardRoles`, and READ BY NOTHING. Two controllers already carry comments
 * saying so - *"a column nobody reads is not a property"*.
 *
 * The decision taken was to expose it as configuration only. That is defensible
 * exactly once: when the screen says, in plain words, that setting it restricts
 * nobody. An administrator who set it to "their own records only" and believed
 * they had locked somebody down would be WORSE off than one never offered the
 * control.
 *
 * So this script checks the labelling as carefully as the behaviour, and it
 * checks the claim from BOTH directions: that the API reports it unenforced,
 * and that it genuinely is - by setting the most restrictive scope on a role and
 * proving somebody in that role can still read exactly what they could before.
 *
 * ── WHAT IS PROVED ──────────────────────────────────────────────────────────
 *
 *   1. Roles list with real counts - people, and screens they can open.
 *   2. The counts come from the same rights rows the sidebar is built from.
 *   3. data_scope saves, and is reported with `data_scope_enforced: false`.
 *   4. AND IT REALLY IS UNENFORCED - proved, not asserted.
 *   5. Per-role settings save under a role-scoped key, and one role's setting
 *      does not disturb another's.
 *   6. An invented role key or scope writes nothing.
 *   7. Another organisation's role is a 404, not a 403.
 *   8. Only an administrator reaches any of it.
 *
 * Everything runs inside a transaction that is ALWAYS rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-role-settings.php';"
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

\Illuminate\Support\Facades\Mail::fake();

$tenant = 6;

$profiles = $db->table('tbluserprofilemaster')->where('sub_institute_id', $tenant)
    ->whereNull('deleted_at')->whereNotNull('role_key')->get(['id', 'role_key']);
$profileFor = fn (string $k) => optional($profiles->firstWhere('role_key', $k))->id;

DB::beginTransaction();

$created = [];

try {
    $make = function (string $tag, ?int $profileId, int $tenantId) use ($db, &$created) {
        $id = $db->table('tbluser')->insertGetId([
            'user_profile_id' => $profileId,
            'sub_institute_id' => $tenantId,
            'first_name' => 'Roles',
            'last_name' => $tag,
            'email' => 'roles.' . $tag . '@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('irrelevant'),
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $created[] = $id;

        return $id;
    };

    $adminId = $make('admin', $profileFor('administrator'), $tenant);
    $employeeId = $make('employee', $profileFor('employee'), $tenant);

    $tok = fn (int $id) => \App\Models\auth\tbluserModel::find($id)->createToken('roles-ev')->plainTextToken;
    $adminToken = $tok($adminId);
    $employeeToken = $tok($employeeId);

    $call = function (string $method, string $uri, string $t, array $body = []) use ($kernel) {
        $request = \Illuminate\Http\Request::create($uri, $method, $body + ['type' => 'API', 'token' => $t]);
        $request->headers->set('Authorization', 'Bearer ' . $t);
        $request->headers->set('Accept', 'application/json');

        return $kernel->handle($request);
    };

    printf("tenant %d - admin #%d, employee #%d\n\n", $tenant, $adminId, $employeeId);

    $db->table('tenant_setting')->where('sub_institute_id', $tenant)
        ->where('setting_key', 'like', 'role.%')->delete();

    // ── 1 & 2. THE LIST ─────────────────────────────────────────────────────
    echo "══ 1. roles list with counts that come from real rows ══\n";

    $body = json_decode($call('GET', '/api/organization/roles', $adminToken)->getContent(), true);
    $roles = collect($body['data']['roles'] ?? []);

    printf("  roles returned                 : %d  %s\n", $roles->count(),
        $roles->count() > 0 ? 'CORRECT' : 'WRONG - none');

    $employeeRole = $roles->firstWhere('role_key', 'employee');
    $adminRole = $roles->firstWhere('role_key', 'administrator');

    // The person count must match a direct query.
    $realCount = $db->table('tbluser')->where('sub_institute_id', $tenant)
        ->where('user_profile_id', $employeeRole['id'])->whereNull('deleted_at')->where('status', 1)->count();

    printf("  employee role people           : reported %d, counted %d  %s\n",
        $employeeRole['user_count'], $realCount,
        $employeeRole['user_count'] === $realCount ? 'CORRECT' : 'WRONG');

    // The screen count must match the rights rows the sidebar reads.
    $realMenus = $db->table('tblgroupwise_rights_g2g')
        ->where('profile_id', $employeeRole['id'])->where('can_view', 1)
        ->distinct()->count('menu_id');

    printf("  employee role screens          : reported %d, counted %d  %s\n",
        $employeeRole['menu_count'], $realMenus,
        $employeeRole['menu_count'] === $realMenus
            ? 'CORRECT - the same rows the sidebar is built from' : 'WRONG');

    printf("  listed in privilege order      : %s  %s\n",
        $roles->pluck('role_key')->take(3)->implode(' -> '),
        $roles->first()['role_key'] === 'employee' ? 'CORRECT - least access first' : 'check');

    // ── 3. data_scope SAVES, AND IS LABELLED HONESTLY ───────────────────────
    echo "\n══ 2. data_scope saves - and is reported as UNENFORCED ══\n";

    printf("  data_scope_enforced            : %s  %s\n",
        var_export($body['data']['data_scope_enforced'], true),
        $body['data']['data_scope_enforced'] === false
            ? 'CORRECT - the screen can say so in plain words' : 'WRONG - claims an effect');

    $r = $call('PUT', '/api/organization/roles/' . $employeeRole['id'], $adminToken, [
        'data_scope' => 'self',
    ]);
    $stored = $db->table('tbluserprofilemaster')->where('id', $employeeRole['id'])->value('data_scope');

    printf("  set to 'self'                  : HTTP %d, stored '%s'  %s\n",
        $r->getStatusCode(), $stored,
        $stored === 'self' ? 'CORRECT - recorded' : 'WRONG');

    // ── 4. AND IT REALLY IS UNENFORCED ──────────────────────────────────────
    echo "\n══ 3. ...and 'self' really does restrict nothing, which is the claim ══\n";

    // The employee's scope is now the most restrictive there is. If anything
    // enforced it, a directory read would narrow or refuse.
    $before = $call('GET', '/api/employees-management', $employeeToken);
    $beforeCount = count(json_decode($before->getContent(), true)['data'] ?? []);

    $call('PUT', '/api/organization/roles/' . $employeeRole['id'], $adminToken, [
        'data_scope' => 'organization',
    ]);

    $after = $call('GET', '/api/employees-management', $employeeToken);
    $afterCount = count(json_decode($after->getContent(), true)['data'] ?? []);

    printf("  directory rows at scope 'self'         : %d\n", $beforeCount);
    printf("  directory rows at scope 'organization' : %d\n", $afterCount);
    printf("  the scope changed what they see        : %s  %s\n",
        $beforeCount !== $afterCount ? 'YES' : 'no',
        $beforeCount === $afterCount
            ? 'CORRECT - unenforced, exactly as the API reports'
            : 'WRONG - it IS enforced, so the screen is now lying the other way');

    // ── 5. PER-ROLE SETTINGS ────────────────────────────────────────────────
    echo "\n══ 4. per-role settings are scoped to their own role ══\n";

    $call('PUT', '/api/organization/roles/' . $employeeRole['id'], $adminToken, [
        'landing_page' => 'last-visited',
        'notify_email' => false,
    ]);

    $fresh = collect(json_decode($call('GET', '/api/organization/roles', $adminToken)->getContent(), true)['data']['roles']);
    $emp = $fresh->firstWhere('role_key', 'employee');
    $adm = $fresh->firstWhere('role_key', 'administrator');

    printf("  employee lands on              : %s  %s\n", $emp['settings']['landing_page'],
        $emp['settings']['landing_page'] === 'last-visited' ? 'CORRECT' : 'WRONG');
    printf("  employee email default         : %s  %s\n", $emp['settings']['notify_email'],
        $emp['settings']['notify_email'] === '0' ? 'CORRECT' : 'WRONG');
    printf("  administrator untouched        : %s / %s  %s\n",
        $adm['settings']['landing_page'], $adm['settings']['notify_email'],
        $adm['settings']['landing_page'] === 'dashboard' && $adm['settings']['notify_email'] === '1'
            ? 'CORRECT - one role\'s setting does not leak into another' : 'WRONG');

    $key = $db->table('tenant_setting')->where('sub_institute_id', $tenant)
        ->where('setting_key', 'role.employee.landing_page')->value('setting_value');

    printf("  stored under role.employee.*   : %s  %s\n", $key ?: '(nothing)',
        $key === 'last-visited' ? 'CORRECT - role-scoped key' : 'WRONG');

    // ── 6. NONSENSE IS REFUSED ──────────────────────────────────────────────
    echo "\n══ 5. an invented scope or landing page writes nothing ══\n";

    foreach ([['data_scope', 'everything'], ['landing_page', 'wherever']] as [$field, $value]) {
        $r = $call('PUT', '/api/organization/roles/' . $employeeRole['id'], $adminToken, [$field => $value]);
        printf("  %-14s = '%-11s'    : HTTP %d  %s\n", $field, $value, $r->getStatusCode(),
            $r->getStatusCode() === 422 ? 'CORRECT - refused' : 'WRONG - accepted');
    }

    $scopeStill = $db->table('tbluserprofilemaster')->where('id', $employeeRole['id'])->value('data_scope');
    printf("  data_scope after both          : '%s'  %s\n", $scopeStill,
        $scopeStill === 'organization'
            ? 'CORRECT - unchanged; an ENUM would have coerced it to empty'
            : 'WRONG');

    // ── 7. TENANCY ──────────────────────────────────────────────────────────
    echo "\n══ 6. another organisation's role is not found, not forbidden ══\n";

    $foreign = (int) $db->table('tbluserprofilemaster')
        ->where('sub_institute_id', '!=', $tenant)->whereNull('deleted_at')->value('id');

    $foreignScopeBefore = $db->table('tbluserprofilemaster')->where('id', $foreign)->value('data_scope');

    $r = $call('PUT', '/api/organization/roles/' . $foreign, $adminToken, ['data_scope' => 'self']);

    printf("  role #%-6d (another tenant)  : HTTP %d  %s\n", $foreign, $r->getStatusCode(),
        $r->getStatusCode() === 404
            ? 'CORRECT - 404, so its existence is not confirmed'
            : ($r->getStatusCode() === 200 ? 'WRONG - edited across tenants' : 'WRONG - should be 404'));

    /*
     * Compared against what it was BEFORE the attempt, not against the value
     * that was sent. The first version asserted `$untouched !== 'self' || true`,
     * which is a tautology - it could not fail, and would have reported CORRECT
     * even if the write had landed on another organisation's role.
     */
    $untouched = $db->table('tbluserprofilemaster')->where('id', $foreign)->value('data_scope');
    printf("  its data_scope %-16s: '%s' -> '%s'  %s\n", '',
        $foreignScopeBefore ?? '(null)', $untouched ?? '(null)',
        (string) $untouched === (string) $foreignScopeBefore
            ? 'CORRECT - unchanged' : 'WRONG - written across tenants');

    // ── 8. WHO REACHES IT ───────────────────────────────────────────────────
    echo "\n══ 7. an ordinary employee reaches none of it ══\n";

    foreach ([['GET', '/api/organization/roles'], ['PUT', '/api/organization/roles/' . $employeeRole['id']]] as [$m, $u]) {
        $code = $call($m, $u, $employeeToken, ['data_scope' => 'organization'])->getStatusCode();
        printf("  %-4s %-38s HTTP %d  %s\n", $m, $u, $code,
            $code !== 200 ? 'CORRECT - refused' : 'WRONG - open to everybody');
    }
} finally {
    DB::rollBack();

    foreach ($created as $id) {
        \App\Models\auth\tbluserModel::find($id)?->tokens()->delete();
    }

    echo "\n(rolled back - no role, setting, account or token kept)\n";
}
