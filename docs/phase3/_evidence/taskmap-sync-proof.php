<?php
/**
 * PROOF that jobrole_task_competency_map's writer SYNCS rather than appends,
 * and that the per-role browse sees an unmapped task.
 *
 * SAFETY: tenant 7, never tenant 3. Two fixture competencies, removed in a
 * finally. The target task is chosen because it has NO map rows.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const SID = 7;
$pass = 0; $fail = 0;
$ok = function ($l, $c, $d = '') use (&$pass, &$fail) {
    if ($c) { $pass++; printf("  PASS  %-48s %s\n", $l, $d); }
    else    { $fail++; printf("  FAIL  %-48s %s\n", $l, $d); }
};

$profiles = DB::table('tbluserprofilemaster')
    ->whereIn('role_key', ['administrator', 'hr_manager', 'hr_executive'])->pluck('id');
$admin = DB::table('tbluser')->where('sub_institute_id', SID)
    ->whereIn('user_profile_id', $profiles)->first(['id']);
$plain = bin2hex(random_bytes(24));
$tid = DB::table('personal_access_tokens')->insertGetId([
    'tokenable_type' => 'App\Models\auth\tbluserModel', 'tokenable_id' => $admin->id,
    'name' => 'ZZ-TASKMAP', 'token' => hash('sha256', $plain),
    'abilities' => '["*"]', 'created_at' => now(), 'updated_at' => now(),
]);

$call = function ($method, $uri, $params = []) use ($kernel, $tid, $plain) {
    $r = Illuminate\Http\Request::create('/' . $uri, $method, array_merge(
        ['token' => $tid . '|' . $plain, 'type' => 'API', 'syear' => '2025', 'sub_institute_id' => SID], $params));
    $r->headers->set('Accept', 'application/json');
    $r->headers->set('Authorization', 'Bearer ' . $plain);
    $res = $kernel->handle($r);
    return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)];
};

$mapBefore  = DB::table('jobrole_task_competency_map')->where('sub_institute_id', SID)->count();
$demoBefore = DB::table('jobrole_task_competency_map')->where('sub_institute_id', 3)->count();
$c1 = $c2 = null;

try {
    $task = DB::table('s_jobrole_task')->whereNotNull('jobrole')->where('jobrole', '!=', '')
        ->first(['id', 'jobrole', 'task']);
    $c1 = DB::table('competency')->insertGetId(['sub_institute_id' => SID,
        'name' => 'ZZ-TASKMAP-A', 'code' => 'ZZTA', 'created_at' => now(), 'updated_at' => now()]);
    $c2 = DB::table('competency')->insertGetId(['sub_institute_id' => SID,
        'name' => 'ZZ-TASKMAP-B', 'code' => 'ZZTB', 'created_at' => now(), 'updated_at' => now()]);
    printf("task #%d of role '%s'\n\n", $task->id, mb_substr($task->jobrole, 0, 40));

    // The unmapped task must appear in the browse - the whole point of the LEFT JOIN.
    [$sb, $jb] = $call('GET', 'api/competency/task-map/tasks', ['jobrole' => $task->jobrole]);
    $found = false;
    foreach ($jb['data'] ?? [] as $t) if ((int) $t['jobrole_task_id'] === (int) $task->id) $found = true;
    $ok('BROWSE shows the task while it is UNMAPPED', $sb === 200 && $found,
        'tasks=' . ($jb['counts']['tasks'] ?? -1) . ' unmapped=' . ($jb['counts']['unmapped'] ?? -1));
    $ok('BROWSE says empty is expected', ($jb['empty_is_expected'] ?? null) === true,
        'flag=' . json_encode($jb['empty_is_expected'] ?? null));

    // STORE TWO
    [$s1, $j1] = $call('POST', 'api/competency/task-map', ['jobrole_task_id' => $task->id,
        'items' => [['competency_id' => $c1], ['competency_id' => $c2]]]);
    $ok('STORE two returns 200', $s1 === 200, 'http ' . $s1 . ' mapped=' . ($j1['mapped'] ?? '?'));
    $ok('STORE reports 2 mapped', ($j1['mapped'] ?? null) === 2, 'mapped=' . json_encode($j1['mapped'] ?? null));
    $ok('STORE removed nothing', ($j1['removed'] ?? null) === 0, 'removed=' . json_encode($j1['removed'] ?? null));

    // SYNC: send only ONE - the other must be DELETED, not left behind.
    [$s2, $j2] = $call('POST', 'api/competency/task-map', ['jobrole_task_id' => $task->id,
        'items' => [['competency_id' => $c1]]]);
    $ok('RE-STORE with one item returns 200', $s2 === 200, 'http ' . $s2);
    $ok('SYNC removed the absent one', ($j2['removed'] ?? null) === 1, 'removed=' . json_encode($j2['removed'] ?? null));
    $ok('SYNC left exactly one mapped', ($j2['mapped'] ?? null) === 1, 'mapped=' . json_encode($j2['mapped'] ?? null));

    $live = DB::table('jobrole_task_competency_map')->where('sub_institute_id', SID)
        ->where('jobrole_task_id', $task->id)->pluck('competency_id')->all();
    $ok('the SURVIVING row is the one that was sent', $live === [$c1], 'rows=' . json_encode($live));

} finally {
    foreach (array_filter([$c1, $c2]) as $c) {
        DB::table('jobrole_task_competency_map')->where('competency_id', $c)->delete();
        DB::table('competency')->where('id', $c)->delete();
    }
    DB::table('personal_access_tokens')->where('id', $tid)->delete();
    $mapAfter  = DB::table('jobrole_task_competency_map')->where('sub_institute_id', SID)->count();
    $demoAfter = DB::table('jobrole_task_competency_map')->where('sub_institute_id', 3)->count();
    $ok('tenant 7 returned to its starting state', $mapAfter === $mapBefore, "$mapBefore->$mapAfter");
    $ok('THE DEMO TENANT WAS NEVER TOUCHED', $demoAfter === $demoBefore, "tenant3 $demoBefore->$demoAfter");
    printf("\nPASS %d   FAIL %d\n", $pass, $fail);
}
