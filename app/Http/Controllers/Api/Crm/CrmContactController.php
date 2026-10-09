<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CrmContactController extends Controller
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

        $query = DB::table('crm_contacts as c')
            ->leftJoin('crm_organizations as o', 'o.id', '=', 'c.organization_id')
            ->where('c.sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('c.deleted_at')
            ->select('c.*', 'o.name as organization_name');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('c.first_name', 'like', "%{$search}%")
                    ->orWhere('c.last_name', 'like', "%{$search}%")
                    ->orWhere('c.email', 'like', "%{$search}%")
                    ->orWhere('o.name', 'like', "%{$search}%");
            });
        }

        if ($organizationId = $request->input('organization_id')) {
            $query->where('c.organization_id', $organizationId);
        }

        $sortableColumns = ['first_name', 'last_name', 'email', 'title', 'created_at'];
        $sortBy = in_array($request->input('sort_by'), $sortableColumns, true) ? 'c.' . $request->input('sort_by') : 'c.last_name';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'desc' ? 'desc' : 'asc';

        $total = (clone $query)->count();
        $rows = $query->orderBy($sortBy, $sortDir)->forPage($page, $perPage)->get();

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

        $validator = Validator::make($request->all(), [
            'lastName' => 'required|string|max:191',
            'email' => 'nullable|email|max:191',
            'assignedTo' => 'required|integer',
            'organizationId' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $data = $this->payload($request);
        $data['contact_no'] = 'CON-' . Str::upper(Str::random(8));
        $data['sub_institute_id'] = $identity['sub_institute_id'];
        $data['created_by'] = $identity['user_id'];
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $id = DB::table('crm_contacts')->insertGetId($data);
        $row = $this->ownRowWithOrganization($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Contact created.', 'data' => $this->resource($row)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Contact not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'lastName' => 'sometimes|required|string|max:191',
            'email' => 'nullable|email|max:191',
            'assignedTo' => 'sometimes|required|integer',
            'organizationId' => 'nullable|integer',
            'reportsToId' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        if ($request->filled('reportsToId') && (int) $request->input('reportsToId') === $id) {
            return response()->json(['status' => 0, 'message' => 'A contact cannot report to themselves.'], 422);
        }

        $data = $this->payload($request, forUpdate: true);
        $data['updated_by'] = $identity['user_id'];
        $data['updated_at'] = now();

        DB::table('crm_contacts')->where('id', $id)->update($data);
        $row = $this->ownRowWithOrganization($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Contact updated.', 'data' => $this->resource($row)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Contact not found.'], 404);
        }

        DB::table('crm_contacts')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $identity['user_id'],
        ]);

        return response()->json(['status' => 1, 'message' => 'Contact moved to Recycle Bin.']);
    }

    public function transferOwnership(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
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

        return response()->json(['status' => 1, 'message' => 'Ownership transferred.']);
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
        return DB::table('crm_contacts as c')
            ->leftJoin('crm_organizations as o', 'o.id', '=', 'c.organization_id')
            ->where('c.id', $id)
            ->where('c.sub_institute_id', $tenantId)
            ->whereNull('c.deleted_at')
            ->select('c.*', 'o.name as organization_name')
            ->first();
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
        ];

        $data = [];

        foreach ($map as $requestKey => $column) {
            if ($forUpdate && ! $request->has($requestKey)) {
                continue;
            }

            $data[$column] = $request->input($requestKey);
        }

        foreach (['email_opt_out', 'do_not_call'] as $boolColumn) {
            if (array_key_exists($boolColumn, $data)) {
                $data[$boolColumn] = (bool) $data[$boolColumn];
            }
        }

        foreach (['organization_id', 'reports_to_id'] as $nullableIdColumn) {
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
            'contactNo' => $row->contact_no,
            'organizationId' => $row->organization_id ? (string) $row->organization_id : null,
            'organizationName' => $row->organization_name ?? null,
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
            'birthday' => $row->birthday,
            'leadSource' => $row->lead_source,
            'doNotCall' => (bool) $row->do_not_call,
            'emailOptOut' => (bool) $row->email_opt_out,
            'mailingStreet' => $row->mailing_street,
            'mailingCity' => $row->mailing_city,
            'mailingState' => $row->mailing_state,
            'mailingCode' => $row->mailing_code,
            'mailingCountry' => $row->mailing_country,
            'mailingPoBox' => $row->mailing_po_box,
            'otherStreet' => $row->other_street,
            'otherCity' => $row->other_city,
            'otherState' => $row->other_state,
            'otherCode' => $row->other_code,
            'otherCountry' => $row->other_country,
            'otherPoBox' => $row->other_po_box,
            'description' => $row->description,
            'assignedTo' => $row->assigned_to ? (string) $row->assigned_to : null,
            'createdBy' => $row->created_by ? (string) $row->created_by : null,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }
}
