<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmAuditTrail;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmBulkActions;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmEmailLinks;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmExport;
use App\Http\Controllers\Controller;
use App\Services\Documents\DocumentStorageService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Invoices - legacy CRM's `vtiger_invoice`. Header-only CRUD here
 * (subject/stage/dates/addresses/terms) - line items are their own small
 * surface (getLineItems/saveLineItems below), same convention Quotes already
 * use. No duplicate-detection/merge pair, same reasoning as Opportunities/
 * Quotes/Sales Orders/Purchase Orders - two same-named invoices can
 * legitimately be different documents, not a data-entry accident.
 *
 * Deliberately no `quote_id` column (confirmed in the legacy schema itself -
 * an Invoice only ever chains off a Sales Order, or stands alone, never
 * directly off a Quote). `fromSalesOrder()` is the one conversion path in:
 * it copies a Sales Order's header fields + a fresh copy of its line items
 * (never a shared reference - editing the Invoice's lines must never
 * retroactively change the originating Sales Order's own totals).
 *
 * `invoice_stage` is NOT mandatory (confirmed from the legacy source, unlike
 * so_stage/po_stage) - a new invoice may start with no stage set at all.
 *
 * Every money total is SERVER-RECOMPUTED on save, never trusted from the
 * client - see saveLineItems()'s own docblock for the exact arithmetic.
 */
class CrmInvoiceController extends Controller
{
    use ResolvesApiIdentity;
    use HasCrmAuditTrail;
    use HasCrmBulkActions;
    use HasCrmEmailLinks;
    use HasCrmExport;

    /** @var array<string, string> db column => CSV header, in export column order. */
    private const EXPORT_COLUMNS = [
        'subject' => 'Subject', 'invoice_stage' => 'Stage', 'invoice_date' => 'Invoice Date',
        'due_date' => 'Due Date', 'currency' => 'Currency', 'total' => 'Total',
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
            $query->where('i.subject', 'like', "%{$search}%");
        }

        if ($invoiceStage = $request->input('invoice_stage')) {
            $query->where('i.invoice_stage', $invoiceStage);
        }

        if ($organizationId = $request->input('organization_id')) {
            $query->where('i.organization_id', $organizationId);
        }

        if ($contactId = $request->input('contact_id')) {
            $query->where('i.contact_id', $contactId);
        }

        if ($salesOrderId = $request->input('sales_order_id')) {
            $query->where('i.sales_order_id', $salesOrderId);
        }

        $sortableColumns = ['subject', 'total', 'invoice_stage', 'invoice_date', 'due_date', 'created_at'];
        $sortBy = in_array($request->input('sort_by'), $sortableColumns, true) ? $request->input('sort_by') : 'created_at';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'desc' ? 'desc' : 'asc';

        $total = (clone $query)->count();
        $rows = $query->orderBy('i.' . $sortBy, $sortDir)->forPage($page, $perPage)->get();

        return response()->json([
            'status' => 1,
            'message' => 'Invoices.',
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
            return response()->json(['status' => 0, 'message' => 'Invoice not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Invoice.', 'data' => $this->resource($row)]);
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
            'organizationId' => 'nullable|integer',
            'contactId' => 'nullable|integer',
            'salesOrderId' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        foreach ([
            ['organizationId', 'crm_organizations', 'Organization'],
            ['contactId', 'crm_contacts', 'Contact'],
            ['salesOrderId', 'crm_sales_orders', 'Sales order'],
        ] as [$field, $table, $label]) {
            if (($rejection = $this->rejectUnknownReference($request, $identity['sub_institute_id'], $field, $table, $label)) !== null) {
                return $rejection;
            }
        }

        $data = $this->payload($request);
        /*
         * shipping_handling_amount/adjustment are NOT NULL with a DB-level
         * default of 0 - that default only applies when a column is
         * OMITTED from the insert entirely, not when NULL is passed for it
         * explicitly. payload() always includes both keys on store() (unlike
         * forUpdate, which skips absent keys), so an untouched client field
         * becomes an explicit `null` here - same fix as CrmQuoteController::store().
         */
        $data['shipping_handling_amount'] = $data['shipping_handling_amount'] ?? 0;
        $data['adjustment'] = $data['adjustment'] ?? 0;
        $data['invoice_no'] = 'INV-' . Str::upper(Str::random(8));
        $data['sub_institute_id'] = $identity['sub_institute_id'];
        $data['created_by'] = $identity['user_id'];
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $id = DB::table('crm_invoices')->insertGetId($data);
        $this->recomputeTotal($id);

        if (! empty($data['invoice_stage'])) {
            $total = (float) DB::table('crm_invoices')->where('id', $id)->value('total');
            $this->writeStatusHistory($id, null, $data['invoice_stage'], $total, $identity['user_id']);
        }

        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Invoice created.', 'data' => $this->resource($row)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Invoice not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'subject' => 'sometimes|required|string|max:191',
            'assignedTo' => 'sometimes|required|integer',
            'organizationId' => 'nullable|integer',
            'contactId' => 'nullable|integer',
            'salesOrderId' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        foreach ([
            ['organizationId', 'crm_organizations', 'Organization'],
            ['contactId', 'crm_contacts', 'Contact'],
            ['salesOrderId', 'crm_sales_orders', 'Sales order'],
        ] as [$field, $table, $label]) {
            if (($rejection = $this->rejectUnknownReference($request, $identity['sub_institute_id'], $field, $table, $label)) !== null) {
                return $rejection;
            }
        }

        $data = $this->payload($request, forUpdate: true);
        $data['updated_by'] = $identity['user_id'];
        $data['updated_at'] = now();

        $stageChanged = array_key_exists('invoice_stage', $data) && (string) ($data['invoice_stage'] ?? '') !== (string) ($existing->invoice_stage ?? '');

        DB::table('crm_invoices')->where('id', $id)->update($data);
        $this->recordCrmAudit('crm.invoice.updated', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'], (array) $existing, array_merge((array) $existing, $data));

        /*
         * A header-only save can still touch shippingHandlingAmount/
         * adjustment - both feed the same total formula saveLineItems()
         * uses. Without this, editing shipping/adjustment on an invoice that
         * already has line items would leave `total` stale.
         */
        $this->recomputeTotal($id);

        if ($stageChanged) {
            $total = (float) DB::table('crm_invoices')->where('id', $id)->value('total');
            $this->writeStatusHistory($id, $existing->invoice_stage, $data['invoice_stage'], $total, $identity['user_id']);
        }

        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Invoice updated.', 'data' => $this->resource($row)]);
    }

    /** Dedicated stage-change endpoint, same narrower-surface convention as CrmOpportunityController::changeStage(). */
    public function changeStage(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Invoice not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'invoiceStage' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $newStage = $request->input('invoiceStage');

        DB::table('crm_invoices')->where('id', $id)->update([
            'invoice_stage' => $newStage,
            'updated_by' => $identity['user_id'],
            'updated_at' => now(),
        ]);

        if ((string) $newStage !== (string) ($existing->invoice_stage ?? '')) {
            $this->writeStatusHistory($id, $existing->invoice_stage, $newStage, (float) $existing->total, $identity['user_id']);
        }

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
            return response()->json(['status' => 0, 'message' => 'Invoice not found.'], 404);
        }

        $rows = DB::table('crm_invoice_status_history')
            ->where('invoice_id', $id)
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
            return response()->json(['status' => 0, 'message' => 'Invoice not found.'], 404);
        }

        DB::table('crm_invoices')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $identity['user_id'],
        ]);

        $this->recordCrmAudit(
            'crm.invoice.deleted', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'],
            (array) $existing,
            array_merge((array) $existing, ['deleted_at' => now()->toDateTimeString(), 'deleted_by' => $identity['user_id']]),
        );

        return response()->json(['status' => 1, 'message' => 'Invoice moved to Recycle Bin.']);
    }

    protected function auditEntityType(): string
    {
        return 'crm_invoices';
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkDeleteRows($request, 'crm_invoices', $identity['sub_institute_id'], $identity['user_id'], 'Invoice');
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkAssignRows($request, 'crm_invoices', $identity['sub_institute_id'], $identity['user_id'], 'Invoice');
    }

    public function getLineItems(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Invoice not found.'], 404);
        }

        $rows = DB::table('crm_invoice_line_items')->where('invoice_id', $id)->orderBy('sequence_no')->get();

        return response()->json(['status' => 1, 'message' => 'Line items.', 'data' => $rows->map(fn ($row) => $this->lineItemResource($row))->all()]);
    }

    /**
     * Replaces the WHOLE line-item set and recomputes every total on the
     * invoice header - there is no per-line create/update/delete endpoint,
     * same convention as CrmQuoteController::saveLineItems() (see that
     * method's own docblock for the exact arithmetic - this mirrors it
     * exactly, only the FK column (invoice_id) differs).
     */
    public function saveLineItems(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $invoice = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $invoice) {
            return response()->json(['status' => 0, 'message' => 'Invoice not found.'], 404);
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
                'invoice_id' => $id,
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
            DB::table('crm_invoice_line_items')->where('invoice_id', $id)->delete();

            if ($rows !== []) {
                DB::table('crm_invoice_line_items')->insert($rows);
            }

            DB::table('crm_invoices')->where('id', $id)->update([
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
     * Converts a Sales Order into a new Invoice - the one conversion path
     * this entity gets (there is deliberately no quote_id/fromQuote(), see
     * this controller's own docblock). Copies the order's header fields +
     * a FRESH COPY of its line items (never a shared reference), server-
     * recomputes the new invoice's own totals from that copy, and sets
     * `invoice_date` to today. `crm_sales_orders`/`crm_sales_order_line_items`
     * are read with a plain arm's-length `DB::table()` call, same pattern
     * every other CRM controller already uses for a sibling module's tables -
     * never CrmSalesOrderController's own code.
     */
    public function fromSalesOrder(Request $request, int $salesOrderId): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $salesOrder = DB::table('crm_sales_orders')
            ->where('id', $salesOrderId)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();

        if (! $salesOrder) {
            return response()->json(['status' => 0, 'message' => 'Sales order not found.'], 404);
        }

        $id = DB::transaction(function () use ($salesOrder, $salesOrderId, $identity) {
            $invoiceData = [
                'subject' => $salesOrder->subject,
                'sales_order_id' => $salesOrder->id,
                'organization_id' => $salesOrder->organization_id,
                'contact_id' => $salesOrder->contact_id,
                'currency' => $salesOrder->currency,
                'billing_street' => $salesOrder->billing_street,
                'billing_city' => $salesOrder->billing_city,
                'billing_state' => $salesOrder->billing_state,
                'billing_code' => $salesOrder->billing_code,
                'billing_country' => $salesOrder->billing_country,
                'billing_po_box' => $salesOrder->billing_po_box,
                'shipping_street' => $salesOrder->shipping_street,
                'shipping_city' => $salesOrder->shipping_city,
                'shipping_state' => $salesOrder->shipping_state,
                'shipping_code' => $salesOrder->shipping_code,
                'shipping_country' => $salesOrder->shipping_country,
                'shipping_po_box' => $salesOrder->shipping_po_box,
                'terms_conditions' => $salesOrder->terms_conditions,
                'invoice_date' => now()->toDateString(),
                'invoice_no' => 'INV-' . Str::upper(Str::random(8)),
                'assigned_to' => $identity['user_id'],
                'sub_institute_id' => $identity['sub_institute_id'],
                'created_by' => $identity['user_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $invoiceId = DB::table('crm_invoices')->insertGetId($invoiceData);

            $sourceLineItems = DB::table('crm_sales_order_line_items')
                ->where('sales_order_id', $salesOrderId)
                ->orderBy('sequence_no')
                ->get();

            $subtotal = 0.0;
            $discountTotal = 0.0;
            $taxTotal = 0.0;
            $rows = [];

            foreach ($sourceLineItems as $index => $source) {
                $quantity = (float) $source->quantity;
                $unitPrice = (float) $source->unit_price;
                $lineGross = $quantity * $unitPrice;

                $discountAmount = $source->discount_percent !== null
                    ? round($lineGross * ((float) $source->discount_percent) / 100, 2)
                    : (float) ($source->discount_amount ?? 0);

                $afterDiscount = max(0, $lineGross - $discountAmount);
                $taxPercent = $source->tax_percent_snapshot !== null ? (float) $source->tax_percent_snapshot : 0.0;
                $lineTax = round($afterDiscount * $taxPercent / 100, 2);
                $lineTotal = round($afterDiscount + $lineTax, 2);

                $subtotal += $lineGross;
                $discountTotal += $discountAmount;
                $taxTotal += $lineTax;

                $rows[] = [
                    'invoice_id' => $invoiceId,
                    'product_id' => $source->product_id,
                    'description' => $source->description,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_percent' => $source->discount_percent,
                    'discount_amount' => $discountAmount,
                    'tax_rate_id' => $source->tax_rate_id,
                    'tax_name_snapshot' => $source->tax_name_snapshot,
                    'tax_percent_snapshot' => $source->tax_percent_snapshot,
                    'line_total' => $lineTotal,
                    'sequence_no' => $index,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows !== []) {
                DB::table('crm_invoice_line_items')->insert($rows);
            }

            DB::table('crm_invoices')->where('id', $invoiceId)->update([
                'subtotal' => round($subtotal, 2),
                'discount_amount' => round($discountTotal, 2),
                'tax_total' => round($taxTotal, 2),
            ]);

            $this->recomputeTotal($invoiceId);

            return $invoiceId;
        });

        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Invoice created from sales order.', 'data' => $this->resource($row)], 201);
    }

    /**
     * Renders the invoice to PDF - same Dompdf + Blade + document_library
     * upsert chain as CrmQuoteController::pdf(), only the view/document_type/
     * file-name prefix differ. See that method's own docblock for why
     * $dompdf->output() is captured once into $pdfContent and never streamed
     * a second time.
     */
    public function pdf(Request $request, int $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $invoice = $this->ownRowJoined($identity['sub_institute_id'], $id);

        if (! $invoice) {
            return response()->json(['status' => 0, 'message' => 'Invoice not found.'], 404);
        }

        $lineItems = DB::table('crm_invoice_line_items')->where('invoice_id', $id)->orderBy('sequence_no')->get();
        $schoolName = DB::table('school_setup')->where('id', $identity['sub_institute_id'])->value('ReceiptHeader');
        $assignedToName = $invoice->assigned_to
            ? DB::table('tbluser')->where('id', $invoice->assigned_to)->selectRaw("TRIM(CONCAT(first_name, ' ', last_name)) as name")->value('name')
            : null;

        $html = view('crm.invoices.pdf', [
            'invoice' => $invoice,
            'lineItems' => $lineItems,
            'schoolName' => $schoolName,
            'assignedToName' => $assignedToName,
            'balanceDue' => round((float) $invoice->total - (float) $invoice->amount_paid, 2),
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdfContent = $dompdf->output();

        $fileName = 'invoice_' . $invoice->invoice_no . '.pdf';
        $stored = (new DocumentStorageService())->storeGenerated($pdfContent, $fileName, 'application/pdf', $id);

        $existing = DB::table('document_library')
            ->where([
                'sub_institute_id' => $identity['sub_institute_id'],
                'document_type' => 'crm_invoice',
                'owner_id' => $id,
            ])
            ->whereNull('deleted_at')
            ->first(['id']);

        $docData = [
            'sub_institute_id' => $identity['sub_institute_id'],
            'owner_id' => $id,
            'title' => 'Invoice ' . $invoice->invoice_no,
            'original_file_name' => $fileName,
            'mime_type' => 'application/pdf',
            'size' => $stored['size'],
            'checksum_sha256' => $stored['checksum_sha256'],
            'storage_path' => $stored['storage_path'],
            'category' => 'organization',
            'document_type' => 'crm_invoice',
            'document_date' => now()->toDateString(),
            'visibility' => 'private',
            'processing_status' => 'done',
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('document_library')->where('id', $existing->id)->update($docData);
        } else {
            $docData['current_version'] = 1;
            $docData['created_at'] = now();
            $docData['created_by'] = $identity['user_id'];
            DB::table('document_library')->insert($docData);
        }

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $fileName . '"',
            'Content-Length' => (string) strlen($pdfContent),
        ]);
    }

    public function getPayments(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Invoice not found.'], 404);
        }

        $rows = DB::table('crm_invoice_payments as p')
            ->leftJoin('tbluser as u', 'u.id', '=', 'p.created_by')
            ->where('p.invoice_id', $id)
            ->orderByDesc('p.created_at')
            ->select('p.*', DB::raw("TRIM(CONCAT(u.first_name, ' ', u.last_name)) as created_by_name"))
            ->get();

        return response()->json([
            'status' => 1,
            'message' => 'Payments.',
            'data' => $rows->map(fn ($row) => [
                'id' => (string) $row->id,
                'amount' => (float) $row->amount,
                'method' => $row->method,
                'reference' => $row->reference,
                'paidOn' => $row->paid_on,
                'note' => $row->note,
                'createdBy' => $row->created_by ? (string) $row->created_by : null,
                'createdByName' => ($row->created_by_name ?? '') !== '' ? $row->created_by_name : null,
                'createdAt' => $row->created_at,
            ])->all(),
        ]);
    }

    /**
     * Records one payment against an invoice (manual ledger entry - no
     * payment-gateway integration, see this effort's gap-closure scope
     * tiering in DECISIONS.md for why). Append-only: a correction is a new
     * row, never an edit of a past one, same reasoning crm_invoice_status_history
     * is append-only. `amount_paid` on the invoice header is then
     * recomputed as SUM(crm_invoice_payments.amount) - never incremented
     * directly - so it can never drift from its own ledger.
     */
    public function recordPayment(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $invoice = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $invoice) {
            return response()->json(['status' => 0, 'message' => 'Invoice not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'method' => 'nullable|string|max:50',
            'reference' => 'nullable|string|max:191',
            'paidOn' => 'nullable|date',
            'note' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        DB::transaction(function () use ($request, $id, $identity) {
            DB::table('crm_invoice_payments')->insert([
                'invoice_id' => $id,
                'amount' => (float) $request->input('amount'),
                'method' => $request->input('method'),
                'reference' => $request->input('reference'),
                'paid_on' => $request->input('paidOn') ?: now()->toDateString(),
                'note' => $request->input('note'),
                'created_by' => $identity['user_id'],
                'created_at' => now(),
            ]);

            $this->recomputePayments($id);
        });

        $this->recordCrmAudit('crm.invoice.payment_recorded', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'], [], ['amount' => $request->input('amount')]);

        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Payment recorded.', 'data' => $this->resource($row)], 201);
    }

    /** Exports the same rows index() would list (search applied). */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $query = DB::table('crm_invoices')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where('subject', 'like', "%{$search}%");
        }

        return $this->exportCsv($query, self::EXPORT_COLUMNS, 'invoices.csv');
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
                'organizationId' => 'nullable|integer',
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
            $data['invoice_no'] = 'INV-' . Str::upper(Str::random(8));
            $data['sub_institute_id'] = $identity['sub_institute_id'];
            $data['created_by'] = $identity['user_id'];
            $data['created_at'] = now();
            $data['updated_at'] = now();

            DB::table('crm_invoices')->insert($data);
            $created++;
            $results[] = ['row' => $index + 1, 'ok' => true];
        }

        return response()->json([
            'status' => 1,
            'message' => $created === count($results) ? "{$created} invoice(s) imported." : "{$created} of " . count($results) . ' row(s) imported.',
            'data' => ['created' => $created, 'results' => $results],
        ]);
    }

    /**
     * The one place `total` is ever computed, so store()/update()/
     * saveLineItems()/fromSalesOrder() can never drift apart on the formula.
     * Reads subtotal/discount_amount/tax_total fresh from the row - those
     * three only ever change inside saveLineItems()/fromSalesOrder() - plus
     * whatever shipping_handling_amount/adjustment the row holds right now.
     */
    private function recomputeTotal(int $id): void
    {
        $row = DB::table('crm_invoices')->where('id', $id)
            ->first(['subtotal', 'discount_amount', 'tax_total', 'shipping_handling_amount', 'adjustment']);

        if (! $row) {
            return;
        }

        $total = round(
            (float) $row->subtotal - (float) $row->discount_amount + (float) $row->tax_total
                + (float) $row->shipping_handling_amount + (float) $row->adjustment,
            2
        );

        DB::table('crm_invoices')->where('id', $id)->update(['total' => $total]);
    }

    /** The one place `amount_paid` is ever written - always the SUM of the ledger, never an increment. */
    private function recomputePayments(int $id): void
    {
        $paid = (float) DB::table('crm_invoice_payments')->where('invoice_id', $id)->sum('amount');

        DB::table('crm_invoices')->where('id', $id)->update(['amount_paid' => round($paid, 2)]);
    }

    protected function emailsLinkedType(): string
    {
        return 'INVOICE';
    }

    private function writeStatusHistory(int $invoiceId, ?string $fromStage, ?string $toStage, ?float $total, int $userId): void
    {
        DB::table('crm_invoice_status_history')->insert([
            'invoice_id' => $invoiceId,
            'from_stage' => $fromStage,
            'to_stage' => $toStage,
            'total' => $total,
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
        return DB::table('crm_invoices as i')
            ->leftJoin('crm_sales_orders as so', 'so.id', '=', 'i.sales_order_id')
            ->leftJoin('crm_organizations as o', 'o.id', '=', 'i.organization_id')
            ->leftJoin('crm_contacts as c', 'c.id', '=', 'i.contact_id')
            ->leftJoin('tbluser as u', 'u.id', '=', 'i.assigned_to')
            ->where('i.sub_institute_id', $tenantId)
            ->whereNull('i.deleted_at')
            ->select(
                'i.*',
                'so.so_no as sales_order_no',
                'o.name as organization_name',
                DB::raw("TRIM(CONCAT(c.first_name, ' ', c.last_name)) as contact_name"),
                DB::raw("TRIM(CONCAT(u.first_name, ' ', u.last_name)) as assigned_to_name"),
            );
    }

    private function ownRow(int $tenantId, int $id): ?object
    {
        return DB::table('crm_invoices')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    private function ownRowJoined(int $tenantId, int $id): ?object
    {
        return $this->baseQuery($tenantId)->where('i.id', $id)->first();
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, bool $forUpdate = false): array
    {
        $map = [
            'subject' => 'subject', 'organizationId' => 'organization_id', 'contactId' => 'contact_id',
            'salesOrderId' => 'sales_order_id', 'invoiceStage' => 'invoice_stage', 'invoiceDate' => 'invoice_date',
            'dueDate' => 'due_date', 'currency' => 'currency',
            'shippingHandlingAmount' => 'shipping_handling_amount', 'adjustment' => 'adjustment',
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

        foreach (['organization_id', 'contact_id', 'sales_order_id'] as $nullableIdColumn) {
            if (array_key_exists($nullableIdColumn, $data) && $data[$nullableIdColumn] === '') {
                $data[$nullableIdColumn] = null;
            }
        }

        if (array_key_exists('invoice_stage', $data) && $data['invoice_stage'] === '') {
            $data['invoice_stage'] = null;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function resource(object $row): array
    {
        $total = (float) $row->total;
        $amountPaid = (float) $row->amount_paid;
        // Never stored - derived the same way Opportunities' "weighted revenue" is: computed
        // here from amount_paid (the one real stored fact, maintained by recomputePayments()),
        // not re-derived from the client, so it can never drift or be spoofed.
        $balanceDue = round($total - $amountPaid, 2);
        $paymentStatus = match (true) {
            $total <= 0.0 || $amountPaid <= 0.0 => 'Unpaid',
            $balanceDue <= 0.004 => 'Paid',
            default => 'Partially Paid',
        };
        $isOverdue = $balanceDue > 0.004 && $row->due_date !== null && $row->due_date < now()->toDateString();

        return [
            'id' => (string) $row->id,
            'legacyId' => $row->legacy_id ? (string) $row->legacy_id : null,
            'invoiceNo' => $row->invoice_no,
            'subject' => $row->subject,
            'salesOrderId' => $row->sales_order_id ? (string) $row->sales_order_id : null,
            'salesOrderNo' => $row->sales_order_no ?? null,
            'organizationId' => $row->organization_id ? (string) $row->organization_id : null,
            'organizationName' => $row->organization_name ?? null,
            'contactId' => $row->contact_id ? (string) $row->contact_id : null,
            'contactName' => ($row->contact_name ?? '') !== '' ? $row->contact_name : null,
            'invoiceStage' => $row->invoice_stage,
            'invoiceDate' => $row->invoice_date,
            'dueDate' => $row->due_date,
            'currency' => $row->currency,
            'subtotal' => (float) $row->subtotal,
            'discountPercent' => $row->discount_percent !== null ? (float) $row->discount_percent : null,
            'discountAmount' => (float) $row->discount_amount,
            'shippingHandlingAmount' => (float) $row->shipping_handling_amount,
            'adjustment' => (float) $row->adjustment,
            'taxTotal' => (float) $row->tax_total,
            'total' => $total,
            'amountPaid' => $amountPaid,
            'balanceDue' => $balanceDue,
            'paymentStatus' => $paymentStatus,
            'isOverdue' => $isOverdue,
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
