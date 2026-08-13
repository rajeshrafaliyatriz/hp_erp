<?php
/**
 * S-06 RETRY: CAN AN ANONYMOUS CALLER WRITE?  ONE ROUTE. TENANT 999999.
 *
 * SUBJECT: POST api/jobrole-skill/store -> jobroleskillcontroller@storeSkill,
 * model App\Models\libraries\Jobroleskill, table `s_user_jobrole`.
 *
 * Chosen from the 5 routes that are UNGUARDED in both layers AND read
 * sub_institute_id FROM THE REQUEST - the only ones where containment is
 * possible. Of those five it names no person and feeds no calculation for a
 * tenant that does not exist:
 *   attendance/punch-in        writes a real person's attendance
 *   job-postings               talent data, feeds dashboards
 *   interview-schedules        names a person
 *   school-setup               507 lines across FIVE tables incl. hrms_departments
 *   jobrole-skill/store        a job-role row. THIS ONE.
 *
 * RELAXED CONDITION, RECORDED: "one table, one insert" was dropped. Multiplicity
 * affects cleanup EFFORT, not containment - a row is still removable by
 * sub_institute_id. "NO SIDE EFFECTS" was NOT relaxed and will not be.
 *
 * THE PRE-FLIGHT IS A HARD GATE. The last probe assumed the request tenant was
 * honoured, and a row landed in TENANT 1 while cleanup watched 999999. So the
 * known-positive runs first and the row is LOCATED, not assumed. Anywhere but
 * 999999 aborts before the anonymous call happens.
 *
 * THREE PROBE PROPERTIES, ALL SILENTLY FAILABLE:
 *   REACH        the known-positive must actually write
 *   DISCRIMINATE refused / accepted-and-wrote / accepted-and-wrote-nothing / errored
 *   CONTAIN      everything written must be findable and removable
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const SID   = 999999;
const URI   = 'api/jobrole-skill/store';
const TABLE = 's_user_jobrole';
const TAG   = 'ZZ-S06-RETRY';

$call = function (array $p, ?string $token) use ($kernel) {
    if ($token !== null) $p['token'] = $token;
    $req = Illuminate\Http\Request::create('/' . URI, 'POST', $p);
    $req->headers->set('Accept', 'application/json');
    if ($token !== null) $req->headers->set('Authorization', 'Bearer ' . explode('|', $token, 2)[1]);
    try {
        $r = $kernel->handle($req);
        return [$r->getStatusCode(), substr(preg_replace('/\s+/', ' ', (string) $r->getContent()), 0, 140)];
    } catch (Throwable $e) {
        return [0, 'EXCEPTION ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 90)];
    }
};

$payload = fn (string $n) => [
    'sub_institute_id' => SID, 'syear' => '2025', 'type' => 'API',
    'jobrole' => $n, 'jobrole_category' => TAG, 'department' => TAG,
    'status' => 'Active', 'user_id' => 1,
];

// TABLE-WIDE, never scoped to 999999. Scoping the search to the tenant I
// intended is exactly what missed the stray row last time.
$findAll = fn () => DB::table(TABLE)->where('jobrole', 'like', TAG . '%')
    ->get(['id', 'sub_institute_id', 'jobrole']);

$tokenRow = null;
try {
    printf("BEFORE (table-wide): %d %s row(s) tagged %s\n\n", count($findAll()), TABLE, TAG);

    $profiles = DB::table('tbluserprofilemaster')
        ->whereIn('role_key', ['administrator', 'hr_manager', 'hr_executive'])->pluck('id');
    $admin = DB::table('tbluser')->whereIn('user_profile_id', $profiles)->first(['id']);
    $plain = bin2hex(random_bytes(24));
    $tokenRow = DB::table('personal_access_tokens')->insertGetId([
        'tokenable_type' => 'App\Models\auth\tbluserModel', 'tokenable_id' => $admin->id,
        'name' => TAG, 'token' => hash('sha256', $plain), 'abilities' => '["*"]',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // ---- PRE-FLIGHT: REACH + CONTAIN, both checked before anything else ----
    [$s1, $b1] = $call($payload(TAG . '-WITH-TOKEN'), $tokenRow . '|' . $plain);
    $landed = $findAll();
    printf("PRE-FLIGHT (valid token)  http %-4d rows %d\n   %s\n", $s1, count($landed), $b1);
    foreach ($landed as $r) printf("   landed: id=%s sid=%s\n", $r->id, $r->sub_institute_id);

    $offTarget = collect($landed)->filter(fn ($r) => (int) $r->sub_institute_id !== SID)->count();

    if (count($landed) === 0) {
        echo "\nABORT - REACH FAILED. The probe cannot write even with a token, so\n";
        echo "an anonymous result would say nothing about authentication.\n";
    } elseif ($offTarget > 0) {
        echo "\nABORT - CONTAINMENT FAILED. A row landed outside tenant " . SID . ".\n";
        echo "The request tenant was NOT honoured. Not proceeding to the anonymous call.\n";
    } else {
        echo "\nGATE PASSED: reached the code, and every row landed in " . SID . ".\n\n";

        [$s2, $b2] = $call($payload(TAG . '-NO-TOKEN'), null);
        $after = $findAll();
        $anon = collect($after)->filter(fn ($r) => str_contains((string) $r->jobrole, 'NO-TOKEN'))->count();

        $verdict = ($s2 === 0 || $s2 >= 500) ? 'ERRORED'
            : (in_array($s2, [401, 403], true) ? 'REFUSED'
            : ($anon > 0 ? '*** ACCEPTED AND WROTE ***' : 'ACCEPTED, WROTE NOTHING'));

        printf("ANONYMOUS (no token, no session, no header)  http %d\n   %s\n", $s2, $b2);
        printf("   rows written by the anonymous call: %d\n\nVERDICT: %s\n", $anon, $verdict);

        if ($anon > 0) {
            echo "\nSTOP. A CONFIRMED UNAUTHENTICATED WRITE OUTRANKS S-06 ITSELF.\n";
        } else {
            echo "\nTHIS CLEARS ONE ROUTE, NOT 336. The prior is strong and one\n";
            echo "negative does not answer for a population.\n";
        }
    }
} finally {
    $left = $findAll();
    foreach ($left as $r) DB::table(TABLE)->where('id', $r->id)->delete();
    if ($tokenRow) DB::table('personal_access_tokens')->where('id', $tokenRow)->delete();
    $stillThere = $findAll();
    printf("\nCLEANUP (TABLE-WIDE): removed %d, %d remain tagged %s\n", count($left), count($stillThere), TAG);
    foreach ($stillThere as $r) printf("   STILL PRESENT: id=%s sid=%s\n", $r->id, $r->sub_institute_id);
    printf("tenant 3 rows in %s: %d (never named by this probe)\n",
        TABLE, DB::table(TABLE)->where('sub_institute_id', 3)->count());
}
