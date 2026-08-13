<?php
/**
 * PROOF: the rating write path, end to end through the GAP.
 *
 * The claim is not "a row was inserted". It is that RATING AN ITEM CHANGES WHAT
 * THE GAP SAYS, and that removing it puts the gap back. So every assertion below
 * reads `ProficiencyService::rollUp` - the thing the gap controller uses - and
 * never the rating table it just wrote.
 *
 * UNMEASURED STAYS UNMEASURED is asserted explicitly: before the rating the level
 * must be NULL, not 0, and after the removal it must be NULL again.
 *
 * SAFETY: tenant 7. A competency, one KASBA item and one rating are created and
 * removed in a `finally`. The demo tenant is counted before and after.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const SID = 7;

$profiles = DB::table('tbluserprofilemaster')
    ->whereIn('role_key', ['administrator', 'hr_manager', 'hr_executive'])->pluck('id');
$admin = DB::table('tbluser')->where('sub_institute_id', SID)
    ->whereIn('user_profile_id', $profiles)->first(['id']);
$subject = DB::table('tbluser')->where('sub_institute_id', SID)->first(['id']);
if (!$admin || !$subject) { echo "NO USERS IN TENANT 7 - refusing\n"; exit(1); }

$plain = bin2hex(random_bytes(24));
$tid = DB::table('personal_access_tokens')->insertGetId([
    'tokenable_type' => 'App\Models\auth\tbluserModel', 'tokenable_id' => $admin->id,
    'name' => 'ZZ-RATING-PROOF-DELETE-ME', 'token' => hash('sha256', $plain),
    'abilities' => '["*"]', 'created_at' => now(), 'updated_at' => now(),
]);
$TOKEN = $tid . '|' . $plain;

$call = function ($method, $uri, $params = []) use ($kernel, $TOKEN) {
    $req = Illuminate\Http\Request::create('/' . ltrim($uri, '/'), $method, array_merge([
        'token' => $TOKEN, 'type' => 'API', 'syear' => '2025', 'sub_institute_id' => SID,
    ], $params));
    $req->headers->set('Accept', 'application/json');
    $req->headers->set('Authorization', 'Bearer ' . explode('|', $TOKEN, 2)[1]);
    $res = $kernel->handle($req);
    return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)];
};

$pass = 0; $fail = 0;
$ok = function ($l, $c, $d = '') use (&$pass, &$fail) {
    if ($c) { $pass++; printf("  PASS  %-48s %s\n", $l, $d); }
    else    { $fail++; printf("  FAIL  %-48s %s\n", $l, $d); }
};

$prof = $app->make(App\Services\Competency\ProficiencyService::class);
$demoBefore = DB::table('competency_kasba_rating')->where('sub_institute_id', 3)->count();
$compId = null;

try {
    $skill = DB::table('s_users_skills')->where('sub_institute_id', SID)->value('id');
    $compId = DB::table('competency')->insertGetId([
        'sub_institute_id' => SID, 'name' => 'ZZ-RATING-PROOF', 'code' => 'ZZRATE',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    // TWO items, deliberately: with one item, coverage can only be 0 or 1 and a
    // partial-coverage bug would be invisible.
    $itemA = DB::table('competency_kasba_item')->insertGetId([
        'sub_institute_id' => SID, 'competency_id' => $compId, 'kasba_type' => 'skill',
        'item_id' => $skill, 'item_label' => null, 'weight' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $itemB = DB::table('competency_kasba_item')->insertGetId([
        'sub_institute_id' => SID, 'competency_id' => $compId, 'kasba_type' => 'knowledge',
        'item_id' => null, 'item_label' => 'ZZ-second-item', 'weight' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // ---- BEFORE: unmeasured means NULL, not zero -------------------------
    $r0 = $prof->rollUp(SID, (int) $subject->id, [$compId])[$compId];
    $ok('BEFORE: level is NULL, not 0', $r0['level'] === null, 'level=' . json_encode($r0['level']));
    $ok('BEFORE: coverage is 0', (float) $r0['coverage'] === 0.0, 'coverage=' . $r0['coverage']);

    // ---- RATE one of the two items ---------------------------------------
    [$s1, $j1] = $call('POST', 'api/competency/kasba-rating', [
        'kasba_item_id' => $itemA, 'user_id' => $subject->id, 'rating' => 4,
    ]);
    $ok('RATE returns 201', $s1 === 201, 'http ' . $s1 . ' ' . ($j1['message'] ?? ''));

    $r1 = $prof->rollUp(SID, (int) $subject->id, [$compId])[$compId];
    $ok('AFTER: level became 4', (float) $r1['level'] === 4.0, 'level=' . json_encode($r1['level']));
    $ok('AFTER: coverage moved to 0.5', (float) $r1['coverage'] === 0.5, 'coverage=' . $r1['coverage']);
    $ok('AFTER: the unrated item is still unmeasured',
        count(array_filter($r1['items'], fn ($i) => !$i['measured'])) === 1,
        'unmeasured items=' . count(array_filter($r1['items'], fn ($i) => !$i['measured'])));

    // ---- IDEMPOTENT: re-rating updates, never duplicates -----------------
    [$s2] = $call('POST', 'api/competency/kasba-rating', [
        'kasba_item_id' => $itemA, 'user_id' => $subject->id, 'rating' => 2,
    ]);
    $rows = DB::table('competency_kasba_rating')->where('kasba_item_id', $itemA)->count();
    $r2 = $prof->rollUp(SID, (int) $subject->id, [$compId])[$compId];
    $ok('RE-RATE did not duplicate the row', $rows === 1, 'rows=' . $rows);
    $ok('RE-RATE changed the level to 2', (float) $r2['level'] === 2.0, 'level=' . json_encode($r2['level']));

    // ---- A RATING OF ZERO IS REFUSED -------------------------------------
    [$s3, $j3] = $call('POST', 'api/competency/kasba-rating', [
        'kasba_item_id' => $itemA, 'user_id' => $subject->id, 'rating' => 0,
    ]);
    $ok('rating 0 is REFUSED (422)', $s3 === 422, 'http ' . $s3 . ' ' . ($j3['message'] ?? ''));

    // ---- CROSS-TENANT item is refused ------------------------------------
    $foreign = DB::table('competency_kasba_item')->where('sub_institute_id', 3)->value('id');
    if ($foreign) {
        [$s4] = $call('POST', 'api/competency/kasba-rating', [
            'kasba_item_id' => $foreign, 'user_id' => $subject->id, 'rating' => 3,
        ]);
        $ok("another tenant's item is refused (404)", $s4 === 404, 'http ' . $s4);
    }

    // ---- REMOVE: back to unmeasured, not to zero -------------------------
    [$s5, $j5] = $call('DELETE', 'api/competency/kasba-rating', [
        'kasba_item_id' => $itemA, 'user_id' => $subject->id,
    ]);
    $ok('REMOVE returns 200', $s5 === 200, 'http ' . $s5 . ' ' . ($j5['message'] ?? ''));
    $r3 = $prof->rollUp(SID, (int) $subject->id, [$compId])[$compId];
    $ok('AFTER REMOVE: level is NULL again, NOT 0', $r3['level'] === null, 'level=' . json_encode($r3['level']));
    $ok('AFTER REMOVE: coverage is 0 again', (float) $r3['coverage'] === 0.0, 'coverage=' . $r3['coverage']);

} finally {
    if ($compId) {
        $ids = DB::table('competency_kasba_item')->where('competency_id', $compId)->pluck('id');
        DB::table('competency_kasba_rating')->whereIn('kasba_item_id', $ids)->delete();
        DB::table('competency_kasba_item')->where('competency_id', $compId)->delete();
        DB::table('competency')->where('id', $compId)->delete();
    }
    DB::table('personal_access_tokens')->where('id', $tid)->delete();
    $demoAfter = DB::table('competency_kasba_rating')->where('sub_institute_id', 3)->count();
    $leftover = DB::table('competency')->where('sub_institute_id', SID)->where('name', 'ZZ-RATING-PROOF')->count();
    $ok('no fixture left behind', $leftover === 0, "$leftover remaining");
    $ok('demo tenant untouched', $demoAfter === $demoBefore, "tenant3 ratings $demoBefore -> $demoAfter");
    printf("\nPASS %d   FAIL %d\n", $pass, $fail);
}
