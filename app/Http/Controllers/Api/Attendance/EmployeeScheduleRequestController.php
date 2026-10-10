<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Api\Attendance\Concerns\ResolvesAttendanceContext;
use App\Http\Controllers\Api\Leave\Concerns\ResolvesLeaveAuthority;
use App\Http\Controllers\Controller;
use App\Services\Attendance\RosterProvenance;
use App\Services\Events\EventRecorder;
use App\Support\RoleKey;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * An employee proposing their own working days and hours, and HR deciding.
 *
 * ── WHY THIS IS A REQUEST AND NOT A SETTING ─────────────────────────────────
 *
 * Office hours live as 21 columns on `tbluser` - seven weekday flags and
 * fourteen `time` columns - and they are a PAYROLL INPUT.
 * `PayrollController::getSaturdayLateCount()` reads `saturday_in_date` to count
 * 2nd-Saturday lateness, and lateness is subtracted from payable days.
 *
 * So an employee writing those columns directly would be an employee adjusting
 * an input to their own pay. Nothing here touches `tbluser` until somebody with
 * the authority to decide approves it.
 *
 * ── THE SELF-SERVICE SHAPE THIS MODULE SETTLED ON ───────────────────────────
 *
 * A separate menu row plus an endpoint that resolves its own subject. `store()`
 * and `mine()` take NO employee id at all - a client that cannot express the
 * wrong request cannot send it - which is what makes them safe for every
 * employee to reach with no role gate. The same split as My Leave Requests vs
 * Leave Approvals, and the same one `AttendanceRegularisationApiController`
 * uses, whose lifecycle this is modelled on almost method for method.
 *
 * ── TWO AUTHORITIES, NOT ONE ────────────────────────────────────────────────
 *
 * Deciding needs BOTH:
 *
 *   `approve_leave` from hrms_leave_role_permissions  - for the SCOPE
 *                     (Self / Team / Department / Organization)
 *   admin or hr from RoleKey                          - for the AUTHORITY
 *
 * The scope matrix is reused rather than adding an `approve_attendance` column,
 * for the reason `AttendanceRegularisationApiController` records at lines 23-39:
 * a column no screen can set is the NOT-WIRED defect this whole remediation
 * exists to remove - it would look like configuration and control nothing.
 *
 * But `approve_leave` alone is too wide here. Reporting Manager and Department
 * Head hold it in some tenants, and approving a leave day is not the same act
 * as changing somebody's standing working week, which moves a payroll input
 * every month from now on. So the role gate narrows it.
 *
 * ── RETROACTIVE, AND SAID OUT LOUD ──────────────────────────────────────────
 *
 * `tbluser`'s roster is not effective-dated. Approving "I work 10:00-19:00"
 * today changes how LAST month's lateness is computed, because
 * PayrollController reads the current column when it scores a past day. Fixing
 * that properly needs an effective-dated roster table and is its own piece of
 * work. `applied_at` is recorded and the event carries the before-image, so the
 * change is at least explainable when the next payroll run surfaces it.
 */
class EmployeeScheduleRequestController extends Controller
{
    use ResolvesAttendanceContext;
    use ResolvesLeaveAuthority;

    /** 'monday'..'sunday' - the tbluser column prefix. See WEEKDAYS below. */
    private const WEEKDAYS = [
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
    ];

    /** Who may decide, on top of the leave scope. */
    private const DECIDERS = ['admin', 'hr'];

    /* ====================================================================== */
    /* The employee's own view                                                 */
    /* ====================================================================== */

    /**
     * GET /api/attendance/my-office-hours
     *
     * Everything the self-service screen needs in ONE response: the employee's
     * current hours, their department's template, anything pending, and what
     * has been decided before.
     *
     * One call rather than three because two of the three an employee may not
     * be permitted to make - `/employees-management/{id}` is gated
     * `profile:admin,hr`, so an employee cannot read their own `tbluser`
     * schedule through it.
     *
     * NO SUBJECT PARAMETER. The subject is the token owner, always.
     */
    public function mine(Request $request)
    {
        $context = $this->attendanceContext($request);

        if (!is_array($context)) {
            return $context;
        }

        $userId   = (int) $context['user_id'];
        $tenantId = (int) $context['sub_institute_id'];

        $employee = DB::table('tbluser')
            ->where('id', $userId)
            ->where('sub_institute_id', $tenantId)
            ->first();

        if (!$employee) {
            return response()->json(['status' => 0, 'message' => 'Employee not found'], 404);
        }

        $current      = $this->weekFromEmployee($employee);
        $departmentId = $employee->department_id ? (int) $employee->department_id : null;

        return response()->json([
            'status' => 1,
            'data'   => [
                'current' => $current,
                /*
                 * False when no weekday has ever been set - which is the state
                 * 2,008 of 2,283 active employees are in, and a different thing
                 * from "set, and not a working day". The screen says so in those
                 * words rather than showing an empty week as though it were a
                 * choice somebody made.
                 */
                'has_schedule'    => collect($current)->contains(fn ($d) => $d['is_working'] !== null),
                'department_id'   => $departmentId,
                'department_name' => $departmentId
                    ? DB::table('hrms_departments')->where('id', $departmentId)->value('department')
                    : null,
                'template'        => $departmentId ? $this->departmentTemplate($tenantId, $departmentId) : null,
                'pending'         => $this->pendingFor($tenantId, $userId),
                'history'         => $this->historyFor($tenantId, $userId),
            ],
        ]);
    }

    /* ====================================================================== */
    /* Raise / withdraw                                                        */
    /* ====================================================================== */

    /**
     * POST /api/attendance/office-hours-requests
     *
     * Always for the caller. Only the weekdays being changed are sent; an
     * omitted weekday is left exactly as it is, which is why the week is stored
     * as a row per weekday rather than 21 columns - an absent row means "not
     * asked about", and a NULL column cannot say that.
     *
     * Re-submitting while one is pending REPLACES it rather than creating a
     * second, matching the rule the regularisation path already follows: an
     * approver should never be shown two contradictory versions of one week.
     */
    public function store(Request $request)
    {
        $context = $this->attendanceContext($request);

        if (!is_array($context)) {
            return $context;
        }

        $userId   = (int) $context['user_id'];
        $tenantId = (int) $context['sub_institute_id'];

        $validator = Validator::make($request->all(), [
            'week'              => 'required|array|min:1|max:7',
            'week.*.weekday'    => 'required|string|in:' . implode(',', self::WEEKDAYS),
            'week.*.is_working' => 'required|boolean',
            'week.*.in_time'    => 'nullable|date_format:H:i',
            'week.*.out_time'   => 'nullable|date_format:H:i',
            'reason'            => 'required|string|max:255',
        ], [
            'reason.required' => 'A reason is required.',
        ]);

        $validator->after(function ($validator) use ($request) {
            $seen = [];

            foreach ((array) $request->input('week', []) as $index => $entry) {
                $weekday = $entry['weekday'] ?? null;

                // One opinion per weekday. Two rows for Saturday would hit the
                // unique index and 500; refusing it here says why.
                if ($weekday !== null && isset($seen[$weekday])) {
                    $validator->errors()->add("week.$index.weekday", 'Each weekday can only appear once.');
                }
                $seen[$weekday] = true;

                $in  = $entry['in_time'] ?? null;
                $out = $entry['out_time'] ?? null;

                /*
                 * Equal is rejected too: a zero-length day is not a request. And
                 * out < in would be an overnight shift, which this form cannot
                 * express - refused with a reason rather than stored and
                 * mis-costed, because AttendanceCorrector stores a NULL duration
                 * for a negative span and that then reads as "no hours worked".
                 */
                if ($in && $out && $out <= $in) {
                    $validator->errors()->add(
                        "week.$index.out_time",
                        ucfirst((string) $weekday) . ': the finish time has to be after the start time.',
                    );
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $employee = DB::table('tbluser')
            ->where('id', $userId)
            ->where('sub_institute_id', $tenantId)
            ->first();

        if (!$employee) {
            return response()->json(['status' => 0, 'message' => 'Employee not found'], 404);
        }

        $week = $request->input('week');

        $requestId = DB::transaction(function () use ($week, $request, $employee, $userId, $tenantId) {
            /*
             * Replace rather than add.
             *
             * Soft-deleted, not removed: an employee changing their mind is
             * itself a fact, and a hard delete makes "did you ever ask for this"
             * unanswerable.
             */
            DB::table('hrms_employee_schedule_requests')
                ->where('sub_institute_id', $tenantId)
                ->where('user_id', $userId)
                ->where('status', 'pending')
                ->whereNull('deleted_at')
                ->update([
                    'status'     => 'cancelled',
                    'deleted_at' => now(),
                    'deleted_by' => $userId,
                    'updated_at' => now(),
                ]);

            $id = DB::table('hrms_employee_schedule_requests')->insertGetId([
                'sub_institute_id' => $tenantId,
                'user_id'          => $userId,
                'department_id'    => $employee->department_id ? (int) $employee->department_id : null,
                'reason'           => $request->input('reason'),
                'status'           => 'pending',
                'created_by'       => $userId,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            foreach ($week as $entry) {
                $weekday = $entry['weekday'];

                /*
                 * The before-image, captured NOW rather than derived at approval
                 * time. By the time an approver looks, `tbluser` may have moved
                 * under it through a department apply or an HR edit - and showing
                 * the approver a diff the employee never saw is worse than
                 * showing none.
                 */
                DB::table('hrms_employee_schedule_request_days')->insert([
                    'request_id'         => $id,
                    'weekday'            => $weekday,
                    'is_working'         => !empty($entry['is_working']) ? 1 : 0,
                    'in_time'            => $entry['in_time'] ?: null,
                    'out_time'           => $entry['out_time'] ?: null,
                    'current_is_working' => $employee->{$weekday} === null ? null : (int) $employee->{$weekday},
                    'current_in_time'    => $employee->{$weekday . '_in_date'} ?: null,
                    'current_out_time'   => $employee->{$weekday . '_out_date'} ?: null,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);
            }

            return $id;
        });

        $this->recordEvent('attendance.office_hours.requested', $tenantId, $requestId, $userId, [
            'employee_id' => $userId,
            'weekdays'    => array_column($week, 'weekday'),
            'reason'      => $request->input('reason'),
        ]);

        return response()->json([
            'status'  => 1,
            'message' => 'Your office hours request has been submitted. Nothing changes until HR approves it.',
            'data'    => ['id' => $requestId],
        ], 201);
    }

    /**
     * DELETE /api/attendance/office-hours-requests/{id}
     *
     * Withdraw my own pending request. Soft-deleted, so the trail survives.
     */
    public function destroy(Request $request, $id)
    {
        $context = $this->attendanceContext($request);

        if (!is_array($context)) {
            return $context;
        }

        $userId   = (int) $context['user_id'];
        $tenantId = (int) $context['sub_institute_id'];

        // Scoped to the caller's own row, so there is no "whose request is this"
        // question to get wrong - an id belonging to somebody else simply does
        // not match.
        $affected = DB::table('hrms_employee_schedule_requests')
            ->where('id', (int) $id)
            ->where('sub_institute_id', $tenantId)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->whereNull('deleted_at')
            ->update([
                'status'     => 'cancelled',
                'deleted_at' => now(),
                'deleted_by' => $userId,
                'updated_at' => now(),
            ]);

        if (!$affected) {
            return response()->json([
                'status'  => 0,
                'message' => 'No pending request of yours with that id.',
            ], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Request withdrawn.']);
    }

    /* ====================================================================== */
    /* The approver's queue                                                    */
    /* ====================================================================== */

    /**
     * GET /api/attendance/office-hours-requests
     *
     * `scope=mine` (the default) is the caller's own. `scope=team` is the
     * approver queue and is refused for anybody who may not decide - so a
     * component can ask for it and render nothing on 403, rather than gating
     * itself on a role it guessed at.
     */
    public function index(Request $request)
    {
        $context = $this->attendanceContext($request);

        if (!is_array($context)) {
            return $context;
        }

        $userId   = (int) $context['user_id'];
        $tenantId = (int) $context['sub_institute_id'];
        $wantsQueue = $request->input('scope') === 'team';

        $query = DB::table('hrms_employee_schedule_requests as r')
            ->leftJoin('tbluser as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('tbluser as reviewer', 'reviewer.id', '=', 'r.reviewed_by')
            ->leftJoin('hrms_departments as d', 'd.id', '=', 'r.department_id')
            ->where('r.sub_institute_id', $tenantId)
            ->whereNull('r.deleted_at');

        if ($wantsQueue) {
            if ($denied = $this->denyQueueAccess($context)) {
                return $denied;
            }

            // Separation of duties: an approver does not decide their own.
            $query->where('r.user_id', '!=', $userId);

            $scoped = $this->leaveScopeUserIds($context);

            // null means the whole tenant - Organization scope - so no narrowing.
            if ($scoped !== null) {
                $query->whereIn('r.user_id', $scoped);
            }
        } else {
            $query->where('r.user_id', $userId);
        }

        if ($request->filled('status')) {
            $query->where('r.status', $request->input('status'));
        }

        $rows = $query
            ->orderByDesc('r.created_at')
            ->limit(200)
            ->get([
                'r.id', 'r.user_id', 'r.department_id', 'r.reason', 'r.status',
                'r.reviewer_comment', 'r.reviewed_at', 'r.applied_at', 'r.created_at',
                'd.department as department_name',
                DB::raw("CONCAT_WS(' ', u.first_name, u.last_name) as employee_name"),
                DB::raw("CONCAT_WS(' ', u.employee_no, u.employee_id) as employee_no"),
                DB::raw("CONCAT_WS(' ', reviewer.first_name, reviewer.last_name) as reviewed_by_name"),
            ]);

        $days = $this->daysFor($rows->pluck('id')->map(fn ($i) => (int) $i)->all());

        return response()->json([
            'status' => 1,
            'scope'  => $wantsQueue ? 'team' : 'mine',
            'count'  => $rows->count(),
            'data'   => $rows->map(fn ($row) => $this->transform($row, $days))->all(),
        ]);
    }

    /**
     * POST /api/attendance/office-hours-requests/{id}/decision
     *
     * An approval is the only thing in this feature that writes `tbluser`.
     */
    public function decision(Request $request, $id)
    {
        $context = $this->attendanceContext($request);

        if (!is_array($context)) {
            return $context;
        }

        $actorId  = (int) $context['user_id'];
        $tenantId = (int) $context['sub_institute_id'];

        $validator = Validator::make($request->all(), [
            'status'           => 'required|in:approved,rejected',
            'reviewer_comment' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        if ($denied = $this->denyQueueAccess($context)) {
            return $denied;
        }

        $row = DB::table('hrms_employee_schedule_requests')
            ->where('id', (int) $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();

        if (!$row) {
            // 404 rather than 403 - a refusal should not confirm that a request
            // exists in somebody else's organisation.
            return response()->json(['status' => 0, 'message' => 'Request not found.'], 404);
        }

        if ((int) $row->user_id === $actorId) {
            return response()->json([
                'status'  => 0,
                'message' => 'You cannot decide your own office hours request.',
            ], 403);
        }

        if (!$this->leaveSubjectInScope($context, (int) $row->user_id)) {
            return response()->json([
                'status'  => 0,
                'message' => 'That employee is outside the scope you may approve for.',
            ], 403);
        }

        if ($row->status !== 'pending') {
            // Already decided. 409, not 422: the request is well formed and the
            // refusal is about the state of the resource.
            return response()->json([
                'status'  => 0,
                'message' => 'This request has already been ' . $row->status . '.',
            ], 409);
        }

        $decision = $request->input('status');
        $days = DB::table('hrms_employee_schedule_request_days')
            ->where('request_id', $row->id)
            ->get();

        if ($decision === 'rejected') {
            DB::table('hrms_employee_schedule_requests')->where('id', $row->id)->update([
                'status'           => 'rejected',
                'reviewer_comment' => $request->input('reviewer_comment'),
                'reviewed_by'      => $actorId,
                'reviewed_at'      => now(),
                'updated_by'       => $actorId,
                'updated_at'       => now(),
            ]);

            $this->recordEvent('attendance.office_hours.decided', $tenantId, (int) $row->id, $actorId, [
                'employee_id' => (int) $row->user_id,
                'decision'    => 'rejected',
                'comment'     => $request->input('reviewer_comment'),
            ]);

            return response()->json([
                'status'  => 1,
                'message' => 'Request rejected. Nothing was changed.',
            ]);
        }

        /* ---------------- the approval: the only tbluser write ---------------- */

        $before   = [];
        $columns  = [];
        $weekdays = [];

        foreach ($days as $day) {
            $weekday    = $day->weekday;
            $weekdays[] = $weekday;

            $before[$weekday] = [
                'is_working' => $day->current_is_working === null ? null : (int) $day->current_is_working,
                'in_time'    => $day->current_in_time,
                'out_time'   => $day->current_out_time,
            ];

            $columns[$weekday] = (int) $day->is_working;

            /*
             * A working day with no hours writes the FLAG only.
             *
             * Writing null into the time columns would clear whatever hours the
             * employee already had and read downstream as midnight - turning a
             * roster change into a pay change nobody asked for. Same rule
             * DepartmentScheduleController follows for its template.
             */
            if ($day->in_time !== null || $day->out_time !== null) {
                $columns[$weekday . '_in_date']  = $day->in_time;
                $columns[$weekday . '_out_date'] = $day->out_time;
            }
        }

        DB::transaction(function () use ($row, $columns, $weekdays, $actorId, $tenantId, $request) {
            DB::table('tbluser')
                ->where('id', $row->user_id)
                // Scoped on the tenant as well as the id. The id alone is enough
                // today, but this is the write that touches somebody else's row.
                ->where('sub_institute_id', $tenantId)
                ->update($columns + ['updated_at' => now()]);

            DB::table('hrms_employee_schedule_requests')->where('id', $row->id)->update([
                'status'           => 'approved',
                'reviewer_comment' => $request->input('reviewer_comment'),
                'reviewed_by'      => $actorId,
                'reviewed_at'      => now(),
                'applied_at'       => now(),
                'updated_by'       => $actorId,
                'updated_at'       => now(),
            ]);

            /*
             * Stamp provenance INSIDE the transaction.
             *
             * This is what stops a later "Apply to department" flattening the
             * hours this employee just had approved. If the stamp committed
             * separately and failed, the roster would say one thing and the
             * protection would not exist - which is the silent half of the bug
             * that got the previous shift feature deleted.
             */
            app(RosterProvenance::class)->record(
                $tenantId,
                (int) $row->user_id,
                $weekdays,
                RosterProvenance::EMPLOYEE_REQUEST,
                (int) $row->id,
                $actorId,
            );
        });

        $this->recordEvent('attendance.office_hours.decided', $tenantId, (int) $row->id, $actorId, [
            'employee_id' => (int) $row->user_id,
            'decision'    => 'approved',
            'weekdays'    => $weekdays,
            'applied'     => $columns,
            'before'      => $before,
            'comment'     => $request->input('reviewer_comment'),
            /*
             * Said in the payload because it will be asked about.
             *
             * tbluser's roster is not effective-dated, so this changes how PAST
             * days are scored too - PayrollController reads the current column
             * when it counts 2nd-Saturday lateness.
             */
            'note'        => 'Roster is not effective-dated: this also changes how earlier days are scored.',
        ]);

        return response()->json([
            'status'  => 1,
            'message' => 'Approved. The employee\'s office hours have been updated.',
        ]);
    }

    /* ====================================================================== */
    /* Shared                                                                  */
    /* ====================================================================== */

    /**
     * Both authorities, in the order that gives the clearest refusal.
     *
     * The role check first: "you may not approve these" is a more useful answer
     * than a scope error for somebody who was never going to be allowed.
     */
    private function denyQueueAccess(array $context)
    {
        if (!RoleKey::satisfies(RoleKey::forUserId((int) $context['user_id']), self::DECIDERS)) {
            return response()->json([
                'status'  => 0,
                'message' => 'Deciding office hours requests is limited to HR and Administrator roles.',
            ], 403);
        }

        return $this->denyUnlessLeaveCan(
            $context,
            'approve_leave',
            'You do not have approval rights configured.',
        );
    }

    /** The 21 tbluser columns as a week. `is_working: null` means never set. */
    private function weekFromEmployee(object $employee): array
    {
        $week = [];

        foreach (self::WEEKDAYS as $weekday) {
            $flag = $employee->{$weekday} ?? null;

            $week[] = [
                'weekday'    => $weekday,
                'is_working' => $flag === null ? null : ((int) $flag === 1),
                'in_time'    => $this->clock($employee->{$weekday . '_in_date'} ?? null),
                'out_time'   => $this->clock($employee->{$weekday . '_out_date'} ?? null),
            ];
        }

        return $week;
    }

    /** The department's saved template, in the same shape as a week. */
    private function departmentTemplate(int $tenantId, int $departmentId): ?array
    {
        $rows = DB::table('hrms_department_schedules')
            ->where('sub_institute_id', $tenantId)
            ->where('department_id', $departmentId)
            ->get()
            ->keyBy('weekday');

        if ($rows->isEmpty()) {
            return null;
        }

        $week = [];

        foreach (self::WEEKDAYS as $weekday) {
            $row = $rows[$weekday] ?? null;

            $week[] = [
                'weekday'    => $weekday,
                'is_working' => $row ? ((int) $row->is_working === 1) : null,
                'in_time'    => $this->clock($row->in_time ?? null),
                'out_time'   => $this->clock($row->out_time ?? null),
            ];
        }

        return $week;
    }

    private function pendingFor(int $tenantId, int $userId): ?array
    {
        $row = DB::table('hrms_employee_schedule_requests as r')
            ->leftJoin('tbluser as reviewer', 'reviewer.id', '=', 'r.reviewed_by')
            ->where('r.sub_institute_id', $tenantId)
            ->where('r.user_id', $userId)
            ->where('r.status', 'pending')
            ->whereNull('r.deleted_at')
            ->orderByDesc('r.id')
            ->first([
                'r.id', 'r.user_id', 'r.department_id', 'r.reason', 'r.status',
                'r.reviewer_comment', 'r.reviewed_at', 'r.applied_at', 'r.created_at',
                DB::raw("CONCAT_WS(' ', reviewer.first_name, reviewer.last_name) as reviewed_by_name"),
            ]);

        return $row ? $this->transform($row, $this->daysFor([(int) $row->id])) : null;
    }

    /** @return array<int, array> the last few decided requests, newest first */
    private function historyFor(int $tenantId, int $userId): array
    {
        $rows = DB::table('hrms_employee_schedule_requests as r')
            ->leftJoin('tbluser as reviewer', 'reviewer.id', '=', 'r.reviewed_by')
            ->where('r.sub_institute_id', $tenantId)
            ->where('r.user_id', $userId)
            ->whereIn('r.status', ['approved', 'rejected'])
            ->orderByDesc('r.id')
            ->limit(5)
            ->get([
                'r.id', 'r.user_id', 'r.department_id', 'r.reason', 'r.status',
                'r.reviewer_comment', 'r.reviewed_at', 'r.applied_at', 'r.created_at',
                DB::raw("CONCAT_WS(' ', reviewer.first_name, reviewer.last_name) as reviewed_by_name"),
            ]);

        $days = $this->daysFor($rows->pluck('id')->map(fn ($i) => (int) $i)->all());

        return $rows->map(fn ($row) => $this->transform($row, $days))->all();
    }

    /**
     * Every request's weekdays, in one query.
     *
     * The same device DepartmentScheduleController::index() uses - a list screen
     * would otherwise run a query per row.
     *
     * @param  int[] $requestIds
     * @return array<int, array<int, object>>
     */
    private function daysFor(array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }

        return DB::table('hrms_employee_schedule_request_days')
            ->whereIn('request_id', $requestIds)
            ->orderBy('id')
            ->get()
            ->groupBy('request_id')
            ->map(fn ($group) => $group->all())
            ->all();
    }

    /**
     * One request, with every field cast on the way out.
     *
     * `PDO::ATTR_EMULATE_PREPARES` makes the driver return every column as a
     * string, so without this an id arrives as "412" and a boolean as "0" -
     * and "0" is truthy in JavaScript. That exact crossing is what made the
     * Change History screen render every row wrong for a month.
     */
    private function transform(object $row, array $daysByRequest): array
    {
        $days = $daysByRequest[(int) $row->id] ?? [];

        $week = [];
        $current = [];

        foreach ($days as $day) {
            $week[] = [
                'weekday'    => $day->weekday,
                'is_working' => (bool) (int) $day->is_working,
                'in_time'    => $this->clock($day->in_time),
                'out_time'   => $this->clock($day->out_time),
            ];

            $current[] = [
                'weekday'    => $day->weekday,
                'is_working' => $day->current_is_working === null
                    ? null
                    : ((int) $day->current_is_working === 1),
                'in_time'    => $this->clock($day->current_in_time),
                'out_time'   => $this->clock($day->current_out_time),
            ];
        }

        return [
            'id'               => (int) $row->id,
            'user_id'          => (int) $row->user_id,
            'employee_name'    => trim((string) ($row->employee_name ?? '')) ?: null,
            'employee_no'      => trim((string) ($row->employee_no ?? '')) ?: null,
            'department_id'    => $row->department_id !== null ? (int) $row->department_id : null,
            'department_name'  => $row->department_name ?? null,
            'week'             => $week,
            'current_week'     => $current,
            // Filled in by mine(); the queue does not need it per row.
            'template_week'    => null,
            'reason'           => (string) $row->reason,
            'status'           => (string) $row->status,
            'reviewer_comment' => $row->reviewer_comment,
            'reviewed_at'      => $row->reviewed_at,
            'reviewed_by_name' => trim((string) ($row->reviewed_by_name ?? '')) ?: null,
            'submitted_at'     => $row->created_at,
            'applied_at'       => $row->applied_at,
        ];
    }

    /** "09:15:00" or "09:15" to "09:15". Null stays null. */
    private function clock($value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return preg_match('/(\d{1,2}):(\d{2})/', (string) $value, $m)
            ? str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2]
            : null;
    }

    /**
     * Record a platform event, after the work and never instead of it.
     *
     * Keyed on the request id and the event type, so a retry is absorbed while
     * two genuinely different events are both kept. g2g_event.idempotency_key is
     * UNIQUE and EventRecorder does no checking of its own - the database
     * rejects a duplicate and this catch swallows it - so the key's precision is
     * the only thing standing between a second event and a lost record.
     */
    private function recordEvent(string $type, int $tenantId, int $requestId, int $actorId, array $payload): void
    {
        try {
            app(EventRecorder::class)->record(
                $type,
                $tenantId,
                'hrms_employee_schedule_requests',
                $requestId,
                $actorId,
                $payload,
                null,
                $type . ':' . $requestId . ':' . ($payload['decision'] ?? 'raised'),
            );
        } catch (\Throwable $caught) {
            Log::warning('Could not record ' . $type, ['request_id' => $requestId, 'error' => $caught->getMessage()]);
        }
    }
}
