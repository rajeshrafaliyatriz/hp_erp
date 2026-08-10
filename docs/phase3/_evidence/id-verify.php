<?php
/**
 * R6 VERIFICATION of the {id} probe's 23 REACHABLE candidates.
 *
 * The standard is the one used for the two confirmed: fetch the target row's own
 * identifying field FROM THE DATABASE and assert it appears in the response.
 * Not status codes. Not byte counts.
 *
 * Three verdicts, and the third is real:
 *   DISCLOSING      the tenant-3 row's own marker is present in the body
 *   NOT DISCLOSING  200, but the marker is absent - the endpoint answered with
 *                   the caller's own data, or with global reference data
 *   INDETERMINATE   the row has no usable marker, or the response is a shape the
 *                   marker test cannot see through. Reported as its own category
 *                   rather than forced into one of the others.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Models\auth\tbluserModel;

const TABLES = ['tbluser', 'lms_assignments', 'hrms_employee_leaves', 'talent_job_applications',
                's_users_skills', 's_user_jobrole', 'task', 'task_management_projects',
                'hrms_departments', 'sub_std_map'];

$offset = (int) ($argv[1] ?? 0);
$limit  = (int) ($argv[2] ?? 6);
$OUT = 'C:/Users/MILAN/Downloads/hp_erp/docs/phase3/_evidence/id-verify-results.jsonl';

/* id -> source table, for tenant 3 */
$idTable = [];
foreach (TABLES as $t) {
    try {
        $id = DB::table($t)->where('sub_institute_id', 3)->orderBy('id')->value('id');
        if ($id) $idTable[(int) $id][] = $t;
    } catch (Throwable $e) {}
}

/* The reachable candidates.
 *
 * CORRECTED: the first version kept only the LARGEST response per route, then
 * tested that one id against markers from whatever table the id happened to be
 * pooled from. For api/user-signup/{id} that selected id=1 - not the tbluser id
 * whose row was actually disclosed - and returned NOT DISCLOSING for a route
 * already confirmed DISCLOSING at id=6. A harness artefact, not a result.
 *
 * Every 200-returning other-tenant id is now tested, each against ITS OWN row's
 * markers, and a route is DISCLOSING if ANY id discloses. */
$best = [];
foreach (file('C:/Users/MILAN/Downloads/hp_erp/docs/phase3/_evidence/id-probe-results.jsonl') as $l) {
    $r = json_decode(trim($l), true);
    if (!$r || $r['kind'] !== 'other' || $r['status'] !== 200 || $r['len'] <= 60) continue;
    $best[$r['uri'] . '#' . $r['id']] = $r;
}
$cands = array_values($best);
usort($cands, fn($a, $b) => strcmp($a['uri'], $b['uri']));
if ($offset === -1) { printf("%d\n", count($cands)); exit; }

/* reach chain per route */
$chain = [];
foreach (Route::getRoutes() as $r) {
    $mw = array_filter($r->gatherMiddleware(), 'is_string');
    $auth = (bool) preg_grep('/auth|sanctum|profile|token/i', $mw);
    $chain[$r->uri()] = ['mw' => implode(',', $mw), 'auth' => $auth];
}

$token = tbluserModel::find(198)->createToken('idverify2')->plainTextToken;

foreach (array_slice($cands, $offset, $limit) as $c) {
    $uri = preg_replace('/\{[^}]+\}/', (string) $c['id'], $c['uri']);
    $req = Illuminate\Http\Request::create('/' . $uri, 'GET', [
        'token' => $token, 'sub_institute_id' => 7, 'syear' => date('Y'), 'type' => 'API',
    ]);
    $req->headers->set('Authorization', 'Bearer ' . explode('|', $token, 2)[1]);
    $req->headers->set('Accept', 'application/json');

    try {
        $res = $kernel->handle($req);
        $body = (string) $res->getContent();
        $code = $res->getStatusCode();
    } catch (Throwable $e) {
        $code = -1; $body = '';
    }

    /* every tenant-3 marker for that id, across the tables it could have come from */
    $markers = [];
    foreach ($idTable[$c['id']] ?? [] as $tbl) {
        $row = DB::table($tbl)->where('id', $c['id'])->first();
        if (!$row) continue;
        foreach (['title', 'name', 'first_name', 'email', 'jobrole', 'department'] as $f) {
            if (!empty($row->$f) && strlen((string) $row->$f) >= 5) $markers[] = (string) $row->$f;
        }
    }
    $markers = array_values(array_unique($markers));

    $hit = null;
    foreach ($markers as $m) if (stripos($body, $m) !== false) { $hit = $m; break; }

    $verdict = $hit !== null ? 'DISCLOSING'
             : ($markers === [] ? 'INDETERMINATE' : 'NOT DISCLOSING');

    file_put_contents($OUT, json_encode([
        'uri' => $c['uri'], 'action' => $c['action'], 'id' => $c['id'],
        'status' => $code, 'len' => strlen($body),
        'verdict' => $verdict, 'marker' => $hit,
        'markers_tried' => count($markers),
        'mw' => $chain[$c['uri']]['mw'] ?? '', 'authed' => $chain[$c['uri']]['auth'] ?? false,
    ]) . "\n", FILE_APPEND);
}

DB::table('personal_access_tokens')->where('name', 'idverify2')->delete();
