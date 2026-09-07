<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Api\Attendance\Concerns\ResolvesAttendanceContext;
use App\Http\Controllers\Api\Leave\Concerns\ResolvesLeaveAuthority;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        DB::transaction(function () use ($row, $decision, $request, $context) {
            DB::table('hrms_attendance_regularisations')->where('id', $row->id)->update([
                'status'           => $decision,
                'reviewer_comment' => $request->input('reviewer_comment'),
                'reviewed_by'      => $context['user_id'],
                'reviewed_at'      => now(),
                'updated_at'       => now(),
                'updated_by'       => $context['user_id'],
            ]);

            if ($decision === 'approved') {
                $this->applyCorrection($row, (int) $context['user_id']);
            }
        });

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
     * Write the approved correction onto the attendance row.
     *
     * Creates the row when the day has none - a wholly missed punch is the
     * commonest reason to regularise, and refusing it would leave the employee
     * with an approved request and an absent day.
     */
    private function applyCorrection(object $row, int $actorId): void
    {
        $day = Carbon::parse($row->day)->toDateString();

        $existing = DB::table('hrms_attendances')
            ->where('user_id', $row->user_id)
            ->where('sub_institute_id', $row->sub_institute_id)
            ->whereNull('deleted_at')
            ->whereDate('day', $day)
            ->first();

        $punchIn  = $row->requested_in_time
            ? $day . ' ' . $row->requested_in_time
            : ($existing->punchin_time ?? null);

        $punchOut = $row->requested_out_time
            ? $day . ' ' . $row->requested_out_time
            : ($existing->punchout_time ?? null);

        $update = [
            'punchin_time'   => $punchIn,
            'punchout_time'  => $punchOut,
            'timestamp_diff' => $this->duration($punchIn, $punchOut),
            'updated_at'     => now(),
            'updated_by'     => $actorId,
        ];

        if ($existing) {
            DB::table('hrms_attendances')->where('id', $existing->id)->update($update);
            return;
        }

        DB::table('hrms_attendances')->insert(array_merge($update, [
            'user_id'          => $row->user_id,
            'sub_institute_id' => $row->sub_institute_id,
            'day'              => $day,
            'created_at'       => now(),
            'created_by'       => $actorId,
        ]));
    }

    /** HH:MM:SS between two datetimes, or null when the day is still open. */
    private function duration(?string $punchIn, ?string $punchOut): ?string
    {
        if (!$punchIn || !$punchOut) {
            return null;
        }

        $in  = Carbon::parse($punchIn);
        $out = Carbon::parse($punchOut);

        if ($out->lessThanOrEqualTo($in)) {
            return null;
        }

        $minutes = $in->diffInMinutes($out);

        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
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
        ];
    }
}
