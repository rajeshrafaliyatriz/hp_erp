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
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Email Templates - legacy CRM's `vtiger_emailtemplates`: a small, reusable
 * subject+body library (folder-organized, same flat-label `folder` as
 * `crm_email_templates`'s own migration docblock) that a CRM Email can
 * optionally be composed from (see CrmEmailController::compose()'s own
 * `templateId` field).
 *
 * A standalone admin library, not a transactional record - no numbering
 * (*_no column, unlike Quote/Ticket/SalesOrder/.../`*-no` convention), no
 * PDF/line-items/stage-history. Full CRUD shape otherwise mirrors
 * CrmQuoteController's own (resolveApiIdentity -> validate -> payload() ->
 * resource()), soft-deleted via the standard deleted_at/deleted_by trailer
 * its own migration already carries.
 */
class CrmEmailTemplateController extends Controller
{
    use ResolvesApiIdentity;
    use HasCrmBulkActions;
    use HasCrmExport;

    /** @var array<string, string> db column => CSV header, in export column order. */
    private const EXPORT_COLUMNS = [
        'name' => 'Name', 'folder' => 'Folder', 'subject' => 'Subject',
        'body' => 'Body', 'assigned_to' => 'Assigned To (user id)',
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
            $query->where('name', 'like', "%{$search}%");
        }

        if ($folder = $request->input('folder')) {
            $query->where('folder', $folder);
        }

        $sortableColumns = ['name', 'folder', 'created_at'];
        $sortBy = in_array($request->input('sort_by'), $sortableColumns, true) ? $request->input('sort_by') : 'name';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'desc' ? 'desc' : 'asc';

        $total = (clone $query)->count();
        $rows = $query->orderBy($sortBy, $sortDir)->forPage($page, $perPage)->get();

        return response()->json([
            'status' => 1,
            'message' => 'Email templates.',
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

        $row = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Email template not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Email template.', 'data' => $this->resource($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:191',
            'folder' => 'nullable|string|max:100',
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'assignedTo' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $data = $this->payload($request);
        $data['sub_institute_id'] = $identity['sub_institute_id'];
        $data['created_by'] = $identity['user_id'];
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $id = DB::table('crm_email_templates')->insertGetId($data);
        $row = $this->ownRow($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Email template created.', 'data' => $this->resource($row)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Email template not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:191',
            'folder' => 'nullable|string|max:100',
            'subject' => 'sometimes|required|string|max:255',
            'body' => 'sometimes|required|string',
            'assignedTo' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $data = $this->payload($request, forUpdate: true);
        $data['updated_by'] = $identity['user_id'];
        $data['updated_at'] = now();

        DB::table('crm_email_templates')->where('id', $id)->update($data);
        $row = $this->ownRow($identity['sub_institute_id'], $id);

        return response()->json(['status' => 1, 'message' => 'Email template updated.', 'data' => $this->resource($row)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Email template not found.'], 404);
        }

        DB::table('crm_email_templates')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $identity['user_id'],
        ]);

        return response()->json(['status' => 1, 'message' => 'Email template moved to Recycle Bin.']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkDeleteRows($request, 'crm_email_templates', $identity['sub_institute_id'], $identity['user_id'], 'Email template');
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkAssignRows($request, 'crm_email_templates', $identity['sub_institute_id'], $identity['user_id'], 'Email template');
    }

    /** Exports the same rows index() would list (search/folder applied). */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $query = DB::table('crm_email_templates')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($folder = $request->input('folder')) {
            $query->where('folder', $folder);
        }

        return $this->exportCsv($query, self::EXPORT_COLUMNS, 'email-templates.csv');
    }

    private function baseQuery(int $tenantId)
    {
        return DB::table('crm_email_templates')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at');
    }

    private function ownRow(int $tenantId, int $id): ?object
    {
        return DB::table('crm_email_templates')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, bool $forUpdate = false): array
    {
        $map = [
            'name' => 'name', 'folder' => 'folder', 'subject' => 'subject',
            'body' => 'body', 'assignedTo' => 'assigned_to',
        ];

        $data = [];

        foreach ($map as $requestKey => $column) {
            if ($forUpdate && ! $request->has($requestKey)) {
                continue;
            }

            $data[$column] = $request->input($requestKey);
        }

        if (array_key_exists('assigned_to', $data) && $data['assigned_to'] === '') {
            $data['assigned_to'] = null;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function resource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'legacyId' => $row->legacy_id !== null ? (string) $row->legacy_id : null,
            'name' => $row->name,
            'folder' => $row->folder,
            'subject' => $row->subject,
            'body' => $row->body,
            'assignedTo' => $row->assigned_to ? (string) $row->assigned_to : null,
            'createdBy' => $row->created_by ? (string) $row->created_by : null,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }
}
