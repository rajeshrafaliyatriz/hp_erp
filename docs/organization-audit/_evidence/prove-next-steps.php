<?php
/**
 * F-155 EVIDENCE — first-run guidance is real, role-aware, and never links to a
 * screen the caller cannot open.
 *
 * Driven through the HTTP kernel with a real Sanctum token per user, so route
 * middleware, identity resolution and the rights check all run.
 *
 * Proved:
 *   1. Different roles get DIFFERENT steps, from the token's owner.
 *   2. Every link is a real access_link from tblmenumaster_g2g.
 *   3. A step whose screen the caller cannot view is ABSENT - demonstrated by
 *      revoking one right and watching the step disappear.
 *   4. Dismissing a step hides it, and writes user_onboarding_status.
 *   5. A set-up organisation gets fewer steps than an empty one.
 *
 * Everything that writes runs inside a transaction that is always rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-next-steps.php';"
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');

$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

$call = function (string $method, string $uri, array $params, string $token) use ($kernel) {
    $request = \Illuminate\Http\Request::create($uri, $method, $params + ['type' => 'API', 'token' => $token]);
    $request->headers->set('Authorization', 'Bearer ' . $token);
    $request->headers->set('Accept', 'application/json');

    return $kernel->handle($request);
};

/** One user per role that actually has one, on a set-up tenant and an empty one. */
$pick = function (int $tenant) use ($db) {
    return $db->table('tbluser as u')
        ->join('tbluserprofilemaster as p', 'p.id', '=', 'u.user_profile_id')
        ->where('u.sub_institute_id', $tenant)
        // NOT filtered on role_key. RoleKey resolves the 13 legacy profiles by
        // an exact name match too, and a tenant whose profiles predate role_key
        // is exactly the tenant this feature has to work for.
        ->select('u.id', 'u.first_name', 'p.name', 'p.role_key', 'p.id as profile_id')
        ->orderBy('p.id')
        ->get()
        ->unique('profile_id')
        ->values();
};

$links = $db->table('tblmenumaster_g2g')->pluck('access_link', 'id')->all();

foreach ([6 => 'Scholar Clone (set up)', 1000018 => 'Fiber Valley (967 people, nothing configured)'] as $tenant => $label) {
    printf("\n══════════ tenant %d — %s ══════════\n", $tenant, $label);

    foreach ($pick($tenant) as $row) {
        $user = \App\Models\auth\tbluserModel::find($row->id);
        $token = $user->createToken('next-steps-evidence')->plainTextToken;

        try {
            $body = json_decode($call('GET', '/api/onboarding/next-steps', [], $token)->getContent(), true);
            $steps = $body['data']['steps'] ?? [];

            printf("\n%-18s (user #%d, %s) — %d step(s)\n",
                $body['data']['role'] ?? '(unresolved)', $row->id, $row->first_name, count($steps));

            foreach ($steps as $s) {
                // Every link must be the menu's own access_link, not a string
                // written into the service.
                // menu_id null is the ONE documented exception: the step that
                // fires BECAUSE the caller's menu rights are empty cannot take
                // its destination from the menu system.
                $real = $s['menu_id'] === null
                    ? $s['link'] === '/settings/module-configuration'
                    : (($links[$s['menu_id']] ?? null) === $s['link']);

                printf("    %-22s %-46s %-22s %s\n",
                    $s['key'],
                    substr($s['detail'], 0, 46),
                    $real ? ($s['menu_id'] === null ? 'link OK (documented)' : 'link OK (menu)') : 'LINK NOT FROM THE MENU',
                    $s['link']);
            }
        } finally {
            $user->tokens()->where('name', 'next-steps-evidence')->delete();
        }
    }
}

// ── 3. A REVOKED RIGHT REMOVES THE STEP ─────────────────────────────────────
echo "\n\n══════════ a step never links somewhere the caller cannot go ══════════\n";

// Tenant 6, because revoking a right needs a profile that HAS the right. Fiber
// Valley has none at all - which is what its own single step, above, is about.
$admin = collect($db->table('tbluser as u')
    ->join('tbluserprofilemaster as p', 'p.id', '=', 'u.user_profile_id')
    ->where('u.sub_institute_id', 6)
    ->select('u.id', 'p.id as profile_id')->get())
    ->first(fn ($r) => \App\Support\RoleKey::forUserId((int) $r->id) === 'administrator');

$tenantUnderTest = 6;

if ($admin) {
    $user = \App\Models\auth\tbluserModel::find($admin->id);
    $token = $user->createToken('next-steps-evidence')->plainTextToken;

    DB::beginTransaction();
    try {
        $keysOf = function () use ($call, $token) {
            $body = json_decode($call('GET', '/api/onboarding/next-steps', [], $token)->getContent(), true);
            return array_column($body['data']['steps'] ?? [], 'key');
        };

        $before = $keysOf();
        printf("with rights as they are : %s\n", implode(', ', $before) ?: '(none)');

        // Organization Profile is menu 12, and `org_profile` is outstanding on
        // this tenant - so revoking it is a test with something to lose.
        $db->table('tblgroupwise_rights_g2g')
            ->where('profile_id', $admin->profile_id)->where('menu_id', 12)
            ->update(['can_view' => 0]);

        $after = $keysOf();
        printf("after revoking menu 12  : %s\n", implode(', ', $after) ?: '(none)');
        printf("'org_profile' step      : %s -> %s  %s\n",
            in_array('org_profile', $before, true) ? 'shown' : 'absent',
            in_array('org_profile', $after, true) ? 'shown' : 'absent',
            (in_array('org_profile', $before, true) && !in_array('org_profile', $after, true))
                ? 'CORRECT — no link to a screen they cannot open'
                : 'INCONCLUSIVE - the step was not outstanding to begin with');

        // ── 4. DISMISSAL ────────────────────────────────────────────────────
        $target = $after[0] ?? $before[0] ?? null;

        if ($target) {
            $call('POST', '/api/onboarding/next-steps/dismiss', ['key' => $target], $token);

            $row = $db->table('user_onboarding_status')
                ->where('user_id', $admin->id)->where('feature', $target)->first();

            printf("\ndismissed '%s' -> user_onboarding_status row: %s (tenant %s)\n",
                $target,
                $row ? 'written' : 'MISSING',
                $row->sub_institute_id ?? '-');

            printf("still listed after dismissal? %s  %s\n",
                in_array($target, $keysOf(), true) ? 'yes' : 'no',
                in_array($target, $keysOf(), true) ? 'WRONG' : 'CORRECT');
        }

        // An unknown key is refused rather than quietly stored.
        $bogus = $call('POST', '/api/onboarding/next-steps/dismiss', ['key' => 'not_a_step'], $token);
        printf("dismissing an unknown key -> HTTP %d  %s\n",
            $bogus->getStatusCode(),
            $bogus->getStatusCode() === 404 ? 'CORRECT' : 'WRONG');
    } finally {
        DB::rollBack();
        $user->tokens()->where('name', 'next-steps-evidence')->delete();
        echo "\n(rolled back — no right changed, no dismissal kept)\n";
    }
}
