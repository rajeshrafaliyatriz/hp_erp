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
 * Opportunities - the legacy CRM's "Potentials" module, relabeled. No
 * duplicate-detection/merge pair here, same reasoning as Campaigns: two
 * same-named deals for one Organization can be legitimately different
 * records, not an accidental double-entry the way a Lead/Contact/
 * Organization/Product usually is.
 *
 * Deliberately separate from the GTM & Revenue module's `gtm_deals` - see
 * this phase's plan for why. The only connection point is
 * `converted_from_gtm_deal_id`, written by GTM's own deal-won flow
 * (Phase 5), never by anything in this controller.
 */
class CrmOpportunityController extends Controller
{
    use ResolvesApiIdentity;
    use HasCrmBulkActions;
    use HasCrmExport;

    /** @var array<string, string> db column => CSV header, in export column order. */
    private const EXPORT_COLUMNS = [
        'name' => 'Name', 'amount' => 'Amount', 'currency' => 'Currency', 'sales_stage' => 'Sales Stage',
        'probability' => 'Probability', 'closing_date' => 'Closing Date', 'lead_source' => 'Lead Source',
        'potential_type' => 'Type', 'next_step' => 'Next Step', 'forecast_category' => 'Forecast Category',
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
            $query->where('op.name', 'like', "%{$search}%");
        }

        if ($salesStage = $request->input('sales_stage')) {
            $query->where('op.sales_stage', $salesStage);
        }

        if ($organizationId = $request->input('organization_id')) {
            $query->where('op.organization_id', $organizationId);
        }

        $sortableColumns = ['name', 'amount', 'sales_stage', 'closing_date', 'created_at'];
        $sortBy = in_array($request->input('sort_by'), $sortableColumns, true) ? $request->input('sort_by') : 'created_at';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'desc' ? 'desc' : 'asc';

        $total = (clone $query)->count();
        $rows = $query->orderBy('op.' . $sortBy, $sortDir)->forPage($page, $perPage)->get();

        return response()->json([
            'status' => 1,
            'message' => 'Opportunities.',
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

    /**
     * Stage-bucketed counts + per-currency totals for the kanban board -
     * mirrors GTM's own DealController::pipeline() shape. Per-currency, never
     * summed across currencies (same discipline GTM's own pipeline uses) -
     * amounts in different currencies are not the same number.
     */
    public function pipeline(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $stages = DB::table('crm_picklist_values')
            ->where('module', 'opportunities')
            ->where('field_key', 'sales_stage')
            ->where('status', true)
            ->orderBy('sort_order')
            ->get(['value', 'label', 'color']);

        $counts = DB::table('crm_opportunities')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            ->selectRaw('sales_stage, currency, COUNT(*) as cnt, SUM(amount) as total')
            ->groupBy('sales_stage', 'currency')
            ->get();

        $byStage = [];
        foreach ($counts as $row) {
            $byStage[$row->sales_stage]['count'] = ($byStage[$row->sales_stage]['count'] ?? 0) + (int) $row->cnt;
            if ($row->currency) {
                $byStage[$row->sales_stage]['totals'][$row->currency] = (float) $row->total;
            }
        }

        $result = $stages->map(fn ($stage) => [
            'stage' => $stage->value,
            'label' => $stage->label,
            'color' => $stage->color,
            'count' => $byStage[$stage->value]['count'] ?? 0,
            'totalAmount' => array_sum($byStage[$stage->value]['totals'] ?? []),
        ])->all();

        return response()->json(['status' => 1, 'message' => 'Opportunity pipeline.', 'data' => ['stages' => $result]]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Opportunity not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Opportunity.', 'data' => $this->resource($row)]);
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
            'organizationId' => 'nullable|integer',
            'campaignId' => 'nullable|integer',
            'amount' => 'nullable|numeric',
            'probability' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        // A bad organizationId/campaignId would otherwise hit a raw
        // foreign-key violation (a 500, not a clean error) - checked
        // explicitly, same reasoning as CrmProductController's taxRateId fix.
        if (($rejection = $this->rejectUnknownReference($request, $identity['sub_institute_id'], 'organizationId', 'crm_organizations', 'Organization')) !== null) {
            return $rejection;
        }
        if (($rejection = $this->rejectUnknownReference($request, $identity['sub_institute_id'], 'campaignId', 'crm_campaigns', 'Campaign')) !== null) {
            return $rejection;
        }

        $data = $this->payload($request);
        $data['opportunity_no'] = 'OPP-' . Str::upper(Str::random(8));
        $data['sub_institute_id'] = $identity['sub_institute_id'];
        $data['created_by'] = $identity['user_id'];
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $id = DB::table('crm_opportunities')->insertGetId($data);

        if (! empty($data['sales_stage'])) {
            $this->writeStageHistory($id, null, $data['sales_stage'], $data['amount'] ?? null, $data['probability'] ?? null, $data['closing_date'] ?? null, $identity['user_id']);
        }

        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Opportunity created.', 'data' => $this->resource($row)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Opportunity not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:191',
            'assignedTo' => 'sometimes|required|integer',
            'organizationId' => 'nullable|integer',
            'campaignId' => 'nullable|integer',
            'amount' => 'nullable|numeric',
            'probability' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        if (($rejection = $this->rejectUnknownReference($request, $identity['sub_institute_id'], 'organizationId', 'crm_organizations', 'Organization')) !== null) {
            return $rejection;
        }
        if (($rejection = $this->rejectUnknownReference($request, $identity['sub_institute_id'], 'campaignId', 'crm_campaigns', 'Campaign')) !== null) {
            return $rejection;
        }

        $data = $this->payload($request, forUpdate: true);
        $data['updated_by'] = $identity['user_id'];
        $data['updated_at'] = now();

        $this->maybeWriteStageHistory($existing, $data, $identity['user_id']);

        DB::table('crm_opportunities')->where('id', $id)->update($data);
        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Opportunity updated.', 'data' => $this->resource($row)]);
    }

    /** Dedicated stage-change endpoint for the kanban board's drag-to-move - same history-writing rule as update(), just a narrower, purpose-built surface. */
    public function changeStage(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Opportunity not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'salesStage' => 'required|string|max:100',
            'amount' => 'nullable|numeric',
            'probability' => 'nullable|numeric|min:0|max:100',
            'closingDate' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $data = ['sales_stage' => $request->input('salesStage'), 'updated_by' => $identity['user_id'], 'updated_at' => now()];

        foreach (['amount', 'probability', 'closingDate'] as $key) {
            $column = $key === 'closingDate' ? 'closing_date' : $key;
            if ($request->has($key)) {
                $data[$column] = $request->input($key);
            }
        }

        $this->maybeWriteStageHistory($existing, $data, $identity['user_id']);

        DB::table('crm_opportunities')->where('id', $id)->update($data);
        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Stage updated.', 'data' => $this->resource($row)]);
    }

    public function stageHistory(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Opportunity not found.'], 404);
        }

        $rows = DB::table('crm_opportunity_stage_history')
            ->where('opportunity_id', $id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'status' => 1,
            'message' => 'Stage history.',
            'data' => $rows->map(fn ($row) => [
                'id' => (string) $row->id,
                'fromStage' => $row->from_stage,
                'toStage' => $row->to_stage,
                'amount' => $row->amount !== null ? (float) $row->amount : null,
                'probability' => $row->probability !== null ? (float) $row->probability : null,
                'closingDate' => $row->closing_date,
                'changedBy' => $row->changed_by ? (string) $row->changed_by : null,
                'createdAt' => $row->created_at,
            ])->all(),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Opportunity not found.'], 404);
        }

        DB::table('crm_opportunities')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $identity['user_id'],
        ]);

        return response()->json(['status' => 1, 'message' => 'Opportunity moved to Recycle Bin.']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkDeleteRows($request, 'crm_opportunities', $identity['sub_institute_id'], $identity['user_id'], 'Opportunity');
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkAssignRows($request, 'crm_opportunities', $identity['sub_institute_id'], $identity['user_id'], 'Opportunity');
    }

    // ── Contacts junction ──────────────────────────────────────────────

    public function contacts(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Opportunity not found.'], 404);
        }

        $rows = DB::table('crm_opportunity_contacts as oc')
            ->join('crm_contacts as c', 'c.id', '=', 'oc.contact_id')
            ->where('oc.opportunity_id', $id)
            ->whereNull('c.deleted_at')
            ->select('oc.id as row_id', 'c.id as contact_id', 'c.first_name', 'c.last_name', 'c.title', 'c.email')
            ->get();

        return response()->json([
            'status' => 1,
            'message' => 'Opportunity contacts.',
            'data' => $rows->map(fn ($row) => [
                'id' => (string) $row->row_id,
                'contactId' => (string) $row->contact_id,
                'name' => trim("{$row->first_name} {$row->last_name}"),
                'subLabel' => $row->title ?: $row->email,
            ])->all(),
        ]);
    }

    public function addContact(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Opportunity not found.'], 404);
        }

        $validator = Validator::make($request->all(), ['contactId' => 'required|integer']);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $contactId = (int) $request->input('contactId');
        $contactExists = DB::table('crm_contacts')
            ->where('id', $contactId)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            ->exists();

        if (! $contactExists) {
            return response()->json(['status' => 0, 'message' => 'Contact not found.'], 404);
        }

        $alreadyLinked = DB::table('crm_opportunity_contacts')->where('opportunity_id', $id)->where('contact_id', $contactId)->exists();

        if (! $alreadyLinked) {
            DB::table('crm_opportunity_contacts')->insert(['opportunity_id' => $id, 'contact_id' => $contactId, 'created_at' => now()]);
        }

        return response()->json(['status' => 1, 'message' => 'Contact added.']);
    }

    public function removeContact(Request $request, int $id, int $rowId): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Opportunity not found.'], 404);
        }

        DB::table('crm_opportunity_contacts')->where('id', $rowId)->where('opportunity_id', $id)->delete();

        return response()->json(['status' => 1, 'message' => 'Contact removed.']);
    }

    // ── Products junction (unpriced interest tag, NOT a line item) ──────

    public function products(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Opportunity not found.'], 404);
        }

        $rows = DB::table('crm_opportunity_products as op')
            ->join('crm_products as p', 'p.id', '=', 'op.product_id')
            ->where('op.opportunity_id', $id)
            ->whereNull('p.deleted_at')
            ->select('op.id as row_id', 'p.id as product_id', 'p.name', 'p.item_type', 'op.quantity')
            ->get();

        return response()->json([
            'status' => 1,
            'message' => 'Opportunity products.',
            'data' => $rows->map(fn ($row) => [
                'id' => (string) $row->row_id,
                'productId' => (string) $row->product_id,
                'name' => $row->name,
                'itemType' => $row->item_type,
                'quantity' => (float) $row->quantity,
            ])->all(),
        ]);
    }

    public function addProduct(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Opportunity not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'productId' => 'required|integer',
            'quantity' => 'nullable|numeric|min:0.01',
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
            return response()->json(['status' => 0, 'message' => 'Product not found.'], 404);
        }

        DB::table('crm_opportunity_products')->insert([
            'opportunity_id' => $id,
            'product_id' => $productId,
            'quantity' => $request->input('quantity', 1),
            'created_at' => now(),
        ]);

        return response()->json(['status' => 1, 'message' => 'Product added.']);
    }

    public function removeProduct(Request $request, int $id, int $rowId): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Opportunity not found.'], 404);
        }

        DB::table('crm_opportunity_products')->where('id', $rowId)->where('opportunity_id', $id)->delete();

        return response()->json(['status' => 1, 'message' => 'Product removed.']);
    }

    /** Exports the same rows index() would list (search applied). */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $query = DB::table('crm_opportunities')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where('name', 'like', "%{$search}%");
        }

        return $this->exportCsv($query, self::EXPORT_COLUMNS, 'opportunities.csv');
    }

    /** CSV import - a bad/missing owner, Organization, or Campaign rejects the row (no fallback), same discipline as Contacts' own import. */
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
                'name' => 'required|string|max:191',
                'assignedTo' => 'required|integer',
                'organizationId' => 'nullable|integer',
                'campaignId' => 'nullable|integer',
                'amount' => 'nullable|numeric',
            ]);

            if ($rowValidator->fails()) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => $rowValidator->errors()->first()];
                continue;
            }

            if (($reason = $this->unknownReferenceReason($rowRequest, $identity['sub_institute_id'], 'organizationId', 'crm_organizations')) !== null) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => "Organization {$reason}"];
                continue;
            }
            if (($reason = $this->unknownReferenceReason($rowRequest, $identity['sub_institute_id'], 'campaignId', 'crm_campaigns')) !== null) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => "Campaign {$reason}"];
                continue;
            }

            $data = $this->payload($rowRequest);
            $data['opportunity_no'] = 'OPP-' . Str::upper(Str::random(8));
            $data['sub_institute_id'] = $identity['sub_institute_id'];
            $data['created_by'] = $identity['user_id'];
            $data['created_at'] = now();
            $data['updated_at'] = now();

            DB::table('crm_opportunities')->insert($data);
            $created++;
            $results[] = ['row' => $index + 1, 'ok' => true];
        }

        return response()->json([
            'status' => 1,
            'message' => $created === count($results) ? "{$created} opportunity(s) imported." : "{$created} of " . count($results) . ' row(s) imported.',
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

    /** @param array<string, mixed> $newData */
    private function maybeWriteStageHistory(object $existing, array $newData, int $userId): void
    {
        $tracked = ['sales_stage', 'amount', 'probability', 'closing_date'];
        $changed = false;

        foreach ($tracked as $column) {
            if (array_key_exists($column, $newData) && (string) ($newData[$column] ?? '') !== (string) ($existing->{$column} ?? '')) {
                $changed = true;
                break;
            }
        }

        if (! $changed) {
            return;
        }

        $this->writeStageHistory(
            $existing->id,
            $existing->sales_stage,
            $newData['sales_stage'] ?? $existing->sales_stage,
            array_key_exists('amount', $newData) ? $newData['amount'] : $existing->amount,
            array_key_exists('probability', $newData) ? $newData['probability'] : $existing->probability,
            array_key_exists('closing_date', $newData) ? $newData['closing_date'] : $existing->closing_date,
            $userId,
        );
    }

    private function writeStageHistory(int $opportunityId, ?string $fromStage, ?string $toStage, $amount, $probability, $closingDate, int $userId): void
    {
        DB::table('crm_opportunity_stage_history')->insert([
            'opportunity_id' => $opportunityId,
            'from_stage' => $fromStage,
            'to_stage' => $toStage,
            'amount' => $amount,
            'probability' => $probability,
            'closing_date' => $closingDate,
            'changed_by' => $userId,
            'created_at' => now(),
        ]);
    }

    private function baseQuery(int $tenantId)
    {
        return DB::table('crm_opportunities as op')
            ->leftJoin('crm_organizations as o', 'o.id', '=', 'op.organization_id')
            ->leftJoin('crm_campaigns as c', 'c.id', '=', 'op.campaign_id')
            ->where('op.sub_institute_id', $tenantId)
            ->whereNull('op.deleted_at')
            ->select('op.*', 'o.name as organization_name', 'c.name as campaign_name');
    }

    private function ownRow(int $tenantId, int $id): ?object
    {
        return DB::table('crm_opportunities')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    private function ownRowJoined(int $tenantId, int $id): ?object
    {
        return $this->baseQuery($tenantId)->where('op.id', $id)->first();
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, bool $forUpdate = false): array
    {
        $map = [
            'name' => 'name', 'organizationId' => 'organization_id', 'campaignId' => 'campaign_id',
            'amount' => 'amount', 'currency' => 'currency', 'closingDate' => 'closing_date',
            'salesStage' => 'sales_stage', 'probability' => 'probability', 'leadSource' => 'lead_source',
            'potentialType' => 'potential_type', 'nextStep' => 'next_step', 'forecastCategory' => 'forecast_category',
            'description' => 'description', 'assignedTo' => 'assigned_to',
        ];

        $data = [];

        foreach ($map as $requestKey => $column) {
            if ($forUpdate && ! $request->has($requestKey)) {
                continue;
            }

            $data[$column] = $request->input($requestKey);
        }

        foreach (['organization_id', 'campaign_id'] as $nullableIdColumn) {
            if (array_key_exists($nullableIdColumn, $data) && $data[$nullableIdColumn] === '') {
                $data[$nullableIdColumn] = null;
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function resource(object $row): array
    {
        $amount = $row->amount !== null ? (float) $row->amount : null;
        $probability = $row->probability !== null ? (float) $row->probability : null;

        return [
            'id' => (string) $row->id,
            'opportunityNo' => $row->opportunity_no,
            'name' => $row->name,
            'organizationId' => $row->organization_id ? (string) $row->organization_id : null,
            'organizationName' => $row->organization_name ?? null,
            'campaignId' => $row->campaign_id ? (string) $row->campaign_id : null,
            'campaignName' => $row->campaign_name ?? null,
            'amount' => $amount,
            'currency' => $row->currency,
            'closingDate' => $row->closing_date,
            'salesStage' => $row->sales_stage,
            'probability' => $probability,
            // Computed, never stored - amount x probability / 100, same as legacy's "Weighted Revenue" UI label.
            'weightedRevenue' => ($amount !== null && $probability !== null) ? round($amount * $probability / 100, 2) : null,
            'leadSource' => $row->lead_source,
            'potentialType' => $row->potential_type,
            'nextStep' => $row->next_step,
            'forecastCategory' => $row->forecast_category,
            'description' => $row->description,
            'convertedFromGtmDealId' => $row->converted_from_gtm_deal_id ? (string) $row->converted_from_gtm_deal_id : null,
            'assignedTo' => $row->assigned_to ? (string) $row->assigned_to : null,
            'createdBy' => $row->created_by ? (string) $row->created_by : null,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }
}
