<?php
/**
 * PROOF for the course->competency writer: store, re-read, idempotent re-store,
 * destroy, re-read - the cycle L-14 and the role map were proved by.
 *
 * SAFETY, STATED BEFORE THE FIRST WRITE
 *
 * - TENANT 7, NEVER TENANT 3. The demo tenant is counted before and after and
 *   must not move.
 * - A fixture competency and a fixture course are created and removed in a
 *   `finally`. Tenant 7 must return to exactly its starting counts.
 * - The endpoint SYNCS, so the target course is chosen BECAUSE IT HAS NO
 *   EXISTING MAP ROWS - sync semantics cannot destroy anything already there.
 * - The proof mints its own admin token: the route carries `profile:admin,hr`
 *   and the default test identity is an employee. Learned when the role-map
 *   proof 403'd and the guard was right.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const SID = 7;

$pass = 0; $fail = 0;
$ok = function ($l, $c, $d = '') use (&$pass, &$fail) {
    if ($c) { $pass++; printf("  PASS  %-50s %s\n", $l, $d); }
    else    { $fail++; printf("  FAIL  %-50s %s\n", $l, $d); }
};

$profiles = DB::table('tbluserprofilemaster')
    ->whereIn('role_key', ['administrator', 'hr_manager', 'hr_executive'])->pluck('id');
$admin = DB::table('tbluser')->where('sub_institute_id', SID)
    ->whereIn('user_profile_id', $profiles)->first(['id']);
if (!$admin) { echo "NO ADMIN IN TENANT 7 - refusing\n"; exit(1); }

$plain = bin2hex(random_bytes(24));
$tid = DB::table('personal_access_tokens')->insertGetId([
    'tokenable_type' => 'App\Models\auth\tbluserModel', 'tokenable_id' => $admin->id,
    'name' => 'ZZ-COURSEMAP-PROOF', 'token' => hash('sha256', $plain),
    'abilities' => '["*"]', 'created_at' => now(), 'updated_at' => now(),
]);
$TOKEN = $tid . '|' . $plain;

$call = function ($method, $uri, $params = []) use ($kernel, $TOKEN, $plain) {
    $req = Illuminate\Http\Request::create('/' . ltrim($uri, '/'), $method, array_merge([
        'token' => $TOKEN, 'type' => 'API', 'syear' => '2025', 'sub_institute_id' => SID,
    ], $params));
    $req->headers->set('Accept', 'application/json');
    $req->headers->set('Authorization', 'Bearer ' . $plain);
    $res = $kernel->handle($req);
    return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)];
};

$mapBefore  = DB::table('course_competency_map')->where('sub_institute_id', SID)->count();
$compBefore = DB::table('competency')->where('sub_institute_id', SID)->count();
$demoBefore = DB::table('course_competency_map')->where('sub_institute_id', 3)->count();
printf("BEFORE  tenant7 map=%d competency=%d  |  tenant3 (demo) map=%d\n\n",
    $mapBefore, $compBefore, $demoBefore);

$compId = $courseId = null;

try {
    // A course of tenant 7 with NO map rows - sync cannot hurt it.
    $courseId = DB::table('sub_std_map')->where('sub_institute_id', SID)
        ->whereNotIn('id', function ($q) {
            $q->select('course_id')->from('course_competency_map')->where('sub_institute_id', SID);
        })->value('id');
    if (!$courseId) { echo "NO CLEAN COURSE IN TENANT 7 - refusing\n"; exit(1); }

    $compId = DB::table('competency')->insertGetId([
        'sub_institute_id' => SID, 'name' => 'ZZ-COURSEMAP-PROOF', 'code' => 'ZZCMAP',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    printf("target course #%d, fixture competency #%d\n\n", $courseId, $compId);

    // ---- 0. THE EMPTY READ IS A STATED ANSWER --------------------------
    [$s0, $j0] = $call('GET', 'api/competency/course-map', ['course_id' => $courseId]);
    $ok('EMPTY READ says empty is expected', $s0 === 200 && ($j0['empty_is_expected'] ?? null) === true,
        'rows=' . count($j0['data'] ?? []) . ' flag=' . json_encode($j0['empty_is_expected'] ?? null));

    // ---- 1. STORE ------------------------------------------------------
    [$s1, $j1] = $call('POST', 'api/competency/course-map', [
        'course_id' => $courseId,
        'items' => [['competency_id' => $compId, 'proficiency_level' => 3, 'is_primary' => 1]],
    ]);
    $ok('STORE returns 201', $s1 === 201, 'http ' . $s1 . ' ' . ($j1['message'] ?? ''));
    $ok('STORE wrote exactly 1', ($j1['data']['written'] ?? null) === 1, 'written=' . json_encode($j1['data']['written'] ?? null));
    $ok('STORE removed nothing', ($j1['data']['removed'] ?? null) === 0, 'removed=' . json_encode($j1['data']['removed'] ?? null));

    // ---- 2. RE-READ ----------------------------------------------------
    [$s2, $j2] = $call('GET', 'api/competency/course-map', ['course_id' => $courseId]);
    $rows = $j2['data'] ?? [];
    $ok('RE-READ returns the row', $s2 === 200 && count($rows) === 1, 'rows=' . count($rows));
    $ok('RE-READ preserved the level', ($rows[0]['proficiency_level'] ?? null) === 3, 'level=' . json_encode($rows[0]['proficiency_level'] ?? null));
    $ok('RE-READ preserved is_primary', !empty($rows[0]['is_primary']), 'primary=' . json_encode($rows[0]['is_primary'] ?? null));
    $ok('RE-READ no longer says empty is expected', ($j2['empty_is_expected'] ?? null) === false,
        'flag=' . json_encode($j2['empty_is_expected'] ?? null));
    $rowId = $rows[0]['id'] ?? null;

    // ---- 3. IDEMPOTENT RE-STORE ---------------------------------------
    [$s3] = $call('POST', 'api/competency/course-map', [
        'course_id' => $courseId,
        'items' => [['competency_id' => $compId, 'proficiency_level' => 3, 'is_primary' => 1]],
    ]);
    [$s4, $j4] = $call('GET', 'api/competency/course-map', ['course_id' => $courseId]);
    $rows2 = $j4['data'] ?? [];
    $ok('RE-STORE returns 201', $s3 === 201, 'http ' . $s3);
    $ok('RE-STORE did not duplicate', count($rows2) === 1, 'rows=' . count($rows2));
    // NOT VACUOUS: null === null is silence, not agreement.
    $ok('RE-STORE kept the same row id',
        $rowId !== null && ($rows2[0]['id'] ?? null) === $rowId,
        'id ' . json_encode($rowId) . ' -> ' . json_encode($rows2[0]['id'] ?? null));

    // ---- 3b. CROSS-TENANT COURSE IS REFUSED ---------------------------
    $foreign = DB::table('sub_std_map')->where('sub_institute_id', 3)->value('id');
    if ($foreign) {
        [$s3b] = $call('POST', 'api/competency/course-map', [
            'course_id' => $foreign,
            'items' => [['competency_id' => $compId, 'proficiency_level' => 3]],
        ]);
        $ok("another tenant's course is refused (404)", $s3b === 404, 'http ' . $s3b);
    }

    // ---- 4. DESTROY ----------------------------------------------------
    [$s5, $j5] = $call('DELETE', 'api/competency/course-map/' . (int) $rowId);
    $ok('DESTROY returns 200', $s5 === 200, 'http ' . $s5 . ' ' . ($j5['message'] ?? ''));

    // ---- 5. RE-READ AFTER DESTROY -------------------------------------
    [$s6, $j6] = $call('GET', 'api/competency/course-map', ['course_id' => $courseId]);
    $ok('RE-READ after destroy is empty',
        $rowId !== null && $s5 === 200 && count($j6['data'] ?? []) === 0,
        'rows=' . count($j6['data'] ?? []) . ' (had id ' . json_encode($rowId) . ')');

} finally {
    if ($compId) {
        DB::table('course_competency_map')->where('competency_id', $compId)->delete();
        DB::table('competency')->where('id', $compId)->delete();
    }
    DB::table('personal_access_tokens')->where('id', $tid)->delete();

    $mapAfter  = DB::table('course_competency_map')->where('sub_institute_id', SID)->count();
    $compAfter = DB::table('competency')->where('sub_institute_id', SID)->count();
    $demoAfter = DB::table('course_competency_map')->where('sub_institute_id', 3)->count();
    printf("\nAFTER   tenant7 map=%d competency=%d  |  tenant3 (demo) map=%d\n", $mapAfter, $compAfter, $demoAfter);
    $ok('tenant 7 returned to its starting state', $mapAfter === $mapBefore && $compAfter === $compBefore,
        "map $mapBefore->$mapAfter comp $compBefore->$compAfter");
    $ok('THE DEMO TENANT WAS NEVER TOUCHED', $demoAfter === $demoBefore, "tenant3 $demoBefore->$demoAfter");
    printf("\nPASS %d   FAIL %d\n", $pass, $fail);
}
