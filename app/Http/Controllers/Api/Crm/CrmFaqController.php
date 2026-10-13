<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
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
 * FAQ - CRM Support phase. The simplest of the 4 Support entities: a flat
 * Q&A list with no Organization/Contact relationship at all (confirmed
 * absent in legacy, not added here) and no comments (deliberately deferred,
 * matching Tickets' own comments living on a separate append-only table
 * that FAQ never got).
 *
 * `product_id` is the one optional FK - a question can be scoped to a
 * specific product/service, or left general. `status` is a plain string
 * enum ('Draft'/'Published'/'Obsolete') backed by CrmPicklistController's
 * picklist-values table under field_key 'status', same convention Quotes'
 * quote_stage uses - the column itself carries a DB-level default of
 * 'Draft' (confirmed via SHOW COLUMNS), so store() only needs to fall back
 * to it explicitly when the client payload omits the key, same reasoning
 * CrmQuoteController::store() documents for shipping_handling_amount/
 * adjustment (an included key with an explicit `null` value defeats a
 * column's DB default; only an entirely OMITTED key lets it apply).
 */
class CrmFaqController extends Controller
{
    use ResolvesApiIdentity;
    use HasCrmBulkActions;
    use HasCrmExport;

    /** @var array<string, string> db column => CSV header, in export column order. */
    private const EXPORT_COLUMNS = [
        'question' => 'Question', 'answer' => 'Answer', 'category' => 'Category',
        'status' => 'Status', 'assigned_to' => 'Assigned To (user id)',
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
                $q->where('f.question', 'like', "%{$search}%")
                    ->orWhere('f.answer', 'like', "%{$search}%");
            });
        }

        if ($category = $request->input('category')) {
            $query->where('f.category', $category);
        }

        if ($status = $request->input('status')) {
            $query->where('f.status', $status);
        }

        if ($productId = $request->input('product_id')) {
            $query->where('f.product_id', $productId);
        }

        $sortableColumns = ['question', 'category', 'status', 'created_at'];
        $sortBy = in_array($request->input('sort_by'), $sortableColumns, true) ? $request->input('sort_by') : 'created_at';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'desc' ? 'desc' : 'asc';

        $total = (clone $query)->count();
        $rows = $query->orderBy('f.' . $sortBy, $sortDir)->forPage($page, $perPage)->get();

        return response()->json([
            'status' => 1,
            'message' => 'FAQs.',
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
            return response()->json(['status' => 0, 'message' => 'FAQ not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'FAQ.', 'data' => $this->resource($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'question' => 'required|string|max:500',
            'answer' => 'required|string',
            'category' => 'nullable|string|max:50',
            'status' => 'nullable|string|max:20',
            'productId' => 'nullable|integer',
            'assignedTo' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        if (($rejection = $this->rejectUnknownReference($request, $identity['sub_institute_id'], 'productId', 'crm_products', 'Product')) !== null) {
            return $rejection;
        }

        $data = $this->payload($request);
        $data['status'] = $data['status'] ?: 'Draft';
        $data['faq_no'] = 'FAQ-' . Str::upper(Str::random(8));
        $data['sub_institute_id'] = $identity['sub_institute_id'];
        $data['created_by'] = $identity['user_id'];
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $id = DB::table('crm_faqs')->insertGetId($data);
        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'FAQ created.', 'data' => $this->resource($row)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'FAQ not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'question' => 'sometimes|required|string|max:500',
            'answer' => 'sometimes|required|string',
            'category' => 'nullable|string|max:50',
            'status' => 'nullable|string|max:20',
            'productId' => 'nullable|integer',
            'assignedTo' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        if (($rejection = $this->rejectUnknownReference($request, $identity['sub_institute_id'], 'productId', 'crm_products', 'Product')) !== null) {
            return $rejection;
        }

        $data = $this->payload($request, forUpdate: true);
        $data['updated_by'] = $identity['user_id'];
        $data['updated_at'] = now();

        DB::table('crm_faqs')->where('id', $id)->update($data);
        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'FAQ updated.', 'data' => $this->resource($row)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'FAQ not found.'], 404);
        }

        DB::table('crm_faqs')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $identity['user_id'],
        ]);

        return response()->json(['status' => 1, 'message' => 'FAQ moved to Recycle Bin.']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkDeleteRows($request, 'crm_faqs', $identity['sub_institute_id'], $identity['user_id'], 'FAQ');
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkAssignRows($request, 'crm_faqs', $identity['sub_institute_id'], $identity['user_id'], 'FAQ');
    }

    /** Exports the same rows index() would list (search applied). */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $query = DB::table('crm_faqs')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('question', 'like', "%{$search}%")
                    ->orWhere('answer', 'like', "%{$search}%");
            });
        }

        return $this->exportCsv($query, self::EXPORT_COLUMNS, 'faqs.csv');
    }

    /** CSV import - same row-by-row best-effort shape as every other CRM import. */
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
                'question' => 'required|string|max:500',
                'answer' => 'required|string',
            ]);

            if ($rowValidator->fails()) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => $rowValidator->errors()->first()];
                continue;
            }

            if (($reason = $this->unknownReferenceReason($rowRequest, $identity['sub_institute_id'], 'productId', 'crm_products')) !== null) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => "Product {$reason}"];
                continue;
            }

            $data = $this->payload($rowRequest);
            $data['status'] = $data['status'] ?: 'Draft';
            $data['faq_no'] = 'FAQ-' . Str::upper(Str::random(8));
            $data['sub_institute_id'] = $identity['sub_institute_id'];
            $data['created_by'] = $identity['user_id'];
            $data['created_at'] = now();
            $data['updated_at'] = now();

            DB::table('crm_faqs')->insert($data);
            $created++;
            $results[] = ['row' => $index + 1, 'ok' => true];
        }

        return response()->json([
            'status' => 1,
            'message' => $created === count($results) ? "{$created} FAQ(s) imported." : "{$created} of " . count($results) . ' row(s) imported.',
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
        return DB::table('crm_faqs as f')
            ->leftJoin('crm_products as p', 'p.id', '=', 'f.product_id')
            ->leftJoin('tbluser as u', 'u.id', '=', 'f.assigned_to')
            ->where('f.sub_institute_id', $tenantId)
            ->whereNull('f.deleted_at')
            ->select('f.*', 'p.name as product_name', DB::raw("TRIM(CONCAT(u.first_name, ' ', u.last_name)) as assigned_to_name"));
    }

    private function ownRow(int $tenantId, int $id): ?object
    {
        return DB::table('crm_faqs')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    private function ownRowJoined(int $tenantId, int $id): ?object
    {
        return $this->baseQuery($tenantId)->where('f.id', $id)->first();
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, bool $forUpdate = false): array
    {
        $map = [
            'question' => 'question', 'answer' => 'answer', 'category' => 'category',
            'status' => 'status', 'productId' => 'product_id', 'assignedTo' => 'assigned_to',
        ];

        $data = [];

        foreach ($map as $requestKey => $column) {
            if ($forUpdate && ! $request->has($requestKey)) {
                continue;
            }

            $data[$column] = $request->input($requestKey);
        }

        foreach (['product_id', 'assigned_to'] as $nullableIdColumn) {
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
            'legacyId' => $row->legacy_id !== null ? (string) $row->legacy_id : null,
            'faqNo' => $row->faq_no,
            'question' => $row->question,
            'answer' => $row->answer,
            'category' => $row->category,
            'status' => $row->status,
            'productId' => $row->product_id ? (string) $row->product_id : null,
            'productName' => $row->product_name ?? null,
            'assignedTo' => $row->assigned_to ? (string) $row->assigned_to : null,
            'assignedToName' => ($row->assigned_to_name ?? '') !== '' ? $row->assigned_to_name : null,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }
}
