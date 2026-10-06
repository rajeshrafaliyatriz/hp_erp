<?php
/**
 * S-06 PROBE: CAN AN ANONYMOUS CALLER WRITE?
 *
 * ONE ROUTE. TENANT 999999. NEVER TENANT 3.
 *
 * WHY THIS ROUTE IS THE SAFEST, stated before it runs:
 *
 *   `POST api/agentic/workflows` -> `agentic_workflows`, chosen from 9 routes that
 *   survived seven conditions (mutating, non-destructive, ONE table, ONE insert,
 *   no Mail/Http/Queue/Storage/dispatch, no file upload, takes a tenant) AND carry
 *   no guard in either layer. 16 of the 25 candidates guard themselves in the
 *   controller; testing one of those would prove nothing, because refusing is what
 *   it is supposed to do.
 *
 *   Among the 9 it is the only INERT DEFINITION table:
 *     hrms_holidays / hrms_weekdays / hrms_leave_types  feed leave calculations
 *     s_users_skills (x2)                               real product library data
 *     lms_assignments                                   ties a course to a person
 *     talent_offboarding_clearances                     a named person's offboarding
 *     agentic_messages                                  may be consumed by a run
 *     agentic_workflows                                 A DEFINITION - nothing
 *                                                       computes from its existence
 *
 * FOUR OUTCOMES, NOT TWO. The verdict is decided on THE ROW, not the status code:
 *   REFUSED                  401/403
 *   ACCEPTED AND WROTE       2xx and a row appeared        <- THE FINDING
 *   ACCEPTED, WROTE NOTHING  2xx and no row
 *   ERRORED                  5xx/exception - proves nothing
 *
 * AND IT MUST REACH THE CODE. A known-positive call WITH a valid token runs first.
 * If that does not write, the probe is broken and its anonymous result means
 * nothing - the failure mode that made three earlier probes report a defect that
 * was really their own payload.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const SID   = 999999;
const URI   = 'api/agentic/workflows';
const TABLE = 'agentic_workflows';
const TAG   = 'ZZ-S06-ANON-PROBE';

// Columns read from DESCRIBE, not guessed: id, sub_institute_id, name,
// description, mode, status, created_by, updated_by, deleted_by, timestamps.
// MODE MUST BE sequential|parallel (WorkflowController::MODES) - my first
// payload sent 'manual' and the KNOWN-POSITIVE caught it. Without that check
// the anonymous call would have returned 422 and I could have read it as
// REFUSED. The probe that cannot reach the code reports auth results it never
// measured.
// The first version of this probe also matched a `title` column, which does not
// exist - the query threw and the probe died before asking its question.
$payload = function (string $name) {
    return [
        'sub_institute_id' => SID, 'syear' => '2025', 'type' => 'API',
        'name' => $name, 'description' => TAG, 'mode' => 'sequential', 'status' => 'draft',
    ];
};

$call = function (array $params, ?string $token) use ($kernel) {
    if ($token !== null) $params['token'] = $token;
    $req = Illuminate\Http\Request::create('/' . URI, 'POST', $params);
    $req->headers->set('Accept', 'application/json');
    if ($token !== null) $req->headers->set('Authorization', 'Bearer ' . explode('|', $token, 2)[1]);
    try {
        $res = $kernel->handle($req);
        return [$res->getStatusCode(), substr(preg_replace('/\s+/', ' ', (string) $res->getContent()), 0, 130)];
    } catch (Throwable $e) {
        return [0, 'EXCEPTION ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 80)];
    }
};

$rowsWith = function (string $name) {
    return DB::table(TABLE)->where('sub_institute_id', SID)->where('name', $name)->count();
};

$tokenRow = null;

try {
    printf("BEFORE: %s rows at tenant %d = %d\n\n", TABLE, SID,
        DB::table(TABLE)->where('sub_institute_id', SID)->count());

    $profiles = DB::table('tbluserprofilemaster')
        ->whereIn('role_key', ['administrator', 'hr_manager', 'hr_executive'])->pluck('id');
    $admin = DB::table('tbluser')->whereIn('user_profile_id', $profiles)->first(['id']);
    $plain = bin2hex(random_bytes(24));
    $tokenRow = DB::table('personal_access_tokens')->insertGetId([
        'tokenable_type' => 'App\Models\auth\tbluserModel', 'tokenable_id' => $admin->id,
        'name' => TAG, 'token' => hash('sha256', $plain), 'abilities' => '["*"]',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    [$sPos, $bPos] = $call($payload(TAG . '-WITH-TOKEN'), $tokenRow . '|' . $plain);
    $wrotePos = $rowsWith(TAG . '-WITH-TOKEN');
    printf("KNOWN-POSITIVE (valid token)   http %-4d  rows written %d\n   %s\n\n", $sPos, $wrotePos, $bPos);

    if ($wrotePos === 0) {
        echo "PROBE IS INVALID: it cannot write even WITH a token.\n";
        echo "Whatever the anonymous call does, THIS RUN SAYS NOTHING ABOUT IT -\n";
        echo "the cause is the payload, the validation or the route, not auth.\n";
    } else {
        [$sAnon, $bAnon] = $call($payload(TAG . '-NO-TOKEN'), null);
        $wroteAnon = $rowsWith(TAG . '-NO-TOKEN');

        $verdict = ($sAnon === 0 || $sAnon >= 500) ? 'ERRORED'
            : (in_array($sAnon, [401, 403], true) ? 'REFUSED'
            : ($wroteAnon > 0 ? '*** ACCEPTED AND WROTE ***' : 'ACCEPTED, WROTE NOTHING'));

        printf("ANONYMOUS (no token, no session, no header)   http %d\n   %s\n", $sAnon, $bAnon);
        printf("   rows written at tenant %d: %d\n\nVERDICT: %s\n", SID, $wroteAnon, $verdict);

        if ($wroteAnon > 0) {
            echo "\nSTOP. A CONFIRMED UNAUTHENTICATED WRITE OUTRANKS S-06 ITSELF.\n";
            echo "The sweep does not continue from here.\n";
        } else {
            echo "\nTHIS CLEARS ONE ROUTE, NOT 338. The prior is strong and one\n";
            echo "negative does not answer for a population.\n";
        }
    }
} finally {
    $removed = DB::table(TABLE)->where('sub_institute_id', SID)->delete();
    if ($tokenRow) DB::table('personal_access_tokens')->where('id', $tokenRow)->delete();
    printf("\nCLEANUP: removed %d row(s) at tenant %d; %d remain. Temp token removed.\n",
        $removed, SID, DB::table(TABLE)->where('sub_institute_id', SID)->count());
    echo "TENANT 3 UNTOUCHED: this probe never names it.\n";
}
