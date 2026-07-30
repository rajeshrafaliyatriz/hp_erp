<?php

namespace App\Http\Controllers\lms\assignment;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\lms\assignment\LmsAssignment;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\DB;

class assignmentController extends Controller
{
    /**
     * Validate API token when type=API.
     */
    private function validateToken(Request $request)
    {
        $type = $request->type;
        if ($type == "API") {
            $token = $request->input('token');
            if (!$token) return response()->json(['message' => 'Token not provided'], 401);
            $accessToken = PersonalAccessToken::findToken($token);
            if (!$accessToken) return response()->json(['message' => 'Invalid token'], 401);
        }
        return null;
    }

    /**
     * GET /api/lmsAssignment
     * List all assignments for the current sub_institute.
     */
    public function index(Request $request)
    {
        $authError = $this->validateToken($request);
        if ($authError) return $authError;

        $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id');

        // Join to get learner and course details
        $query = DB::table('lms_assignments as a')
            ->join('sub_std_map as c', 'a.course_id', '=', 'c.id')
            ->join('tbluser as u', 'a.user_id', '=', 'u.id')
            ->where('a.sub_institute_id', $subInstituteId)
            ->whereNull('a.deleted_at');

        // tbluser has no `name` column - the display name is composed from
        // first_name / last_name (see any other controller that joins tbluser).
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('c.display_name', 'like', "%{$search}%")
                  ->orWhere('u.first_name', 'like', "%{$search}%")
                  ->orWhere('u.last_name', 'like', "%{$search}%")
                  ->orWhere('u.employee_no', 'like', "%{$search}%");
            });
        }

        // The Assignment Queue shows live assignments; the Approval Queue asks
        // for approval_status=pending. Defaults to everything already approved
        // so a pending self-request never looks like an active assignment.
        $approvalStatus = $request->input('approval_status', 'approved');
        if ($approvalStatus !== 'all') {
            $query->where('a.approval_status', $approvalStatus);
        }

        $assignments = $query->select(
            'a.id',
            DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) as learner_name"),
            'c.display_name as course_name',
            'c.subject_type as type',
            'a.assignment_type',
            'a.due_date',
            'a.status',
            'a.approval_status',
            'a.requested_by',
            'a.reviewed_at',
            'a.review_note',
            'a.progress',
            'a.assigned_by',
            'a.assigned_on',
            /*
             * Provenance. lms_assignments is shared: the Competency module
             * writes rows here with source='competency' when learning is
             * assigned from a development plan, and every row currently in the
             * table came that way. Without these columns the LMS list showed
             * them as if they were its own, so an admin had no way to know that
             * changing one also moves a competency plan.
             */
            'a.source',
            'a.competency_id',
            'a.development_plan_id'
        )->get();

        // Compute initials on the server side
        $assignments->transform(function ($item) {
            $parts = explode(' ', $item->learner_name ?? '');
            $initials = '';
            foreach ($parts as $part) {
                $initials .= strtoupper(substr($part, 0, 1));
            }
            $item->initials = substr($initials, 0, 2);
            return $item;
        });

        return response()->json([
            'status' => true,
            'data' => $assignments
        ]);
    }

    /**
     * GET /api/lmsAssignment/stats
     * KPI statistics for the assignment dashboard.
     */
    public function stats(Request $request)
    {
        $authError = $this->validateToken($request);
        if ($authError) return $authError;

        $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id');
        $query = LmsAssignment::where('sub_institute_id', $subInstituteId)->whereNull('deleted_at');

        $total = $query->count();
        $inProgress = (clone $query)->where('status', 'In Progress')->count();
        $completed = (clone $query)->where('status', 'Completed')->count();

        // Overdue: status is explicitly 'Overdue' OR due_date has passed and not completed
        $overdue = (clone $query)->where(function ($q) {
            $q->where('status', 'Overdue')
              ->orWhere(function ($q2) {
                  $q2->whereNotNull('due_date')
                     ->where('due_date', '<', now())
                     ->where('status', '!=', 'Completed');
              });
        })->count();

        // Real count now that approvals exist - this was hardcoded to 0.
        $pendingApproval = (clone $query)->where('approval_status', 'pending')->count();

        return response()->json([
            'status' => true,
            'data' => [
                'total_assigned' => $total,
                'in_progress' => $inProgress,
                'completed' => $completed,
                'overdue' => $overdue,
                'pending_approval' => $pendingApproval
            ]
        ]);
    }

    /**
     * POST /api/lmsAssignment
     * Create one or more assignments (bulk assign to multiple users).
     */
    public function store(Request $request)
    {
        $authError = $this->validateToken($request);
        if ($authError) return $authError;

        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array',
            'course_id' => 'required|integer',
            'assignment_type' => 'required|string',
            'due_date' => 'nullable|date',
            'sub_institute_id' => 'required|integer'
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        // Same reason as in index(): tbluser stores the name in parts, so
        // ->value('name') returned null and assigned_by was never populated.
        $assignedBy = $request->user_id
            ? (DB::table('tbluser')
                ->where('id', $request->user_id)
                ->selectRaw("TRIM(CONCAT_WS(' ', first_name, last_name)) as full_name")
                ->value('full_name') ?: 'Admin')
            : 'Admin';

        $assignments = [];
        foreach ($request->user_ids as $userId) {
            $assignments[] = [
                'user_id' => $userId,
                'course_id' => $request->course_id,
                'assignment_type' => $request->assignment_type,
                'due_date' => $request->due_date,
                'status' => 'Not Started',
                'progress' => 0,
                'assigned_by' => $assignedBy,
                'assigned_on' => now(),
                'sub_institute_id' => $request->sub_institute_id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        LmsAssignment::insert($assignments);

        return response()->json(['message' => 'Assignments created successfully', 'status' => true]);
    }

    /**
     * POST /api/lmsAssignment/updateStatus/{id}
     * Update the status of a single assignment.
     */
    public function updateStatus(Request $request, $id)
    {
        $authError = $this->validateToken($request);
        if ($authError) return $authError;

        $validator = Validator::make($request->all(), [
            'status' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $assignment = LmsAssignment::find($id);
        if (!$assignment) return response()->json(['message' => 'Not found'], 404);

        $assignment->status = $request->status;
        $assignment->save();

        return response()->json(['message' => 'Status updated successfully', 'status' => true]);
    }

    /**
     * POST /api/lmsAssignment/bulkUpdateStatus
     * Batch-update status for multiple assignments at once.
     */
    public function bulkUpdateStatus(Request $request)
    {
        $authError = $this->validateToken($request);
        if ($authError) return $authError;

        $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id');

        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            // Constrained to the four states the table actually uses. Previously
            // 'required|string' accepted anything, so a typo silently wrote a
            // status neither module's filters would ever match again.
            'status' => 'required|in:Not Started,In Progress,Completed,Overdue',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $status = $request->input('status');
        $update = ['status' => $status, 'updated_at' => now()];

        /*
         * Keep `progress` in step with `status`.
         *
         * lms_assignments is shared with the Competency module, which assigns
         * learning from a development plan and reads `progress` back to show
         * plan completion. Its own update() already couples the two - marking
         * an assignment Completed sets progress to 100. This endpoint set only
         * `status`, so a bulk "Completed" here left progress at whatever it was
         * (20-84% on live rows) and the Competency dashboard would report the
         * plan as unfinished forever.
         *
         * Only the unambiguous ends are forced; In Progress and Overdue leave
         * whatever real progress the learner has made alone.
         */
        if ($status === 'Completed') {
            $update['progress'] = 100;
        } elseif ($status === 'Not Started') {
            $update['progress'] = 0;
        }

        // Scoped to the tenant and to live rows. Without this an id from another
        // institute could be updated by guessing it.
        $affected = LmsAssignment::whereIn('id', $request->ids)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->update($update);

        return response()->json([
            'message' => $affected . ' assignment(s) updated.',
            'status' => true,
            'data' => ['affected' => $affected, 'skipped' => count($request->ids) - $affected],
        ]);
    }

    /**
     * POST /api/lmsAssignment/request
     *
     * A learner asks to take a course. Lands in the Approval Queue rather than
     * becoming an active assignment, which is what distinguishes a self-request
     * from an admin push (store() writes approval_status = approved).
     */
    public function requestEnrollment(Request $request)
    {
        $authError = $this->validateToken($request);
        if ($authError) return $authError;

        $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id');
        $userId = $request->user_id ?? $request->header('user_id');

        $validator = Validator::make(
            array_merge($request->all(), ['user_id' => $userId, 'sub_institute_id' => $subInstituteId]),
            [
                'user_id'          => 'required|integer',
                'sub_institute_id' => 'required|integer',
                'course_id'        => 'required|integer',
                'due_date'         => 'nullable|date',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $existing = DB::table('lms_assignments')
            ->where('user_id', $userId)
            ->where('course_id', $request->course_id)
            ->whereNull('deleted_at')
            ->whereIn('approval_status', ['approved', 'pending'])
            ->first(['approval_status']);

        if ($existing) {
            return response()->json([
                'status' => false,
                'message' => $existing->approval_status === 'pending'
                    ? 'You already have a pending request for this course.'
                    : 'You are already assigned to this course.',
            ], 422);
        }

        $now = now();
        $id = DB::table('lms_assignments')->insertGetId([
            'user_id' => $userId,
            'course_id' => $request->course_id,
            'assignment_type' => 'Optional',
            'due_date' => $request->due_date,
            'status' => 'Not Started',
            'progress' => 0,
            'approval_status' => 'pending',
            'requested_by' => $userId,
            'assigned_by' => 'Self-requested',
            'assigned_on' => $now,
            'sub_institute_id' => $subInstituteId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Request submitted for approval',
            'data' => DB::table('lms_assignments')->find($id),
        ], 201);
    }

    /**
     * POST /api/lmsAssignment/review/{id}
     *
     * Approve or reject a pending request. Admin/HR only - the same profiles
     * that may author courses.
     */
    public function review(Request $request, $id)
    {
        $authError = $this->validateToken($request);
        if ($authError) return $authError;

        $profile = strtolower(trim((string) $request->input('user_profile_name', '')));
        if ($profile !== '' && !str_contains($profile, 'admin') && !str_contains($profile, 'hr')) {
            return response()->json([
                'status' => false,
                'message' => 'Your profile is not permitted to review enrolment requests.',
            ], 403);
        }

        $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id');

        $validator = Validator::make($request->all(), [
            'decision'    => 'required|in:approved,rejected',
            'review_note' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $assignment = DB::table('lms_assignments')
            ->where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$assignment) {
            return response()->json(['status' => false, 'message' => 'Request not found'], 404);
        }

        if ($assignment->approval_status !== 'pending') {
            return response()->json([
                'status' => false,
                'message' => 'That request has already been reviewed.',
            ], 422);
        }

        DB::table('lms_assignments')->where('id', $id)->update([
            'approval_status' => $request->decision,
            'reviewed_by' => $request->user_id,
            'reviewed_at' => now(),
            'review_note' => $request->review_note,
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => $request->decision === 'approved' ? 'Request approved' : 'Request rejected',
            'data' => DB::table('lms_assignments')->find($id),
        ]);
    }

    /**
     * POST /api/lmsAssignment/bulkReview
     *
     * Approve or reject several pending requests at once.
     */
    public function bulkReview(Request $request)
    {
        $authError = $this->validateToken($request);
        if ($authError) return $authError;

        $profile = strtolower(trim((string) $request->input('user_profile_name', '')));
        if ($profile !== '' && !str_contains($profile, 'admin') && !str_contains($profile, 'hr')) {
            return response()->json([
                'status' => false,
                'message' => 'Your profile is not permitted to review enrolment requests.',
            ], 403);
        }

        $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id');

        $validator = Validator::make($request->all(), [
            'ids'      => 'required|array|min:1',
            'ids.*'    => 'integer',
            'decision' => 'required|in:approved,rejected',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        // Scoped to the tenant and to rows still pending, so an id from another
        // institute or an already-reviewed row is skipped rather than acted on.
        $affected = DB::table('lms_assignments')
            ->whereIn('id', $request->ids)
            ->where('sub_institute_id', $subInstituteId)
            ->where('approval_status', 'pending')
            ->whereNull('deleted_at')
            ->update([
                'approval_status' => $request->decision,
                'reviewed_by' => $request->user_id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'status' => true,
            'message' => $affected . ' request(s) ' . $request->decision . '.',
            'data' => ['affected' => $affected, 'skipped' => count($request->ids) - $affected],
        ]);
    }

    /**
     * GET /api/lmsAssignment/enrollments
     *
     * Learner-initiated enrolments, from lms_course_enroll.
     *
     * This is a different entity from an assignment: assignments are pushed by
     * an admin, enrolments are what a learner took up themselves. The
     * Enrollments tab rendered the assignment table, which showed neither.
     */
    public function enrollments(Request $request)
    {
        $authError = $this->validateToken($request);
        if ($authError) return $authError;

        $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id');

        if (!$subInstituteId) {
            return response()->json(['status' => false, 'message' => 'sub_institute_id is required'], 422);
        }

        $query = DB::table('lms_course_enroll as e')
            ->join('sub_std_map as c', 'e.course_id', '=', 'c.id')
            ->join('tbluser as u', 'e.user_id', '=', 'u.id')
            ->where('e.sub_institute_id', $subInstituteId)
            ->whereNull('e.deleted_at');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('c.display_name', 'like', "%{$search}%")
                  ->orWhere('u.first_name', 'like', "%{$search}%")
                  ->orWhere('u.last_name', 'like', "%{$search}%")
                  ->orWhere('u.employee_no', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('e.status', $status);
        }

        $enrollments = $query
            ->orderByDesc('e.created_at')
            ->limit(min(max((int) $request->input('limit', 200), 1), 500))
            ->get([
                'e.id',
                'e.course_id',
                'e.user_id',
                DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) as learner_name"),
                'u.employee_no',
                'c.display_name as course_name',
                'c.subject_type as type',
                'e.status',
                'e.start_date',
                'e.end_date',
                'e.created_at as enrolled_on',
            ])
            ->map(function ($row) {
                $initials = '';
                foreach (explode(' ', $row->learner_name ?? '') as $part) {
                    $initials .= strtoupper(substr($part, 0, 1));
                }
                $row->initials = substr($initials, 0, 2);
                return $row;
            });

        return response()->json(['status' => true, 'data' => $enrollments]);
    }

    /**
     * GET /api/lmsAssignment/learners
     *
     * Learner picker for the Assign Learning dialog.
     *
     * /api/attendance/employees returns a similar list, but it is scoped to the
     * attendance module and shaped for that report; borrowing it here would be
     * a cross-module guess. This returns just what the picker needs, from the
     * same tenant.
     */
    public function learners(Request $request)
    {
        $authError = $this->validateToken($request);
        if ($authError) return $authError;

        $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id');

        if (!$subInstituteId) {
            return response()->json(['status' => false, 'message' => 'sub_institute_id is required'], 422);
        }

        $learners = DB::table('tbluser')
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('employee_no', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('first_name')
            ->limit(min(max((int) $request->input('limit', 50), 1), 200))
            ->get([
                'id',
                'employee_no',
                'email',
                DB::raw("TRIM(CONCAT_WS(' ', first_name, last_name)) as name"),
            ]);

        return response()->json(['status' => true, 'data' => $learners]);
    }

    /**
     * POST /api/lmsAssignment/import
     *
     * Bulk-create assignments from a CSV.
     *
     * Learner and course are each resolved from whichever identifier the file
     * happens to use, because export formats vary between HR systems:
     *   learner - employee_no | email | numeric tbluser id
     *   course  - subject_code | display_name | numeric sub_std_map id
     *
     * Every row is validated independently and reported back with its line
     * number, so one bad row never silently drops the rest of the file. Nothing
     * is written unless the whole file is importable, or skip_invalid=1 is sent.
     */
    public function import(Request $request)
    {
        $authError = $this->validateToken($request);
        if ($authError) return $authError;

        $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id');

        $validator = Validator::make(
            array_merge($request->all(), ['sub_institute_id' => $subInstituteId]),
            [
                'sub_institute_id' => 'required|integer',
                'file'             => 'required|file|mimes:csv,txt|max:5120',
                'assignment_type'  => 'nullable|string|max:100',
                'skip_invalid'     => 'nullable|boolean',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $rows = array_map('str_getcsv', file($request->file('file')->getRealPath()));
        $rows = array_values(array_filter($rows, fn ($r) => count(array_filter($r, fn ($v) => trim((string) $v) !== '')) > 0));

        if (count($rows) < 2) {
            return response()->json([
                'status' => false,
                'message' => 'The file needs a header row and at least one data row.',
            ], 422);
        }

        // Header is matched case/format-insensitively so "Employee No" and
        // "employee_no" both work.
        $header = array_map(
            fn ($h) => strtolower(trim(str_replace([' ', '-'], '_', (string) $h))),
            array_shift($rows)
        );

        $columnOf = function (array $candidates) use ($header) {
            foreach ($candidates as $candidate) {
                $index = array_search($candidate, $header, true);
                if ($index !== false) return $index;
            }
            return null;
        };

        $learnerCol = $columnOf(['employee_no', 'employee_number', 'emp_no', 'email', 'user_email', 'user_id', 'learner']);
        $courseCol  = $columnOf(['course_code', 'subject_code', 'course', 'course_name', 'course_id']);
        $dueCol     = $columnOf(['due_date', 'due', 'deadline']);
        $typeCol    = $columnOf(['assignment_type', 'type']);

        if ($learnerCol === null || $courseCol === null) {
            return response()->json([
                'status' => false,
                'message' => 'The file needs a learner column (employee_no, email or user_id) and a course column (course_code or course_name).',
                'data' => ['detected_headers' => $header],
            ], 422);
        }

        $defaultType = $request->input('assignment_type', 'Mandatory');
        $valid = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2; // +1 for the header, +1 for 1-based numbering.
            $learnerKey = trim((string) ($row[$learnerCol] ?? ''));
            $courseKey  = trim((string) ($row[$courseCol] ?? ''));

            if ($learnerKey === '' || $courseKey === '') {
                $errors[] = ['line' => $line, 'message' => 'Learner and course are both required.'];
                continue;
            }

            $user = DB::table('tbluser')
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($learnerKey) {
                    $q->where('employee_no', $learnerKey)
                      ->orWhere('email', $learnerKey);
                    if (ctype_digit($learnerKey)) $q->orWhere('id', (int) $learnerKey);
                })
                ->first(['id']);

            if (!$user) {
                $errors[] = ['line' => $line, 'message' => "No learner matches \"{$learnerKey}\"."];
                continue;
            }

            $course = DB::table('sub_std_map')
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($courseKey) {
                    $q->where('subject_code', $courseKey)
                      ->orWhere('display_name', $courseKey);
                    if (ctype_digit($courseKey)) $q->orWhere('id', (int) $courseKey);
                })
                ->first(['id']);

            if (!$course) {
                $errors[] = ['line' => $line, 'message' => "No course matches \"{$courseKey}\"."];
                continue;
            }

            $dueDate = null;
            if ($dueCol !== null && ($rawDue = trim((string) ($row[$dueCol] ?? ''))) !== '') {
                // Explicit formats first. Carbon reads an ambiguous slash date
                // as m/d/Y, which would silently turn 03/04/2027 into 4 March
                // instead of 3 April - day-first is the norm for this ERP, so
                // it is tried before falling back to Carbon's own guess.
                foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $format) {
                    $parsed = \DateTime::createFromFormat('!' . $format, $rawDue);
                    if ($parsed && $parsed->format($format) === $rawDue) {
                        $dueDate = $parsed->format('Y-m-d');
                        break;
                    }
                }

                if ($dueDate === null) {
                    try {
                        $dueDate = \Carbon\Carbon::parse($rawDue)->toDateString();
                    } catch (\Exception $e) {
                        $errors[] = ['line' => $line, 'message' => "Could not read the due date \"{$rawDue}\"."];
                        continue;
                    }
                }
            }

            // A learner already assigned to a course is reported, not duplicated.
            $alreadyAssigned = DB::table('lms_assignments')
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->whereNull('deleted_at')
                ->exists();

            if ($alreadyAssigned) {
                $errors[] = ['line' => $line, 'message' => 'This learner is already assigned to that course.'];
                continue;
            }

            $valid[] = [
                'user_id' => $user->id,
                'course_id' => $course->id,
                'assignment_type' => $typeCol !== null && trim((string) ($row[$typeCol] ?? '')) !== ''
                    ? trim((string) $row[$typeCol])
                    : $defaultType,
                'due_date' => $dueDate,
            ];
        }

        $skipInvalid = filter_var($request->input('skip_invalid', false), FILTER_VALIDATE_BOOLEAN);

        if (!empty($errors) && !$skipInvalid) {
            return response()->json([
                'status' => false,
                'message' => count($errors) . ' row(s) could not be imported. Nothing was saved.',
                'data' => ['valid_rows' => count($valid), 'errors' => $errors],
            ], 422);
        }

        if (empty($valid)) {
            return response()->json([
                'status' => false,
                'message' => 'No importable rows were found.',
                'data' => ['errors' => $errors],
            ], 422);
        }

        try {
            $assignedBy = $request->user_id
                ? (DB::table('tbluser')
                    ->where('id', $request->user_id)
                    ->selectRaw("TRIM(CONCAT_WS(' ', first_name, last_name)) as full_name")
                    ->value('full_name') ?: 'Admin')
                : 'Admin';

            $now = now();
            DB::table('lms_assignments')->insert(array_map(fn ($row) => $row + [
                'status' => 'Not Started',
                'progress' => 0,
                'assigned_by' => $assignedBy,
                'assigned_on' => $now,
                'sub_institute_id' => $subInstituteId,
                'created_at' => $now,
                'updated_at' => $now,
            ], $valid));

            return response()->json([
                'status' => true,
                'message' => count($valid) . ' assignment(s) imported'
                    . (empty($errors) ? '.' : ', ' . count($errors) . ' row(s) skipped.'),
                'data' => ['imported' => count($valid), 'skipped' => count($errors), 'errors' => $errors],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to import the assignments',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
