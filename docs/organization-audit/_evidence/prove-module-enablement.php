<?php
/**
 * SPRINT 4 EVIDENCE — turning a module on actually turns it on.
 *
 * Runs against the Sprint 1 test tenant on DEV, inside a transaction that is
 * ALWAYS rolled back. Drives the real endpoints with a real administrator token
 * and then asks displaySidebarMenu what changed, because the sidebar is the
 * thing the customer actually sees.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-module-enablement.php';"
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');

$tenant = (int) $db->table('school_setup')->where('SchoolName', 'Sprint1 Test Org')->value('id');

if (!$tenant) {
    echo "The Sprint1 Test Org tenant does not exist on this database.\n";

    return;
}

$profile = $db->table('tbluserprofilemaster')
    ->where('sub_institute_id', $tenant)->where('role_key', 'administrator')
    ->first(['id', 'name']);

$admin = $db->table('tbluser')
    ->where('sub_institute_id', $tenant)->where('user_profile_id', $profile->id)
    ->first(['id']);

$user = \App\Models\auth\tbluserModel::find($admin->id);
$token = $user->createToken('modules-evidence')->plainTextToken;

$request = function (string $method, array $params) use ($token, $tenant) {
    $r = \Illuminate\Http\Request::create('/x', $method, $params + [
        'type' => 'API',
        'token' => $token,
        'sub_institute_id' => $tenant,
        'syear' => date('Y'),
    ]);
    $r->headers->set('Authorization', 'Bearer ' . $token);

    return $r;
};

/** What the customer would actually see in their sidebar. */
$sidebar = function () use ($request, $profile) {
    $body = json_decode(
        app(\App\Http\Controllers\user\tblmenumasterG2gController::class)
            ->displaySidebarMenu($request('GET', ['profile_id' => $profile->id]))
            ->getContent(),
        true
    );

    return collect($body['data'] ?? [])->pluck('label')->all();
};

$controller = app(\App\Http\Controllers\Api\Organization\ModuleEnablementController::class);

printf("Tenant #%d, administrator profile #%d\n\n", $tenant, $profile->id);

DB::beginTransaction();

try {
    // ── What the endpoint reports today ─────────────────────────────────────
    $body = json_decode($controller->index($request('GET', []))->getContent(), true);
    $modules = collect($body['data']['modules'] ?? []);

    echo "MODULES THE ENDPOINT OFFERS (real counts, from the real catalogue)\n";
    foreach ($modules as $m) {
        printf("  [%s] %-28s %2d screens / %2d menus%s\n",
            $m['enabled'] ? 'x' : ' ',
            $m['name'],
            $m['screens'],
            $m['menus'],
            $m['always_on'] ? '   (always on)' : '');
    }

    printf("\nSidebar before: %s\n", implode(', ', $sidebar()) ?: '(nothing)');

    // ── Turn everything on ──────────────────────────────────────────────────
    $all = $modules->pluck('id')->all();
    $res = $controller->store($request('POST', ['module_ids' => $all]));
    printf("\nPOST all %d modules -> HTTP %d  %s\n",
        count($all), $res->getStatusCode(),
        json_decode($res->getContent(), true)['message'] ?? '');

    printf("Sidebar after:  %s\n", implode(', ', $sidebar()) ?: '(nothing)');

    // ── Turn two back off ───────────────────────────────────────────────────
    $keep = $modules->reject(fn ($m) => in_array($m['name'], ['LMS', 'Agentic AI'], true))
        ->pluck('id')->all();
    $res = $controller->store($request('POST', ['module_ids' => $keep]));
    printf("\nPOST without LMS and Agentic AI -> %s\n",
        json_decode($res->getContent(), true)['message'] ?? '');
    printf("Sidebar now:    %s\n", implode(', ', $sidebar()) ?: '(nothing)');

    // ── The always-on rule ──────────────────────────────────────────────────
    $res = $controller->store($request('POST', ['module_ids' => []]));
    printf("\nPOST an EMPTY set -> %s\n", json_decode($res->getContent(), true)['message'] ?? '');
    printf("Sidebar now:    %s\n", implode(', ', $sidebar()) ?: '(nothing)');
    echo "  (Main Dashboard and Organizational Management cannot be switched off -\n";
    echo "   an organisation must keep the screens it would need to recover.)\n";

    // ── Saving twice must not duplicate: the table has no unique key ────────
    $controller->store($request('POST', ['module_ids' => $all]));
    $controller->store($request('POST', ['module_ids' => $all]));

    $dupes = $db->table('tblgroupwise_rights_g2g')
        ->where('profile_id', $profile->id)
        ->select('menu_id', DB::raw('COUNT(*) c'))
        ->groupBy('menu_id')->having('c', '>', 1)->get();

    $stamped = $db->table('tblgroupwise_rights_g2g')
        ->where('profile_id', $profile->id)->whereNull('sub_institute_id')->count();

    printf("\nSaved twice: %d duplicated menu(s), %d row(s) with a NULL tenant\n",
        $dupes->count(), $stamped);
    printf("  %s\n", $dupes->count() === 0 && $stamped === 0
        ? 'Idempotent, and every row is stamped with the tenant.'
        : 'PROBLEM - see above.');
} finally {
    DB::rollBack();
    $user->tokens()->where('name', 'modules-evidence')->delete();
    echo "\n(rolled back)\n";
}
