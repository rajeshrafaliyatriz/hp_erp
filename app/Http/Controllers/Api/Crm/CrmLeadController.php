<?php

namespace App\Http\Controllers\Api\Crm;

use App\Domain\Crm\Leads\LeadAssigner;
use App\Domain\Crm\Leads\LeadActivity;
use App\Domain\Crm\Leads\LeadConverter;
use App\Domain\Crm\Leads\LeadFactory;
use App\Domain\Crm\Leads\LeadScorer;
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
 * Leads — the legacy CRM's Marketing module, first vertical slice.
 *
 * Raw `DB::table('crm_leads')` query builder throughout, inline
 * `Validator::make()` per action, tenant-scoped by `sub_institute_id` only
 * (no `syear` — Leads are not academic-year-bound) — matching Task
 * Management's actual, confirmed convention rather than an Eloquent model.
 */
class CrmLeadController extends Controller
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

    /** @var array<string, string> db column => CSV header, in export column order. Also the accepted import row keys (camelCase - matches payload()'s map). */
    private const EXPORT_COLUMNS = [
        'salutation' => 'Salutation', 'first_name' => 'First Name', 'last_name' => 'Last Name',
        'company' => 'Company', 'designation' => 'Designation', 'email' => 'Email', 'secondary_email' => 'Secondary Email',
        'phone' => 'Phone', 'mobile' => 'Mobile', 'website' => 'Website', 'industry' => 'Industry',
        'lead_source' => 'Lead Source', 'lead_status' => 'Lead Status', 'rating' => 'Rating',
        'lead_score' => 'Lead Score',
        'annual_revenue' => 'Annual Revenue', 'street' => 'Street', 'city' => 'City',
        'state' => 'State', 'country' => 'Country', 'postal_code' => 'Postal Code',
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

        $query = DB::table('crm_leads')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            // Converted leads are a soft archive, not a hard delete, matching
            // the legacy CRM's own getDeletedRecordCondition() behavior —
            // excluded from the default list, never removed from the table.
            ->where('converted', false);

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('lead_status')) {
            $query->where('lead_status', $status);
        }

        if ($assignedTo = $request->input('assigned_to')) {
            $query->where('assigned_to', $assignedTo);
        }

        $sortableColumns = [
            'first_name', 'last_name', 'company', 'email', 'lead_status',
            'lead_source', 'rating', 'lead_score', 'created_at',
        ];
        $sortBy = in_array($request->input('sort_by'), $sortableColumns, true)
            ? $request->input('sort_by')
            : 'created_at';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'asc' ? 'asc' : 'desc';

        $total = (clone $query)->count();
        $rows = $query->orderBy($sortBy, $sortDir)
            ->forPage($page, $perPage)
            ->get();

        return response()->json([
            'status' => 1,
            'message' => 'Leads.',
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

        $row = DB::table('crm_leads')
            ->where('id', $id)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();

        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Lead not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Lead.', 'data' => $this->resource($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        // An empty string (what a blank form field actually sends - distinct from the key
        // being absent) fails Laravel's own `integer` check even under `nullable`, since
        // `nullable` only skips the other rules when the value is genuinely null. Normalize
        // it to real null first so "left blank" validates the same way as "never sent".
        if ($request->has('assignedTo') && $request->input('assignedTo') === '') {
            $request->merge(['assignedTo' => null]);
        }

        $validator = Validator::make($request->all(), [
            'lastName' => 'required|string|max:191',
            'company' => 'nullable|string|max:191',
            'email' => 'nullable|email|max:191',
            'secondaryEmail' => 'nullable|email|max:191',
            'leadStatus' => 'nullable|string|max:191',
            'leadSource' => 'nullable|string|max:191',
            'industry' => 'nullable|string|max:191',
            'rating' => 'nullable|string|max:191',
            'annualRevenue' => 'nullable|numeric',
            // Nullable, not required: an omitted assignee falls back to the round-robin
            // rotation below (Sales & Marketing gap-closure, Wave 2) when a tenant has
            // turned it on; untouched tenants still must pick someone, exactly as before.
            'assignedTo' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $assignedTo = $request->filled('assignedTo')
            ? (int) $request->input('assignedTo')
            : LeadAssigner::next($identity['sub_institute_id']);

        if ($assignedTo === null) {
            return response()->json(['status' => 0, 'message' => 'Choose who this lead is assigned to (no auto-assignment rotation is configured).', 'errors' => ['assignedTo' => ['The assigned to field is required.']]], 422);
        }

        $data = $this->payload($request, $identity['sub_institute_id']);
        $data['assigned_to'] = $assignedTo;
        // Tenant stamp/created_by/timestamps/lead_no are LeadFactory's job now - see
        // app/Domain/Crm/Leads/LeadFactory.php (Sales & Marketing unification, Phase 4).
        unset($data['sub_institute_id']);
        $id = LeadFactory::create($data, $identity['sub_institute_id'], $identity['user_id']);

        $row = DB::table('crm_leads')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'Lead created.', 'data' => $this->resource($row)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = DB::table('crm_leads')
            ->where('id', $id)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Lead not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'lastName' => 'sometimes|required|string|max:191',
            'email' => 'nullable|email|max:191',
            'secondaryEmail' => 'nullable|email|max:191',
            'annualRevenue' => 'nullable|numeric',
            'assignedTo' => 'sometimes|required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $data = $this->payload($request, $identity['sub_institute_id'], forUpdate: true);
        $data['updated_by'] = $identity['user_id'];
        $data['updated_at'] = now();

        // Scored against the row as it will stand AFTER this update, not just the changed
        // fields - a rule on `industry` must still re-fire if only `rating` changed.
        $scored = LeadScorer::score(array_merge((array) $existing, $data), $identity['sub_institute_id']);
        $data['lead_score'] = $scored['total'];
        $data['lead_score_basis'] = $scored['basis'] === [] ? null : json_encode($scored['basis']);

        DB::table('crm_leads')->where('id', $id)->update($data);

        $this->recordCrmAudit('crm.lead.updated', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'], (array) $existing, array_merge((array) $existing, $data));
        LeadActivity::announceQualified($identity['sub_institute_id'], $existing, $data['lead_status'] ?? null, $identity['user_id']);
        LeadActivity::announceReady($identity['sub_institute_id'], $id, $existing, array_merge((array) $existing, $data), $identity['user_id']);

        $row = DB::table('crm_leads')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'Lead updated.', 'data' => $this->resource($row)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = DB::table('crm_leads')
            ->where('id', $id)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Lead not found.'], 404);
        }

        DB::table('crm_leads')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $identity['user_id'],
        ]);

        $this->recordCrmAudit(
            'crm.lead.deleted', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'],
            (array) $existing,
            array_merge((array) $existing, ['deleted_at' => now()->toDateTimeString(), 'deleted_by' => $identity['user_id']]),
        );

        return response()->json(['status' => 1, 'message' => 'Lead moved to Recycle Bin.']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkDeleteRows($request, 'crm_leads', $identity['sub_institute_id'], $identity['user_id'], 'Lead');
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkAssignRows($request, 'crm_leads', $identity['sub_institute_id'], $identity['user_id'], 'Lead');
    }

    /**
     * Possible duplicates, grouped by two independent signals: an exact
     * (normalized) email match, and a same last-name-and-company match for
     * leads with no email at all. Converted leads are excluded - they are
     * already a soft archive (see index()), not an actionable record.
     */
    public function duplicates(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $tenantId = $identity['sub_institute_id'];
        $resource = fn ($row) => $this->resource($row);
        $excludeConverted = fn ($query) => $query->where('converted', false);

        $groups = array_merge(
            $this->duplicateGroups('crm_leads', $tenantId, 'LOWER(TRIM(email))', ['email'], 'Same email', $resource, $excludeConverted),
            $this->duplicateGroups('crm_leads', $tenantId, "CONCAT(LOWER(TRIM(last_name)), '|', LOWER(TRIM(company)))", ['last_name', 'company'], 'Same name and company', $resource, $excludeConverted),
        );

        return response()->json(['status' => 1, 'message' => 'Possible duplicate leads.', 'data' => $groups]);
    }

    public function merge(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->mergeRows(
            $request, 'crm_leads', $identity['sub_institute_id'], $identity['user_id'], 'Lead',
            repoint: fn (int $fromId, int $toId) => $this->repointCampaignTargets('lead', $fromId, $toId),
        );
    }

    /** Exports the same rows index() would list (search applied, converted leads excluded), never the hidden/deleted ones. */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $query = DB::table('crm_leads')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            ->where('converted', false);

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $this->exportCsv($query, self::EXPORT_COLUMNS, 'leads.csv');
    }

    /**
     * CSV import. Explicit decisions (none of this silently ports the
     * legacy system's permissive behavior):
     *  - A missing/invalid owner REJECTS the row (no fallback to an admin
     *    or to the importing user) - same as BulkTaskController's own CSV
     *    import, and the same rule store() already applies to a manual
     *    single create.
     *  - lead_status/lead_source/industry/rating are free-text columns, not
     *    foreign keys to a picklist table, so an unrecognized value is
     *    simply accepted as typed - there is no "picklist" to auto-grow.
     *  - No cross-references to validate (Leads has none of its own - unlike
     *    Contacts' organizationId).
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

        foreach ($request->input('rows') as $index => $row) {
            $rowRequest = Request::create('/', 'POST', is_array($row) ? $row : []);

            // Same normalization store() applies: a blank CSV cell arrives as '', which
            // `nullable|integer` does not treat the same as a genuinely absent key.
            if ($rowRequest->has('assignedTo') && $rowRequest->input('assignedTo') === '') {
                $rowRequest->merge(['assignedTo' => null]);
            }

            $rowValidator = Validator::make($rowRequest->all(), [
                'lastName' => 'required|string|max:191',
                'email' => 'nullable|email|max:191',
                'assignedTo' => 'nullable|integer',
                'annualRevenue' => 'nullable|numeric',
            ]);

            if ($rowValidator->fails()) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => $rowValidator->errors()->first()];
                continue;
            }

            $assignedTo = $rowRequest->filled('assignedTo')
                ? (int) $rowRequest->input('assignedTo')
                : LeadAssigner::next($identity['sub_institute_id']);

            if ($assignedTo === null) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => 'Assigned To is required (no auto-assignment rotation is configured).'];
                continue;
            }

            $data = $this->payload($rowRequest, $identity['sub_institute_id']);
            $data['assigned_to'] = $assignedTo;
            unset($data['sub_institute_id']);
            LeadFactory::create($data, $identity['sub_institute_id'], $identity['user_id']);
            $created++;
            $results[] = ['row' => $index + 1, 'ok' => true];
        }

        return response()->json([
            'status' => 1,
            'message' => $created === count($results) ? "{$created} lead(s) imported." : "{$created} of " . count($results) . ' row(s) imported.',
            'data' => ['created' => $created, 'results' => $results],
        ]);
    }

    /**
     * Convert Lead -> Organization + Contact (+ optional Opportunity).
     *
     * Mirrors the legacy CRM's exact mechanics (confirmed from the legacy
     * source, `include/Webservices/ConvertLead.php`):
     *  - "Link to existing Organization" is never an explicit UI choice —
     *    an exact-name match against `crm_organizations.name` is reused
     *    silently if one exists, otherwise a new Organization is created.
     *  - The lead is never hard-deleted: `converted=1` is set, which the
     *    index() query above already excludes from the default list.
     *  - Default field-mapping table (verbatim from legacy research):
     *    company->accountname, email->email1/email, firstname/lastname->
     *    Contact, industry/phone/fax/rating/website/addresses->Organization,
     *    leadsource->Contact, designation->title.
     *
     * Two Phase-6-audit additions, deliberately NOT mirroring legacy exactly:
     *  - `existingContactId`: unlike Organization's silent name-match, a
     *    Contact is never fuzzy-matched by name (two different people often
     *    share one) - the caller must explicitly pick an existing Contact to
     *    attach to, or a new one is created as before.
     *  - `createOpportunity`: legacy's own Convert-Lead-to-Potential was
     *    excluded when this controller first shipped because the Sales
     *    module didn't exist yet. It does now (crm_opportunities) - off by
     *    default (a converted Lead becoming a pipeline deal is a real
     *    decision, not implied by every conversion), created with no
     *    amount/stage so the rep sets those explicitly afterward.
     */
    public function convert(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $lead = DB::table('crm_leads')
            ->where('id', $id)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();

        if (! $lead) {
            return response()->json(['status' => 0, 'message' => 'Lead not found.'], 404);
        }

        if ($lead->converted) {
            return response()->json(['status' => 0, 'message' => 'This lead has already been converted.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'createOrganization' => 'required|boolean',
            'createContact' => 'required|boolean',
            'existingContactId' => 'nullable|integer',
            'createOpportunity' => 'nullable|boolean',
            'assignedTo' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        if (! $request->boolean('createOrganization') && ! $request->boolean('createContact')) {
            return response()->json(['status' => 0, 'message' => 'Select at least one of Organization or Contact to create.'], 422);
        }

        $existingContactId = $request->filled('existingContactId') ? (int) $request->input('existingContactId') : null;

        if ($existingContactId !== null) {
            $existingContactOk = DB::table('crm_contacts')
                ->where('id', $existingContactId)
                ->where('sub_institute_id', $identity['sub_institute_id'])
                ->whereNull('deleted_at')
                ->exists();

            if (! $existingContactOk) {
                return response()->json(['status' => 0, 'message' => 'That existing Contact was not found.'], 422);
            }
        }

        $assignedTo = (int) $request->input('assignedTo');
        $createOpportunity = $request->boolean('createOpportunity');
        // The conversion itself lives in LeadConverter (moved there unchanged) so the lead-automation workflow runs the same code.
        $converted = LeadConverter::convert($lead, $identity['sub_institute_id'], $identity['user_id'], $assignedTo, $request->boolean('createOrganization'), $request->boolean('createContact'), $existingContactId, $createOpportunity);
        $organizationId = $converted['organization_id'];
        $contactId = $converted['contact_id'];
        $opportunityId = $converted['opportunity_id'];
        LeadConverter::announce($identity['sub_institute_id'], $lead, $converted, $identity['user_id']);

        return response()->json([
            'status' => 1,
            'message' => 'Lead converted.',
            'data' => [
                'organizationId' => $organizationId ? (string) $organizationId : null,
                'contactId' => $contactId ? (string) $contactId : null,
                'opportunityId' => $opportunityId ? (string) $opportunityId : null,
            ],
        ]);
    }

    protected function commentsTable(): string
    {
        return 'crm_lead_comments';
    }

    protected function commentsForeignKey(): string
    {
        return 'lead_id';
    }

    protected function documentsLinkedType(): string
    {
        return 'LEAD';
    }

    protected function emailsLinkedType(): string
    {
        return 'LEAD';
    }

    protected function auditEntityType(): string
    {
        return 'crm_leads';
    }

    /**
     * CrmLeadController has no pre-existing ownRow() (unlike every other CRM
     * controller) - added so HasCrmComments/HasCrmDocuments/HasCrmEmailLinks/
     * HasCrmAuditTrail's own 404 guards and history() action have it.
     */
    private function ownRow(int $tenantId, int $id): ?object
    {
        return DB::table('crm_leads')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, int $subInstituteId, bool $forUpdate = false): array
    {
        $map = [
            'salutation' => 'salutation',
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'company' => 'company',
            'designation' => 'designation',
            'email' => 'email',
            'secondaryEmail' => 'secondary_email',
            'phone' => 'phone',
            'mobile' => 'mobile',
            'fax' => 'fax',
            'website' => 'website',
            'industry' => 'industry',
            'leadSource' => 'lead_source',
            'leadStatus' => 'lead_status',
            'rating' => 'rating',
            'annualRevenue' => 'annual_revenue',
            'numberOfEmp' => 'number_of_emp',
            'emailOptOut' => 'email_opt_out',
            'campaignText' => 'campaign_text',
            'street' => 'street',
            'city' => 'city',
            'state' => 'state',
            'country' => 'country',
            'postalCode' => 'postal_code',
            'poBox' => 'po_box',
            'description' => 'description',
            'assignedTo' => 'assigned_to',
        ];

        $data = [];

        foreach ($map as $requestKey => $column) {
            if ($forUpdate && ! $request->has($requestKey)) {
                continue;
            }

            $data[$column] = $request->input($requestKey);
        }

        // email_opt_out is NOT NULL (default false) - never pass through a
        // bare null for it, which would override that default with an error.
        if (array_key_exists('email_opt_out', $data)) {
            $data['email_opt_out'] = (bool) $data['email_opt_out'];
        }

        if (! $forUpdate) {
            $data['sub_institute_id'] = $subInstituteId;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function resource(object $row): array
    {
        $missing = \App\Domain\Crm\Leads\LeadCompleteness::missing($row);
        $hasRealName = \App\Domain\Crm\Leads\LeadCompleteness::hasRealName($row);
        $enrichmentStatus = $missing === [] ? 'Complete' : ((! empty($row->email) || ! empty($row->phone)) ? 'Partially Complete' : 'Needs Review');

        return [
            'id' => (string) $row->id,
            'leadNo' => $row->lead_no,
            'salutation' => $row->salutation,
            'firstName' => $row->first_name,
            'lastName' => $row->last_name,
            'company' => $row->company,
            'designation' => $row->designation,
            'email' => $row->email,
            'secondaryEmail' => $row->secondary_email,
            'phone' => $row->phone,
            'mobile' => $row->mobile,
            'fax' => $row->fax,
            'website' => $row->website,
            'industry' => $row->industry,
            'leadSource' => $row->lead_source,
            'leadStatus' => $row->lead_status,
            'rating' => $row->rating,
            'leadScore' => (int) $row->lead_score,
            'leadScoreBasis' => $row->lead_score_basis ? json_decode($row->lead_score_basis, true) : [],
            'annualRevenue' => $row->annual_revenue !== null ? (float) $row->annual_revenue : null,
            'numberOfEmp' => $row->number_of_emp,
            'emailOptOut' => (bool) $row->email_opt_out,
            'lifecycleStatus' => $row->lifecycle_status ?? null,
            'nextAction' => $row->next_action ?? null,
            'followUpDueDate' => $row->follow_up_due_date ?? null,
            'consentStatus' => $row->consent_status ?? null,
            'suppressedAt' => $row->suppressed_at ?? null,
            'campaignText' => $row->campaign_text,
            'street' => $row->street,
            'city' => $row->city,
            'state' => $row->state,
            'country' => $row->country,
            'postalCode' => $row->postal_code,
            'poBox' => $row->po_box,
            'converted' => (bool) $row->converted,
            'convertedOrganizationId' => $row->converted_organization_id ? (string) $row->converted_organization_id : null,
            'convertedContactId' => $row->converted_contact_id ? (string) $row->converted_contact_id : null,
            'convertedOpportunityId' => $row->converted_opportunity_id ? (string) $row->converted_opportunity_id : null,
            'sourceCompanyOpportunityId' => isset($row->source_company_opportunity_id) && $row->source_company_opportunity_id ? (string) $row->source_company_opportunity_id : null,
            'description' => $row->description,
            'assignedTo' => $row->assigned_to ? (string) $row->assigned_to : null,
            'createdBy' => $row->created_by ? (string) $row->created_by : null,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
            'missingFields' => $missing,
            'hasRealName' => $hasRealName,
            'enrichmentStatus' => $enrichmentStatus,
            'isQualifiedForOutreach' => $missing === [],
        ];
    }
}
