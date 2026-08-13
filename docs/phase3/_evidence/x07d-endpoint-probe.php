<?php
/**
 * X-07d. The endpoint's guard proven BY REQUEST, per role, not by reading the
 * route file. Two roles, opposite outcomes - one passing case would not show the
 * guard is doing anything (the warning_days shape: config decides only if two
 * configs give two answers).
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$k = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\auth\tbluserModel; use Illuminate\Support\Facades\DB;

foreach (['administrator', 'hr_manager', 'employee'] as $role) {
    $p = DB::table('tbluserprofilemaster')->where('sub_institute_id', 3)->where('role_key', $role)->first();
    if (!$p) { printf("  %-14s no profile in tenant 3\n", $role); continue; }
    $uid = DB::table('tbluser')->where('sub_institute_id', 3)
        ->where('user_profile_id', $p->id)->value('id')
        ?: DB::table('tbluser')->where('sub_institute_id', 3)->value('id');
    $tok = tbluserModel::find($uid)->createToken('x07d')->plainTextToken;

    $req = Illuminate\Http\Request::create('/api/readiness/gates', 'GET',
        ['token' => $tok, 'type' => 'API', 'user_profile_name' => $p->name ?? $role]);
    $req->headers->set('Accept', 'application/json');
    $res = $k->handle($req);
    $b = json_decode($res->getContent(), true);
    printf("  %-14s HTTP %-4d %s\n", $role, $res->getStatusCode(),
        $res->getStatusCode() === 200
            ? 'sees ' . count($b['gates'] ?? []) . ' gates; losing[0]=' . substr((string)($b['gates'][0]['losing'] ?? '-'), 0, 44)
            : substr((string)($b['message'] ?? ''), 0, 60));
}
DB::table('personal_access_tokens')->where('name', 'x07d')->delete();
echo "cleaned up: probe tokens deleted\n";
