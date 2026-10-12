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
 * Purchase Orders - legacy CRM's `vtiger_purchaseorder`. Same raw-query-
 * builder/header-plus-line-items shape as CrmQuoteController, which this
 * class mirrors structurally (payload/resource/baseQuery/ownRow/
 * rejectUnknownReference, numbering via a random suffix, manual
 * soft-delete). No duplicate-detection/merge pair - same reasoning as
 * Quotes/Opportunities, two same-named purchase orders can legitimately be
 * different documents.
 *
 * vendor_id is the one field this module enforces as REQUIRED at the
 * application-validation level (store() and update()) even though it is
 * nullable at the DB level, matching legacy's own PurchaseOrder mandatory
 * vendor field - every other FK here stays optional like Quotes'.
 *
 * Status history (crm_purchase_order_status_history) is append-only, one
 * row per actual po_stage change, written from changeStage() only - a
 * header-only update() that doesn't touch po_stage never writes a history
 * row, matching crm_opportunity_stage_history's own convention.
 */
class CrmPurchaseOrderController extends Controller
{
    use ResolvesApiIdentity;
    use HasCrmBulkActions;
    use HasCrmExport;

    /** @var array<string, string> db column => CSV header, in export column order. */
    private const EXPORT_COLUMNS = [
        'subject' => 'Subject', 'po_stage' => 'Stage', 'due_date' => 'Due Date',
        'currency' => 'Currency', 'total' => 'Total', 'terms_conditions' => 'Terms & Conditions',
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
            $query->where('po.subject', 'like', "%{$search}%");
        }

        if ($poStage = $request->input('po_stage')) {
            $query->where('po.po_stage', $poStage);
        }

        if ($vendorId = $request->input('vendor_id')) {
            $query->where('po.vendor_id', $vendorId);
        }

        if ($organizationId = $request->input('organization_id')) {
            $query->where('po.organization_id', $organizationId);
        }

        if ($contactId = $request->input('contact_id')) {
            $query->where('po.contact_id', $contactId);
        }

        if ($quoteId = $request->input('quote_id')) {
            $query->where('po.quote_id', $quoteId);
        }

        $sortableColumns = ['subject', 'total', 'po_stage', 'due_date', 'created_at'];
        $sortBy = in_array($request->input('sort_by'), $sortableColumns, true) ? $request->input('sort_by') : 'created_at';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'desc' ? 'desc' : 'asc';

        $total = (clone $query)->count();
        $rows = $query->orderBy('po.' . $sortBy, $sortDir)->forPage($page, $perPage)->get();

        return response()->json([
            'status' => 1,
            'message' => 'Purchase orders.',
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
            return response()->json(['status' => 0, 'message' => 'Purchase order not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Purchase order.', 'data' => $this->resource($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'subject' => 'required|string|max:191',
            'vendorId' => 'required|integer',
            'assignedTo' => 'required|integer',
            'organizationId' => 'nullable|integer',
            'contactId' => 'nullable|integer',
            'quoteId' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        foreach ([
            ['vendorId', 'crm_vendors', 'Vendor'],
            ['organizationId', 'crm_organizations', 'Organization'],
            ['contactId', 'crm_contacts', 'Contact'],
            ['quoteId', 'crm_quotes', 'Quote'],
        ] as [$field, $table, $label]) {
            if (($rejection = $this->rejectUnknownReference($request, $identity['sub_institute_id'], $field, $table, $label)) !== null) {
                return $rejection;
            }
        }

        $data = $this->payload($request);
        /*
         * Same NOT-NULL-with-a-DB-default trap CrmQuoteController::store()
         * already documents - shipping_handling_amount/adjustment are
         * NOT NULL with a default of 0, but that default only applies when
         * the column is omitted entirely, not when payload() includes it as
         * an explicit `null`.
         */
        $data['shipping_handling_amount'] = $data['shipping_handling_amount'] ?? 0;
        $data['adjustment'] = $data['adjustment'] ?? 0;
        $data['po_no'] = 'PO-' . Str::upper(Str::random(8));
        $data['sub_institute_id'] = $identity['sub_institute_id'];
        $data['created_by'] = $identity['user_id'];
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $id = DB::table('crm_purchase_orders')->insertGetId($data);
        $this->recomputeTotal($id);
        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Purchase order created.', 'data' => $this->resource($row)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Purchase order not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'subject' => 'sometimes|required|string|max:191',
            'vendorId' => 'sometimes|required|integer',
            'assignedTo' => 'sometimes|required|integer',
            'organizationId' => 'nullable|integer',
            'contactId' => 'nullable|integer',
            'quoteId' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        foreach ([
            ['vendorId', 'crm_vendors', 'Vendor'],
            ['organizationId', 'crm_organizations', 'Organization'],
            ['contactId', 'crm_contacts', 'Contact'],
            ['quoteId', 'crm_quotes', 'Quote'],
        ] as [$field, $table, $label]) {
            if (($rejection = $this->rejectUnknownReference($request, $identity['sub_institute_id'], $field, $table, $label)) !== null) {
                return $rejection;
            }
        }

        $data = $this->payload($request, forUpdate: true);
        $data['updated_by'] = $identity['user_id'];
        $data['updated_at'] = now();

        DB::table('crm_purchase_orders')->where('id', $id)->update($data);

        /*
         * A header-only save can still touch shippingHandlingAmount/
         * adjustment - both feed the same total formula saveLineItems()
         * uses, so recompute here too (see CrmQuoteController::update()'s
         * own docblock for why).
         */
        $this->recomputeTotal($id);
        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Purchase order updated.', 'data' => $this->resource($row)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Purchase order not found.'], 404);
        }

        DB::table('crm_purchase_orders')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $identity['user_id'],
        ]);

        return response()->json(['status' => 1, 'message' => 'Purchase order moved to Recycle Bin.']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkDeleteRows($request, 'crm_purchase_orders', $identity['sub_institute_id'], $identity['user_id'], 'Purchase order');
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkAssignRows($request, 'crm_purchase_orders', $identity['sub_institute_id'], $identity['user_id'], 'Purchase order');
    }

    /**
     * PUT /crm/purchase-orders/{id}/stage - changes `po_stage` only, and
     * writes a `crm_purchase_order_status_history` row IF the stage actually
     * changed (same guard CrmOpportunityController::changeStage() uses
     * around crm_opportunity_stage_history - a save that resubmits the same
     * stage must not pile up a no-op history row).
     */
    public function changeStage(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Purchase order not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'poStage' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $toStage = $request->input('poStage');

        if ($toStage !== $existing->po_stage) {
            DB::table('crm_purchase_order_status_history')->insert([
                'purchase_order_id' => $id,
                'from_stage' => $existing->po_stage,
                'to_stage' => $toStage,
                'total' => $existing->total,
                'changed_by' => $identity['user_id'],
                'created_at' => now(),
            ]);
        }

        DB::table('crm_purchase_orders')->where('id', $id)->update([
            'po_stage' => $toStage,
            'updated_by' => $identity['user_id'],
            'updated_at' => now(),
        ]);

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
            return response()->json(['status' => 0, 'message' => 'Purchase order not found.'], 404);
        }

        $rows = DB::table('crm_purchase_order_status_history')
            ->where('purchase_order_id', $id)
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

    public function getLineItems(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Purchase order not found.'], 404);
        }

        $rows = DB::table('crm_purchase_order_line_items')->where('purchase_order_id', $id)->orderBy('sequence_no')->get();

        return response()->json(['status' => 1, 'message' => 'Line items.', 'data' => $rows->map(fn ($row) => $this->lineItemResource($row))->all()]);
    }

    /**
     * Replaces the WHOLE line-item set and recomputes every total on the PO
     * header - identical mechanics to CrmQuoteController::saveLineItems()
     * (see its own docblock for the exact arithmetic and why tax is a
     * frozen per-line snapshot, not a live join).
     */
    public function saveLineItems(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $purchaseOrder = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $purchaseOrder) {
            return response()->json(['status' => 0, 'message' => 'Purchase order not found.'], 404);
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

        $lineItems = $request->input('lineItems', []);
        $taxRates = DB::table('crm_tax_rates')
            ->where('sub_institute_id', $identity['sub_institute_id'])
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
                'purchase_order_id' => $id,
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

        DB::transaction(function () use ($id, $rows, $subtotal, $discountTotal, $taxTotal, $identity) {
            DB::table('crm_purchase_order_line_items')->where('purchase_order_id', $id)->delete();

            if ($rows !== []) {
                DB::table('crm_purchase_order_line_items')->insert($rows);
            }

            DB::table('crm_purchase_orders')->where('id', $id)->update([
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
     * POST /crm/purchase-orders/from-quote/{quoteId} - copies the quote's
     * header fields and its whole line-item set into a brand-new Purchase
     * Order, inside one transaction (either both the header and every line
     * land, or neither does). `vendor_id` is deliberately left null - Quotes
     * have no vendor concept, so there is nothing to copy it from; the user
     * fills it in afterward via a normal update() call. `quote_id` on the
     * new row links back to its source, same column Quotes themselves use
     * to link back to an Opportunity.
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

        $id = DB::transaction(function () use ($quote, $quoteId, $identity) {
            $id = DB::table('crm_purchase_orders')->insertGetId([
                'po_no' => 'PO-' . Str::upper(Str::random(8)),
                'subject' => $quote->subject,
                'vendor_id' => null,
                'organization_id' => $quote->organization_id,
                'contact_id' => $quote->contact_id,
                'quote_id' => $quoteId,
                'currency' => $quote->currency,
                'subtotal' => $quote->subtotal,
                'discount_percent' => $quote->discount_percent,
                'discount_amount' => $quote->discount_amount,
                'shipping_handling_amount' => $quote->shipping_handling_amount ?? 0,
                'adjustment' => $quote->adjustment ?? 0,
                'tax_total' => $quote->tax_total,
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
                'description' => $quote->description,
                'assigned_to' => $quote->assigned_to ?? $identity['user_id'],
                'sub_institute_id' => $identity['sub_institute_id'],
                'created_by' => $identity['user_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $lineItems = DB::table('crm_quote_line_items')->where('quote_id', $quoteId)->orderBy('sequence_no')->get();

            if ($lineItems->isNotEmpty()) {
                DB::table('crm_purchase_order_line_items')->insert($lineItems->map(fn ($item) => [
                    'purchase_order_id' => $id,
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
                ])->all());
            }

            $this->recomputeTotal($id);

            return $id;
        });

        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Purchase order created from quote.', 'data' => $this->resource($row)], 201);
    }

    /** Exports the same rows index() would list (search applied). */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $query = DB::table('crm_purchase_orders')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where('subject', 'like', "%{$search}%");
        }

        return $this->exportCsv($query, self::EXPORT_COLUMNS, 'purchase-orders.csv');
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
                'vendorId' => 'required|integer',
                'assignedTo' => 'required|integer',
            ]);

            if ($rowValidator->fails()) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => $rowValidator->errors()->first()];
                continue;
            }

            if (($reason = $this->unknownReferenceReason($rowRequest, $identity['sub_institute_id'], 'vendorId', 'crm_vendors')) !== null) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => "Vendor {$reason}"];
                continue;
            }

            $data = $this->payload($rowRequest);
            $data['shipping_handling_amount'] = $data['shipping_handling_amount'] ?? 0;
            $data['adjustment'] = $data['adjustment'] ?? 0;
            $data['po_no'] = 'PO-' . Str::upper(Str::random(8));
            $data['sub_institute_id'] = $identity['sub_institute_id'];
            $data['created_by'] = $identity['user_id'];
            $data['created_at'] = now();
            $data['updated_at'] = now();

            DB::table('crm_purchase_orders')->insert($data);
            $created++;
            $results[] = ['row' => $index + 1, 'ok' => true];
        }

        return response()->json([
            'status' => 1,
            'message' => $created === count($results) ? "{$created} purchase order(s) imported." : "{$created} of " . count($results) . ' row(s) imported.',
            'data' => ['created' => $created, 'results' => $results],
        ]);
    }

    /**
     * The one place `total` is ever computed - reads subtotal/
     * discount_amount/tax_total fresh from the row (those three only ever
     * change inside saveLineItems()) plus whatever shipping_handling_amount/
     * adjustment the row holds right now, so a header-only edit to either
     * still lands in the total without needing the line items re-saved too.
     */
    private function recomputeTotal(int $id): void
    {
        $row = DB::table('crm_purchase_orders')->where('id', $id)
            ->first(['subtotal', 'discount_amount', 'tax_total', 'shipping_handling_amount', 'adjustment']);

        if (! $row) {
            return;
        }

        $total = round(
            (float) $row->subtotal - (float) $row->discount_amount + (float) $row->tax_total
                + (float) $row->shipping_handling_amount + (float) $row->adjustment,
            2
        );

        DB::table('crm_purchase_orders')->where('id', $id)->update(['total' => $total]);
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
        return DB::table('crm_purchase_orders as po')
            ->leftJoin('crm_vendors as v', 'v.id', '=', 'po.vendor_id')
            ->leftJoin('crm_organizations as o', 'o.id', '=', 'po.organization_id')
            ->leftJoin('crm_contacts as c', 'c.id', '=', 'po.contact_id')
            ->leftJoin('crm_quotes as q', 'q.id', '=', 'po.quote_id')
            ->where('po.sub_institute_id', $tenantId)
            ->whereNull('po.deleted_at')
            ->select('po.*', 'v.name as vendor_name', 'o.name as organization_name', DB::raw("TRIM(CONCAT(c.first_name, ' ', c.last_name)) as contact_name"), 'q.quote_no as quote_no');
    }

    private function ownRow(int $tenantId, int $id): ?object
    {
        return DB::table('crm_purchase_orders')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    private function ownRowJoined(int $tenantId, int $id): ?object
    {
        return $this->baseQuery($tenantId)->where('po.id', $id)->first();
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, bool $forUpdate = false): array
    {
        $map = [
            'subject' => 'subject', 'vendorId' => 'vendor_id', 'organizationId' => 'organization_id', 'contactId' => 'contact_id',
            'quoteId' => 'quote_id', 'poStage' => 'po_stage', 'requisitionNo' => 'requisition_no', 'trackingNo' => 'tracking_no',
            'dueDate' => 'due_date', 'currency' => 'currency', 'shippingHandlingAmount' => 'shipping_handling_amount', 'adjustment' => 'adjustment',
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

        foreach (['organization_id', 'contact_id', 'quote_id'] as $nullableIdColumn) {
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
            'poNo' => $row->po_no,
            'subject' => $row->subject,
            'vendorId' => $row->vendor_id ? (string) $row->vendor_id : null,
            'vendorName' => $row->vendor_name ?? null,
            'organizationId' => $row->organization_id ? (string) $row->organization_id : null,
            'organizationName' => $row->organization_name ?? null,
            'contactId' => $row->contact_id ? (string) $row->contact_id : null,
            'contactName' => ($row->contact_name ?? '') !== '' ? $row->contact_name : null,
            'quoteId' => $row->quote_id ? (string) $row->quote_id : null,
            'quoteNo' => $row->quote_no ?? null,
            'poStage' => $row->po_stage,
            'requisitionNo' => $row->requisition_no,
            'trackingNo' => $row->tracking_no,
            'dueDate' => $row->due_date,
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
