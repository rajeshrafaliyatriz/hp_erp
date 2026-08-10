<?php
/**
 * THE {id} READ PROBE — what an ordinary employee reaches by changing a number.
 *
 * WHY THIS SURFACE. The untested half is three for three on real findings:
 * assignmentController's approval path, LeaveRequestApiController::show, and the
 * injection paths reachable by POST. `{id}` routes were never probed because a
 * fabricated id returns 404 and proves nothing.
 *
 * DESIGN, all constraints stated so the numbers can be read correctly:
 *   - REAL ids, harvested per table from TENANT 7, plus real ids from TENANT 3
 *     so cross-tenant reach is measured rather than assumed.
 *   - EMPLOYEE token (user 198, tenant 7, profile Employee). The question is what
 *     an ordinary user can reach, not an administrator.
 *   - READ VERBS ONLY. GET. Nothing here writes.
 *   - Chunked: one PROCESS per chunk, so a fatal costs a chunk, not the run.
 *     Within a chunk every request uses the SAME token, so G-HARNESS-01's cached
 *     identity is the CORRECT identity - the same reasoning that was MEASURED
 *     when C23's harness was verified, not assumed.
 *
 * Output is one JSON line per request; classification happens in the summary,
 * so REACHABLE and DISCLOSING stay separate numbers (R10).
 *
 * Usage: php id-probe.php <offset> <limit>
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Models\auth\tbluserModel;

const CALLER  = 198;      // tenant 7, profile Employee
const TENANT  = 7;
const OTHER   = 3;        // a different tenant, for cross-tenant reach
$OUT = 'C:/Users/MILAN/Downloads/hp_erp/docs/phase3/_evidence/id-probe-results.jsonl';

$offset = (int) ($argv[1] ?? 0);
$limit  = (int) ($argv[2] ?? 12);

/* ---- targets: api/* GET routes with exactly one {param} ---- */
$targets = [];
foreach (Route::getRoutes() as $r) {
    if (!in_array('GET', $r->methods(), true)) continue;
    if (substr_count($r->uri(), '{') !== 1) continue;
    if (!str_starts_with($r->uri(), 'api/')) continue;
    $a = $r->getActionName();
    if (!str_contains($a, '@')) continue;
    $targets[] = ['uri' => $r->uri(), 'action' => str_replace('App\\Http\\Controllers\\', '', $a)];
}
if ($offset === -1) { printf("%d\n", count($targets)); exit; }

/* ---- a pool of REAL ids, per tenant ---- */
function pool(int $tenant): array {
    $ids = [];
    $tables = ['tbluser', 'lms_assignments', 'hrms_employee_leaves', 'talent_job_applications',
               's_users_skills', 's_user_jobrole', 'task', 'task_management_projects',
               'hrms_departments', 'sub_std_map'];
    foreach ($tables as $t) {
        try {
            $row = DB::table($t)->where('sub_institute_id', $tenant)->orderBy('id')->value('id');
            if ($row) $ids[] = (int) $row;
        } catch (Throwable $e) { /* table absent - skip, not a failure of the probe */ }
    }
    return array_values(array_unique($ids));
}

$own   = pool(TENANT);
$other = pool(OTHER);

$token = tbluserModel::find(CALLER)->createToken('idprobe')->plainTextToken;

foreach (array_slice($targets, $offset, $limit) as $t) {
    foreach ([['own', $own], ['other', $other]] as [$kind, $ids]) {
        foreach ($ids as $id) {
            $uri = preg_replace('/\{[^}]+\}/', (string) $id, $t['uri']);
            $req = Illuminate\Http\Request::create('/' . $uri, 'GET', [
                'token' => $token, 'sub_institute_id' => TENANT, 'syear' => date('Y'), 'type' => 'API',
            ]);
            $req->headers->set('Authorization', 'Bearer ' . explode('|', $token, 2)[1]);
            $req->headers->set('Accept', 'application/json');
            try {
                $res  = $kernel->handle($req);
                $code = $res->getStatusCode();
                $body = (string) $res->getContent();
            } catch (Throwable $e) {
                $code = -1; $body = '';
            }
            file_put_contents($OUT, json_encode([
                'uri' => $t['uri'], 'action' => $t['action'], 'kind' => $kind,
                'id' => $id, 'status' => $code, 'len' => strlen($body),
                'sha' => sha1($body),
            ]) . "\n", FILE_APPEND);
        }
    }
}

DB::table('personal_access_tokens')->where('name', 'idprobe')->delete();
