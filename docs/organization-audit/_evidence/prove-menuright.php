<?php
/**
 * F-152 EVIDENCE — a menu right is now ENFORCED at the API, and enforcing it
 * does not lock out the tenants whose rights rows were never stamped.
 *
 * Dispatches through the real HTTP kernel so every middleware runs. READ ONLY.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-menuright.php';"
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');

$menuId = (int) $db->table('tblmenumaster_g2g')
    ->where('access_link', '/module/organizational-management/readiness-gates')->value('id');

printf("Readiness Gates is menu %d, and /api/readiness/gates now carries menuright:%d,view\n\n", $menuId, $menuId);

function hit($db, int $tenant, string $roleKey, int $menuId): void
{
    $profile = $db->table('tbluserprofilemaster')
        ->where('sub_institute_id', $tenant)->where('role_key', $roleKey)->first(['id', 'name']);

    if (!$profile) { printf("  %-16s no such profile on tenant %d\n", $roleKey, $tenant); return; }

    $user = $db->table('tbluser')->where('sub_institute_id', $tenant)
        ->where('user_profile_id', $profile->id)->first(['id']);

    if (!$user) { printf("  %-16s nobody holds it on tenant %d\n", $roleKey, $tenant); return; }

    // How is this profile's grant for that menu actually stamped?
    $rights = $db->table('tblgroupwise_rights_g2g')
        ->where('profile_id', $profile->id)->where('menu_id', $menuId)->get(['sub_institute_id', 'can_view']);

    $stamp = $rights->isEmpty() ? 'no row'
        : $rights->map(fn ($r) => ($r->sub_institute_id === null ? 'NULL' : $r->sub_institute_id) . '/view=' . $r->can_view)->implode(' ');

    $model = \App\Models\auth\tbluserModel::find($user->id);
    $token = $model->createToken('menuright')->plainTextToken;

    $request = \Illuminate\Http\Request::create('/api/readiness/gates', 'GET', [
        'type' => 'API', 'token' => $token, 'sub_institute_id' => $tenant,
    ]);
    $request->headers->set('Accept', 'application/json');

    $response = app(\Illuminate\Contracts\Http\Kernel::class)->handle($request);
    $model->tokens()->where('name', 'menuright')->delete();

    printf("  tenant %-8s %-16s rights[%s] -> HTTP %d %s\n",
        $tenant, $roleKey, $stamp, $response->getStatusCode(),
        $response->getStatusCode() === 200 ? 'allowed' : mb_substr(
            json_decode($response->getContent(), true)['message'] ?? '', 0, 52));
}

echo "A role WITH the right:\n";
hit($db, 6, 'administrator', $menuId);
hit($db, 6, 'hr_manager', $menuId);

echo "\nA role WITHOUT it (should be refused, and by the menu right or the profile gate):\n";
hit($db, 6, 'employee', $menuId);

echo "\nA tenant whose OTHER rights are mostly unstamped - the case that made this unshippable:\n";
hit($db, 2, 'administrator', $menuId);
hit($db, 5, 'administrator', $menuId);
