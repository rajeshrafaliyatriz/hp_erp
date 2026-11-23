<?php

namespace App\Http\Controllers\HRMS;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Expense categories: /api/expenses/categories.
 *
 * Reads need a token (the claim form lists them); writes are HR/admin only,
 * gated in routes/api.php. Tenant and actor come from the token.
 *
 * A category that has expense lines against it cannot be deleted - that would
 * orphan history. Set it Inactive instead: it leaves the claim form's list and
 * every existing claim keeps reading it.
 */
class ExpenseCategoryController extends Controller
{
    use ResolvesApiIdentity;

    private const COLUMNS = [
        'id', 'name', 'code', 'description', 'max_amount', 'requires_receipt', 'status', 'created_at',
    ];

    /** GET /api/expenses/categories  (?status=1 for active only) */
    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $query = DB::table('hrms_expense_categories')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->orderBy('name');

        if ($request->filled('status')) {
            $query->where('status', (int) $request->input('status'));
        }

        $rows = $query->get(self::COLUMNS);

        $used = DB::table('hrms_expense_items')
            ->where('sub_institute_id', $tenantId)
            ->groupBy('category_id')
            ->select('category_id', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'category_id');

        $rows = $rows->map(function ($row) use ($used) {
            $row->requires_receipt = (bool) $row->requires_receipt;
            $row->max_amount = $row->max_amount !== null ? (float) $row->max_amount : null;
            $row->item_count = (int) ($used[$row->id] ?? 0);

            return $row;
        });

        return response()->json(['status' => 1, 'message' => 'Success', 'data' => $rows]);
    }

    /** POST /api/expenses/categories */
    public function store(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        if ($error = $this->validateInput($request, $tenantId, null)) {
            return $error;
        }

        $id = DB::table('hrms_expense_categories')->insertGetId(array_merge($this->payload($request), [
            'sub_institute_id' => $tenantId,
            'created_by' => $identity['user_id'],
            'updated_by' => $identity['user_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return response()->json([
            'status' => 1,
            'message' => 'Category created.',
            'data' => $this->find($id, $tenantId),
        ], 201);
    }

    /** PUT /api/expenses/categories/{id} */
    public function update(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        if (!$this->find((int) $id, $tenantId)) {
            return response()->json(['status' => 0, 'message' => 'Category not found'], 404);
        }

        if ($error = $this->validateInput($request, $tenantId, (int) $id)) {
            return $error;
        }

        DB::table('hrms_expense_categories')
            ->where('id', (int) $id)
            ->where('sub_institute_id', $tenantId)
            ->update(array_merge($this->payload($request), [
                'updated_by' => $identity['user_id'],
                'updated_at' => now(),
            ]));

        return response()->json([
            'status' => 1,
            'message' => 'Category updated.',
            'data' => $this->find((int) $id, $tenantId),
        ]);
    }

    /** DELETE /api/expenses/categories/{id} */
    public function destroy(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        if (!$this->find((int) $id, $tenantId)) {
            return response()->json(['status' => 0, 'message' => 'Category not found'], 404);
        }

        $used = DB::table('hrms_expense_items')
            ->where('sub_institute_id', $tenantId)
            ->where('category_id', (int) $id)
            ->count();

        if ($used > 0) {
            return response()->json([
                'status' => 0,
                'message' => 'This category is used on ' . $used . ' expense line' . ($used === 1 ? '' : 's')
                    . ', so it cannot be deleted. Set it to Inactive instead.',
                'item_count' => $used,
            ], 409);
        }

        DB::table('hrms_expense_categories')
            ->where('id', (int) $id)
            ->where('sub_institute_id', $tenantId)
            ->update(['deleted_at' => now(), 'updated_by' => $identity['user_id']]);

        return response()->json(['status' => 1, 'message' => 'Category deleted.']);
    }

    /** Validation plus the one-name-per-tenant check; returns a response on failure. */
    private function validateInput(Request $request, int $tenantId, ?int $ignoreId)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'code' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:1000',
            'max_amount' => 'nullable|numeric|min:0|max:9999999999.99',
            'requires_receipt' => 'nullable|boolean',
            'status' => 'nullable|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $taken = DB::table('hrms_expense_categories')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $request->input('name')))])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($taken) {
            return response()->json(['status' => 0, 'message' => 'A category with this name already exists.'], 422);
        }

        return null;
    }

    /** Only this table's own fields. */
    private function payload(Request $request): array
    {
        return [
            'name' => trim((string) $request->input('name')),
            'code' => $request->filled('code') ? trim((string) $request->input('code')) : null,
            'description' => $request->filled('description') ? $request->input('description') : null,
            'max_amount' => $request->filled('max_amount') ? round((float) $request->input('max_amount'), 2) : null,
            'requires_receipt' => $request->boolean('requires_receipt'),
            'status' => (int) $request->input('status', 1),
        ];
    }

    private function find(int $id, int $tenantId)
    {
        $row = DB::table('hrms_expense_categories')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first(self::COLUMNS);

        if ($row) {
            $row->requires_receipt = (bool) $row->requires_receipt;
            $row->max_amount = $row->max_amount !== null ? (float) $row->max_amount : null;
        }

        return $row;
    }
}
