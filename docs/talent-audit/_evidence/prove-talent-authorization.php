<?php

/**
 * PROOF that the Talent ownership boundary now holds — and that HR still works.
 *
 * Two halves, and the second matters as much as the first:
 *
 *   1. an employee is refused on a colleague's record, and gets 404 (not 403)
 *      for a cross-tenant id, so ids cannot be probed for existence;
 *   2. every one of those same calls still succeeds for HR.
 *
 * Half 2 is the one the audit's own recommendation table omitted, and it is the
 * half that catches the menu-225 failure: a guard that refuses everybody looks
 * exactly like a guard that works, until somebody tries to do their job.
 *
 * Everything runs inside a transaction that is always rolled back, so no id
 * bookkeeping is needed and nothing survives a crash. Tenant 6, app database.
 *
 *   php Docs/talent-audit/_evidence/prove-talent-authorization.php
 */

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\SubjectAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

const TENANT = 6;
const MARKER = 'PROOF-TALENT-AUTHZ';

$ok = 0;
$bad = 0;

/** printf-safe rendering: arrays and bools both show up readably. */
function describe($value): string
{
    if (is_bool($value)) {
        return var_export($value, true);
    }

    return is_array($value) ? '[' . implode(',', $value) . ']' : (string) $value;
}

function check(string $label, $expected, $actual, string $note = ''): void
{
    global $ok, $bad;

    $pass = $expected === $actual;
    $pass ? $ok++ : $bad++;

    printf(
        "  %-6s %-58s want=%-6s got=%-6s %s\n",
        $pass ? 'ok' : 'FAIL',
        $label,
        describe($expected),
        describe($actual),
        $note
    );
}

/** A user on tenant 6 whose profile carries $roleKey. */
function userWithRole(string $roleKey): ?int
{
    $profileIds = DB::table('tbluserprofilemaster')
        ->where('sub_institute_id', TENANT)
        ->where('role_key', $roleKey)
        ->whereNull('deleted_at')
        ->pluck('id');

    if ($profileIds->isEmpty()) {
        return null;
    }

    $id = DB::table('tbluser')
        ->where('sub_institute_id', TENANT)
        ->whereIn('user_profile_id', $profileIds->all())
        ->value('id');

    return $id ? (int) $id : null;
}

DB::beginTransaction();

try {
    /* ── Actors ──────────────────────────────────────────────────────────── */

    $me        = userWithRole('employee');
    $hr        = userWithRole('hr_manager') ?: userWithRole('administrator');
    $colleague = DB::table('tbluser')->where('sub_institute_id', TENANT)
        ->where('id', '!=', $me)->value('id');
    $foreign   = DB::table('tbluser')->where('sub_institute_id', '!=', TENANT)
        ->whereNotNull('sub_institute_id')->value('id');

    if (!$me || !$hr || !$colleague) {
        echo "cannot run: need an employee, an HR/admin and a second user on tenant " . TENANT . "\n";
        exit(1);
    }

    printf("employee=%s  colleague=%s  hr=%s  foreign-tenant user=%s\n\n", $me, $colleague, $hr, $foreign ?: 'none');

    $token = function (int $userId): string {
        return App\Models\auth\tbluserModel::find($userId)->createToken(MARKER)->plainTextToken;
    };

    $meToken = $token($me);
    $hrToken = $token($hr);

    $call = function (string $method, string $uri, string $bearer, array $body = []) use ($kernel) {
        $request = Request::create('/api' . $uri, $method, $body);
        $request->headers->set('Authorization', 'Bearer ' . $bearer);
        $request->headers->set('Accept', 'application/json');
        $response = $kernel->handle($request);

        return [$response->getStatusCode(), json_decode($response->getContent(), true)];
    };

    /* ── Fixtures: a review for each of them ─────────────────────────────── */

    $cycleId = DB::table('s_performance_cycles')->insertGetId([
        'sub_institute_id' => TENANT, 'name' => MARKER, 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $review = function (int $userId, ?int $managerId = null) use ($cycleId): int {
        return DB::table('s_performance_reviews')->insertGetId([
            'sub_institute_id' => TENANT, 'cycle_id' => $cycleId, 'user_id' => $userId,
            'manager_id' => $managerId, 'stage' => 'self_review', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $myReview        = $review($me);
    $colleagueReview = $review($colleague);
    // A review where the EMPLOYEE is the named manager - the row path that a
    // role list alone can never authorise.
    $managedReview   = $review($colleague, $me);

    echo "── S1: the field-level review rule ──\n";

    [$s] = $call('PUT', "/performance/reviews/$myReview", $meToken, ['self_rating' => 4]);
    check('employee sets self_rating on OWN review', 200, $s);
    check('  ... and it persisted', 4.0,
        (float) DB::table('s_performance_reviews')->where('id', $myReview)->value('self_rating'));

    [$s, $b] = $call('PUT', "/performance/reviews/$myReview", $meToken, ['manager_rating' => 5]);
    check('employee sets ONLY manager_rating on own review', 403, $s);
    check('  ... manager_rating untouched', null,
        DB::table('s_performance_reviews')->where('id', $myReview)->value('manager_rating'));

    [$s, $b] = $call('PUT', "/performance/reviews/$myReview", $meToken,
        ['self_rating' => 3, 'manager_rating' => 5]);
    check('employee sends self_rating + manager_rating', 200, $s);
    check('  ... self_rating applied', 3.0,
        (float) DB::table('s_performance_reviews')->where('id', $myReview)->value('self_rating'));
    check('  ... manager_rating still untouched', null,
        DB::table('s_performance_reviews')->where('id', $myReview)->value('manager_rating'));
    check('  ... and the response names what it ignored', 'manager_rating',
        implode(',', $b['ignored'] ?? []));

    [$s] = $call('PUT', "/performance/reviews/$colleagueReview", $meToken, ['self_rating' => 5]);
    check("employee writes a COLLEAGUE's review", 403, $s);

    [$s] = $call('GET', "/performance/reviews/$colleagueReview", $meToken);
    check("employee opens a COLLEAGUE's review", 403, $s);

    [$s] = $call('GET', "/performance/reviews/$myReview", $meToken);
    check('employee opens their OWN review', 200, $s, '(self-appraisal needs this)');

    [$s] = $call('PUT', "/performance/reviews/$managedReview", $meToken, ['manager_rating' => 4]);
    check('row MANAGER (employee profile) sets manager_rating', 200, $s, '(the row path)');

    [$s] = $call('PUT', "/performance/reviews/$managedReview", $meToken, ['self_rating' => 1]);
    check("row manager writes the subject's self_rating", 403, $s, '(not theirs to type)');

    [$s] = $call('PUT', "/performance/reviews/$myReview", $meToken, ['due_date' => '2027-01-01']);
    check('employee sets due_date on own review', 403, $s, '(HR-only field)');

    echo "\n── The list scope ──\n";

    [$s, $b] = $call('GET', '/performance/reviews', $meToken);
    $rows = $b['data'] ?? [];
    // presentRow() nests the subject as employee.id - see :969.
    $mine = array_filter($rows, fn ($r) => (int) ($r['employee']['id'] ?? $r['user_id'] ?? 0) === $me);
    check('employee lists reviews', 200, $s);
    check('  ... every row is theirs', count($rows), count($mine));

    [$s, $b] = $call('GET', '/performance/reviews', $meToken, ['user_id_filter' => $colleague]);
    check("employee filters the list to a colleague", 200, $s, '(not 403)');
    check('  ... and gets nothing', 0, count($b['data'] ?? []));

    echo "\n── S2, S6, S7: the gated write groups ──\n";

    foreach ([
        ['PUT',  "/performance/calibration-sessions/1/calibrate", 'S2 calibrate'],
        ['POST', '/performance/calibration-sessions',             'S2 create session'],
        ['PUT',  '/performance/appraisals/1/decision',            'S6 appraisal decision'],
        ['PUT',  '/performance/compensation/1/decision',          'S6 compensation decision'],
        ['PUT',  '/performance/bonus/1/decision',                 'S6 bonus decision'],
        ['POST', '/performance/goals',                            'S7 create goal'],
    ] as [$method, $uri, $label]) {
        [$s] = $call($method, $uri, $meToken);
        check("employee: $label", 403, $s);
    }

    echo "\n── S4: offboarding ──\n";

    foreach ([
        ['GET',    '/offboarding/cases',   'list exit cases'],
        ['POST',   '/offboarding/cases',   'open an exit case'],
        ['DELETE', '/offboarding/cases/1', 'delete an exit case'],
    ] as [$method, $uri, $label]) {
        [$s] = $call($method, $uri, $meToken, ['employee_id' => $colleague, 'exit_type' => 'involuntary']);
        check("employee: $label", 403, $s);
    }

    $before = DB::table('talent_offboarding_cases')->count();
    $call('POST', '/offboarding/cases', $meToken, ['employee_id' => $colleague, 'exit_type' => 'involuntary']);
    check('  ... and no case was created', $before, DB::table('talent_offboarding_cases')->count());

    echo "\n── S5, S8, S9: competency and mobility ──\n";

    foreach ([
        ['POST', '/competency/certifications',            'S5 create a certification'],
        ['PUT',  '/competency/certifications/1',          'S5 edit/verify a certification'],
        ['POST', '/competency/development-plans',         'S7 create a development plan'],
        ['POST', '/competency/assessments',               'S8 launch an assessment'],
        ['POST', '/competency/learning-assignments',      'S3 assign learning'],
        ['GET',  '/mobility/successions',                 'S9 read succession slates'],
        ['GET',  '/mobility/pools',                       'S9 read talent pools'],
    ] as [$method, $uri, $label]) {
        [$s] = $call($method, $uri, $meToken);
        check("employee: $label", 403, $s);
    }

    [$s] = $call('GET', '/mobility/jobs', $meToken);
    check('employee browses the internal job board', 200, $s, '(stays open by design)');

    [$s] = $call('GET', '/competency/my-certifications', $meToken);
    check('employee reads their OWN certifications', 200, $s, '(the replacement surface)');

    echo "\n── S10: the dashboard feed redacts names ──\n";

    $exitCase = DB::table('talent_offboarding_cases')->insertGetId([
        'sub_institute_id' => TENANT, 'employee_id' => $colleague,
        'exit_type' => 'voluntary', 'status' => 'Notice Period',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $colleagueName = trim((string) DB::table('tbluser')->where('id', $colleague)
        ->selectRaw("TRIM(CONCAT_WS(' ', first_name, last_name)) as n")->value('n'));

    [$s, $b] = $call('GET', '/talent/dashboard', $meToken);
    $feedText = json_encode($b['data']['activity'] ?? []);
    check('employee opens the dashboard', 200, $s, '(not gated)');
    check("  ... and the feed does not name who resigned", false,
        $colleagueName !== '' && str_contains($feedText, $colleagueName));

    [$s, $b] = $call('GET', '/talent/dashboard', $hrToken);
    $hrFeed = json_encode($b['data']['activity'] ?? []);
    check('HR sees the name', true,
        $colleagueName === '' || str_contains($hrFeed, $colleagueName));

    echo "\n── The half that matters as much: HR STILL WORKS ──\n";

    [$s] = $call('GET', "/performance/reviews/$colleagueReview", $hrToken);
    check("HR opens an employee's review", 200, $s);

    [$s, $b] = $call('PUT', "/performance/reviews/$colleagueReview", $hrToken,
        ['manager_rating' => 5, 'overall_rating' => 4, 'due_date' => '2027-06-30']);
    check('HR writes manager, overall AND due_date', 200, $s);
    check('  ... nothing was ignored', 0, count($b['ignored'] ?? []));
    check('  ... manager_rating persisted', 5.0,
        (float) DB::table('s_performance_reviews')->where('id', $colleagueReview)->value('manager_rating'));

    foreach ([
        ['GET', '/offboarding/cases',        'list exit cases'],
        ['GET', '/mobility/successions',     'read succession slates'],
        ['GET', '/mobility/pools',           'read talent pools'],
        ['GET', '/performance/reviews',      'list all reviews'],
        ['GET', '/competency/certifications', 'list certifications'],
    ] as [$method, $uri, $label]) {
        [$s] = $call($method, $uri, $hrToken);
        check("HR: $label", 200, $s);
    }

    [$s, $b] = $call('GET', '/performance/reviews', $hrToken);
    $hrRows = $b['data'] ?? [];
    check('HR list is NOT narrowed to self', true, count($hrRows) > count($rows));

    echo "\n── S3: learning-assignment targets ──\n";

    $courseId = DB::table('sub_std_map')->value('id');
    if ($courseId && $foreign) {
        [$s, $b] = $call('POST', '/competency/learning-assignments', $hrToken, [
            'course_id' => $courseId,
            'user_ids'  => [$colleague, $foreign, 999999999],
        ]);
        check('HR assigns to 1 valid + 1 foreign + 1 nonexistent', 201, $s);
        check('  ... only the valid one was assigned', 1, $b['data']['assigned'] ?? -1);
        check('  ... the rest are reported, not dropped silently', 2, count($b['rejected'] ?? []));
        check('  ... and no row exists for the foreign id', 0,
            DB::table('lms_assignments')->where('user_id', $foreign)
                ->where('sub_institute_id', TENANT)->where('created_at', '>=', now()->subMinute())->count());
    } else {
        echo "  skipped: need a course and a foreign-tenant user\n";
    }

    echo PHP_EOL . '── Self-verification, and foreign-tenant owners ──' . PHP_EOL;

    // A credential HR holds themselves.
    $ownCert = DB::table('s_competency_certifications')->insertGetId([
        'sub_institute_id' => TENANT, 'name' => MARKER . ' own', 'user_id' => $hr,
        'status' => 'valid', 'verification_status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $otherCert = DB::table('s_competency_certifications')->insertGetId([
        'sub_institute_id' => TENANT, 'name' => MARKER . ' other', 'user_id' => $colleague,
        'status' => 'valid', 'verification_status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    [$s] = $call('PUT', "/competency/certifications/$ownCert", $hrToken, ['verification_status' => 'verified']);
    check('HR verifies their OWN credential', 403, $s, '(cannot verify what you hold)');
    check('  ... and it is still pending', 'pending',
        DB::table('s_competency_certifications')->where('id', $ownCert)->value('verification_status'));

    [$s] = $call('PUT', "/competency/certifications/$otherCert", $hrToken, ['verification_status' => 'verified']);
    check("HR verifies somebody else's credential", 200, $s);
    check('  ... and it is verified', 'verified',
        DB::table('s_competency_certifications')->where('id', $otherCert)->value('verification_status'));

    [$s, $b] = $call('POST', '/competency/certifications/bulk', $hrToken, [
        'action' => 'verify', 'ids' => [$ownCert, $otherCert],
    ]);
    check('bulk verify including their own', 200, $s);
    check('  ... one was skipped and reported', 1, $b['data']['self_skipped'] ?? -1);
    check('  ... their own is STILL pending', 'pending',
        DB::table('s_competency_certifications')->where('id', $ownCert)->value('verification_status'));

    if ($foreign) {
        [$s] = $call('POST', '/competency/certifications', $hrToken, [
            'name' => MARKER . ' foreign', 'user_id_target' => $foreign,
        ]);
        check('HR creates a credential for a FOREIGN tenant user', 404, $s, '(not found, not forbidden)');

        [$s] = $call('POST', '/competency/development-plans', $hrToken, [
            'title' => MARKER, 'user_id_target' => $foreign,
        ]);
        check('HR creates a development plan for a foreign user', 404, $s);

        [$s] = $call('POST', '/competency/assessments', $hrToken, [
            'title' => MARKER, 'user_id' => $foreign,
        ]);
        check('HR creates an assessment for a foreign user', 404, $s);
    }

    echo "\n── The authority itself ──\n";

    check('employee satisfies neither tier', false,
        SubjectAuthority::userSatisfies($me, SubjectAuthority::HR_ELEVATED)
        || SubjectAuthority::userSatisfies($me, SubjectAuthority::PEOPLE_MANAGERS));
    check('HR satisfies both tiers', true,
        SubjectAuthority::userSatisfies($hr, SubjectAuthority::HR_ELEVATED)
        && SubjectAuthority::userSatisfies($hr, SubjectAuthority::PEOPLE_MANAGERS));
    check('PEOPLE_MANAGERS is a superset of HR_ELEVATED', [],
        array_values(array_diff(SubjectAuthority::HR_ELEVATED, SubjectAuthority::PEOPLE_MANAGERS)));
    check('unknown tier name resolves to null', true, SubjectAuthority::tier('nonsense') === null);

    if ($foreign) {
        check('cross-tenant subject is NOT FOUND, not FORBIDDEN',
            SubjectAuthority::NOT_FOUND,
            SubjectAuthority::verdict($hr, (int) $foreign, TENANT, SubjectAuthority::HR_ELEVATED),
            '(no existence probing)');
    }
    check('a colleague is FORBIDDEN for an employee',
        SubjectAuthority::FORBIDDEN,
        SubjectAuthority::verdict($me, (int) $colleague, TENANT, SubjectAuthority::PEOPLE_MANAGERS));
    check('self is always OK',
        SubjectAuthority::OK,
        SubjectAuthority::verdict($me, $me, TENANT, SubjectAuthority::HR_ELEVATED));
} finally {
    DB::rollBack();
    echo "\nrolled back - nothing persisted\n";
    printf("\n%d passed, %d failed\n", $ok, $bad);
}

exit($bad === 0 ? 0 : 1);
