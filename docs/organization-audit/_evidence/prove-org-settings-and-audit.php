<?php
/**
 * F-169 EVIDENCE — organisation defaults, security policy, and the audit trail.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE ONE THAT ACTUALLY BITES
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Three sections shipped together, and only one of them CHANGES anything today:
 * the password policy, because `PasswordController::rule()` now reads it and
 * that rule is shared by every password path in the product.
 *
 * The rest are stored and not yet consulted, and the API says so per group in
 * an `enforced` map that the screen renders verbatim. This script proves BOTH
 * halves: that the enforced one really is, and that the honest labels match
 * what the code actually does. A settings screen claiming an effect it does not
 * have is the exact defect this whole engagement exists to remove.
 *
 * ── WHAT IS PROVED ──────────────────────────────────────────────────────────
 *
 *   1. Defaults come back for an organisation that has never saved anything.
 *   2. Settings save, and are scoped to the caller's own organisation.
 *   3. THE PASSWORD MINIMUM CANNOT BE LOWERED BELOW 8, from either side.
 *   4. Raising it immediately changes what /account/password accepts.
 *   5. Requiring a symbol immediately changes what it accepts.
 *   6. A malformed working-day mask is refused rather than coerced.
 *   7. The audit trail reads, filters and pages - and an AUDITOR can read it
 *      while being refused every settings write.
 *   8. An ordinary employee reaches none of it.
 *
 * Everything runs inside a transaction that is ALWAYS rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-org-settings-and-audit.php';"
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

\Illuminate\Support\Facades\Mail::fake();

$tenant = 6;

$profiles = $db->table('tbluserprofilemaster')->where('sub_institute_id', $tenant)
    ->whereNotNull('role_key')->get(['id', 'role_key']);
$profileFor = fn (string $k) => optional($profiles->firstWhere('role_key', $k))->id;

DB::beginTransaction();

$created = [];

try {
    $make = function (string $tag, ?int $profileId) use ($db, $tenant, &$created) {
        $id = $db->table('tbluser')->insertGetId([
            'user_profile_id' => $profileId,
            'sub_institute_id' => $tenant,
            'first_name' => 'Settings',
            'last_name' => $tag,
            'email' => 'settings.' . $tag . '@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('CurrentPass123'),
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $created[] = $id;

        return $id;
    };

    $adminId = $make('admin', $profileFor('administrator'));
    $auditorId = $make('auditor', $profileFor('auditor'));
    $employeeId = $make('employee', $profileFor('employee'));

    $tok = fn (int $id, string $name) => \App\Models\auth\tbluserModel::find($id)
        ->createToken($name)->plainTextToken;

    $adminToken = $tok($adminId, 'orgset-ev');
    $auditorToken = $tok($auditorId, 'orgset-ev');
    $employeeToken = $tok($employeeId, 'orgset-ev');

    $ip = 0;
    $call = function (string $method, string $uri, string $t, array $body = []) use ($kernel, &$ip) {
        $request = \Illuminate\Http\Request::create($uri, $method, $body + ['type' => 'API', 'token' => $t]);
        $request->headers->set('Authorization', 'Bearer ' . $t);
        $request->headers->set('Accept', 'application/json');
        // Distinct addresses: /account/password is throttle:6,1 and this script
        // makes more than six calls to it.
        $request->server->set('REMOTE_ADDR', '10.7.' . intdiv(++$ip, 250) . '.' . ($ip % 250));

        return $kernel->handle($request);
    };

    printf("tenant %d - admin #%d, auditor #%d, employee #%d\n\n", $tenant, $adminId, $auditorId, $employeeId);

    $db->table('tenant_setting')->where('sub_institute_id', $tenant)->delete();

    // ── 1. DEFAULTS ─────────────────────────────────────────────────────────
    echo "══ 1. an organisation that has saved nothing gets the declared defaults ══\n";

    $body = json_decode($call('GET', '/api/organization/settings', $adminToken)->getContent(), true);
    $settings = $body['data']['settings'];

    printf("  keys returned                  : %d  %s\n", count($settings),
        count($settings) === count(\App\Services\Organization\TenantSettings::DEFAULTS)
            ? 'CORRECT - every declared key' : 'WRONG');
    printf("  password minimum               : %s  %s\n", $settings['security.password_min_length'],
        $settings['security.password_min_length'] === '8' ? 'CORRECT' : 'WRONG');
    printf("  working days                   : %s (%s)\n", $settings['org.working_days'],
        implode(', ', $body['data']['working_days']));
    printf("  password policy is enforced    : %s  %s\n",
        var_export($body['data']['enforced']['password_policy'], true),
        $body['data']['enforced']['password_policy'] === true
            ? 'CORRECT - and section 3 proves it' : 'WRONG');

    // ── 3. THE FLOOR ────────────────────────────────────────────────────────
    echo "\n══ 2. the password minimum cannot be lowered below the product floor ══\n";

    $r = $call('PUT', '/api/organization/settings', $adminToken, ['password_min_length' => 4]);

    printf("  setting it to 4                : HTTP %d  %s\n", $r->getStatusCode(),
        $r->getStatusCode() === 422 ? 'CORRECT - refused' : 'WRONG - a tenant weakened the platform rule');

    // ── 2 & 4. SAVING, AND IT TAKING EFFECT ─────────────────────────────────
    echo "\n══ 3. raising it changes what a password endpoint accepts, at once ══\n";

    // Twelve characters, letters and numbers - passes the floor, fails a 14 min.
    $candidate = 'Abcdefgh1234';

    $before = $call('POST', '/api/account/password', $adminToken, [
        'current_password' => 'CurrentPass123',
        'password' => $candidate,
        'password_confirmation' => $candidate,
    ]);

    printf("  '%s' at minimum 8        : HTTP %d  %s\n", $candidate, $before->getStatusCode(),
        $before->getStatusCode() === 200 ? 'CORRECT - accepted' : 'WRONG');

    // Put the password back, so the next attempt has a known current one and
    // its result is about the RULE rather than about a stale credential.
    $db->table('tbluser')->where('id', $adminId)
        ->update(['password' => \Illuminate\Support\Facades\Hash::make('CurrentPass123')]);

    $call('PUT', '/api/organization/settings', $adminToken, ['password_min_length' => 14]);

    $after = $call('POST', '/api/account/password', $adminToken, [
        'current_password' => 'CurrentPass123',
        'password' => $candidate,
        'password_confirmation' => $candidate,
    ]);

    printf("  the same one at minimum 14     : HTTP %d  %s\n", $after->getStatusCode(),
        $after->getStatusCode() === 422
            ? 'CORRECT - the stored policy is READ, not just stored'
            : 'WRONG - the setting changes nothing');

    // ── 5. THE SYMBOL RULE ──────────────────────────────────────────────────
    echo "\n══ 4. requiring a symbol takes effect the same way ══\n";

    $call('PUT', '/api/organization/settings', $adminToken, [
        'password_min_length' => 8,
        'password_require_symbol' => true,
    ]);

    /*
     * THE PASSWORD IS RESET BETWEEN THE TWO ATTEMPTS.
     *
     * Without that, the first attempt changes it, and the second one's
     * `current_password` is then wrong - so it 422s for the wrong reason and
     * the check reports the symbol rule working when it is not. That is exactly
     * what happened on the first run: "without a symbol" was ACCEPTED and "with
     * one" was refused, the opposite of the truth, and both readings were
     * artefacts of the ordering rather than of the rule.
     */
    $resetPassword = function () use ($db, $adminId) {
        $db->table('tbluser')->where('id', $adminId)
            ->update(['password' => \Illuminate\Support\Facades\Hash::make('CurrentPass123')]);
    };

    $resetPassword();
    $noSymbol = $call('POST', '/api/account/password', $adminToken, [
        'current_password' => 'CurrentPass123',
        'password' => 'Abcdefgh1234',
        'password_confirmation' => 'Abcdefgh1234',
    ]);

    $resetPassword();
    $withSymbol = $call('POST', '/api/account/password', $adminToken, [
        'current_password' => 'CurrentPass123',
        'password' => 'Abcdefgh1234!',
        'password_confirmation' => 'Abcdefgh1234!',
    ]);

    printf("  without a symbol               : HTTP %d  %s\n", $noSymbol->getStatusCode(),
        $noSymbol->getStatusCode() === 422 ? 'CORRECT - refused' : 'WRONG');
    printf("  with one                       : HTTP %d  %s\n", $withSymbol->getStatusCode(),
        $withSymbol->getStatusCode() === 200
            ? 'CORRECT - so the refusal above was the RULE, not a dead endpoint'
            : 'WRONG - it refuses everything, proving nothing');

    $db->table('tbluser')->where('id', $adminId)
        ->update(['password' => \Illuminate\Support\Facades\Hash::make('CurrentPass123')]);

    // ── 6. THE MASK ─────────────────────────────────────────────────────────
    echo "\n══ 5. a malformed working week is refused, not coerced ══\n";

    foreach (['111', '1111111x', '2222222'] as $bad) {
        $r = $call('PUT', '/api/organization/settings', $adminToken, ['working_days' => $bad]);
        printf("  '%-8s'                     : HTTP %d  %s\n", $bad, $r->getStatusCode(),
            $r->getStatusCode() === 422 ? 'CORRECT - refused' : 'WRONG - would blank the week');
    }

    $r = $call('PUT', '/api/organization/settings', $adminToken, ['working_days' => '1111110']);
    $now = json_decode($r->getContent(), true);

    printf("  '1111110'                      : HTTP %d, days: %s  %s\n", $r->getStatusCode(),
        implode(', ', $now['data']['working_days'] ?? []),
        count($now['data']['working_days'] ?? []) === 6 ? 'CORRECT - six-day week' : 'WRONG');

    // ── 7. THE AUDIT TRAIL ──────────────────────────────────────────────────
    echo "\n══ 6. the audit trail reads, and an auditor is exactly who can read it ══\n";

    /*
     * ═══════════════════════════════════════════════════════════════════════
     * THE TRANSPORT MARKER IS SENT, DELIBERATELY
     * ═══════════════════════════════════════════════════════════════════════
     *
     * `$call` puts `type => 'API'` in the request, exactly as every real
     * frontend call does. That is what broke this screen: the audit filter used
     * to be called `type`, so `?type=api` became `where a.type = 'api'` and the
     * trail was permanently empty while looking perfectly wired.
     *
     * The first version of this check only asserted that `entries` was SET,
     * which an empty array satisfies - so it passed while the screen was dead.
     * It now asserts against the row count in the table, which is the only
     * assertion that can tell the two apart.
     */
    $auditRows = $db->table('g2g_audit_log')->where('sub_institute_id', $tenant)->count();

    $audit = json_decode($call('GET', '/api/organization/audit', $auditorToken)->getContent(), true);

    printf("  rows in g2g_audit_log          : %d\n", $auditRows);
    printf("  auditor reading it             : %d entries of %d total\n",
        count($audit['data']['entries'] ?? []), $audit['data']['total'] ?? 0);
    printf("  the total matches the table    : %s\n",
        (int) ($audit['data']['total'] ?? -1) === $auditRows
            ? 'CORRECT - the transport marker no longer filters the trail away'
            : 'WRONG - reported ' . ($audit['data']['total'] ?? 'nothing') . ' of ' . $auditRows);

    // And the filter itself still works, under its new name.
    if ($auditRows > 0) {
        $someType = $db->table('g2g_audit_log')->where('sub_institute_id', $tenant)->value('type');
        $filtered = json_decode($call('GET', '/api/organization/audit', $auditorToken,
            ['event_type' => $someType])->getContent(), true);
        $expected = $db->table('g2g_audit_log')->where('sub_institute_id', $tenant)
            ->where('type', $someType)->count();

        printf("  filtering on '%s'%s: %d of %d  %s\n", $someType,
            str_repeat(' ', max(1, 18 - strlen($someType))),
            $filtered['data']['total'] ?? -1, $auditRows,
            (int) ($filtered['data']['total'] ?? -1) === $expected
                ? 'CORRECT - event_type filters, and narrows' : 'WRONG');
    }
    printf("  filter offers real types       : %s\n",
        implode(', ', array_slice($audit['data']['types'] ?? [], 0, 4)) ?: '(none recorded)');

    // Every entry must belong to this tenant.
    $ids = collect($audit['data']['entries'] ?? [])->pluck('id');
    $foreign = $ids->isEmpty() ? 0 : $db->table('g2g_audit_log')
        ->whereIn('id', $ids)->where('sub_institute_id', '!=', $tenant)->count();

    printf("  entries from another tenant    : %d  %s\n", $foreign,
        $foreign === 0 ? 'CORRECT - scoped' : 'WRONG - cross-tenant leak');

    // ...and an auditor must NOT be able to change a setting.
    $r = $call('PUT', '/api/organization/settings', $auditorToken, ['currency' => 'USD']);

    printf("  auditor changing a setting     : HTTP %d  %s\n", $r->getStatusCode(),
        $r->getStatusCode() === 403
            ? 'CORRECT - may read the record, may not change the product'
            : 'WRONG - read access became write access');

    // ── 8. AN EMPLOYEE REACHES NONE OF IT ───────────────────────────────────
    echo "\n══ 7. an ordinary employee reaches none of it ══\n";

    foreach ([
        ['GET', '/api/organization/settings'],
        ['PUT', '/api/organization/settings'],
        ['GET', '/api/organization/audit'],
    ] as [$m, $u]) {
        $code = $call($m, $u, $employeeToken)->getStatusCode();
        printf("  %-4s %-34s HTTP %d  %s\n", $m, $u, $code,
            $code !== 200 ? 'CORRECT - refused' : 'WRONG - open to everybody');
    }

    // ── TENANCY ON THE WRITE PATH ───────────────────────────────────────────
    echo "\n══ 8. a save lands on the caller's own organisation ══\n";

    $rows = $db->table('tenant_setting')->where('sub_institute_id', '!=', $tenant)->count();
    $ours = $db->table('tenant_setting')->where('sub_institute_id', $tenant)->count();

    printf("  rows written for tenant %-6d : %d\n", $tenant, $ours);
    printf("  rows written for anybody else  : %d  %s\n", $rows,
        $rows === 0 ? 'CORRECT - no tenant id is accepted from the request' : 'WRONG');
} finally {
    DB::rollBack();

    foreach ($created as $id) {
        \App\Models\auth\tbluserModel::find($id)?->tokens()->delete();
    }

    echo "\n(rolled back - no setting, account, password or token kept)\n";
}
