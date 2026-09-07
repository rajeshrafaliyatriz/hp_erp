<?php
/**
 * PART A EVIDENCE — what does a tenant's administrator actually see?
 *
 * Calls the real displaySidebarMenu endpoint as the administrator of each
 * tenant and prints the tree. READ ONLY.
 *
 * NOTE ON THE RESPONSE SHAPE — this cost a false finding once. The nesting key
 * CHANGES BY LEVEL: a module carries its children under `menus`
 * (tblmenumasterG2gController::displaySidebarMenu), and a menu carries its
 * children under `submenus` (buildMenuTree:311). Reading `menus` at both levels
 * makes level 3 look empty and the product look broken when it is not.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-frontdoor.php';"
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');

/** @return array{modules:int, tree:string, rights:int}|array{error:string} */
function sidebarFor($db, int $tenant): array
{
    $profile = $db->table('tbluserprofilemaster')
        ->where('sub_institute_id', $tenant)
        ->where(function ($q) {
            $q->where('role_key', 'administrator')->orWhere('name', 'like', '%dmin%');
        })
        ->first(['id', 'name']);

    if (!$profile) {
        return ['error' => 'no administrator profile on this tenant'];
    }

    $user = $db->table('tbluser')
        ->where('sub_institute_id', $tenant)
        ->where('user_profile_id', $profile->id)
        ->first(['id']);

    if (!$user) {
        return ['error' => 'no user holds the administrator profile — nobody can sign in'];
    }

    $model = \App\Models\auth\tbluserModel::find($user->id);
    $token = $model->createToken('frontdoor-evidence')->plainTextToken;

    $request = \Illuminate\Http\Request::create('/x', 'GET', [
        'type' => 'API',
        'token' => $token,
        'sub_institute_id' => $tenant,
        'profile_id' => $profile->id,
    ]);
    $request->headers->set('Authorization', 'Bearer ' . $token);

    $body = json_decode(
        app(\App\Http\Controllers\user\tblmenumasterG2gController::class)
            ->displaySidebarMenu($request)->getContent(),
        true
    );

    $model->tokens()->where('name', 'frontdoor-evidence')->delete();

    $modules = $body['data'] ?? [];
    $lines = [];

    foreach ((array) $modules as $module) {
        $lines[] = '    ' . ($module['label'] ?? '?');

        foreach ((array) ($module['menus'] ?? []) as $menu) {
            $lines[] = '      - ' . ($menu['label'] ?? '?');

            foreach ((array) ($menu['submenus'] ?? []) as $leaf) {
                $lines[] = '          * ' . ($leaf['label'] ?? '?');
            }
        }
    }

    return [
        'modules' => count((array) $modules),
        'tree' => implode("\n", $lines),
        'rights' => $db->table('tblgroupwise_rights_g2g')->where('sub_institute_id', $tenant)->count(),
    ];
}

$tenants = [
    6 => 'Scholar Clone — the prepared demo tenant',
    1000011 => 'xyz — created through the real signup endpoint',
    1000018 => 'Fiber Valley — 967 employees',
];

// Include the Sprint 1 test tenant when it exists.
$sprint1 = $db->table('school_setup')->where('SchoolName', 'Sprint1 Test Org')->value('id');
if ($sprint1) {
    $tenants[(int) $sprint1] = 'Sprint1 Test Org — created after the Sprint 1 fix';
}

foreach ($tenants as $tenant => $label) {
    $result = sidebarFor($db, $tenant);

    printf("\n== tenant %s — %s ==\n", $tenant, $label);

    if (isset($result['error'])) {
        printf("   %s\n", $result['error']);
        continue;
    }

    printf("   tenant-stamped rights rows: %d\n", $result['rights']);
    printf("   MODULES IN SIDEBAR: %d\n", $result['modules']);

    if ($result['tree'] !== '') {
        echo $result['tree'] . "\n";
    }
}
