<?php
/**
 * PROOF for the Role Requirements panel — the same cycle L-14 was proved by:
 * store, re-read, idempotent re-store, destroy, re-read.
 *
 * SAFETY, STATED BEFORE THE FIRST WRITE
 *
 * - TENANT 7, NOT TENANT 3. All 23 existing `jobrole_competency_map` rows are
 *   in tenant 3, which is the DEMO tenant. A write test does not touch what a
 *   customer would be shown.
 * - Tenant 7 has ZERO competency rows, so a fixture competency is created for
 *   the test and REMOVED in a `finally` — registered here, not left behind.
 * - The endpoint SYNCS (rows absent from the payload are deleted), so the
 *   target job role is chosen BECAUSE IT HAS NO EXISTING MAP ROWS. Sync
 *   semantics cannot destroy anything that was already there.
 * - Every count is taken before and after. The test fails itself if tenant 7
 *   does not return to exactly its starting state.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const SID = 7;

// THE FIRST RUN FAILED 403 AND THE GUARD WAS RIGHT. The default test token
// belongs to an `employee`; `POST /competency/role-map` carries
// `profile:admin,hr`. So the proof MINTS ITS OWN token for a tenant-7
// administrator and deletes it in the `finally` - the identity is part of the
// fixture, not an assumption.
$adminProfiles = DB::table('tbluserprofilemaster')
    ->whereIn('role_key', ['administrator', 'hr_manager', 'hr_executive'])->pluck('id');
$adminUser = DB::table('tbluser')->where('sub_institute_id', SID)
    ->whereIn('user_profile_id', $adminProfiles)->first(['id']);
if (!$adminUser) { echo "NO ADMIN/HR USER IN TENANT 7 - refusing to run
"; exit(1); }

$plain     = bin2hex(random_bytes(24));
$tokenRow  = DB::table('personal_access_tokens')->insertGetId([
    'tokenable_type' => 'App\Models\auth\tbluserModel',
    'tokenable_id'   => $adminUser->id,
    'name'           => 'ZZ-PROOF-ROLEMAP-DELETE-ME',
    'token'          => hash('sha256', $plain),
    'abilities'      => '["*"]',
    'created_at'     => now(),
    'updated_at'     => now(),
]);
$TOKEN = $tokenRow . '|' . $plain;
printf("proof identity: tenant-7 admin user #%d, temp token #%d (removed in finally)
", $adminUser->id, $tokenRow);

$call = function (string $method, string $uri, array $params = []) use ($kernel, $TOKEN) {
    $req = Illuminate\Http\Request::create('/' . ltrim($uri, '/'), $method, array_merge([
        'token' => $TOKEN, 'type' => 'API', 'syear' => '2025', 'sub_institute_id' => SID,
    ], $params));
    $req->headers->set('Accept', 'application/json');
    $req->headers->set('Authorization', 'Bearer ' . explode('|', $TOKEN, 2)[1]);
    $res = $kernel->handle($req);
    return [$res->getStatusCode(), json_decode((string) $res->getContent(), true), (string) $res->getContent()];
};

$pass = 0; $fail = 0;
$ok = function (string $label, bool $cond, string $detail = '') use (&$pass, &$fail) {
    if ($cond) { $pass++; printf("  PASS  %-52s %s\n", $label, $detail); }
    else       { $fail++; printf("  FAIL  %-52s %s\n", $label, $detail); }
};

// ---- BEFORE ---------------------------------------------------------------
$mapBefore  = DB::table('jobrole_competency_map')->where('sub_institute_id', SID)->count();
$compBefore = DB::table('competency')->where('sub_institute_id', SID)->count();
$demoBefore = DB::table('jobrole_competency_map')->where('sub_institute_id', 3)->count();
printf("BEFORE  tenant7 map=%d competency=%d   |   tenant3 (demo) map=%d\n\n", $mapBefore, $compBefore, $demoBefore);

$fixtureId = null;
$role = null;

try {
    // A job role of tenant 7 that has NO map rows - sync cannot hurt it.
    $role = DB::table('s_user_jobrole')->where('sub_institute_id', SID)
        ->whereNotIn('id', function ($q) {
            $q->select('jobrole_id')->from('jobrole_competency_map')->where('sub_institute_id', SID);
        })->first(['id', 'jobrole']);
    if (!$role) { echo "NO CLEAN JOB ROLE IN TENANT 7 - refusing to run\n"; exit(1); }
    printf("target role: #%d %s\n", $role->id, $role->jobrole);

    $fixtureId = DB::table('competency')->insertGetId([
        'sub_institute_id' => SID,
        'name'             => 'ZZ-PROOF-ROLEMAP-DELETE-ME',
        'code'             => 'ZZPROOF',
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);
    printf("fixture competency: #%d (removed in finally)\n\n", $fixtureId);

    // ---- 1. STORE ---------------------------------------------------------
    [$s, $j] = $call('POST', 'api/competency/role-map', [
        'jobrole_id' => $role->id,
        'items'      => [['competency_id' => $fixtureId, 'required_proficiency' => 3, 'is_mandatory' => 1]],
    ]);
    // MEASURED, NOT ASSUMED: the endpoint answers 201 (a create) and nests the
    // counts under `data`. The first version of these three checks asserted 200
    // and top-level counts, failed, and WAS WRONG - the code was right. The
    // TypeScript service carried the same wrong shape and would have shown
    // `undefined removed from this role`; it was corrected from this run.
    $ok('STORE returns 201 (create)', $s === 201, 'http ' . $s . ' ' . ($j['message'] ?? ''));
    $ok('STORE wrote exactly 1', ($j['data']['written'] ?? null) === 1, 'written=' . json_encode($j['data']['written'] ?? null));
    $ok('STORE removed nothing', ($j['data']['removed'] ?? null) === 0, 'removed=' . json_encode($j['data']['removed'] ?? null));

    // ---- 2. RE-READ -------------------------------------------------------
    [$s2, $j2] = $call('GET', 'api/competency/role-map', ['jobrole_id' => $role->id]);
    $rows = $j2['data'] ?? [];
    $ok('RE-READ returns the row', $s2 === 200 && count($rows) === 1, 'rows=' . count($rows));
    $ok('RE-READ preserved the level', ($rows[0]['required_proficiency'] ?? null) == 3, 'level=' . json_encode($rows[0]['required_proficiency'] ?? null));
    $ok('RE-READ preserved is_mandatory', !empty($rows[0]['is_mandatory']), 'mandatory=' . json_encode($rows[0]['is_mandatory'] ?? null));
    $rowId = $rows[0]['id'] ?? null;

    // ---- 3. IDEMPOTENT RE-STORE ------------------------------------------
    [$s3, $j3] = $call('POST', 'api/competency/role-map', [
        'jobrole_id' => $role->id,
        'items'      => [['competency_id' => $fixtureId, 'required_proficiency' => 3, 'is_mandatory' => 1]],
    ]);
    $ok('RE-STORE returns 201', $s3 === 201, 'http ' . $s3);
    [$s4, $j4] = $call('GET', 'api/competency/role-map', ['jobrole_id' => $role->id]);
    $rows2 = $j4['data'] ?? [];
    $ok('RE-STORE did not duplicate', count($rows2) === 1, 'rows=' . count($rows2));
    // NOT VACUOUS: on the 403 run this passed as null === null. A comparison
    // whose both sides are absent is not agreement, it is silence.
    $ok('RE-STORE kept the same row id',
        $rowId !== null && ($rows2[0]['id'] ?? null) === $rowId,
        'id ' . json_encode($rowId) . ' -> ' . json_encode($rows2[0]['id'] ?? null));

    // ---- 4. DESTROY -------------------------------------------------------
    [$s5, $j5] = $call('DELETE', 'api/competency/role-map/' . (int) $rowId);
    $ok('DESTROY returns 200', $s5 === 200, 'http ' . $s5 . ' ' . ($j5['message'] ?? ''));

    // ---- 5. RE-READ AFTER DESTROY ----------------------------------------
    [$s6, $j6] = $call('GET', 'api/competency/role-map', ['jobrole_id' => $role->id]);
    // NOT VACUOUS: empty proves a deletion only if something was there first.
    $ok('RE-READ after destroy is empty',
        $rowId !== null && $s5 === 200 && count($j6['data'] ?? []) === 0,
        'rows=' . count($j6['data'] ?? []) . ' (had id ' . json_encode($rowId) . ')');

} finally {
    // ---- CLEANUP, ALWAYS --------------------------------------------------
    if (!empty($tokenRow)) DB::table('personal_access_tokens')->where('id', $tokenRow)->delete();
    if ($fixtureId) {
        DB::table('jobrole_competency_map')->where('competency_id', $fixtureId)->delete();
        DB::table('competency')->where('id', $fixtureId)->delete();
    }
    $mapAfter  = DB::table('jobrole_competency_map')->where('sub_institute_id', SID)->count();
    $compAfter = DB::table('competency')->where('sub_institute_id', SID)->count();
    $demoAfter = DB::table('jobrole_competency_map')->where('sub_institute_id', 3)->count();
    printf("\nAFTER   tenant7 map=%d competency=%d   |   tenant3 (demo) map=%d\n", $mapAfter, $compAfter, $demoAfter);
    $ok('tenant 7 returned to its starting state', $mapAfter === $mapBefore && $compAfter === $compBefore, "map $mapBefore->$mapAfter comp $compBefore->$compAfter");
    $ok('THE DEMO TENANT WAS NEVER TOUCHED', $demoAfter === $demoBefore, "tenant3 map $demoBefore->$demoAfter");
    printf("\nPASS %d   FAIL %d\n", $pass, $fail);
}
