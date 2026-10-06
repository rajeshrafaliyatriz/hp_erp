<?php
/**
 * L-14 — the task-catalogue write path, PROVED BY WRITE.
 *
 * store -> re-read -> destroy -> re-read, the same chain the role-map check uses.
 * Plus the two things this table gets wrong most easily:
 *
 *   - a competency from ANOTHER tenant must be refused (competency is
 *     tenant-owned, so this is checkable);
 *   - a jobrole_task id is checked for EXISTENCE only, because `s_jobrole_task`
 *     is a GLOBAL library with no tenant column. The probe asserts the refusal
 *     that DOES exist rather than one that cannot.
 *
 * Every row it writes is removed, and the removal is verified - the table's
 * correct state is 0 rows and it is returned to it.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\auth\tbluserModel;
use Illuminate\Support\Facades\DB;

const T = 3;

$before = DB::table('jobrole_task_competency_map')->count();
printf("rows before: %d  (0 is the correct resting state)\n\n", $before);

$prof = DB::table('tbluserprofilemaster')->where('sub_institute_id', T)->where('role_key', 'administrator')->value('id');
$uid = DB::table('tbluser')->where('sub_institute_id', T)->where('user_profile_id', $prof)
    ->where('email', 'like', '%@healthcare.g2g')->value('id');
$tok = tbluserModel::find($uid)->createToken('l14')->plainTextToken;

$taskId = (int) DB::table('s_jobrole_task')->value('id');
$mine   = DB::table('competency')->where('sub_institute_id', T)->whereNull('deleted_at')->pluck('id')->take(2)->all();
$theirs = (int) DB::table('competency')->where('sub_institute_id', '!=', T)->whereNull('deleted_at')->value('id');

printf("fixture: task %d (global library), my competencies %s, another tenant's %d\n\n",
    $taskId, implode('+', $mine), $theirs);

function call($kernel, string $method, string $uri, array $p): array
{
    $r = Illuminate\Http\Request::create($uri, $method, $p);
    $r->headers->set('Accept', 'application/json');
    $res = $kernel->handle($r);
    $b = json_decode((string) $res->getContent(), true);
    return [$res->getStatusCode(), $b ?: []];
}

$auth = ['token' => $tok, 'type' => 'API'];
$fail = 0;

// ── 1. EMPTY READ SAYS SO ───────────────────────────────────────────────────
[$c, $b] = call($kernel, 'GET', '/api/competency/task-map', $auth);
$saysAuthored = str_contains((string) ($b['note'] ?? ''), 'not derived');
printf("  empty read              HTTP %-4d %s\n", $c,
    $saysAuthored ? 'says "authored by your organisation, not derived"' : '*** no note - reads as "no data" ***');
if (!$saysAuthored) $fail++;

// ── 2. ANOTHER TENANT'S COMPETENCY IS REFUSED ──────────────────────────────
[$c, $b] = call($kernel, 'POST', '/api/competency/task-map',
    $auth + ['jobrole_task_id' => $taskId, 'items' => [['competency_id' => $theirs]]]);
printf("  foreign competency      HTTP %-4d %s\n", $c, substr((string) ($b['message'] ?? ''), 0, 54));
if ($c !== 422) $fail++;

// ── 3. A REPEATED COMPETENCY IS REFUSED BEFORE THE WRITE ───────────────────
[$c, $b] = call($kernel, 'POST', '/api/competency/task-map',
    $auth + ['jobrole_task_id' => $taskId, 'items' => [['competency_id' => $mine[0]], ['competency_id' => $mine[0]]]]);
printf("  repeated competency     HTTP %-4d %s\n", $c, substr((string) ($b['message'] ?? ''), 0, 54));
if ($c !== 422) $fail++;

// ── 4. STORE ───────────────────────────────────────────────────────────────
[$c, $b] = call($kernel, 'POST', '/api/competency/task-map',
    $auth + ['jobrole_task_id' => $taskId, 'items' => [['competency_id' => $mine[0]], ['competency_id' => $mine[1]]]]);
printf("\n  store 2                 HTTP %-4d mapped=%s\n", $c, $b['mapped'] ?? '?');
if ($c !== 200 || ($b['mapped'] ?? 0) !== 2) $fail++;

// ── 5. RE-READ ─────────────────────────────────────────────────────────────
[$c, $b] = call($kernel, 'GET', '/api/competency/task-map', $auth + ['jobrole_task_id' => $taskId]);
$rows = $b['data'] ?? [];
printf("  re-read                 HTTP %-4d %d row(s), names resolved: %s\n", $c, count($rows),
    !empty($rows[0]['competency_name']) ? 'yes' : 'NO');
if (count($rows) !== 2) $fail++;

// ── 6. STORE IS IDEMPOTENT ─────────────────────────────────────────────────
[$c, $b] = call($kernel, 'POST', '/api/competency/task-map',
    $auth + ['jobrole_task_id' => $taskId, 'items' => [['competency_id' => $mine[0]], ['competency_id' => $mine[1]]]]);
printf("  store the same again    HTTP %-4d mapped=%s  %s\n", $c, $b['mapped'] ?? '?',
    ($b['mapped'] ?? 0) === 2 ? 'IDEMPOTENT' : '*** DUPLICATED ***');
if (($b['mapped'] ?? 0) !== 2) $fail++;

// ── 7. DESTROY, THEN RE-READ ───────────────────────────────────────────────
$ids = array_column($rows, 'id');
foreach ($ids as $id) {
    [$c] = call($kernel, 'DELETE', '/api/competency/task-map/' . $id, $auth);
    if ($c !== 200) $fail++;
}
[$c, $b] = call($kernel, 'GET', '/api/competency/task-map', $auth + ['jobrole_task_id' => $taskId]);
printf("  destroy %d, re-read      HTTP %-4d %d row(s)\n", count($ids), $c, count($b['data'] ?? []));
if (count($b['data'] ?? []) !== 0) $fail++;

// ── cleanup verified ───────────────────────────────────────────────────────
DB::table('jobrole_task_competency_map')->where('sub_institute_id', T)->delete();
DB::table('personal_access_tokens')->where('name', 'l14')->delete();
$after = DB::table('jobrole_task_competency_map')->count();
printf("\nrows after: %d  %s\n", $after, $after === $before ? 'back to the resting state' : '*** LEFT ROWS BEHIND ***');
if ($after !== $before) $fail++;

printf("VERDICT: %s\n", $fail === 0 ? 'PASS' : '*** ' . $fail . ' FAILURE(S) ***');
