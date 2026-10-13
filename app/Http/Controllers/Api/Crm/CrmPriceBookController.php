<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmAuditTrail;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmBulkActions;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Price Books - a named list of per-product override prices (e.g.
 * "Wholesale" vs "Retail"), vtiger's PriceBooks module. A Quote may
 * optionally select one; a newly-added line item then defaults its price
 * from this book's own crm_price_book_items row for that product, falling
 * back to the product's base crm_products.unit_price when the book has no
 * override for it (see CrmQuoteController's own saveLineItems()).
 *
 * No duplicate-detection/merge pair, same reasoning as Opportunities/
 * Quotes/Campaigns - two price books can legitimately share a name (e.g.
 * "Wholesale 2027" re-created yearly), not an accidental double-entry.
 */
class CrmPriceBookController extends Controller
{
    use ResolvesApiIdentity;
    use HasCrmAuditTrail;
    use HasCrmBulkActions;
    use HasCrmExport;

    /** @var array<string, string> db column => CSV header, in export column order. */
    private const EXPORT_COLUMNS = [
        'name' => 'Name', 'currency' => 'Currency', 'is_active' => 'Active',
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

        $query = DB::table('crm_price_books')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where('name', 'like', "%{$search}%");
        }

        $sortableColumns = ['name', 'currency', 'created_at'];
        $sortBy = in_array($request->input('sort_by'), $sortableColumns, true) ? $request->input('sort_by') : 'name';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'desc' ? 'desc' : 'asc';

        $total = (clone $query)->count();
        $rows = $query->orderBy($sortBy, $sortDir)->forPage($page, $perPage)->get();

        return response()->json([
            'status' => 1,
            'message' => 'Price books.',
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

    /** Active price books only, for a Quote's own picker - no pagination, same shape as CrmTaxRateController::index(). */
    public function active(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $rows = DB::table('crm_price_books')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        return response()->json(['status' => 1, 'message' => 'Price books.', 'data' => $rows->map(fn ($row) => $this->resource($row))->all()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $row = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Price book not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Price book.', 'data' => $this->resource($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:191',
            'assignedTo' => 'required|integer',
            'currency' => 'nullable|string|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $data = $this->payload($request);
        $data['price_book_no'] = 'PB-' . Str::upper(Str::random(8));
        $data['sub_institute_id'] = $identity['sub_institute_id'];
        $data['created_by'] = $identity['user_id'];
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $id = DB::table('crm_price_books')->insertGetId($data);
        $row = $this->ownRow($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Price book created.', 'data' => $this->resource($row)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Price book not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:191',
            'assignedTo' => 'sometimes|required|integer',
            'currency' => 'nullable|string|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $data = $this->payload($request, forUpdate: true);
        $data['updated_by'] = $identity['user_id'];
        $data['updated_at'] = now();

        DB::table('crm_price_books')->where('id', $id)->update($data);
        $this->recordCrmAudit('crm.price_book.updated', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'], (array) $existing, array_merge((array) $existing, $data));
        $row = $this->ownRow($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Price book updated.', 'data' => $this->resource($row)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Price book not found.'], 404);
        }

        DB::table('crm_price_books')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $identity['user_id'],
        ]);

        $this->recordCrmAudit(
            'crm.price_book.deleted', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'],
            (array) $existing,
            array_merge((array) $existing, ['deleted_at' => now()->toDateTimeString(), 'deleted_by' => $identity['user_id']]),
        );

        return response()->json(['status' => 1, 'message' => 'Price book moved to Recycle Bin.']);
    }

    protected function auditEntityType(): string
    {
        return 'crm_price_books';
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkDeleteRows($request, 'crm_price_books', $identity['sub_institute_id'], $identity['user_id'], 'Price book');
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkAssignRows($request, 'crm_price_books', $identity['sub_institute_id'], $identity['user_id'], 'Price book');
    }

    /** Exports the same rows index() would list (search applied). */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $query = DB::table('crm_price_books')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where('name', 'like', "%{$search}%");
        }

        return $this->exportCsv($query, self::EXPORT_COLUMNS, 'price-books.csv');
    }

    // ── Items (per-product override price) ─────────────────────────────

    public function items(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Price book not found.'], 404);
        }

        $rows = DB::table('crm_price_book_items as i')
            ->join('crm_products as p', 'p.id', '=', 'i.product_id')
            ->where('i.price_book_id', $id)
            ->whereNull('p.deleted_at')
            ->select('i.id as row_id', 'p.id as product_id', 'p.name', 'p.item_type', 'p.unit_price as base_price', 'i.list_price')
            ->orderBy('p.name')
            ->get();

        return response()->json([
            'status' => 1,
            'message' => 'Price book items.',
            'data' => $rows->map(fn ($row) => [
                'id' => (string) $row->row_id,
                'productId' => (string) $row->product_id,
                'name' => $row->name,
                'itemType' => $row->item_type,
                'basePrice' => $row->base_price !== null ? (float) $row->base_price : null,
                'listPrice' => (float) $row->list_price,
            ])->all(),
        ]);
    }

    public function addItem(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Price book not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'productId' => 'required|integer',
            'listPrice' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $productId = (int) $request->input('productId');
        $productExists = DB::table('crm_products')
            ->where('id', $productId)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            ->exists();

        if (! $productExists) {
            return response()->json(['status' => 0, 'message' => 'That product was not found.'], 422);
        }

        DB::table('crm_price_book_items')->updateOrInsert(
            ['price_book_id' => $id, 'product_id' => $productId],
            ['list_price' => $request->input('listPrice'), 'created_at' => now(), 'updated_at' => now()],
        );

        return response()->json(['status' => 1, 'message' => 'Price added.']);
    }

    public function updateItem(Request $request, int $id, int $rowId): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Price book not found.'], 404);
        }

        $validator = Validator::make($request->all(), ['listPrice' => 'required|numeric|min:0']);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        DB::table('crm_price_book_items')
            ->where('id', $rowId)
            ->where('price_book_id', $id)
            ->update(['list_price' => $request->input('listPrice'), 'updated_at' => now()]);

        return response()->json(['status' => 1, 'message' => 'Price updated.']);
    }

    public function removeItem(Request $request, int $id, int $rowId): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Price book not found.'], 404);
        }

        DB::table('crm_price_book_items')->where('id', $rowId)->where('price_book_id', $id)->delete();

        return response()->json(['status' => 1, 'message' => 'Price removed.']);
    }

    private function ownRow(int $tenantId, int $id): ?object
    {
        return DB::table('crm_price_books')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, bool $forUpdate = false): array
    {
        $map = [
            'name' => 'name', 'currency' => 'currency', 'description' => 'description',
            'isActive' => 'is_active', 'assignedTo' => 'assigned_to',
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

        return $data;
    }

    /** @return array<string, mixed> */
    private function resource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'priceBookNo' => $row->price_book_no,
            'name' => $row->name,
            'currency' => $row->currency,
            'isActive' => (bool) $row->is_active,
            'description' => $row->description,
            'assignedTo' => $row->assigned_to ? (string) $row->assigned_to : null,
            'createdBy' => $row->created_by ? (string) $row->created_by : null,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }
}
