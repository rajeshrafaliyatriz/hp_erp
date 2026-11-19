<?php

namespace App\Http\Controllers\HRMS;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Http\Controllers\HRMS\Concerns\ExpenseRoles;
use App\Support\RoleKey;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Employee advances: /api/expenses/advances.
 *
 *   requested -> approved -> paid -> claimed (balance used up by paid claims)
 *             \-> rejected
 *   requested -> cancelled
 *
 * Who may do what is decided here, the same as claims: the owner reads and
 * cancels their own; the manager it was routed to (or HR/admin) decides; only
 * HR/admin pays, and never their own. Executive and auditor can read.
 * `claimed_amount` is never taken from a request - the claim controller raises
 * it when a claim that uses the advance is paid.
 */
class ExpenseAdvanceController extends Controller
{
    use ResolvesApiIdentity;
    use ExpenseRoles;

    private const STATUSES = ['requested', 'approved', 'rejected', 'paid', 'claimed', 'cancelled'];

    private const COLUMNS = [
        'a.id', 'a.advance_no', 'a.employee_id', 'a.purpose', 'a.needed_by', 'a.note', 'a.advance_amount',
        'a.claimed_amount', 'a.currency', 'a.status', 'a.requested_at', 'a.approver_id', 'a.decided_by',
        'a.decided_at', 'a.decision_note', 'a.paid_by', 'a.paid_at', 'a.payment_reference', 'a.created_at',
    ];

    /** GET /api/expenses/advances?scope=mine|approvals&status= */
    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $userId = (int) $identity['user_id'];
        $role = RoleKey::forUserId($userId);

        $query = $this->query($identity['sub_institute_id'])->orderByDesc('a.id');

        if ($request->input('scope', 'mine') === 'approvals') {
            if (!$this->canReadAll($role)) {
                $query->where('a.approver_id', $userId)->where('a.employee_id', '!=', $userId);
            }
        } else {
            $query->where('a.employee_id', $userId);
        }

        $summary = (clone $query)
            ->reorder()
            ->select('a.status', DB::raw('COUNT(*) as advances'), DB::raw('SUM(a.advance_amount) as amount'), DB::raw('SUM(a.claimed_amount) as claimed'))
            ->groupBy('a.status')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->status => [
                'advances' => (int) $r->advances,
                'amount' => (float) $r->amount,
                'claimed' => (float) $r->claimed,
            ]]);

        if ($request->filled('status') && in_array($request->input('status'), self::STATUSES, true)) {
            $query->where('a.status', $request->input('status'));
        }

        $rows = $query->limit(500)->get($this->select());

        return response()->json([
            'status' => 1,
            'message' => 'Success',
            'data' => $rows->map(fn ($row) => $this->present($row, $identity, $role))->values(),
            'summary' => $summary,
        ]);
    }

    /**
     * GET /api/expenses/advances/open
     *
     * The caller's OWN paid advances that still have a balance - what the claim
     * form offers to settle against.
     */
    public function open(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $rows = $this->query($identity['sub_institute_id'])
            ->where('a.employee_id', (int) $identity['user_id'])
            ->where('a.status', 'paid')
            ->whereRaw('a.advance_amount > a.claimed_amount')
            ->orderBy('a.id')
            ->get($this->select())
            ->map(fn ($row) => $this->present($row, $identity, null))
            ->values();

        return response()->json(['status' => 1, 'message' => 'Success', 'data' => $rows]);
    }

    /** GET /api/expenses/advances/{id} */
    public function show(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $advance = $this->findVisible((int) $id, $identity);
        if (!$advance) {
            return response()->json(['status' => 0, 'message' => 'Advance not found'], 404);
        }

        return $this->respond($advance, $identity, 'Success');
    }

    /** POST /api/expenses/advances */
    public function store(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $userId = (int) $identity['user_id'];

        $validator = Validator::make($request->all(), [
            'purpose' => 'required|string|max:255',
            'advance_amount' => 'required|numeric|gt:0|max:9999999999.99',
            'needed_by' => 'nullable|date|after_or_equal:today',
            'note' => 'nullable|string|max:2000',
        ], [
            'needed_by.after_or_equal' => 'The needed-by date cannot be in the past.',
            'advance_amount.gt' => 'The amount must be more than zero.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        // Routed to whoever they report to RIGHT NOW. Nobody -> HR's queue.
        $managerId = (int) DB::table('tbluser')
            ->where('id', $userId)->where('sub_institute_id', $tenantId)->value('reporting_manager_id');

        $id = null;

        for ($attempt = 0; $attempt < 5 && $id === null; $attempt++) {
            try {
                $id = DB::table('hrms_expense_advances')->insertGetId([
                    'sub_institute_id' => $tenantId,
                    'advance_no' => $this->nextNo($tenantId),
                    'employee_id' => $userId,
                    'purpose' => trim((string) $request->input('purpose')),
                    'needed_by' => $request->filled('needed_by') ? date('Y-m-d', strtotime((string) $request->input('needed_by'))) : null,
                    'note' => $request->filled('note') ? $request->input('note') : null,
                    'advance_amount' => round((float) $request->input('advance_amount'), 2),
                    'claimed_amount' => 0,
                    'currency' => 'INR',
                    'status' => 'requested',
                    'requested_at' => now(),
                    'approver_id' => ($managerId > 0 && $managerId !== $userId) ? $managerId : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException $e) {
                // Two people took the same number at once: try the next one.
                if ($attempt === 4 || !str_contains($e->getMessage(), 'hexpadv_tenant_no_uniq')) {
                    throw $e;
                }
            }
        }

        return $this->respond($this->findVisible($id, $identity), $identity, 'Advance requested.', 201);
    }

    /** POST /api/expenses/advances/{id}/cancel */
    public function cancel(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $advance = $this->findVisible((int) $id, $identity);
        if (!$advance) {
            return response()->json(['status' => 0, 'message' => 'Advance not found'], 404);
        }
        if ((int) $advance->employee_id !== (int) $identity['user_id']) {
            return response()->json(['status' => 0, 'message' => 'You can only cancel your own advances.'], 403);
        }
        if ($advance->status !== 'requested') {
            return response()->json(['status' => 0, 'message' => 'Only an advance still awaiting a decision can be cancelled.'], 409);
        }

        DB::table('hrms_expense_advances')->where('id', $advance->id)->update(['status' => 'cancelled', 'updated_at' => now()]);

        return $this->respond($this->findVisible((int) $id, $identity), $identity, 'Advance cancelled.');
    }

    /** POST /api/expenses/advances/{id}/approve   { note? } */
    public function approve(Request $request, $id)
    {
        return $this->decide($request, (int) $id, 'approved');
    }

    /** POST /api/expenses/advances/{id}/reject   { note } */
    public function reject(Request $request, $id)
    {
        return $this->decide($request, (int) $id, 'rejected');
    }

    /** POST /api/expenses/advances/{id}/pay   { payment_reference? }   HR/admin, routes/api.php */
    public function pay(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $userId = (int) $identity['user_id'];

        $advance = $this->findVisible((int) $id, $identity);
        if (!$advance) {
            return response()->json(['status' => 0, 'message' => 'Advance not found'], 404);
        }
        if ($advance->status !== 'approved') {
            return response()->json(['status' => 0, 'message' => 'Only an approved advance can be paid.'], 409);
        }
        if ((int) $advance->employee_id === $userId) {
            return response()->json(['status' => 0, 'message' => 'You cannot pay out your own advance. Ask another HR user.'], 403);
        }

        $validator = Validator::make($request->all(), ['payment_reference' => 'nullable|string|max:100']);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        DB::table('hrms_expense_advances')->where('id', $advance->id)->update([
            'status' => 'paid',
            'paid_by' => $userId,
            'paid_at' => now(),
            'payment_reference' => $request->filled('payment_reference') ? trim($request->input('payment_reference')) : null,
            'updated_at' => now(),
        ]);

        return $this->respond($this->findVisible((int) $id, $identity), $identity, 'Advance paid.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function decide(Request $request, int $id, string $outcome)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $userId = (int) $identity['user_id'];
        $role = RoleKey::forUserId($userId);

        $advance = $this->findVisible($id, $identity);
        if (!$advance) {
            return response()->json(['status' => 0, 'message' => 'Advance not found'], 404);
        }
        if ($advance->status !== 'requested') {
            return response()->json(['status' => 0, 'message' => 'Only a requested advance can be approved or rejected.'], 409);
        }
        if ((int) $advance->employee_id === $userId) {
            return response()->json(['status' => 0, 'message' => 'You cannot approve or reject your own advance.'], 403);
        }
        if (!$this->mayDecide($advance, $userId, $role)) {
            return response()->json(['status' => 0, 'message' => 'This advance is not yours to decide.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'note' => ($outcome === 'rejected' ? 'required' : 'nullable') . '|string|max:1000',
        ], ['note.required' => 'Say why the advance is being rejected.']);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        DB::table('hrms_expense_advances')->where('id', $advance->id)->update([
            'status' => $outcome,
            'decided_by' => $userId,
            'decided_at' => now(),
            'decision_note' => $request->filled('note') ? trim($request->input('note')) : null,
            'updated_at' => now(),
        ]);

        return $this->respond($this->findVisible($id, $identity), $identity, $outcome === 'approved' ? 'Advance approved.' : 'Advance rejected.');
    }

    private function nextNo(int $tenantId): string
    {
        $prefix = 'ADV-' . date('Y') . '-';

        // Soft-deleted rows still hold their number, so they count.
        $last = DB::table('hrms_expense_advances')
            ->where('sub_institute_id', $tenantId)
            ->where('advance_no', 'like', $prefix . '%')
            ->max('advance_no');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function query(int $tenantId)
    {
        return DB::table('hrms_expense_advances as a')
            ->leftJoin('tbluser as u', 'u.id', '=', 'a.employee_id')
            ->where('a.sub_institute_id', $tenantId)
            ->whereNull('a.deleted_at');
    }

    private function select(): array
    {
        return array_merge(self::COLUMNS, [
            DB::raw("TRIM(CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.last_name, ''))) as employee_name"),
        ]);
    }

    /** One advance, only if this caller may read it. */
    private function findVisible(int $id, array $identity)
    {
        $advance = $this->query($identity['sub_institute_id'])->where('a.id', $id)->first($this->select());

        if (!$advance) {
            return null;
        }

        $userId = (int) $identity['user_id'];

        if ((int) $advance->employee_id === $userId
            || (int) $advance->approver_id === $userId
            || $this->canReadAll(RoleKey::forUserId($userId))) {
            return $advance;
        }

        return null;
    }

    private function present($advance, array $identity, ?string $role): array
    {
        $userId = (int) $identity['user_id'];
        $isOwner = (int) $advance->employee_id === $userId;
        $amount = (float) $advance->advance_amount;
        $claimed = (float) $advance->claimed_amount;

        // Only a paid advance has money out; before that nothing is unclaimed.
        $unclaimed = in_array($advance->status, ['paid', 'claimed'], true) ? max(round($amount - $claimed, 2), 0) : 0.0;

        return [
            'id' => (int) $advance->id,
            'advance_no' => $advance->advance_no,
            'employee_id' => (int) $advance->employee_id,
            'employee_name' => $advance->employee_name ?: '—',
            'purpose' => $advance->purpose,
            'needed_by' => $advance->needed_by,
            'note' => $advance->note,
            'advance_amount' => $amount,
            'claimed_amount' => $claimed,
            'unclaimed_amount' => $unclaimed,
            'currency' => $advance->currency,
            'status' => $advance->status,
            'requested_at' => $advance->requested_at,
            'decided_at' => $advance->decided_at,
            'decision_note' => $advance->decision_note,
            'paid_at' => $advance->paid_at,
            'payment_reference' => $advance->payment_reference,
            'created_at' => $advance->created_at,
            'can' => [
                'cancel' => $isOwner && $advance->status === 'requested',
                'decide' => !$isOwner && $advance->status === 'requested' && $this->mayDecide($advance, $userId, $role ?? RoleKey::forUserId($userId)),
                'pay' => $this->isHr($role ?? RoleKey::forUserId($userId)) && !$isOwner && $advance->status === 'approved',
            ],
        ];
    }

    private function respond($advance, array $identity, string $message, int $code = 200)
    {
        return response()->json([
            'status' => 1,
            'message' => $message,
            'data' => $this->present($advance, $identity, RoleKey::forUserId((int) $identity['user_id'])),
        ], $code);
    }
}
