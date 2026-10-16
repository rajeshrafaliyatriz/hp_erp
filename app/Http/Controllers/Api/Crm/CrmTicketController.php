<?php

namespace App\Http\Controllers\Api\Crm;

use App\Domain\Crm\Support\TicketSlaCalculator;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmAuditTrail;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmBulkActions;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmDocuments;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmEmailLinks;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tickets - the legacy CRM's "HelpDesk" module, relabeled. The centerpiece of
 * the Support phase: the only entity here with real cross-entity business
 * logic (see applyContractConsumption()/adjustContractUsedUnits() below).
 *
 * No duplicate-detection/merge pair - a Support ticket is a unique incident,
 * never a "same record entered twice" the way a Lead/Contact/Organization/
 * Product is.
 *
 * service_contract_id/asset_id are read/written as plain foreign keys here,
 * validated with rejectUnknownReference() the same way every other CRM
 * controller validates its own references - this controller never imports or
 * depends on CrmServiceContractController/CrmAssetController as classes
 * (those are sibling controllers built in parallel, each its own isolated
 * file); crm_service_contracts is read/written directly via DB::table() in
 * adjustContractUsedUnits() below for exactly that reason.
 */
class CrmTicketController extends Controller
{
    use ResolvesApiIdentity;
    use HasCrmBulkActions;
    use HasCrmExport;
    use HasCrmDocuments;
    use HasCrmEmailLinks;
    use HasCrmAuditTrail;

    /** @var array<string, string> db column => CSV header, in export column order. */
    private const EXPORT_COLUMNS = [
        'title' => 'Title', 'status' => 'Status', 'priority' => 'Priority', 'severity' => 'Severity',
        'category' => 'Category', 'hours' => 'Hours', 'days' => 'Days',
        'description' => 'Description', 'solution' => 'Solution', 'assigned_to' => 'Assigned To (user id)',
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
            $query->where('t.title', 'like', "%{$search}%");
        }

        if ($status = $request->input('status')) {
            $query->where('t.status', $status);
        }

        if ($priority = $request->input('priority')) {
            $query->where('t.priority', $priority);
        }

        if ($severity = $request->input('severity')) {
            $query->where('t.severity', $severity);
        }

        if ($category = $request->input('category')) {
            $query->where('t.category', $category);
        }

        if ($organizationId = $request->input('organization_id')) {
            $query->where('t.organization_id', $organizationId);
        }

        if ($contactId = $request->input('contact_id')) {
            $query->where('t.contact_id', $contactId);
        }

        if ($assetId = $request->input('asset_id')) {
            $query->where('t.asset_id', $assetId);
        }

        if ($serviceContractId = $request->input('service_contract_id')) {
            $query->where('t.service_contract_id', $serviceContractId);
        }

        if ($assignedTo = $request->input('assigned_to')) {
            $query->where('t.assigned_to', $assignedTo);
        }

        $sortableColumns = ['title', 'status', 'priority', 'created_at'];
        $sortBy = in_array($request->input('sort_by'), $sortableColumns, true) ? $request->input('sort_by') : 'created_at';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'desc' ? 'desc' : 'asc';

        $total = (clone $query)->count();
        $rows = $query->orderBy('t.' . $sortBy, $sortDir)->forPage($page, $perPage)->get();
        $policies = TicketSlaCalculator::policiesFor($identity['sub_institute_id']);

        return response()->json([
            'status' => 1,
            'message' => 'Tickets.',
            'data' => [
                'items' => $rows->map(fn ($row) => $this->resource($row, $policies))->all(),
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
            return response()->json(['status' => 0, 'message' => 'Ticket not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Ticket.', 'data' => $this->resource($row, TicketSlaCalculator::policiesFor($identity['sub_institute_id']))]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:191',
            'organizationId' => 'nullable|integer',
            'contactId' => 'nullable|integer',
            'productId' => 'nullable|integer',
            'assetId' => 'nullable|integer',
            'serviceContractId' => 'nullable|integer',
            'status' => 'nullable|string|max:50',
            'priority' => 'nullable|string|max:50',
            'severity' => 'nullable|string|max:50',
            'category' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'solution' => 'nullable|string',
            'hours' => 'nullable|numeric|min:0',
            'days' => 'nullable|numeric|min:0',
            'assignedTo' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        foreach ([
            ['organizationId', 'crm_organizations', 'Organization'],
            ['contactId', 'crm_contacts', 'Contact'],
            ['productId', 'crm_products', 'Product'],
            ['assetId', 'crm_assets', 'Asset'],
            ['serviceContractId', 'crm_service_contracts', 'Service contract'],
        ] as [$field, $table, $label]) {
            if (($rejection = $this->rejectUnknownReference($request, $identity['sub_institute_id'], $field, $table, $label)) !== null) {
                return $rejection;
            }
        }

        $data = $this->payload($request);
        $data['status'] = $data['status'] !== null && $data['status'] !== '' ? $data['status'] : 'Open';
        $data['ticket_no'] = 'TT-' . Str::upper(Str::random(8));
        $data['sub_institute_id'] = $identity['sub_institute_id'];
        $data['created_by'] = $identity['user_id'];
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $id = DB::transaction(function () use ($data) {
            $id = DB::table('crm_tickets')->insertGetId($data);

            // $oldTicket is null here - a ticket created already Closed has no
            // "old state" to diff against, same reasoning applyContractConsumption()'s
            // own docblock gives for every other call site.
            $this->applyContractConsumption(null, $data);

            return $id;
        });

        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Ticket created.', 'data' => $this->resource($row, TicketSlaCalculator::policiesFor($identity['sub_institute_id']))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        // Fetched BEFORE any change is applied - applyContractConsumption()
        // needs the ticket's state as it stood before this request, not
        // after, to correctly diff old-vs-new contract consumption.
        $oldTicket = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $oldTicket) {
            return response()->json(['status' => 0, 'message' => 'Ticket not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:191',
            'organizationId' => 'nullable|integer',
            'contactId' => 'nullable|integer',
            'productId' => 'nullable|integer',
            'assetId' => 'nullable|integer',
            'serviceContractId' => 'nullable|integer',
            'status' => 'nullable|string|max:50',
            'priority' => 'nullable|string|max:50',
            'severity' => 'nullable|string|max:50',
            'category' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'solution' => 'nullable|string',
            'hours' => 'nullable|numeric|min:0',
            'days' => 'nullable|numeric|min:0',
            'assignedTo' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        foreach ([
            ['organizationId', 'crm_organizations', 'Organization'],
            ['contactId', 'crm_contacts', 'Contact'],
            ['productId', 'crm_products', 'Product'],
            ['assetId', 'crm_assets', 'Asset'],
            ['serviceContractId', 'crm_service_contracts', 'Service contract'],
        ] as [$field, $table, $label]) {
            if (($rejection = $this->rejectUnknownReference($request, $identity['sub_institute_id'], $field, $table, $label)) !== null) {
                return $rejection;
            }
        }

        $data = $this->payload($request, forUpdate: true);
        $data['updated_by'] = $identity['user_id'];
        $data['updated_at'] = now();

        // The FULL new state of the 4 fields applyContractConsumption() cares
        // about - not just whatever this request happened to touch. A plain
        // PUT that only changes `priority`, say, must still see the ticket's
        // own already-Closed status/contract/hours/days as they stand, or
        // this would wrongly read them as reset to null.
        $merged = [
            'service_contract_id' => array_key_exists('service_contract_id', $data) ? $data['service_contract_id'] : $oldTicket->service_contract_id,
            'status' => array_key_exists('status', $data) ? $data['status'] : $oldTicket->status,
            'hours' => array_key_exists('hours', $data) ? $data['hours'] : $oldTicket->hours,
            'days' => array_key_exists('days', $data) ? $data['days'] : $oldTicket->days,
        ];

        DB::transaction(function () use ($id, $data, $oldTicket, $merged, $identity) {
            $this->maybeWriteStatusHistory($id, $oldTicket->status, $merged['status'], $identity['user_id']);

            DB::table('crm_tickets')->where('id', $id)->update($data);

            $this->applyContractConsumption($oldTicket, $merged);
        });

        // Deliberately outside the transaction above (matches this trait's own
        // post-commit convention) - $oldTicket, not $existing, since that is
        // the name this controller's own update() already captures the
        // pre-write row under.
        $this->recordCrmAudit('crm.ticket.updated', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'], (array) $oldTicket, array_merge((array) $oldTicket, $data));

        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Ticket updated.', 'data' => $this->resource($row, TicketSlaCalculator::policiesFor($identity['sub_institute_id']))]);
    }

    /** Dedicated status-change endpoint (e.g. a list/detail-page status dropdown) - same history-writing + contract-consumption rule as update(), just a narrower, purpose-built surface. */
    public function changeStatus(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $oldTicket = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $oldTicket) {
            return response()->json(['status' => 0, 'message' => 'Ticket not found.'], 404);
        }

        $validator = Validator::make($request->all(), ['status' => 'required|string|max:50']);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $newStatus = $request->input('status');

        // service_contract_id/hours/days are untouched by this endpoint - only
        // `status` itself changes - so the merged "new state" reuses the
        // ticket's own current values for the other 3 fields.
        $merged = [
            'service_contract_id' => $oldTicket->service_contract_id,
            'status' => $newStatus,
            'hours' => $oldTicket->hours,
            'days' => $oldTicket->days,
        ];

        DB::transaction(function () use ($id, $oldTicket, $newStatus, $merged, $identity) {
            $this->maybeWriteStatusHistory($id, $oldTicket->status, $newStatus, $identity['user_id']);

            DB::table('crm_tickets')->where('id', $id)->update([
                'status' => $newStatus,
                'updated_by' => $identity['user_id'],
                'updated_at' => now(),
            ]);

            $this->applyContractConsumption($oldTicket, $merged);
        });

        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Status updated.', 'data' => $this->resource($row, TicketSlaCalculator::policiesFor($identity['sub_institute_id']))]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Ticket not found.'], 404);
        }

        DB::table('crm_tickets')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $identity['user_id'],
        ]);

        $this->recordCrmAudit(
            'crm.ticket.deleted', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'],
            (array) $existing,
            array_merge((array) $existing, ['deleted_at' => now()->toDateTimeString(), 'deleted_by' => $identity['user_id']]),
        );

        return response()->json(['status' => 1, 'message' => 'Ticket moved to Recycle Bin.']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkDeleteRows($request, 'crm_tickets', $identity['sub_institute_id'], $identity['user_id'], 'Ticket');
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkAssignRows($request, 'crm_tickets', $identity['sub_institute_id'], $identity['user_id'], 'Ticket');
    }

    // ── Comments sub-resource (flat, append-only - no soft-deletes) ────────

    public function getComments(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Ticket not found.'], 404);
        }

        $rows = DB::table('crm_ticket_comments as tc')
            ->leftJoin('tbluser as u', 'u.id', '=', 'tc.created_by')
            ->where('tc.ticket_id', $id)
            ->orderBy('tc.created_at')
            ->select('tc.*', DB::raw("TRIM(CONCAT(u.first_name, ' ', u.last_name)) as created_by_name"))
            ->get();

        return response()->json([
            'status' => 1,
            'message' => 'Ticket comments.',
            'data' => $rows->map(fn ($row) => $this->commentResource($row))->all(),
        ]);
    }

    public function addComment(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $ticket = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $ticket) {
            return response()->json(['status' => 0, 'message' => 'Ticket not found.'], 404);
        }

        $validator = Validator::make($request->all(), ['comment' => 'required|string']);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $commentId = DB::table('crm_ticket_comments')->insertGetId([
            'ticket_id' => $id,
            'sub_institute_id' => $identity['sub_institute_id'],
            'comment' => $request->input('comment'),
            'created_by' => $identity['user_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The SLA "first response" - set once, on this ticket's very first comment, and
        // never overwritten by any comment after it (there is no public ticket-submission
        // portal in this product, so every comment is a staff response by construction).
        if ($ticket->first_response_at === null) {
            DB::table('crm_tickets')->where('id', $id)->update(['first_response_at' => now()]);
        }

        $row = DB::table('crm_ticket_comments as tc')
            ->leftJoin('tbluser as u', 'u.id', '=', 'tc.created_by')
            ->where('tc.id', $commentId)
            ->select('tc.*', DB::raw("TRIM(CONCAT(u.first_name, ' ', u.last_name)) as created_by_name"))
            ->first();

        return response()->json(['status' => 1, 'message' => 'Comment added.', 'data' => $this->commentResource($row)], 201);
    }

    // ── Status history sub-resource (append-only - no soft-deletes) ────────

    public function statusHistory(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Ticket not found.'], 404);
        }

        $rows = DB::table('crm_ticket_status_history as h')
            ->leftJoin('tbluser as u', 'u.id', '=', 'h.changed_by')
            ->where('h.ticket_id', $id)
            ->orderByDesc('h.created_at')
            ->select('h.*', DB::raw("TRIM(CONCAT(u.first_name, ' ', u.last_name)) as changed_by_name"))
            ->get();

        return response()->json([
            'status' => 1,
            'message' => 'Ticket status history.',
            'data' => $rows->map(fn ($row) => $this->statusHistoryResource($row))->all(),
        ]);
    }

    /** Exports the same rows index() would list (search applied). */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $query = DB::table('crm_tickets')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where('title', 'like', "%{$search}%");
        }

        return $this->exportCsv($query, self::EXPORT_COLUMNS, 'tickets.csv');
    }

    /** CSV import - header fields only, same as every other CRM import. A ticket created already-Closed via import still runs applyContractConsumption() - see store()'s own docblock for why $oldTicket is null here too. */
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
                'title' => 'required|string|max:191',
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
            $data['status'] = $data['status'] !== null && $data['status'] !== '' ? $data['status'] : 'Open';
            $data['ticket_no'] = 'TT-' . Str::upper(Str::random(8));
            $data['sub_institute_id'] = $identity['sub_institute_id'];
            $data['created_by'] = $identity['user_id'];
            $data['created_at'] = now();
            $data['updated_at'] = now();

            DB::transaction(function () use ($data) {
                DB::table('crm_tickets')->insert($data);
                $this->applyContractConsumption(null, $data);
            });

            $created++;
            $results[] = ['row' => $index + 1, 'ok' => true];
        }

        return response()->json([
            'status' => 1,
            'message' => $created === count($results) ? "{$created} ticket(s) imported." : "{$created} of " . count($results) . ' row(s) imported.',
            'data' => ['created' => $created, 'results' => $results],
        ]);
    }

    /**
     * Writes a crm_ticket_status_history row only when the status genuinely
     * changed - shared by update() and changeStatus() so the two can never
     * drift apart on when a history row is (or isn't) written.
     */
    private function maybeWriteStatusHistory(int $ticketId, ?string $fromStatus, ?string $toStatus, int $userId): void
    {
        if ((string) ($fromStatus ?? '') === (string) ($toStatus ?? '')) {
            return;
        }

        DB::table('crm_ticket_status_history')->insert([
            'ticket_id' => $ticketId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changed_by' => $userId,
            'created_at' => now(),
        ]);
    }

    /**
     * The one place a ticket's Closed/contract/hours/days state is translated
     * into a change to its service contract's used_units bucket - symmetric
     * and diff-based (not transition-triggered), so the exact same call
     * correctly handles a ticket created already Closed ($oldTicket === null,
     * treated as status=null/contract=null/hours=null/days=null), a plain
     * close, a reopen, editing hours/days while staying Closed, and
     * reassigning service_contract_id while staying Closed. Always called
     * from inside a DB::transaction() that also contains the ticket row
     * write itself, so a crash mid-operation can never leave a ticket Closed
     * with its contract never adjusted.
     */
    private function applyContractConsumption(?object $oldTicket, array $newData): void
    {
        $oldContractId = $oldTicket->service_contract_id ?? null;
        $oldStatus = $oldTicket->status ?? null;
        $oldHours = $oldTicket->hours ?? null;
        $oldDays = $oldTicket->days ?? null;

        $newContractId = $newData['service_contract_id'] ?? null;
        $newStatus = $newData['status'] ?? null;
        $newHours = $newData['hours'] ?? null;
        $newDays = $newData['days'] ?? null;

        if ($oldStatus === 'Closed' && $oldContractId) {
            $this->adjustContractUsedUnits((int) $oldContractId, -1, $oldHours, $oldDays);
        }
        if ($newStatus === 'Closed' && $newContractId) {
            $this->adjustContractUsedUnits((int) $newContractId, 1, $newHours, $newDays);
        }
    }

    /**
     * $sign is +1 (consuming - a ticket just became/stayed Closed against
     * this contract) or -1 (releasing - a ticket that used to count against
     * this contract no longer does). Unit interpretation depends on the
     * CONTRACT's own tracking_unit, never the ticket's: Incidents is a flat 1
     * per closed ticket regardless of hours/days; Hours uses the ticket's own
     * `hours`, falling back to `days*24` when hours is null; Days uses the
     * ticket's own `days`, falling back to `hours/24` when days is null.
     */
    private function adjustContractUsedUnits(int $contractId, int $sign, $hours, $days): void
    {
        $contract = DB::table('crm_service_contracts')->where('id', $contractId)->whereNull('deleted_at')->first();
        if (! $contract) {
            return;
        }

        $amount = match ($contract->tracking_unit) {
            'Incidents' => 1,
            'Hours' => $hours !== null ? (float) $hours : ($days !== null ? (float) $days * 24 : 0),
            'Days' => $days !== null ? (float) $days : ($hours !== null ? (float) $hours / 24 : 0),
            default => 0,
        };

        $newUsed = max(0, (float) $contract->used_units + ($sign * $amount));
        $progress = ($contract->total_units !== null && (float) $contract->total_units > 0)
            ? round($newUsed * 100 / (float) $contract->total_units, 2) : 0;

        $updates = ['used_units' => $newUsed, 'progress' => $progress, 'updated_at' => now()];

        if ($contract->total_units !== null && $newUsed >= (float) $contract->total_units && $contract->contract_status !== 'Complete') {
            $updates['contract_status'] = 'Complete';
            $updates['end_date'] = $contract->end_date ?? now()->toDateString();
        }

        DB::table('crm_service_contracts')->where('id', $contractId)->update($updates);
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
        return DB::table('crm_tickets as t')
            ->leftJoin('crm_organizations as o', 'o.id', '=', 't.organization_id')
            ->leftJoin('crm_contacts as c', 'c.id', '=', 't.contact_id')
            ->leftJoin('crm_products as p', 'p.id', '=', 't.product_id')
            ->leftJoin('crm_assets as a', 'a.id', '=', 't.asset_id')
            ->leftJoin('crm_service_contracts as sc', 'sc.id', '=', 't.service_contract_id')
            ->leftJoin('tbluser as u', 'u.id', '=', 't.assigned_to')
            ->where('t.sub_institute_id', $tenantId)
            ->whereNull('t.deleted_at')
            ->select(
                't.*',
                'o.name as organization_name',
                DB::raw("TRIM(CONCAT(c.first_name, ' ', c.last_name)) as contact_name"),
                'p.name as product_name',
                'a.asset_name as asset_name',
                'sc.contract_no as service_contract_no',
                DB::raw("TRIM(CONCAT(u.first_name, ' ', u.last_name)) as assigned_to_name"),
            );
    }

    private function ownRow(int $tenantId, int $id): ?object
    {
        return DB::table('crm_tickets')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    private function ownRowJoined(int $tenantId, int $id): ?object
    {
        return $this->baseQuery($tenantId)->where('t.id', $id)->first();
    }

    protected function documentsLinkedType(): string
    {
        return 'TICKET';
    }

    protected function emailsLinkedType(): string
    {
        return 'TICKET';
    }

    protected function auditEntityType(): string
    {
        return 'crm_tickets';
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, bool $forUpdate = false): array
    {
        $map = [
            'title' => 'title', 'organizationId' => 'organization_id', 'contactId' => 'contact_id',
            'productId' => 'product_id', 'assetId' => 'asset_id', 'serviceContractId' => 'service_contract_id',
            'status' => 'status', 'priority' => 'priority', 'severity' => 'severity', 'category' => 'category',
            'description' => 'description', 'solution' => 'solution', 'hours' => 'hours', 'days' => 'days',
            'assignedTo' => 'assigned_to',
        ];

        $data = [];

        foreach ($map as $requestKey => $column) {
            if ($forUpdate && ! $request->has($requestKey)) {
                continue;
            }

            $data[$column] = $request->input($requestKey);
        }

        foreach (['organization_id', 'contact_id', 'product_id', 'asset_id', 'service_contract_id', 'assigned_to'] as $nullableIdColumn) {
            if (array_key_exists($nullableIdColumn, $data) && $data[$nullableIdColumn] === '') {
                $data[$nullableIdColumn] = null;
            }
        }

        return $data;
    }

    /**
     * @param  array<string, object>  $policies  From TicketSlaCalculator::policiesFor() -
     *                                            fetched once per request by the caller, never refetched per row.
     * @return array<string, mixed>
     */
    private function resource(object $row, array $policies = []): array
    {
        $sla = TicketSlaCalculator::forTicket($row, $policies);

        return [
            'id' => (string) $row->id,
            'legacyId' => $row->legacy_id !== null ? (string) $row->legacy_id : null,
            'ticketNo' => $row->ticket_no,
            'title' => $row->title,
            'organizationId' => $row->organization_id ? (string) $row->organization_id : null,
            'organizationName' => $row->organization_name ?? null,
            'contactId' => $row->contact_id ? (string) $row->contact_id : null,
            'contactName' => ($row->contact_name ?? '') !== '' ? $row->contact_name : null,
            'productId' => $row->product_id ? (string) $row->product_id : null,
            'productName' => $row->product_name ?? null,
            'assetId' => $row->asset_id ? (string) $row->asset_id : null,
            'assetName' => $row->asset_name ?? null,
            'serviceContractId' => $row->service_contract_id ? (string) $row->service_contract_id : null,
            'serviceContractNo' => $row->service_contract_no ?? null,
            'status' => $row->status,
            'priority' => $row->priority,
            'severity' => $row->severity,
            'category' => $row->category,
            'description' => $row->description,
            'solution' => $row->solution,
            'hours' => $row->hours !== null ? (float) $row->hours : null,
            'days' => $row->days !== null ? (float) $row->days : null,
            'firstResponseAt' => $row->first_response_at,
            'slaDueAt' => $sla['dueAt'],
            'slaStatus' => $sla['status'],
            'slaResponseHours' => $sla['responseHours'],
            // Sales & Marketing gap-closure, Wave 6: set once by the hourly
            // crm:tickets-escalate-sla sweep the moment this ticket's SLA
            // breaches with no response - never cleared, never guessed.
            'slaEscalatedAt' => $row->sla_escalated_at ?? null,
            'assignedTo' => $row->assigned_to ? (string) $row->assigned_to : null,
            'assignedToName' => ($row->assigned_to_name ?? '') !== '' ? $row->assigned_to_name : null,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    private function commentResource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'comment' => $row->comment,
            'createdBy' => $row->created_by ? (string) $row->created_by : null,
            'createdByName' => ($row->created_by_name ?? '') !== '' ? $row->created_by_name : null,
            'createdAt' => $row->created_at,
        ];
    }

    /** @return array<string, mixed> */
    private function statusHistoryResource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'fromStatus' => $row->from_status,
            'toStatus' => $row->to_status,
            'changedBy' => $row->changed_by ? (string) $row->changed_by : null,
            'changedByName' => ($row->changed_by_name ?? '') !== '' ? $row->changed_by_name : null,
            'createdAt' => $row->created_at,
        ];
    }
}
