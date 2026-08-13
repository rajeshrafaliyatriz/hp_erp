<?php
/**
 * G-SEC-29: HrmsController's two live leaking routes, before and after.
 * departmentAttendanceReport is EXCLUDED - read and cleared as correct code
 * (session-only tenant, varies because it embeds now()).
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
const TOKEN_A = '4554|slYeN3HOca8AMIt2bz1bcl31nkdOOKm80HWZ6MPRe7c12925';
$uriOf = [];
foreach (json_decode(file_get_contents(__DIR__.'/sweeps/c23-result-FULL-912.json'), true) as $r) $uriOf[$r[0]] = $r[1];
$call = function ($uri, $t) use ($kernel) {
    $req = Illuminate\Http\Request::create('/'.ltrim($uri,'/'),'GET',['token'=>TOKEN_A,'type'=>'API','syear'=>'2025','sub_institute_id'=>$t]);
    $req->headers->set('Accept','application/json');
    $req->headers->set('Authorization','Bearer '.explode('|',TOKEN_A,2)[1]);
    try { $res=$kernel->handle($req); return [$res->getStatusCode(), (string)$res->getContent()]; } catch (Throwable $e) { return [0,'EX']; }
};
foreach (['HrmsController@generalSettingIndex','HrmsController@getHolidays'] as $a) {
    $uri = $uriOf[$a] ?? null; if (!$uri) { printf("  %-40s NO URI\n", $a); continue; }
    // same tenant twice first - the fifth verdict, before comparing across tenants
    [$sa,$ba] = $call($uri,7); [$sb,$bb] = $call($uri,7);
    if ($sa === $sb && $ba !== $bb) { printf("  %-40s UNJUDGEABLE (varies from itself)\n", $a); continue; }
    [$s1,$b1] = $call($uri,7); [$s2,$b2] = $call($uri,3);
    $v = ($s1===$s2 && $b1===$b2) ? 'PASS' : (($s2===403||$s2===401) ? 'REFUSED' : (($s1>=500||$s2>=500) ? 'ERROR' : 'LEAK'));
    printf("  %-40s %-8s %db/%db\n", $a, $v, strlen($b1), strlen($b2));
}
