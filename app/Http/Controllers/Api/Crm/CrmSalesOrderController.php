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
 * Sales Orders - legacy CRM's `vtiger_salesorder`. Same header-only CRUD +
 * separate line-item surface shape as CrmQuoteController (which this file
 * mirrors near-verbatim) - a Sales Order can originate from a Quote
 * (fromQuote()) or be created standalone, and its own stage changes are
 * tracked append-only in crm_sales_order_status_history, the same way
 * CrmOpportunityController tracks sales_stage changes, just narrowed to
 * stage + a total snapshot (that history table has no amount/probability/
 * closing_date columns to mirror those extra fields with).
 *
 * No duplicate-detection/merge pair here, same reasoning as Quotes/
 * Opportunities - two same-named Sales Orders can legitimately be
 * different documents, not a data-entry accident.
 */
class CrmSalesOrderController extends Controller
{
    use ResolvesApiIdentity;
    use HasCrmAuditTrail;
    use HasCrmBulkActions;
    use HasCrmExport;

    /** @var array<string, string> db column => CSV header, in export column order. */
    private const EXPORT_COLUMNS = [
        'subject' => 'Subject', 'so_stage' => 'Stage', 'due_date' => 'Due Date',
        'carrier' => 'Carrier', 'currency' => 'Currency', 'total' => 'Total',
        'terms_conditions' => 'Terms & Conditions', 'description' => 'Description',
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

        $query = $this->baseQuery($identity['sub_institute_id']);

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where('so.subject', 'like', "%{$search}%");
        }

        if ($soStage = $request->input('so_stage')) {
            $query->where('so.so_stage', $soStage);
        }

        if ($organizationId = $request->input('organization_id')) {
            $query->where('so.organization_id', $organizationId);
        }

        if ($contactId = $request->input('contact_id')) {
            $query->where('so.contact_id', $contactId);
        }

        if ($opportunityId = $request->input('opportunity_id')) {
            $query->where('so.opportunity_id', $opportunityId);
        }

        if ($quoteId = $request->input('quote_id')) {
            $query->where('so.quote_id', $quoteId);
        }

        $sortableColumns = ['subject', 'total', 'so_stage', 'due_date', 'created_at'];
        $sortBy = in_array($request->input('sort_by'), $sortableColumns, true) ? $request->input('sort_by') : 'created_at';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'desc' ? 'desc' : 'asc';

        $total = (clone $query)->count();
        $rows = $query->orderBy('so.' . $sortBy, $sortDir)->forPage($page, $perPage)->get();

        return response()->json([
            'status' => 1,
            'message' => 'Sales orders.',
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
            return response()->json(['status' => 0, 'message' => 'Sales order not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Sales order.', 'data' => $this->resource($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'subject' => 'required|string|max:191',
            'assignedTo' => 'required|integer',
            'organizationId' => 'required|integer',
            'contactId' => 'nullable|integer',
            'opportunityId' => 'nullable|integer',
            'quoteId' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        foreach ([
            ['organizationId', 'crm_organizations', 'Organization'],
            ['contactId', 'crm_contacts', 'Contact'],
            ['opportunityId', 'crm_opportunities', 'Opportunity'],
            ['quoteId', 'crm_quotes', 'Quote'],
        ] as [$field, $table, $label]) {
            if (($rejection = $this->rejectUnknownReference($request, $identity['sub_institute_id'], $field, $table, $label)) !== null) {
                return $rejection;
            }
        }

        $data = $this->payload($request);
        /*
         * shipping_handling_amount/adjustment are NOT NULL with a DB-level
         * default of 0 - same trap CrmQuoteController::store() already
         * documents and guards against. An omitted client field becomes an
         * explicit `null` here via payload()'s always-present keys on
         * store(), which the insert would otherwise reject.
         */
        $data['shipping_handling_amount'] = $data['shipping_handling_amount'] ?? 0;
        $data['adjustment'] = $data['adjustment'] ?? 0;
        $data['so_no'] = 'SO-' . Str::upper(Str::random(8));
        $data['sub_institute_id'] = $identity['sub_institute_id'];
        $data['created_by'] = $identity['user_id'];
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $id = DB::table('crm_sales_orders')->insertGetId($data);
        $this->recomputeTotal($id);
        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Sales order created.', 'data' => $this->resource($row)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Sales order not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'subject' => 'sometimes|required|string|max:191',
            'assignedTo' => 'sometimes|required|integer',
            'organizationId' => 'sometimes|required|integer',
            'contactId' => 'nullable|integer',
            'opportunityId' => 'nullable|integer',
            'quoteId' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        foreach ([
            ['organizationId', 'crm_organizations', 'Organization'],
            ['contactId', 'crm_contacts', 'Contact'],
            ['opportunityId', 'crm_opportunities', 'Opportunity'],
            ['quoteId', 'crm_quotes', 'Quote'],
        ] as [$field, $table, $label]) {
            if (($rejection = $this->rejectUnknownReference($request, $identity['sub_institute_id'], $field, $table, $label)) !== null) {
                return $rejection;
            }
        }

        $data = $this->payload($request, forUpdate: true);
        $this->maybeWriteStatusHistory($existing, $data, $identity['user_id']);

        $data['updated_by'] = $identity['user_id'];
        $data['updated_at'] = now();

        DB::table('crm_sales_orders')->where('id', $id)->update($data);
        $this->recordCrmAudit('crm.sales_order.updated', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'], (array) $existing, array_merge((array) $existing, $data));

        /*
         * A header-only save can still touch shippingHandlingAmount/
         * adjustment - both feed the same total formula saveLineItems()
         * uses. Without this, editing shipping/adjustment on a sales order
         * that already has line items would leave `total` stale until
         * someone happens to re-save the line-item table too.
         */
        $this->recomputeTotal($id);
        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Sales order updated.', 'data' => $this->resource($row)]);
    }

    /** Dedicated stage-change endpoint - same history-writing rule as update(), just a narrower, purpose-built surface. */
    public function changeStage(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Sales order not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'soStage' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $data = ['so_stage' => $request->input('soStage'), 'updated_by' => $identity['user_id'], 'updated_at' => now()];
        $this->maybeWriteStatusHistory($existing, $data, $identity['user_id']);

        DB::table('crm_sales_orders')->where('id', $id)->update($data);
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
            return response()->json(['status' => 0, 'message' => 'Sales order not found.'], 404);
        }

        $rows = DB::table('crm_sales_order_status_history')
            ->where('sales_order_id', $id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'status' => 1,
            'message' => 'Stage history.',
            'data' => $rows->map(fn ($row) => [
                'id' => (string) $row->id,
                'fromStage' => $row->from_stage,
                'toStage' => $row->to_stage,
                'total' => $row->total !== null ? (float) $row->total : null,
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

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Sales order not found.'], 404);
        }

        DB::table('crm_sales_orders')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $identity['user_id'],
        ]);

        $this->recordCrmAudit(
            'crm.sales_order.deleted', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'],
            (array) $existing,
            array_merge((array) $existing, ['deleted_at' => now()->toDateTimeString(), 'deleted_by' => $identity['user_id']]),
        );

        return response()->json(['status' => 1, 'message' => 'Sales order moved to Recycle Bin.']);
    }

    protected function auditEntityType(): string
    {
        return 'crm_sales_orders';
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkDeleteRows($request, 'crm_sales_orders', $identity['sub_institute_id'], $identity['user_id'], 'Sales order');
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkAssignRows($request, 'crm_sales_orders', $identity['sub_institute_id'], $identity['user_id'], 'Sales order');
    }

    public function getLineItems(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Sales order not found.'], 404);
        }

        $rows = DB::table('crm_sales_order_line_items')->where('sales_order_id', $id)->orderBy('sequence_no')->get();

        return response()->json(['status' => 1, 'message' => 'Line items.', 'data' => $rows->map(fn ($row) => $this->lineItemResource($row))->all()]);
    }

    /**
     * Replaces the WHOLE line-item set and recomputes every total on the
     * sales order header - exact mirror of
     * CrmQuoteController::saveLineItems()'s arithmetic and wholesale-replace
     * behavior (see that method's own docblock for the full reasoning).
     * taxRateId resolves to a REAL rate at save time, frozen into
     * tax_name_snapshot/tax_percent_snapshot so editing crm_tax_rates later
     * never changes an already-saved sales order's total.
     *
     * Header aggregates: subtotal = sum of gross line amounts (before
     * discount). discountAmount = sum of every line's own discount.
     * taxTotal = sum of every line's own tax. total = subtotal -
     * discountAmount + taxTotal + shippingHandlingAmount + adjustment.
     */
    public function saveLineItems(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $salesOrder = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $salesOrder) {
            return response()->json(['status' => 0, 'message' => 'Sales order not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'lineItems' => 'array',
            'lineItems.*.productId' => 'nullable|integer',
            'lineItems.*.description' => 'nullable|string|max:500',
            'lineItems.*.quantity' => 'required|numeric|min:0.01',
            'lineItems.*.unitPrice' => 'required|numeric|min:0',
            'lineItems.*.discountPercent' => 'nullable|numeric|min:0|max:100',
            'lineItems.*.discountAmount' => 'nullable|numeric|min:0',
            'lineItems.*.taxRateId' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        [$rows, $subtotal, $discountTotal, $taxTotal] = $this->computeLineItemRows(
            $request->input('lineItems', []),
            $id,
            $identity['sub_institute_id'],
        );

        DB::transaction(function () use ($id, $rows, $subtotal, $discountTotal, $taxTotal, $identity) {
            DB::table('crm_sales_order_line_items')->where('sales_order_id', $id)->delete();

            if ($rows !== []) {
                DB::table('crm_sales_order_line_items')->insert($rows);
            }

            DB::table('crm_sales_orders')->where('id', $id)->update([
                'subtotal' => round($subtotal, 2),
                'discount_amount' => round($discountTotal, 2),
                'tax_total' => round($taxTotal, 2),
                'updated_by' => $identity['user_id'],
                'updated_at' => now(),
            ]);

            $this->recomputeTotal($id);
        });

        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Line items saved.', 'data' => $this->resource($row)]);
    }

    /**
     * Converts a Quote into a new Sales Order in one transaction: copies the
     * quote's own organization/contact/currency/addresses/terms (subject
     * defaults to the quote's own subject), sets quote_id to the source
     * quote, clones every crm_quote_line_items row for it verbatim
     * (including the already-frozen tax snapshot - a conversion must not
     * re-evaluate tax rates as of today, it carries the quote's own frozen
     * figures forward), then recomputes the new sales order's total.
     */
    public function fromQuote(Request $request, int $quoteId): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $quote = DB::table('crm_quotes')
            ->where('id', $quoteId)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();

        if (! $quote) {
            return response()->json(['status' => 0, 'message' => 'Quote not found.'], 404);
        }

        $id = DB::transaction(function () use ($quote, $identity, $request) {
            $soId = DB::table('crm_sales_orders')->insertGetId([
                'so_no' => 'SO-' . Str::upper(Str::random(8)),
                'subject' => $request->filled('subject') ? $request->input('subject') : $quote->subject,
                'organization_id' => $quote->organization_id,
                'contact_id' => $quote->contact_id,
                'opportunity_id' => $quote->opportunity_id,
                'quote_id' => $quote->id,
                'currency' => $quote->currency,
                'billing_street' => $quote->billing_street,
                'billing_city' => $quote->billing_city,
                'billing_state' => $quote->billing_state,
                'billing_code' => $quote->billing_code,
                'billing_country' => $quote->billing_country,
                'billing_po_box' => $quote->billing_po_box,
                'shipping_street' => $quote->shipping_street,
                'shipping_city' => $quote->shipping_city,
                'shipping_state' => $quote->shipping_state,
                'shipping_code' => $quote->shipping_code,
                'shipping_country' => $quote->shipping_country,
                'shipping_po_box' => $quote->shipping_po_box,
                'terms_conditions' => $quote->terms_conditions,
                'shipping_handling_amount' => 0,
                'adjustment' => 0,
                'assigned_to' => $identity['user_id'],
                'sub_institute_id' => $identity['sub_institute_id'],
                'created_by' => $identity['user_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $quoteLineItems = DB::table('crm_quote_line_items')->where('quote_id', $quote->id)->orderBy('sequence_no')->get();

            $subtotal = 0.0;
            $discountTotal = 0.0;
            $taxTotal = 0.0;
            $rows = [];

            foreach ($quoteLineItems as $item) {
                $lineGross = (float) $item->quantity * (float) $item->unit_price;
                $discountAmount = (float) ($item->discount_amount ?? 0);
                $subtotal += $lineGross;
                $discountTotal += $discountAmount;
                $taxTotal += (float) $item->line_total - max(0, $lineGross - $discountAmount);

                $rows[] = [
                    'sales_order_id' => $soId,
                    'product_id' => $item->product_id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'discount_percent' => $item->discount_percent,
                    'discount_amount' => $item->discount_amount,
                    'tax_rate_id' => $item->tax_rate_id,
                    'tax_name_snapshot' => $item->tax_name_snapshot,
                    'tax_percent_snapshot' => $item->tax_percent_snapshot,
                    'line_total' => $item->line_total,
                    'sequence_no' => $item->sequence_no,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows !== []) {
                DB::table('crm_sales_order_line_items')->insert($rows);

                DB::table('crm_sales_orders')->where('id', $soId)->update([
                    'subtotal' => round($subtotal, 2),
                    'discount_amount' => round($discountTotal, 2),
                    'tax_total' => round($taxTotal, 2),
                ]);
            }

            $this->recomputeTotal($soId);

            return $soId;
        });

        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Sales order created from quote.', 'data' => $this->resource($row)], 201);
    }

    /** Exports the same rows index() would list (search applied). */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $query = DB::table('crm_sales_orders')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where('subject', 'like', "%{$search}%");
        }

        return $this->exportCsv($query, self::EXPORT_COLUMNS, 'sales-orders.csv');
    }

    /** CSV import - header fields only, same as every other CRM import; line items are never part of a CSV row. */
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
                'subject' => 'required|string|max:191',
                'assignedTo' => 'required|integer',
                'organizationId' => 'required|integer',
            ]);

            if ($rowValidator->fails()) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => $rowValidator->errors()->first()];
                continue;
            }

            if (($reason = $this->unknownReferenceReason($rowRequest, $identity['sub_institute_id'], 'organizationId', 'crm_organizations')) !== null) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => "Organization {$reason}"];
                continue;
            }

            $data = $this->payload($rowRequest);
            $data['shipping_handling_amount'] = $data['shipping_handling_amount'] ?? 0;
            $data['adjustment'] = $data['adjustment'] ?? 0;
            $data['so_no'] = 'SO-' . Str::upper(Str::random(8));
            $data['sub_institute_id'] = $identity['sub_institute_id'];
            $data['created_by'] = $identity['user_id'];
            $data['created_at'] = now();
            $data['updated_at'] = now();

            DB::table('crm_sales_orders')->insert($data);
            $created++;
            $results[] = ['row' => $index + 1, 'ok' => true];
        }

        return response()->json([
            'status' => 1,
            'message' => $created === count($results) ? "{$created} sales order(s) imported." : "{$created} of " . count($results) . ' row(s) imported.',
            'data' => ['created' => $created, 'results' => $results],
        ]);
    }

    /**
     * Shared line-item arithmetic for both saveLineItems() and fromQuote()'s
     * own hand-rolled copy loop conceptually mirrors this, but fromQuote()
     * deliberately does NOT call this helper - it carries the quote's own
     * already-frozen snapshot forward verbatim instead of re-resolving tax
     * rates as of today. This helper is the one place a CLIENT-supplied
     * line-item array (not a cloned-from-quote one) is ever turned into
     * rows + aggregates.
     *
     * @param array<int, array<string, mixed>> $lineItems
     * @return array{0: array<int, array<string, mixed>>, 1: float, 2: float, 3: float}
     */
    private function computeLineItemRows(array $lineItems, int $salesOrderId, int $tenantId): array
    {
        $taxRates = DB::table('crm_tax_rates')
            ->where('sub_institute_id', $tenantId)
            ->get(['id', 'name', 'percentage'])
            ->keyBy('id');

        $subtotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;
        $rows = [];

        foreach (array_values($lineItems) as $index => $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unitPrice'] ?? 0);
            $lineGross = $quantity * $unitPrice;

            $discountPercent = $item['discountPercent'] ?? null;
            $discountAmount = $discountPercent !== null
                ? round($lineGross * ((float) $discountPercent) / 100, 2)
                : (float) ($item['discountAmount'] ?? 0);

            $afterDiscount = max(0, $lineGross - $discountAmount);

            $taxRateId = $item['taxRateId'] ?? null;
            $taxRate = $taxRateId ? $taxRates->get((int) $taxRateId) : null;
            $taxPercent = $taxRate ? (float) $taxRate->percentage : 0.0;
            $lineTax = round($afterDiscount * $taxPercent / 100, 2);

            $lineTotal = round($afterDiscount + $lineTax, 2);

            $subtotal += $lineGross;
            $discountTotal += $discountAmount;
            $taxTotal += $lineTax;

            $rows[] = [
                'sales_order_id' => $salesOrderId,
                'product_id' => $item['productId'] ?? null,
                'description' => $item['description'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_percent' => $discountPercent,
                'discount_amount' => $discountAmount,
                'tax_rate_id' => $taxRate ? $taxRate->id : null,
                'tax_name_snapshot' => $taxRate ? $taxRate->name : null,
                'tax_percent_snapshot' => $taxRate ? $taxRate->percentage : null,
                'line_total' => $lineTotal,
                'sequence_no' => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return [$rows, $subtotal, $discountTotal, $taxTotal];
    }

    /**
     * The one place `total` is ever computed, so store()/update()/
     * saveLineItems()/fromQuote() can never drift apart on the formula.
     * Reads subtotal/discount_amount/tax_total fresh from the row - those
     * three only ever change inside saveLineItems()/fromQuote() - plus
     * whatever shipping_handling_amount/adjustment the row holds right now,
     * so a header-only edit to either of those two still lands in the total
     * without needing the line items re-saved too.
     */
    private function recomputeTotal(int $id): void
    {
        $row = DB::table('crm_sales_orders')->where('id', $id)
            ->first(['subtotal', 'discount_amount', 'tax_total', 'shipping_handling_amount', 'adjustment']);

        if (! $row) {
            return;
        }

        $total = round(
            (float) $row->subtotal - (float) $row->discount_amount + (float) $row->tax_total
                + (float) $row->shipping_handling_amount + (float) $row->adjustment,
            2
        );

        DB::table('crm_sales_orders')->where('id', $id)->update(['total' => $total]);
    }

    /** Writes one crm_sales_order_status_history row whenever so_stage actually changes - total is snapshotted as of right now, before any line-item recompute this same request might also trigger. */
    private function maybeWriteStatusHistory(object $existing, array $newData, int $userId): void
    {
        if (! array_key_exists('so_stage', $newData)) {
            return;
        }

        if ((string) ($newData['so_stage'] ?? '') === (string) ($existing->so_stage ?? '')) {
            return;
        }

        DB::table('crm_sales_order_status_history')->insert([
            'sales_order_id' => $existing->id,
            'from_stage' => $existing->so_stage,
            'to_stage' => $newData['so_stage'],
            'total' => $existing->total,
            'changed_by' => $userId,
            'created_at' => now(),
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
        return DB::table('crm_sales_orders as so')
            ->leftJoin('crm_organizations as o', 'o.id', '=', 'so.organization_id')
            ->leftJoin('crm_contacts as c', 'c.id', '=', 'so.contact_id')
            ->leftJoin('crm_opportunities as op', 'op.id', '=', 'so.opportunity_id')
            ->leftJoin('crm_quotes as q', 'q.id', '=', 'so.quote_id')
            ->leftJoin('tbluser as u', 'u.id', '=', 'so.assigned_to')
            ->where('so.sub_institute_id', $tenantId)
            ->whereNull('so.deleted_at')
            ->select(
                'so.*',
                'o.name as organization_name',
                DB::raw("TRIM(CONCAT(c.first_name, ' ', c.last_name)) as contact_name"),
                'op.name as opportunity_name',
                'q.quote_no as quote_no',
                DB::raw("TRIM(CONCAT(u.first_name, ' ', u.last_name)) as assigned_to_name"),
            );
    }

    private function ownRow(int $tenantId, int $id): ?object
    {
        return DB::table('crm_sales_orders')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    private function ownRowJoined(int $tenantId, int $id): ?object
    {
        return $this->baseQuery($tenantId)->where('so.id', $id)->first();
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, bool $forUpdate = false): array
    {
        $map = [
            'subject' => 'subject', 'organizationId' => 'organization_id', 'contactId' => 'contact_id',
            'opportunityId' => 'opportunity_id', 'quoteId' => 'quote_id', 'soStage' => 'so_stage', 'dueDate' => 'due_date',
            'carrier' => 'carrier', 'currency' => 'currency', 'shippingHandlingAmount' => 'shipping_handling_amount', 'adjustment' => 'adjustment',
            'billingStreet' => 'billing_street', 'billingCity' => 'billing_city', 'billingState' => 'billing_state',
            'billingCode' => 'billing_code', 'billingCountry' => 'billing_country', 'billingPoBox' => 'billing_po_box',
            'shippingStreet' => 'shipping_street', 'shippingCity' => 'shipping_city', 'shippingState' => 'shipping_state',
            'shippingCode' => 'shipping_code', 'shippingCountry' => 'shipping_country', 'shippingPoBox' => 'shipping_po_box',
            'termsConditions' => 'terms_conditions', 'description' => 'description', 'assignedTo' => 'assigned_to',
        ];

        $data = [];

        foreach ($map as $requestKey => $column) {
            if ($forUpdate && ! $request->has($requestKey)) {
                continue;
            }

            $data[$column] = $request->input($requestKey);
        }

        foreach (['organization_id', 'contact_id', 'opportunity_id', 'quote_id'] as $nullableIdColumn) {
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
            'soNo' => $row->so_no,
            'subject' => $row->subject,
            'organizationId' => $row->organization_id ? (string) $row->organization_id : null,
            'organizationName' => $row->organization_name ?? null,
            'contactId' => $row->contact_id ? (string) $row->contact_id : null,
            'contactName' => ($row->contact_name ?? '') !== '' ? $row->contact_name : null,
            'opportunityId' => $row->opportunity_id ? (string) $row->opportunity_id : null,
            'opportunityName' => $row->opportunity_name ?? null,
            'quoteId' => $row->quote_id ? (string) $row->quote_id : null,
            'quoteNo' => $row->quote_no ?? null,
            'soStage' => $row->so_stage,
            'dueDate' => $row->due_date,
            'carrier' => $row->carrier,
            'currency' => $row->currency,
            'subtotal' => (float) $row->subtotal,
            'discountPercent' => $row->discount_percent !== null ? (float) $row->discount_percent : null,
            'discountAmount' => (float) $row->discount_amount,
            'shippingHandlingAmount' => (float) $row->shipping_handling_amount,
            'adjustment' => (float) $row->adjustment,
            'taxTotal' => (float) $row->tax_total,
            'total' => (float) $row->total,
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
            'termsConditions' => $row->terms_conditions,
            'description' => $row->description,
            'assignedTo' => $row->assigned_to ? (string) $row->assigned_to : null,
            'assignedToName' => ($row->assigned_to_name ?? '') !== '' ? $row->assigned_to_name : null,
            'createdBy' => $row->created_by ? (string) $row->created_by : null,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    private function lineItemResource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'productId' => $row->product_id ? (string) $row->product_id : null,
            'description' => $row->description,
            'quantity' => (float) $row->quantity,
            'unitPrice' => (float) $row->unit_price,
            'discountPercent' => $row->discount_percent !== null ? (float) $row->discount_percent : null,
            'discountAmount' => $row->discount_amount !== null ? (float) $row->discount_amount : null,
            'taxRateId' => $row->tax_rate_id ? (string) $row->tax_rate_id : null,
            'taxNameSnapshot' => $row->tax_name_snapshot,
            'taxPercentSnapshot' => $row->tax_percent_snapshot !== null ? (float) $row->tax_percent_snapshot : null,
            'lineTotal' => (float) $row->line_total,
            'sequenceNo' => (int) $row->sequence_no,
        ];
    }
}
