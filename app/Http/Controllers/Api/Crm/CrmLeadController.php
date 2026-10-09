<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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
            'lead_source', 'rating', 'created_at',
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
            'assignedTo' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $data = $this->payload($request, $identity['sub_institute_id']);
        $data['lead_no'] = $this->nextLeadNo($identity['sub_institute_id']);
        $data['created_by'] = $identity['user_id'];
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $id = DB::table('crm_leads')->insertGetId($data);

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

        DB::table('crm_leads')->where('id', $id)->update($data);

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

        return response()->json(['status' => 1, 'message' => 'Lead moved to Recycle Bin.']);
    }

    /**
     * Convert Lead -> Organization + Contact.
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
     *  - Convert Lead -> Potential/Deal is explicitly out of scope — Sales
     *    module not yet migrated (see this plan's Feature Parity Checklist).
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
            'assignedTo' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        if (! $request->boolean('createOrganization') && ! $request->boolean('createContact')) {
            return response()->json(['status' => 0, 'message' => 'Select at least one of Organization or Contact to create.'], 422);
        }

        $assignedTo = (int) $request->input('assignedTo');
        $organizationId = null;
        $contactId = null;

        DB::transaction(function () use ($request, $lead, $identity, $assignedTo, &$organizationId, &$contactId) {
            if ($request->boolean('createOrganization') && $lead->company) {
                $existingOrg = DB::table('crm_organizations')
                    ->where('sub_institute_id', $identity['sub_institute_id'])
                    ->where('name', $lead->company)
                    ->whereNull('deleted_at')
                    ->first();

                if ($existingOrg) {
                    $organizationId = $existingOrg->id;
                } else {
                    $organizationId = DB::table('crm_organizations')->insertGetId([
                        'account_no' => 'ORG-' . Str::upper(Str::random(8)),
                        'name' => $lead->company,
                        'industry' => $lead->industry,
                        'phone' => $lead->phone,
                        'fax' => $lead->fax,
                        'rating' => $lead->rating,
                        'website' => $lead->website,
                        'billing_city' => $lead->city,
                        'billing_state' => $lead->state,
                        'billing_country' => $lead->country,
                        'billing_code' => $lead->postal_code,
                        'billing_street' => $lead->street,
                        'billing_po_box' => $lead->po_box,
                        'sub_institute_id' => $identity['sub_institute_id'],
                        'assigned_to' => $assignedTo,
                        'created_by' => $identity['user_id'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            if ($request->boolean('createContact')) {
                $contactId = DB::table('crm_contacts')->insertGetId([
                    'contact_no' => 'CON-' . Str::upper(Str::random(8)),
                    'organization_id' => $organizationId,
                    'salutation' => $lead->salutation,
                    'first_name' => $lead->first_name,
                    'last_name' => $lead->last_name,
                    'title' => $lead->designation ?? null,
                    'email' => $lead->email,
                    'secondary_email' => $lead->secondary_email,
                    'phone' => $lead->phone,
                    'mobile' => $lead->mobile,
                    'fax' => $lead->fax,
                    'lead_source' => $lead->lead_source,
                    'email_opt_out' => $lead->email_opt_out,
                    'mailing_city' => $lead->city,
                    'mailing_state' => $lead->state,
                    'mailing_country' => $lead->country,
                    'mailing_code' => $lead->postal_code,
                    'mailing_street' => $lead->street,
                    'mailing_po_box' => $lead->po_box,
                    'description' => $lead->description,
                    'sub_institute_id' => $identity['sub_institute_id'],
                    'assigned_to' => $assignedTo,
                    'created_by' => $identity['user_id'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('crm_leads')->where('id', $lead->id)->update([
                'converted' => true,
                'converted_organization_id' => $organizationId,
                'converted_contact_id' => $contactId,
                'updated_by' => $identity['user_id'],
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'status' => 1,
            'message' => 'Lead converted.',
            'data' => [
                'organizationId' => $organizationId ? (string) $organizationId : null,
                'contactId' => $contactId ? (string) $contactId : null,
            ],
        ]);
    }

    private function nextLeadNo(int $subInstituteId): string
    {
        $max = (int) DB::table('crm_leads')
            ->where('sub_institute_id', $subInstituteId)
            ->max(DB::raw("CAST(SUBSTRING_INDEX(lead_no, '-', -1) AS UNSIGNED)"));

        return 'LD-' . str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, int $subInstituteId, bool $forUpdate = false): array
    {
        $map = [
            'salutation' => 'salutation',
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'company' => 'company',
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
        return [
            'id' => (string) $row->id,
            'leadNo' => $row->lead_no,
            'salutation' => $row->salutation,
            'firstName' => $row->first_name,
            'lastName' => $row->last_name,
            'company' => $row->company,
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
            'annualRevenue' => $row->annual_revenue !== null ? (float) $row->annual_revenue : null,
            'numberOfEmp' => $row->number_of_emp,
            'emailOptOut' => (bool) $row->email_opt_out,
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
            'description' => $row->description,
            'assignedTo' => $row->assigned_to ? (string) $row->assigned_to : null,
            'createdBy' => $row->created_by ? (string) $row->created_by : null,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }
}
