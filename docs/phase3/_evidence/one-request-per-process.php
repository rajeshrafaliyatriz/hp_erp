<?php
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Models\auth\tbluserModel;
[$who, $uri, $method] = [$argv[1], $argv[2], $argv[3] ?? 'GET'];
$params = ['sub_institute_id' => 1];
if ($method === 'POST') $params['decision'] = 'approved';
if ($who !== 'none') {
    $uid = $who === 'admin' ? 1 : 2;
    $params['token'] = tbluserModel::find($uid)->createToken('one')->plainTextToken;
}
if ($who === 'forged') { $params['user_profile_name'] = 'admin'; unset($params['token']); }
$r = Illuminate\Http\Request::create($uri, $method, $params);
$r->headers->set('Accept','application/json');
$resp = $kernel->handle($r);
$d = json_decode($resp->getContent(), true);
$rows = is_array($d['data'] ?? null) ? count($d['data']) : null;
printf("  %-10s %-34s -> HTTP %d%s  %s\n", $who, $uri, $resp->getStatusCode(),
  $rows === null ? '' : "  rows=$rows", substr(strip_tags($resp->getContent()), 0, 40));
DB::table('personal_access_tokens')->where('name','one')->delete();
