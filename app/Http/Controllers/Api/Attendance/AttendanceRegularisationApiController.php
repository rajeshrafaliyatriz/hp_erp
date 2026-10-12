<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Services\Attendance\AttendanceCorrector;
use App\Http\Controllers\Api\Attendance\Concerns\ResolvesAttendanceContext;
use App\Http\Controllers\Api\Leave\Concerns\ResolvesLeaveAuthority;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Attendance regularisation: request -> approve/reject -> apply. F-107.
 *
 * The correction itself already existed - HrmsController@updateUserAttendance,
 * routed as POST update_user_att - and nothing called it. What was missing was
 * everything around it: a way for an employee to ask, a queue for the approver,
 * and a record of who decided what.
 *
 * ON AUTHORITY, AND A DELIBERATE REUSE.
 *
 * Approving a colleague's attendance correction is the same kind of act as
 * approving their leave: it needs a person with reach over that employee. That
 * reach is already configured, per tenant, in hrms_leave_role_permissions -
 * `approve_leave` plus a scope of Self / Team / Department / Organization -
 * and Sprint 1 made it load-bearing.
 *
 * This controller reuses it rather than adding an `approve_attendance` column.
 * A new column would need a checkbox on the Roles & Access tab to be settable,
 * and shipping a column no screen can set is precisely the NOT-WIRED defect
 * this remediation exists to remove: it would look like configuration and
 * control nothing. When that tab is reworked (Sprint 5) attendance gets its own
 * column, and this comment is the reason to look for it.
 *
 * Until then the rule is stated plainly in one place: an approver is someone
 * the tenant has already trusted to approve absence.
 */
class AttendanceRegularisationApiController extends Controller
{
    use ResolvesAttendanceContext;
    use ResolvesLeaveAuthority;

    private const DECISIONS = ['approved', 'rejected'];

    /**
     * GET /api/attendance/regularisations
     *
     * `scope=mine`  the caller's own requests (the default)
     * `scope=team`  the queue of requests the caller may decide
     */
    public function index(Request $request)
    {
        $context = $this->attendanceContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $wantsQueue = $request->input('scope') === 'team';

        $query = DB::table('hrms_attendance_regularisations as r')
            ->join('tbluser as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('hrms_departments as hd', 'hd.id', '=', 'u.department_id')
            ->where('r.sub_institute_id', $context['sub_institute_id'])
            ->whereNull('r.deleted_at');

        if ($wantsQueue) {
            if ($denied = $this->denyUnlessLeaveCan($context, 'approve_leave', 'You do not have permission to review attendance corrections.')) {
                return $denied;
            }

            // The approver's reach, and never their own request - the same
            // separation of duties the leave decision enforces.
            $this->applyLeaveScope($query, $context, 'r.user_id');
            $query->where('r.user_id', '!=', $context['user_id']);
        } else {
            $query->where('r.user_id', $context['user_id']);
        }

        if ($status = $request->input('status')) {
            $query->where('r.status', $status);
        }

        $rows = $query
            ->orderByRaw("FIELD(r.status, 'pending') DESC")
            ->orderByDesc('r.day')
            ->limit(min(max((int) $request->input('limit', 100), 1), 500))
            ->get([
                'r.*',
                DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS employee_name"),
                'u.employee_no',
                DB::raw("COALESCE(hd.department, '') AS department"),
            ]);

        return response()->json([
            'status'  => 1,
            'message' => 'Regularisation requests fetched successfully',
            'scope'   => $wantsQueue ? 'team' : 'mine',
            'count'   => $rows->count(),
            'data'    => $rows->map(fn ($row) => $this->transform($row)),
        ]);
    }

    /**
     * POST /api/attendance/regularisations
     *
     * Always for the caller. Correcting somebody else's attendance directly is
     * an administrative act with its own legacy endpoint; this is self-service.
     */
    public function store(Request $request)
    {
        $context = $this->attendanceContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'day'                 => 'required|date|before_or_equal:today',
            'requested_in_time'   => 'nullable|date_format:H:i',
            'requested_out_time'  => 'nullable|date_format:H:i',
            'reason'              => 'required|string|max:255',
        ], [
            'day.before_or_equal' => 'You can only regularise a day that has already happened.',
            'reason.required'     => 'A reason is required.',
        ]);

        $validator->after(function ($validator) use ($request) {
            if (!$request->input('requested_in_time') && !$request->input('requested_out_time')) {
                $validator->errors()->add('requested_in_time', 'Give a corrected punch-in time, a punch-out time, or both.');
            }

            $in  = $request->input('requested_in_time');
            $out = $request->input('requested_out_time');

            // Equal is rejected too: a zero-length day is not a correction.
            // Out < in is allowed only as an overnight shift, which this form
            // does not currently express, so it is refused with a clear reason
            // rather than silently stored and mis-costed by payroll.
            if ($in && $out && $out <= $in) {
                $validator->errors()->add('requested_out_time', 'Punch-out must be later than punch-in.');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $userId = (int) $context['user_id'];
        $day    = Carbon::parse($request->input('day'))->toDateString();

        // What is on the attendance row today, captured so the approver sees
        // the before/after and the trail survives later edits.
        $existing = DB::table('hrms_attendances')
            ->where('user_id', $userId)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->whereDate('day', $day)
            ->first(['punchin_time', 'punchout_time']);

        $payload = [
            'sub_institute_id'   => $context['sub_institute_id'],
            'user_id'            => $userId,
            'day'                => $day,
            'requested_in_time'  => $request->input('requested_in_time'),
            'requested_out_time' => $request->input('requested_out_time'),
            'original_in_time'   => $existing && $existing->punchin_time ? Carbon::parse($existing->punchin_time)->format('H:i:s') : null,
            'original_out_time'  => $existing && $existing->punchout_time ? Carbon::parse($existing->punchout_time)->format('H:i:s') : null,
            'reason'             => $request->input('reason'),
            'status'             => 'pending',
            'updated_at'         => now(),
            'updated_by'         => $userId,
        ];

        // One open request per employee per day: a second ask edits the first.
        // Without this an approver sees three contradictory versions of one
        // morning and any of them can be applied.
        $open = DB::table('hrms_attendance_regularisations')
            ->where('user_id', $userId)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereDate('day', $day)
            ->where('status', 'pending')
            ->whereNull('deleted_at')
            ->first(['id']);

        if ($open) {
            DB::table('hrms_attendance_regularisations')->where('id', $open->id)->update($payload);

            return response()->json([
                'status'  => 1,
                'message' => 'Your pending request for this day was updated.',
                'data'    => ['id' => (int) $open->id],
            ]);
        }

        $payload['created_at'] = now();
        $payload['created_by'] = $userId;
        $id = DB::table('hrms_attendance_regularisations')->insertGetId($payload);

        // Freeze a platform chain onto this request if the tenant has one
        // configured for hrms.attendance.regularisation. Opens nothing — and
        // changes nothing about what happens next — when there is no active
        // chain, which is every tenant until one configures this point.
        app(\App\Services\Attendance\AttendanceRegularisationApprovalWorkflow::class)
            ->openFor((int) $id, (int) $context['sub_institute_id']);

        return response()->json([
            'status'  => 1,
            'message' => 'Regularisation request submitted.',
            'data'    => ['id' => (int) $id],
        ], 201);
    }

    /**
     * POST /api/attendance/regularisations/{id}/decision
     *
     * Approving APPLIES the correction to hrms_attendances in the same
     * transaction that records the decision. The two must not be able to
     * disagree - an approved request whose correction never landed is exactly
     * the kind of silent half-write this remediation is cleaning up elsewhere.
     */
    public function decision(Request $request, $id)
    {
        $context = $this->attendanceContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'status'           => 'required|in:' . implode(',', self::DECISIONS),
            'reviewer_comment' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        if ($denied = $this->denyUnlessLeaveCan($context, 'approve_leave', 'You do not have permission to review attendance corrections.')) {
            return $denied;
        }

        $row = DB::table('hrms_attendance_regularisations')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();

        if (!$row) {
            return response()->json(['status' => 0, 'message' => 'Regularisation request not found'], 404);
        }

        // Out of scope reads as not-found: a 403 would confirm the request exists.
        if (!$this->leaveSubjectInScope($context, (int) $row->user_id)) {
            return response()->json(['status' => 0, 'message' => 'Regularisation request not found'], 404);
        }

        if ((int) $row->user_id === (int) $context['user_id']) {
            return response()->json([
                'status'  => 0,
                'message' => 'You cannot decide your own regularisation request.',
            ], 403);
        }

        if ($row->status !== 'pending') {
            return response()->json([
                'status'  => 0,
                'message' => 'This request has already been ' . $row->status . '.',
            ], 422);
        }

        $decision = $request->input('status');

        /*
         * ROUND 4. CHAIN-ENFORCED WHEN A PLATFORM CHAIN WAS OPEN AT SUBMISSION.
         *
         * The permission check above (`approve_leave` + scope) is UNCHANGED and
         * still gates every decision — a chain never widens who may act, only
         * narrows it further to whichever step is currently open. A request
         * submitted before this tenant configured a chain (or for a tenant that
         * never has) has no steps at all, and falls straight through to the
         * original single-decision behaviour below, unchanged.
         */
        $workflow = app(\App\Services\Attendance\AttendanceRegularisationApprovalWorkflow::class);
        $steps = $workflow->stepsFor((int) $row->id);

        if ($steps !== []) {
            $current = $workflow->currentStep((int) $row->id);

            if ($current === null) {
                // Defensive: a pending request with a chain that has no pending
                // step is a data inconsistency, not a normal outcome — the
                // status guard above already refused anything not 'pending'.
                return response()->json([
                    'status'  => 0,
                    'message' => 'This request\'s approval chain is in an inconsistent state. Contact an administrator.',
                ], 422);
            }

            $actorRoleKey = \App\Support\RoleKey::forUserId((int) $context['user_id']);

            if (! $workflow->roleMayDecide($current, $actorRoleKey, (int) $context['user_id'])) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'You are not the approver for this step.',
                ], 403);
            }

            $progress = $workflow->recordDecision(
                (int) $row->id,
                $current,
                $decision,
                ['user_id' => (int) $context['user_id']],
                $request->input('reviewer_comment')
            );

            if (! empty($progress['conflict'])) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Somebody else just decided this step. Refresh and try again.',
                ], 409);
            }

            if (! $progress['final']) {
                return response()->json([
                    'status'  => 1,
                    'message' => "Step {$progress['step']} of {$progress['of']} decided. Now awaiting {$progress['next']}.",
                ]);
            }

            // The chain finished on this decision — apply the same status +
            // correction path the unenforced flow below always has.
            $correction = null;

            DB::transaction(function () use ($row, $decision, $request, $context, &$correction) {
                DB::table('hrms_attendance_regularisations')->where('id', $row->id)->update([
                    'status'           => $decision,
                    'reviewer_comment' => $request->input('reviewer_comment'),
                    'reviewed_by'      => $context['user_id'],
                    'reviewed_at'      => now(),
                    'updated_at'       => now(),
                    'updated_by'       => $context['user_id'],
                ]);

                if ($decision === 'approved') {
                    $correction = $this->applyCorrection($row, (int) $context['user_id']);
                }
            });

            $this->recordDecisionEvents($row, $decision, $request, $context, $correction);

            return response()->json([
                'status'  => 1,
                'message' => $decision === 'approved'
                    ? 'Approved. The attendance record has been corrected.'
                    : 'Request rejected.',
            ]);
        }

        $correction = null;

        DB::transaction(function () use ($row, $decision, $request, $context, &$correction) {
            DB::table('hrms_attendance_regularisations')->where('id', $row->id)->update([
                'status'           => $decision,
                'reviewer_comment' => $request->input('reviewer_comment'),
                'reviewed_by'      => $context['user_id'],
                'reviewed_at'      => now(),
                'updated_at'       => now(),
                'updated_by'       => $context['user_id'],
            ]);

            if ($decision === 'approved') {
                $correction = $this->applyCorrection($row, (int) $context['user_id']);
            }
        });

        /*
         * The attendance audit trail. Leave got one in Sprint 7 and payroll in
         * Sprint 9; attendance emitted nothing, so the release gate's
         * "Error handling + audit trail" line could not close.
         *
         * PROJECTOR-only, deliberately. AuditLogProjector::handles() returns true
         * for everything, so this reaches g2g_audit_log with no new wiring and
         * NotificationDispatcher is not involved - nobody's inbox changes. Telling
         * the applicant their correction landed is a separate decision with its own
         * recipient question, and is not smuggled in behind an audit-trail change.
         *
         * Recorded AFTER the transaction commits: an event describing a write that
         * rolled back would be a lie, and the event is the trace, not the write.
         */
        $this->recordDecisionEvents($row, $decision, $request, $context, $correction);

        return response()->json([
            'status'  => 1,
            'message' => $decision === 'approved'
                ? 'Approved. The attendance record has been corrected.'
                : 'Request rejected.',
        ]);
    }

    /**
     * DELETE /api/attendance/regularisations/{id}
     * Withdrawal by the applicant, while it is still pending. Soft delete, so
     * the trail survives.
     */
    public function destroy(Request $request, $id)
    {
        $context = $this->attendanceContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $row = DB::table('hrms_attendance_regularisations')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first(['id', 'user_id', 'status']);

        if (!$row) {
            return response()->json(['status' => 0, 'message' => 'Regularisation request not found'], 404);
        }

        if ((int) $row->user_id !== (int) $context['user_id']) {
            return response()->json([
                'status'  => 0,
                'message' => 'You can only withdraw your own request.',
            ], 403);
        }

        if ($row->status !== 'pending') {
            return response()->json([
                'status'  => 0,
                'message' => 'Only a pending request can be withdrawn.',
            ], 422);
        }

        DB::table('hrms_attendance_regularisations')->where('id', $row->id)->update([
            'deleted_at' => now(),
            'deleted_by' => $context['user_id'],
        ]);

        return response()->json(['status' => 1, 'message' => 'Request withdrawn.']);
    }

    /**
     * Record the decision, and - when it changed an attendance row - what that
     * row used to say.
     *
     * Two events rather than one because they answer different questions.
     * "Who decided this request, and when" is true of every decision; "this
     * person's recorded hours changed from X to Y" is only true of approvals,
     * and it is the one payroll depends on.
     *
     * Failure here is logged and swallowed. The correction is already committed
     * and must not be undone because its trace could not be written - but a
     * missing trace has to be loud, or the trail is quietly incomplete.
     */
    private function recordDecisionEvents(object $row, string $decision, Request $request, array $context, ?array $correction): void
    {
        $recorder = app(\App\Services\Events\EventRecorder::class);
        $tenantId = (int) $row->sub_institute_id;

        try {
            $recorder->record(
                'attendance.regularisation.decided',
                $tenantId,
                'hrms_attendance_regularisations',
                (int) $row->id,
                (int) $context['user_id'],
                [
                    'employee_id'      => (int) $row->user_id,
                    'day'              => Carbon::parse($row->day)->toDateString(),
                    'decision'         => $decision,
                    'reviewer_comment' => $request->input('reviewer_comment'),
                    'requested_in'     => $row->requested_in_time,
                    'requested_out'    => $row->requested_out_time,
                ],
                null,
                'attendance.regularisation.decided:' . $row->id . ':' . $decision
            );
        } catch (\Throwable $e) {
            Log::warning('Regularisation decision not recorded in the event store', [
                'regularisation_id' => $row->id, 'decision' => $decision, 'error' => $e->getMessage(),
            ]);
        }

        if ($correction === null) {
            return;   // rejected: no attendance row was touched
        }

        try {
            $recorder->record(
                'attendance.corrected',
                $tenantId,
                'hrms_attendances',
                $correction['attendance_id'],
                (int) $context['user_id'],
                [
                    'employee_id'       => (int) $row->user_id,
                    'day'               => Carbon::parse($row->day)->toDateString(),
                    'regularisation_id' => (int) $row->id,
                    // null before-image means the day had no attendance row at
                    // all and this correction created it - a different fact from
                    // "the times changed", and worth being able to tell apart.
                    'before'            => $correction['before'],
                    'after'             => [
                        'punchin_time'   => $correction['after']['punchin_time'] ?? null,
                        'punchout_time'  => $correction['after']['punchout_time'] ?? null,
                        'timestamp_diff' => $correction['after']['timestamp_diff'] ?? null,
                    ],
                    'created_row'       => $correction['before'] === null,
                ],
                null,
                'attendance.corrected:' . $row->id . ':' . $correction['attendance_id']
            );
        } catch (\Throwable $e) {
            Log::warning('Attendance correction not recorded in the event store', [
                'regularisation_id' => $row->id,
                'attendance_id'     => $correction['attendance_id'],
                'error'             => $e->getMessage(),
            ]);
        }
    }

    /**
     * Write the approved correction onto the attendance row.
     *
     * Creates the row when the day has none - a wholly missed punch is the
     * commonest reason to regularise, and refusing it would leave the employee
     * with an approved request and an absent day.
     */
    /**
     * Apply the correction this request asked for, and record it.
     *
     * The write itself moved to App\Services\Attendance\AttendanceCorrector so
     * the HR-initiated route could share it rather than grow a second writer.
     * The service also marks a created row with in_note/out_note the way a real
     * punch does, which this path previously left at 0.
     *
     * ── THE EDIT ROW, WHICH THIS PATH NEVER USED TO WRITE ───────────────────
     *
     * `hrms_attendance_edits` had exactly one writer -
     * AttendanceAdminController::correct() - which hardcodes source = 'admin'.
     * So an approved employee request corrected the attendance row and left no
     * HR-readable record of it, while the Change History screen's empty state
     * told users that approved requests appear there, and the 'regularisation'
     * value that table's own migration documents was produced by nothing.
     *
     * Written here rather than inside the service because the two paths differ
     * on exactly the fields that matter: `reason` is the EMPLOYEE's words here
     * and the HR user's there, `source` differs, and only this path has a
     * request id. The column mapping is shared via editRowFrom(); the decision
     * and the differing values stay visible at the call site.
     *
     * In the same transaction as the correction - both callers of this method
     * are already inside DB::transaction(), so the row and the change it
     * describes commit together or not at all.
     *
     * `regularisation_id` needs the column added by
     * 2026_10_09_100300_add_regularisation_id_to_hrms_attendance_edits_table,
     * which is migrated on both hosts. Finding the request by (user_id, day)
     * instead was considered and rejected: two requests can exist for one day -
     * one rejected and one approved, or one withdrawn and one resubmitted, and
     * this table soft-deletes - so the join would be ambiguous.
     */
    private function applyCorrection(object $row, int $actorId): array
    {
        $corrector = app(AttendanceCorrector::class);
        $applied   = $corrector->apply($row, $actorId);

        $applied['edit_id'] = DB::table('hrms_attendance_edits')->insertGetId(
            $corrector->editRowFrom(
                $applied,
                (int) $row->sub_institute_id,
                (int) $row->user_id,
                Carbon::parse($row->day)->toDateString(),
                (string) ($row->reason ?? ''),
                'regularisation',
                $actorId,
            ) + ['regularisation_id' => (int) $row->id]
        );

        return $applied;
    }

    /** HH:MM:SS between two datetimes, or null when the day is still open. */
    /** Worked time. Delegated, so both correction paths round it identically. */
    private function duration(?string $punchIn, ?string $punchOut): ?string
    {
        return app(AttendanceCorrector::class)->duration($punchIn, $punchOut);
    }

    private function transform(object $row): array
    {
        return [
            'id'                 => (int) $row->id,
            'employee_id'        => (int) $row->user_id,
            'employee_name'      => $row->employee_name ?? null,
            'employee_no'        => $row->employee_no ?? null,
            'department'         => $row->department ?? null,
            'day'                => Carbon::parse($row->day)->toDateString(),
            'requested_in_time'  => $row->requested_in_time ? substr($row->requested_in_time, 0, 5) : null,
            'requested_out_time' => $row->requested_out_time ? substr($row->requested_out_time, 0, 5) : null,
            'original_in_time'   => $row->original_in_time ? substr($row->original_in_time, 0, 5) : null,
            'original_out_time'  => $row->original_out_time ? substr($row->original_out_time, 0, 5) : null,
            'reason'             => $row->reason,
            'status'             => $row->status,
            'reviewer_comment'   => $row->reviewer_comment,
            'reviewed_at'        => $row->reviewed_at,
            'submitted_at'       => $row->created_at,
            'approval'           => $this->approvalInfoFor((int) $row->id, (string) $row->status),
        ];
    }

    /**
     * ROUND 5 FOLLOW-UP. Same read-side addition made for offer/mobility/
     * offboarding/requisition — surface the chain a pending regularisation
     * is actually waiting on. The existing single-action decision() endpoint
     * is already chain-aware (built last round); this only adds visibility
     * for a screen that today shows just one Approve/Reject action per step.
     * `null` for every request with no active chain.
     */
    private function approvalInfoFor(int $regularisationId, string $status): ?array
    {
        if ($status !== 'pending') {
            return null;
        }

        $workflow = app(\App\Services\Attendance\AttendanceRegularisationApprovalWorkflow::class);
        $steps = $workflow->stepsFor($regularisationId);
        if ($steps === []) {
            return null;
        }

        $current = $workflow->currentStep($regularisationId);

        return [
            'pending' => $current !== null,
            'step_name' => $current['step_name'] ?? null,
            'approver_role' => $current['approver_role'] ?? null,
            'step' => $current['step_order'] ?? null,
            'of' => count($steps),
        ];
    }
}
