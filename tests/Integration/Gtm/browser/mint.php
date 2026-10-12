<?php
// Mints short-lived Sanctum tokens named gtm-e2e and prints browser session JSON. Run from D:\hp_erp.
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\auth\tbluserModel as U;
use Illuminate\Support\Facades\DB;
$mode = $argv[1] ?? 'mint';
if ($mode === 'cleanup') {
    DB::table('personal_access_tokens')->where('name', 'gtm-e2e')->delete();
    $id = DB::table('tbluser')->where('user_name', 'like', 'verify_e2e_exec_%')->pluck('id');
    DB::table('personal_access_tokens')->whereIn('tokenable_id', $id)->delete();
    DB::table('tbluser')->whereIn('id', $id)->delete();
    echo "cleaned\n"; exit;
}
$out = [];
$exec = U::where('user_name', 'like', 'verify_e2e_exec_%')->first()
    ?? U::find(DB::table('tbluser')->insertGetId(['first_name' => 'Verify', 'last_name' => 'E2E Exec', 'user_name' => 'verify_e2e_exec_' . time(), 'email' => 'verify.e2e.exec@example.invalid', 'password' => 'x', 'user_profile_id' => 72, 'sub_institute_id' => 6, 'status' => 1]));
$users = ['admin' => U::find(28), 'executive' => $exec, 'employee' => U::where('sub_institute_id', 6)->where('user_profile_id', 17)->first(), 'other' => U::where('sub_institute_id', '!=', 6)->whereNotNull('sub_institute_id')->where('user_profile_id', 16)->first()];
foreach ($users as $k => $u) {
    if (! $u) { continue; }
    $p = DB::table('tbluserprofilemaster')->where('id', $u->user_profile_id)->first();
    $out[$k] = ['token' => $u->createToken('gtm-e2e')->plainTextToken, 'user_id' => $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name), 'email' => $u->email, 'profile_id' => $u->user_profile_id, 'profile_name' => $p->name ?? null, 'role_key' => $p->role_key ?? null, 'sub_institute_id' => $u->sub_institute_id, 'syear' => DB::table('academic_year')->where('sub_institute_id', $u->sub_institute_id)->orderByDesc('syear')->value('syear') ?? date('Y')];
}
echo json_encode($out);
