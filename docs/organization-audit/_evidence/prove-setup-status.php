<?php
/**
 * SPRINT 5 EVIDENCE — the setup checklist reports the truth, for any tenant.
 *
 * Nothing is stored: every number is counted from the tables the rest of the
 * product uses. So a tenant that did its setup through the ordinary screens,
 * never opening this one, still reads as done - and a tenant that clicked
 * through a wizard but changed nothing does not.
 *
 * READ ONLY apart from the roles step, which runs inside a rolled-back
 * transaction.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-setup-status.php';"
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');

$controller = app(\App\Http\Controllers\Api\Organization\OrganizationSetupController::class);

function statusFor($db, $controller, int $tenant, string $label): void
{
    $user = $db->table('tbluser')->where('sub_institute_id', $tenant)
        ->whereNotNull('user_profile_id')->first(['id']);

    if (!$user) {
        printf("\n== %s (tenant %d) ==\n   no user to authenticate as\n", $label, $tenant);
        return;
    }

    $model = \App\Models\auth\tbluserModel::find($user->id);
    $token = $model->createToken('setup-evidence')->plainTextToken;

    $request = \Illuminate\Http\Request::create('/x', 'GET', [
        'type' => 'API', 'token' => $token, 'sub_institute_id' => $tenant,
    ]);
    $request->headers->set('Authorization', 'Bearer ' . $token);

    $body = json_decode($controller->status($request)->getContent(), true);
    $model->tokens()->where('name', 'setup-evidence')->delete();

    $data = $body['data'] ?? [];

    printf("\n== %s (tenant %d) — %d of %d done ==\n", $label, $tenant,
        $data['done'] ?? 0, $data['total'] ?? 0);

    foreach ($data['steps'] ?? [] as $step) {
        printf("   [%s] %-22s %s\n",
            $step['done'] ? 'x' : ' ',
            $step['label'],
            $step['detail']);
    }
}

$sprint1 = (int) $db->table('school_setup')->where('SchoolName', 'Sprint1 Test Org')->value('id');

statusFor($db, $controller, 6, 'Scholar Clone — an established tenant');
if ($sprint1) {
    statusFor($db, $controller, $sprint1, 'Sprint1 Test Org — a brand-new tenant');
}

// ── The one step that can be completed in place ─────────────────────────────
if ($sprint1) {
    $before = $db->table('tbluserprofilemaster')->where('sub_institute_id', $sprint1)->count();

    $admin = $db->table('tbluser')->where('sub_institute_id', $sprint1)->first(['id']);
    $model = \App\Models\auth\tbluserModel::find($admin->id);
    $token = $model->createToken('roles-evidence')->plainTextToken;

    DB::beginTransaction();
    try {
        $request = \Illuminate\Http\Request::create('/x', 'POST', [
            'type' => 'API', 'token' => $token, 'sub_institute_id' => $sprint1,
        ]);
        $request->headers->set('Authorization', 'Bearer ' . $token);

        $body = json_decode($controller->createRoles($request)->getContent(), true);
        $after = $db->table('tbluserprofilemaster')->where('sub_institute_id', $sprint1)->count();

        printf("\n== Creating the standard roles on tenant %d ==\n", $sprint1);
        printf("   %s\n", $body['message'] ?? '');
        printf("   profiles: %d -> %d\n", $before, $after);

        foreach ($db->table('tbluserprofilemaster')->where('sub_institute_id', $sprint1)
            ->orderBy('id')->get(['name', 'role_key', 'data_scope']) as $p) {
            printf("     %-20s %-20s scope=%s\n", $p->name, $p->role_key ?? '(none)', $p->data_scope ?? '-');
        }

        // Running it twice must change nothing.
        $again = json_decode($controller->createRoles($request)->getContent(), true);
        printf("   run again: %s\n", $again['message'] ?? '');
    } finally {
        DB::rollBack();
        $model->tokens()->where('name', 'roles-evidence')->delete();
        echo "\n(the roles step was rolled back)\n";
    }
}
