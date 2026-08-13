<?php
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
const TOKEN_A = '4554|slYeN3HOca8AMIt2bz1bcl31nkdOOKm80HWZ6MPRe7c12925';
$uriOf = [];
foreach (json_decode(file_get_contents('C:/Users/MILAN/Downloads/hp_erp/docs/phase3/_evidence/sweeps/c23-result-FULL-912.json'), true) as $r) $uriOf[$r[0]] = $r[1];
$uri = $uriOf['AuditController@export'];
echo "route: $uri\n\n";
$call = function ($t) use ($kernel, $uri) {
    $req = Illuminate\Http\Request::create('/'.ltrim($uri,'/'),'GET',['token'=>TOKEN_A,'type'=>'API','syear'=>'2025','sub_institute_id'=>$t]);
    $req->headers->set('Accept','application/json');
    $req->headers->set('Authorization','Bearer '.explode('|',TOKEN_A,2)[1]);
    $res = $kernel->handle($req);
    $b = json_decode((string)$res->getContent(), true);
    return [$res->getStatusCode(), is_array($b['data'] ?? null) ? count($b['data']) : -1, strlen((string)$res->getContent())];
};
// THE DECISIVE TEST: call TWICE WITH THE SAME TENANT.
// If those differ too, the difference is a SIDE EFFECT, not a leak.
[$s1,$n1,$l1] = $call(7);
[$s2,$n2,$l2] = $call(7);
[$s3,$n3,$l3] = $call(3);
printf("  tenant 7, call 1 : %d rows, %d bytes\n", $n1, $l1);
printf("  tenant 7, call 2 : %d rows, %d bytes   <- SAME tenant, second call\n", $n2, $l2);
printf("  tenant 3 asked   : %d rows, %d bytes\n", $n3, $l3);
printf("\n  same-tenant calls differ : %s\n", $l1 !== $l2 ? "YES - the endpoint MUTATES what it reads" : "no");
// does the row count grow by exactly one each call?
printf("  growth per call          : %d row(s)\n", $n2 - $n1);
// are any returned rows from another tenant?
$req = Illuminate\Http\Request::create('/'.ltrim($uri,'/'),'GET',['token'=>TOKEN_A,'type'=>'API','syear'=>'2025','sub_institute_id'=>3]);
$req->headers->set('Accept','application/json'); $req->headers->set('Authorization','Bearer '.explode('|',TOKEN_A,2)[1]);
$b = json_decode((string)$kernel->handle($req)->getContent(), true);
$tenants = [];
foreach (($b['data'] ?? []) as $r) if (isset($r['sub_institute_id'])) $tenants[$r['sub_institute_id']] = true;
printf("  tenants present in the tenant-3 request's rows : %s\n", $tenants ? implode(', ', array_keys($tenants)) : '(no sub_institute_id in payload)');
