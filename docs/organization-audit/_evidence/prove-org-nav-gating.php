<?php
/**
 * EVIDENCE — an employee can neither SEE nor WRITE the organisation.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE REPORT
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * "As an employee why am I seeing the organization setting?"
 *
 * Two separate faults behind one sentence, and the second is the serious one.
 *
 * SEEING IT. `tblgroupwise_rights_g2g` grants the Employee profile `can_view = 1`
 * on Organizational Management, Organization Setup and Organization Profile - and
 * on tenant 6 also Role & Permissions. Organization Profile is the screen that
 * displays the company logo, which is also the answer to the earlier report of
 * "profile image of my organization's setting": the avatar code was never
 * involved, a menu grant was.
 *
 * WRITING IT. Those rows carry `can_edit = 0`, and nothing on the server reads
 * either column. `authMiddleware` accepts ANY valid Sanctum token;
 * `MenuMiddleware` returns early on `type=API` and aborts on nothing;
 * `organizationDetailsController` has no role check of its own. So
 * `POST /settings/organization_data` with a plain employee's token rewrote the
 * organisation's legal name, CIN, GSTIN, PAN, registered address and logo.
 *
 * ── WHAT THIS ASSERTS ───────────────────────────────────────────────────────
 *
 *   1. No non-administrator profile holds can_view on the administrative menus.
 *      The auditor keeps Audit & Activity Center - reading the trail is that
 *      role's entire purpose, and the API already admits administrator and
 *      auditor to it.
 *   2. An employee is REFUSED on both the read and the write. The write matters
 *      most: that is the escalation, and it is the one a browser pass can never
 *      find, because the button is not on their screen.
 *   3. An administrator still gets through - INCLUDING a legacy profile named
 *      "Admin" with no role_key, which ten live tenants rely on. Gating this
 *      route without that would have locked real administrators out of their own
 *      organisation, which is a worse outcome than the bug.
 *   4. The five personal sections still work for an employee, because the point
 *      was never to take things away from them.
 *
 * Runs on DEV inside a transaction that is always rolled back. Nothing is kept.
 *
 *   php artisan tinker --execute="require getcwd().'/docs/organization-audit/_evidence/prove-org-nav-gating.php';"
 */

use Illuminate\Support\Facades\DB;

DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

\Illuminate\Support\Facades\Mail::fake();

$tenant = 6;

/**
 * The menus that describe the organisation itself rather than somebody's work.
 *
 * Named rather than matched by pattern: a regex over menu names is how "Monthly
 * Attendance Report" ends up revoked because it contains "report".
 */
$ADMIN_MENUS = [
    'Organizational Management',
    'Organization Setup',
    'Organization Profile',
    'Role & Permissions',
    'Guided Setup',
    'Audit & Activity Center',
];

/** The one exception, and why. */
$KEEPS = ['Audit & Activity Center' => ['administrator', 'auditor']];

$correct = 0;
$wrong = 0;

$ok = function (string $message) use (&$correct) {
    printf("  CORRECT  %s\n", $message);
    $correct++;
};

$bad = function (string $message) use (&$wrong) {
    printf("  WRONG    %s\n", $message);
    $wrong++;
};

echo "══ 1. the navigation does not offer the organisation to non-administrators ══\n";

$grants = $db->table('tblgroupwise_rights_g2g as r')
    ->join('tblmenumaster_g2g as m', 'm.id', '=', 'r.menu_id')
    ->join('tbluserprofilemaster as p', 'p.id', '=', 'r.profile_id')
    ->whereIn('m.menu_name', $ADMIN_MENUS)
    ->where('r.can_view', 1)
    ->get(['r.sub_institute_id', 'p.id as pid', 'p.name', 'p.role_key', 'm.menu_name']);

/*
 * A grant is resolved the way the SERVER resolves it, through RoleKey - so a
 * legacy profile named "Admin" counts as an administrator here exactly as it does
 * at the gate. Comparing on the display name instead is how the two would
 * disagree.
 */
$offenders = [];

foreach ($grants as $g) {
    $roleKey = \App\Support\RoleKey::forProfileId((int) $g->pid);
    $permitted = $KEEPS[$g->menu_name] ?? ['administrator'];

    if (in_array($roleKey, $permitted, true)) {
        continue;
    }

    $offenders[] = sprintf('tenant %s / %s / %s',
        $g->sub_institute_id, $roleKey ?? ('"' . $g->name . '"'), $g->menu_name);
}

printf("  %d grants on administrative menus, %d held by a role that should not have them\n",
    $grants->count(), count($offenders));

if (count($offenders) === 0) {
    $ok('no non-administrator profile can see the organisation in the navigation');
} else {
    $bad(sprintf('%d grants remain that should have been revoked', count($offenders)));
    foreach (array_slice($offenders, 0, 8) as $o) {
        printf("             - %s\n", $o);
    }
}

/*
 * AND THE AUDITOR MUST BE ABLE TO REACH THE TRAIL.
 *
 * Asserted positively, because a blanket "revoke the administrative menus" would
 * satisfy the check above while breaking the one role that exists to read the
 * audit log.
 *
 * It turned out there was nothing to keep. On BOTH databases, ZERO auditor
 * profiles held this grant: `hr_executive` had it on ten tenants and the auditor
 * on none. So the role whose entire purpose is reading the trail had no way to
 * open the screen for it, while a role the API refuses could see the menu. The
 * migration therefore GRANTS it rather than merely preserving it, and this
 * assertion is what says so.
 */
$auditorProfiles = $db->table('tbluserprofilemaster')->where('role_key', 'auditor')->count();

$auditorCanSee = $grants->filter(function ($g) {
    return $g->menu_name === 'Audit & Activity Center'
        && \App\Support\RoleKey::forProfileId((int) $g->pid) === 'auditor';
})->count();

if ($auditorProfiles === 0) {
    printf("  (no auditor profile on this database - exception not applicable)\n");
} elseif ($auditorCanSee >= $auditorProfiles) {
    $ok(sprintf('every auditor profile (%d) can reach Audit & Activity Center', $auditorProfiles));
} else {
    $bad(sprintf('only %d of %d auditor profiles can reach Audit & Activity Center',
        $auditorCanSee, $auditorProfiles));
}

DB::beginTransaction();

try {
    $call = function (string $method, string $uri, string $token, array $body = []) use ($kernel) {
        $request = \Illuminate\Http\Request::create(
            $uri, $method, array_merge(['type' => 'API', 'token' => $token], $body)
        );
        $request->headers->set('Accept', 'application/json');

        return $kernel->handle($request);
    };

    /*
     * One account per role, created rather than found. A check that silently
     * skips the roles it cannot find proves least about exactly the roles nobody
     * has tested.
     */
    $actors = [];

    foreach (['employee', 'hr_manager', 'hr_executive', 'auditor', 'administrator'] as $roleKey) {
        $profile = $db->table('tbluserprofilemaster')
            ->where('sub_institute_id', $tenant)->where('role_key', $roleKey)->first(['id']);

        if (!$profile) {
            printf("  %-16s NO PROFILE on tenant %d - skipped\n", $roleKey, $tenant);
            continue;
        }

        $id = $db->table('tbluser')->insertGetId([
            'user_profile_id' => $profile->id,
            'sub_institute_id' => $tenant,
            'first_name' => 'Nav',
            'last_name' => $roleKey,
            'email' => 'nav.' . $roleKey . '@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('NavCheck123'),
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actors[$roleKey] = [
            'id' => $id,
            'token' => \App\Models\auth\tbluserModel::find($id)->createToken('nav-evidence')->plainTextToken,
        ];
    }

    /*
     * AND a legacy administrator - no role_key, profile named "Admin".
     *
     * This is the lockout case. Ten live tenants have exactly this shape, and a
     * gate that refused it would take every one of those organisations away from
     * its own administrator.
     */
    $legacyProfile = $db->table('tbluserprofilemaster')
        ->whereNull('role_key')->whereRaw('LOWER(TRIM(name)) = ?', ['admin'])
        ->first(['id', 'sub_institute_id']);

    if ($legacyProfile) {
        $id = $db->table('tbluser')->insertGetId([
            'user_profile_id' => $legacyProfile->id,
            'sub_institute_id' => $legacyProfile->sub_institute_id,
            'first_name' => 'Legacy',
            'last_name' => 'Admin',
            'email' => 'nav.legacy.admin@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('NavCheck123'),
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actors['legacy Admin'] = [
            'id' => $id,
            'token' => \App\Models\auth\tbluserModel::find($id)->createToken('nav-evidence')->plainTextToken,
        ];
    }

    echo "\n══ 2. the organisation endpoint refuses everybody but an administrator ══\n";

    $ALLOWED = ['administrator', 'legacy Admin'];

    foreach ([
        ['GET',  '/settings/organization_data', [], 'read'],
        ['POST', '/settings/organization_data', ['legal_name' => 'Rewritten By Evidence'], 'WRITE'],
    ] as [$method, $uri, $body, $label]) {
        printf("\n  %s  (%s %s)\n", $label, $method, $uri);

        foreach ($actors as $roleKey => $actor) {
            $shouldPass = in_array($roleKey, $ALLOWED, true);
            $code = $call($method, $uri, $actor['token'], $body)->getStatusCode();

            /*
             * 403 IS THE GATE. Anything else reached the controller.
             *
             * This asserted `$code < 400`, and an administrator's POST came back
             * 500 - the gate had let them through and the controller then threw on
             * a deliberately incomplete body. It was reported as "refused an
             * administrator", the opposite of the truth.
             *
             * What is under test is the GATE, not the controller's validation, so
             * the only question is whether the request was refused for being the
             * wrong role. The status code is printed either way, so a 500 stays
             * visible rather than being laundered into a pass.
             */
            $didPass = $code !== 403;

            printf("    %-16s HTTP %-4d %s\n", $roleKey, $code,
                $didPass === $shouldPass
                    ? ($shouldPass ? 'CORRECT - an administrator, allowed' : 'CORRECT - refused')
                    : ($didPass
                        ? 'WRONG - REACHED IT, and must not have'
                        : 'WRONG - refused an administrator'));

            $didPass === $shouldPass ? $correct++ : $wrong++;
        }
    }

    echo "\n══ 3. nothing was taken away from the employee ══\n";

    if (isset($actors['employee'])) {
        $token = $actors['employee']['token'];

        $checks = [
            'their account'      => ['GET', '/api/account/me', []],
            'their profile'      => ['PUT', '/api/account/profile', ['city' => 'Rajkot']],
            'their preferences'  => ['PUT', '/api/account/preferences', ['theme' => 'dark']],
            'their sessions'     => ['GET', '/api/account/sessions', []],
        ];

        foreach ($checks as $what => [$method, $uri, $body]) {
            $code = $call($method, $uri, $token, $body)->getStatusCode();
            printf("    %-18s HTTP %-4d %s\n", $what, $code,
                $code === 200 ? 'CORRECT - still theirs' : 'WRONG - an employee lost their own settings');
            $code === 200 ? $correct++ : $wrong++;
        }

        // And the photo specifically, since that is what the report asked for.
        $code = $call('GET', '/api/account/me', $token)->getStatusCode();
        $me = json_decode($call('GET', '/api/account/me', $token)->getContent(), true);
        $hasPhotoField = is_array($me) && array_key_exists('image_url', $me['data']['profile'] ?? []);
        printf("    %-18s %s\n", 'photo field',
            $hasPhotoField ? 'CORRECT - image_url is present, so a photo can be shown and set'
                           : 'WRONG - no image_url on the profile payload');
        $hasPhotoField ? $correct++ : $wrong++;
    }
} finally {
    DB::rollBack();
    echo "\n(rolled back - no account or token kept)\n";
}

printf("\n%d CORRECT, %d WRONG\n", $correct, $wrong);
