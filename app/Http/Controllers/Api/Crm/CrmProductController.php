<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmBulkActions;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmDuplicateDetection;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmExport;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmMerge;
use App\Http\Controllers\Controller;
use App\Support\MenuRight;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Products & Services - one unified `crm_products` table, `item_type`
 * discriminated. See that table's own migration for why this is the
 * correct translation of legacy's polymorphic design (vtiger_products/
 * vtiger_service share a global crmentity id space hp_erp has no
 * equivalent of), not just a convenience.
 *
 * Two menu leaves/routes (Products, Services) share this one controller and
 * one table - unlike every other CRM entity, NO static route middleware
 * gates these endpoints (same "check internally" shape as
 * CrmRecycleBinController/CrmSavedViewController), because which of the two
 * rights applies depends on the request's own `itemType` field, not
 * anything the router can see when the route is registered. Every action
 * below resolves the right item_type (from the request for create/list
 * actions, from the row itself for by-id actions) and checks that type's
 * own right explicitly before doing anything.
 */
class CrmProductController extends Controller
{
    use ResolvesApiIdentity;
    use HasCrmBulkActions;
    use HasCrmDuplicateDetection;
    use HasCrmMerge;
    use HasCrmExport;

    private const ITEM_TYPES = ['product', 'service'];

    /** @var array<string, string> item_type => its own menu access_link. */
    private const TYPE_LINKS = [
        'product' => '/module/crm/sales/products',
        'service' => '/module/crm/sales/services',
    ];

    /** @var array<string, string> db column => CSV header, in export column order. */
    private const EXPORT_COLUMNS = [
        'name' => 'Name', 'sku' => 'SKU', 'category' => 'Category', 'gl_account' => 'GL Account',
        'description' => 'Description', 'unit_price' => 'Unit Price', 'cost_price' => 'Cost Price',
        'commission_rate' => 'Commission Rate', 'currency' => 'Currency', 'is_active' => 'Active',
        'vendor' => 'Vendor', 'qty_in_stock' => 'Qty In Stock', 'qty_in_demand' => 'Qty In Demand',
        'reorder_level' => 'Reorder Level', 'weight' => 'Weight', 'usage_unit' => 'Usage Unit',
        'assigned_to' => 'Assigned To (user id)',
    ];

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $itemType = $this->validItemType($request);

        if ($itemType === null) {
            return response()->json(['status' => 0, 'message' => 'A valid itemType (product or service) is required.'], 422);
        }

        if (! $this->canOnType($identity, $itemType, 'view')) {
            return $this->forbidden();
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = max(1, (int) $request->input('page', 1));

        $query = $this->baseQuery($identity['sub_institute_id'], $itemType);

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('p.name', 'like', "%{$search}%")
                    ->orWhere('p.sku', 'like', "%{$search}%")
                    ->orWhere('p.product_no', 'like', "%{$search}%");
            });
        }

        if ($category = $request->input('category')) {
            $query->where('p.category', $category);
        }

        $sortableColumns = ['name', 'category', 'unit_price', 'qty_in_stock', 'created_at'];
        $sortBy = in_array($request->input('sort_by'), $sortableColumns, true) ? $request->input('sort_by') : 'name';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'desc' ? 'desc' : 'asc';

        $total = (clone $query)->count();
        $rows = $query->orderBy('p.' . $sortBy, $sortDir)->forPage($page, $perPage)->get();

        return response()->json([
            'status' => 1,
            'message' => 'Products.',
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

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Product not found.'], 404);
        }

        if (! $this->canOnType($identity, $existing->item_type, 'view')) {
            return $this->forbidden();
        }

        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Product.', 'data' => $this->resource($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'itemType' => 'required|string|in:' . implode(',', self::ITEM_TYPES),
            'name' => 'required|string|max:191',
            'assignedTo' => 'required|integer',
            'unitPrice' => 'nullable|numeric',
            'costPrice' => 'nullable|numeric',
            'commissionRate' => 'nullable|numeric|min:0|max:100',
            'taxRateId' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        if (($rejection = $this->rejectUnknownTaxRate($request, $identity['sub_institute_id'])) !== null) {
            return $rejection;
        }

        $itemType = $request->input('itemType');

        if (! $this->canOnType($identity, $itemType, 'add')) {
            return $this->forbidden();
        }

        $data = $this->payload($request);
        $data['item_type'] = $itemType;
        $data['product_no'] = ($itemType === 'service' ? 'SVC-' : 'PRD-') . Str::upper(Str::random(8));
        $data['sub_institute_id'] = $identity['sub_institute_id'];
        $data['created_by'] = $identity['user_id'];
        $data['created_at'] = now();
        $data['updated_at'] = now();

        // Service rows never carry stock/vendor data - the table allows it
        // (nullable columns), but every write path should agree, not just
        // the create-form's own conditional UI.
        if ($itemType === 'service') {
            $data['vendor'] = null;
            $data['qty_in_stock'] = null;
            $data['reorder_level'] = null;
            $data['qty_in_demand'] = null;
        }

        $id = DB::table('crm_products')->insertGetId($data);
        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => ucfirst($itemType) . ' created.', 'data' => $this->resource($row)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Product not found.'], 404);
        }

        if (! $this->canOnType($identity, $existing->item_type, 'edit')) {
            return $this->forbidden();
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:191',
            'assignedTo' => 'sometimes|required|integer',
            'unitPrice' => 'nullable|numeric',
            'costPrice' => 'nullable|numeric',
            'commissionRate' => 'nullable|numeric|min:0|max:100',
            'taxRateId' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        if (($rejection = $this->rejectUnknownTaxRate($request, $identity['sub_institute_id'])) !== null) {
            return $rejection;
        }

        // item_type is immutable once created - legacy never had a way to
        // "convert" a Product into a Service either (separate tables there).
        $data = $this->payload($request, forUpdate: true);
        unset($data['item_type']);

        if ($existing->item_type === 'service') {
            $data['vendor'] = null;
            $data['qty_in_stock'] = null;
            $data['reorder_level'] = null;
            $data['qty_in_demand'] = null;
        }

        $data['updated_by'] = $identity['user_id'];
        $data['updated_at'] = now();

        DB::table('crm_products')->where('id', $id)->update($data);
        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => ucfirst($existing->item_type) . ' updated.', 'data' => $this->resource($row)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Product not found.'], 404);
        }

        if (! $this->canOnType($identity, $existing->item_type, 'delete')) {
            return $this->forbidden();
        }

        DB::table('crm_products')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $identity['user_id'],
        ]);

        return response()->json(['status' => 1, 'message' => ucfirst($existing->item_type) . ' moved to Recycle Bin.']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $itemType = $this->validItemType($request);

        if ($itemType === null) {
            return response()->json(['status' => 0, 'message' => 'A valid itemType (product or service) is required.'], 422);
        }

        if (! $this->canOnType($identity, $itemType, 'delete')) {
            return $this->forbidden();
        }

        return $this->bulkDeleteRows(
            $request, 'crm_products', $identity['sub_institute_id'], $identity['user_id'], 'Product',
            guard: function (int $id) use ($itemType) {
                $row = DB::table('crm_products')->where('id', $id)->first(['item_type']);

                return $row && $row->item_type !== $itemType ? 'Wrong item type for this screen.' : null;
            },
        );
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $itemType = $this->validItemType($request);

        if ($itemType === null) {
            return response()->json(['status' => 0, 'message' => 'A valid itemType (product or service) is required.'], 422);
        }

        if (! $this->canOnType($identity, $itemType, 'edit')) {
            return $this->forbidden();
        }

        // bulkAssignRows() has no per-id guard hook (unlike bulkDeleteRows) -
        // checked up front instead: every targeted id must already be this
        // itemType, or the whole batch is rejected before anything changes.
        $ids = array_map('intval', (array) $request->input('ids', []));
        $mismatched = DB::table('crm_products')
            ->whereIn('id', $ids)
            ->where('item_type', '!=', $itemType)
            ->exists();

        if ($mismatched) {
            return response()->json(['status' => 0, 'message' => 'All selected rows must be the same item type as this screen.'], 422);
        }

        return $this->bulkAssignRows($request, 'crm_products', $identity['sub_institute_id'], $identity['user_id'], 'Product');
    }

    /** Possible duplicates: a same (normalized) name, or a same SKU - scoped to one item_type, never crossing Products/Services. */
    public function duplicates(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $itemType = $this->validItemType($request);

        if ($itemType === null) {
            return response()->json(['status' => 0, 'message' => 'A valid itemType (product or service) is required.'], 422);
        }

        if (! $this->canOnType($identity, $itemType, 'view')) {
            return $this->forbidden();
        }

        $tenantId = $identity['sub_institute_id'];
        $resource = fn ($row) => $this->resource($row);
        $scopeToType = fn ($q) => $q->where('item_type', $itemType);

        $groups = array_merge(
            $this->duplicateGroups('crm_products', $tenantId, 'LOWER(TRIM(name))', ['name'], 'Same name', $resource, $scopeToType),
            $this->duplicateGroups('crm_products', $tenantId, 'LOWER(TRIM(sku))', ['sku'], 'Same SKU', $resource, $scopeToType),
        );

        return response()->json(['status' => 1, 'message' => 'Possible duplicate ' . $itemType . 's.', 'data' => $groups]);
    }

    public function merge(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        // mergeRows() has no built-in type scoping - a Product and a Service
        // must never merge into each other (they're different kinds of
        // record that happen to share a table), so that is checked here
        // before any row is touched, and before the right check below (there
        // is no "which type" to check a right against until this resolves).
        $ids = array_filter(array_merge(
            [(int) $request->input('survivorId')],
            array_map('intval', (array) $request->input('duplicateIds', [])),
        ));

        $types = DB::table('crm_products')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereIn('id', $ids)
            ->distinct()
            ->pluck('item_type');

        if ($types->count() > 1) {
            return response()->json(['status' => 0, 'message' => 'A Product and a Service cannot be merged together.'], 422);
        }

        if ($types->isEmpty() || ! $this->canOnType($identity, $types->first(), 'delete')) {
            return $this->forbidden();
        }

        return $this->mergeRows(
            $request, 'crm_products', $identity['sub_institute_id'], $identity['user_id'], 'Product',
            repoint: function (int $fromId, int $toId) {
                // crm_opportunity_products/crm_quote_line_items point at a
                // product by id with no repoint-on-merge mechanism of their
                // own (their FKs only fire on a hard delete, not this soft
                // one) - anything still pointing at the merged-away
                // duplicate is re-pointed at the survivor here instead.
                DB::table('crm_opportunity_products')->where('product_id', $fromId)->update(['product_id' => $toId]);
                DB::table('crm_quote_line_items')->where('product_id', $fromId)->update(['product_id' => $toId]);
            },
        );
    }

    // ── Bundle components (Products only - one level, no price override) ──

    public function bundleItems(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $parent = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $parent) {
            return response()->json(['status' => 0, 'message' => 'Product not found.'], 404);
        }

        if (! $this->canOnType($identity, $parent->item_type, 'view')) {
            return $this->forbidden();
        }

        $rows = DB::table('crm_product_bundle_items as b')
            ->join('crm_products as p', 'p.id', '=', 'b.component_product_id')
            ->where('b.parent_product_id', $id)
            ->whereNull('p.deleted_at')
            ->select('b.id as row_id', 'p.id as product_id', 'p.name', 'p.item_type', 'p.unit_price', 'b.quantity')
            ->get();

        return response()->json([
            'status' => 1,
            'message' => 'Bundle components.',
            'data' => $rows->map(fn ($row) => [
                'id' => (string) $row->row_id,
                'productId' => (string) $row->product_id,
                'name' => $row->name,
                'itemType' => $row->item_type,
                'unitPrice' => $row->unit_price !== null ? (float) $row->unit_price : null,
                'quantity' => (float) $row->quantity,
            ])->all(),
        ]);
    }

    public function addBundleItem(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $parent = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $parent) {
            return response()->json(['status' => 0, 'message' => 'Product not found.'], 404);
        }

        if ($parent->item_type !== 'product') {
            return response()->json(['status' => 0, 'message' => 'Only Products can be bundles, not Services.'], 422);
        }

        if (! $this->canOnType($identity, 'product', 'edit')) {
            return $this->forbidden();
        }

        $validator = Validator::make($request->all(), [
            'componentProductId' => 'required|integer',
            'quantity' => 'nullable|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $componentId = (int) $request->input('componentProductId');

        if ($componentId === $id) {
            return response()->json(['status' => 0, 'message' => 'A product cannot be a component of itself.'], 422);
        }

        $component = $this->ownRow($identity['sub_institute_id'], $componentId);

        if (! $component) {
            return response()->json(['status' => 0, 'message' => 'That component product was not found.'], 422);
        }

        // One level only, matching legacy (getSubProducts() never recurses) -
        // a bundle cannot itself be used as a component, which also rules
        // out any cycle (A contains B, B contains A) by construction.
        $componentIsItselfABundle = DB::table('crm_product_bundle_items')->where('parent_product_id', $componentId)->exists();

        if ($componentIsItselfABundle) {
            return response()->json(['status' => 0, 'message' => 'That product is itself a bundle - bundles cannot be nested.'], 422);
        }

        DB::table('crm_product_bundle_items')->updateOrInsert(
            ['parent_product_id' => $id, 'component_product_id' => $componentId],
            ['quantity' => $request->input('quantity', 1), 'created_at' => now()],
        );

        return response()->json(['status' => 1, 'message' => 'Component added.']);
    }

    public function removeBundleItem(Request $request, int $id, int $rowId): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $parent = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $parent) {
            return response()->json(['status' => 0, 'message' => 'Product not found.'], 404);
        }

        if (! $this->canOnType($identity, $parent->item_type, 'edit')) {
            return $this->forbidden();
        }

        DB::table('crm_product_bundle_items')->where('id', $rowId)->where('parent_product_id', $id)->delete();

        return response()->json(['status' => 1, 'message' => 'Component removed.']);
    }

    // ── Image upload ────────────────────────────────────────────────────

    public function uploadImage(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Product not found.'], 404);
        }

        if (! $this->canOnType($identity, $existing->item_type, 'edit')) {
            return $this->forbidden();
        }

        $validator = Validator::make($request->all(), ['image' => 'required|image|max:5120']);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $file = $request->file('image');
        // Content-derived extension and a random basename, never the
        // client's filename - same reasoning as AccountController's own
        // storeAvatar() (a client-named key lets a disguised upload get
        // served back with an attacker-chosen Content-Type).
        $extension = $file->extension() ?: 'jpg';
        $name = $id . '_' . Str::random(24) . '.' . $extension;

        Storage::disk('digitalocean')->putFileAs('public/hp_crm_product/', $file, $name, [
            'visibility' => 'public',
            'ContentType' => $file->getMimeType() ?: 'application/octet-stream',
        ]);

        DB::table('crm_products')->where('id', $id)->update([
            'image' => $name, 'updated_by' => $identity['user_id'], 'updated_at' => now(),
        ]);

        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Image uploaded.', 'data' => $this->resource($row)]);
    }

    /** Exports the same rows index() would list (itemType + search applied). */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $itemType = $this->validItemType($request);

        if ($itemType === null) {
            return response()->json(['status' => 0, 'message' => 'A valid itemType (product or service) is required.'], 422);
        }

        if (! $this->canOnType($identity, $itemType, 'view')) {
            return $this->forbidden();
        }

        $query = DB::table('crm_products')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->where('item_type', $itemType)
            ->whereNull('deleted_at');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%");
            });
        }

        return $this->exportCsv($query, self::EXPORT_COLUMNS, $itemType . 's.csv');
    }

    /** CSV import - one itemType per batch (a spreadsheet is one kind of catalog item, never mixed). */
    public function import(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $itemType = $this->validItemType($request);

        if ($itemType === null) {
            return response()->json(['status' => 0, 'message' => 'A valid itemType (product or service) is required.'], 422);
        }

        if (! $this->canOnType($identity, $itemType, 'add')) {
            return $this->forbidden();
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
                'name' => 'required|string|max:191',
                'assignedTo' => 'required|integer',
                'unitPrice' => 'nullable|numeric',
            ]);

            if ($rowValidator->fails()) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => $rowValidator->errors()->first()];
                continue;
            }

            $data = $this->payload($rowRequest);
            $data['item_type'] = $itemType;
            $data['product_no'] = ($itemType === 'service' ? 'SVC-' : 'PRD-') . Str::upper(Str::random(8));
            $data['sub_institute_id'] = $identity['sub_institute_id'];
            $data['created_by'] = $identity['user_id'];
            $data['created_at'] = now();
            $data['updated_at'] = now();

            if ($itemType === 'service') {
                $data['vendor'] = null;
                $data['qty_in_stock'] = null;
                $data['reorder_level'] = null;
                $data['qty_in_demand'] = null;
            }

            DB::table('crm_products')->insert($data);
            $created++;
            $results[] = ['row' => $index + 1, 'ok' => true];
        }

        return response()->json([
            'status' => 1,
            'message' => $created === count($results) ? "{$created} {$itemType}(s) imported." : "{$created} of " . count($results) . ' row(s) imported.',
            'data' => ['created' => $created, 'results' => $results],
        ]);
    }

    /**
     * A bad taxRateId would otherwise surface as a raw foreign-key
     * violation (a 500, not a clean error) - checked explicitly so it reads
     * as an ordinary validation failure instead. Returns null when the
     * request has no taxRateId, or it names a real row in this tenant.
     */
    private function rejectUnknownTaxRate(Request $request, int $tenantId): ?JsonResponse
    {
        if (! $request->filled('taxRateId')) {
            return null;
        }

        $exists = DB::table('crm_tax_rates')
            ->where('id', (int) $request->input('taxRateId'))
            ->where('sub_institute_id', $tenantId)
            ->exists();

        return $exists ? null : response()->json(['status' => 0, 'message' => 'That tax rate was not found.'], 422);
    }

    private function validItemType(Request $request): ?string
    {
        $itemType = $request->input('itemType');

        return in_array($itemType, self::ITEM_TYPES, true) ? $itemType : null;
    }

    /** @param array{user: object, user_id: int, sub_institute_id: int} $identity */
    private function canOnType(array $identity, string $itemType, string $action): bool
    {
        $link = self::TYPE_LINKS[$itemType] ?? null;

        if ($link === null) {
            return false;
        }

        $profileId = (int) ($identity['user']->user_profile_id ?? 0);
        $menuId = MenuRight::idForAccessLink($link);

        return $menuId !== null && MenuRight::can($profileId, $menuId, $identity['sub_institute_id'], $action);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => 'You do not have permission to perform this action.'], 403);
    }

    private function baseQuery(int $tenantId, string $itemType)
    {
        return DB::table('crm_products as p')
            ->leftJoin('crm_tax_rates as t', 't.id', '=', 'p.tax_rate_id')
            ->where('p.sub_institute_id', $tenantId)
            ->where('p.item_type', $itemType)
            ->whereNull('p.deleted_at')
            ->select('p.*', 't.name as tax_rate_name');
    }

    private function ownRow(int $tenantId, int $id): ?object
    {
        return DB::table('crm_products')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    private function imageUrl(?string $filename): ?string
    {
        $filename = trim((string) $filename);

        if ($filename === '') {
            return null;
        }

        try {
            return Storage::disk('digitalocean')->url('public/hp_crm_product/' . $filename);
        } catch (\Throwable) {
            return null;
        }
    }

    private function ownRowJoined(int $tenantId, int $id): ?object
    {
        return DB::table('crm_products as p')
            ->leftJoin('crm_tax_rates as t', 't.id', '=', 'p.tax_rate_id')
            ->where('p.id', $id)
            ->where('p.sub_institute_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->select('p.*', 't.name as tax_rate_name')
            ->first();
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, bool $forUpdate = false): array
    {
        $map = [
            'name' => 'name', 'sku' => 'sku', 'category' => 'category', 'glAccount' => 'gl_account',
            'description' => 'description', 'unitPrice' => 'unit_price', 'costPrice' => 'cost_price',
            'commissionRate' => 'commission_rate', 'currency' => 'currency', 'taxRateId' => 'tax_rate_id',
            'isActive' => 'is_active', 'vendor' => 'vendor', 'qtyInStock' => 'qty_in_stock',
            'qtyInDemand' => 'qty_in_demand', 'reorderLevel' => 'reorder_level', 'weight' => 'weight',
            'usageUnit' => 'usage_unit', 'assignedTo' => 'assigned_to',
        ];

        $data = [];

        foreach ($map as $requestKey => $column) {
            if ($forUpdate && ! $request->has($requestKey)) {
                continue;
            }

            $data[$column] = $request->input($requestKey);
        }

        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = $data['is_active'] === null ? true : (bool) $data['is_active'];
        }

        if (array_key_exists('tax_rate_id', $data) && $data['tax_rate_id'] === '') {
            $data['tax_rate_id'] = null;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function resource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'itemType' => $row->item_type,
            'productNo' => $row->product_no,
            'name' => $row->name,
            'sku' => $row->sku,
            'category' => $row->category,
            'glAccount' => $row->gl_account,
            'description' => $row->description,
            'unitPrice' => $row->unit_price !== null ? (float) $row->unit_price : null,
            'costPrice' => $row->cost_price !== null ? (float) $row->cost_price : null,
            'commissionRate' => $row->commission_rate !== null ? (float) $row->commission_rate : null,
            'currency' => $row->currency,
            'taxRateId' => $row->tax_rate_id ? (string) $row->tax_rate_id : null,
            'taxRateName' => $row->tax_rate_name ?? null,
            'isActive' => (bool) $row->is_active,
            'vendor' => $row->vendor,
            'qtyInStock' => $row->qty_in_stock !== null ? (float) $row->qty_in_stock : null,
            'qtyInDemand' => $row->qty_in_demand !== null ? (float) $row->qty_in_demand : null,
            'reorderLevel' => $row->reorder_level !== null ? (int) $row->reorder_level : null,
            'weight' => $row->weight !== null ? (float) $row->weight : null,
            'usageUnit' => $row->usage_unit,
            'imageUrl' => $this->imageUrl($row->image ?? null),
            'assignedTo' => $row->assigned_to ? (string) $row->assigned_to : null,
            'createdBy' => $row->created_by ? (string) $row->created_by : null,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }
}
