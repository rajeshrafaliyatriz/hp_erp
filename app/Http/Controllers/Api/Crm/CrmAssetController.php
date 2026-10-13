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
 * Assets - the Support phase's "a serialized unit of a Product, owned by an
 * Organization" entity. No depreciation/warranty concept - confirmed absent
 * from the legacy CRM, deliberately not invented here.
 *
 * `product_id` is nullable at the DB level but required at the VALIDATION
 * layer (`required|integer`) - every asset the UI lets you create must name
 * the product it is a unit of, but the column itself stays nullable so a
 * product can later be hard-deleted without an FK violation on its
 * already-sold units (same reasoning CrmQuoteController's own nullable FK
 * columns already document).
 *
 * Gets duplicate-detection + merge on TWO independent signals - mirrors
 * CrmLeadController::duplicates()'s own two-signal call shape exactly, just
 * with serial_number/tag_number in place of email/name+company. Merge also
 * re-points `crm_tickets.asset_id` away from the merged-away duplicate, same
 * "don't leave a dependent row pointing at a soft-deleted id" discipline
 * HasCrmMerge::repointCampaignTargets() already establishes for Leads/
 * Contacts/Organizations.
 */
class CrmAssetController extends Controller
{
    use ResolvesApiIdentity;
    use HasCrmBulkActions;
    use HasCrmDuplicateDetection;
    use HasCrmMerge;
    use HasCrmExport;

    /** @var array<string, string> db column => CSV header, in export column order. */
    private const EXPORT_COLUMNS = [
        'asset_name' => 'Asset Name', 'serial_number' => 'Serial Number', 'tag_number' => 'Tag Number',
        'status' => 'Status', 'date_sold' => 'Date Sold', 'date_in_service' => 'Date In Service',
        'shipping_method' => 'Shipping Method', 'shipping_tracking_number' => 'Shipping Tracking Number',
        'description' => 'Description', 'assigned_to' => 'Assigned To (user id)',
    ];

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = max(1, (int) $request->input('page', 1));

        $query = $this->baseQuery($identity['sub_institute_id']);

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('a.asset_name', 'like', "%{$search}%")
                    ->orWhere('a.asset_no', 'like', "%{$search}%")
                    ->orWhere('a.serial_number', 'like', "%{$search}%")
                    ->orWhere('a.tag_number', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('a.status', $status);
        }

        if ($productId = $request->input('product_id')) {
            $query->where('a.product_id', $productId);
        }

        if ($organizationId = $request->input('organization_id')) {
            $query->where('a.organization_id', $organizationId);
        }

        $sortableColumns = ['asset_name', 'status', 'date_sold', 'date_in_service', 'created_at'];
        $sortBy = in_array($request->input('sort_by'), $sortableColumns, true) ? $request->input('sort_by') : 'created_at';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'desc' ? 'desc' : 'asc';

        $total = (clone $query)->count();
        $rows = $query->orderBy('a.' . $sortBy, $sortDir)->forPage($page, $perPage)->get();

        return response()->json([
            'status' => 1,
            'message' => 'Assets.',
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

        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Asset not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Asset.', 'data' => $this->resource($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'assetName' => 'required|string|max:191',
            // The DB column is nullable (a product can later be hard-deleted
            // without orphaning the FK) - this app-level rule is what
            // actually makes it required for every asset created through
            // this API.
            'productId' => 'required|integer',
            'organizationId' => 'nullable|integer',
            'invoiceId' => 'nullable|integer',
            'assignedTo' => 'required|integer',
            'dateSold' => 'nullable|date',
            'dateInService' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        foreach ([
            ['productId', 'crm_products', 'Product'],
            ['organizationId', 'crm_organizations', 'Organization'],
            ['invoiceId', 'crm_invoices', 'Invoice'],
        ] as [$field, $table, $label]) {
            if (($rejection = $this->rejectUnknownReference($request, $identity['sub_institute_id'], $field, $table, $label)) !== null) {
                return $rejection;
            }
        }

        $data = $this->payload($request);
        // The column is NOT NULL DEFAULT 'In Service' - but an explicit NULL
        // in an INSERT bypasses a column default entirely (MySQL only applies
        // it when the column is omitted), so this must be defaulted here
        // rather than left to the DB. Same gotcha class CrmQuoteController's
        // own store() already documents for its money columns.
        if (empty($data['status'])) {
            $data['status'] = 'In Service';
        }
        $data['asset_no'] = 'ASSET-' . Str::upper(Str::random(8));
        $data['sub_institute_id'] = $identity['sub_institute_id'];
        $data['created_by'] = $identity['user_id'];
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $id = DB::table('crm_assets')->insertGetId($data);
        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Asset created.', 'data' => $this->resource($row)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Asset not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'assetName' => 'sometimes|required|string|max:191',
            'productId' => 'sometimes|required|integer',
            'organizationId' => 'nullable|integer',
            'invoiceId' => 'nullable|integer',
            'assignedTo' => 'sometimes|required|integer',
            'dateSold' => 'nullable|date',
            'dateInService' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        foreach ([
            ['productId', 'crm_products', 'Product'],
            ['organizationId', 'crm_organizations', 'Organization'],
            ['invoiceId', 'crm_invoices', 'Invoice'],
        ] as [$field, $table, $label]) {
            if (($rejection = $this->rejectUnknownReference($request, $identity['sub_institute_id'], $field, $table, $label)) !== null) {
                return $rejection;
            }
        }

        $data = $this->payload($request, forUpdate: true);
        $data['updated_by'] = $identity['user_id'];
        $data['updated_at'] = now();

        DB::table('crm_assets')->where('id', $id)->update($data);
        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Asset updated.', 'data' => $this->resource($row)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Asset not found.'], 404);
        }

        DB::table('crm_assets')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $identity['user_id'],
        ]);

        return response()->json(['status' => 1, 'message' => 'Asset moved to Recycle Bin.']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkDeleteRows($request, 'crm_assets', $identity['sub_institute_id'], $identity['user_id'], 'Asset');
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkAssignRows($request, 'crm_assets', $identity['sub_institute_id'], $identity['user_id'], 'Asset');
    }

    /**
     * Possible duplicates, grouped by two independent signals: a same
     * (normalized) serial number, and a same (normalized) tag number - each
     * only considered when that column is non-empty, same reasoning
     * CrmLeadController::duplicates() already documents (an empty company
     * shouldn't group every lead with a blank company together).
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
            $this->duplicateGroups('crm_assets', $tenantId, 'LOWER(TRIM(serial_number))', ['serial_number'], 'Same serial number', $resource),
            $this->duplicateGroups('crm_assets', $tenantId, 'LOWER(TRIM(tag_number))', ['tag_number'], 'Same tag number', $resource),
        );

        return response()->json(['status' => 1, 'message' => 'Possible duplicate assets.', 'data' => $groups]);
    }

    public function merge(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->mergeRows(
            $request, 'crm_assets', $identity['sub_institute_id'], $identity['user_id'], 'Asset',
            repoint: function (int $fromId, int $toId) {
                // crm_tickets.asset_id has no uniqueness constraint to worry
                // about (many tickets can legitimately point at one asset),
                // so this is a plain re-point, not the drop-or-move dance
                // HasCrmMerge::repointCampaignTargets() needs for a unique pair.
                DB::table('crm_tickets')->where('asset_id', $fromId)->update(['asset_id' => $toId]);
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

        $query = DB::table('crm_assets')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('asset_name', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%")
                    ->orWhere('tag_number', 'like', "%{$search}%");
            });
        }

        return $this->exportCsv($query, self::EXPORT_COLUMNS, 'assets.csv');
    }

    /** CSV import - header fields only, same as every other CRM import. A missing/invalid owner or Product rejects the row (no fallback). */
    public function import(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), ['rows' => 'required|array|min:1|max:500']);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $created = 0;
        $results = [];

        foreach ($request->input('rows') as $index => $row) {
            $rowRequest = Request::create('/', 'POST', is_array($row) ? $row : []);

            $rowValidator = Validator::make($rowRequest->all(), [
                'assetName' => 'required|string|max:191',
                'productId' => 'required|integer',
                'organizationId' => 'nullable|integer',
                'invoiceId' => 'nullable|integer',
                'assignedTo' => 'required|integer',
            ]);

            if ($rowValidator->fails()) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => $rowValidator->errors()->first()];
                continue;
            }

            if (($reason = $this->unknownReferenceReason($rowRequest, $identity['sub_institute_id'], 'productId', 'crm_products')) !== null) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => "Product {$reason}"];
                continue;
            }
            if (($reason = $this->unknownReferenceReason($rowRequest, $identity['sub_institute_id'], 'organizationId', 'crm_organizations')) !== null) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => "Organization {$reason}"];
                continue;
            }
            if (($reason = $this->unknownReferenceReason($rowRequest, $identity['sub_institute_id'], 'invoiceId', 'crm_invoices')) !== null) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => "Invoice {$reason}"];
                continue;
            }

            $data = $this->payload($rowRequest);
            $data['asset_no'] = 'ASSET-' . Str::upper(Str::random(8));
            $data['sub_institute_id'] = $identity['sub_institute_id'];
            $data['created_by'] = $identity['user_id'];
            $data['created_at'] = now();
            $data['updated_at'] = now();

            DB::table('crm_assets')->insert($data);
            $created++;
            $results[] = ['row' => $index + 1, 'ok' => true];
        }

        return response()->json([
            'status' => 1,
            'message' => $created === count($results) ? "{$created} asset(s) imported." : "{$created} of " . count($results) . ' row(s) imported.',
            'data' => ['created' => $created, 'results' => $results],
        ]);
    }

    private function rejectUnknownReference(Request $request, int $tenantId, string $field, string $table, string $label): ?JsonResponse
    {
        $reason = $this->unknownReferenceReason($request, $tenantId, $field, $table);

        return $reason === null ? null : response()->json(['status' => 0, 'message' => "{$label} {$reason}"], 422);
    }

    private function unknownReferenceReason(Request $request, int $tenantId, string $field, string $table): ?string
    {
        if (! $request->filled($field)) {
            return null;
        }

        $exists = DB::table($table)
            ->where('id', (int) $request->input($field))
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->exists();

        return $exists ? null : 'not found.';
    }

    private function baseQuery(int $tenantId)
    {
        return DB::table('crm_assets as a')
            ->leftJoin('crm_products as p', 'p.id', '=', 'a.product_id')
            ->leftJoin('crm_organizations as o', 'o.id', '=', 'a.organization_id')
            ->leftJoin('crm_invoices as i', 'i.id', '=', 'a.invoice_id')
            ->leftJoin('tbluser as u', 'u.id', '=', 'a.assigned_to')
            ->where('a.sub_institute_id', $tenantId)
            ->whereNull('a.deleted_at')
            ->select(
                'a.*',
                'p.name as product_name',
                'o.name as organization_name',
                'i.invoice_no as invoice_no',
                DB::raw("TRIM(CONCAT(u.first_name, ' ', u.last_name)) as assigned_to_name"),
            );
    }

    private function ownRow(int $tenantId, int $id): ?object
    {
        return DB::table('crm_assets')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    private function ownRowJoined(int $tenantId, int $id): ?object
    {
        return $this->baseQuery($tenantId)->where('a.id', $id)->first();
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, bool $forUpdate = false): array
    {
        $map = [
            'assetName' => 'asset_name', 'productId' => 'product_id', 'organizationId' => 'organization_id',
            'serialNumber' => 'serial_number', 'tagNumber' => 'tag_number', 'dateSold' => 'date_sold',
            'dateInService' => 'date_in_service', 'status' => 'status', 'shippingMethod' => 'shipping_method',
            'shippingTrackingNumber' => 'shipping_tracking_number', 'invoiceId' => 'invoice_id',
            'description' => 'description', 'assignedTo' => 'assigned_to',
        ];

        $data = [];

        foreach ($map as $requestKey => $column) {
            if ($forUpdate && ! $request->has($requestKey)) {
                continue;
            }

            $data[$column] = $request->input($requestKey);
        }

        foreach (['organization_id', 'invoice_id', 'product_id'] as $nullableIdColumn) {
            if (array_key_exists($nullableIdColumn, $data) && $data[$nullableIdColumn] === '') {
                $data[$nullableIdColumn] = null;
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function resource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'legacyId' => $row->legacy_id ? (string) $row->legacy_id : null,
            'assetNo' => $row->asset_no,
            'assetName' => $row->asset_name,
            'productId' => $row->product_id ? (string) $row->product_id : null,
            'productName' => $row->product_name ?? null,
            'organizationId' => $row->organization_id ? (string) $row->organization_id : null,
            'organizationName' => $row->organization_name ?? null,
            'serialNumber' => $row->serial_number,
            'tagNumber' => $row->tag_number,
            'dateSold' => $row->date_sold,
            'dateInService' => $row->date_in_service,
            'status' => $row->status,
            'shippingMethod' => $row->shipping_method,
            'shippingTrackingNumber' => $row->shipping_tracking_number,
            'invoiceId' => $row->invoice_id ? (string) $row->invoice_id : null,
            'invoiceNo' => $row->invoice_no ?? null,
            'description' => $row->description,
            'assignedTo' => $row->assigned_to ? (string) $row->assigned_to : null,
            'assignedToName' => ($row->assigned_to_name ?? '') !== '' ? $row->assigned_to_name : null,
            'createdBy' => $row->created_by ? (string) $row->created_by : null,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }
}
