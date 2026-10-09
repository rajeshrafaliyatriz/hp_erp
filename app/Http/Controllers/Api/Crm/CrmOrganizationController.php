<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Organizations - the legacy CRM's "Accounts" module, relabeled. Internal
 * name stays Organization/crm_organizations everywhere; "Organizations" is
 * a presentation-layer label only.
 */
class CrmOrganizationController extends Controller
{
    use ResolvesApiIdentity;

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = max(1, (int) $request->input('page', 1));

        $query = DB::table('crm_organizations')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($accountType = $request->input('account_type')) {
            $query->where('account_type', $accountType);
        }

        $sortableColumns = ['name', 'account_type', 'industry', 'rating', 'billing_city', 'created_at'];
        $sortBy = in_array($request->input('sort_by'), $sortableColumns, true) ? $request->input('sort_by') : 'name';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'desc' ? 'desc' : 'asc';

        $total = (clone $query)->count();
        $rows = $query->orderBy($sortBy, $sortDir)->forPage($page, $perPage)->get();

        return response()->json([
            'status' => 1,
            'message' => 'Organizations.',
            'data' => [
                'items' => $rows->map(fn ($row) => $this->resource($row))->all(),
                'pagination' => [
                    'current_page' => $page,
                    'last_page' => (int) max(1, ceil($total / $perPage)),
                    'per_page' => $perPage,
                    'total' => $total,
                ],
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $row = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Organization not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Organization.', 'data' => $this->resource($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:191',
            'email' => 'nullable|email|max:191',
            'assignedTo' => 'required|integer',
            'parentId' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $nameClash = DB::table('crm_organizations')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->where('name', $request->input('name'))
            ->whereNull('deleted_at')
            ->exists();

        if ($nameClash) {
            return response()->json(['status' => 0, 'message' => 'An organization with that name already exists.'], 422);
        }

        $data = $this->payload($request);
        $data['account_no'] = 'ORG-' . Str::upper(Str::random(8));
        $data['sub_institute_id'] = $identity['sub_institute_id'];
        $data['created_by'] = $identity['user_id'];
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $id = DB::table('crm_organizations')->insertGetId($data);
        $row = DB::table('crm_organizations')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'Organization created.', 'data' => $this->resource($row)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Organization not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:191',
            'email' => 'nullable|email|max:191',
            'assignedTo' => 'sometimes|required|integer',
            'parentId' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        if ($request->filled('parentId') && (int) $request->input('parentId') === $id) {
            return response()->json(['status' => 0, 'message' => 'An organization cannot be its own parent.'], 422);
        }

        if ($request->has('name')) {
            $nameClash = DB::table('crm_organizations')
                ->where('sub_institute_id', $identity['sub_institute_id'])
                ->where('name', $request->input('name'))
                ->where('id', '!=', $id)
                ->whereNull('deleted_at')
                ->exists();

            if ($nameClash) {
                return response()->json(['status' => 0, 'message' => 'An organization with that name already exists.'], 422);
            }
        }

        $data = $this->payload($request, forUpdate: true);
        $data['updated_by'] = $identity['user_id'];
        $data['updated_at'] = now();

        DB::table('crm_organizations')->where('id', $id)->update($data);
        $row = DB::table('crm_organizations')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'Organization updated.', 'data' => $this->resource($row)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Organization not found.'], 404);
        }

        $hasChildren = DB::table('crm_organizations')->where('parent_id', $id)->whereNull('deleted_at')->exists();

        if ($hasChildren) {
            return response()->json(['status' => 0, 'message' => 'Re-parent or remove this organization’s child organizations first.'], 422);
        }

        DB::table('crm_organizations')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $identity['user_id'],
        ]);

        return response()->json(['status' => 1, 'message' => 'Organization moved to Recycle Bin.']);
    }

    /**
     * The parent/child tree, cycle-guarded - mirrors the legacy CRM's
     * `Accounts::getAccountHierarchy()` algorithm (upward to the topmost
     * ancestor, downward to every descendant), confirmed from research.
     */
    public function hierarchy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $root = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $root) {
            return response()->json(['status' => 0, 'message' => 'Organization not found.'], 404);
        }

        $all = DB::table('crm_organizations')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'parent_id'])
            ->keyBy('id');

        $ancestors = [];
        $seen = [(int) $id => true];
        $cursor = $root;

        while ($cursor && $cursor->parent_id && ! isset($seen[(int) $cursor->parent_id])) {
            $parent = $all->get((int) $cursor->parent_id);
            if (! $parent) {
                break;
            }
            $seen[(int) $parent->id] = true;
            array_unshift($ancestors, ['id' => (string) $parent->id, 'name' => $parent->name]);
            $cursor = $parent;
        }

        $descendants = [];
        $this->collectDescendants((int) $id, $all, $descendants, $seen);

        return response()->json([
            'status' => 1,
            'message' => 'Organization hierarchy.',
            'data' => [
                'ancestors' => $ancestors,
                'current' => ['id' => (string) $root->id, 'name' => $root->name],
                'descendants' => $descendants,
            ],
        ]);
    }

    /** @param array<int, array{id: string, name: string, depth: int}> $out */
    private function collectDescendants(int $parentId, $all, array &$out, array &$seen, int $depth = 1): void
    {
        foreach ($all as $row) {
            if ((int) $row->parent_id !== $parentId || isset($seen[(int) $row->id])) {
                continue;
            }

            $seen[(int) $row->id] = true;
            $out[] = ['id' => (string) $row->id, 'name' => $row->name, 'depth' => $depth];
            $this->collectDescendants((int) $row->id, $all, $out, $seen, $depth + 1);
        }
    }

    /**
     * Single-record ownership transfer, with an opt-in cascade to this
     * organization's own Contacts - the one direct related-module this app
     * actually has (the legacy system's Potentials/Quotes/etc. cascade
     * targets don't exist here). Mirrors the legacy's "only the owner field
     * changes, cascade is opt-in" behavior, confirmed from research.
     */
    public function transferOwnership(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Organization not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'assignedTo' => 'required|integer',
            'cascadeToContacts' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $assignedTo = (int) $request->input('assignedTo');

        DB::table('crm_organizations')->where('id', $id)->update([
            'assigned_to' => $assignedTo,
            'updated_by' => $identity['user_id'],
            'updated_at' => now(),
        ]);

        $contactsTransferred = 0;

        if ($request->boolean('cascadeToContacts')) {
            $contactsTransferred = DB::table('crm_contacts')
                ->where('organization_id', $id)
                ->where('sub_institute_id', $identity['sub_institute_id'])
                ->whereNull('deleted_at')
                ->update(['assigned_to' => $assignedTo, 'updated_by' => $identity['user_id'], 'updated_at' => now()]);
        }

        return response()->json([
            'status' => 1,
            'message' => $contactsTransferred > 0
                ? "Ownership transferred, including {$contactsTransferred} contact(s)."
                : 'Ownership transferred.',
        ]);
    }

    private function ownRow(int $tenantId, int $id): ?object
    {
        return DB::table('crm_organizations')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, bool $forUpdate = false): array
    {
        $map = [
            'name' => 'name', 'parentId' => 'parent_id', 'accountType' => 'account_type',
            'industry' => 'industry', 'rating' => 'rating', 'ownership' => 'ownership',
            'annualRevenue' => 'annual_revenue', 'employees' => 'employees', 'sicCode' => 'sic_code',
            'tickerSymbol' => 'ticker_symbol', 'phone' => 'phone', 'secondaryPhone' => 'secondary_phone',
            'email' => 'email', 'secondaryEmail' => 'secondary_email', 'website' => 'website', 'fax' => 'fax',
            'emailOptOut' => 'email_opt_out', 'billingStreet' => 'billing_street', 'billingCity' => 'billing_city',
            'billingState' => 'billing_state', 'billingCode' => 'billing_code', 'billingCountry' => 'billing_country',
            'billingPoBox' => 'billing_po_box', 'shippingStreet' => 'shipping_street', 'shippingCity' => 'shipping_city',
            'shippingState' => 'shipping_state', 'shippingCode' => 'shipping_code', 'shippingCountry' => 'shipping_country',
            'shippingPoBox' => 'shipping_po_box', 'description' => 'description', 'assignedTo' => 'assigned_to',
        ];

        $data = [];

        foreach ($map as $requestKey => $column) {
            if ($forUpdate && ! $request->has($requestKey)) {
                continue;
            }

            $data[$column] = $request->input($requestKey);
        }

        if (array_key_exists('email_opt_out', $data)) {
            $data['email_opt_out'] = (bool) $data['email_opt_out'];
        }

        if (array_key_exists('parent_id', $data) && $data['parent_id'] === '') {
            $data['parent_id'] = null;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function resource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'accountNo' => $row->account_no,
            'name' => $row->name,
            'parentId' => $row->parent_id ? (string) $row->parent_id : null,
            'accountType' => $row->account_type,
            'industry' => $row->industry,
            'rating' => $row->rating,
            'ownership' => $row->ownership,
            'annualRevenue' => $row->annual_revenue !== null ? (float) $row->annual_revenue : null,
            'employees' => $row->employees !== null ? (int) $row->employees : null,
            'sicCode' => $row->sic_code,
            'tickerSymbol' => $row->ticker_symbol,
            'phone' => $row->phone,
            'secondaryPhone' => $row->secondary_phone,
            'email' => $row->email,
            'secondaryEmail' => $row->secondary_email,
            'website' => $row->website,
            'fax' => $row->fax,
            'emailOptOut' => (bool) $row->email_opt_out,
            'billingStreet' => $row->billing_street,
            'billingCity' => $row->billing_city,
            'billingState' => $row->billing_state,
            'billingCode' => $row->billing_code,
            'billingCountry' => $row->billing_country,
            'billingPoBox' => $row->billing_po_box,
            'shippingStreet' => $row->shipping_street,
            'shippingCity' => $row->shipping_city,
            'shippingState' => $row->shipping_state,
            'shippingCode' => $row->shipping_code,
            'shippingCountry' => $row->shipping_country,
            'shippingPoBox' => $row->shipping_po_box,
            'description' => $row->description,
            'assignedTo' => $row->assigned_to ? (string) $row->assigned_to : null,
            'createdBy' => $row->created_by ? (string) $row->created_by : null,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }
}
