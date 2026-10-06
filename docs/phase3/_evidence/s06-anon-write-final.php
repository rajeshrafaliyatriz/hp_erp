<?php
/**
 * S-06: CAN AN ANONYMOUS CALLER WRITE?  ONE ROUTE. TENANT 999999.
 *
 * SUBJECT  POST api/job-postings -> talent_jobpostingcontroller@store
 *          -> talent_job_postings, which carries ZERO FKs beyond the tenant.
 *
 * WHY THIS ONE, after two aborted attempts:
 *   - unguarded in BOTH layers, and reads sub_institute_id FROM THE REQUEST, so
 *     containment is possible at all (the first probe's failure)
 *   - needs NO companion rows (the second probe's failure - s_user_jobrole would
 *     have aborted on department_id even after the tenant row existed)
 *   - "feeds dashboards" was my reason for passing it over, and it does not hold
 *     in a tenant with no users
 *
 * THE TENANT ROW IS REGISTERED HERE, BEFORE ANYTHING WRITES TO IT:
 *   school_setup #999999, SchoolName 'ZZ-S06-PROBE-TENANT'
 *   Created by this script, removed by this script, table-wide re-read after.
 *   FOR THIS PROBE ONLY. Not a general-purpose test tenant.
 *   (school_setup carries 97 inbound FKs; only SchoolName is NOT NULL without a
 *   default, so a minimum viable row really is minimal.)
 *
 * THREE PROBE PROPERTIES, ALL SILENTLY FAILABLE:
 *   REACH        the known-positive must actually write
 *   DISCRIMINATE refused / accepted-and-wrote / accepted-and-wrote-nothing / errored
 *   CONTAIN      everything written must be findable and removable, TABLE-WIDE
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const SID   = 999999;
const URI   = 'api/job-postings';
const TABLE = 'talent_job_postings';
const TAG   = 'ZZ-S06-FINAL';

$call = function (array $p, ?string $token) use ($kernel) {
    if ($token !== null) $p['token'] = $token;
    $req = Illuminate\Http\Request::create('/' . URI, 'POST', $p);
    $req->headers->set('Accept', 'application/json');
    if ($token !== null) $req->headers->set('Authorization', 'Bearer ' . explode('|', $token, 2)[1]);
    try {
        $r = $kernel->handle($req);
        return [$r->getStatusCode(), substr(preg_replace('/\s+/', ' ', (string) $r->getContent()), 0, 150)];
    } catch (Throwable $e) {
        return [0, 'EXCEPTION ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 100)];
    }
};

// TABLE-WIDE. Never scoped to 999999 - scoping the search to the tenant I
// intended is exactly what missed the stray row in tenant 1.
$findAll = function () {
    $cols = array_map(fn ($c) => $c->Field, DB::select('DESCRIBE ' . TABLE));
    $titleCol = in_array('title', $cols, true) ? 'title'
        : (in_array('job_title', $cols, true) ? 'job_title' : $cols[1]);
    return DB::table(TABLE)->where($titleCol, 'like', TAG . '%')
        ->get(['id', 'sub_institute_id', $titleCol . ' as tag']);
};

$payload = fn (string $n) => [
    // BUILT FROM THE VALIDATOR, NOT BY TRIAL AND ERROR. Two runs aborted on
    // one missing field at a time (department_id, then positions) - discovering
    // a contract field-by-field is the same waste as measuring a population one
    // row at a time. Rules read at talent_jobpostingcontroller.php:125-142.
    // REQUIRED: title, department_id (integer, NO exists: rule so no companion
    // department is needed), location, employment_type, positions, status
    // (in:active,inactive - 'draft' is NOT accepted).
    'sub_institute_id' => SID, 'syear' => '2025', 'type' => 'API',
    'title'           => $n,
    'department_id'   => 999999,
    'location'        => TAG,
    'employment_type' => 'Full-Time',  // DB enum, not the validator's free string
    'positions'       => 1,
    'status'          => 'Inactive',   // validator says in:active,inactive (lower);
    // the COLUMN is enum('Active','Draft','Closed','Inactive'). The validator and
    // the schema disagree on case, and MySQL truncated the write to a warning
    // rather than an error - a 200 with no row. Filed.
    'description'     => TAG,
];

$tokenRow = null;
$madeTenant = false;

try {
    // ---- REGISTER AND CREATE THE TENANT ROW -------------------------------
    if (!DB::table('school_setup')->where('id', SID)->exists()) {
        DB::table('school_setup')->insert([
            'id' => SID, 'SchoolName' => 'ZZ-S06-PROBE-TENANT',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $madeTenant = true;
        printf("REGISTERED: school_setup #%d 'ZZ-S06-PROBE-TENANT' created for this probe only\n", SID);
    }

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

    // ---- PRE-FLIGHT: REACH + CONTAIN --------------------------------------
    [$s1, $b1] = $call($payload(TAG . '-WITH-TOKEN'), $tokenRow . '|' . $plain);
    $landed = $findAll();
    printf("PRE-FLIGHT (valid token)  http %-4d rows %d\n   %s\n", $s1, count($landed), $b1);
    foreach ($landed as $r) printf("   landed: id=%s sid=%s\n", $r->id, $r->sub_institute_id);

    $offTarget = 0;
    foreach ($landed as $r) if ((int) $r->sub_institute_id !== SID) $offTarget++;

    if (count($landed) === 0) {
        echo "\nABORT - REACH FAILED. Cannot write even with a token, so an anonymous\n";
        echo "result would say nothing about authentication.\n";
    } elseif ($offTarget > 0) {
        echo "\nABORT - CONTAINMENT FAILED. A row landed outside tenant " . SID . ".\n";
        echo "The request tenant was NOT honoured. Not proceeding to the anonymous call.\n";
    } else {
        echo "\nGATE PASSED: reached the code, and every row landed in " . SID . ".\n\n";

        [$s2, $b2] = $call($payload(TAG . '-NO-TOKEN'), null);
        $anon = 0;
        foreach ($findAll() as $r) if (str_contains((string) $r->tag, 'NO-TOKEN')) $anon++;

        $verdict = ($s2 === 0 || $s2 >= 500) ? 'ERRORED'
            : (in_array($s2, [401, 403], true) ? 'REFUSED'
            : ($anon > 0 ? '*** ACCEPTED AND WROTE ***' : 'ACCEPTED, WROTE NOTHING'));

        printf("ANONYMOUS (no token, no session, no header)  http %d\n   %s\n", $s2, $b2);
        printf("   rows written by the anonymous call: %d\n\nVERDICT: %s\n", $anon, $verdict);

        if ($anon > 0) {
            echo "\nSTOP. A CONFIRMED UNAUTHENTICATED WRITE OUTRANKS S-06 ITSELF.\n";
            echo "The sweep does not continue from here.\n";
        } else {
            echo "\nTHIS CLEARS ONE ROUTE, NOT 336. The prior is strong and one\n";
            echo "negative does not answer for a population.\n";
        }
    }
} finally {
    $left = $findAll();
    foreach ($left as $r) DB::table(TABLE)->where('id', $r->id)->delete();
    if ($tokenRow) DB::table('personal_access_tokens')->where('id', $tokenRow)->delete();
    if ($madeTenant) DB::table('school_setup')->where('id', SID)->delete();

    $still = $findAll();
    printf("\nCLEANUP (TABLE-WIDE): removed %d row(s); %d remain tagged %s\n", count($left), count($still), TAG);
    foreach ($still as $r) printf("   STILL PRESENT: id=%s sid=%s\n", $r->id, $r->sub_institute_id);
    printf("school_setup #%d removed: %s\n", SID,
        DB::table('school_setup')->where('id', SID)->exists() ? 'NO - STILL THERE' : 'yes');
    printf("school_setup rows now: %d (was 11)\n", DB::table('school_setup')->count());
    printf("tenant 3 rows in %s: %d (never named by this probe)\n",
        TABLE, DB::table(TABLE)->where('sub_institute_id', 3)->count());
}
