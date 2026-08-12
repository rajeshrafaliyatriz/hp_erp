<?php
/**
 * PROOF: department options come from the tenant's own hrms_departments, with
 * already-used values first, and nothing from another tenant.
 *
 * WRITTEN TO A FILE, NOT PASSED TO `php -r`. The class name
 * App\Models\auth\tbluserModel contains \t, and every attempt to send it through
 * a shell string has turned that into a TAB and produced a "class not found"
 * that looks like a code fault. Sixth time; the file is the fix.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$pass = 0; $fail = 0;
$ok = function ($l, $c, $d = '') use (&$pass, &$fail) {
    if ($c) { $pass++; printf("  PASS  %-50s %s\n", $l, $d); }
    else    { $fail++; printf("  FAIL  %-50s %s\n", $l, $d); }
};

$profiles = DB::table('tbluserprofilemaster')
    ->whereIn('role_key', ['administrator', 'hr_manager', 'hr_executive'])->pluck('id');

foreach ([7, 3] as $sid) {
    $u = DB::table('tbluser')->where('sub_institute_id', $sid)
        ->whereIn('user_profile_id', $profiles)->first(['id']);
    if (!$u) { printf("tenant %d: no admin, skipped\n", $sid); continue; }

    $plain = bin2hex(random_bytes(24));
    $tid = DB::table('personal_access_tokens')->insertGetId([
        'tokenable_type' => 'App\Models\auth\tbluserModel', 'tokenable_id' => $u->id,
        'name' => 'ZZ-DEPT-PROOF-DELETE-ME', 'token' => hash('sha256', $plain),
        'abilities' => '["*"]', 'created_at' => now(), 'updated_at' => now(),
    ]);

    try {
        $req = Illuminate\Http\Request::create('/api/competency/library/meta', 'GET', [
            'token' => $tid . '|' . $plain, 'type' => 'API', 'syear' => '2025',
            'sub_institute_id' => $sid,
        ]);
        $req->headers->set('Accept', 'application/json');
        $req->headers->set('Authorization', 'Bearer ' . $plain);
        $res = $kernel->handle($req);
        $j = json_decode((string) $res->getContent(), true);

        // Find the department list wherever it sits in the envelope.
        // THE KEY IS `departments`, PLURAL. My first version looked for
        // `department` - the bucket name inside buildMeta - and found an
        // unrelated empty array, then reported 0 options as though the change had
        // not worked. The response renames the buckets on the way out.
        $depts = null;
        $walk = function ($node) use (&$walk, &$depts) {
            if (!is_array($node)) return;
            foreach ($node as $k => $v) {
                if ($k === 'departments' && is_array($v) && $depts === null) { $depts = $v; return; }
                $walk($v);
            }
        };
        $walk($j);
        $depts = $depts ?? [];

        $own = DB::table('hrms_departments')->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')->pluck('department')
            ->map(fn ($x) => mb_strtolower(trim((string) $x)))->flip();
        $used = DB::table('s_users_skills')->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')->whereNotNull('department')->where('department', '!=', '')
            ->distinct()->pluck('department')
            ->map(fn ($x) => mb_strtolower(trim((string) $x)))->flip();

        $foreign = 0;
        foreach ($depts as $d) {
            $k = mb_strtolower(trim((string) $d));
            if (!isset($own[$k]) && !isset($used[$k])) $foreign++;
        }

        printf("\ntenant %d  (http %d)\n", $sid, $res->getStatusCode());
        printf("  options=%d  own hrms_departments=%d  already-used=%d\n", count($depts), count($own), count($used));
        $ok("tenant $sid: options are not empty", count($depts) > 0, count($depts) . ' options');
        $ok("tenant $sid: NOTHING from another tenant", $foreign === 0, "$foreign foreign values");

        // Already-used values must lead. Check the first N entries are all used
        // ones, where N = how many used values survived de-duplication.
        $leadOk = true;
        $n = 0;
        foreach ($depts as $d) {
            if (isset($used[mb_strtolower(trim((string) $d))])) { $n++; continue; }
            break;
        }
        $leadOk = ($n === count($used)) || (count($used) === 0);
        $ok("tenant $sid: already-used values lead the list", $leadOk, "$n leading, " . count($used) . ' used');
        printf("  first 5: %s\n", implode(' | ', array_slice($depts, 0, 5)));
    } finally {
        DB::table('personal_access_tokens')->where('id', $tid)->delete();
    }
}

printf("\nPASS %d   FAIL %d\n", $pass, $fail);
