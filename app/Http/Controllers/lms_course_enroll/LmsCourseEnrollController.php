<?php

namespace App\Http\Controllers\lms_course_enroll;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Models\lms_course_enroll\LmsCourseEnroll;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class LmsCourseEnrollController extends Controller
{
    use ResolvesApiIdentity;
    use \App\Http\Controllers\Concerns\ResolvesG2gActor;

    /**
     * The ACTING user, resolved from the token and never from the request.
     *
     * G-SEC-12. created_by / updated_by were taken from request input, so a caller
     * could attribute their own write to another user and the audit trail would
     * record it as fact. A leak exposes data; this corrupts the record of who did
     * what - the evidence you would rely on when investigating a leak.
     *
     * Blocks the event store: actor_id on every event has to be trustworthy or the
     * store inherits a corrupted audit trail on day one.
     *
     * Same shape as payrollActorId (D-004): token first, session fallback.
     */


    /*
     * Authentication used to be opt-in: every method ran its token check
     * only `if ($type == "API")`, so omitting one parameter skipped it.
     * The organisation was read from the request too, which let an
     * enrolment be created against another tenant's course.
     *
     * `user_id` is deliberately NOT forced to the token owner here. Unlike
     * the other controllers in this pass it is a genuine subject: store()
     * supports an administrator recording an enrolment or completion for
     * somebody else, as its own comment describes. Confining it to the
     * caller would remove that. It is now bounded by the tenant instead -
     * the queries filter on the token-derived sub_institute_id, so a
     * subject outside the caller's organisation cannot be reached.
     */

    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $subInstituteId = $identity['sub_institute_id'];

        $userId = $request->user_id ?? $request->header('user_id');
        
        if (!$userId) {
            return response()->json([
                'status' => false,
                'message' => 'user_id is required'
            ], 422);
        }
        
        // Get user's jobrole
        $userJobrole = DB::table('tbluser as u')
            ->leftJoin('s_user_jobrole as j', 'u.allocated_standards', '=', 'j.id')
            ->where('u.id', $userId)
            ->select('j.jobrole')
            ->first();
        
        // Get the latest enrollment for each course. Soft-deleted rows are
        // excluded so that a course the learner has unenrolled from (destroy()
        // sets deleted_at) actually disappears from their list.
        $latestEnrollments = DB::table('lms_course_enroll')
            ->select('course_id', DB::raw('MAX(created_at) as latest_enrolled_at'))
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->groupBy('course_id');

        // A course's title is sub_std_map.display_name - the same column that
        // lms/course_master aliases as `subject_name`. sub_std_map.subject_id is
        // polymorphic (it points at a task, skill or jobrole row depending on
        // subject_category) and does NOT reference the `subject` table, so it is
        // deliberately not joined. standard_id references hrms_departments, not
        // `standard`, which is likewise how courseController resolves it.
        $course = DB::table('lms_course_enroll as e')
            ->join('sub_std_map as s', 'e.course_id', '=', 's.id')
            ->leftJoin('hrms_departments as d', 'd.id', '=', 's.standard_id')
            ->joinSub($latestEnrollments, 'latest', function ($join) {
                $join->on('e.course_id', '=', 'latest.course_id')
                     ->on('e.created_at', '=', 'latest.latest_enrolled_at');
            })
            ->where('e.user_id', $userId)
            /*
             * SCOPED. $subInstituteId was resolved at the top of this method
             * from the token's owner and then never used, so passing any
             * user_id returned that person's enrolments regardless of which
             * organisation either of you belonged to.
             *
             * Filtered on the COURSE's tenant: sub_std_map.sub_institute_id is
             * NOT NULL and is the authority on ownership, while
             * lms_course_enroll.sub_institute_id is nullable. Same reasoning,
             * and the same ordering, as LmsLearningController::courses().
             */
            ->where('s.sub_institute_id', $subInstituteId)
            ->whereNull('e.deleted_at')
            ->select(
                's.*',
                // The enrolment row id - required to PUT /api/enroll/{id}. `s.*`
                // only exposes sub_std_map.id (the course id), so without this
                // the client cannot address its own enrolment record.
                'e.id as enrollment_id',
                'd.department as standard_name',
                'e.status as enrollment_status',
                'e.start_date',
                'e.end_date',
                'e.created_at as enrolled_at'
            )
            ->get();
        
        return response()->json([
            'status' => true,
            'data' => $course,
        ]);
    }

    /**
     * Courses the learner can enrol in.
     *
     * lms/course_master already lists the catalogue, but it sits behind the
     * ['auth','session','menu'] web middleware, so a token-authenticated client
     * cannot reach it. This exposes the same sub_std_map catalogue over the
     * token contract the rest of /api/enroll* uses, annotated with the caller's
     * current enrolment state so the picker can hide or mark what they already
     * have. Read-only and additive - no existing route or response changes.
     */
    public function available(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $subInstituteId = $identity['sub_institute_id'];

        $userId = $request->user_id ?? $request->header('user_id');

        if (!$userId) {
            return response()->json([
                'status' => false,
                'message' => 'user_id is required'
            ], 422);
        }

        if (!$subInstituteId) {
            return response()->json([
                'status' => false,
                'message' => 'sub_institute_id is required'
            ], 422);
        }

        // Course ids the learner already holds an active enrolment for.
        $enrolledCourseIds = DB::table('lms_course_enroll')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('course_id')
            ->all();

        // standard_id references hrms_departments, matching courseController.
        $query = DB::table('sub_std_map as s')
            ->leftJoin('hrms_departments as d', 'd.id', '=', 's.standard_id')
            ->where('s.sub_institute_id', $subInstituteId)
            ->whereNull('s.deleted_at')
            ->where('s.status', 1);

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('s.display_name', 'like', "%{$search}%")
                  ->orWhere('s.subject_category', 'like', "%{$search}%")
                  ->orWhere('s.jobrole', 'like', "%{$search}%");
            });
        }

        // Default to only what the learner can still enrol in; pass
        // exclude_enrolled=0 to get the whole catalogue with flags instead.
        $excludeEnrolled = $request->input('exclude_enrolled', '1') !== '0';
        if ($excludeEnrolled && !empty($enrolledCourseIds)) {
            $query->whereNotIn('s.id', $enrolledCourseIds);
        }

        $limit = min(max((int) $request->input('limit', 50), 1), 200);

        $courses = $query
            ->orderBy('s.display_name')
            ->limit($limit)
            ->get([
                's.id',
                's.display_name',
                's.display_image',
                's.subject_type',
                's.subject_category',
                's.jobrole',
                's.proficiency',
                's.standard_id',
                'd.department as standard_name',
            ])
            ->map(function ($course) use ($enrolledCourseIds) {
                $course->is_enrolled = in_array($course->id, $enrolledCourseIds);
                return $course;
            })
            ->values();

        return response()->json([
            'status' => true,
            'data' => $courses,
        ]);
    }

    /**
     * Decide whether a learner may enrol on a course, and how.
     *
     * The Course Builder writes availability, visibility, department/role
     * restrictions, prerequisites and the open-vs-approval rule to
     * lms_course_settings and lms_course_prerequisites. Until now nothing read
     * them back, so a course marked "Approval Required" or restricted to one
     * department still enrolled anyone instantly - the builder's settings were
     * recorded but not enforced.
     *
     * Returns:
     *   ['allowed' => bool, 'reason' => ?string, 'status' => 'enrolled'|'pending']
     *
     * A course with no settings row behaves exactly as it did before: open
     * enrolment, no restrictions. That keeps every pre-existing course working.
     */
    private function checkEnrolmentEligibility($courseId, $userId, $subInstituteId): array
    {
        $open = ['allowed' => true, 'reason' => null, 'status' => 'enrolled'];

        $settings = DB::table('lms_course_settings')
            ->where('course_id', $courseId)
            ->whereNull('deleted_at')
            ->first();

        $prerequisites = DB::table('lms_course_prerequisites as p')
            ->join('sub_std_map as s', 's.id', '=', 'p.prerequisite_course_id')
            ->where('p.course_id', $courseId)
            ->whereNull('p.deleted_at')
            ->whereNull('s.deleted_at')
            ->pluck('s.display_name', 'p.prerequisite_course_id');

        // Prerequisites are checked even when there is no settings row: they
        // live in their own table and are meaningful on their own.
        if ($prerequisites->isNotEmpty()) {
            $completed = DB::table('lms_course_enroll')
                ->where('user_id', $userId)
                ->whereIn('course_id', $prerequisites->keys())
                ->where('status', 'completed')
                ->whereNull('deleted_at')
                ->pluck('course_id')
                ->all();

            $missing = $prerequisites->except($completed);

            if ($missing->isNotEmpty()) {
                return [
                    'allowed' => false,
                    'reason' => 'Complete ' . $missing->implode(', ') . ' first.',
                    'status' => null,
                ];
            }
        }

        if (!$settings) {
            return $open;
        }

        // Availability window.
        $today = now()->startOfDay();

        if ($settings->available_from && $today->lt(\Carbon\Carbon::parse($settings->available_from))) {
            return [
                'allowed' => false,
                'reason' => 'This course opens on '
                    . \Carbon\Carbon::parse($settings->available_from)->format('d M Y') . '.',
                'status' => null,
            ];
        }

        if ($settings->available_until && $today->gt(\Carbon\Carbon::parse($settings->available_until))) {
            return [
                'allowed' => false,
                'reason' => 'Enrolment for this course closed on '
                    . \Carbon\Carbon::parse($settings->available_until)->format('d M Y') . '.',
                'status' => null,
            ];
        }

        // Department and role restrictions only bite when visibility is
        // 'restricted' - an admin can list departments while leaving the course
        // open, and that should stay open.
        if ($settings->visibility === 'restricted') {
            $learner = DB::table('tbluser')
                ->where('id', $userId)
                ->first(['department_id', 'user_profile_id']);

            $departments = $settings->restrict_departments
                ? json_decode($settings->restrict_departments, true)
                : null;

            if (is_array($departments) && $departments !== []) {
                if (!$learner || !in_array((int) $learner->department_id, array_map('intval', $departments), true)) {
                    return [
                        'allowed' => false,
                        'reason' => 'This course is restricted to specific departments.',
                        'status' => null,
                    ];
                }
            }

            $roles = $settings->restrict_roles ? json_decode($settings->restrict_roles, true) : null;

            if (is_array($roles) && $roles !== []) {
                $profileName = $learner
                    ? DB::table('tbluserprofilemaster')->where('id', $learner->user_profile_id)->value('name')
                    : null;

                $matches = collect($roles)->contains(
                    fn ($role) => $profileName && strcasecmp(trim((string) $role), trim($profileName)) === 0
                );

                if (!$matches) {
                    return [
                        'allowed' => false,
                        'reason' => 'This course is restricted to specific roles.',
                        'status' => null,
                    ];
                }
            }
        }

        // Approval-required courses enrol as 'pending' rather than being
        // refused: the learner has asked, an admin decides.
        return [
            'allowed' => true,
            'reason' => null,
            'status' => $settings->enrollment_rule === 'approval' ? 'pending' : 'enrolled',
        ];
    }

     public function store(Request $request)
    {
    $identity = $this->resolveApiIdentity($request);
    if (!is_array($identity)) {
        return $identity;
    }
    $subInstituteId = $identity['sub_institute_id'];

    // ✅ VALIDATION
    $validator = Validator::make($request->all(), [
        'user_id' => 'required|integer',
        'course_id' => 'required|integer|exists:sub_std_map,id',
        'status' => 'required|in:completed,in-progress,enrolled,pending',
        'start_date' => 'nullable|date',
        'end_date' => 'nullable|date',
        'sub_institute_id' => 'nullable|integer'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => 0,
            'message' => $validator->messages()->first()
        ], 422);
    }

    /*
     * Enforce the Course Builder's rules before creating the enrolment.
     *
     * Only applied when the caller is asking to enrol ('enrolled'). An admin
     * recording a completion or an in-progress state is correcting the record,
     * not requesting access, so gating that would block legitimate admin edits.
     */
    if ($request->status === 'enrolled') {
        $eligibility = $this->checkEnrolmentEligibility(
            $request->course_id,
            $request->user_id,
            $subInstituteId
        );

        if (!$eligibility['allowed']) {
            return response()->json([
                'status' => 0,
                'message' => $eligibility['reason'],
                'eligibility' => $eligibility,
            ], 422);
        }

        // 'pending' when the course requires approval.
        $request->merge(['status' => $eligibility['status']]);
    }

    try {
        $objcourse = new LmsCourseEnroll();
        $objcourse->user_id = $request->user_id;
        $objcourse->course_id = $request->course_id;
        $objcourse->status = $request->status;
        $objcourse->start_date = $request->start_date;
        $objcourse->end_date = $request->end_date;
        $objcourse->sub_institute_id = $subInstituteId;

        if ($objcourse->save()) {
            return response()->json([
                // The learner must be told when the request is awaiting
                // approval rather than active, or they will wait for access
                // that never silently arrives.
                'message' => $objcourse->status === 'pending'
                    ? 'Enrolment requested. An administrator will review it.'
                    : 'Course Enroll added successfully!',
                'data' => $objcourse,
                'requires_approval' => $objcourse->status === 'pending',
            ], 200);
        }

        return response()->json(['message' => 'Something went wrong!'], 500);

    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}


    public function show()
    {
        //
    }

    public function update(Request $request, string $id)
{
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $subInstituteId = $identity['sub_institute_id'];


    // Validate required fields
    $validator = Validator::make($request->all(), [
        'user_id' => 'required|integer',
        'course_id' => 'required|integer|exists:sub_std_map,id',
        'status' => 'required|in:completed,in-progress',
        'start_date' => 'nullable|date',
        'end_date' => 'nullable|date',
        'sub_institute_id' => 'required|integer'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    // Find existing course enrollment record, scoped to the requesting user.
    // Looking it up by id alone let any caller mutate another learner's
    // enrolment by guessing the id; user_id is already validated as required.
    $courseEnroll = LmsCourseEnroll::where([
        'id'      => $id,
        'user_id' => $request->user_id,
    ])->whereNull('deleted_at')->first();

    if (!$courseEnroll) {
        return response()->json([
            'message' => 'Enrollment record not found',
            'data' => $id
        ], 404);
    }

    // Update fields
    $update = $courseEnroll->update([
        'user_id' => $request->user_id,
        'course_id' => $request->course_id,
        'status' => $request->status,
        'start_date' => $request->start_date,
        'end_date' => $request->end_date,
        'sub_institute_id' => $subInstituteId,
        'updated_by' => $this->g2gActorId($request),
        'updated_at' => now()
    ]);

    if ($update) {
        return response()->json([
            'message' => 'Course enrollment updated successfully',
            'data' => $courseEnroll
        ], 200);
    }

    return response()->json([
        'message' => 'Failed to update course enrollment',
        'data' => $id
    ], 400);
}


     public function destroy(Request $request, string $id)
{
    $identity = $this->resolveApiIdentity($request);
    if (!is_array($identity)) {
        return $identity;
    }
    $subInstituteId = $identity['sub_institute_id'];

    // --------------------------
    // 🛂 Required Validation
    // --------------------------
    $validator = Validator::make($request->all(), [
        'user_id'           => 'required|integer',
        'sub_institute_id'  => 'required|integer'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Validation failed',
            'errors'  => $validator->errors()
        ], 422);
    }

    $sub_institute_id = $subInstituteId;

    // --------------------------
    // 🔎 Find Enrollment Record
    // --------------------------
    // Scoped to the requesting user: without user_id in the lookup this picked
    // whichever row matched the course first, so one learner unenrolling could
    // soft-delete a different learner's enrolment in the same course. user_id is
    // already validated as required above, so this restores the intended scope.
    // Already-deleted rows are skipped so a repeat call 404s instead of
    // re-stamping deleted_at.
    $courseEnroll = LmsCourseEnroll::where([
        'sub_institute_id' => $sub_institute_id,
        'course_id'        => $id,
        'user_id'          => $request->user_id,
    ])->whereNull('deleted_at')->first();

    if (!$courseEnroll) {
        return response()->json([
            'message' => 'Enrollment record not found',
            'data' => $id
        ], 404);
    }

    // --------------------------
    // 🗑 Soft Delete Record
    // --------------------------
    $delete = $courseEnroll->update([
        'deleted_at' => now(),
        'deleted_by' => $this->g2gActorId($request),
        'updated_at' => now()
    ]);

    if ($delete) {
        return response()->json([
            'message' => 'Course enrollment deleted successfully',
            'data'    => $courseEnroll
        ], 200);
    }

    return response()->json([
        'message' => 'Failed to delete course enrollment',
        'data'    => $id
    ], 400);
}
}
