<?php
/**
 * PART A EVIDENCE — what does a tenant actually see in the sidebar?
 *
 * Calls the real displaySidebarMenu endpoint as the administrator of each
 * tenant, and counts the modules and screens returned. READ ONLY.
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');

function sidebarFor($db, int $tenant): array
{
    $profile = $db->table('tbluserprofilemaster')
        ->where('sub_institute_id', $tenant)
        ->where(function ($q) {
            $q->where('role_key', 'administrator')->orWhere('name', 'like', '%dmin%');
        })
        ->first(['id', 'name', 'role_key']);

    if (!$profile) {
        return ['error' => 'no administrator profile'];
    }

    $user = $db->table('tbluser')
        ->where('sub_institute_id', $tenant)
        ->where('user_profile_id', $profile->id)
        ->first(['id']);

    if (!$user) {
        return ['error' => 'no user holds the administrator profile', 'profile' => $profile->name];
    }

    $model = \App\Models\auth\tbluserModel::find($user->id);
    $token = $model->createToken('frontdoor')->plainTextToken;

    $request = \Illuminate\Http\Request::create('/x', 'GET', [
        'type' => 'API',
        'token' => $token,
        'sub_institute_id' => $tenant,
        'profile_id' => $profile->id,
    ]);
    $request->headers->set('Authorization', 'Bearer ' . $token);

    $response = app(\App\Http\Controllers\user\tblmenumasterG2gController::class)->displaySidebarMenu($request);
    $body = json_decode($response->getContent(), true);

    $model->tokens()->where('name', 'frontdoor')->delete();

    $modules = $body['data'] ?? $body['menu'] ?? $body ?? [];
    if (!is_array($modules)) {
        $modules = [];
    }

    $names = [];
    $screens = 0;
    foreach ($modules as $m) {
        if (!is_array($m)) {
            continue;
        }
        $names[] = $m['menu_name'] ?? $m['name'] ?? '?';
        $screens += count($m['submenu'] ?? $m['children'] ?? $m['menus'] ?? []);
    }

    return [
        'profile' => $profile->name,
        'modules' => count($names),
        'names' => $names,
        'children' => $screens,
        'rights' => $db->table('tblgroupwise_rights_g2g')->where('sub_institute_id', $tenant)->count(),
    ];
}

foreach ([6 => 'Scholar Clone (demo, set up)', 1000011 => 'xyz (fresh signup)', 1000018 => 'Fiber Valley (967 users)'] as $tenant => $label) {
    $r = sidebarFor($db, $tenant);
    printf("\n== tenant %s — %s ==\n", $tenant, $label);

    if (isset($r['error'])) {
        printf("  %s\n", $r['error']);
        continue;
    }

    printf("  tenant-stamped rights rows: %d\n", $r['rights']);
    printf("  MODULES IN SIDEBAR: %d\n", $r['modules']);
    foreach ($r['names'] as $n) {
        printf("    - %s\n", $n);
    }
}
