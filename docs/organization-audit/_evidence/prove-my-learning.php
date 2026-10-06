<?php
/**
 * EVIDENCE — My Learning loads for somebody who actually has a course.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE BUG, AND WHY EVERY EXISTING CHECK MISSED IT
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Reported from live: an employee opening My Learning saw "Failed to load your
 * courses". The lesson-count query plucked its key from `course_id`, and
 * `content_master` HAS NO SUCH COLUMN - the course is `subject_id` there. Every
 * row came back with an undefined key, the result collapsed into one entry keyed
 * by the empty string, and:
 *
 *   - `total_content` was 0 for every course, so with a real completed count
 *     beside it the progress bar read 700%;
 *   - and on the live web server `HandleExceptions` promotes that PHP warning to
 *     an ErrorException, which the controller's catch turns into a 500.
 *
 * ── THE SHAPE OF THE MISS IS THE LESSON ─────────────────────────────────────
 *
 * The query sits behind `empty($courseIds) ? collect() : ...`. A learner with no
 * enrolments never reaches it. So a brand-new probe account - which is what a
 * smoke test creates - got a clean HTTP 200 and an empty list, and the endpoint
 * looked healthy. Only somebody with a course in progress ever hit it.
 *
 * That is why section 1 below enrols the learner and gives the course real
 * content BEFORE calling anything. An assertion that does not reach the branch is
 * not an assertion about it.
 *
 * Runs on DEV inside a transaction that is always rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/docs/organization-audit/_evidence/prove-my-learning.php';"
 */

use Illuminate\Support\Facades\DB;

DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

\Illuminate\Support\Facades\Mail::fake();

$tenant = 6;
$correct = 0;
$wrong = 0;

$ok = function (string $m) use (&$correct) { printf("  CORRECT  %s\n", $m); $correct++; };
$bad = function (string $m) use (&$wrong) { printf("  WRONG    %s\n", $m); $wrong++; };

DB::beginTransaction();

try {
    /*
     * ═══════════════════════════════════════════════════════════════════════
     * CLEAR THE ROUTER'S CACHED CONTROLLER BETWEEN REQUESTS
     * ═══════════════════════════════════════════════════════════════════════
     *
     * `Illuminate\Routing\Route::getController()` builds the controller ONCE and
     * keeps it on the Route object, and the RouteCollection lives for the whole
     * process. In production that is harmless - PHP-FPM gives every request its own
     * process, so every request gets a fresh controller.
     *
     * In a script like this one it is not harmless. `ResolvesLmsIdentity` memoises
     * the resolved identity in `$lmsIdentityCache`, a private INSTANCE property. So
     * the second learner's request reuses the first learner's controller, hits that
     * cache, and is answered as the FIRST learner.
     *
     * That is exactly what happened while this file was being written: section 3
     * reported a brand-new account seeing another learner's course, which looks
     * precisely like a data leak and is not one. Worth stating plainly, because the
     * next person to write a two-user evidence script against an LMS controller
     * will see the same thing and have to decide whether to trust it.
     *
     * (The product is safe TODAY, and the reason is only that this application does
     * not run Octane - there is no octane config and it is not in composer.json.
     * Under a long-lived worker that instance cache WOULD be a cross-request leak.)
     */
    $freshController = function () {
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $property = new ReflectionProperty(\Illuminate\Routing\Route::class, 'controller');
            $property->setAccessible(true);
            $property->setValue($route, null);
        }
    };

    $call = function (string $uri, string $token) use ($kernel, $freshController) {
        // What PHP-FPM gives every request for free.
        $freshController();

        $request = \Illuminate\Http\Request::create($uri, 'GET', ['type' => 'API', 'token' => $token]);
        $request->headers->set('Accept', 'application/json');

        $response = $kernel->handle($request);

        return ['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)];
    };

    $profileId = $db->table('tbluserprofilemaster')
        ->where('sub_institute_id', $tenant)->where('role_key', 'employee')->value('id');

    $make = function (string $label) use ($db, $tenant, $profileId) {
        $id = $db->table('tbluser')->insertGetId([
            'user_profile_id' => $profileId,
            'sub_institute_id' => $tenant,
            'first_name' => 'Learner',
            'last_name' => $label,
            'email' => 'learner.' . $label . '@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('LearnerCheck123'),
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'token' => \App\Models\auth\tbluserModel::find($id)->createToken('Learner laptop')->plainTextToken];
    };

    // A course that belongs to this tenant, so the tenant predicate lets it through.
    $courseId = (int) $db->table('sub_std_map')
        ->where('sub_institute_id', $tenant)
        ->whereNull('deleted_at')
        ->value('id');

    if (!$courseId) {
        $bad("tenant $tenant has no course in sub_std_map - nothing below can run");
        DB::rollBack();
        printf("\n%d CORRECT, %d WRONG\n", $correct, $wrong);
        return;
    }

    $lessons = (int) $db->table('content_master')
        ->where('subject_id', $courseId)->whereNull('deleted_at')->count();

    // ── 1. A LEARNER WITH A COURSE — THE BRANCH THAT WAS BROKEN ─────────────
    echo "══ 1. somebody who actually has a course can open My Learning ══\n";

    $learner = $make('enrolled');

    $db->table('lms_course_enroll')->insert([
        'user_id' => $learner['id'],
        'course_id' => $courseId,
        'sub_institute_id' => $tenant,
        'status' => 'in-progress',
        'start_date' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $call('/api/lms/learning/courses', $learner['token']);

    $response['status'] === 200
        ? $ok('the endpoint answers 200')
        : $bad('HTTP ' . $response['status'] . ': ' . json_encode($response['body']));

    /*
     * The server's own words, checked explicitly. On live this came back as
     * `{"status": false, "message": "Failed to load your courses"}` with a 500 -
     * the exact sentence the screen shows - so asserting only on the status code
     * would have missed a body that says the request failed.
     */
    ($response['body']['status'] ?? false) === true
        ? $ok('and the body reports success rather than "Failed to load your courses"')
        : $bad('the body says failure: ' . json_encode($response['body']['message'] ?? null)
            . ' / ' . json_encode($response['body']['error'] ?? null));

    $row = collect($response['body']['data'] ?? [])->firstWhere('id', $courseId);

    $row
        ? $ok('the enrolled course is in the list')
        : $bad('the course is missing from the response');

    // ── 2. THE LESSON COUNT IS THE REAL ONE ─────────────────────────────────
    //
    // The collapsed key made this 0 for every course. Compared against the table
    // rather than against a constant, so it stays true as content is added.
    echo "\n══ 2. the lesson count matches the course's actual content ══\n";

    if ($row) {
        (int) ($row['total_content'] ?? -1) === $lessons
            ? $ok("total_content is $lessons, matching content_master")
            : $bad('total_content is ' . var_export($row['total_content'] ?? null, true) . ", but the course has $lessons lesson(s)");

        /*
         * The visible symptom. 6 completed out of a broken 0 produced 700%, which
         * is the kind of number somebody screenshots. Asserted as a RANGE rather
         * than a value: the point is that it can never again be impossible.
         */
        $percent = (int) ($row['progress_percent'] ?? -1);

        $percent >= 0 && $percent <= 100
            ? $ok("progress_percent is $percent - within 0-100")
            : $bad("progress_percent is $percent, which is not a percentage");
    }

    // ── 3. AND THE EMPTY CASE, WHICH IS WHAT HID THE BUG ────────────────────
    //
    // Kept deliberately. A learner with no enrolments never reaches the query that
    // was broken, so this passing while section 1 failed is exactly what happened -
    // and a future change that fixes the empty case while breaking the enrolled one
    // would look fine here without section 1 beside it.
    echo "\n══ 3. a learner with no courses still gets a clean empty list ══\n";

    $fresh = $make('nocourses');
    $empty = $call('/api/lms/learning/courses', $fresh['token']);

    $empty['status'] === 200 && ($empty['body']['status'] ?? false) === true
        ? $ok('HTTP 200 with status true')
        : $bad('HTTP ' . $empty['status'] . ': ' . json_encode($empty['body']));

    ($empty['body']['data'] ?? null) === []
        ? $ok('and an empty list rather than an error')
        : $bad('unexpected data: ' . json_encode($empty['body']['data'] ?? null));
} finally {
    DB::rollBack();
    echo "\n(rolled back - no account, enrolment or token kept)\n";
}

printf("\n%d CORRECT, %d WRONG\n", $correct, $wrong);
