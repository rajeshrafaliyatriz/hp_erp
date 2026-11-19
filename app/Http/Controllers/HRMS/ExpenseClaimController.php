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
 * Expense claims: /api/expenses/claims.
 *
 *   draft -> submitted -> approved -> paid
 *                      \-> rejected -> (edited) -> submitted again
 *   draft | submitted -> cancelled
 *
 * ── WHO MAY DO WHAT, DECIDED HERE AND NOT BY THE SCREEN ─────────────────────
 *
 *   read    the owner; the manager the claim was routed to; HR/admin;
 *           executive and auditor (read-only, never drafts)
 *   edit    the owner, while draft or rejected
 *   submit  the owner, from draft or rejected
 *   cancel  the owner, from draft or submitted
 *   delete  the owner, drafts only
 *   approve / reject
 *           the manager the claim was routed to, or HR/admin - never the owner
 *   pay     HR/admin only, only an approved claim, never their own
 *
 * Tenant and actor always come from the token. The total is always the sum of
 * the lines, worked out here. Each response carries `can` flags so the screen
 * shows exactly the actions this server would accept and does not reimplement
 * these rules.
 */
class ExpenseClaimController extends Controller
{
    use ResolvesApiIdentity;
    use ExpenseRoles;

    private const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'paid', 'cancelled'];

    private const CLAIM_COLUMNS = [
        'c.id', 'c.claim_no', 'c.employee_id', 'c.title', 'c.description', 'c.total_amount', 'c.currency',
        'c.status', 'c.submitted_at', 'c.approver_id', 'c.decided_by', 'c.decided_at', 'c.decision_note',
        'c.paid_by', 'c.paid_at', 'c.payment_reference', 'c.created_at', 'c.updated_at',
        'c.advance_id', 'c.advance_adjusted',
    ];

    /** GET /api/expenses/claims?scope=mine|approvals&status= */
    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $userId = (int) $identity['user_id'];
        $role = RoleKey::forUserId($userId);

        $scope = $request->input('scope', 'mine') === 'approvals' ? 'approvals' : 'mine';

        $query = $this->claimQuery($tenantId)->orderByDesc('c.id');

        if ($scope === 'mine') {
            $query->where('c.employee_id', $userId);
        } else {
            $query->where('c.status', '!=', 'draft');

            if (!$this->canReadAll($role)) {
                // A manager sees the claims routed to them, never their own.
                $query->where('c.approver_id', $userId)->where('c.employee_id', '!=', $userId);
            }
        }

        // Totals are over the whole scope, so the tiles do not change with the filter.
        $summary = (clone $query)
            ->reorder()
            ->select('c.status', DB::raw('COUNT(*) as claims'), DB::raw('SUM(c.total_amount) as amount'))
            ->groupBy('c.status')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->status => ['claims' => (int) $r->claims, 'amount' => (float) $r->amount]]);

        if ($request->filled('status') && in_array($request->input('status'), self::STATUSES, true)) {
            $query->where('c.status', $request->input('status'));
        }

        $rows = $query->limit(500)->get($this->listSelect());

        return response()->json([
            'status' => 1,
            'message' => 'Success',
            'data' => $rows->map(fn ($row) => $this->present($row, $identity, $role, false))->values(),
            'summary' => $summary,
        ]);
    }

    /** GET /api/expenses/claims/{id} */
    public function show(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $claim = $this->findVisible((int) $id, $identity);
        if (!$claim) {
            return response()->json(['status' => 0, 'message' => 'Claim not found'], 404);
        }

        return response()->json([
            'status' => 1,
            'message' => 'Success',
            'data' => $this->present($claim, $identity, RoleKey::forUserId((int) $identity['user_id']), true),
        ]);
    }

    /** POST /api/expenses/claims   (submit=true files it straight away) */
    public function store(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $userId = (int) $identity['user_id'];

        if ($error = $this->validateBody($request)) {
            return $error;
        }
        if ($error = $this->checkItems($request->input('items', []), $tenantId, [])) {
            return $error;
        }

        $advance = $this->resolveAdvance($request, $tenantId, $userId);
        if ($advance instanceof \Illuminate\Http\JsonResponse) {
            return $advance;
        }

        $claimId = null;

        for ($attempt = 0; $attempt < 5 && $claimId === null; $attempt++) {
            try {
                $claimId = DB::transaction(function () use ($request, $tenantId, $userId, $advance) {
                    $id = DB::table('hrms_expense_claims')->insertGetId([
                        'sub_institute_id' => $tenantId,
                        'claim_no' => $this->nextClaimNo($tenantId),
                        'employee_id' => $userId,
                        'title' => trim((string) $request->input('title')),
                        'description' => $request->filled('description') ? $request->input('description') : null,
                        'currency' => 'INR',
                        'status' => 'draft',
                        'total_amount' => 0,
                        'advance_id' => $advance['id'],
                        'advance_adjusted' => $advance['amount'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $this->writeItems($id, $tenantId, $request->input('items', []));

                    return $id;
                });
            } catch (QueryException $e) {
                // Two people took the same number at once: try the next one.
                if ($attempt === 4 || !str_contains($e->getMessage(), 'hexpclaim_tenant_no_uniq')) {
                    throw $e;
                }
            }
        }

        if ($request->boolean('submit')) {
            $claim = $this->findVisible($claimId, $identity);
            if ($error = $this->submitClaim($claim, $tenantId)) {
                // It was never filed, and the employee is about to fix it and send it
                // again - so do not leave the half-made draft behind to be duplicated.
                DB::transaction(function () use ($claimId) {
                    DB::table('hrms_expense_items')->where('claim_id', $claimId)->delete();
                    DB::table('hrms_expense_claims')->where('id', $claimId)->delete();
                });

                return $error;
            }
        }

        return response()->json([
            'status' => 1,
            'message' => $request->boolean('submit') ? 'Claim submitted.' : 'Draft saved.',
            'data' => $this->present($this->findVisible($claimId, $identity), $identity, RoleKey::forUserId($userId), true),
        ], 201);
    }

    /** PUT /api/expenses/claims/{id} */
    public function update(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $userId = (int) $identity['user_id'];

        $claim = $this->findVisible((int) $id, $identity);
        if (!$claim) {
            return response()->json(['status' => 0, 'message' => 'Claim not found'], 404);
        }
        if ((int) $claim->employee_id !== $userId) {
            return response()->json(['status' => 0, 'message' => 'You can only edit your own claims.'], 403);
        }
        if (!in_array($claim->status, ['draft', 'rejected'], true)) {
            return response()->json(['status' => 0, 'message' => 'A claim can only be edited while it is a draft or rejected.'], 409);
        }

        if ($error = $this->validateBody($request)) {
            return $error;
        }

        // Categories already on this claim stay allowed even if since set Inactive.
        $existingCategories = DB::table('hrms_expense_items')
            ->where('claim_id', $claim->id)->pluck('category_id')->map(fn ($v) => (int) $v)->all();

        if ($error = $this->checkItems($request->input('items', []), $tenantId, $existingCategories)) {
            return $error;
        }

        $advance = $this->resolveAdvance($request, $tenantId, $userId);
        if ($advance instanceof \Illuminate\Http\JsonResponse) {
            return $advance;
        }

        DB::transaction(function () use ($request, $claim, $tenantId, $advance) {
            DB::table('hrms_expense_claims')->where('id', $claim->id)->update([
                'title' => trim((string) $request->input('title')),
                'description' => $request->filled('description') ? $request->input('description') : null,
                'advance_id' => $advance['id'],
                'advance_adjusted' => $advance['amount'],
                'updated_at' => now(),
            ]);

            DB::table('hrms_expense_items')->where('claim_id', $claim->id)->delete();
            $this->writeItems($claim->id, $tenantId, $request->input('items', []));
        });

        if ($request->boolean('submit')) {
            if ($error = $this->submitClaim($this->findVisible((int) $id, $identity), $tenantId)) {
                return $error;
            }
        }

        return response()->json([
            'status' => 1,
            'message' => $request->boolean('submit') ? 'Claim submitted.' : 'Changes saved.',
            'data' => $this->present($this->findVisible((int) $id, $identity), $identity, RoleKey::forUserId($userId), true),
        ]);
    }

    /** DELETE /api/expenses/claims/{id}   (drafts only) */
    public function destroy(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $claim = $this->findVisible((int) $id, $identity);
        if (!$claim) {
            return response()->json(['status' => 0, 'message' => 'Claim not found'], 404);
        }
        if ((int) $claim->employee_id !== (int) $identity['user_id']) {
            return response()->json(['status' => 0, 'message' => 'You can only delete your own claims.'], 403);
        }
        if ($claim->status !== 'draft') {
            return response()->json(['status' => 0, 'message' => 'Only a draft can be deleted. Cancel a submitted claim instead.'], 409);
        }

        DB::table('hrms_expense_claims')->where('id', $claim->id)->update(['deleted_at' => now()]);

        return response()->json(['status' => 1, 'message' => 'Draft deleted.']);
    }

    /** POST /api/expenses/claims/{id}/submit */
    public function submit(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $claim = $this->findVisible((int) $id, $identity);
        if (!$claim) {
            return response()->json(['status' => 0, 'message' => 'Claim not found'], 404);
        }
        if ((int) $claim->employee_id !== (int) $identity['user_id']) {
            return response()->json(['status' => 0, 'message' => 'You can only submit your own claims.'], 403);
        }

        if ($error = $this->submitClaim($claim, $identity['sub_institute_id'])) {
            return $error;
        }

        return $this->respond((int) $id, $identity, 'Claim submitted.');
    }

    /** POST /api/expenses/claims/{id}/cancel */
    public function cancel(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $claim = $this->findVisible((int) $id, $identity);
        if (!$claim) {
            return response()->json(['status' => 0, 'message' => 'Claim not found'], 404);
        }
        if ((int) $claim->employee_id !== (int) $identity['user_id']) {
            return response()->json(['status' => 0, 'message' => 'You can only cancel your own claims.'], 403);
        }
        if (!in_array($claim->status, ['draft', 'submitted'], true)) {
            return response()->json(['status' => 0, 'message' => 'Only a draft or a claim awaiting a decision can be cancelled.'], 409);
        }

        DB::table('hrms_expense_claims')->where('id', $claim->id)->update(['status' => 'cancelled', 'updated_at' => now()]);

        return $this->respond((int) $id, $identity, 'Claim cancelled.');
    }

    /** POST /api/expenses/claims/{id}/approve   { note? } */
    public function approve(Request $request, $id)
    {
        return $this->decide($request, (int) $id, 'approved');
    }

    /** POST /api/expenses/claims/{id}/reject   { note } */
    public function reject(Request $request, $id)
    {
        return $this->decide($request, (int) $id, 'rejected');
    }

    /** POST /api/expenses/claims/{id}/pay   { payment_reference? }   HR/admin, routes/api.php */
    public function pay(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $userId = (int) $identity['user_id'];

        $claim = $this->findVisible((int) $id, $identity);
        if (!$claim) {
            return response()->json(['status' => 0, 'message' => 'Claim not found'], 404);
        }
        if ($claim->status !== 'approved') {
            return response()->json(['status' => 0, 'message' => 'Only an approved claim can be marked paid.'], 409);
        }
        if ((int) $claim->employee_id === $userId) {
            return response()->json(['status' => 0, 'message' => 'You cannot settle your own claim. Ask another HR user.'], 403);
        }

        $validator = Validator::make($request->all(), ['payment_reference' => 'nullable|string|max:100']);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $error = null;

        DB::transaction(function () use ($claim, $userId, $request, $identity, &$error) {
            // The advance is only drawn down NOW, when the money moves - and re-checked
            // against the live balance, which another claim may have used since.
            if ((int) $claim->advance_id > 0 && (float) $claim->advance_adjusted > 0) {
                $advance = DB::table('hrms_expense_advances')
                    ->where('id', $claim->advance_id)
                    ->where('sub_institute_id', $identity['sub_institute_id'])
                    ->lockForUpdate()
                    ->first();

                $balance = $advance ? round((float) $advance->advance_amount - (float) $advance->claimed_amount, 2) : 0.0;

                if (!$advance || $advance->status !== 'paid' || (float) $claim->advance_adjusted > $balance + 0.001) {
                    $error = response()->json([
                        'status' => 0,
                        'message' => 'The advance no longer has enough balance for this claim (' . number_format($balance, 2)
                            . ' left). Ask the employee to edit the claim and adjust a smaller amount.',
                    ], 409);

                    return;
                }

                $claimed = round((float) $advance->claimed_amount + (float) $claim->advance_adjusted, 2);

                DB::table('hrms_expense_advances')->where('id', $advance->id)->update([
                    'claimed_amount' => $claimed,
                    'status' => $claimed >= (float) $advance->advance_amount - 0.001 ? 'claimed' : 'paid',
                    'updated_at' => now(),
                ]);
            }

            DB::table('hrms_expense_claims')->where('id', $claim->id)->update([
                'status' => 'paid',
                'paid_by' => $userId,
                'paid_at' => now(),
                'payment_reference' => $request->filled('payment_reference') ? trim($request->input('payment_reference')) : null,
                'updated_at' => now(),
            ]);
        });

        if ($error) {
            return $error;
        }

        return $this->respond((int) $id, $identity, 'Marked as paid.');
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

        $claim = $this->findVisible($id, $identity);
        if (!$claim) {
            return response()->json(['status' => 0, 'message' => 'Claim not found'], 404);
        }
        if ($claim->status !== 'submitted') {
            return response()->json(['status' => 0, 'message' => 'Only a submitted claim can be approved or rejected.'], 409);
        }
        if ((int) $claim->employee_id === $userId) {
            return response()->json(['status' => 0, 'message' => 'You cannot approve or reject your own claim.'], 403);
        }
        if (!$this->mayDecide($claim, $userId, $role)) {
            return response()->json(['status' => 0, 'message' => 'This claim is not yours to decide.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'note' => ($outcome === 'rejected' ? 'required' : 'nullable') . '|string|max:1000',
        ], ['note.required' => 'Say why the claim is being rejected.']);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        DB::table('hrms_expense_claims')->where('id', $claim->id)->update([
            'status' => $outcome,
            'decided_by' => $userId,
            'decided_at' => now(),
            'decision_note' => $request->filled('note') ? trim($request->input('note')) : null,
            'updated_at' => now(),
        ]);

        return $this->respond($id, $identity, $outcome === 'approved' ? 'Claim approved.' : 'Claim rejected.');
    }

    /** Move a draft/rejected claim to submitted, or return the reason it cannot. */
    private function submitClaim($claim, int $tenantId)
    {
        if (!in_array($claim->status, ['draft', 'rejected'], true)) {
            return response()->json(['status' => 0, 'message' => 'Only a draft or a rejected claim can be submitted.'], 409);
        }

        $items = DB::table('hrms_expense_items as i')
            ->join('hrms_expense_categories as k', 'k.id', '=', 'i.category_id')
            ->where('i.claim_id', $claim->id)
            ->get(['i.amount', 'i.receipt_ref', 'k.name as category', 'k.requires_receipt']);

        if ($items->isEmpty()) {
            return response()->json(['status' => 0, 'message' => 'Add at least one expense line before submitting.'], 422);
        }

        foreach ($items as $item) {
            if ($item->requires_receipt && trim((string) $item->receipt_ref) === '') {
                return response()->json([
                    'status' => 0,
                    'message' => "{$item->category} needs a bill / receipt reference.",
                ], 422);
            }
        }

        // Routed to whoever the employee reports to RIGHT NOW. Nobody -> HR's queue.
        $managerId = (int) DB::table('tbluser')
            ->where('id', $claim->employee_id)
            ->where('sub_institute_id', $tenantId)
            ->value('reporting_manager_id');

        DB::table('hrms_expense_claims')->where('id', $claim->id)->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'approver_id' => ($managerId > 0 && $managerId !== (int) $claim->employee_id) ? $managerId : null,
            'decided_by' => null,
            'decided_at' => null,
            'decision_note' => null,
            'updated_at' => now(),
        ]);

        return null;
    }

    private function validateBody(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'advance_id' => 'nullable|integer|min:1',
            'advance_amount' => 'nullable|numeric|gt:0|max:9999999999.99',
            'items' => 'nullable|array|max:100',
            'items.*.category_id' => 'required|integer|min:1',
            'items.*.expense_date' => 'required|date|before_or_equal:today',
            'items.*.amount' => 'required|numeric|gt:0|max:9999999999.99',
            'items.*.description' => 'nullable|string|max:255',
            'items.*.receipt_ref' => 'nullable|string|max:150',
        ], [
            'items.*.expense_date.before_or_equal' => 'An expense cannot be dated in the future.',
            'items.*.amount.gt' => 'Each amount must be more than zero.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        return null;
    }

    /**
     * Every category must be this organisation's and Active (or already on this
     * claim), and a line may not exceed its category's limit.
     *
     * @param array<int,array> $items
     * @param int[]            $alreadyOnClaim
     */
    private function checkItems(array $items, int $tenantId, array $alreadyOnClaim)
    {
        if ($items === []) {
            return null;
        }

        $categories = DB::table('hrms_expense_categories')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereIn('id', array_unique(array_map(fn ($i) => (int) $i['category_id'], $items)))
            ->get(['id', 'name', 'max_amount', 'status'])
            ->keyBy('id');

        foreach ($items as $item) {
            $category = $categories->get((int) $item['category_id']);

            if (!$category) {
                return response()->json(['status' => 0, 'message' => 'A category on this claim does not exist in your organisation.'], 422);
            }
            if ((int) $category->status !== 1 && !in_array((int) $category->id, $alreadyOnClaim, true)) {
                return response()->json(['status' => 0, 'message' => "{$category->name} is no longer available."], 422);
            }
            if ($category->max_amount !== null && (float) $item['amount'] > (float) $category->max_amount) {
                return response()->json([
                    'status' => 0,
                    'message' => "{$category->name} is limited to " . number_format((float) $category->max_amount, 2) . ' per expense.',
                ], 422);
            }
        }

        return null;
    }

    /** Insert the lines and refresh the claim's total from them. */
    private function writeItems(int $claimId, int $tenantId, array $items): void
    {
        $now = now();
        $total = 0.0;
        $rows = [];

        foreach ($items as $item) {
            $amount = round((float) $item['amount'], 2);
            $total += $amount;
            $rows[] = [
                'sub_institute_id' => $tenantId,
                'claim_id' => $claimId,
                'category_id' => (int) $item['category_id'],
                'expense_date' => date('Y-m-d', strtotime((string) $item['expense_date'])),
                'description' => !empty($item['description']) ? $item['description'] : null,
                'amount' => $amount,
                'receipt_ref' => !empty($item['receipt_ref']) ? trim($item['receipt_ref']) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('hrms_expense_items')->insert($rows);
        }

        DB::table('hrms_expense_claims')->where('id', $claimId)->update(['total_amount' => round($total, 2)]);
    }

    /**
     * The advance a claim wants to settle against, and how much of it.
     *
     * Returns ['id' => ?int, 'amount' => float], or a 422 response. The advance must
     * be the CALLER'S OWN, in this organisation, already PAID and with something
     * left. The amount defaults to as much as it can use (the smaller of the balance
     * and the claim total) and can never exceed either.
     */
    private function resolveAdvance(Request $request, int $tenantId, int $userId)
    {
        if (!$request->filled('advance_id')) {
            return ['id' => null, 'amount' => 0.0];
        }

        $advance = DB::table('hrms_expense_advances')
            ->where('id', (int) $request->input('advance_id'))
            ->where('sub_institute_id', $tenantId)
            ->where('employee_id', $userId)
            ->whereNull('deleted_at')
            ->first();

        if (!$advance || $advance->status !== 'paid') {
            return response()->json(['status' => 0, 'message' => 'That advance is not available to settle against.'], 422);
        }

        $balance = round((float) $advance->advance_amount - (float) $advance->claimed_amount, 2);
        if ($balance <= 0) {
            return response()->json(['status' => 0, 'message' => 'That advance has no balance left.'], 422);
        }

        $total = round(array_sum(array_map(fn ($i) => (float) ($i['amount'] ?? 0), $request->input('items', []))), 2);
        $amount = $request->filled('advance_amount')
            ? round((float) $request->input('advance_amount'), 2)
            : min($balance, $total);

        if ($amount <= 0) {
            return response()->json(['status' => 0, 'message' => 'Add expense lines before settling against an advance.'], 422);
        }
        if ($amount > $balance + 0.001) {
            return response()->json(['status' => 0, 'message' => 'Only ' . number_format($balance, 2) . ' is left on that advance.'], 422);
        }
        if ($amount > $total + 0.001) {
            return response()->json(['status' => 0, 'message' => 'The advance adjusted cannot be more than the claim total.'], 422);
        }

        return ['id' => (int) $advance->id, 'amount' => $amount];
    }

    private function nextClaimNo(int $tenantId): string
    {
        $prefix = 'EXP-' . date('Y') . '-';

        // Soft-deleted claims still hold their number, so they are counted.
        $last = DB::table('hrms_expense_claims')
            ->where('sub_institute_id', $tenantId)
            ->where('claim_no', 'like', $prefix . '%')
            ->max('claim_no');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    // ── Reading and presenting ──────────────────────────────────────────────

    private function claimQuery(int $tenantId)
    {
        return DB::table('hrms_expense_claims as c')
            ->leftJoin('tbluser as u', 'u.id', '=', 'c.employee_id')
            ->where('c.sub_institute_id', $tenantId)
            ->whereNull('c.deleted_at');
    }

    private function listSelect(): array
    {
        return array_merge(self::CLAIM_COLUMNS, [
            DB::raw("TRIM(CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.last_name, ''))) as employee_name"),
            DB::raw('(SELECT COUNT(*) FROM hrms_expense_items i WHERE i.claim_id = c.id) as item_count'),
            DB::raw('(SELECT a.advance_no FROM hrms_expense_advances a WHERE a.id = c.advance_id) as advance_no'),
        ]);
    }

    /** One claim, only if this caller may read it. */
    private function findVisible(int $id, array $identity)
    {
        $claim = $this->claimQuery($identity['sub_institute_id'])
            ->where('c.id', $id)
            ->first($this->listSelect());

        if (!$claim) {
            return null;
        }

        $userId = (int) $identity['user_id'];
        $role = RoleKey::forUserId($userId);

        if ((int) $claim->employee_id === $userId) {
            return $claim;
        }
        if ($claim->status === 'draft') {
            return null;   // somebody else's draft is not for anybody else to see
        }
        if ($this->canReadAll($role) || (int) $claim->approver_id === $userId) {
            return $claim;
        }

        return null;
    }

    private function present($claim, array $identity, ?string $role, bool $withItems): array
    {
        $userId = (int) $identity['user_id'];
        $isOwner = (int) $claim->employee_id === $userId;
        $isHr = $this->isHr($role);
        $status = $claim->status;

        $out = [
            'id' => (int) $claim->id,
            'claim_no' => $claim->claim_no,
            'employee_id' => (int) $claim->employee_id,
            'employee_name' => $claim->employee_name ?: '—',
            'title' => $claim->title,
            'description' => $claim->description,
            'total_amount' => (float) $claim->total_amount,
            'currency' => $claim->currency,
            'advance_id' => $claim->advance_id ? (int) $claim->advance_id : null,
            'advance_no' => $claim->advance_no ?? null,
            'advance_adjusted' => (float) ($claim->advance_adjusted ?? 0),
            // What is actually paid out: the total less what the advance already covered.
            'net_payable' => round((float) $claim->total_amount - (float) ($claim->advance_adjusted ?? 0), 2),
            'status' => $status,
            'item_count' => (int) ($claim->item_count ?? 0),
            'submitted_at' => $claim->submitted_at,
            'decided_at' => $claim->decided_at,
            'decision_note' => $claim->decision_note,
            'paid_at' => $claim->paid_at,
            'payment_reference' => $claim->payment_reference,
            'created_at' => $claim->created_at,
            'can' => [
                'edit' => $isOwner && in_array($status, ['draft', 'rejected'], true),
                'submit' => $isOwner && in_array($status, ['draft', 'rejected'], true),
                'cancel' => $isOwner && in_array($status, ['draft', 'submitted'], true),
                'delete' => $isOwner && $status === 'draft',
                'decide' => !$isOwner && $status === 'submitted' && $this->mayDecide($claim, $userId, $role),
                'pay' => $isHr && !$isOwner && $status === 'approved',
            ],
        ];

        if ($withItems) {
            $out['items'] = DB::table('hrms_expense_items as i')
                ->leftJoin('hrms_expense_categories as k', 'k.id', '=', 'i.category_id')
                ->where('i.claim_id', $claim->id)
                ->orderBy('i.expense_date')
                ->orderBy('i.id')
                ->get(['i.id', 'i.category_id', 'k.name as category_name', 'i.expense_date', 'i.description', 'i.amount', 'i.receipt_ref'])
                ->map(function ($i) {
                    $i->amount = (float) $i->amount;

                    return $i;
                })
                ->all();
        }

        return $out;
    }

    private function respond(int $id, array $identity, string $message)
    {
        return response()->json([
            'status' => 1,
            'message' => $message,
            'data' => $this->present($this->findVisible($id, $identity), $identity, RoleKey::forUserId((int) $identity['user_id']), true),
        ]);
    }
}
