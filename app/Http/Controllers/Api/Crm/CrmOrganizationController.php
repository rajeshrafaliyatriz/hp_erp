<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmBulkActions;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmDuplicateDetection;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmExport;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmMerge;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Organizations - the legacy CRM's "Accounts" module, relabeled. Internal
 * name stays Organization/crm_organizations everywhere; "Organizations" is
 * a presentation-layer label only.
 */
class CrmOrganizationController extends Controller
{
    use ResolvesApiIdentity;
    use HasCrmBulkActions;
    use HasCrmDuplicateDetection;
    use HasCrmMerge;
    use HasCrmExport;

    /** @var array<string, string> db column => CSV header, in export column order. */
    private const EXPORT_COLUMNS = [
        'name' => 'Name', 'account_type' => 'Type', 'industry' => 'Industry', 'rating' => 'Rating',
        'ownership' => 'Ownership', 'annual_revenue' => 'Annual Revenue', 'employees' => 'Employees',
        'phone' => 'Phone', 'email' => 'Email', 'website' => 'Website',
        'billing_street' => 'Billing Street', 'billing_city' => 'Billing City',
        'billing_state' => 'Billing State', 'billing_code' => 'Billing Postal Code',
        'billing_country' => 'Billing Country', 'description' => 'Description',
        'assigned_to' => 'Assigned To (user id)',
    ];

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

    public function bulkDelete(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        // Same guard as the single-record destroy() above: a parent with
        // live children must be re-parented first, bulk or not.
        return $this->bulkDeleteRows(
            $request,
            'crm_organizations',
            $identity['sub_institute_id'],
            $identity['user_id'],
            'Organization',
            guard: fn (int $id) => DB::table('crm_organizations')->where('parent_id', $id)->whereNull('deleted_at')->exists()
                ? 'Re-parent or remove this organization\'s child organizations first.'
                : null,
        );
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $cascade = $request->boolean('cascadeToContacts');

        return $this->bulkAssignRows(
            $request, 'crm_organizations', $identity['sub_institute_id'], $identity['user_id'], 'Organization',
            afterEach: $cascade
                ? function (int $organizationId, int $assignedTo) use ($identity) {
                    return DB::table('crm_contacts')
                        ->where('organization_id', $organizationId)
                        ->where('sub_institute_id', $identity['sub_institute_id'])
                        ->whereNull('deleted_at')
                        ->update(['assigned_to' => $assignedTo, 'updated_by' => $identity['user_id'], 'updated_at' => now()]);
                }
                : null,
        );
    }

    /**
     * Possible duplicates: a same (normalized) website, or a same phone
     * number. `name` is deliberately not a signal here - store() already
     * rejects a second organization with the same name per tenant, so an
     * exact name collision cannot exist going forward.
     */
    public function duplicates(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $tenantId = $identity['sub_institute_id'];
        $resource = fn ($row) => $this->resource($row);

        $groups = array_merge(
            $this->duplicateGroups('crm_organizations', $tenantId, 'LOWER(TRIM(website))', ['website'], 'Same website', $resource),
            $this->duplicateGroups('crm_organizations', $tenantId, 'LOWER(TRIM(phone))', ['phone'], 'Same phone number', $resource),
        );

        return response()->json(['status' => 1, 'message' => 'Possible duplicate organizations.', 'data' => $groups]);
    }

    public function merge(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->mergeRows(
            $request, 'crm_organizations', $identity['sub_institute_id'], $identity['user_id'], 'Organization',
            repoint: function (int $fromId, int $toId) {
                $this->repointCampaignTargets('organization', $fromId, $toId);

                DB::table('crm_contacts')->where('organization_id', $fromId)->update(['organization_id' => $toId]);
                DB::table('crm_leads')->where('converted_organization_id', $fromId)->update(['converted_organization_id' => $toId]);

                // If the survivor itself was a child of the duplicate, clear
                // that FIRST - otherwise the blanket re-point just below would
                // match the survivor's own row too and leave it parented to
                // itself (where('parent_id', $fromId) stops matching it the
                // moment that update runs, so this guard must go first).
                DB::table('crm_organizations')->where('id', $toId)->where('parent_id', $fromId)->update(['parent_id' => null]);

                // Every other organization that had the duplicate as its
                // parent now reports to the survivor instead.
                DB::table('crm_organizations')->where('parent_id', $fromId)->update(['parent_id' => $toId]);
            },
        );
    }

    /** Exports the same rows index() would list (search applied). */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

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

        return $this->exportCsv($query, self::EXPORT_COLUMNS, 'organizations.csv');
    }

    /**
     * CSV import. A missing/invalid owner rejects the row (no fallback),
     * and a name that collides with an existing organization in this
     * tenant is also rejected - the same rule store() already applies to a
     * manual single create, so a CSV never bypasses it.
     */
    public function import(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'rows' => 'required|array|min:1|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $created = 0;
        $results = [];

        foreach ($request->input('rows') as $index => $row) {
            $rowRequest = Request::create('/', 'POST', is_array($row) ? $row : []);

            $rowValidator = Validator::make($rowRequest->all(), [
                'name' => 'required|string|max:191',
                'email' => 'nullable|email|max:191',
                'assignedTo' => 'required|integer',
                'annualRevenue' => 'nullable|numeric',
                'employees' => 'nullable|integer',
            ]);

            if ($rowValidator->fails()) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => $rowValidator->errors()->first()];
                continue;
            }

            $nameClash = DB::table('crm_organizations')
                ->where('sub_institute_id', $identity['sub_institute_id'])
                ->where('name', $rowRequest->input('name'))
                ->whereNull('deleted_at')
                ->exists();

            if ($nameClash) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => 'An organization with that name already exists.'];
                continue;
            }

            $data = $this->payload($rowRequest);
            $data['account_no'] = 'ORG-' . Str::upper(Str::random(8));
            $data['sub_institute_id'] = $identity['sub_institute_id'];
            $data['created_by'] = $identity['user_id'];
            $data['created_at'] = now();
            $data['updated_at'] = now();

            DB::table('crm_organizations')->insert($data);
            $created++;
            $results[] = ['row' => $index + 1, 'ok' => true];
        }

        return response()->json([
            'status' => 1,
            'message' => $created === count($results) ? "{$created} organization(s) imported." : "{$created} of " . count($results) . ' row(s) imported.',
            'data' => ['created' => $created, 'results' => $results],
        ]);
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
