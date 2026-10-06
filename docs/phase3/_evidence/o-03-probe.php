<?php
/**
 * O-03. G-SEC-11 recorded this controller with 2 DIFFERING ROUTES and the register
 * left them unexplained. This decides it by measurement, not by reading the helper
 * - clearing a route by reading a helper is what the register refused to do.
 *
 * FIXTURE: institute_google_credentials and excel_templates each hold exactly one
 * row for tenant 3 and one for tenant 6. A cross-tenant read returns an
 * IDENTIFIABLY WRONG row id, not an empty result. That is the known-negative: the
 * probe can tell the two outcomes apart (R29).
 *
 * SAFETY: the leak test sends tenant A's token with tenant B requested. If the
 * guard holds, every route refuses and NOTHING IS WRITTEN - which is why the two
 * POST routes can be probed at all. Any 2xx on a POST is itself the finding.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\auth\tbluserModel;
use Illuminate\Support\Facades\DB;

$cred = [];
foreach ([3, 6] as $t) {
    $cred[$t] = DB::table('institute_google_credentials')->where('sub_institute_id', $t)->value('id');
}
printf("FIXTURE  tenant 3 credential id=%s   tenant 6 credential id=%s\n", $cred[3], $cred[6]);

$users = [];
foreach ([3, 6] as $t) {
    $u = DB::table('tbluser')->where('sub_institute_id', $t)->first(['id']);
    $users[$t] = $u ? $u->id : null;
}
if (!$users[3] || !$users[6]) { exit("no user in one of the tenants\n"); }

$tok = [];
foreach ([3, 6] as $t) {
    $tok[$t] = tbluserModel::find($users[$t])->createToken('o03')->plainTextToken;
}
printf("CALLERS  tenant 3 user %d   tenant 6 user %d\n\n", $users[3], $users[6]);

$routes = [
    ['GET',  'api/excel-agent/credentials',     'credentialStatus'],
    ['GET',  'api/excel-agent/template',        'downloadTemplate'],
    ['POST', 'api/excel-agent/credentials',     'saveCredentials'],
    ['POST', 'api/excel-agent/test-connection', 'testConnection'],
    ['POST', 'api/excel-agent/upload',          'upload'],
];

function call($kernel, $method, $uri, $params)
{
    $req = Illuminate\Http\Request::create('/' . $uri, $method, $params);
    $req->headers->set('Accept', 'application/json');
    $res = $kernel->handle($req);
    return [$res->getStatusCode(), (string) $res->getContent()];
}

// ── CONTROL: does the probe reach the code at all? (probe discipline, property 1)
echo "CONTROL - own identity, no tenant requested. A 4xx here means the probe never\n";
echo "          reached the code and every result below would be worthless.\n";
foreach ([3, 6] as $t) {
    [$code, $body] = call($kernel, 'GET', 'api/excel-agent/credentials', ['token' => $tok[$t]]);
    $sees = $cred[3] && str_contains($body, '"id":' . $cred[3]) ? 3
          : ($cred[6] && str_contains($body, '"id":' . $cred[6]) ? 6 : '?');
    printf("  tenant %d caller -> HTTP %d   sees credential of tenant %s  %s\n",
        $t, $code, $sees, $sees === $t ? 'CORRECT' : ($sees === '?' ? '(id not echoed)' : '*** WRONG TENANT ***'));
}

// ── LEAK TEST: A's token, B's tenant requested. Every route.
echo "\nLEAK TEST - caller in tenant 3 requesting tenant 6, and the reverse.\n";
echo "            A refusal writes nothing, which is why the POSTs are safe to send.\n";
$leaks = 0;
foreach ($routes as [$method, $uri, $label]) {
    foreach ([[3, 6], [6, 3]] as [$mine, $theirs]) {
        $params = ['token' => $tok[$mine], 'sub_institute_id' => $theirs];
        [$code, $body] = call($kernel, $method, $uri, $params);

        $sawTheirs = $cred[$theirs] && str_contains($body, '"id":' . $cred[$theirs]);
        if ($code < 400 || $sawTheirs) { $leaks++; $verdict = '*** PROCEEDED - INVESTIGATE ***'; }
        else { $verdict = 'refused'; }

        printf("  %-6s %-34s %d->%d  HTTP %-4d %-32s %s\n",
            $method, $label, $mine, $theirs, $code, $verdict,
            $code >= 400 ? trim(substr((string) (json_decode($body, true)['message'] ?? ''), 0, 40)) : '');
    }
}

printf("\nROUTES PROCEEDING WITH A FOREIGN TENANT: %d of %d\n", $leaks, count($routes) * 2);

DB::table('personal_access_tokens')->where('name', 'o03')->delete();
echo "cleaned up: probe tokens deleted\n";
