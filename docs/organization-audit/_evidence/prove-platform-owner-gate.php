<?php
/**
 * F-157 EVIDENCE — creating an organisation is no longer anonymous.
 *
 * Both endpoints that can bring a tenant into existence used to carry NO
 * middleware at all:
 *
 *   POST /api/school-setup   creates a tenant, a client, 3 role profiles and
 *                            24 rights rows
 *   POST /api/user-signup    creates a user with a hashed password, and read
 *                            `is_admin` straight off the request body
 *
 * Driven through the HTTP kernel so the route middleware actually runs. Three
 * callers, and the expected answer differs for each:
 *
 *   no token                 401  - api.token refuses first
 *   an ordinary administrator 404 - platform.owner refuses, and deliberately
 *                                   does not admit the endpoint exists
 *   a platform owner          not 401/404 - the gate lets them through
 *
 * The owner's request is sent with a DELIBERATELY INVALID body, so the proof is
 * that it reaches validation (422) rather than that it creates anything. No
 * tenant is created by this script.
 *
 * Also proved: `is_admin` is now derived from the profile, not asserted by the
 * caller.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-platform-owner-gate.php';"
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');

$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

$call = function (string $uri, array $params, ?string $token) use ($kernel) {
    $request = \Illuminate\Http\Request::create($uri, 'POST', $params + ($token ? ['type' => 'API', 'token' => $token] : []));

    if ($token) {
        $request->headers->set('Authorization', 'Bearer ' . $token);
    }

    $request->headers->set('Accept', 'application/json');

    return $kernel->handle($request);
};

// ── The three callers ───────────────────────────────────────────────────────
$owner = $db->table('platform_owners as p')
    ->join('tbluser as u', 'u.id', '=', 'p.user_id')
    ->whereNull('p.revoked_at')
    ->first(['u.id', 'u.email']);

// An ordinary administrator who is NOT a platform owner.
$admin = collect($db->table('tbluser')->where('sub_institute_id', 6)->get(['id', 'email']))
    ->first(fn ($u) => \App\Support\RoleKey::forUserId((int) $u->id) === 'administrator'
        && (int) $u->id !== (int) $owner->id);

printf("platform owner       : #%d %s\n", $owner->id, $owner->email);
printf("ordinary administrator: #%d %s\n\n", $admin->id, $admin->email);

$ownerUser = \App\Models\auth\tbluserModel::find($owner->id);
$adminUser = \App\Models\auth\tbluserModel::find($admin->id);

$ownerToken = $ownerUser->createToken('platform-gate-evidence')->plainTextToken;
$adminToken = $adminUser->createToken('platform-gate-evidence')->plainTextToken;

try {
    foreach (['/api/school-setup', '/api/user-signup'] as $uri) {
        printf("══ %s ══\n", $uri);

        // 1. No credential at all.
        $status = $call($uri, ['SchoolName' => 'Evidence Org'], null)->getStatusCode();
        printf("  no token                 -> %d  %s\n", $status,
            $status === 401 ? 'CORRECT — refused before anything ran' : 'WRONG — expected 401');

        // 2. A valid token belonging to somebody with no business here.
        $status = $call($uri, ['SchoolName' => 'Evidence Org'], $adminToken)->getStatusCode();
        printf("  ordinary administrator   -> %d  %s\n", $status,
            $status === 404 ? 'CORRECT — refused, and the endpoint is not admitted to exist' : 'WRONG — expected 404');

        // 3. The platform owner, with a body that cannot pass validation.
        //    Reaching 422 proves the GATE opened; nothing is created.
        $response = $call($uri, ['SchoolName' => ''], $ownerToken);
        $status = $response->getStatusCode();
        printf("  platform owner           -> %d  %s\n", $status,
            in_array($status, [401, 404], true)
                ? 'WRONG — the owner was refused'
                : 'CORRECT — through the gate, stopped by validation');
    }

    // ── is_admin is derived, not asserted ───────────────────────────────────
    echo "\n══ is_admin can no longer be claimed by the caller ══\n";

    DB::beginTransaction();

    try {
        $employeeProfile = $db->table('tbluserprofilemaster')
            ->where('sub_institute_id', 6)->where('role_key', 'employee')->value('id');

        $email = 'evidence-' . uniqid() . '@example.test';

        $response = $call('/api/user-signup', [
            'user_name' => 'evidence',
            'password' => 'Evidence12345',
            'first_name' => 'Evidence',
            'email' => $email,
            'user_profile_id' => $employeeProfile,
            'sub_institute_id' => 6,
            'syear' => '2026',
            // The claim. It used to be believed.
            'is_admin' => 1,
        ], $ownerToken);

        $created = $db->table('tbluser')->where('email', $email)->first(['id', 'is_admin', 'user_profile_id']);

        if ($created) {
            printf("  created on an EMPLOYEE profile, request said is_admin=1 -> stored is_admin=%d  %s\n",
                $created->is_admin,
                (int) $created->is_admin === 0 ? 'CORRECT — derived from the profile' : 'WRONG — the claim was believed');
        } else {
            printf("  no user created (HTTP %d) — cannot judge\n", $response->getStatusCode());
        }
    } finally {
        DB::rollBack();
        echo "  (rolled back — no user kept)\n";
    }
} finally {
    $ownerUser->tokens()->where('name', 'platform-gate-evidence')->delete();
    $adminUser->tokens()->where('name', 'platform-gate-evidence')->delete();
}
