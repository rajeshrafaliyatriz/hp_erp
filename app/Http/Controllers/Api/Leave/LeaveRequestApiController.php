<?php

namespace App\Http\Controllers\Api\Leave;

use App\Http\Controllers\Api\Leave\Concerns\ResolvesLeaveAuthority;
use App\Http\Controllers\Api\Leave\Concerns\ResolvesLeaveContext;
use App\Http\Controllers\Controller;
use App\Services\Leave\LeaveAnalyticsService;
use App\Services\Leave\LeaveApprovalWorkflow;
use App\Services\Leave\LeaveDayCounter;
use App\Services\Leave\LeaveNotifier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class LeaveRequestApiController extends Controller
{
    use ResolvesLeaveContext;
    use ResolvesLeaveAuthority;

    /** Statuses an approver may move a request into. */
    private const DECISION_STATUSES = ['approved', 'rejected', 'sent_back', 'cancelled', 'approved_lwp'];

    private const SORTABLE = [
        'employee_name' => 'employee_name',
        'department'    => 'hd.department',
        'leaveType'     => 'hlt.leave_type',
        'fromDate'      => 'hel.from_date',
        'toDate'        => 'hel.to_date',
        'status'        => 'hel.status',
        'submittedDate' => 'hel.created_at',
        'id'            => 'hel.id',
    ];

    public function __construct(
        private LeaveAnalyticsService $analytics,
        private LeaveDayCounter $dayCounter,
        private LeaveApprovalWorkflow $workflow,
        private LeaveNotifier $notifier,
    ) {
    }

    /**
     * GET /api/leave/requests
     *
     * Server side search + filter + sort + pagination for the Leave Requests table.
     */
    public function index(Request $request)
    {
        $context = $this->leaveContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $perPage = min(max((int) $request->input('per_page', 10), 1), 200);
        $page    = max((int) $request->input('page', 1), 1);

        // F-103: this list was scoped by TENANT ONLY, so every role - including
        // `employee`, whose own configured scope is 'Self' - received every
        // leave request in the organisation, with names, departments and
        // reasons. Leave reasons are health and family information.
        //
        // The frontend's only narrowing was a `?mine=1` URL parameter that no
        // control ever set.
        $query = $this->applyLeaveScope(
            $this->applyFilters(
                $this->analytics->requestsQuery($context['sub_institute_id'], $context['year']),
                $request
            ),
            $context
        );

        $total = (clone $query)->count('hel.id');

        $sortBy  = self::SORTABLE[$request->input('sort_by')] ?? 'hel.from_date';
        $sortDir = strtolower($request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $rows = $query
            ->selectRaw("
                hel.*,
                CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name) AS employee_name,
                u.employee_no,
                u.email,
                u.mobile,
                u.image,
                u.department_id AS user_department_id,
                COALESCE(hd.department, '') AS department,
                COALESCE(hjt.title, '') AS designation,
                COALESCE(hlt.leave_type, '') AS leave_type,
                hlt.leave_type_id AS leave_type_code
            ")
            ->orderBy($sortBy, $sortDir)
            ->orderByDesc('hel.id')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($row) => $this->transform($row));

        return response()->json([
            'status'     => 1,
            'message'    => 'Leave requests fetched successfully',
            'year'       => $context['year'],
            'data'       => $rows,
            'pagination' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => (int) max(ceil($total / $perPage), 1),
            ],
        ]);
    }

    /**
     * GET /api/leave/requests/{id}
     * Single request with balance snapshot, comments and timeline.
     */
    public function show(Request $request, $id)
    {
        $context = $this->leaveContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $row = $this->analytics
            ->requestsQuery($context['sub_institute_id'], $context['year'])
            ->selectRaw("
                hel.*,
                CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name) AS employee_name,
                u.employee_no, u.email, u.mobile, u.image,
                u.department_id AS user_department_id,
                COALESCE(hd.department, '') AS department,
                COALESCE(hjt.title, '') AS designation,
                COALESCE(hlt.leave_type, '') AS leave_type,
                hlt.leave_type_id AS leave_type_code
            ")
            ->where('hel.id', $id)
            ->first();

        if (!$row) {
            return response()->json(['status' => 0, 'message' => 'Leave request not found'], 404);
        }

        // Out of scope reads as "not found", not "forbidden". A 403 here would
        // confirm that a particular colleague has a leave request with this id,
        // which is the thing being withheld.
        if (!$this->leaveSubjectInScope($context, (int) $row->user_id)) {
            return response()->json(['status' => 0, 'message' => 'Leave request not found'], 404);
        }

        $balances = $this->analytics->balancesForEmployee(
            $context['sub_institute_id'],
            $context['year'],
            (int) $row->user_id
        );

        return response()->json([
            'status'  => 1,
            'message' => 'Leave request fetched successfully',
            'data'    => array_merge($this->transform($row), [
                'balances' => $balances['leave_types'],
                'comments' => $this->comments($row),
                'timeline' => $this->timeline($row),
                // F-124. The approval chain, separate from `timeline` above -
                // that one narrates what happened to the request, this one says
                // who still has to say yes.
                'approval_chain' => $this->workflow->timelineFor((int) $row->id),
            ]),
        ]);
    }

    /**
     * POST /api/leave/requests
     * Apply for leave. Mirrors ApplyLeaveController::store but with the validation
     * rules that were commented out there, and with status actually set.
     */
    public function store(Request $request)
    {
        $context = $this->leaveContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            /*
             * F-101. Was `exists:hrms_leave_types,id` with no tenant scope, so a
             * tenant 3 employee could apply using tenant 6's leave type id 9 and
             * get a 201. The row then rendered with a blank leave type, because
             * requestsQuery() joins hrms_leave_types ON hlt.sub_institute_id =
             * <caller>, and landed in the "Unassigned" bucket beside F-94's 15
             * mis-typed rows.
             */
            'leave_type_id' => [
                'required',
                'integer',
                Rule::exists('hrms_leave_types', 'id')
                    ->where('sub_institute_id', $context['sub_institute_id'])
                    ->whereNull('deleted_at'),
            ],
            'day_type'      => 'required|in:full,half',
            'from_date'     => 'required|date',
            'to_date'       => 'required_if:day_type,full|nullable|date|after_or_equal:from_date',
            'slot'          => 'required_if:day_type,half|nullable|in:first_half,second_half',
            'comment'       => 'required|string|max:255',
            'employee_id'   => 'nullable|integer|exists:tbluser,id',
            'department_id' => 'nullable|integer',
        ], [
            'comment.required'     => 'A reason is required.',
            'leave_type_id.exists' => 'That leave type is not available to your organisation.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        // G-LEAVE-SEC-01: request-first with a safe-looking fallback.
        // The subject is now resolved AGAINST the caller, not merged with them.
        $userId = $this->leaveSubject($request, $context);
        if (!is_int($userId)) {
            return $userId;
        }

        if (!$userId) {
            return response()->json(['status' => 0, 'message' => 'Unable to resolve the employee for this request'], 422);
        }

        $isHalfDay = $request->input('day_type') === 'half';
        $fromDate  = $request->input('from_date');
        $toDate    = $isHalfDay ? $fromDate : ($request->input('to_date') ?: $fromDate);

        $departmentId = $request->input('department_id')
            ?: DB::table('tbluser')->where('id', $userId)->value('department_id');

        /*
         * F-102. Everything down to the payload below is new.
         *
         * store() used to validate SHAPES and write. No balance check, no
         * overlap check, no period rule - and the audit proved all three by
         * calling the endpoint directly:
         *
         *   365-day leave, 2026-12-01 .. 2027-11-30   -> 201 Created
         *   leave dated 1990-01-01 .. 1990-01-05      -> 201 Created
         *
         * The only guard against duplication was an upsert keyed on
         * (user_id, from_date, status='pending'), which a different start date
         * walks straight past.
         */

        // 1. Inside the leave year being filed against. A leave year is
        //    April-March; a request outside it cannot be counted against any
        //    balance, which is how a 1990 date was accepted.
        $yearStart = $context['from'];
        $yearEnd   = $context['to'];

        if ($fromDate < $yearStart || $toDate > $yearEnd) {
            $message = "Leave must fall inside the {$yearStart} to {$yearEnd} leave year.";

            return response()->json([
                'status'  => 0,
                'message' => $message,
                'errors'  => ['from_date' => [$message]],
            ], 422);
        }

        // 2. What it actually costs, excluding weekly-offs and holidays (F-95).
        $breakdown = $this->dayCounter->breakdown(
            (int) $context['sub_institute_id'],
            $departmentId ? (int) $departmentId : null,
            $fromDate,
            $toDate,
            $isHalfDay ? '0.5' : '1'
        );

        $chargeableDays = $breakdown['days'];

        if ($chargeableDays <= 0) {
            return response()->json([
                'status'   => 0,
                'message'  => 'Every day in that range is a weekly off or a holiday, so there is no leave to take.',
                'errors'   => ['from_date' => ['Every day in that range is a weekly off or a holiday.']],
                'excluded' => $breakdown['excluded'],
            ], 422);
        }

        // 3. No overlap with the employee's own live requests. Checked on dates
        //    rather than on a start-date key, which is what let two overlapping
        //    requests through.
        $overlap = DB::table('hrms_emp_leaves')
            ->where('user_id', $userId)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->whereIn('status', ['pending', 'approved', 'approved_lwp'])
            ->where('from_date', '<=', $toDate)
            ->whereRaw('COALESCE(to_date, from_date) >= ?', [$fromDate])
            ->first(['id', 'from_date', 'to_date', 'status']);

        // Re-applying for the same start date is an EDIT of the pending row
        // further down, not an overlap, so that one row is allowed through.
        if ($overlap && !($overlap->status === 'pending' && $overlap->from_date === $fromDate)) {
            return response()->json([
                'status'  => 0,
                'message' => 'You already have ' . $overlap->status . ' leave from '
                    . $overlap->from_date . ' to ' . ($overlap->to_date ?: $overlap->from_date) . '.',
                'errors'  => ['from_date' => ['These dates overlap leave you have already requested.']],
            ], 422);
        }

        // 4. Balance, for the leave type being applied for.
        $leaveTypeName = DB::table('hrms_leave_types')
            ->where('id', (int) $request->input('leave_type_id'))
            ->value('leave_type');

        $balances = $this->analytics->balancesForEmployee(
            (int) $context['sub_institute_id'],
            (int) $context['year'],
            $userId
        );

        $forType = collect($balances['leave_types'] ?? [])->firstWhere('leave_type', $leaveTypeName);

        $entitled  = (float) ($forType['total'] ?? 0);
        $used      = (float) ($forType['used'] ?? 0);
        $remaining = max($entitled - $used, 0);

        /*
         * An entitlement of 0 is NOT read as "no balance, refuse".
         *
         * hrms_leave_allocation holds one row for the entire platform (F-96), so
         * today every entitlement is zero and enforcing this strictly would
         * refuse every leave request in the product the moment it shipped. The
         * rule is therefore: enforce the balance where the tenant has configured
         * one, and allow it through where they have not.
         *
         * A deliberate, temporary looseness. It disappears on its own as soon as
         * entitlements are set through the screen this sprint adds, and it is
         * written down here rather than left as a silent gap.
         */
        if ($entitled > 0 && $chargeableDays > $remaining) {
            return response()->json([
                'status'  => 0,
                'message' => 'That is ' . $chargeableDays . ' day(s) of ' . $leaveTypeName
                    . ' and you have ' . $remaining . ' remaining.',
                'errors'  => ['leave_type_id' => ['Not enough ' . $leaveTypeName . ' balance.']],
                'balance' => [
                    'entitled'  => $entitled,
                    'used'      => $used,
                    'remaining' => $remaining,
                    'requested' => $chargeableDays,
                ],
            ], 422);
        }

        $payload = [
            'sub_institute_id' => $context['sub_institute_id'],
            'department_id'    => $departmentId,
            'user_id'          => $userId,
            'leave_type_id'    => (int) $request->input('leave_type_id'),
            'day_type'         => $isHalfDay ? '0.5' : '1',
            'slot'             => $isHalfDay ? $request->input('slot') : null,
            'from_date'        => $fromDate,
            'to_date'          => $toDate,
            'comment'          => $request->input('comment'),
            // F-95: the cost is computed once, here, and stored - so the reports
            // sum a column instead of re-deriving a third version of it.
            'chargeable_days'  => $chargeableDays,
            'status'           => 'pending',
        ];

        // Preserve the legacy upsert: re-applying for the same start date edits the
        // employee's existing pending row rather than creating a duplicate.
        //
        // `sent_back` is included deliberately. Sending a request back means
        // "amend this and try again", and re-submitting is precisely how the
        // employee does that - so it must land on the SAME row. Matching only
        // 'pending' meant a sent-back request could never be resubmitted: the
        // employee got a second leave row and the first sat sent_back for ever,
        // with its chain going nowhere. Found by reviewing this sprint's own
        // work; no probe exercised sent_back.
        $existing = DB::table('hrms_emp_leaves')
            ->where('user_id', $userId)
            ->where('from_date', $fromDate)
            ->whereIn('status', ['pending', 'sent_back'])
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            $payload['updated_at'] = now();
            $payload['updated_by'] = $context['user_id'];
            DB::table('hrms_emp_leaves')->where('id', $existing->id)->update($payload);
            $leaveId = (int) $existing->id;
            $message = 'Leave request updated successfully';

            // Editing a pending request does NOT restart its chain. The days may
            // have changed but the same approvers are still being asked, and
            // discarding a manager's approval because the employee fixed a typo
            // would quietly undo work somebody already did.
            //
            // Re-submitting a SENT BACK request is the exception, and the whole
            // point of sending one back: it goes to the front of its own chain
            // again so the first approver sees the amended version.
            $chain = array_column($this->workflow->stepsFor($leaveId), 'approver_role');

            if ($chain === []) {
                $chain = $this->workflow->openFor($leaveId, (int) $context['sub_institute_id']);
            } elseif ($existing->status === 'sent_back') {
                $this->workflow->reopenFor($leaveId);
                $message = 'Leave request updated and sent for approval again';
            }
        } else {
            $payload['created_at'] = now();
            $payload['created_by'] = $context['user_id'];
            $leaveId = (int) DB::table('hrms_emp_leaves')->insertGetId($payload);
            $message = 'Leave applied successfully';

            // F-124. The chain the tenant has configured is frozen onto this
            // request now, so a later configuration change cannot retroactively
            // approve it or strand it.
            $chain = $this->workflow->openFor($leaveId, (int) $context['sub_institute_id']);
        }

        /*
         * F-128. Tell the approver. Until this sprint the module told nobody
         * anything: an approver found out a request existed by opening the
         * screen, which is why requests sat pending for months.
         *
         * Emitted only for a request that is actually WAITING on someone - a
         * silent edit to an already-pending request is not news, and re-sending
         * on every save would train people to ignore the bell.
         */
        if (!$existing || $existing->status === 'sent_back') {
            $employeeName = DB::table('tbluser')
                ->selectRaw("TRIM(CONCAT_WS(' ', first_name, last_name)) AS n")
                ->where('id', $userId)
                ->value('n') ?: 'An employee';

            $this->notifier->submitted(
                $leaveId,
                array_merge($context, ['subject_id' => $userId]),
                $chain,
                $employeeName,
                $fromDate === $toDate ? $fromDate : ($fromDate . ' to ' . $toDate)
            );
        }

        return response()->json([
            'status'  => 1,
            'message' => $message,
            'data'    => ['id' => $leaveId],
            'workflow' => [
                'chain'    => $chain,
                'timeline' => $this->workflow->timelineFor($leaveId),
            ],
        ], $existing ? 200 : 201);
    }

    /**
     * POST /api/leave/requests/{id}/decision
     */
    public function decision(Request $request, $id)
    {
        $context = $this->leaveContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'status'      => 'required|in:' . implode(',', self::DECISION_STATUSES),
            'hod_comment' => 'nullable|string|max:50',
            'hr_remarks'  => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $leave = DB::table('hrms_emp_leaves')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first(['id', 'user_id', 'status']);

        if (!$leave) {
            return response()->json(['status' => 0, 'message' => 'Leave request not found'], 404);
        }

        /*
         * F-126. THIS METHOD NEVER LOOKED AT THE REQUEST'S OWN STATUS.
         *
         * Found by the Sprint 6 adversarial review of Sprint 6's own work, and
         * it is a hole the chain OPENED rather than one it inherited. The chain
         * is enforced inside `if ($step)`; a finished chain has no pending step,
         * so currentStep() returns null, the whole block is skipped, and
         * applyDecision() rewrote status with no state predicate at all.
         *
         * The backfill is what made it reachable on live rather than theoretical:
         * it writes a CLOSED chain for every already-decided request, so the
         * "this request predates the chain" fall-through stopped catching legacy
         * rows and started catching FINISHED ones. Tenant 3 has 4 approved and 1
         * rejected; any Organization-scope holder of approve_leave could have
         * flipped the rejected one to approved on a single signature, leaving
         * hrms_leave_approval_steps saying "rejected at step 1" beside a request
         * marked approved.
         *
         * cancel() and destroy() have always guarded status. decision() was the
         * outlier, and this is the guard it should always have had.
         */
        if ($leave->status !== 'pending') {
            return response()->json([
                'status'  => 0,
                'message' => "This request is already {$leave->status} and cannot be decided again. "
                    . 'Ask HR to correct it if that is wrong.',
            ], 422);
        }

        /*
         * F-87. This method used to check the tenant and the row's existence and
         * nothing else - never who was approving. An employee approved their own
         * leave with it during the audit, and the request returned 200.
         *
         * Three separate questions, in the order they should be asked:
         */

        // 1. May this role approve at all? (hrms_leave_role_permissions)
        if ($denied = $this->denyUnlessLeaveCan($context, 'approve_leave', 'You do not have permission to decide leave requests.')) {
            return $denied;
        }

        // 2. Is this employee inside their scope? 404, not 403 - see show().
        if (!$this->leaveSubjectInScope($context, (int) $leave->user_id)) {
            return response()->json(['status' => 0, 'message' => 'Leave request not found'], 404);
        }

        // 3. Nobody decides their own request, whatever their role.
        //
        // This is not covered by scope: an HR Manager legitimately holds
        // Organization scope, which includes themselves. Separation of duties is
        // a rule about the actor, not about reach, so it is checked separately.
        if ((int) $leave->user_id === (int) $context['user_id']) {
            return response()->json([
                'status'  => 0,
                'message' => 'You cannot decide your own leave request. Ask your approver.',
            ], 403);
        }

        /*
         * 4. Is it THIS approver's turn? (F-124, hrms_leave_workflow_settings)
         *
         * Everything above answers "may this person approve leave at all". The
         * chain answers a different question the product had never asked: this
         * request needs the reporting manager AND then the department head, so
         * an HR Manager holding Organization scope still cannot skip to the end.
         *
         * Requests that predate the chain have no steps. They fall through to a
         * single decision, exactly as before - there is no correct way to
         * retro-fit a chain onto a request nobody was ever asked to approve,
         * and inventing one would strand every open request in the product.
         */
        $decision = $request->input('status');
        $step     = $this->workflow->currentStep((int) $id);
        $progress = null;

        if ($step) {
            // leaveAuthority() already resolved this caller's role_key for check 1
            // above, and caches it - asking again costs nothing.
            $roleKey = $this->leaveAuthority($context)['role_key'] ?? null;

            if (!$this->workflow->roleMayDecide($step, $roleKey)) {
                $waitingFor = LeaveApprovalWorkflow::label($step['approver_role']);

                return response()->json([
                    'status'  => 0,
                    'message' => "This request is waiting for {$waitingFor} approval (step {$step['step_order']}). "
                        . 'You are not the approver for this step.',
                ], 403);
            }

            $progress = $this->workflow->recordDecision(
                (int) $id,
                $step,
                $decision,
                $context,
                $request->input('hod_comment') ?? $request->input('hr_remarks')
            );

            // Somebody else claimed this step between the read and the write.
            // Say so rather than reporting a decision that did not happen.
            if (!empty($progress['conflict'])) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Another approver decided this step a moment ago. Reload to see where it is now.',
                ], 409);
            }

            // The chain is not finished: the request stays pending and moves on
            // to the next approver. Recording the step IS the decision here -
            // there is nothing to write to hrms_emp_leaves.status yet.
            if (!$progress['final']) {
                $nextLabel = LeaveApprovalWorkflow::label($progress['next']);

                $this->applyDecision(
                    [$id],
                    'pending',
                    [$id => $request->input('hod_comment')],
                    [$id => $request->input('hr_remarks')],
                    $context
                );

                // F-128. The employee is told even though nothing is decided
                // yet: "your manager approved it, it is now with the department
                // head" is what they actually want to know, and no screen has
                // ever said it. The next approver is told by the same emit -
                // RecipientResolver reads the chain's newly-pending step.
                $this->notifier->decided(
                    (int) $id, $context, (int) $leave->user_id, $decision,
                    false, $progress['next'], $progress['step'], $progress['of']
                );
                $this->notifier->submitted(
                    (int) $id,
                    array_merge($context, ['subject_id' => (int) $leave->user_id]),
                    array_column($this->workflow->stepsFor((int) $id), 'approver_role'),
                    $this->employeeName((int) $leave->user_id),
                    $this->leaveDates((int) $id),
                    // The step that is NOW pending, not the one just decided -
                    // this tells the next approver, and the key must differ from
                    // the one that told the previous approver.
                    (int) $progress['step'] + 1
                );

                return response()->json([
                    'status'        => 1,
                    'message'       => "Approved at step {$progress['step']} of {$progress['of']}. "
                        . "Now waiting for {$nextLabel}.",
                    'updated_count' => 1,
                    'workflow'      => [
                        'final'    => false,
                        'step'     => $progress['step'],
                        'of'       => $progress['of'],
                        'next'     => $progress['next'],
                        'timeline' => $this->workflow->timelineFor((int) $id),
                    ],
                ]);
            }
        }

        $updated = $this->applyDecision(
            [$id],
            $decision,
            [$id => $request->input('hod_comment')],
            [$id => $request->input('hr_remarks')],
            $context
        );

        // F-128. The decision that ends it. Emitted only when the write landed -
        // telling somebody their leave was approved when the UPDATE matched no
        // row would be worse than telling them nothing.
        if ($updated) {
            $this->notifier->decided(
                (int) $id, $context, (int) $leave->user_id, $decision,
                true, null,
                $progress['step'] ?? 1, $progress['of'] ?? 1
            );
        }

        return response()->json([
            'status'        => $updated ? 1 : 0,
            'message'       => $updated ? 'Leave request updated successfully' : 'No leave request updated',
            'updated_count' => $updated,
            'workflow'      => $progress ? [
                'final'    => true,
                'step'     => $progress['step'],
                'of'       => $progress['of'],
                'next'     => null,
                'timeline' => $this->workflow->timelineFor((int) $id),
            ] : null,
        ], $updated ? 200 : 400);
    }

    /**
     * POST /api/leave/requests/bulk-decision
     * Fixes the legacy leaveAuthorisationStore, which returned from inside its own
     * loop and therefore only ever updated the first selected record.
     */
    public function bulkDecision(Request $request)
    {
        $context = $this->leaveContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'ids'           => 'required|array|min:1',
            'ids.*'         => 'integer',
            'status'        => 'required|in:' . implode(',', self::DECISION_STATUSES),
            'hod_comment'   => 'nullable|array',
            'hr_remarks'    => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Same three questions as decision(), plus bulk_operations - deciding
        // fifty requests in one call is a distinct privilege from deciding one,
        // and the matrix has always said so.
        if ($denied = $this->denyUnlessLeaveCan($context, 'approve_leave', 'You do not have permission to decide leave requests.')) {
            return $denied;
        }

        if ($denied = $this->denyUnlessLeaveCan($context, 'bulk_operations', 'You do not have permission to decide leave requests in bulk.')) {
            return $denied;
        }

        $rows = DB::table('hrms_emp_leaves')
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereIn('id', $request->input('ids'))
            ->whereNull('deleted_at')
            // F-126, the same guard decision() now carries. Without it "approve
            // fifty" was a way to re-decide fifty already-finished requests -
            // exactly the thing bulkDecision must never be a shortcut around.
            ->where('status', 'pending')
            ->get(['id', 'user_id']);

        // Out-of-scope and own rows are dropped rather than failing the whole
        // call: a bulk action over a filtered list should apply to what the
        // caller may act on, and the response reports the count so a silent
        // partial application is visible.
        $ids = $rows
            ->filter(fn ($row) => $this->leaveSubjectInScope($context, (int) $row->user_id)
                && (int) $row->user_id !== (int) $context['user_id'])
            ->pluck('id')
            ->all();

        if (empty($ids)) {
            return response()->json(['status' => 0, 'message' => 'No matching leave requests found'], 404);
        }

        /*
         * F-124. The chain applies in bulk exactly as it does one at a time, and
         * for the same reason: "approve fifty" must not become a way round a
         * check that "approve one" enforces.
         *
         * Each id sorts into one of three outcomes, and all three are reported:
         *
         *   finalised  the caller's approval was the last one the chain needed
         *   advanced   recorded, and the request moved on to the next approver
         *   notYours   waiting on a step this caller is not the approver for
         *
         * Skipping rather than failing the whole call matches the scope filter
         * directly above, which already drops what the caller may not act on.
         */
        $decision  = $request->input('status');
        $roleKey   = $this->leaveAuthority($context)['role_key'] ?? null;
        $comments  = (array) $request->input('hod_comment', []);
        $remarks   = (array) $request->input('hr_remarks', []);

        $finalise = [];
        $advanced = 0;
        $notYours = [];

        foreach ($ids as $leaveId) {
            $step = $this->workflow->currentStep((int) $leaveId);

            // No chain: a request that predates F-124's fix. One decision, as before.
            if (!$step) {
                $finalise[] = $leaveId;
                continue;
            }

            if (!$this->workflow->roleMayDecide($step, $roleKey)) {
                $notYours[] = (int) $leaveId;
                continue;
            }

            $progress = $this->workflow->recordDecision(
                (int) $leaveId,
                $step,
                $decision,
                $context,
                $comments[$leaveId] ?? $remarks[$leaveId] ?? null
            );

            if (!empty($progress['conflict'])) {
                // Somebody else took this step between the read and the write.
                // Reported as skipped rather than counted as decided.
                $notYours[] = (int) $leaveId;
                continue;
            }

            if ($progress['final']) {
                $finalise[] = $leaveId;
            } else {
                $advanced++;
                // These stay 'pending', but the approver's comment and the
                // updated_by/updated_at trail still belong on the row - single
                // decision() has always written them for an advancing request
                // and bulk did not, so a bulk approval lost the comment.
                $this->applyDecision([$leaveId], 'pending', $comments, $remarks, $context);
            }
        }

        $updated = $finalise === [] ? 0 : $this->applyDecision(
            $finalise,
            $decision,
            $comments,
            $remarks,
            $context
        );

        $total = $updated + $advanced;

        $message = $total
            ? $total . ' leave request(s) updated successfully'
                . ($advanced ? " ({$advanced} passed to the next approver)" : '')
            : 'No leave request updated';

        if ($notYours !== []) {
            $message .= '. ' . count($notYours) . ' skipped - waiting on another approver.';
        }

        return response()->json([
            'status'        => $total ? 1 : 0,
            'message'       => $message,
            'updated_count' => $total,
            'workflow'      => [
                'finalised' => $updated,
                'advanced'  => $advanced,
                'skipped'   => $notYours,
            ],
        ], $total ? 200 : 400);
    }

    /**
     * DELETE /api/leave/requests/{id}
     * Withdrawal by the applicant - soft delete, never a hard delete, so the
     * audit trail survives.
     */
    /**
     * POST /api/leave/requests/{id}/cancel
     *
     * The applicant cancels their own APPROVED leave. F-105 in the audit's
     * golden transactions: "cancel after approval" was listed as FAIL, because
     * the status enum has had `cancelled` since the table was created and no
     * code path has ever reached it.
     *
     * Withdrawal (destroy()) and cancellation are different acts and are kept
     * apart deliberately:
     *
     *   withdraw   a PENDING request, before anyone has decided. Soft-deleted;
     *              it never happened.
     *   cancel     an APPROVED request, before it has started. Status becomes
     *              `cancelled` and the row survives, because somebody approved
     *              it and that decision is part of the record.
     *
     * The balance returns on its own: LeaveAnalyticsService::CONSUMING_STATUSES
     * is ['approved', 'pending'], so a cancelled request stops being counted
     * the moment its status changes. No compensating write, nothing to get out
     * of step.
     *
     * ONLY BEFORE IT STARTS. Cancelling leave that has already begun is not a
     * self-service action - the days were taken, attendance for them is already
     * recorded, and unpicking that is an HR correction. Refused with a reason
     * rather than silently allowed.
     */
    public function cancel(Request $request, $id)
    {
        $context = $this->leaveContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $leave = DB::table('hrms_emp_leaves')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();

        if (!$leave) {
            return response()->json(['status' => 0, 'message' => 'Leave request not found'], 404);
        }

        $isOwner    = (int) $leave->user_id === (int) $context['user_id'];
        $canApprove = $this->leaveCan($context, 'approve_leave')
            && $this->leaveSubjectInScope($context, (int) $leave->user_id);

        // The applicant cancels their own; an approver may cancel one inside
        // their scope, which is the HR-side correction path.
        if (!$isOwner && !$canApprove) {
            return response()->json([
                'status'  => 0,
                'message' => 'You can only cancel your own leave.',
            ], 403);
        }

        if (!in_array($leave->status, ['approved', 'approved_lwp'], true)) {
            return response()->json([
                'status'  => 0,
                'message' => $leave->status === 'pending'
                    ? 'This request has not been approved yet - withdraw it instead.'
                    : 'Only approved leave can be cancelled. This request is ' . $leave->status . '.',
            ], 422);
        }

        if (Carbon::parse($leave->from_date)->startOfDay()->isPast()) {
            return response()->json([
                'status'  => 0,
                'message' => 'This leave has already started. Ask HR to correct it.',
            ], 422);
        }

        DB::table('hrms_emp_leaves')->where('id', $leave->id)->update([
            'status'     => 'cancelled',
            'hr_remarks' => $request->input('reason'),
            'updated_at' => now(),
            'updated_by' => $context['user_id'],
        ]);

        // Nothing is waiting on an approver any more. Leaving a step 'pending'
        // would keep a cancelled request sitting in somebody's queue for ever,
        // and would let the escalation sweep chase it.
        $this->workflow->closeOpenSteps((int) $leave->id);

        return response()->json([
            'status'  => 1,
            'message' => 'Leave cancelled. The days have been returned to your balance.',
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $context = $this->leaveContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $leave = DB::table('hrms_emp_leaves')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();

        if (!$leave) {
            return response()->json(['status' => 0, 'message' => 'Leave request not found'], 404);
        }

        /*
         * F-88. The docblock above says "withdrawal by the applicant" and the
         * code never identified the applicant: it checked the tenant and the
         * status only. During the audit an employee withdrew a colleague's
         * pending request with it - 200, no notification to the victim.
         *
         * Withdrawal is the applicant's own act. An approver cancelling somebody
         * else's leave is a DECISION (status 'cancelled'), which goes through
         * decision() and its approve_leave gate - not through here.
         */
        if ((int) $leave->user_id !== (int) $context['user_id']) {
            return response()->json([
                'status'  => 0,
                'message' => 'You can only withdraw your own leave request.',
            ], 403);
        }

        if ($leave->status !== 'pending') {
            return response()->json([
                'status'  => 0,
                'message' => 'Only a pending leave request can be withdrawn',
            ], 422);
        }

        DB::table('hrms_emp_leaves')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $context['user_id'],
        ]);

        // Same reason as cancel(): a withdrawn request is nobody's to approve.
        $this->workflow->closeOpenSteps((int) $id);

        return response()->json(['status' => 1, 'message' => 'Leave request withdrawn successfully']);
    }

    /** A display name for a user id, or a stable label when there is none. */
    private function employeeName(int $userId): string
    {
        return DB::table('tbluser')
            ->selectRaw("TRIM(CONCAT_WS(' ', first_name, last_name)) AS n")
            ->where('id', $userId)
            ->value('n') ?: 'An employee';
    }

    /** The request's dates, as one human string for a notification body. */
    private function leaveDates(int $leaveId): string
    {
        $row = DB::table('hrms_emp_leaves')->where('id', $leaveId)->first(['from_date', 'to_date']);

        if (!$row) {
            return '';
        }

        return ($row->to_date && $row->to_date !== $row->from_date)
            ? $row->from_date . ' to ' . $row->to_date
            : (string) $row->from_date;
    }

    /** Shared write path for single and bulk decisions. */
    private function applyDecision(array $ids, string $status, array $hodComments, array $hrRemarks, array $context): int
    {
        // Resolve by id alone: an approver acting on an institute's requests is not
        // necessarily a member of it (the legacy leaveAuthorisationStore filtered on
        // sub_institute_id here and silently wrote a null approved_by whenever the two
        // differed). Fall back to a label rather than storing nothing.
        $approverName = DB::table('tbluser')
            ->selectRaw("TRIM(CONCAT_WS(' ', first_name, last_name)) AS employee_name")
            ->where('id', $context['user_id'])
            ->value('employee_name');

        $approverName = $approverName ?: 'User #' . $context['user_id'];

        $updated = 0;

        DB::transaction(function () use ($ids, $status, $hodComments, $hrRemarks, $approverName, $context, &$updated) {
            foreach ($ids as $id) {
                $payload = [
                    'status'      => $status,
                    'approved_by' => $approverName,
                    'updated_at'  => now(),
                    'updated_by'  => $context['user_id'],
                ];

                if (array_key_exists($id, $hodComments) && $hodComments[$id] !== null && $hodComments[$id] !== '') {
                    $payload['hod_comment']      = $hodComments[$id];
                    $payload['hod_comment_date'] = now();
                }

                if (array_key_exists($id, $hrRemarks) && $hrRemarks[$id] !== null && $hrRemarks[$id] !== '') {
                    $payload['hr_remarks']     = $hrRemarks[$id];
                    $payload['hr_remark_date'] = now();
                }

                $updated += DB::table('hrms_emp_leaves')
                    ->where('id', $id)
                    ->where('sub_institute_id', $context['sub_institute_id'])
                    ->update($payload);
            }
        });

        return $updated;
    }

    private function applyFilters($query, Request $request)
    {
        $search       = trim((string) $request->input('search', ''));
        $statuses     = $this->filterList($request->input('status'));
        $departments  = $this->filterList($request->input('department_id'));
        $leaveTypes   = $this->filterList($request->input('leave_type_id'));
        $employees    = $this->filterList($request->input('employee_id'));
        $fromDate     = $this->activeFilter($request->input('from_date'));
        $toDate       = $this->activeFilter($request->input('to_date'));

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->whereRaw("CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name) LIKE ?", [$like])
                  ->orWhere('u.employee_no', 'like', $like)
                  ->orWhere('u.email', 'like', $like)
                  ->orWhere('hel.id', 'like', $like)
                  ->orWhere('hel.comment', 'like', $like);
            });
        }

        if (!empty($statuses)) {
            $query->whereIn('hel.status', $statuses);
        }

        if (!empty($departments)) {
            $query->whereIn('u.department_id', $departments);
        }

        if (!empty($leaveTypes)) {
            $query->whereIn('hel.leave_type_id', $leaveTypes);
        }

        if (!empty($employees)) {
            $query->whereIn('hel.user_id', $employees);
        }

        // Overlap, not containment: a leave spanning the window edge still belongs in it.
        if ($fromDate) {
            $query->where('hel.to_date', '>=', $fromDate);
        }

        if ($toDate) {
            $query->where('hel.from_date', '<=', $toDate);
        }

        return $query;
    }

    /** Row shape consumed by the Next.js LeaveRequest type. */
    private function transform($row): array
    {
        $days = $this->analytics->requestDays($row->from_date, $row->to_date, $row->day_type, $row->chargeable_days ?? null);

        return [
            'id'             => (int) $row->id,
            'employee_id'    => (int) $row->user_id,
            'employee_no'    => $row->employee_no,
            'employee_name'  => trim(preg_replace('/\s+/', ' ', (string) $row->employee_name)),
            'designation'    => $row->designation ?: null,
            'email'          => $row->email,
            'mobile'         => $row->mobile,
            'avatar'         => $row->image ?: null,
            'department_id'  => (int) ($row->user_department_id ?? $row->department_id ?? 0),
            'department'     => $row->department ?: 'Unassigned',
            'leave_type_id'  => (int) $row->leave_type_id,
            'leave_type'     => $row->leave_type ?: 'Unassigned',
            'leave_type_code'=> $row->leave_type_code ?? null,
            'day_type'       => $row->day_type,
            'slot'           => $row->slot,
            'session'        => ((string) $row->day_type === '0.5') ? 'Half Day' : 'Full Day',
            'days'           => $days,
            'duration'       => $this->analytics->durationLabel($days),
            'from_date'      => $row->from_date,
            'to_date'        => $row->to_date,
            'status'         => $row->status,
            'reason'         => $row->comment,
            'hod_comment'    => $row->hod_comment,
            'hod_comment_date' => $row->hod_comment_date,
            'hr_remarks'     => $row->hr_remarks,
            'hr_remark_date' => $row->hr_remark_date,
            'approver'       => $row->approved_by,
            'submitted_date' => $row->created_at,
            'updated_date'   => $row->updated_at,
        ];
    }

    private function comments($row): array
    {
        $comments = [];

        if ($row->comment) {
            $comments[] = [
                'author'    => trim((string) $row->employee_name),
                'role'      => 'Employee',
                'body'      => $row->comment,
                'timestamp' => $row->created_at,
            ];
        }

        if ($row->hod_comment) {
            $comments[] = [
                'author'    => $row->approved_by ?: 'Department Head',
                'role'      => 'Department Head',
                'body'      => $row->hod_comment,
                'timestamp' => $row->hod_comment_date,
            ];
        }

        if ($row->hr_remarks) {
            $comments[] = [
                'author'    => $row->approved_by ?: 'HR',
                'role'      => 'HR',
                'body'      => $row->hr_remarks,
                'timestamp' => $row->hr_remark_date,
            ];
        }

        return $comments;
    }

    private function timeline($row): array
    {
        $timeline = [[
            'stage'     => 'Request submitted',
            'status'    => 'completed',
            'actor'     => trim((string) $row->employee_name),
            'timestamp' => $row->created_at,
        ]];

        if ($row->hod_comment_date) {
            $timeline[] = [
                'stage'     => 'Department head reviewed',
                'status'    => 'completed',
                'actor'     => $row->approved_by,
                'timestamp' => $row->hod_comment_date,
            ];
        }

        if ($row->hr_remark_date) {
            $timeline[] = [
                'stage'     => 'HR reviewed',
                'status'    => 'completed',
                'actor'     => $row->approved_by,
                'timestamp' => $row->hr_remark_date,
            ];
        }

        if ($row->status !== 'pending') {
            $decidedAt = $row->hr_remark_date ?: ($row->hod_comment_date ?: $row->updated_at);

            $timeline[] = [
                'stage'     => 'Request ' . str_replace('_', ' ', $row->status),
                'status'    => $row->status,
                'actor'     => $row->approved_by,
                'timestamp' => $decidedAt ? Carbon::parse($decidedAt)->toDateTimeString() : null,
            ];
        }

        return $timeline;
    }
}
