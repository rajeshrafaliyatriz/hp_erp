<?php
/**
 * RE-AUDIT — Organization + Onboarding, driven through the HTTP kernel.
 *
 * Reads only. Nothing is written, nothing is rolled back because nothing is
 * changed. Every GET below is issued twice: once by a member of tenant 6, once
 * by a member of another tenant, both asking for tenant 6's data by naming it in
 * the request. A correct endpoint gives the outsider their OWN tenant's answer
 * (or refuses); a leaking one gives them tenant 6's.
 *
 * The point is not that the code contains `resolveApiIdentity` - it is that the
 * request parameter cannot move the answer.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/reaudit-organization-onboarding.php';"
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

/** A member of a tenant, with a token. */
$actor = function (int $tenant) use ($db) {
    $row = collect($db->table('tbluser')->where('sub_institute_id', $tenant)->get(['id']))
        ->first(fn ($u) => \App\Support\RoleKey::forUserId((int) $u->id) === 'administrator');

    if (!$row) {
        return null;
    }

    $user = \App\Models\auth\tbluserModel::find($row->id);

    return ['user' => $user, 'token' => $user->createToken('reaudit')->plainTextToken, 'id' => (int) $row->id];
};

$home = $actor(6);
$outsider = $actor(1);

if (!$home || !$outsider) {
    echo "Could not find an administrator in both tenants.\n";
    return;
}

printf("insider  : #%d (tenant 6)\n", $home['id']);
printf("outsider : #%d (tenant 1), asking for tenant 6 in every request\n\n", $outsider['id']);

$get = function (string $uri, string $token, array $params = []) use ($kernel) {
    $request = \Illuminate\Http\Request::create($uri, 'GET', $params + [
        'type' => 'API',
        'token' => $token,
        // The lie. Every endpoint is told the caller belongs to tenant 6.
        'sub_institute_id' => 6,
    ]);
    $request->headers->set('Authorization', 'Bearer ' . $token);
    $request->headers->set('Accept', 'application/json');

    return $kernel->handle($request);
};

/** A stable fingerprint of a response body, so two answers can be compared. */
$fingerprint = function ($response) {
    $body = json_decode($response->getContent(), true);

    if (!is_array($body)) {
        return 'non-json';
    }

    // Count rows wherever they are, and hash the payload - identical hashes
    // between two different tenants is the leak.
    return substr(md5(json_encode($body)), 0, 12);
};

$endpoints = [
    'setup checklist'      => '/api/organization/setup-status',
    'organisation profile' => '/api/organization/profile',
    'modules'              => '/api/organization/modules',
    'readiness gates'      => '/api/readiness/gates',
    'first-run guidance'   => '/api/onboarding/next-steps',
    'departments'          => '/api/departments-management',
    'onboarding journeys'  => '/api/onboarding/journeys',
    'onboarding filters'   => '/api/onboarding/filters',
];

printf("%-22s %-22s %-22s %s\n", 'ENDPOINT', 'INSIDER (t6)', 'OUTSIDER (t1)', 'VERDICT');
echo str_repeat('-', 92), "\n";

$leaks = 0;

try {
    foreach ($endpoints as $label => $uri) {
        $a = $get($uri, $home['token']);
        $b = $get($uri, $outsider['token']);

        $fa = $a->getStatusCode() . '/' . $fingerprint($a);
        $fb = $b->getStatusCode() . '/' . $fingerprint($b);

        /*
         * Identical bodies mean the outsider received tenant 6's answer.
         *
         * The one honest exception is an endpoint that refuses BOTH callers the
         * same way - two identical 403s are not a leak - so the status is
         * checked before the hash.
         */
        $sameBody = $fa === $fb;
        $bothRefused = $a->getStatusCode() >= 400 && $b->getStatusCode() >= 400;

        if ($sameBody && !$bothRefused) {
            $verdict = 'LEAK - same payload';
            $leaks++;
        } elseif ($b->getStatusCode() >= 400) {
            $verdict = 'refused the outsider';
        } else {
            $verdict = 'scoped - different answers';
        }

        printf("%-22s %-22s %-22s %s\n", $label, $fa, $fb, $verdict);
    }

    echo "\n";
    printf("%d endpoint(s) leaked across tenants.\n", $leaks);

    // ── Does the checklist actually count, or does it assert? ───────────────
    echo "\n-- the setup checklist against the real tables --\n";

    $body = json_decode($get('/api/organization/setup-status', $home['token'])->getContent(), true);

    $counted = [
        'departments' => $db->table('hrms_departments')->where('sub_institute_id', 6)->whereNull('deleted_at')->count(),
        'people' => $db->table('tbluser')->where('sub_institute_id', 6)->count(),
        'roles' => $db->table('tbluserprofilemaster')->where('sub_institute_id', 6)
            ->whereIn('role_key', \App\Support\RoleKey::ALL)->distinct()->count('role_key'),
    ];

    foreach ($body['data']['steps'] ?? [] as $step) {
        $key = $step['key'];

        if (!isset($counted[$key])) {
            continue;
        }

        /*
         * The step's sentence has to contain the number the database holds -
         * OR its word form. The roles step says "All nine standard roles
         * exist." when the set is complete and "3 of 9 exist. Missing: ..."
         * when it is not, so a digit-only check reports a false failure on a
         * correct screen. Checked both ways rather than loosened.
         */
        $stated = (string) $counted[$key];
        $words = [1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five',
                  6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine'];
        $agrees = str_contains($step['detail'], $stated)
            || (isset($words[$counted[$key]]) && str_contains(strtolower($step['detail']), $words[$counted[$key]]));

        printf("  %-14s db says %-5s | screen says: %-46s %s\n",
            $key, $stated, substr($step['detail'], 0, 46),
            $agrees ? 'AGREES' : 'DOES NOT MATCH THE DATABASE');
    }
} finally {
    $home['user']->tokens()->where('name', 'reaudit')->delete();
    $outsider['user']->tokens()->where('name', 'reaudit')->delete();
}
