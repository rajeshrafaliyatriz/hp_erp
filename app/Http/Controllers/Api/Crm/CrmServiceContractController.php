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
 * Service Contracts - a support-hours/incidents consumption bucket tracked
 * against a date range. No duplicate-detection/merge pair, same reasoning as
 * Opportunities/Quotes - two contracts for one Organization can be
 * legitimately different (a renewal sitting alongside the contract it
 * renews), not an accidental double-entry.
 *
 * `used_units`/`progress` are SERVER-MAINTAINED ONLY - never accepted from
 * the client here. They are written exclusively by the sibling
 * CrmTicketController when a linked ticket closes/reopens/changes (a
 * cross-agent dependency this controller does not reach into), and by this
 * controller's own manual-complete auto-stamp below. payload()'s own map
 * deliberately has no entry for either column, so there is no key to
 * accidentally trust from the request body.
 */
class CrmServiceContractController extends Controller
{
    use ResolvesApiIdentity;
    use HasCrmAuditTrail;
    use HasCrmBulkActions;
    use HasCrmExport;

    /** @var array<string, string> db column => CSV header, in export column order. */
    private const EXPORT_COLUMNS = [
        'subject' => 'Subject', 'contract_status' => 'Status', 'contract_type' => 'Type', 'priority' => 'Priority',
        'start_date' => 'Start Date', 'end_date' => 'End Date', 'due_date' => 'Due Date',
        'tracking_unit' => 'Tracking Unit', 'total_units' => 'Total Units', 'used_units' => 'Used Units',
        'progress' => 'Progress %', 'assigned_to' => 'Assigned To (user id)',
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
            $query->where('sc.subject', 'like', "%{$search}%");
        }

        if ($contractStatus = $request->input('contract_status')) {
            $query->where('sc.contract_status', $contractStatus);
        }

        if ($organizationId = $request->input('organization_id')) {
            $query->where('sc.organization_id', $organizationId);
        }

        if ($contactId = $request->input('contact_id')) {
            $query->where('sc.contact_id', $contactId);
        }

        $sortableColumns = ['subject', 'contract_status', 'start_date', 'end_date', 'due_date', 'progress', 'created_at'];
        $sortBy = in_array($request->input('sort_by'), $sortableColumns, true) ? $request->input('sort_by') : 'created_at';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'desc' ? 'desc' : 'asc';

        $total = (clone $query)->count();
        $rows = $query->orderBy('sc.' . $sortBy, $sortDir)->forPage($page, $perPage)->get();

        return response()->json([
            'status' => 1,
            'message' => 'Service contracts.',
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
            return response()->json(['status' => 0, 'message' => 'Service contract not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Service contract.', 'data' => $this->resource($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'subject' => 'required|string|max:191',
            'organizationId' => 'nullable|integer',
            'contactId' => 'nullable|integer',
            'assignedTo' => 'nullable|integer',
            'totalUnits' => 'nullable|numeric|min:0',
            'trackingUnit' => 'nullable|string|max:50',
            'contractStatus' => 'nullable|string|max:50',
            'contractType' => 'nullable|string|max:50',
            'priority' => 'nullable|string|max:50',
            'startDate' => 'nullable|date',
            'endDate' => 'nullable|date',
            'dueDate' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        foreach ([
            ['organizationId', 'crm_organizations', 'Organization'],
            ['contactId', 'crm_contacts', 'Contact'],
        ] as [$field, $table, $label]) {
            if (($rejection = $this->rejectUnknownReference($request, $identity['sub_institute_id'], $field, $table, $label)) !== null) {
                return $rejection;
            }
        }

        $data = $this->payload($request);
        $data['contract_no'] = 'SERCON-' . Str::upper(Str::random(8));
        $data['contract_status'] = $data['contract_status'] ?? 'In Planning';
        $data['total_units'] = $data['total_units'] ?? 0;
        // used_units/progress: server-maintained only, always start a new
        // contract at zero - never read from the request (payload()'s map
        // has no entry for either, so there is nothing to override here).
        $data['used_units'] = 0;
        $data['progress'] = 0;
        $data['sub_institute_id'] = $identity['sub_institute_id'];
        $data['created_by'] = $identity['user_id'];
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $id = DB::table('crm_service_contracts')->insertGetId($data);
        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Service contract created.', 'data' => $this->resource($row)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Service contract not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'subject' => 'sometimes|required|string|max:191',
            'organizationId' => 'nullable|integer',
            'contactId' => 'nullable|integer',
            'assignedTo' => 'nullable|integer',
            'totalUnits' => 'nullable|numeric|min:0',
            'trackingUnit' => 'nullable|string|max:50',
            'contractStatus' => 'nullable|string|max:50',
            'contractType' => 'nullable|string|max:50',
            'priority' => 'nullable|string|max:50',
            'startDate' => 'nullable|date',
            'endDate' => 'nullable|date',
            'dueDate' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        foreach ([
            ['organizationId', 'crm_organizations', 'Organization'],
            ['contactId', 'crm_contacts', 'Contact'],
        ] as [$field, $table, $label]) {
            if (($rejection = $this->rejectUnknownReference($request, $identity['sub_institute_id'], $field, $table, $label)) !== null) {
                return $rejection;
            }
        }

        /*
         * Tracking-unit immutability guard - a deliberate new safeguard,
         * legacy has no equivalent. Once a contract has recorded any usage
         * (used_units > 0, written only by the sibling Ticket controller or
         * by nothing else here), switching what unit that usage was counted
         * in would silently orphan the number already on the row - e.g. 6
         * "hours" used so far suddenly reads as 6 "incidents" used, with no
         * actual re-count. Checked, and rejected, BEFORE any other change on
         * this request is applied.
         */
        if ($request->filled('trackingUnit')
            && (string) $request->input('trackingUnit') !== (string) ($existing->tracking_unit ?? '')
            && (float) $existing->used_units > 0
        ) {
            return response()->json([
                'status' => 0,
                'message' => 'Cannot change the tracking unit once this contract has recorded usage - start a new contract instead.',
            ], 422);
        }

        $data = $this->payload($request, forUpdate: true);

        /*
         * Manual-complete auto-stamp: closing a contract by hand without
         * also giving an explicit end date stamps today's date, mirroring
         * the small auto-stamp the sibling Ticket controller's own
         * consumption-math does from its own side. Deliberately a private,
         * ~3-line copy of that rule rather than a shared function - sharing
         * one across controllers built in parallel by different agents
         * would create a cross-agent file dependency neither side can see.
         */
        if (($data['contract_status'] ?? null) === 'Complete' && ! $request->filled('endDate')) {
            $data['end_date'] = now()->toDateString();
        }

        $data['updated_by'] = $identity['user_id'];
        $data['updated_at'] = now();

        DB::table('crm_service_contracts')->where('id', $id)->update($data);
        $this->recordCrmAudit('crm.service_contract.updated', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'], (array) $existing, array_merge((array) $existing, $data));
        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Service contract updated.', 'data' => $this->resource($row)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Service contract not found.'], 404);
        }

        DB::table('crm_service_contracts')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $identity['user_id'],
        ]);

        $this->recordCrmAudit(
            'crm.service_contract.deleted', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'],
            (array) $existing,
            array_merge((array) $existing, ['deleted_at' => now()->toDateTimeString(), 'deleted_by' => $identity['user_id']]),
        );

        return response()->json(['status' => 1, 'message' => 'Service contract moved to Recycle Bin.']);
    }

    protected function auditEntityType(): string
    {
        return 'crm_service_contracts';
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkDeleteRows($request, 'crm_service_contracts', $identity['sub_institute_id'], $identity['user_id'], 'Service contract');
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkAssignRows($request, 'crm_service_contracts', $identity['sub_institute_id'], $identity['user_id'], 'Service contract');
    }

    /** Exports the same rows index() would list (search applied). */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $query = DB::table('crm_service_contracts')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where('subject', 'like', "%{$search}%");
        }

        return $this->exportCsv($query, self::EXPORT_COLUMNS, 'service-contracts.csv');
    }

    /** CSV import - header fields only, same discipline as every other CRM import. */
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
            $data['contract_no'] = 'SERCON-' . Str::upper(Str::random(8));
            $data['contract_status'] = $data['contract_status'] ?? 'In Planning';
            $data['total_units'] = $data['total_units'] ?? 0;
            $data['used_units'] = 0;
            $data['progress'] = 0;
            $data['sub_institute_id'] = $identity['sub_institute_id'];
            $data['created_by'] = $identity['user_id'];
            $data['created_at'] = now();
            $data['updated_at'] = now();

            DB::table('crm_service_contracts')->insert($data);
            $created++;
            $results[] = ['row' => $index + 1, 'ok' => true];
        }

        return response()->json([
            'status' => 1,
            'message' => $created === count($results) ? "{$created} service contract(s) imported." : "{$created} of " . count($results) . ' row(s) imported.',
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
        return DB::table('crm_service_contracts as sc')
            ->leftJoin('crm_organizations as o', 'o.id', '=', 'sc.organization_id')
            ->leftJoin('crm_contacts as c', 'c.id', '=', 'sc.contact_id')
            ->leftJoin('tbluser as u', 'u.id', '=', 'sc.assigned_to')
            ->where('sc.sub_institute_id', $tenantId)
            ->whereNull('sc.deleted_at')
            ->select(
                'sc.*',
                'o.name as organization_name',
                DB::raw("TRIM(CONCAT(c.first_name, ' ', c.last_name)) as contact_name"),
                DB::raw("TRIM(CONCAT(u.first_name, ' ', u.last_name)) as assigned_to_name"),
            );
    }

    private function ownRow(int $tenantId, int $id): ?object
    {
        return DB::table('crm_service_contracts')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    private function ownRowJoined(int $tenantId, int $id): ?object
    {
        return $this->baseQuery($tenantId)->where('sc.id', $id)->first();
    }

    /**
     * @return array<string, mixed>
     *
     * Deliberately no 'usedUnits'/'progress' entry in this map - they are
     * server-maintained only (see class docblock), so there is no request
     * key that could ever flow through to those two columns here.
     */
    private function payload(Request $request, bool $forUpdate = false): array
    {
        $map = [
            'subject' => 'subject', 'organizationId' => 'organization_id', 'contactId' => 'contact_id',
            'startDate' => 'start_date', 'endDate' => 'end_date', 'dueDate' => 'due_date',
            'trackingUnit' => 'tracking_unit', 'totalUnits' => 'total_units',
            'contractStatus' => 'contract_status', 'contractType' => 'contract_type', 'priority' => 'priority',
            'assignedTo' => 'assigned_to',
        ];

        $data = [];

        foreach ($map as $requestKey => $column) {
            if ($forUpdate && ! $request->has($requestKey)) {
                continue;
            }

            $data[$column] = $request->input($requestKey);
        }

        foreach (['organization_id', 'contact_id', 'assigned_to'] as $nullableIdColumn) {
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
            'contractNo' => $row->contract_no,
            'subject' => $row->subject,
            'organizationId' => $row->organization_id ? (string) $row->organization_id : null,
            'organizationName' => $row->organization_name ?? null,
            'contactId' => $row->contact_id ? (string) $row->contact_id : null,
            'contactName' => ($row->contact_name ?? '') !== '' ? $row->contact_name : null,
            'startDate' => $row->start_date,
            'endDate' => $row->end_date,
            'dueDate' => $row->due_date,
            'trackingUnit' => $row->tracking_unit,
            'totalUnits' => $row->total_units !== null ? (float) $row->total_units : null,
            'usedUnits' => $row->used_units !== null ? (float) $row->used_units : null,
            'progress' => $row->progress !== null ? (float) $row->progress : null,
            'contractStatus' => $row->contract_status,
            'contractType' => $row->contract_type,
            'priority' => $row->priority,
            'assignedTo' => $row->assigned_to ? (string) $row->assigned_to : null,
            'assignedToName' => ($row->assigned_to_name ?? '') !== '' ? $row->assigned_to_name : null,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }
}
