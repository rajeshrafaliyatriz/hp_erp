<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmBulkActions;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmDuplicateDetection;
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
 * Vendors - the legacy CRM's `vtiger_vendor`, relabeled. Simple master-data
 * entity (see `crm_vendors`' own migration docblock): one flat address
 * block, no status/workflow field, no line items. Closest in CRUD shape to
 * CrmOrganizationController, not CrmQuoteController - but the payload/
 * resource/baseQuery/numbering/soft-delete MECHANICS below are copied from
 * Quote's own template, same as every other CRM controller in this phase.
 *
 * Unlike Organization (which blocks a same-name insert outright), a Vendor
 * has no store()-time uniqueness guard - same reasoning this codebase
 * already used for Products: catching an accidental re-entry is
 * duplicates()/merge()'s job, not a hard insert-time block, since a vendor
 * name collision is sometimes legitimate (two different suppliers can share
 * a common business name).
 */
class CrmVendorController extends Controller
{
    use ResolvesApiIdentity;
    use HasCrmBulkActions;
    use HasCrmDuplicateDetection;
    use HasCrmMerge;
    use HasCrmExport;

    /** @var array<string, string> db column => CSV header, in export column order. */
    private const EXPORT_COLUMNS = [
        'name' => 'Name', 'category' => 'Category', 'phone' => 'Phone', 'email' => 'Email',
        'website' => 'Website', 'gl_account' => 'GL Account', 'street' => 'Street', 'city' => 'City',
        'state' => 'State', 'country' => 'Country', 'postal_code' => 'Postal Code', 'po_box' => 'PO Box',
        'description' => 'Description', 'is_active' => 'Active', 'assigned_to' => 'Assigned To (user id)',
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
            $query->where(function ($q) use ($search) {
                $q->where('v.name', 'like', "%{$search}%")
                    ->orWhere('v.email', 'like', "%{$search}%")
                    ->orWhere('v.phone', 'like', "%{$search}%");
            });
        }

        if ($category = $request->input('category')) {
            $query->where('v.category', $category);
        }

        if ($request->filled('isActive')) {
            $query->where('v.is_active', $request->boolean('isActive'));
        }

        $sortableColumns = ['name', 'category', 'phone', 'email', 'created_at'];
        $sortBy = in_array($request->input('sort_by'), $sortableColumns, true) ? $request->input('sort_by') : 'name';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'desc' ? 'desc' : 'asc';

        $total = (clone $query)->count();
        $rows = $query->orderBy('v.' . $sortBy, $sortDir)->forPage($page, $perPage)->get();

        return response()->json([
            'status' => 1,
            'message' => 'Vendors.',
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
            return response()->json(['status' => 0, 'message' => 'Vendor not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Vendor.', 'data' => $this->resource($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:191',
            'email' => 'nullable|email|max:191',
            'assignedTo' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $data = $this->payload($request);
        // is_active is NOT NULL with a DB-level default of true - that
        // default only applies when the column is OMITTED from the insert
        // entirely, not when NULL is passed explicitly. payload() always
        // includes the key on store() (forUpdate skips absent keys, store()
        // doesn't), so an untouched client field becomes an explicit `null`
        // here - same failure mode CrmQuoteController::store() already
        // documents for shipping_handling_amount/adjustment.
        $data['is_active'] = $data['is_active'] ?? true;
        $data['vendor_no'] = 'VEN-' . Str::upper(Str::random(8));
        $data['sub_institute_id'] = $identity['sub_institute_id'];
        $data['created_by'] = $identity['user_id'];
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $id = DB::table('crm_vendors')->insertGetId($data);
        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Vendor created.', 'data' => $this->resource($row)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Vendor not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:191',
            'email' => 'nullable|email|max:191',
            'assignedTo' => 'sometimes|required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $data = $this->payload($request, forUpdate: true);
        $data['updated_by'] = $identity['user_id'];
        $data['updated_at'] = now();

        DB::table('crm_vendors')->where('id', $id)->update($data);
        $row = $this->ownRowJoined($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Vendor updated.', 'data' => $this->resource($row)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Vendor not found.'], 404);
        }

        DB::table('crm_vendors')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $identity['user_id'],
        ]);

        return response()->json(['status' => 1, 'message' => 'Vendor moved to Recycle Bin.']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkDeleteRows($request, 'crm_vendors', $identity['sub_institute_id'], $identity['user_id'], 'Vendor');
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkAssignRows($request, 'crm_vendors', $identity['sub_institute_id'], $identity['user_id'], 'Vendor');
    }

    // ── Contacts junction ──────────────────────────────────────────────
    // Mirrors CrmOpportunityController::contacts()/addContact()/
    // removeContact() exactly - crm_vendor_contacts in place of
    // crm_opportunity_contacts, vendor_id in place of opportunity_id.

    public function contacts(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Vendor not found.'], 404);
        }

        $rows = DB::table('crm_vendor_contacts as vc')
            ->join('crm_contacts as c', 'c.id', '=', 'vc.contact_id')
            ->where('vc.vendor_id', $id)
            ->whereNull('c.deleted_at')
            ->select('vc.id as row_id', 'c.id as contact_id', 'c.first_name', 'c.last_name', 'c.title', 'c.email')
            ->get();

        return response()->json([
            'status' => 1,
            'message' => 'Vendor contacts.',
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
            return response()->json(['status' => 0, 'message' => 'Vendor not found.'], 404);
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

        $alreadyLinked = DB::table('crm_vendor_contacts')->where('vendor_id', $id)->where('contact_id', $contactId)->exists();

        if (! $alreadyLinked) {
            DB::table('crm_vendor_contacts')->insert(['vendor_id' => $id, 'contact_id' => $contactId, 'created_at' => now()]);
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
            return response()->json(['status' => 0, 'message' => 'Vendor not found.'], 404);
        }

        DB::table('crm_vendor_contacts')->where('id', $rowId)->where('vendor_id', $id)->delete();

        return response()->json(['status' => 1, 'message' => 'Contact removed.']);
    }

    /**
     * Possible duplicates: a same (normalized) name, website, or phone
     * number. Unlike Organization, `name` IS a signal here - store() applies
     * no uniqueness guard for Vendor, so an exact name collision can
     * genuinely exist going forward (same reasoning CrmProductController's
     * own duplicates() already uses for `name`/`sku`).
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
            $this->duplicateGroups('crm_vendors', $tenantId, 'LOWER(TRIM(name))', ['name'], 'Same name', $resource),
            $this->duplicateGroups('crm_vendors', $tenantId, 'LOWER(TRIM(website))', ['website'], 'Same website', $resource),
            $this->duplicateGroups('crm_vendors', $tenantId, 'LOWER(TRIM(phone))', ['phone'], 'Same phone number', $resource),
        );

        return response()->json(['status' => 1, 'message' => 'Possible duplicate vendors.', 'data' => $groups]);
    }

    public function merge(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->mergeRows(
            $request, 'crm_vendors', $identity['sub_institute_id'], $identity['user_id'], 'Vendor',
            repoint: function (int $fromId, int $toId) {
                // crm_vendor_contacts has a unique (vendor_id, contact_id)
                // pair - if the survivor already links the same contact,
                // the duplicate's own link row is simply dropped instead of
                // erroring, same pattern HasCrmMerge::repointCampaignTargets()
                // already uses for crm_campaign_targets.
                $rows = DB::table('crm_vendor_contacts')->where('vendor_id', $fromId)->get();

                foreach ($rows as $row) {
                    $alreadyLinked = DB::table('crm_vendor_contacts')
                        ->where('vendor_id', $toId)
                        ->where('contact_id', $row->contact_id)
                        ->exists();

                    if ($alreadyLinked) {
                        DB::table('crm_vendor_contacts')->where('id', $row->id)->delete();
                    } else {
                        DB::table('crm_vendor_contacts')->where('id', $row->id)->update(['vendor_id' => $toId]);
                    }
                }
            },
        );
    }

    /** Exports the same rows index() would list (search applied). */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $query = DB::table('crm_vendors')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return $this->exportCsv($query, self::EXPORT_COLUMNS, 'vendors.csv');
    }

    /** CSV import - header fields only, same as every other CRM import. A missing/invalid owner rejects the row (no fallback). */
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
                'email' => 'nullable|email|max:191',
                'assignedTo' => 'required|integer',
            ]);

            if ($rowValidator->fails()) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => $rowValidator->errors()->first()];
                continue;
            }

            $data = $this->payload($rowRequest);
            $data['is_active'] = $data['is_active'] ?? true;
            $data['vendor_no'] = 'VEN-' . Str::upper(Str::random(8));
            $data['sub_institute_id'] = $identity['sub_institute_id'];
            $data['created_by'] = $identity['user_id'];
            $data['created_at'] = now();
            $data['updated_at'] = now();

            DB::table('crm_vendors')->insert($data);
            $created++;
            $results[] = ['row' => $index + 1, 'ok' => true];
        }

        return response()->json([
            'status' => 1,
            'message' => $created === count($results) ? "{$created} vendor(s) imported." : "{$created} of " . count($results) . ' row(s) imported.',
            'data' => ['created' => $created, 'results' => $results],
        ]);
    }

    private function baseQuery(int $tenantId)
    {
        return DB::table('crm_vendors as v')
            ->leftJoin('tbluser as u', 'u.id', '=', 'v.assigned_to')
            ->where('v.sub_institute_id', $tenantId)
            ->whereNull('v.deleted_at')
            ->select('v.*', DB::raw("TRIM(CONCAT(u.first_name, ' ', u.last_name)) as assigned_to_name"));
    }

    private function ownRow(int $tenantId, int $id): ?object
    {
        return DB::table('crm_vendors')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    private function ownRowJoined(int $tenantId, int $id): ?object
    {
        return $this->baseQuery($tenantId)->where('v.id', $id)->first();
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, bool $forUpdate = false): array
    {
        $map = [
            'name' => 'name', 'phone' => 'phone', 'email' => 'email', 'website' => 'website',
            'glAccount' => 'gl_account', 'category' => 'category', 'street' => 'street', 'city' => 'city',
            'state' => 'state', 'country' => 'country', 'postalCode' => 'postal_code', 'poBox' => 'po_box',
            'description' => 'description', 'isActive' => 'is_active', 'assignedTo' => 'assigned_to',
        ];

        $data = [];

        foreach ($map as $requestKey => $column) {
            if ($forUpdate && ! $request->has($requestKey)) {
                continue;
            }

            $data[$column] = $request->input($requestKey);
        }

        if (array_key_exists('is_active', $data) && $data['is_active'] !== null) {
            $data['is_active'] = (bool) $data['is_active'];
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function resource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'legacyId' => $row->legacy_id ? (string) $row->legacy_id : null,
            'vendorNo' => $row->vendor_no,
            'name' => $row->name,
            'phone' => $row->phone,
            'email' => $row->email,
            'website' => $row->website,
            'glAccount' => $row->gl_account,
            'category' => $row->category,
            'street' => $row->street,
            'city' => $row->city,
            'state' => $row->state,
            'country' => $row->country,
            'postalCode' => $row->postal_code,
            'poBox' => $row->po_box,
            'description' => $row->description,
            'isActive' => (bool) $row->is_active,
            'assignedTo' => $row->assigned_to ? (string) $row->assigned_to : null,
            'assignedToName' => ($row->assigned_to_name ?? '') !== '' ? $row->assigned_to_name : null,
            'createdBy' => $row->created_by ? (string) $row->created_by : null,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }
}
