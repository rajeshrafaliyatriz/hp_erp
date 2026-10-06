<?php
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
const TOKEN_A = '4554|slYeN3HOca8AMIt2bz1bcl31nkdOOKm80HWZ6MPRe7c12925';
$uriOf = [];
foreach (json_decode(file_get_contents('C:/Users/MILAN/Downloads/hp_erp/docs/phase3/_evidence/sweeps/c23-result-FULL-912.json'), true) as $r) $uriOf[$r[0]] = $r[1];
$targets = ['DepartmentManagementController@index','organizationDetailsController@index','tblmenumasterG2gController@displayUserProfilesG2g'];
$call = function ($uri, $t) use ($kernel) {
    $req = Illuminate\Http\Request::create('/'.ltrim($uri,'/'),'GET',['token'=>TOKEN_A,'type'=>'API','syear'=>'2025','sub_institute_id'=>$t]);
    $req->headers->set('Accept','application/json');
    $req->headers->set('Authorization','Bearer '.explode('|',TOKEN_A,2)[1]);
    try { $res=$kernel->handle($req); return [$res->getStatusCode(), (string)$res->getContent()]; } catch (Throwable $e) { return [0,'EX']; }
};
foreach ($targets as $a) {
    $uri = $uriOf[$a] ?? null; if (!$uri) { printf("  %-50s NO URI\n", $a); continue; }
    [$s1,$b1] = $call($uri,7); [$s2,$b2] = $call($uri,3);
    $v = ($s1===$s2 && $b1===$b2) ? 'PASS' : (($s2===403||$s2===401) ? 'REFUSED' : (($s1>=500||$s2>=500) ? 'ERROR' : 'LEAK'));
    printf("  %-50s %-8s %d/%d  %db/%db\n", $a, $v, $s1, $s2, strlen($b1), strlen($b2));
}
