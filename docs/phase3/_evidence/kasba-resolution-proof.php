<?php
/**
 * PROOF: the item_id validation branch, all five dimensions.
 *
 * TWO CASES PER DIMENSION, because one is not a test:
 *   KNOWN-POSITIVE  a REAL id of the caller's tenant  -> must be STORED as a key
 *   KNOWN-NEGATIVE  an id that does not exist         -> must be DROPPED to label
 *
 * A branch that only ever sees valid input passes by doing nothing. The old
 * `=== 'skill'` branch would pass every positive case here and fail every
 * negative one for four of the five types - which is exactly the defect.
 *
 * SAFETY: tenant 7. One competency created per case and deleted in the finally.
 * The demo tenant is counted before and after and must not move.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const SID = 7;
$TABLES = [
    'skill'     => 's_users_skills',
    'knowledge' => 's_user_knowledge',
    'ability'   => 's_user_ability',
    'attitude'  => 's_user_attitude',
    'behaviour' => 's_user_behaviour',
];

$profiles = DB::table('tbluserprofilemaster')
    ->whereIn('role_key', ['administrator', 'hr_manager', 'hr_executive'])->pluck('id');
$admin = DB::table('tbluser')->where('sub_institute_id', SID)
    ->whereIn('user_profile_id', $profiles)->first(['id']);
if (!$admin) { echo "NO ADMIN IN TENANT 7 - refusing\n"; exit(1); }

$plain = bin2hex(random_bytes(24));
$tid = DB::table('personal_access_tokens')->insertGetId([
    'tokenable_type' => 'App\Models\auth\tbluserModel', 'tokenable_id' => $admin->id,
    'name' => 'ZZ-KASBA-PROOF-DELETE-ME', 'token' => hash('sha256', $plain),
    'abilities' => '["*"]', 'created_at' => now(), 'updated_at' => now(),
]);
$TOKEN = $tid . '|' . $plain;

$demoBefore = DB::table('competency_kasba_item')->where('sub_institute_id', 3)->count();
$made = [];
$pass = 0; $fail = 0;
$ok = function ($l, $c, $d = '') use (&$pass, &$fail) {
    if ($c) { $pass++; printf("  PASS  %-46s %s\n", $l, $d); }
    else    { $fail++; printf("  FAIL  %-46s %s\n", $l, $d); }
};

$create = function (string $type, $itemId, string $label) use ($kernel, $TOKEN, &$made) {
    $req = Illuminate\Http\Request::create('/api/competency/definitions', 'POST', [
        'token' => $TOKEN, 'type' => 'API', 'syear' => '2025', 'sub_institute_id' => SID,
        'name'  => 'ZZ-PROOF-' . $type . '-' . $label . '-' . substr(md5((string) $itemId), 0, 6),
        // `code` is NOT in the controller's validation rules and the column is
        // NOT NULL, so omitting it returns a 500 instead of a 422. Found by this
        // proof failing; filed separately. Sent here so the branch can be tested.
        'code'  => 'ZZ' . strtoupper(substr($type, 0, 3)) . substr(md5($label . $itemId), 0, 5),
        'items' => [['kasba_type' => $type, 'item_id' => $itemId, 'item_label' => 'held-' . $type, 'weight' => 1]],
    ]);
    $req->headers->set('Accept', 'application/json');
    $req->headers->set('Authorization', 'Bearer ' . explode('|', $TOKEN, 2)[1]);
    $res = $kernel->handle($req);
    $j = json_decode((string) $res->getContent(), true);
    $id = $j['data']['id'] ?? $j['id'] ?? null;
    if ($id) $made[] = (int) $id;
    if (!$id && !isset($GLOBALS['shown'])) {
        $GLOBALS['shown'] = 1;
        printf("  [first failure] http %d :: %s
", $res->getStatusCode(),
            substr(preg_replace('/\s+/', ' ', (string) $res->getContent()), 0, 180));
    }
    return [$res->getStatusCode(), $id];
};

try {
    foreach ($TABLES as $type => $table) {
        $real = DB::table($table)->where('sub_institute_id', SID)->value('id');
        if (!$real) { printf("  SKIP  %-46s no %s row in tenant 7\n", $type, $table); continue; }

        // KNOWN-POSITIVE - a real id must survive as a key
        [$s1, $c1] = $create($type, (int) $real, 'real');
        $stored = $c1 ? DB::table('competency_kasba_item')->where('competency_id', $c1)->value('item_id') : null;
        $ok("$type: REAL id is stored as a key", (int) $stored === (int) $real, "sent $real stored " . json_encode($stored));

        // KNOWN-NEGATIVE - a nonexistent id must be dropped to a label
        $fake = ((int) DB::table($table)->max('id')) + 999000;
        [$s2, $c2] = $create($type, $fake, 'fake');
        $stored2 = $c2 ? DB::table('competency_kasba_item')->where('competency_id', $c2)->value('item_id') : 'NO ROW';
        $ok("$type: FAKE id is dropped to a label", $c2 !== null && $stored2 === null, "sent $fake stored " . json_encode($stored2));
    }
} finally {
    foreach ($made as $cid) {
        DB::table('competency_kasba_item')->where('competency_id', $cid)->delete();
        DB::table('competency')->where('id', $cid)->delete();
    }
    DB::table('personal_access_tokens')->where('id', $tid)->delete();
    $left = DB::table('competency')->where('sub_institute_id', SID)->where('name', 'like', 'ZZ-PROOF-%')->count();
    $demoAfter = DB::table('competency_kasba_item')->where('sub_institute_id', 3)->count();
    printf("\ncleanup: %d fixtures removed, %d ZZ-PROOF rows left behind\n", count($made), $left);
    $ok('no fixture left behind', $left === 0, "$left remaining");
    $ok('demo tenant untouched', $demoAfter === $demoBefore, "tenant3 kasba_item $demoBefore -> $demoAfter");
    printf("\nPASS %d   FAIL %d\n", $pass, $fail);
}
