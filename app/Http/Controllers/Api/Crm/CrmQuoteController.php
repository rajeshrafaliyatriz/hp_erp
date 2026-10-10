<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmBulkActions;
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
 * Quotes - the legacy CRM's "Quotes" module, the one entity in this whole
 * phase with a real multi-line-item builder (confirmed from reading
 * `Quotes.php`'s own `$tab_name` array, which lists
 * `vtiger_inventoryproductrel` as one of its own persisted tables).
 *
 * Header-only CRUD here (name/stage/addresses/terms) - line items are their
 * own small surface (getLineItems/saveLineItems below), managed on the
 * quote's own detail page after creation, same convention Campaign targets
 * already use (create the parent first, manage its children on its own
 * page). No duplicate-detection/merge pair, same reasoning as
 * Opportunities/Campaigns.
 *
 * Every money total is SERVER-RECOMPUTED on save, never trusted from the
 * client - see saveLineItems()'s own docblock for the exact arithmetic and
 * why tax is a frozen per-line snapshot, not just a live FK.
 */
class CrmQuoteController extends Controller
{
    use ResolvesApiIdentity;
    use HasCrmBulkActions;
    use HasCrmExport;

    /** @var array<string, string> db column => CSV header, in export column order. */
    private const EXPORT_COLUMNS = [
        'subject' => 'Subject', 'quote_stage' => 'Stage', 'valid_till' => 'Valid Till',
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
            $query->where('q.subject', 'like', "%{$search}%");
        }

        if ($quoteStage = $request->input('quote_stage')) {
            $query->where('q.quote_stage', $quoteStage);
        }

        if ($organizationId = $request->input('organization_id')) {
            $query->where('q.organization_id', $organizationId);
        }

        if ($opportunityId = $request->input('opportunity_id')) {
            $query->where('q.opportunity_id', $opportunityId);
        }

        $sortableColumns = ['subject', 'total', 'quote_stage', 'valid_till', 'created_at'];
        $sortBy = in_array($request->input('sort_by'), $sortableColumns, true) ? $request->input('sort_by') : 'created_at';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'desc' ? 'desc' : 'asc';

        $total = (clone $query)->count();
        $rows = $query->orderBy('q.' . $sortBy, $sortDir)->forPage($page, $perPage)->get();

        return response()->json([
            'status' => 1,
            'message' => 'Quotes.',
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
            return response()->json(['status' => 0, 'message' => 'Quote not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Quote.', 'data' => $this->resource($row)]);
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
            'opportunityId' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        foreach ([
            ['organizationId', 'crm_organizations', 'Organization'],
            ['contactId', 'crm_contacts', 'Contact'],
            ['opportunityId', 'crm_opportunities', 'Opportunity'],
        ] as [$field, $table, $label]) {
            if (($rejection = $this->rejectUnknownReference($request, $identity['sub_institute_id'], $field, $table, $label)) !== null) {
                return $rejection;
            }
        }

        $data = $this->payload($request);
        /*
         * shipping_handling_amount/adjustment are NOT NULL with a DB-level
         * default of 0 - but that default only applies when a column is
         * OMITTED from the insert entirely, not when NULL is passed for it
         * explicitly. payload() always includes both keys on store() (unlike
         * forUpdate, which skips absent keys), so an omitted client field
         * becomes an explicit `null` here and the insert fails with
         * "Column 'adjustment' cannot be null" - found live, clicking
         * Create Quote with neither field touched.
         */
        $data['shipping_handling_amount'] = $data['shipping_handling_amount'] ?? 0;
        $data['adjustment'] = $data['adjustment'] ?? 0;
        $data['quote_no'] = 'QT-' . Str::upper(Str::random(8));
        $data['sub_institute_id'] = $identity['sub_institute_id'];
        $data['created_by'] = $identity['user_id'];
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $id = DB::table('crm_quotes')->insertGetId($data);
        $this->recomputeTotal($id);
        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Quote created.', 'data' => $this->resource($row)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Quote not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'subject' => 'sometimes|required|string|max:191',
            'assignedTo' => 'sometimes|required|integer',
            'organizationId' => 'nullable|integer',
            'contactId' => 'nullable|integer',
            'opportunityId' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        foreach ([
            ['organizationId', 'crm_organizations', 'Organization'],
            ['contactId', 'crm_contacts', 'Contact'],
            ['opportunityId', 'crm_opportunities', 'Opportunity'],
        ] as [$field, $table, $label]) {
            if (($rejection = $this->rejectUnknownReference($request, $identity['sub_institute_id'], $field, $table, $label)) !== null) {
                return $rejection;
            }
        }

        $data = $this->payload($request, forUpdate: true);
        $data['updated_by'] = $identity['user_id'];
        $data['updated_at'] = now();

        DB::table('crm_quotes')->where('id', $id)->update($data);

        /*
         * A header-only save can still touch shippingHandlingAmount/
         * adjustment - both feed the same total formula saveLineItems()
         * uses. Without this, editing shipping/adjustment on a quote that
         * already has line items would leave `total` stale until someone
         * happens to re-save the line-item table too.
         */
        $this->recomputeTotal($id);
        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Quote updated.', 'data' => $this->resource($row)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Quote not found.'], 404);
        }

        DB::table('crm_quotes')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $identity['user_id'],
        ]);

        return response()->json(['status' => 1, 'message' => 'Quote moved to Recycle Bin.']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkDeleteRows($request, 'crm_quotes', $identity['sub_institute_id'], $identity['user_id'], 'Quote');
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkAssignRows($request, 'crm_quotes', $identity['sub_institute_id'], $identity['user_id'], 'Quote');
    }

    public function getLineItems(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Quote not found.'], 404);
        }

        $rows = DB::table('crm_quote_line_items')->where('quote_id', $id)->orderBy('sequence_no')->get();

        return response()->json(['status' => 1, 'message' => 'Line items.', 'data' => $rows->map(fn ($row) => $this->lineItemResource($row))->all()]);
    }

    /**
     * Replaces the WHOLE line-item set and recomputes every total on the
     * quote header - there is no per-line create/update/delete endpoint,
     * matching how the frontend sends the entire edited table on every
     * save. The client's own running total is a preview only; this is the
     * one place a number is ever trusted into storage.
     *
     * Per line: lineSubtotal = quantity x unitPrice. discountPercent (if
     * set) computes discountAmount, overriding any flat discountAmount the
     * client also sent - a line should not express both at once ambiguously.
     * taxRateId resolves to a REAL rate at save time, frozen into
     * tax_name_snapshot/tax_percent_snapshot - editing crm_tax_rates later
     * must never change an already-saved quote's total the next time it is
     * viewed or PDF'd, so the snapshot, not a live join, is what every
     * later read uses.
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

        $quote = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $quote) {
            return response()->json(['status' => 0, 'message' => 'Quote not found.'], 404);
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
                'quote_id' => $id,
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
            DB::table('crm_quote_line_items')->where('quote_id', $id)->delete();

            if ($rows !== []) {
                DB::table('crm_quote_line_items')->insert($rows);
            }

            DB::table('crm_quotes')->where('id', $id)->update([
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
     * Renders the quote to PDF (Dompdf + a Blade view, same chain as
     * `PayrollController::monthlyPayrollPdf()`), persists it into the
     * generic `document_library` table via `DocumentStorageService`, and
     * streams it back. Keyed by (sub_institute_id, document_type='crm_quote',
     * owner_id=quote id) so re-downloading after an edit REPLACES the stored
     * copy rather than piling up a new row every time - same convention
     * `monthlyPayrollStore`'s own payslip-PDF upsert already uses.
     */
    public function pdf(Request $request, int $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $quote = $this->ownRowJoined($identity['sub_institute_id'], $id);

        if (! $quote) {
            return response()->json(['status' => 0, 'message' => 'Quote not found.'], 404);
        }

        $lineItems = DB::table('crm_quote_line_items')->where('quote_id', $id)->orderBy('sequence_no')->get();
        $schoolName = DB::table('school_setup')->where('id', $identity['sub_institute_id'])->value('ReceiptHeader');
        $assignedToName = $quote->assigned_to
            ? DB::table('tbluser')->where('id', $quote->assigned_to)->selectRaw("TRIM(CONCAT(first_name, ' ', last_name)) as name")->value('name')
            : null;

        $html = view('crm.quotes.pdf', [
            'quote' => $quote,
            'lineItems' => $lineItems,
            'schoolName' => $schoolName,
            'assignedToName' => $assignedToName,
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdfContent = $dompdf->output();

        $fileName = 'quote_' . $quote->quote_no . '.pdf';
        $stored = (new DocumentStorageService())->storeGenerated($pdfContent, $fileName, 'application/pdf', $id);

        $existing = DB::table('document_library')
            ->where([
                'sub_institute_id' => $identity['sub_institute_id'],
                'document_type' => 'crm_quote',
                'owner_id' => $id,
            ])
            ->whereNull('deleted_at')
            ->first(['id']);

        $docData = [
            'sub_institute_id' => $identity['sub_institute_id'],
            'owner_id' => $id,
            'title' => 'Quote ' . $quote->quote_no,
            'original_file_name' => $fileName,
            'mime_type' => 'application/pdf',
            'size' => $stored['size'],
            'checksum_sha256' => $stored['checksum_sha256'],
            'storage_path' => $stored['storage_path'],
            'category' => 'organization',
            'document_type' => 'crm_quote',
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

        /*
         * NOT $dompdf->stream() here - that calls the canvas's own output()
         * a second time, and Cpdf::output() is not safe to call twice on one
         * instance (confirmed live: the two calls produced different byte
         * lengths, 45821 vs 26367, on an identical render - it mutates
         * internal object/compression state rather than re-serializing
         * idempotently). $pdfContent above is the ONE render this request
         * does; the download must be exactly those bytes, the same ones
         * just written to storage, not a second, different serialization.
         */
        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $fileName . '"',
            'Content-Length' => (string) strlen($pdfContent),
        ]);
    }

    /** Exports the same rows index() would list (search applied). */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $query = DB::table('crm_quotes')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where('subject', 'like', "%{$search}%");
        }

        return $this->exportCsv($query, self::EXPORT_COLUMNS, 'quotes.csv');
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
            $data['quote_no'] = 'QT-' . Str::upper(Str::random(8));
            $data['sub_institute_id'] = $identity['sub_institute_id'];
            $data['created_by'] = $identity['user_id'];
            $data['created_at'] = now();
            $data['updated_at'] = now();

            DB::table('crm_quotes')->insert($data);
            $created++;
            $results[] = ['row' => $index + 1, 'ok' => true];
        }

        return response()->json([
            'status' => 1,
            'message' => $created === count($results) ? "{$created} quote(s) imported." : "{$created} of " . count($results) . ' row(s) imported.',
            'data' => ['created' => $created, 'results' => $results],
        ]);
    }

    /**
     * The one place `total` is ever computed, so store()/update()/
     * saveLineItems() can never drift apart on the formula. Reads
     * subtotal/discount_amount/tax_total fresh from the row - those three
     * only ever change inside saveLineItems() - plus whatever
     * shipping_handling_amount/adjustment the row holds right now, so a
     * header-only edit to either of those two still lands in the total
     * without needing the line items re-saved too.
     */
    private function recomputeTotal(int $id): void
    {
        $row = DB::table('crm_quotes')->where('id', $id)
            ->first(['subtotal', 'discount_amount', 'tax_total', 'shipping_handling_amount', 'adjustment']);

        if (! $row) {
            return;
        }

        $total = round(
            (float) $row->subtotal - (float) $row->discount_amount + (float) $row->tax_total
                + (float) $row->shipping_handling_amount + (float) $row->adjustment,
            2
        );

        DB::table('crm_quotes')->where('id', $id)->update(['total' => $total]);
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
        return DB::table('crm_quotes as q')
            ->leftJoin('crm_organizations as o', 'o.id', '=', 'q.organization_id')
            ->leftJoin('crm_contacts as c', 'c.id', '=', 'q.contact_id')
            ->leftJoin('crm_opportunities as op', 'op.id', '=', 'q.opportunity_id')
            ->where('q.sub_institute_id', $tenantId)
            ->whereNull('q.deleted_at')
            ->select('q.*', 'o.name as organization_name', DB::raw("TRIM(CONCAT(c.first_name, ' ', c.last_name)) as contact_name"), 'op.name as opportunity_name');
    }

    private function ownRow(int $tenantId, int $id): ?object
    {
        return DB::table('crm_quotes')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    private function ownRowJoined(int $tenantId, int $id): ?object
    {
        return $this->baseQuery($tenantId)->where('q.id', $id)->first();
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, bool $forUpdate = false): array
    {
        $map = [
            'subject' => 'subject', 'organizationId' => 'organization_id', 'contactId' => 'contact_id',
            'opportunityId' => 'opportunity_id', 'quoteStage' => 'quote_stage', 'validTill' => 'valid_till',
            'currency' => 'currency', 'shippingHandlingAmount' => 'shipping_handling_amount', 'adjustment' => 'adjustment',
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

        foreach (['organization_id', 'contact_id', 'opportunity_id'] as $nullableIdColumn) {
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
            'quoteNo' => $row->quote_no,
            'subject' => $row->subject,
            'organizationId' => $row->organization_id ? (string) $row->organization_id : null,
            'organizationName' => $row->organization_name ?? null,
            'contactId' => $row->contact_id ? (string) $row->contact_id : null,
            'contactName' => ($row->contact_name ?? '') !== '' ? $row->contact_name : null,
            'opportunityId' => $row->opportunity_id ? (string) $row->opportunity_id : null,
            'opportunityName' => $row->opportunity_name ?? null,
            'quoteStage' => $row->quote_stage,
            'validTill' => $row->valid_till,
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
