<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesLmsIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * My Learning - the course player.
 *
 * lms/chapter_master already returns a course's chapters and content, but it is
 * a session-guarded web route that returns everything ungrouped and knows
 * nothing about the learner's position. This assembles the player's view:
 * chapters + content + the caller's own progress, plus notes and assessments.
 *
 * Progress and notes are backed by lms_content_progress / lms_content_notes,
 * created for this page - neither existed before (the previous frontend kept
 * both in browser storage).
 */
class LmsLearningController extends Controller
{
    use ResolvesLmsIdentity;

    /** Profiles allowed to author course content. */
    private const AUTHORING_PROFILES = ['admin', 'hr'];

    private function guardApiToken(Request $request)
    {
        // Was: `if ($request->input('type') !== 'API') return null;` followed by
        // a token check that discarded the token's owner. Omitting `type`
        // skipped authentication entirely. Identity now always comes from the
        // token - see ResolvesLmsIdentity.
        return $this->guardLmsToken($request);
    }

    private function guardAuthoring(Request $request)
    {
        // The profile now comes from the caller's tbluser row, not from
        // a `user_profile_name` they supplied themselves.
        return $this->guardLmsProfile($request, self::AUTHORING_PROFILES, 'Your profile is not permitted to edit course content.');
    }

    private function tenantId(Request $request)
    {
        // The caller's own organisation, from their token - not from whatever
        // sub_institute_id the request asked for.
        return $this->lmsTenantId($request);
    }

    private function requireUser(Request $request)
    {
        return $this->contextUserId($request);
    }

    /*
     * ── SESSIONS COUNT TOWARD A COURSE, BUT ONLY THE ONES A LEARNER IS ON ────
     *
     * A course can have live sessions attached (lms_virtual_classroom rows
     * whose subject_id is the course). Attending one is part of finishing the
     * course, so it belongs in the completion figure alongside the lessons.
     *
     * WHICH sessions count is the whole safety question. Counting every session
     * linked to the course for every learner would put an item in the
     * denominator that a learner who never signed up can never satisfy — and
     * claimCertificate refuses while anything is outstanding, so they would be
     * permanently locked out of a certificate by a session they were never
     * asked to attend. The schema has no "this session is mandatory" flag, so
     * there is no honest way to know whether it was required of them.
     *
     * So a session counts for a learner only once they have a live
     * registration on it. That gives the three cases the behaviour each
     * deserves:
     *
     *   registered + attended  -> numerator and denominator: progress rises.
     *   registered + no-show   -> denominator only: correctly held back, and
     *                             escapable by cancelling the registration.
     *   never registered       -> counted nowhere: the course behaves exactly
     *                             as it did before sessions were counted.
     *
     * Cancelled registrations are excluded throughout: giving up a seat is not
     * attending, and it should not hold a certificate hostage either.
     */

    /** Statuses that mean "this learner is on this session". */
    private const SESSION_ENGAGED = ['registered', 'attended', 'no-show'];

    /**
     * Per course: how many of its sessions this learner is on, and how many of
     * those they attended. Two grouped queries regardless of how many courses.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection}
     *         [course_id => required, course_id => attended]
     */
    private function sessionCounts($userId, array $courseIds, $subInstituteId): array
    {
        if (empty($courseIds)) {
            return [collect(), collect()];
        }

        $base = fn () => DB::table('lms_session_registrations as r')
            ->join('lms_virtual_classroom as v', 'v.id', '=', 'r.session_id')
            ->where('r.user_id', $userId)
            ->whereIn('v.course_id', $courseIds)
            ->whereNull('r.deleted_at')
            ->whereNull('v.deleted_at')
            // The session's own tenant, not the registration's: the session is
            // what owns the course link.
            ->when($subInstituteId, fn ($q) => $q->where('v.sub_institute_id', $subInstituteId));

        $required = $base()
            ->whereIn('r.status', self::SESSION_ENGAGED)
            ->select('v.course_id', DB::raw('COUNT(*) as total'))
            ->groupBy('v.course_id')
            ->pluck('total', 'course_id');

        $attended = $base()
            ->where('r.status', 'attended')
            ->select('v.course_id', DB::raw('COUNT(*) as done'))
            ->groupBy('v.course_id')
            ->pluck('done', 'course_id');

        return [$required, $attended];
    }

    /**
     * Has this learner passed the course's quiz, if it has one?
     *
     * A course with no quiz is open — adding a quiz is how an author asks for
     * the stricter rule, and imposing it on courses that never had one would
     * lock every existing learner out of a certificate they had earned under
     * the old rule.
     *
     * An attempt still `awaiting_review` does not pass: some of its answers are
     * unmarked because a model could not reach a verdict, and issuing a
     * certificate on a score that is not final would be certifying a guess.
     *
     * @return array{passed:bool, has_quiz:bool, reason:?string, best_percent:?float}
     */
    private function quizGate($userId, $courseId, $subInstituteId): array
    {
        $paper = DB::table('question_paper')
            ->where('subject_id', $courseId)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first(['id']);

        if (!$paper) {
            return ['passed' => true, 'has_quiz' => false, 'reason' => null, 'best_percent' => null];
        }

        $attempts = DB::table('lms_quiz_attempt')
            ->where('user_id', $userId)
            ->where('paper_id', $paper->id)
            ->where('sub_institute_id', $subInstituteId)
            ->where('status', 'submitted')
            ->whereNull('deleted_at')
            ->get(['percent', 'passed', 'awaiting_review']);

        $best = $attempts->max('percent');

        if ($attempts->contains(fn ($a) => (bool) $a->passed && (int) $a->awaiting_review === 0)) {
            return [
                'passed' => true,
                'has_quiz' => true,
                'reason' => null,
                'best_percent' => $best !== null ? (float) $best : null,
            ];
        }

        if ($attempts->isEmpty()) {
            $reason = 'Take the course quiz before claiming the certificate.';
        } elseif ($attempts->contains(fn ($a) => (bool) $a->passed)) {
            $reason = 'Some of your answers are still awaiting marking. '
                . 'The certificate will be available once they are marked.';
        } else {
            $reason = 'You have not passed the course quiz yet.';
        }

        return [
            'passed' => false,
            'has_quiz' => true,
            'reason' => $reason,
            'best_percent' => $best !== null ? (float) $best : null,
        ];
    }

    /**
     * The completion figures for ONE course: lessons plus sessions.
     *
     * The single place that defines what "finished" means, so My Learning, the
     * course player, the progress ping, the complete button and the certificate
     * gate cannot drift apart on the arithmetic.
     *
     * @return array{total:int, done:int, content_total:int, content_done:int,
     *               session_total:int, session_done:int}
     */
    private function courseCompletion($userId, $courseId, $subInstituteId): array
    {
        $contentTotal = DB::table('content_master')
            ->where('subject_id', $courseId)
            ->whereNull('deleted_at')
            ->count();

        $contentDone = DB::table('lms_content_progress')
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('status', 'completed')
            ->whereNull('deleted_at')
            ->count();

        [$required, $attended] = $this->sessionCounts($userId, [(int) $courseId], $subInstituteId);

        $sessionTotal = (int) ($required[$courseId] ?? 0);
        $sessionDone = (int) ($attended[$courseId] ?? 0);

        return [
            'total' => $contentTotal + $sessionTotal,
            'done' => $contentDone + $sessionDone,
            'content_total' => $contentTotal,
            'content_done' => $contentDone,
            'session_total' => $sessionTotal,
            'session_done' => $sessionDone,
        ];
    }

    /**
     * GET /api/lms/learning/courses
     *
     * The learner's enrolled courses with a real completion percentage, for the
     * course picker. /api/enrolled_courses returns the enrolments but has no
     * concept of progress, so the percentage is computed here from
     * lms_content_progress against each course's total content.
     */
    public function courses(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $userId = $this->requireUser($request);
        $subInstituteId = $this->tenantId($request);

        if (!$userId) {
            return response()->json(['status' => false, 'message' => 'user_id is required'], 422);
        }

        try {
            // Latest active enrolment per course.
            $latest = DB::table('lms_course_enroll')
                ->select('course_id', DB::raw('MAX(created_at) as latest_enrolled_at'))
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->groupBy('course_id');

            /*
             * ── THE TENANT PREDICATE GOES ON THE COURSE, NOT THE ENROLMENT ──
             *
             * This query had no tenant filter at all, while `course()` below
             * has always had one - so a user whose enrolment rows spanned
             * organisations saw another organisation's courses in their own
             * list.
             *
             * It is filtered on `s.sub_institute_id` deliberately.
             * `sub_std_map.sub_institute_id` is NOT NULL and is the authority
             * on which organisation owns a course, and you cannot enrol in a
             * course you cannot see. `lms_course_enroll.sub_institute_id` is
             * NULLABLE, so filtering on it would have silently emptied the
             * course list of every learner whose row predates that column
             * being populated. Measured before writing this: 0 NULL and 0 zero
             * on both dev and live, so the enrolment-side predicate below is
             * safe today - it is the belt to the course-side braces, and it is
             * ordered second so that if either is ever wrong, the course-side
             * one still holds.
             */
            $courses = DB::table('lms_course_enroll as e')
                ->join('sub_std_map as s', 'e.course_id', '=', 's.id')
                ->leftJoin('hrms_departments as d', 'd.id', '=', 's.standard_id')
                ->joinSub($latest, 'latest', function ($join) {
                    $join->on('e.course_id', '=', 'latest.course_id')
                         ->on('e.created_at', '=', 'latest.latest_enrolled_at');
                })
                ->where('e.user_id', $userId)
                ->where('s.sub_institute_id', $subInstituteId)
                ->where('e.sub_institute_id', $subInstituteId)
                ->whereNull('e.deleted_at')
                ->whereNull('s.deleted_at')
                ->select(
                    's.id',
                    's.display_name',
                    's.display_image',
                    's.subject_category',
                    's.subject_type',
                    's.standard_id',
                    'd.department as standard_name',
                    'e.id as enrollment_id',
                    'e.status as enrollment_status',
                    'e.start_date',
                    'e.end_date'
                )
                ->get();

            $courseIds = $courses->pluck('id')->all();

            // Total content and completed content per course, in two queries
            // rather than one per course.
            $totals = empty($courseIds) ? collect() : DB::table('content_master')
                ->whereIn('subject_id', $courseIds)
                ->whereNull('deleted_at')
                ->select('subject_id', DB::raw('COUNT(*) as total'))
                ->groupBy('subject_id')
                ->pluck('total', 'course_id');

            $completed = empty($courseIds) ? collect() : DB::table('lms_content_progress')
                ->where('user_id', $userId)
                ->where('status', 'completed')
                ->whereNull('deleted_at')
                ->whereIn('course_id', $courseIds)
                ->select('course_id', DB::raw('COUNT(*) as done'))
                ->groupBy('course_id')
                ->pluck('done', 'course_id');

            // Sessions the learner is on count alongside the lessons - see
            // sessionCounts() for why only the ones they registered for.
            [$sessionsRequired, $sessionsAttended] =
                $this->sessionCounts($userId, $courseIds, $subInstituteId);

            $courses->transform(function ($course) use (
                $totals, $completed, $sessionsRequired, $sessionsAttended
            ) {
                $sessionTotal = (int) ($sessionsRequired[$course->id] ?? 0);
                $sessionDone = (int) ($sessionsAttended[$course->id] ?? 0);

                // total_content stays LESSONS ONLY - every screen labels it
                // "lessons", and folding sessions into it would make that label
                // a lie. The sessions are their own pair of counts, and only
                // the percentage combines the two.
                $course->total_content = (int) ($totals[$course->id] ?? 0);
                $course->completed_content = (int) ($completed[$course->id] ?? 0);
                $course->total_sessions = $sessionTotal;
                $course->attended_sessions = $sessionDone;

                $total = $course->total_content + $sessionTotal;
                $done = $course->completed_content + $sessionDone;

                $course->progress_percent = $total > 0 ? (int) round($done / $total * 100) : 0;

                return $course;
            });

            /*
             * ── AWAITING APPROVAL IS NOT THE SAME AS ENROLLED ──────────────
             *
             * A self-requested enrolment sits at `pending` until an admin
             * reviews it. This query had no status filter, so a pending
             * enrolment was returned alongside approved ones and was fully
             * learnable - which made the approval step decorative, since the
             * learner could simply start the course while waiting.
             *
             * They are separated rather than hidden. The learner does own that
             * enrolment and is entitled to see that they asked for it; what
             * they may not do is start it. Dropping it from the response
             * entirely would look like the request had been lost.
             */
            $awaiting = $courses->where('enrollment_status', 'pending')->values();
            $learnable = $courses->whereNotIn('enrollment_status', ['pending'])->values();

            return response()->json([
                'status' => true,
                'data' => $learnable,
                'awaiting_approval' => $awaiting,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to load your courses',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/lms/learning/courses/{courseId}
     *
     * Everything the player needs in one call: the course, its chapters, each
     * chapter's content, and the caller's progress on each item.
     */
    public function course(Request $request, $courseId)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $userId = $this->requireUser($request);
        $subInstituteId = $this->tenantId($request);

        if (!$userId) {
            return response()->json(['status' => false, 'message' => 'user_id is required'], 422);
        }

        try {
            $course = DB::table('sub_std_map as s')
                ->leftJoin('hrms_departments as d', 'd.id', '=', 's.standard_id')
                ->where('s.id', $courseId)
                ->when($subInstituteId, fn ($q) => $q->where('s.sub_institute_id', $subInstituteId))
                ->whereNull('s.deleted_at')
                ->select('s.*', 'd.department as standard_name')
                ->first();

            if (!$course) {
                return response()->json(['status' => false, 'message' => 'Course not found'], 404);
            }

            $enrollment = DB::table('lms_course_enroll')
                ->where('user_id', $userId)
                ->where('course_id', $courseId)
                ->whereNull('deleted_at')
                ->orderByDesc('created_at')
                ->first();

            // chapter_master.subject_id references sub_std_map.id.
            $chapters = DB::table('chapter_master')
                ->where('subject_id', $courseId)
                ->whereNull('deleted_at')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'chapter_name', 'chapter_desc', 'standard_id', 'subject_id', 'sort_order', 'show_hide']);

            $content = DB::table('content_master')
                ->where('subject_id', $courseId)
                ->whereNull('deleted_at')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get([
                    'id', 'chapter_id', 'title', 'description', 'filename', 'file_type',
                    'file_size', 'url', 'content_category', 'sort_order', 'show_hide',
                ]);

            $progress = DB::table('lms_content_progress')
                ->where('user_id', $userId)
                ->where('course_id', $courseId)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('content_id');

            /*
             * ── SEQUENTIAL LOCKING IS NOW OPT-IN, AND OFF BY DEFAULT ────────
             *
             * The rule was unconditional: a lesson opened only once every
             * lesson before it was complete. That is a defensible rule for
             * compliance training and the wrong default for everything else -
             * and with 2 progress rows across 1,454 enrolments on live, its
             * real effect was that EVERY course in the product showed lesson
             * one open and every other lesson locked. It is a large part of
             * why "the employee cannot start a course and learn".
             *
             * It is a per-course setting now, defaulting to 0, so a course
             * that genuinely must be taken in order can still say so. Still
             * computed rather than stored, so changing the rule never needs a
             * backfill; and a course with no settings row - which is every
             * course on live today - takes the unlocked path.
             */
            $sequential = (bool) DB::table('lms_course_settings')
                ->where('course_id', $courseId)
                ->where('sub_institute_id', $subInstituteId)
                ->value('sequential_unlock');

            $byChapter = [];
            $previousComplete = true;

            foreach ($content as $item) {
                $row = $progress[$item->id] ?? null;

                $item->status = $row->status ?? 'not-started';
                $item->last_position_seconds = $row->last_position_seconds ?? null;
                $item->time_spent_seconds = (int) ($row->time_spent_seconds ?? 0);
                $item->completed_at = $row->completed_at ?? null;

                // Already-touched lessons never re-lock.
                // Already-touched lessons never re-lock, even when sequential
                // unlocking is on.
                $item->is_locked = $sequential
                    && !$previousComplete
                    && $item->status === 'not-started';
                $previousComplete = $item->status === 'completed';

                $byChapter[$item->chapter_id][] = $item;
            }

            $totalContent = $content->count();
            $completedContent = $content->filter(fn ($i) => $i->status === 'completed')->count();

            $chapters->transform(function ($chapter) use ($byChapter) {
                $items = $byChapter[$chapter->id] ?? [];
                $chapter->content = array_values($items);
                $chapter->total_content = count($items);
                $chapter->completed_content = count(
                    array_filter($items, fn ($i) => $i->status === 'completed')
                );
                return $chapter;
            });

            // Sessions this learner is on count toward the course as well, so
            // the player's percentage agrees with My Learning and with the
            // certificate gate. The lesson counts stay separate underneath -
            // the chapter list is lessons only, and mixing them there would
            // make the per-chapter figures wrong.
            [$sessionsRequired, $sessionsAttended] =
                $this->sessionCounts($userId, [(int) $courseId], $subInstituteId);

            $sessionTotal = (int) ($sessionsRequired[$courseId] ?? 0);
            $sessionDone = (int) ($sessionsAttended[$courseId] ?? 0);

            $overallTotal = $totalContent + $sessionTotal;
            $overallDone = $completedContent + $sessionDone;

            return response()->json([
                'status' => true,
                'data' => [
                    'course' => $course,
                    'enrollment' => $enrollment,
                    'chapters' => $chapters,
                    'total_content' => $totalContent,
                    'completed_content' => $completedContent,
                    'total_sessions' => $sessionTotal,
                    'attended_sessions' => $sessionDone,
                    'progress_percent' => $overallTotal > 0
                        ? (int) round($overallDone / $overallTotal * 100)
                        : 0,
                    'time_spent_seconds' => (int) $progress->sum('time_spent_seconds'),
                    /*
                     * THE MANAGED CATALOGUE, PLUS WHATEVER IS ALREADY IN USE.
                     *
                     * This returned only the distinct values already present on
                     * this course's lessons, so the authoring datalist could
                     * offer nothing on a course with no lessons yet - the first
                     * lesson anybody added always got a typed category, and
                     * `lms_content_category` (Technical, Functional, Soft
                     * Skills, ...) was a managed list nothing read.
                     *
                     * Merged rather than replaced: the in-use values are real
                     * even when somebody typed them, and dropping them would
                     * make existing lessons look mis-categorised.
                     */
                    'content_categories' => DB::table('lms_content_category')
                        ->where(function ($q) use ($subInstituteId) {
                            // sub_institute_id 0 is the shared seed list.
                            $q->where('sub_institute_id', 0)
                              ->orWhere('sub_institute_id', $subInstituteId);
                        })
                        ->where('status', 1)
                        ->whereNull('deleted_at')
                        ->orderBy('sort_order')
                        ->pluck('category_name')
                        ->concat($content->pluck('content_category'))
                        ->filter()
                        ->unique()
                        ->values(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to load the course',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/lms/learning/progress
     *
     * Upsert the caller's progress on one content item. Called when a lesson is
     * opened, periodically while media plays, and when it is completed.
     */
    public function saveProgress(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $userId = $this->requireUser($request);
        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make(
            array_merge($request->all(), ['user_id' => $userId]),
            [
                'user_id'               => 'required|integer',
                'course_id'             => 'required|integer',
                'content_id'            => 'required|integer',
                'chapter_id'            => 'nullable|integer',
                'status'                => 'required|in:not-started,in-progress,completed',
                'last_position_seconds' => 'nullable|integer|min:0',
                'time_spent_delta'      => 'nullable|integer|min:0|max:86400',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        // The content item must really belong to the course being reported.
        $belongs = DB::table('content_master')
            ->where('id', $request->content_id)
            ->where('subject_id', $request->course_id)
            ->whereNull('deleted_at')
            ->exists();

        if (!$belongs) {
            return response()->json([
                'status' => false,
                'message' => 'That content item does not belong to this course.',
            ], 422);
        }

        try {
            $existing = DB::table('lms_content_progress')
                ->where('user_id', $userId)
                ->where('content_id', $request->content_id)
                ->first();

            $status = $request->status;
            // Completion is not undone by a later "in-progress" ping from a
            // replay - only an explicit not-started reset clears it.
            if ($existing && $existing->status === 'completed' && $status === 'in-progress') {
                $status = 'completed';
            }

            $payload = [
                'status' => $status,
                'chapter_id' => $request->chapter_id,
                'last_position_seconds' => $request->last_position_seconds,
                'time_spent_seconds' => (int) ($existing->time_spent_seconds ?? 0)
                    + (int) $request->input('time_spent_delta', 0),
                'completed_at' => $status === 'completed'
                    ? ($existing->completed_at ?? now())
                    : null,
                'sub_institute_id' => $this->tenantId($request),
                'updated_by' => $userId,
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('lms_content_progress')->where('id', $existing->id)->update($payload);
            } else {
                DB::table('lms_content_progress')->insert($payload + [
                    'user_id' => $userId,
                    'course_id' => $request->course_id,
                    'content_id' => $request->content_id,
                    'created_by' => $userId,
                    'created_at' => now(),
                ]);
            }

            // Recompute the course percentage so the client does not have to.
            // Lessons plus the sessions this learner is on - one definition of
            // "finished", shared with the certificate gate.
            $completion = $this->courseCompletion($userId, $request->course_id, $subInstituteId);
            $total = $completion['total'];
            $done = $completion['done'];

            $percent = $total > 0 ? (int) round($done / $total * 100) : 0;

            /*
             * ── OPENING A LESSON MEANS THE COURSE IS IN PROGRESS ────────────
             *
             * This percentage was computed, returned, and then forgotten.
             * `lms_course_enroll.status` stayed 'enrolled' forever - the only
             * thing that ever set 'completed' was claiming a certificate - so
             * a course somebody was halfway through was indistinguishable
             * from one they had never opened, and the UI showed both as
             * "In Progress" alongside courses awaiting approval.
             *
             * Only from 'enrolled'. A completed course is not un-completed by
             * revisiting a lesson, and a pending one must not quietly become
             * active without an approval.
             */
            DB::table('lms_course_enroll')
                ->where('user_id', $userId)
                ->where('course_id', $request->course_id)
                ->where('status', 'enrolled')
                ->whereNull('deleted_at')
                ->update(['status' => 'in-progress', 'updated_at' => now()]);

            /*
             * ── AND THE ASSIGNMENT HAS TO AGREE WITH MY LEARNING ────────────
             *
             * `lms_assignments` appeared nowhere in this controller, so its
             * `progress` column was only ever the literal 0 or 100 somebody
             * set by hand. The Assignments screen therefore showed 0% for a
             * learner My Learning showed at 80% - two screens, one fact, two
             * answers.
             */
            DB::table('lms_assignments')
                ->where('user_id', $userId)
                ->where('course_id', $request->course_id)
                ->whereNull('deleted_at')
                ->update([
                    'progress' => $percent,
                    'status' => $percent >= 100 ? 'Completed' : 'In Progress',
                    'updated_at' => now(),
                ]);

            return response()->json([
                'status' => true,
                'message' => 'Progress saved',
                'data' => [
                    'content_id' => (int) $request->content_id,
                    'status' => $status,
                    'total_content' => $completion['content_total'],
                    'completed_content' => $completion['content_done'],
                    'total_sessions' => $completion['session_total'],
                    'attended_sessions' => $completion['session_done'],
                    'progress_percent' => $percent,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to save progress',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/lms/learning/assessments
     *
     * A course's question papers with the caller's own attempts.
     *
     * question_paper already carries standard_id/subject_id (58 of 61 rows join
     * cleanly to sub_std_map), so no schema change was needed - the link simply
     * had no endpoint exposing it. Attempts come from lms_online_exam, which
     * keys on question_paper_id.
     */
    public function assessments(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $userId = $this->requireUser($request);
        $courseId = $request->input('course_id');

        if (!$userId || !$courseId) {
            return response()->json([
                'status' => false,
                'message' => 'user_id and course_id are required',
            ], 422);
        }

        try {
            $papers = DB::table('question_paper')
                ->where('subject_id', $courseId)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get([
                    'id', 'paper_name', 'paper_desc', 'total_ques', 'total_marks',
                    'time_allowed', 'timelimit_enable', 'attempt_allowed',
                    'open_date', 'close_date', 'exam_type', 'show_hide',
                ]);

            $paperIds = $papers->pluck('id')->all();

            /*
             * ── ONE ATTEMPT HISTORY, FROM BOTH PLACES IT LIVES ──────────────
             *
             * Attempts are recorded in two tables and this read saw only one of
             * them:
             *
             *   lms_online_exam   the legacy mobile/Blade exam path (64 rows on
             *                     live) - real history that must not vanish
             *   lms_quiz_attempt  the course quiz, which is where every new
             *                     attempt goes
             *
             * So a learner who sat the course quiz saw "0 taken" on this tab
             * while the quiz panel beside it showed their score. Two screens,
             * one fact, two answers - and the tab's answer was the wrong one,
             * because it was reading a table the current quiz never writes.
             *
             * Merged and normalised to one shape rather than picking a winner:
             * `source` says which path an attempt came from, so an old result
             * is still explicable.
             */
            $legacy = empty($paperIds) ? collect() : DB::table('lms_online_exam')
                ->where('employee_id', $userId)
                ->whereIn('question_paper_id', $paperIds)
                ->whereNull('deleted_at')
                ->get(['id', 'question_paper_id', 'total_right', 'total_wrong', 'obtain_marks', 'created_at'])
                ->map(fn ($row) => (object) [
                    'id' => (int) $row->id,
                    'question_paper_id' => (int) $row->question_paper_id,
                    'source' => 'legacy',
                    'total_right' => (int) $row->total_right,
                    'total_wrong' => (int) $row->total_wrong,
                    'obtain_marks' => $row->obtain_marks,
                    'percent' => null,
                    'passed' => null,
                    'created_at' => $row->created_at,
                ]);

            $quiz = empty($paperIds) ? collect() : DB::table('lms_quiz_attempt')
                ->where('user_id', $userId)
                ->whereIn('paper_id', $paperIds)
                ->where('status', 'submitted')
                ->whereNull('deleted_at')
                ->get(['id', 'paper_id', 'score', 'max_score', 'percent', 'passed', 'questions', 'submitted_at', 'created_at'])
                ->map(fn ($row) => (object) [
                    'id' => (int) $row->id,
                    'question_paper_id' => (int) $row->paper_id,
                    'source' => 'quiz',
                    // The quiz records a score, not a right/wrong split; the
                    // tab renders both, so derive what can be derived and leave
                    // the rest null rather than inventing it.
                    'total_right' => null,
                    'total_wrong' => null,
                    'obtain_marks' => $row->score,
                    'percent' => $row->percent === null ? null : (float) $row->percent,
                    'passed' => $row->passed === null ? null : (bool) $row->passed,
                    'created_at' => $row->submitted_at ?: $row->created_at,
                ]);

            $attempts = $legacy->concat($quiz)
                ->sortByDesc('created_at')
                ->groupBy('question_paper_id');

            $papers->transform(function ($paper) use ($attempts) {
                $mine = ($attempts[$paper->id] ?? collect())->values();

                $paper->attempts = $mine;
                $paper->attempt_count = $mine->count();
                $paper->best_score = $mine->max('obtain_marks');
                $paper->best_percent = $mine->whereNotNull('percent')->max('percent');
                $paper->passed = $mine->contains(fn ($a) => $a->passed === true);
                $paper->last_attempt_at = $mine->first()->created_at ?? null;
                $paper->status = $mine->isEmpty() ? 'not-started' : 'completed';

                return $paper;
            });

            return response()->json(['status' => true, 'data' => $papers]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to load assessments',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /* ------------------------------------------------------------------ *
     * Notes - private to their author.
     * ------------------------------------------------------------------ */

    public function notes(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $userId = $this->requireUser($request);
        if (!$userId) {
            return response()->json(['status' => false, 'message' => 'user_id is required'], 422);
        }

        $notes = DB::table('lms_content_notes as n')
            ->leftJoin('content_master as c', 'c.id', '=', 'n.content_id')
            ->where('n.user_id', $userId)
            ->when($request->input('course_id'), fn ($q, $id) => $q->where('n.course_id', $id))
            ->whereNull('n.deleted_at')
            ->orderByDesc('n.id')
            ->get([
                'n.id', 'n.course_id', 'n.chapter_id', 'n.content_id', 'n.note',
                'n.timestamp_seconds', 'n.created_at', 'n.updated_at',
                'c.title as content_title',
            ]);

        return response()->json(['status' => true, 'data' => $notes]);
    }

    public function storeNote(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $userId = $this->requireUser($request);

        $validator = Validator::make(
            array_merge($request->all(), ['user_id' => $userId]),
            [
                'user_id'           => 'required|integer',
                'course_id'         => 'required|integer',
                'chapter_id'        => 'nullable|integer',
                'content_id'        => 'nullable|integer',
                'note'              => 'required|string|max:5000',
                'timestamp_seconds' => 'nullable|integer|min:0',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $id = DB::table('lms_content_notes')->insertGetId([
            'user_id' => $userId,
            'course_id' => $request->course_id,
            'chapter_id' => $request->chapter_id,
            'content_id' => $request->content_id,
            'note' => $request->note,
            'timestamp_seconds' => $request->timestamp_seconds,
            'sub_institute_id' => $this->tenantId($request),
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Note saved',
            'data' => DB::table('lms_content_notes')->find($id),
        ], 201);
    }

    public function updateNote(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $userId = $this->requireUser($request);

        $validator = Validator::make(
            array_merge($request->all(), ['user_id' => $userId]),
            [
                'user_id' => 'required|integer',
                'note'    => 'required|string|max:5000',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        // Scoped to the author - a note is private, so another user's id must
        // never match.
        $note = DB::table('lms_content_notes')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->first();

        if (!$note) {
            return response()->json(['status' => false, 'message' => 'Note not found'], 404);
        }

        DB::table('lms_content_notes')->where('id', $id)->update([
            'note' => $request->note,
            'timestamp_seconds' => $request->input('timestamp_seconds', $note->timestamp_seconds),
            'updated_by' => $userId,
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Note updated',
            'data' => DB::table('lms_content_notes')->find($id),
        ]);
    }

    public function destroyNote(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $userId = $this->requireUser($request);
        if (!$userId) {
            return response()->json(['status' => false, 'message' => 'user_id is required'], 422);
        }

        $note = DB::table('lms_content_notes')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->first();

        if (!$note) {
            return response()->json(['status' => false, 'message' => 'Note not found'], 404);
        }

        DB::table('lms_content_notes')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $userId,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Note deleted',
            'data' => ['id' => (int) $id],
        ]);
    }

    /* ------------------------------------------------------------------ *
     * Chapter / content authoring (admin + HR).
     *
     * lms/chapter_master and lms/content_master accept writes, but they are web
     * routes: a cross-origin POST is rejected by Laravel's CSRF guard with 419
     * (confirmed while integrating the catalog). These API equivalents apply
     * the same tenancy rules over a usable transport.
     * ------------------------------------------------------------------ */

    public function storeChapter(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAuthoring($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make(
            array_merge($request->all(), ['sub_institute_id' => $subInstituteId]),
            [
                'sub_institute_id' => 'required|integer',
                'subject_id'       => 'required|integer',
                'chapter_name'     => 'required|string|max:191',
                'chapter_desc'     => 'nullable|string',
                'sort_order'       => 'nullable|integer',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $course = DB::table('sub_std_map')
            ->where('id', $request->subject_id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$course) {
            return response()->json(['status' => false, 'message' => 'Course not found'], 404);
        }

        $id = DB::table('chapter_master')->insertGetId([
            'subject_id' => $request->subject_id,
            'standard_id' => $course->standard_id,
            'chapter_name' => $request->chapter_name,
            'chapter_desc' => $request->chapter_desc,
            'sort_order' => $request->input('sort_order', 1),
            'show_hide' => 1,
            'sub_institute_id' => $subInstituteId,
            'created_by' => $this->requireUser($request),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Chapter created',
            'data' => DB::table('chapter_master')->find($id),
        ], 201);
    }

    public function updateChapter(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAuthoring($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make(
            array_merge($request->all(), ['sub_institute_id' => $subInstituteId]),
            [
                'sub_institute_id' => 'required|integer',
                'chapter_name'     => 'required|string|max:191',
                'chapter_desc'     => 'nullable|string',
                'sort_order'       => 'nullable|integer',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $chapter = DB::table('chapter_master')
            ->where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$chapter) {
            return response()->json(['status' => false, 'message' => 'Chapter not found'], 404);
        }

        DB::table('chapter_master')->where('id', $id)->update([
            'chapter_name' => $request->chapter_name,
            'chapter_desc' => $request->chapter_desc,
            'sort_order' => $request->input('sort_order', $chapter->sort_order),
            'updated_by' => $this->requireUser($request),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Chapter updated',
            'data' => DB::table('chapter_master')->find($id),
        ]);
    }

    public function destroyChapter(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAuthoring($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $chapter = DB::table('chapter_master')
            ->where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$chapter) {
            return response()->json(['status' => false, 'message' => 'Chapter not found'], 404);
        }

        $userId = $this->requireUser($request);
        $now = now();

        // Content belongs to its chapter, so it goes with it.
        DB::table('chapter_master')->where('id', $id)
            ->update(['deleted_at' => $now, 'deleted_by' => $userId]);
        DB::table('content_master')->where('chapter_id', $id)->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'deleted_by' => $userId]);

        return response()->json([
            'status' => true,
            'message' => 'Chapter deleted',
            'data' => ['id' => (int) $id],
        ]);
    }

    /**
     * POST /api/lms/learning/content/upload
     *
     * Put a lesson file somewhere the player can open it.
     *
     * ── WHY A LESSON COULD ONLY EVER BE A LINK ──────────────────────────────
     *
     * Both authoring surfaces asked for a URL and nothing else: the Course
     * Builder's media field is a bare text input, and course-authoring.tsx says
     * out loud "Paste the file or video URL. Uploads are handled by the content
     * library" - a content library that does not exist anywhere in this
     * codebase.
     *
     * So an author with a PDF on their laptop had no way to make it a lesson.
     * They had to publish it somewhere public first and paste the address, and
     * anything behind a login rendered as a broken frame for every learner.
     *
     * ── WHAT THE PLAYER ALREADY SUPPORTS ────────────────────────────────────
     *
     * Nothing new is needed on the learner side. `lessonKind()` already maps
     * mp4/pdf/jpg to native viewers and ppt/pptx/doc/docx/xls/xlsx through the
     * Office viewer, and falls back to the file extension when file_type is
     * blank. An uploaded file gets a public URL on the same DigitalOcean space
     * the course thumbnails and organisation logos already use, so it plays
     * exactly like a pasted link - which is the point.
     *
     * ── THE OFFICE VIEWER NEEDS A PUBLIC URL ────────────────────────────────
     *
     * view.officeapps.live.com fetches the file itself, so a private object
     * would render as an error inside the course. Uploads are written with
     * 'public' visibility for that reason, and the response says so rather than
     * leaving the author to discover it.
     */
    public function uploadContent(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAuthoring($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make($request->all(), [
            /*
             * The formats the player can actually render, and nothing else.
             *
             * Accepting a .zip or a .exe would put a file in the course that no
             * learner can open, and the failure would surface as a blank lesson
             * rather than a refused upload.
             */
            'file' => 'required|file|max:102400|mimes:mp4,webm,mov,pdf,ppt,pptx,doc,docx,xls,xlsx,jpg,jpeg,png,gif,webp',
            'course_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        // A course id is optional (the builder uploads before the lesson row
        // exists) but when given it must be the caller's own.
        if ($request->filled('course_id')) {
            $ours = DB::table('sub_std_map')
                ->where('id', $request->course_id)
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->exists();

            if (!$ours) {
                return response()->json(['status' => false, 'message' => 'Course not found'], 404);
            }
        }

        try {
            $file = $request->file('file');
            $extension = strtolower($file->getClientOriginalExtension());

            /*
             * Tenant-partitioned, timestamped, and NOT the original filename.
             *
             * Two authors uploading "training.pdf" must not overwrite each
             * other, and a filename from a browser is attacker-controlled text
             * that should never become a path segment.
             */
            $name = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
            $path = 'public/lms_content/' . $subInstituteId . '/' . $name;

            \Illuminate\Support\Facades\Storage::disk('digitalocean')
                ->putFileAs(dirname($path), $file, basename($path), 'public');

            $url = \Illuminate\Support\Facades\Storage::disk('digitalocean')->url($path);

            return response()->json([
                'status' => true,
                'message' => 'File uploaded',
                'data' => [
                    'url' => $url,
                    // The player switches on this, so it is derived from the
                    // real extension rather than trusted from the client.
                    'file_type' => $this->lessonTypeFor($extension),
                    'filename' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to upload the file',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * The `file_type` the player understands, from a real file extension.
     *
     * Deliberately the SAME vocabulary as lessonKind() on the client - mp4,
     * pdf, pptx, docx, jpg, link. A value outside that set renders as "unknown"
     * and the lesson cannot be opened, which is how three of the four original
     * Course Builder content types used to behave.
     */
    private function lessonTypeFor(string $extension): string
    {
        return match ($extension) {
            'mp4', 'webm', 'mov' => 'mp4',
            'pdf' => 'pdf',
            'ppt', 'pptx' => 'pptx',
            'doc', 'docx' => 'docx',
            'xls', 'xlsx' => 'docx',
            'jpg', 'jpeg', 'png', 'gif', 'webp' => 'jpg',
            default => 'link',
        };
    }

    public function storeContent(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAuthoring($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make(
            array_merge($request->all(), ['sub_institute_id' => $subInstituteId]),
            [
                'sub_institute_id' => 'required|integer',
                'chapter_id'       => 'required|integer',
                'title'            => 'required|string|max:191',
                'description'      => 'nullable|string',
                'filename'         => 'nullable|string',
                // Accepted because a caller sending it must not be silently
                // ignored - which is exactly what happened to the Course
                // Builder: it posted `url`, nothing read it, and every lesson
                // it made was stored with no media at all.
                'url'              => 'nullable|string|max:1000',
                'file_type'        => 'nullable|string|max:191',
                'content_category' => 'nullable|string|max:191',
                'sort_order'       => 'nullable|integer',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $chapter = DB::table('chapter_master')
            ->where('id', $request->chapter_id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$chapter) {
            return response()->json(['status' => false, 'message' => 'Chapter not found'], 404);
        }

        $id = DB::table('content_master')->insertGetId([
            'chapter_id' => $chapter->id,
            'subject_id' => $chapter->subject_id,
            'standard_id' => $chapter->standard_id,
            'title' => $request->title,
            'description' => $request->description,
            /*
             * `filename` is the canonical media column - it is what the player
             * reads first (`lesson.filename || lesson.url`). `url` is accepted
             * as an alternate and written to its own column so a caller that
             * sends either gets a playable lesson.
             */
            'filename' => $request->input('filename') ?: $request->input('url'),
            'url' => $request->input('url'),
            'file_type' => $request->file_type,
            'content_category' => $request->input('content_category', 'Videos'),
            'sort_order' => $request->input('sort_order', 1),
            'show_hide' => 1,
            'sub_institute_id' => $subInstituteId,
            'created_by' => $this->requireUser($request),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Content created',
            'data' => DB::table('content_master')->find($id),
        ], 201);
    }

    public function updateContent(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAuthoring($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make(
            array_merge($request->all(), ['sub_institute_id' => $subInstituteId]),
            [
                'sub_institute_id' => 'required|integer',
                'title'            => 'required|string|max:191',
                'description'      => 'nullable|string',
                'filename'         => 'nullable|string',
                'url'              => 'nullable|string|max:1000',
                'file_type'        => 'nullable|string|max:191',
                'content_category' => 'nullable|string|max:191',
                'sort_order'       => 'nullable|integer',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $content = DB::table('content_master')
            ->where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$content) {
            return response()->json(['status' => false, 'message' => 'Content not found'], 404);
        }

        DB::table('content_master')->where('id', $id)->update([
            'title' => $request->title,
            'description' => $request->description,
            // Either key sets the media, and neither key leaves it alone -
            // an edit that changes only the title must not blank the video.
            'filename' => $request->input('filename')
                ?: $request->input('url')
                ?: $content->filename,
            'url' => $request->input('url', $content->url),
            'file_type' => $request->input('file_type', $content->file_type),
            'content_category' => $request->input('content_category', $content->content_category),
            'sort_order' => $request->input('sort_order', $content->sort_order),
            'updated_by' => $this->requireUser($request),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Content updated',
            'data' => DB::table('content_master')->find($id),
        ]);
    }

    public function destroyContent(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAuthoring($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $content = DB::table('content_master')
            ->where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$content) {
            return response()->json(['status' => false, 'message' => 'Content not found'], 404);
        }

        DB::table('content_master')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $this->requireUser($request),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Content deleted',
            'data' => ['id' => (int) $id],
        ]);
    }

    /* ------------------------------------------------------------------ *
     * Certificates.
     * ------------------------------------------------------------------ */

    /**
     * The skill a course teaches, or null.
     *
     * sub_std_map.subject_id is polymorphic - it points at a task, jobrole or
     * skill row depending on subject_category. Only the skill-flavoured
     * categories resolve against s_users_skills; 'Task', 'jobrole', 'course'
     * and 'sub' address entirely different tables and must not be followed here.
     */
    private function resolveCourseSkillId($course): ?int
    {
        $entityCategories = ['task', 'jobrole', 'course', 'sub'];
        $category = strtolower(trim((string) ($course->subject_category ?? '')));

        if ($category === '' || in_array($category, $entityCategories, true)) {
            return null;
        }

        if (!$course->subject_id) {
            return null;
        }

        $exists = DB::table('s_users_skills')
            ->where('id', $course->subject_id)
            ->whereNull('deleted_at')
            ->exists();

        return $exists ? (int) $course->subject_id : null;
    }

    /**
     * GET /api/lms/learning/certificates
     *
     * Certificates with a derived `expiry_state`, so nothing has to
     * re-implement the date maths.
     *
     * Scope follows the caller: admin/HR see every learner in the tenant (the
     * Certifications & Records screen), everyone else sees only their own.
     * Pass scope=mine to force the personal view even as an admin - that is
     * what the learner-facing dashboard and player use.
     */
    public function certificates(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $userId = $this->requireUser($request);
        if (!$userId) {
            return response()->json(['status' => false, 'message' => 'user_id is required'], 422);
        }

        $subInstituteId = $this->tenantId($request);
        $wantsAll = $request->input('scope') === 'all' && $this->guardAuthoring($request) === null;

        $now = now();
        $soon = $now->copy()->addDays($this->certificateWarningDays());

        $certificates = DB::table('lms_certificates as c')
            ->leftJoin('sub_std_map as s', 's.id', '=', 'c.course_id')
            ->leftJoin('s_users_skills as k', 'k.id', '=', 'c.skill_id')
            ->leftJoin('tbluser as u', 'u.id', '=', 'c.user_id')
            // Org-wide is tenant-scoped; personal is user-scoped.
            ->when(
                $wantsAll,
                fn ($q) => $q->where('c.sub_institute_id', $subInstituteId),
                fn ($q) => $q->where('c.user_id', $userId)
            )
            ->when($request->input('course_id'), fn ($q, $id) => $q->where('c.course_id', $id))
            /*
             * ONE EMPLOYEE'S CERTIFICATES, for an administrator looking at
             * their record.
             *
             * Only meaningful alongside scope=all, which is already gated on
             * guardAuthoring - so this narrows a set the caller was entitled to
             * see rather than widening one they were not. Without it, showing
             * certificates on an employee's profile would mean fetching every
             * certificate in the organisation and filtering in the browser.
             */
            ->when(
                $wantsAll && $request->input('user_id'),
                fn ($q) => $q->where('c.user_id', $request->input('user_id'))
            )
            ->when($request->input('search'), function ($q, $search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('c.course_title', 'like', "%{$search}%")
                          ->orWhere('c.certificate_number', 'like', "%{$search}%")
                          ->orWhere('u.first_name', 'like', "%{$search}%")
                          ->orWhere('u.last_name', 'like', "%{$search}%")
                          ->orWhere('u.employee_no', 'like', "%{$search}%");
                });
            })
            ->whereNull('c.deleted_at')
            ->orderByDesc('c.issued_at')
            ->get([
                'c.id', 'c.user_id', 'c.course_id', 'c.skill_id', 'c.certificate_number',
                'c.course_title', 'c.issued_at', 'c.expires_at', 'c.status',
                'c.name', 'c.description', 'c.tags', 'c.verification_code',
                'c.supersedes', 'c.superseded_by', 'c.reissued_at',
                's.display_image', 's.subject_category',
                'k.title as skill_title',
                'u.employee_no',
                DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) as learner_name"),
            ])
            ->map(function ($certificate) use ($now, $soon) {
                if (!$certificate->expires_at) {
                    $certificate->expiry_state = 'active';
                    $certificate->days_to_expiry = null;
                } else {
                    $expiresAt = \Carbon\Carbon::parse($certificate->expires_at);
                    // Negative once expired, which the UI renders as "overdue by".
                    $certificate->days_to_expiry = (int) $now->diffInDays($expiresAt, false);

                    if ($expiresAt < $now) {
                        $certificate->expiry_state = 'expired';
                    } elseif ($expiresAt <= $soon) {
                        $certificate->expiry_state = 'expiring';
                    } else {
                        $certificate->expiry_state = 'active';
                    }
                }

                // tags is stored as a JSON string; hand the client a real array
                // so it never has to parse a column it did not write.
                $decoded = $certificate->tags ? json_decode($certificate->tags, true) : null;
                $certificate->tags = is_array($decoded) ? array_values($decoded) : null;

                return $certificate;
            });

        return response()->json([
            'status' => true,
            'data' => $certificates,
            'meta' => [
                'scope' => $wantsAll ? 'all' : 'mine',
                'warning_days' => $this->certificateWarningDays(),
            ],
        ]);
    }

    /**
     * GET /api/lms/learning/certificates/{id}/download
     *
     * The certificate as a PDF, rendered with dompdf (already a dependency —
     * barryvdh/laravel-dompdf — so no new package). Scoped: a learner may only
     * download their own; admin/HR may download any in their tenant.
     */
    public function downloadCertificate(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $userId = $this->requireUser($request);
        $subInstituteId = $this->tenantId($request);
        // guardAuthoring() now refuses an unresolvable profile outright, so its
        // passing already means admin/hr. The old second clause re-read
        // user_profile_name from the request and would have downgraded a real
        // admin who simply did not send the parameter.
        $isAdmin = $this->guardAuthoring($request) === null;

        $certificate = DB::table('lms_certificates as c')
            ->leftJoin('tbluser as u', 'u.id', '=', 'c.user_id')
            ->where('c.id', $id)
            ->when(
                $isAdmin,
                fn ($q) => $q->where('c.sub_institute_id', $subInstituteId),
                fn ($q) => $q->where('c.user_id', $userId)
            )
            ->whereNull('c.deleted_at')
            ->first([
                'c.*',
                DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) as learner_name"),
            ]);

        if (!$certificate) {
            return response()->json(['status' => false, 'message' => 'Certificate not found'], 404);
        }

        return $this->renderCertificatePdf($certificate);
    }

    /**
     * GET /verify/certificate/{code}
     *
     * Public verification — deliberately unauthenticated, because a credential
     * nobody outside the org can check is not worth issuing. Keyed on the random
     * verification_code, never the sequential certificate_number, and returns
     * only what a verifier needs: whether it is genuine, for whom, and whether
     * it is still in date.
     */
    /**
     * POST /api/lms/learning/courses/{courseId}/complete
     *
     * The learner says they are finished.
     *
     * ── WHY A DECLARATION AND NOT A CALCULATION ─────────────────────────────
     *
     * Completion is the employee's own statement, by decision. Some of what a
     * course asks for happens away from the screen - a conversation, a shift,
     * a piece of practice - and a system that only counts opened lessons
     * cannot see any of it.
     *
     * So the declaration is recorded AND the real lesson count is recorded
     * beside it, and both are returned. A report can then say "marked complete
     * with 3 of 8 lessons opened", which is the honest sentence. Hiding the
     * gap would make the declaration meaningless; refusing the declaration
     * would make the feature useless.
     *
     * ── IT DOES NOT MINT A CERTIFICATE ──────────────────────────────────────
     *
     * The certificate keeps its own rule: every lesson complete. A certificate
     * granted for 3 of 8 lessons devalues every certificate that came before
     * it, so the two artefacts stay separate - you may declare yourself done,
     * and the certificate still has to be earned.
     */
    public function completeCourse(Request $request, $courseId)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $userId = $this->requireUser($request);
        $subInstituteId = $this->tenantId($request);

        // The caller's own enrolment, in their own organisation. A pending one
        // is not completable - it has not been approved yet.
        $enrolment = DB::table('lms_course_enroll as e')
            ->join('sub_std_map as s', 's.id', '=', 'e.course_id')
            ->where('e.user_id', $userId)
            ->where('e.course_id', $courseId)
            ->where('s.sub_institute_id', $subInstituteId)
            ->whereNull('e.deleted_at')
            ->whereIn('e.status', ['enrolled', 'in-progress', 'completed'])
            ->orderByDesc('e.created_at')
            ->select('e.id', 'e.status')
            ->first();

        if (! $enrolment) {
            return response()->json(['status' => false, 'message' => 'You are not enrolled in this course.'], 404);
        }

        $completion = $this->courseCompletion($userId, $courseId, $subInstituteId);
        $total = $completion['total'];
        $done = $completion['done'];

        DB::transaction(function () use ($enrolment, $userId, $courseId, $total, $done) {
            DB::table('lms_course_enroll')->where('id', $enrolment->id)->update([
                'status' => 'completed',
                'end_date' => now()->toDateString(),
                'updated_at' => now(),
            ]);

            // The assignment screen has to agree with My Learning. `progress`
            // stays the REAL figure, not 100 - the declaration is in `status`,
            // and overwriting the measurement with it would erase the evidence.
            DB::table('lms_assignments')
                ->where('user_id', $userId)
                ->where('course_id', $courseId)
                ->whereNull('deleted_at')
                ->update([
                    'status' => 'Completed',
                    'progress' => $total > 0 ? (int) round($done / $total * 100) : 0,
                    'updated_at' => now(),
                ]);
        });

        /*
         * ── AND SAY SO, ONCE, IN THE EVENT STORE — BUT ONLY IF IT IS TRUE ───
         *
         * `course.completed` has been in EventCatalogue since it was written,
         * with CertificateIssuer registered against it and a notification
         * template waiting for it — and the event store holds ZERO of them,
         * because nothing has ever emitted one. Three finished pieces, wired to
         * each other, connected to nothing.
         *
         * ── WHY THE EMIT IS GATED AND NOT UNCONDITIONAL ─────────────────────
         *
         * CertificateIssuer MINTS A CERTIFICATE on this event, and its only
         * condition is that a completed enrolment exists. It does not check
         * lessons, sessions or the quiz — it cannot, that is claimCertificate's
         * job, and the two reach the certificate by different doors.
         *
         * This method sets status='completed' on the learner's own DECLARATION,
         * which is the whole point of it: some of what a course asks for happens
         * away from the screen. So an unconditional emit here would mean
         * pressing "Mark as complete" at 3 of 8 lessons issues a real
         * certificate automatically — bypassing the lesson gate, the session
         * gate and the quiz gate in one step, and making every certificate
         * worthless. That is precisely what this method's own docblock promises
         * does not happen.
         *
         * So the event fires only when the course is genuinely finished by the
         * same rules claimCertificate applies. The declaration is still recorded
         * either way — it is in lms_course_enroll and lms_assignments above.
         * What is withheld is the ANNOUNCEMENT, because `course.completed`
         * should mean what its name says.
         */
        $quizGate = $this->quizGate($userId, $courseId, $subInstituteId);
        $genuinelyComplete = $total > 0 && $done >= $total && $quizGate['passed'];

        /*
         * OUTSIDE the transaction, deliberately: the completion is the fact,
         * and an event-store failure must not roll it back. Idempotency-keyed
         * on the enrolment, so pressing Complete twice records one event.
         */
        try {
            if ($genuinelyComplete) {
                app(\App\Services\Events\EventRecorder::class)->record(
                    'course.completed',
                    (int) $subInstituteId,
                    'enrolment',
                    (int) $enrolment->id,
                    (int) $userId,
                    [
                        'enrollment_id' => (int) $enrolment->id,
                        'user_id' => (int) $userId,
                        'course_id' => (int) $courseId,
                        'total_content' => $completion['content_total'],
                        'completed_content' => $completion['content_done'],
                        'total_sessions' => $completion['session_total'],
                        'attended_sessions' => $completion['session_done'],
                        'quiz_passed' => $quizGate['has_quiz'] ? true : null,
                        'quiz_percent' => $quizGate['best_percent'],
                    ],
                    null,
                    'course.completed:' . $enrolment->id,
                );
            }
        } catch (\Throwable $e) {
            // The learner finished the course. That is true whether or not the
            // event store accepted the announcement, so it is logged, not
            // raised.
            \Illuminate\Support\Facades\Log::warning('course.completed not recorded', [
                'enrolment_id' => $enrolment->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Marked complete.',
            'data' => [
                'marked_complete' => true,
                'total_content' => $completion['content_total'],
                'completed_content' => $completion['content_done'],
                'total_sessions' => $completion['session_total'],
                'attended_sessions' => $completion['session_done'],
                'progress_percent' => $total > 0 ? (int) round($done / $total * 100) : 0,
                /*
                 * So the UI can say what the certificate still needs, rather
                 * than offering a button that will be refused.
                 *
                 * This has to agree with claimCertificate's rules EXACTLY, and
                 * that now includes the quiz. Reporting availability on lesson
                 * and session counts alone would put a Claim button in front of
                 * a learner who has not sat the quiz, and the claim would come
                 * back 422 — the exact "offered and then refused" that this
                 * field exists to prevent.
                 */
                'certificate_available' => $genuinelyComplete,
                'quiz_required' => $quizGate['has_quiz'],
                'quiz_passed' => $quizGate['passed'],
                'certificate_blocked_reason' => $genuinelyComplete ? null : ($quizGate['reason']
                    ?: 'Finish every lesson and attend the sessions you are registered for.'),
            ],
        ]);
    }

    public function verifyCertificate(Request $request, string $code)
    {
        $certificate = DB::table('lms_certificates as c')
            ->leftJoin('tbluser as u', 'u.id', '=', 'c.user_id')
            ->where('c.verification_code', $code)
            ->whereNull('c.deleted_at')
            ->first([
                'c.certificate_number', 'c.name', 'c.course_title', 'c.issued_at',
                'c.expires_at', 'c.status', 'c.superseded_by',
                DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) as learner_name"),
            ]);

        if (!$certificate) {
            return response()->json([
                'status' => false,
                'valid' => false,
                'message' => 'No certificate matches that verification code.',
            ], 404);
        }

        $expired = $certificate->expires_at !== null
            && \Carbon\Carbon::parse($certificate->expires_at)->isPast();
        $superseded = $certificate->superseded_by !== null;

        // "Not valid" covers two different situations and the checker needs to
        // know which: a lapsed credential is not the same as one that has been
        // replaced by a newer issue.
        if ($superseded) {
            $message = 'This certificate is genuine but has been superseded by a newer issue.';
        } elseif ($expired) {
            $message = 'This certificate is genuine but expired on '
                . \Carbon\Carbon::parse($certificate->expires_at)->format('d M Y') . '.';
        } else {
            $message = 'This is a valid, current certificate.';
        }

        return response()->json([
            'status' => true,
            // Superseded certificates are genuine but no longer the current one.
            'valid' => !$expired && !$superseded,
            'message' => $message,
            'data' => [
                'certificate_number' => $certificate->certificate_number,
                'name' => $certificate->name ?? $certificate->course_title,
                'course_title' => $certificate->course_title,
                'learner_name' => $certificate->learner_name,
                'issued_at' => $certificate->issued_at,
                'expires_at' => $certificate->expires_at,
                'is_expired' => $expired,
                'is_superseded' => $certificate->superseded_by !== null,
            ],
        ]);
    }

    /**
     * POST /api/lms/learning/certificates/{id}/reissue
     *
     * Renew a certificate without touching the learner's progress: the old row
     * is marked superseded and a new one is issued with a fresh number, code and
     * expiry. Admin/HR only — a learner cannot extend their own credential.
     */
    public function reissueCertificate(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAuthoring($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $original = DB::table('lms_certificates')
            ->where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$original) {
            return response()->json(['status' => false, 'message' => 'Certificate not found'], 404);
        }

        if ($original->superseded_by !== null) {
            return response()->json([
                'status' => false,
                'message' => 'That certificate has already been re-issued.',
            ], 422);
        }

        $course = DB::table('sub_std_map')->where('id', $original->course_id)->first();
        $now = now();

        try {
            $newId = DB::table('lms_certificates')->insertGetId([
                'user_id' => $original->user_id,
                'course_id' => $original->course_id,
                'enrollment_id' => $original->enrollment_id,
                'skill_id' => $original->skill_id,
                // -R1, -R2 … counting existing renewals for this learner+course.
                // (whereNotNull, not where('supersedes','!=',null) — the latter
                // compares against NULL and never matches.)
                'certificate_number' => $original->certificate_number . '-R'
                    . (DB::table('lms_certificates')
                        ->whereNotNull('supersedes')
                        ->where('course_id', $original->course_id)
                        ->where('user_id', $original->user_id)
                        ->count() + 1),
                'verification_code' => $this->makeVerificationCode(),
                'name' => $original->name,
                'description' => $original->description,
                'tags' => $original->tags,
                'course_title' => $original->course_title,
                'issued_at' => $now,
                'expires_at' => $course && $course->certificate_validity_months
                    ? $now->copy()->addMonths((int) $course->certificate_validity_months)
                    : null,
                'status' => 'active',
                'supersedes' => $original->id,
                'reissued_at' => $now,
                'reissued_by' => $this->contextUserId($request),
                'sub_institute_id' => $subInstituteId,
                'created_by' => $this->contextUserId($request),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // The original is kept for the audit trail, just marked as replaced.
            DB::table('lms_certificates')->where('id', $original->id)->update([
                'superseded_by' => $newId,
                'status' => 'superseded',
                'updated_by' => $this->contextUserId($request),
                'updated_at' => $now,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Certificate re-issued',
                'data' => DB::table('lms_certificates')->find($newId),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to re-issue the certificate',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /** Random, non-guessable — unlike the sequential certificate_number. */
    private function makeVerificationCode(): string
    {
        do {
            $code = strtoupper(bin2hex(random_bytes(8)));
        } while (DB::table('lms_certificates')->where('verification_code', $code)->exists());

        return $code;
    }

    /** Shared PDF rendering for download. */
    /**
     * The organisation a certificate is issued BY.
     *
     * ── WHY A CERTIFICATE NEEDED THIS ───────────────────────────────────────
     *
     * The templates named the course, the learner and the date, and never once
     * said who awarded it. A credential that does not identify its issuer
     * cannot be verified by the person holding it or by anyone they show it to,
     * which is most of the point of having one.
     *
     * The name comes from `org_details.legal_name`, falling back to
     * `institute_detail.organization_name` - tenant 6 has "Scholar Clone" in
     * the first and "Scholar Clone Pvt. Ltd." in the second, and the legal name
     * is the one that belongs on a certificate.
     *
     * ── THE LOGO IS EMBEDDED, NOT LINKED ────────────────────────────────────
     *
     * `org_details.logo` stores a bare filename; the file lives on DigitalOcean
     * under public/hp_logo/. DomPDF is configured with enable_remote = false,
     * so a plain <img src="https://..."> renders as nothing at all - silently,
     * which is the worst way for it to fail.
     *
     * So it is fetched once and inlined as a data URI. Enabling remote fetching
     * globally would let any HTML this app ever renders pull an arbitrary URL
     * server-side, which is a much larger door than this needs.
     *
     * Every failure here is non-fatal: a certificate without a logo is still a
     * valid certificate, and a network timeout must never turn a download into
     * a 500.
     *
     * @return array{name:?string, logo:?string}
     */
    private function certificateOrganisation($subInstituteId): array
    {
        if (!$subInstituteId) {
            return ['name' => null, 'logo' => null];
        }

        $org = DB::table('org_details')
            ->where('sub_institute_id', $subInstituteId)
            ->first(['legal_name', 'logo']);

        $name = $org->legal_name ?? null;

        if (!$name) {
            $name = DB::table('institute_detail')
                ->where('sub_institute_id', $subInstituteId)
                ->value('organization_name');
        }

        return [
            'name' => $name,
            'logo' => $this->inlineLogo($org->logo ?? null),
        ];
    }

    /** A stored logo filename as a data URI DomPDF can actually draw. */
    private function inlineLogo(?string $filename): ?string
    {
        if (!$filename) {
            return null;
        }

        try {
            // The same disk and path organizationDetailsController uploads to.
            $url = \Illuminate\Support\Facades\Storage::disk('digitalocean')
                ->url('public/hp_logo/' . $filename);

            $response = \Illuminate\Support\Facades\Http::timeout(5)->get($url);

            if (!$response->successful()) {
                return null;
            }

            $body = $response->body();

            // A logo is a logo. Anything above a megabyte is not one, and
            // inlining it would bloat every certificate this tenant issues.
            if ($body === '' || strlen($body) > 1024 * 1024) {
                return null;
            }

            $mime = $response->header('Content-Type') ?: 'image/png';

            return 'data:' . $mime . ';base64,' . base64_encode($body);
        } catch (\Throwable $e) {
            // Logged, not raised: the certificate is still correct without it.
            \Illuminate\Support\Facades\Log::info('Certificate logo not embedded', [
                'file' => $filename,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function renderCertificatePdf($certificate)
    {
        $tags = [];
        if (!empty($certificate->tags)) {
            $decoded = json_decode($certificate->tags, true);
            $tags = is_array($decoded) ? $decoded : [];
        }

        /*
         * Honour the template chosen in the Course Builder.
         *
         * The choice is stored on lms_course_settings.certificate_template and
         * resolved through config('lms.certificate_templates'), which maps each
         * value to the blade that renders it. Before this, every certificate
         * rendered lms.certificate regardless of the selection - the dropdown
         * changed a stored value that nothing read.
         *
         * The compliance template is portrait; the standard one is landscape.
         */
        $templateKey = DB::table('lms_course_settings')
            ->where('course_id', $certificate->course_id)
            ->value('certificate_template');

        $templates = collect(config('lms.certificate_templates', []));
        $template = $templates->firstWhere('value', $templateKey) ?? $templates->first();

        $view = $template['view'] ?? 'lms.certificate';
        // A template naming a missing view must not 500 a download.
        if (!view()->exists($view)) {
            $view = 'lms.certificate';
        }

        $orientation = ($template['value'] ?? 'standard') === 'compliance' ? 'portrait' : 'landscape';

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($view, [
            'certificate' => $certificate,
            'learnerName' => $certificate->learner_name ?? '',
            // The issuing organisation, so a certificate says who awarded it.
            'organisation' => $this->certificateOrganisation($certificate->sub_institute_id),
            'tags' => $tags,
            'isExpired' => $certificate->expires_at !== null
                && \Carbon\Carbon::parse($certificate->expires_at)->isPast(),
            'verifyUrl' => $certificate->verification_code
                ? rtrim(config('app.url'), '/') . '/verify/certificate/' . $certificate->verification_code
                : null,
        ])->setPaper('a4', $orientation);

        /*
         * Returning the raw bytes rather than ->download(): any stray output
         * elsewhere in the app (a newline after a closing PHP tag, for example)
         * otherwise lands ahead of the %PDF header, which strict readers reject.
         * Trimming to the header guarantees a clean file.
         */
        $output = $pdf->output();
        $headerAt = strpos($output, '%PDF');
        if ($headerAt !== false && $headerAt > 0) {
            $output = substr($output, $headerAt);
        }

        return response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $certificate->certificate_number . '.pdf"',
            'Content-Length' => (string) strlen($output),
        ]);
    }

    /**
     * How long before expiry a certificate counts as "expiring soon".
     *
     * Configurable because compliance windows differ; the default matches the
     * Certifications & Records screen, and the value is returned in the payload
     * so the UI never hardcodes a number in its label.
     */
    private function certificateWarningDays(): int
    {
        return (int) config('lms.certificate_warning_days', env('LMS_CERT_EXPIRY_WARNING_DAYS', 90));
    }

    /**
     * POST /api/lms/learning/certificates
     *
     * Issue the certificate for a completed course. Idempotent: calling it again
     * returns the existing certificate rather than minting a duplicate, which is
     * what the unique (user_id, course_id) index enforces at the database level.
     */
    public function issueCertificate(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $userId = $this->requireUser($request);
        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make(
            array_merge($request->all(), ['user_id' => $userId]),
            [
                'user_id'   => 'required|integer',
                'course_id' => 'required|integer',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $existing = DB::table('lms_certificates')
            ->where('user_id', $userId)
            ->where('course_id', $request->course_id)
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            return response()->json([
                'status' => true,
                'message' => 'Certificate already issued',
                'data' => $existing,
            ]);
        }

        $course = DB::table('sub_std_map')
            ->where('id', $request->course_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$course) {
            return response()->json(['status' => false, 'message' => 'Course not found'], 404);
        }

        // A certificate is only earned once every lesson is complete, and every
        // session the learner signed up for has been attended. Courses with no
        // content cannot be certified - there is nothing to finish.
        $completion = $this->courseCompletion($userId, $request->course_id, $subInstituteId);
        $total = $completion['total'];
        $done = $completion['done'];

        if ($total === 0 || $done < $total) {
            // Name the actual obstacle. "Finish every lesson" sent a learner
            // who had finished every lesson back to look for one that was not
            // there, when what they were missing was a session.
            $outstandingSessions = $completion['session_total'] - $completion['session_done'];
            $outstandingLessons = $completion['content_total'] - $completion['content_done'];

            if ($total === 0) {
                $message = 'This course has nothing to complete yet, so it cannot be certified.';
            } elseif ($outstandingLessons > 0 && $outstandingSessions > 0) {
                $message = "You still have {$outstandingLessons} lesson(s) and {$outstandingSessions} session(s) outstanding.";
            } elseif ($outstandingSessions > 0) {
                $message = "You are registered for {$outstandingSessions} session(s) on this course that have not been marked attended.";
            } else {
                $message = 'Finish every lesson in this course before claiming the certificate.';
            }

            return response()->json([
                'status' => false,
                'message' => $message,
                'data' => [
                    'total_content' => $completion['content_total'],
                    'completed_content' => $completion['content_done'],
                    'total_sessions' => $completion['session_total'],
                    'attended_sessions' => $completion['session_done'],
                ],
            ], 422);
        }

        /*
         * ── AND THE QUIZ, WHERE THE COURSE HAS ONE ──────────────────────────
         *
         * Before this, a certificate said only that somebody had opened every
         * file in the course. That certifies attendance, not learning, and it
         * is the difference between a credential and a receipt.
         *
         * Only courses that actually have a quiz are gated: adding one is how a
         * course author asks for the stricter rule, and a course without one
         * behaves exactly as it did.
         */
        $quizGate = $this->quizGate($userId, $request->course_id, $subInstituteId);

        if (!$quizGate['passed']) {
            return response()->json([
                'status' => false,
                'message' => $quizGate['reason'],
                'data' => $quizGate,
            ], 422);
        }

        $enrollment = DB::table('lms_course_enroll')
            ->where('user_id', $userId)
            ->where('course_id', $request->course_id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->first();

        try {
            $now = now();
            $certificateId = DB::table('lms_certificates')->insertGetId([
                'user_id' => $userId,
                'course_id' => $request->course_id,
                'enrollment_id' => $enrollment->id ?? null,
                'skill_id' => $this->resolveCourseSkillId($course),
                'certificate_number' => 'CERT-' . $now->format('Y') . '-'
                    . str_pad((string) $request->course_id, 5, '0', STR_PAD_LEFT) . '-'
                    . str_pad((string) $userId, 5, '0', STR_PAD_LEFT),
                // Random and non-guessable, so the public verify page cannot be
                // enumerated from the sequential certificate_number.
                'verification_code' => $this->makeVerificationCode(),
                'course_title' => $course->display_name,
                // Presentation fields default to the course, and can be edited
                // per certificate afterwards.
                'name' => $course->display_name,
                'description' => "Awarded on successful completion of {$course->display_name}.",
                'tags' => json_encode(array_values(array_filter([
                    $course->subject_category,
                    $course->subject_type,
                ]))),
                'issued_at' => $now,
                // Validity is a property of the course. Null means the
                // certificate never expires, which is the default.
                'expires_at' => $course->certificate_validity_months
                    ? $now->copy()->addMonths((int) $course->certificate_validity_months)
                    : null,
                'status' => 'active',
                'sub_institute_id' => $subInstituteId,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Finishing every lesson also completes the enrolment, so the
            // dashboard and catalog agree with the player.
            //
            // Only status/updated_at are written: lms_course_enroll has no
            // updated_by column. The Eloquent path elsewhere appears to set one
            // but $fillable silently drops it, which is why that never surfaced.
            if ($enrollment && $enrollment->status !== 'completed') {
                DB::table('lms_course_enroll')->where('id', $enrollment->id)->update([
                    'status' => 'completed',
                    'updated_at' => $now,
                ]);
            }

            return response()->json([
                'status' => true,
                'message' => 'Certificate issued',
                'data' => DB::table('lms_certificates')->find($certificateId),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to issue the certificate',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /* ------------------------------------------------------------------ *
     * Discussions.
     * ------------------------------------------------------------------ */

    private function isInstructor(Request $request): bool
    {
        // Was read from $request->input('user_profile_name'), so any caller
        // could claim to be an instructor. Resolved from the token's user now.
        $identity = $this->lmsIdentity($request);

        if (!is_array($identity)) {
            return false;
        }

        // G-AUTH-02: exact role_key match, shared with guardLmsProfile so the
        // two gates cannot drift apart.
        return $this->lmsRoleMatches($identity['user'], self::AUTHORING_PROFILES);
    }

    /** GET /api/lms/learning/discussions - threads for a course, with replies. */
    public function discussions(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $courseId = $request->input('course_id');
        if (!$courseId) {
            return response()->json(['status' => false, 'message' => 'course_id is required'], 422);
        }

        $threads = DB::table('lms_course_discussions as d')
            ->leftJoin('tbluser as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('content_master as c', 'c.id', '=', 'd.content_id')
            ->where('d.course_id', $courseId)
            ->whereNull('d.deleted_at')
            ->orderByDesc('d.created_at')
            ->get([
                'd.id', 'd.course_id', 'd.chapter_id', 'd.content_id', 'd.user_id',
                'd.title', 'd.message', 'd.is_instructor', 'd.is_resolved', 'd.created_at',
                DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) as author_name"),
                'c.title as content_title',
            ]);

        $replies = $threads->isEmpty() ? collect() : DB::table('lms_course_discussion_replies as r')
            ->leftJoin('tbluser as u', 'u.id', '=', 'r.user_id')
            ->whereIn('r.discussion_id', $threads->pluck('id'))
            ->whereNull('r.deleted_at')
            ->orderBy('r.created_at')
            ->get([
                'r.id', 'r.discussion_id', 'r.user_id', 'r.message', 'r.is_instructor', 'r.created_at',
                DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) as author_name"),
            ])
            ->groupBy('discussion_id');

        $threads->transform(function ($thread) use ($replies) {
            $thread->replies = ($replies[$thread->id] ?? collect())->values();
            $thread->reply_count = $thread->replies->count();
            $thread->is_instructor = (bool) $thread->is_instructor;
            $thread->is_resolved = (bool) $thread->is_resolved;
            return $thread;
        });

        return response()->json(['status' => true, 'data' => $threads]);
    }

    /** POST /api/lms/learning/discussions - start a thread. */
    public function storeDiscussion(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $userId = $this->requireUser($request);

        $validator = Validator::make(
            array_merge($request->all(), ['user_id' => $userId]),
            [
                'user_id'    => 'required|integer',
                'course_id'  => 'required|integer',
                'chapter_id' => 'nullable|integer',
                'content_id' => 'nullable|integer',
                'title'      => 'nullable|string|max:191',
                'message'    => 'required|string|max:5000',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $id = DB::table('lms_course_discussions')->insertGetId([
            'course_id' => $request->course_id,
            'chapter_id' => $request->chapter_id,
            'content_id' => $request->content_id,
            'user_id' => $userId,
            'title' => $request->title,
            'message' => $request->message,
            'is_instructor' => $this->isInstructor($request),
            'sub_institute_id' => $this->tenantId($request),
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Posted',
            'data' => DB::table('lms_course_discussions')->find($id),
        ], 201);
    }

    /** POST /api/lms/learning/discussions/{id}/replies */
    public function replyToDiscussion(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $userId = $this->requireUser($request);

        $validator = Validator::make(
            array_merge($request->all(), ['user_id' => $userId]),
            [
                'user_id' => 'required|integer',
                'message' => 'required|string|max:5000',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $thread = DB::table('lms_course_discussions')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$thread) {
            return response()->json(['status' => false, 'message' => 'Discussion not found'], 404);
        }

        $replyId = DB::table('lms_course_discussion_replies')->insertGetId([
            'discussion_id' => $id,
            'user_id' => $userId,
            'message' => $request->message,
            'is_instructor' => $this->isInstructor($request),
            'sub_institute_id' => $this->tenantId($request),
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Reply posted',
            'data' => DB::table('lms_course_discussion_replies')->find($replyId),
        ], 201);
    }

    /**
     * DELETE /api/lms/learning/discussions/{id}
     *
     * Authors delete their own threads; admin/HR can moderate any of them.
     */
    public function destroyDiscussion(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $userId = $this->requireUser($request);

        $thread = DB::table('lms_course_discussions')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$thread) {
            return response()->json(['status' => false, 'message' => 'Discussion not found'], 404);
        }

        if ((int) $thread->user_id !== (int) $userId && !$this->isInstructor($request)) {
            return response()->json([
                'status' => false,
                'message' => 'You can only delete your own posts.',
            ], 403);
        }

        $now = now();
        DB::table('lms_course_discussions')->where('id', $id)
            ->update(['deleted_at' => $now, 'deleted_by' => $userId]);
        DB::table('lms_course_discussion_replies')->where('discussion_id', $id)->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'deleted_by' => $userId]);

        return response()->json([
            'status' => true,
            'message' => 'Discussion deleted',
            'data' => ['id' => (int) $id],
        ]);
    }
}
