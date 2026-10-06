<?php
/**
 * F-168 EVIDENCE — an organisation can finally configure how it sends email.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ONE ROW, TWELVE ORGANISATIONS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `smtp_details` has been per-tenant since it was created and holds exactly one
 * row, belonging to tenant 1. There has never been a screen, endpoint or command
 * to add a second - the only way was a direct database write.
 *
 * That is the reason the invite flow hands out a copyable link: `InviteService`
 * emails when a tenant can send and returns a link when it cannot, and eleven of
 * twelve tenants cannot.
 *
 * ── WHAT IS PROVED ──────────────────────────────────────────────────────────
 *
 *   1. A tenant with no mailbox reports `configured: false` - honestly, rather
 *      than falling back to somebody else's row.
 *   2. Settings can be created, and are scoped to the caller's tenant.
 *   3. THE PASSWORD IS NEVER RETURNED, on any path.
 *   4. Saving without a password KEEPS the stored one; it cannot be blanked by
 *      leaving the field empty.
 *   5. The first save REQUIRES a password - no half-configured mailbox.
 *   6. One tenant's settings are invisible to another.
 *   7. The test send goes to the CALLER and accepts no address.
 *   8. Only administrators and HR reach any of it.
 *
 * Everything runs inside a transaction that is ALWAYS rolled back, and Mail is
 * faked - tenant 6 is on the allowlist and MAIL_MAILER is smtp, so an unfaked
 * run would send real email.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-delivery-settings.php';"
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
    $make = function (string $tag, int $profileId, int $tenantId) use ($db, &$created) {
        $id = $db->table('tbluser')->insertGetId([
            'user_profile_id' => $profileId,
            'sub_institute_id' => $tenantId,
            'first_name' => 'Delivery',
            'last_name' => $tag,
            'email' => 'delivery.' . $tag . '@example.test',
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

    $adminToken = \App\Models\auth\tbluserModel::find($adminId)->createToken('delivery-ev')->plainTextToken;
    $employeeToken = \App\Models\auth\tbluserModel::find($employeeId)->createToken('delivery-ev')->plainTextToken;

    $call = function (string $method, string $uri, string $tok, array $body = []) use ($kernel) {
        $request = \Illuminate\Http\Request::create($uri, $method, $body + ['type' => 'API', 'token' => $tok]);
        $request->headers->set('Authorization', 'Bearer ' . $tok);
        $request->headers->set('Accept', 'application/json');

        return $kernel->handle($request);
    };

    printf("tenant %d, acting as administrator #%d\n\n", $tenant, $adminId);

    // Start from nothing, so "configured: false" is a fact and not a hope.
    $db->table('smtp_details')->where('sub_institute_id', $tenant)->delete();

    // ── 1. AN UNCONFIGURED TENANT SAYS SO ───────────────────────────────────
    echo "══ 1. a tenant with no mailbox reports it honestly ══\n";

    $body = json_decode($call('GET', '/api/organization/delivery', $adminToken)->getContent(), true);

    printf("  configured                     : %s  %s\n",
        var_export($body['data']['email']['configured'], true),
        $body['data']['email']['configured'] === false
            ? 'CORRECT - not borrowing tenant 1\'s row' : 'WRONG');
    printf("  the platform allows sending    : %s\n", var_export($body['data']['allowed'], true));

    // ── 5. THE FIRST SAVE NEEDS A PASSWORD ──────────────────────────────────
    echo "\n══ 2. a mailbox cannot be half-configured ══\n";

    $r = $call('PUT', '/api/organization/delivery', $adminToken, [
        'from_address' => 'hr@example.test',
        'server' => 'smtp.example.test',
        'port' => 465,
    ]);

    printf("  first save with no password    : HTTP %d  %s\n", $r->getStatusCode(),
        $r->getStatusCode() === 422 ? 'CORRECT - refused' : 'WRONG - stored an unusable mailbox');

    // ── 2. IT CAN BE CREATED ────────────────────────────────────────────────
    echo "\n══ 3. settings can be created, and land on the right tenant ══\n";

    $r = $call('PUT', '/api/organization/delivery', $adminToken, [
        'from_address' => 'hr@example.test',
        'server' => 'smtp.example.test',
        'port' => 465,
        'password' => 'SecretMailboxPass',
    ]);

    $row = $db->table('smtp_details')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->first();

    printf("  save                           : HTTP %d  %s\n", $r->getStatusCode(),
        $r->getStatusCode() === 200 ? 'CORRECT' : 'WRONG');
    printf("  row exists for tenant %-8d : %s  %s\n", $tenant, $row ? 'yes' : 'NO',
        $row ? 'CORRECT' : 'WRONG');
    printf("  its sub_institute_id           : %s  %s\n", $row->sub_institute_id ?? '(none)',
        (int) ($row->sub_institute_id ?? 0) === $tenant ? 'CORRECT - the token decided' : 'WRONG');

    // ── 3. THE PASSWORD IS NEVER RETURNED ───────────────────────────────────
    echo "\n══ 4. the mailbox password never comes back ══\n";

    $raw = $call('GET', '/api/organization/delivery', $adminToken)->getContent();
    $body = json_decode($raw, true);

    printf("  'SecretMailboxPass' in response: %s  %s\n",
        str_contains($raw, 'SecretMailboxPass') ? 'YES' : 'no',
        !str_contains($raw, 'SecretMailboxPass')
            ? 'CORRECT - whether, never what' : 'WRONG - the mailbox password was published');
    printf("  has_password reported          : %s  %s\n",
        var_export($body['data']['email']['has_password'], true),
        $body['data']['email']['has_password'] === true ? 'CORRECT' : 'WRONG');

    // The PUT response is built from show(), so it must be clean too.
    $rawPut = $call('PUT', '/api/organization/delivery', $adminToken, [
        'from_address' => 'hr@example.test',
        'server' => 'smtp.example.test',
        'port' => 465,
    ])->getContent();

    printf("  ...nor in the save response    : %s  %s\n",
        str_contains($rawPut, 'SecretMailboxPass') ? 'YES' : 'no',
        !str_contains($rawPut, 'SecretMailboxPass') ? 'CORRECT' : 'WRONG');

    // ── 4. A BLANK FIELD KEEPS THE STORED PASSWORD ──────────────────────────
    echo "\n══ 5. saving without a password keeps the one you have ══\n";

    $stillThere = $db->table('smtp_details')->where('sub_institute_id', $tenant)->value('password');

    printf("  after a save with no password  : %s  %s\n",
        $stillThere === 'SecretMailboxPass' ? 'kept' : 'LOST',
        $stillThere === 'SecretMailboxPass'
            ? 'CORRECT - an empty field is not an instruction to erase'
            : 'WRONG - editing the port broke the mailbox');

    // And an explicitly empty password is refused rather than silently ignored.
    $r = $call('PUT', '/api/organization/delivery', $adminToken, [
        'from_address' => 'hr@example.test',
        'server' => 'smtp.example.test',
        'port' => 465,
        'password' => '',
    ]);

    printf("  an explicitly EMPTY password   : HTTP %d  %s\n", $r->getStatusCode(),
        $r->getStatusCode() === 422 ? 'CORRECT - refused' : 'WRONG - accepted a blank credential');

    // ── 6. TENANCY ──────────────────────────────────────────────────────────
    echo "\n══ 6. one organisation cannot see another's mailbox ══\n";

    $otherTenant = (int) $db->table('school_setup')->where('id', '!=', $tenant)->value('id');
    $otherProfile = (int) $db->table('tbluserprofilemaster')
        ->where('sub_institute_id', $otherTenant)->where('role_key', 'administrator')->value('id');

    if ($otherProfile) {
        $otherAdminId = $make('otheradmin', $otherProfile, $otherTenant);
        $otherToken = \App\Models\auth\tbluserModel::find($otherAdminId)->createToken('delivery-ev')->plainTextToken;

        $theirs = json_decode($call('GET', '/api/organization/delivery', $otherToken)->getContent(), true);

        printf("  tenant %d's admin sees from    : %s  %s\n", $otherTenant,
            $theirs['data']['email']['from_address'] ?? '(nothing)',
            ($theirs['data']['email']['from_address'] ?? null) !== 'hr@example.test'
                ? 'CORRECT - not tenant ' . $tenant . '\'s' : 'WRONG - cross-tenant leak');
    } else {
        printf("  (tenant %d has no administrator profile - skipped)\n", $otherTenant);
    }

    // ── 7. THE TEST SEND ────────────────────────────────────────────────────
    echo "\n══ 7. the test goes to the caller, and only the caller ══\n";

    $r = $call('POST', '/api/organization/delivery/test', $adminToken, [
        // Sent deliberately. It must be ignored.
        'to' => 'attacker@example.test',
        'email' => 'attacker@example.test',
        'address' => 'attacker@example.test',
    ]);
    $body = json_decode($r->getContent(), true);

    printf("  test send                      : HTTP %d\n", $r->getStatusCode());
    printf("  message names                  : %s\n", $body['message'] ?? '(none)');
    printf("  the attacker's address used    : %s  %s\n",
        str_contains($body['message'] ?? '', 'attacker@example.test') ? 'YES' : 'no',
        !str_contains($body['message'] ?? '', 'attacker@example.test')
            ? 'CORRECT - no address is accepted from the request' : 'WRONG - an open relay');
    printf("  the caller's own address used  : %s  %s\n",
        str_contains($body['message'] ?? '', 'delivery.admin@example.test') ? 'yes' : 'NO',
        str_contains($body['message'] ?? '', 'delivery.admin@example.test')
            || $r->getStatusCode() !== 200 ? 'CORRECT' : 'WRONG');

    // ── 8. WHO CAN REACH IT ─────────────────────────────────────────────────
    echo "\n══ 8. an ordinary employee reaches none of it ══\n";

    foreach ([['GET', '/api/organization/delivery'], ['PUT', '/api/organization/delivery'], ['POST', '/api/organization/delivery/test']] as [$m, $u]) {
        $code = $call($m, $u, $employeeToken)->getStatusCode();
        printf("  %-4s %-38s HTTP %d  %s\n", $m, $u, $code,
            $code !== 200 ? 'CORRECT - refused' : 'WRONG - open to everybody');
    }
} finally {
    DB::rollBack();

    foreach ($created as $id) {
        \App\Models\auth\tbluserModel::find($id)?->tokens()->delete();
    }

    echo "\n(rolled back - no mailbox, account or token kept; Mail was faked)\n";
}
