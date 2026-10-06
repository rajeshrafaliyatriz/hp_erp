<?php
/**
 * S-06, THE DECISIVE CALL: can an anonymous caller write?
 *
 * SUBJECT  POST api/jobrole-skill/store -> jobroleskillcontroller@storeSkill,
 *          chosen BY RUNNING, not by reading: it was the one candidate of five
 *          whose known-positive actually wrote into tenant 999999.
 *
 * THE COUNTER IS FIXED. The previous run scraped `DB::table('...')` literals out
 * of the method body. Four of the five candidates write through ELOQUENT MODELS,
 * so their table lists were empty and every delta read +0 - the script printed
 * NO CANDIDATE WROTE while a row was landing. A probe that cannot see the write
 * reports "nothing happened" in exactly the voice it would use for a real
 * negative.
 *
 * Now the table set is resolved from BOTH sources: DB::table literals AND every
 * `use App\Models\...` import in the controller file, with each model's $table
 * read by reflection.
 *
 * REDUNDANCY OVER EFFICIENCY. Cleanup deliberately does NOT reuse that set: it
 * sweeps a table list of its own. Two checks sharing a source share its
 * blindness, and last run it was precisely the unshared cleanup list that caught
 * the counter's blind spot.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const SID = 999999;
const TAG = 'ZZS06ANS';
const URI = 'api/jobrole-skill/store';

/* ---- resolve the controller ------------------------------------------- */
$cls = $mth = null;
foreach (app('router')->getRoutes() as $r) {
    if ($r->uri() !== URI || !in_array('POST', $r->methods(), true)) continue;
    [$cls, $mth] = explode('@', $r->getAction()['uses'], 2);
}
if (!$cls) { echo "route not found\n"; exit(1); }

$rm   = new ReflectionMethod($cls, $mth);
$file = file_get_contents($rm->getFileName());
$body = implode('', array_slice(file($rm->getFileName()),
    $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));

/* ---- THE FIXED COUNTER: literals AND model-resolved tables -------------- */
$tables = [];
preg_match_all("/DB::table\(\s*'([a-z_]+)'\s*\)/i", $body, $m);
foreach ($m[1] as $t) $tables[$t] = 'DB::table literal';

preg_match_all('/^use\s+(App\\\\Models\\\\[A-Za-z0-9_\\\\]+);/m', $file, $mm);
foreach ($mm[1] as $model) {
    if (!class_exists($model)) continue;
    try {
        $inst = new $model();
        if (method_exists($inst, 'getTable')) $tables[$inst->getTable()] = 'model ' . class_basename($model);
    } catch (Throwable $e) {}
}
$tables = array_filter($tables, fn ($src, $t) => !in_array($t, ['tbluser', 'personal_access_tokens'], true), ARRAY_FILTER_USE_BOTH);

echo "COUNTER WATCHES:\n";
foreach ($tables as $t => $src) printf("   %-26s (%s)\n", $t, $src);
echo "\n";

$countAt = function () use ($tables) {
    $n = 0;
    foreach (array_keys($tables) as $t) {
        try { $n += DB::table($t)->where('sub_institute_id', SID)->count(); } catch (Throwable $e) {}
    }
    return $n;
};

$call = function (array $p, ?string $token) use ($kernel) {
    if ($token !== null) $p['token'] = $token;
    $req = Illuminate\Http\Request::create('/' . URI, 'POST', $p);
    $req->headers->set('Accept', 'application/json');
    if ($token !== null) $req->headers->set('Authorization', 'Bearer ' . explode('|', $token, 2)[1]);
    try {
        $r = $kernel->handle($req);
        return [$r->getStatusCode(), substr(preg_replace('/\s+/', ' ', (string) $r->getContent()), 0, 130)];
    } catch (Throwable $e) {
        return [0, 'EX ' . substr($e->getMessage(), 0, 110)];
    }
};

/* payload from the validator block, read once */
$payload = function (string $n) use ($body) {
    $p = ['sub_institute_id' => SID, 'syear' => '2025', 'type' => 'API',
          'jobrole' => $n, 'department' => TAG, 'category' => TAG, 'status' => 'Active'];
    if (preg_match('/Validator::make\s*\([^,]+,\s*\[(.*?)\]\s*\)/s', $body, $m)
        && preg_match_all("/'([a-zA-Z0-9_]+)'\s*=>\s*'([^']*)'/", $m[1], $rr, PREG_SET_ORDER)) {
        foreach ($rr as [$all, $f, $rule]) {
            if (str_contains($rule, 'nullable') || isset($p[$f])) continue;
            $p[$f] = str_contains($rule, 'integer') || str_contains($rule, 'numeric') ? 1 : TAG;
        }
    }
    return $p;
};

$tokenRow = null; $madeTenant = false;

try {
    if (!DB::table('school_setup')->where('id', SID)->exists()) {
        DB::table('school_setup')->insert(['id' => SID, 'SchoolName' => 'ZZ-S06-PROBE-TENANT',
            'created_at' => now(), 'updated_at' => now()]);
        $madeTenant = true;
        echo "REGISTERED school_setup #" . SID . " - this probe only\n\n";
    }

    $profiles = DB::table('tbluserprofilemaster')
        ->whereIn('role_key', ['administrator', 'hr_manager', 'hr_executive'])->pluck('id');
    $admin = DB::table('tbluser')->whereIn('user_profile_id', $profiles)->first(['id']);
    $plain = bin2hex(random_bytes(24));
    $tokenRow = DB::table('personal_access_tokens')->insertGetId([
        'tokenable_type' => 'App\Models\auth\tbluserModel', 'tokenable_id' => $admin->id,
        'name' => TAG, 'token' => hash('sha256', $plain), 'abilities' => '["*"]',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    /* ---- RE-CONFIRM THE KNOWN-POSITIVE --------------------------------- */
    $b0 = $countAt();
    [$s1, $r1] = $call($payload(TAG . '-WITH-TOKEN'), $tokenRow . '|' . $plain);
    $a1 = $countAt();
    printf("KNOWN-POSITIVE (valid token)  http %-4d rows %+d\n   %s\n\n", $s1, $a1 - $b0, $r1);

    if ($a1 - $b0 <= 0) {
        echo "ABORT - the counter still cannot see a write, or the route did not write.\n";
        echo "The anonymous call is NOT made on an instrument that cannot observe it.\n";
    } else {
        echo "GATE PASSED: the counter sees the write, and it landed in " . SID . ".\n\n";

        /* ---- THE QUESTION ---------------------------------------------- */
        $b2 = $countAt();
        [$s2, $r2] = $call($payload(TAG . '-NO-TOKEN'), null);
        $a2 = $countAt();
        $anon = $a2 - $b2;

        $verdict = ($s2 === 0 || $s2 >= 500) ? 'ERRORED'
            : (in_array($s2, [401, 403], true) ? 'REFUSED'
            : ($anon > 0 ? '*** ACCEPTED AND WROTE ***' : 'ACCEPTED, WROTE NOTHING'));

        printf("ANONYMOUS (no token, no session, no header)  http %d\n   %s\n", $s2, $r2);
        printf("   rows written: %d\n\nVERDICT: %s\n", $anon, $verdict);

        if ($anon > 0) {
            echo "\nSTOP. A CONFIRMED UNAUTHENTICATED WRITE OUTRANKS S-06 ITSELF.\n";
            echo "The sweep does not continue from here.\n";
        } else {
            echo "\nTHIS CLEARS ONE ROUTE, NOT 336. The prior is strong and one\n";
            echo "negative does not answer for a population.\n";
        }
    }
} finally {
    /* CLEANUP USES ITS OWN LIST - deliberately not the counter's. */
    $sweep = ['s_user_jobrole', 's_users_skills', 's_user_jobrole_task', 's_user_skill_jobrole',
              'hrms_departments', 's_user_knowledge', 's_user_ability', 's_user_attitude', 's_user_behaviour'];
    $removed = 0;
    foreach ($sweep as $t) {
        try { $removed += DB::table($t)->where('sub_institute_id', SID)->delete(); } catch (Throwable $e) {}
    }
    if ($tokenRow) DB::table('personal_access_tokens')->where('id', $tokenRow)->delete();
    if ($madeTenant) DB::table('school_setup')->where('id', SID)->delete();

    $left = 0;
    foreach ($sweep as $t) {
        try { $left += DB::table($t)->where('sub_institute_id', SID)->count(); } catch (Throwable $e) {}
    }
    printf("\nCLEANUP (own list, %d tables): removed %d; %d remain at tenant %d\n",
        count($sweep), $removed, $left, SID);
    printf("school_setup #%d gone: %s   total %d (expect 11)   stray tokens: %d\n", SID,
        DB::table('school_setup')->where('id', SID)->exists() ? 'NO' : 'yes',
        DB::table('school_setup')->count(),
        DB::table('personal_access_tokens')->where('name', TAG)->count());
}
