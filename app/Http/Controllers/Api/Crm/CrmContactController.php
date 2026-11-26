<?php

namespace App\Http\Controllers\Api\Crm;

use App\Domain\Crm\Contacts\CompanyPeople;
use App\Domain\Crm\Contacts\ContactMerger;
use App\Domain\Crm\Contacts\ContactPrimary;
use App\Domain\Crm\Contacts\ContactRoles;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmAuditTrail;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmBulkActions;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmComments;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmDocuments;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmDuplicateDetection;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmEmailLinks;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmExport;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmMerge;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Contacts - the people of a company (S18: they now live inside Organizations, one company page that holds its people). A contact belongs to at most ONE company
 * (crm_contacts.organization_id); `is_primary` marks the company's primary contact (at most one per company, kept by ContactPrimary) and `contact_role` its role there (a value of the
 * contacts / contact_role picklist).
 */
class CrmContactController extends Controller
{
    use ResolvesApiIdentity;
    use HasCrmBulkActions;
    use HasCrmDuplicateDetection;
    use HasCrmMerge;
    use HasCrmExport;
    use HasCrmComments;
    use HasCrmDocuments;
    use HasCrmEmailLinks;
    use HasCrmAuditTrail;

    /** @var array<string, string> db column => CSV header, in export column order. The last three (S18) are appended so the earlier columns keep their place. */
    private const EXPORT_COLUMNS = [
        'salutation' => 'Salutation', 'first_name' => 'First Name', 'last_name' => 'Last Name',
        'title' => 'Title', 'department' => 'Department', 'email' => 'Email',
        'secondary_email' => 'Secondary Email', 'phone' => 'Phone', 'mobile' => 'Mobile',
        'home_phone' => 'Home Phone', 'birthday' => 'Birthday', 'lead_source' => 'Lead Source',
        'mailing_street' => 'Mailing Street', 'mailing_city' => 'Mailing City',
        'mailing_state' => 'Mailing State', 'mailing_code' => 'Mailing Postal Code',
        'mailing_country' => 'Mailing Country', 'description' => 'Description',
        'organization_id' => 'Organization ID', 'assigned_to' => 'Assigned To (user id)',
        'primary_label' => 'Primary', 'contact_role' => 'Role', 'organization_name' => 'Company',
    ];

    /** @var array<string, int> request field => the length of its column; a longer value is refused with a message (the dev database is strict, the live one would cut it silently). */
    private const LENGTHS = [
        'salutation' => 20, 'firstName' => 100, 'lastName' => 100, 'title' => 100, 'department' => 100,
        'email' => 191, 'secondaryEmail' => 191, 'phone' => 30, 'mobile' => 30, 'fax' => 30, 'homePhone' => 30,
        'assistant' => 100, 'assistantPhone' => 30, 'leadSource' => 100, 'contactRole' => 40, 'linkedinUrl' => 300,
        'mailingStreet' => 250, 'mailingCity' => 50, 'mailingState' => 50, 'mailingCode' => 30, 'mailingPostalCode' => 30, 'mailingCountry' => 50, 'mailingPoBox' => 30,
        'otherStreet' => 250, 'otherCity' => 50, 'otherState' => 50, 'otherCode' => 30, 'otherPostalCode' => 30, 'otherCountry' => 50, 'otherPoBox' => 30,
    ];

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = max(1, (int) $request->input('page', 1));

        $query = $this->listQuery($identity['sub_institute_id']);
        $this->applyFilters($query, $request, $identity['sub_institute_id']);

        $sortableColumns = ['first_name', 'last_name', 'email', 'title', 'created_at', 'is_primary', 'contact_role'];
        $sortBy = in_array($request->input('sort_by'), $sortableColumns, true) ? 'c.' . $request->input('sort_by') : 'c.last_name';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'desc' ? 'desc' : 'asc';

        $total = (clone $query)->count();
        $rows = $query->orderBy($sortBy, $sortDir)->orderBy('c.id')->forPage($page, $perPage)->get();

        return response()->json([
            'status' => 1,
            'message' => 'Contacts.',
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

        $row = $this->ownRowWithOrganization($identity['sub_institute_id'], $id);

        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Contact not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Contact.', 'data' => $this->resource($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $problem = $this->problemWith($request, $identity['sub_institute_id'], null, forUpdate: false);

        if ($problem !== null) {
            return response()->json(['status' => 0, 'message' => $problem], 422);
        }

        $data = $this->payload($request);
        $data['contact_no'] = 'CON-' . Str::upper(Str::random(8));
        $data['sub_institute_id'] = $identity['sub_institute_id'];
        $data['created_by'] = $identity['user_id'];
        $data['created_at'] = now();
        $data['updated_at'] = now();
        // A host that has not run the S18 migration / the LinkedIn one yet still takes a person without them.
        foreach (['contact_role', 'linkedin_url'] as $optional) {
            if (($data[$optional] ?? null) === null) {
                unset($data[$optional]);
            }
        }

        $wantsPrimary = $request->boolean('isPrimary');
        $id = DB::transaction(function () use ($data, $identity, $wantsPrimary) {
            $id = DB::table('crm_contacts')->insertGetId($data);
            if ($wantsPrimary && ($data['organization_id'] ?? null) !== null) {
                ContactPrimary::make($identity['sub_institute_id'], (int) $id, (int) $data['organization_id']);
            }

            return (int) $id;
        });

        $row = $this->ownRowWithOrganization($identity['sub_institute_id'], $id);
        $this->recordCrmAudit('crm.contact.created', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'], [], $this->auditable($row));

        return response()->json(['status' => 1, 'message' => 'Contact created.', 'data' => $this->resource($row)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Contact not found.'], 404);
        }

        $problem = $this->problemWith($request, $identity['sub_institute_id'], $id, forUpdate: true);

        if ($problem !== null) {
            return response()->json(['status' => 0, 'message' => $problem], 422);
        }

        $data = $this->payload($request, forUpdate: true);

        // The star (S18): a person without a company cannot hold it, and moving to another company takes it off (the new company may already have its primary).
        $company = array_key_exists('organization_id', $data) ? $data['organization_id'] : $existing->organization_id;
        $moved = array_key_exists('organization_id', $data) && (int) $data['organization_id'] !== (int) $existing->organization_id;
        $wantsPrimary = $request->has('isPrimary') ? $request->boolean('isPrimary') : null;

        if ($wantsPrimary === true && $company === null) {
            return response()->json(['status' => 0, 'message' => ContactPrimary::NEEDS_COMPANY], 422);
        }
        if ($wantsPrimary === false || (($moved || $company === null) && $wantsPrimary !== true && (int) ($existing->is_primary ?? 0) === 1)) {
            $data['is_primary'] = 0;
        }

        $data['updated_by'] = $identity['user_id'];
        $data['updated_at'] = now();

        $lost = [];
        DB::transaction(function () use ($id, $data, $identity, $wantsPrimary, $company, &$lost) {
            DB::table('crm_contacts')->where('id', $id)->update($data);
            if ($wantsPrimary === true) {
                $lost = ContactPrimary::make($identity['sub_institute_id'], $id, (int) $company);
            }
        });
        if ($wantsPrimary === true) {
            $data['is_primary'] = 1;
        }

        $this->recordCrmAudit('crm.contact.updated', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'], (array) $existing, array_merge((array) $existing, $data));
        $this->auditPrimaryLost($identity, $lost);

        $row = $this->ownRowWithOrganization($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Contact updated.', 'data' => $this->resource($row)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Contact not found.'], 404);
        }

        // A deleted person never keeps the star, so restoring it from the Recycle Bin cannot bring a second primary back.
        $update = ['deleted_at' => now(), 'deleted_by' => $identity['user_id']];
        $wasPrimary = (int) ($existing->is_primary ?? 0) === 1;
        if ($wasPrimary) {
            $update['is_primary'] = 0;
        }
        DB::table('crm_contacts')->where('id', $id)->update($update);

        $this->recordCrmAudit(
            'crm.contact.deleted', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'],
            (array) $existing,
            array_merge((array) $existing, ['deleted_at' => now()->toDateTimeString(), 'deleted_by' => $identity['user_id']], $wasPrimary ? ['is_primary' => 0] : []),
        );

        return response()->json(['status' => 1, 'message' => 'Contact moved to Recycle Bin.']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkDeleteRows(
            $request, 'crm_contacts', $identity['sub_institute_id'], $identity['user_id'], 'Contact',
            audit: function (int $id, object $before, array $changes) use ($identity) {
                $wasPrimary = (int) ($before->is_primary ?? 0) === 1;
                if ($wasPrimary) {
                    ContactPrimary::clear($identity['sub_institute_id'], $id);
                }
                $this->recordCrmAudit('crm.contact.deleted', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'], (array) $before,
                    array_merge((array) $before, $changes, $wasPrimary ? ['is_primary' => 0] : []));
            },
        );
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkAssignRows(
            $request, 'crm_contacts', $identity['sub_institute_id'], $identity['user_id'], 'Contact',
            audit: fn (int $id, object $before, array $changes) => $this->recordCrmAudit(
                'crm.contact.transferred', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'], (array) $before, array_merge((array) $before, $changes),
            ),
        );
    }

    /**
     * Possible duplicates: an exact (normalized) email match, and a same
     * first-and-last-name match for contacts with no email at all.
     */
    public function duplicates(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $tenantId = $identity['sub_institute_id'];
        $resource = fn ($row) => $this->resource($row);

        $groups = array_merge(
            $this->duplicateGroups('crm_contacts', $tenantId, 'LOWER(TRIM(email))', ['email'], 'Same email', $resource),
            $this->duplicateGroups('crm_contacts', $tenantId, "CONCAT(LOWER(TRIM(first_name)), '|', LOWER(TRIM(last_name)))", ['first_name', 'last_name'], 'Same name', $resource),
        );

        return response()->json(['status' => 1, 'message' => 'Possible duplicate contacts.', 'data' => $groups]);
    }

    public function merge(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $tenantId = $identity['sub_institute_id'];
        $survivorBefore = $request->filled('survivorId') ? $this->ownRow($tenantId, (int) $request->input('survivorId')) : null;
        $merged = [];

        $response = $this->mergeRows(
            $request, 'crm_contacts', $tenantId, $identity['user_id'], 'Contact',
            // Every table that stores a contact id (quotes, orders, invoices, tickets, opportunities, vendors, comments, e-mails, documents, schedule, campaign targets, consent, facts, ...)
            // is listed in ContactReferences and moved by ContactMerger, scoped to this tenant, in mergeRows' transaction.
            repoint: function (int $fromId, int $toId) use ($tenantId, &$merged) {
                $merged[] = [$fromId, $toId];

                // Slice 4: the duplicate's facts follow it to the survivor, and what it said about being contacted is not lost with it (the field supplement
                // below copies a blank, and `email_opt_out = 0` is not blank, so a duplicate's opt-out used to vanish: defect D7).
                app(\App\Domain\Facts\FactRepoint::class)->move($tenantId, 'contact', $fromId, $toId);
                app(\App\Domain\Facts\ConsentRecorder::class)->carryOver($tenantId, 'contact', $fromId, 'contact', $toId);

                // The rest of the registry, then the star: the survivor keeps its own, or takes the duplicate's when neither it nor its company has one.
                ContactMerger::repoint($tenantId, $fromId, $toId);
                ContactPrimary::afterMerge($tenantId, $fromId, $toId);
            },
        );

        // History, after the transaction is committed (HasCrmAuditTrail): the survivor says what it absorbed, each removed contact says where it went.
        if ($merged !== [] && $response->getStatusCode() === 200 && $survivorBefore) {
            $survivorId = (int) $survivorBefore->id;
            $after = $this->ownRow($tenantId, $survivorId);
            $ids = implode(',', array_map(fn ($pair) => $pair[0], $merged));
            $this->recordCrmAudit('crm.contact.merged', $tenantId, $this->auditEntityType(), $survivorId, $identity['user_id'], (array) $survivorBefore + ['merged_ids' => null],
                array_merge((array) ($after ?? $survivorBefore), ['merged_ids' => $ids]));
            foreach ($merged as [$fromId]) {
                $this->recordCrmAudit('crm.contact.merged_away', $tenantId, $this->auditEntityType(), $fromId, $identity['user_id'], ['merged_into_id' => null, 'deleted_at' => null],
                    ['merged_into_id' => $survivorId, 'deleted_at' => now()->toDateTimeString()]);
            }
        }

        return $response;
    }

    /** Exports the same rows index() would list (search and filters applied). */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        // No join: the CSV skeleton orders by a bare `id`, which a second table would make ambiguous. The company's name is a sub-select, the star a yes/no.
        $query = DB::table('crm_contacts as c')
            ->where('c.sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('c.deleted_at')
            ->select('c.*')
            ->selectSub(fn ($q) => $q->select('xo.name')->from('crm_organizations as xo')->whereColumn('xo.id', 'c.organization_id')->limit(1), 'organization_name')
            ->selectRaw("CASE WHEN c.is_primary = 1 THEN 'yes' ELSE 'no' END as primary_label");

        $this->applyFilters($query, $request, $identity['sub_institute_id']);

        return $this->exportCsv($query, self::EXPORT_COLUMNS, 'contacts.csv');
    }

    /**
     * CSV import. A missing/invalid owner rejects the row (no fallback), and
     * an `organizationId` that doesn't resolve to a real, non-deleted
     * organization in this tenant is also rejected rather than silently
     * creating an orphaned reference or auto-creating a new organization -
     * a CSV is far more likely to carry a typo'd id than the picker UI ever
     * would.
     *
     * S18: an optional `contactRole` must be a value of the role picklist (else the row is reported, not saved), and an optional `isPrimary` (yes/no) is honoured only when the
     * row has a company and that company has no primary yet (never an error, never takes the star from someone).
     */
    public function import(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'rows' => 'required|array|min:1|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $created = 0;
        $results = [];
        $roles = null;

        foreach ($request->input('rows') as $index => $row) {
            $rowRequest = Request::create('/', 'POST', is_array($row) ? $row : []);

            $rowValidator = Validator::make($this->stringified($rowRequest->all()), $this->rules(false), $this->messages());

            if ($rowValidator->fails()) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => $rowValidator->errors()->first()];
                continue;
            }

            if ($rowRequest->filled('organizationId')) {
                $organizationExists = DB::table('crm_organizations')
                    ->where('id', (int) $rowRequest->input('organizationId'))
                    ->where('sub_institute_id', $identity['sub_institute_id'])
                    ->whereNull('deleted_at')
                    ->exists();

                if (! $organizationExists) {
                    $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => 'Organization ID not found.'];
                    continue;
                }
            }

            if ($rowRequest->filled('contactRole')) {
                $roles ??= ContactRoles::allowed();
                if (! in_array((string) $rowRequest->input('contactRole'), $roles, true)) {
                    $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => 'Choose a role from the list.'];
                    continue;
                }
            }

            if ($rowRequest->filled('reportsToId') && ! $this->liveContact($identity['sub_institute_id'], (int) $rowRequest->input('reportsToId'))) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => 'Choose a person from the list.'];
                continue;
            }

            $data = $this->payload($rowRequest);
            $data['contact_no'] = 'CON-' . Str::upper(Str::random(8));
            $data['sub_institute_id'] = $identity['sub_institute_id'];
            $data['created_by'] = $identity['user_id'];
            $data['created_at'] = now();
            $data['updated_at'] = now();
            foreach (['contact_role', 'linkedin_url'] as $optional) {
                if (($data[$optional] ?? null) === null) {
                    unset($data[$optional]);
                }
            }

            $id = (int) DB::table('crm_contacts')->insertGetId($data);

            if ($this->yes($rowRequest->input('isPrimary')) && ($data['organization_id'] ?? null) !== null) {
                $companyHasPrimary = DB::table('crm_contacts')->where('sub_institute_id', $identity['sub_institute_id'])->where('organization_id', $data['organization_id'])
                    ->whereNull('deleted_at')->where('is_primary', 1)->exists();
                if (! $companyHasPrimary) {
                    ContactPrimary::make($identity['sub_institute_id'], $id, (int) $data['organization_id']);
                }
            }

            $this->recordCrmAudit('crm.contact.created', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'], [], $this->auditable($this->ownRow($identity['sub_institute_id'], $id)) + ['created_via' => 'import']);

            $created++;
            $results[] = ['row' => $index + 1, 'ok' => true];
        }

        return response()->json([
            'status' => 1,
            'message' => $created === count($results) ? "{$created} contact(s) imported." : "{$created} of " . count($results) . ' row(s) imported.',
            'data' => ['created' => $created, 'results' => $results],
        ]);
    }

    public function transferOwnership(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Contact not found.'], 404);
        }

        $validator = Validator::make($request->all(), ['assignedTo' => 'required|integer']);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        DB::table('crm_contacts')->where('id', $id)->update([
            'assigned_to' => (int) $request->input('assignedTo'),
            'updated_by' => $identity['user_id'],
            'updated_at' => now(),
        ]);

        $this->recordCrmAudit('crm.contact.transferred', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'], (array) $existing, array_merge((array) $existing, ['assigned_to' => (int) $request->input('assignedTo')]));

        return response()->json(['status' => 1, 'message' => 'Ownership transferred.']);
    }

    /** POST /api/crm/contacts/{id}/primary - makes this person the company's primary contact; every other person of the company loses the star, in ONE transaction. */
    public function setPrimary(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Contact not found.'], 404);
        }

        if ($existing->organization_id === null) {
            return response()->json(['status' => 0, 'message' => ContactPrimary::NEEDS_COMPANY], 422);
        }

        $lost = ContactPrimary::make($identity['sub_institute_id'], $id, (int) $existing->organization_id);

        $this->recordCrmAudit('crm.contact.primary_set', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'], (array) $existing, array_merge((array) $existing, ['is_primary' => 1]));
        $this->auditPrimaryLost($identity, $lost);

        return response()->json(['status' => 1, 'message' => 'Primary contact set.', 'data' => $this->resource($this->ownRowWithOrganization($identity['sub_institute_id'], $id))]);
    }

    /** DELETE /api/crm/contacts/{id}/primary - takes the star off this person (the company then has no primary). */
    public function clearPrimary(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Contact not found.'], 404);
        }

        if (ContactPrimary::clear($identity['sub_institute_id'], $id)) {
            $this->recordCrmAudit('crm.contact.primary_cleared', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'], (array) $existing, array_merge((array) $existing, ['is_primary' => 0]));
        }

        return response()->json(['status' => 1, 'message' => 'Primary contact cleared.', 'data' => $this->resource($this->ownRowWithOrganization($identity['sub_institute_id'], $id))]);
    }

    /**
     * GET /api/crm/contacts/{id}/deals - everything this person is on: opportunities (through the people-on-an-opportunity list), quotes, sales orders, invoices and tickets (through their
     * contact). One query per table, tenant scoped, newest first, at most 200 of each. Rows: {id, number, name, status, amount, currency, date}; a ticket has no amount or currency.
     */
    public function deals(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Contact not found.'], 404);
        }

        $tenant = $identity['sub_institute_id'];
        $row = fn (object $r, string $number, string $name, string $status, ?string $amount, ?string $currency, string $date) => [
            'id' => (string) $r->id,
            'number' => $r->{$number},
            'name' => $r->{$name},
            'status' => $r->{$status},
            'amount' => $amount !== null && $r->{$amount} !== null ? (float) $r->{$amount} : null,
            'currency' => $currency !== null ? ($r->{$currency} ?? null) : null,
            'date' => $r->{$date} !== null ? substr((string) $r->{$date}, 0, 10) : null,
        ];

        $opportunities = DB::table('crm_opportunity_contacts as oc')
            ->join('crm_opportunities as t', 't.id', '=', 'oc.opportunity_id')
            ->where('oc.contact_id', $id)->where('t.sub_institute_id', $tenant)->whereNull('t.deleted_at')
            ->orderByDesc('t.id')->limit(200)
            ->get(['t.id', 't.opportunity_no', 't.name', 't.sales_stage', 't.amount', 't.currency', 't.closing_date']);
        $quotes = $this->dealsOf('crm_quotes', $id, $tenant, ['quote_no', 'subject', 'quote_stage', 'total', 'currency', 'valid_till']);
        $salesOrders = $this->dealsOf('crm_sales_orders', $id, $tenant, ['so_no', 'subject', 'so_stage', 'total', 'currency', 'due_date']);
        $invoices = $this->dealsOf('crm_invoices', $id, $tenant, ['invoice_no', 'subject', 'invoice_stage', 'total', 'currency', 'invoice_date']);
        $tickets = $this->dealsOf('crm_tickets', $id, $tenant, ['ticket_no', 'title', 'status', null, null, 'created_at']);

        return response()->json([
            'status' => 1,
            'message' => 'Deals of this contact.',
            'data' => [
                'opportunities' => $opportunities->map(fn ($r) => $row($r, 'opportunity_no', 'name', 'sales_stage', 'amount', 'currency', 'closing_date'))->all(),
                'quotes' => $quotes->map(fn ($r) => $row($r, 'quote_no', 'subject', 'quote_stage', 'total', 'currency', 'valid_till'))->all(),
                'salesOrders' => $salesOrders->map(fn ($r) => $row($r, 'so_no', 'subject', 'so_stage', 'total', 'currency', 'due_date'))->all(),
                'invoices' => $invoices->map(fn ($r) => $row($r, 'invoice_no', 'subject', 'invoice_stage', 'total', 'currency', 'invoice_date'))->all(),
                'tickets' => $tickets->map(fn ($r) => $row($r, 'ticket_no', 'title', 'status', null, null, 'created_at'))->all(),
            ],
        ]);
    }

    /**
     * One query: the live rows of a table whose contact_id is this person (the columns are number, name, status, amount, currency, date; null where the table has none).
     *
     * @param  list<string|null>  $columns
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function dealsOf(string $table, int $contactId, int $tenantId, array $columns)
    {
        return DB::table($table)->where('contact_id', $contactId)->where('sub_institute_id', $tenantId)->whereNull('deleted_at')
            ->orderByDesc('id')->limit(200)->get(array_merge(['id'], array_values(array_filter($columns))));
    }

    // ---------------------------------------------------------------------------------------------------------------- queries

    /** The list/detail query: the person, the company's name and whether the company is in the Recycle Bin, and the name of the person they report to. */
    private function listQuery(int $tenantId): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('crm_contacts as c')
            ->leftJoin('crm_organizations as o', 'o.id', '=', 'c.organization_id')
            ->leftJoin('crm_contacts as rt', fn ($join) => $join->on('rt.id', '=', 'c.reports_to_id')->whereNull('rt.deleted_at'))
            ->where('c.sub_institute_id', $tenantId)
            ->whereNull('c.deleted_at')
            ->select('c.*', 'o.name as organization_name', 'o.deleted_at as organization_deleted_at', 'rt.first_name as reports_to_first_name', 'rt.last_name as reports_to_last_name');
        // The owner's name, so no screen has to look the user up again (it showed "User #28" whenever the employee list had not loaded). The tenant is part of the lookup.
        if (\Illuminate\Support\Facades\Schema::hasTable('tbluser')) {
            $query->leftJoin('tbluser as u', fn ($join) => $join->on('u.id', '=', 'c.assigned_to')->where('u.sub_institute_id', '=', $tenantId))
                ->addSelect('u.first_name as owner_first_name', 'u.last_name as owner_last_name');
        }

        return $query;
    }

    /** Search and filters shared by the list and the export; the query is the crm_contacts table aliased c. */
    private function applyFilters($query, Request $request, int $tenantId): void
    {
        if ($search = trim((string) $request->input('search', ''))) {
            $like = "%{$search}%";
            $query->where(function ($q) use ($like, $tenantId) {
                $q->where('c.first_name', 'like', $like)
                    ->orWhere('c.last_name', 'like', $like)
                    ->orWhere('c.email', 'like', $like)
                    ->orWhere('c.phone', 'like', $like)
                    ->orWhere('c.mobile', 'like', $like)
                    ->orWhere('c.title', 'like', $like)
                    ->orWhere('c.department', 'like', $like)
                    ->orWhereRaw(CompanyPeople::fullNameSql('c') . ' like ?', [$like])
                    ->orWhereIn('c.organization_id', fn ($o) => $o->select('id')->from('crm_organizations')->where('sub_institute_id', $tenantId)->where('name', 'like', $like));
            });
        }

        if ($organizationId = $request->input('organization_id')) {
            $query->where('c.organization_id', $organizationId);
        }

        if ($request->boolean('primary')) {
            $query->where('c.is_primary', 1);
        }

        if ($request->filled('owner')) {
            $query->where('c.assigned_to', (int) $request->input('owner'));
        }

        if ($request->filled('consent')) {
            $status = strtolower(trim((string) $request->input('consent')));
            // A contact nobody has recorded anything for is 'unknown', whether the column says so or is empty.
            $status === 'unknown'
                ? $query->where(fn ($q) => $q->whereNull('c.consent_status')->orWhere('c.consent_status', 'unknown'))
                : $query->where('c.consent_status', $status);
        }

        if ($request->boolean('do_not_call')) {
            $query->where('c.do_not_call', 1);
        }

        if ($request->boolean('no_company')) {
            $query->whereNull('c.organization_id');
        }
    }

    private function ownRow(int $tenantId, int $id): ?object
    {
        return DB::table('crm_contacts')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    private function ownRowWithOrganization(int $tenantId, int $id): ?object
    {
        return $this->listQuery($tenantId)->where('c.id', $id)->first();
    }

    private function liveContact(int $tenantId, int $id): bool
    {
        return DB::table('crm_contacts')->where('id', $id)->where('sub_institute_id', $tenantId)->whereNull('deleted_at')->exists();
    }

    protected function commentsTable(): string
    {
        return 'crm_contact_comments';
    }

    protected function commentsForeignKey(): string
    {
        return 'contact_id';
    }

    protected function documentsLinkedType(): string
    {
        return 'CONTACT';
    }

    protected function emailsLinkedType(): string
    {
        return 'CONTACT';
    }

    protected function auditEntityType(): string
    {
        return 'crm_contacts';
    }

    // ---------------------------------------------------------------------------------------------------------------- writing

    /**
     * Everything that can be wrong with a create / update request, as one message (the first): field rules and lengths first, then what the fields point at. Null when it is fine.
     * `organizationId` must be a live company of the caller's organisation; `reportsToId` a live person of it and not the person themselves; `contactRole` a role of the picklist.
     */
    private function problemWith(Request $request, int $tenantId, ?int $selfId, bool $forUpdate): ?string
    {
        $validator = Validator::make($this->stringified($request->all()), $this->rules($forUpdate), $this->messages());

        if ($validator->fails()) {
            return $validator->errors()->first();
        }

        if ($request->filled('organizationId') && ! DB::table('crm_organizations')->where('id', (int) $request->input('organizationId'))->where('sub_institute_id', $tenantId)->whereNull('deleted_at')->exists()) {
            return 'Choose a company from the list.';
        }

        if ($request->filled('reportsToId')) {
            if ($selfId !== null && (int) $request->input('reportsToId') === $selfId) {
                return 'A contact cannot report to themselves.';
            }
            if (! $this->liveContact($tenantId, (int) $request->input('reportsToId'))) {
                return 'Choose a person from the list.';
            }
        }

        if ($request->filled('contactRole') && ! in_array((string) $request->input('contactRole'), ContactRoles::allowed(), true)) {
            return 'Choose a role from the list.';
        }

        // The star needs a company (create: the company in the request; update: checked against the company the person ends up with, further down).
        if (! $forUpdate && $request->boolean('isPrimary') && ! $request->filled('organizationId')) {
            return ContactPrimary::NEEDS_COMPANY;
        }

        return null;
    }

    /** @return array<string, string> */
    private function rules(bool $forUpdate): array
    {
        $rules = [
            'lastName' => ($forUpdate ? 'sometimes|required|' : 'required|') . 'string|max:' . self::LENGTHS['lastName'],
            'email' => 'nullable|email|max:' . self::LENGTHS['email'],
            'assignedTo' => ($forUpdate ? 'sometimes|required|' : 'required|') . 'integer',
            'organizationId' => 'nullable|integer',
            'reportsToId' => 'nullable|integer',
            'birthday' => 'nullable|date_format:Y-m-d',
        ];

        foreach (self::LENGTHS as $field => $max) {
            $rules[$field] ??= 'nullable|string|max:' . $max;
        }

        return $rules;
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return ['birthday.date_format' => 'Birthday must be a date (YYYY-MM-DD).'];
    }

    /**
     * A phone number sent as a JSON number is still a phone number: the length rules read strings.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function stringified(array $input): array
    {
        foreach (array_keys(self::LENGTHS) as $field) {
            if (isset($input[$field]) && (is_int($input[$field]) || is_float($input[$field]))) {
                $input[$field] = (string) $input[$field];
            }
        }

        return $input;
    }

    private function yes(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['yes', 'y', '1', 'true', 'on'], true);
    }

    /** The row as the history sees it (the joined display columns are not part of the contact). @return array<string, mixed> */
    private function auditable(?object $row): array
    {
        return array_diff_key((array) $row, array_flip(['organization_name', 'organization_deleted_at', 'reports_to_first_name', 'reports_to_last_name', 'owner_first_name', 'owner_last_name']));
    }

    /**
     * @param  array{user_id: int, sub_institute_id: int}  $identity
     * @param  list<int>  $lost  the people who lost the star to someone else
     */
    private function auditPrimaryLost(array $identity, array $lost): void
    {
        foreach ($lost as $lostId) {
            $this->recordCrmAudit('crm.contact.primary_cleared', $identity['sub_institute_id'], $this->auditEntityType(), $lostId, $identity['user_id'], ['is_primary' => 1], ['is_primary' => 0]);
        }
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, bool $forUpdate = false): array
    {
        $map = [
            'organizationId' => 'organization_id', 'salutation' => 'salutation', 'firstName' => 'first_name',
            'lastName' => 'last_name', 'title' => 'title', 'department' => 'department', 'email' => 'email',
            'secondaryEmail' => 'secondary_email', 'phone' => 'phone', 'mobile' => 'mobile', 'fax' => 'fax',
            'homePhone' => 'home_phone', 'assistant' => 'assistant', 'assistantPhone' => 'assistant_phone',
            'reportsToId' => 'reports_to_id', 'birthday' => 'birthday', 'leadSource' => 'lead_source',
            'doNotCall' => 'do_not_call', 'emailOptOut' => 'email_opt_out',
            'mailingStreet' => 'mailing_street', 'mailingCity' => 'mailing_city', 'mailingState' => 'mailing_state',
            'mailingCode' => 'mailing_code', 'mailingCountry' => 'mailing_country', 'mailingPoBox' => 'mailing_po_box',
            'otherStreet' => 'other_street', 'otherCity' => 'other_city', 'otherState' => 'other_state',
            'otherCode' => 'other_code', 'otherCountry' => 'other_country', 'otherPoBox' => 'other_po_box',
            'description' => 'description', 'assignedTo' => 'assigned_to',
            'linkedinUrl' => 'linkedin_url', 'contactRole' => 'contact_role',
        ];

        $data = [];

        foreach ($map as $requestKey => $column) {
            if ($forUpdate && ! $request->has($requestKey)) {
                continue;
            }

            $data[$column] = $request->input($requestKey);
        }

        // The screens call the postal codes "postal code": the same two columns, under both names (the older name wins when both are sent).
        foreach (['mailingPostalCode' => ['mailingCode', 'mailing_code'], 'otherPostalCode' => ['otherCode', 'other_code']] as $alias => [$canonical, $column]) {
            if ($request->has($alias) && ! $request->has($canonical)) {
                $data[$column] = $request->input($alias);
            }
        }

        foreach (['email_opt_out', 'do_not_call'] as $boolColumn) {
            if (array_key_exists($boolColumn, $data)) {
                $data[$boolColumn] = (bool) $data[$boolColumn];
            }
        }

        foreach (['organization_id', 'reports_to_id', 'birthday', 'contact_role'] as $nullableColumn) {
            if (array_key_exists($nullableColumn, $data) && $data[$nullableColumn] === '') {
                $data[$nullableColumn] = null;
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function resource(object $row): array
    {
        $reportsTo = CompanyPeople::name($row->reports_to_first_name ?? null, $row->reports_to_last_name ?? null);

        return [
            'id' => (string) $row->id,
            'contactNo' => $row->contact_no,
            'organizationId' => $row->organization_id ? (string) $row->organization_id : null,
            'organizationName' => $row->organization_name ?? null,
            // True while the company is in the Recycle Bin: the person stays linked to it and is under it again once it is restored.
            'organizationDeleted' => ($row->organization_deleted_at ?? null) !== null,
            'isPrimary' => (bool) ($row->is_primary ?? 0),
            'contactRole' => $row->contact_role ?? null,
            'salutation' => $row->salutation,
            'firstName' => $row->first_name,
            'lastName' => $row->last_name,
            'title' => $row->title,
            'department' => $row->department,
            'email' => $row->email,
            'secondaryEmail' => $row->secondary_email,
            'phone' => $row->phone,
            'mobile' => $row->mobile,
            'fax' => $row->fax,
            'homePhone' => $row->home_phone,
            'assistant' => $row->assistant,
            'assistantPhone' => $row->assistant_phone,
            'reportsToId' => $row->reports_to_id ? (string) $row->reports_to_id : null,
            'reportsToName' => $reportsTo !== '' ? $reportsTo : null,
            'birthday' => $row->birthday !== null && $row->birthday !== '' ? substr((string) $row->birthday, 0, 10) : null,
            'leadSource' => $row->lead_source,
            'doNotCall' => (bool) $row->do_not_call,
            'emailOptOut' => (bool) $row->email_opt_out,
            'linkedinUrl' => $row->linkedin_url ?? null,
            'mailingStreet' => $row->mailing_street,
            'mailingCity' => $row->mailing_city,
            'mailingState' => $row->mailing_state,
            'mailingCode' => $row->mailing_code,
            'mailingPostalCode' => $row->mailing_code,
            'mailingCountry' => $row->mailing_country,
            'mailingPoBox' => $row->mailing_po_box,
            'otherStreet' => $row->other_street,
            'otherCity' => $row->other_city,
            'otherState' => $row->other_state,
            'otherCode' => $row->other_code,
            'otherPostalCode' => $row->other_code,
            'otherCountry' => $row->other_country,
            'otherPoBox' => $row->other_po_box,
            'description' => $row->description,
            'assignedTo' => $row->assigned_to ? (string) $row->assigned_to : null,
            'assignedToName' => trim(($row->owner_first_name ?? '') . ' ' . ($row->owner_last_name ?? '')) ?: null,
            'createdBy' => $row->created_by ? (string) $row->created_by : null,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }
}
