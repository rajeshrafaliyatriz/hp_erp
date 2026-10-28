<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Services\Documents\DocumentAccess;
use App\Services\Documents\DocumentFolderShareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Per-person FOLDER sharing - `DocumentShareController`'s twin. All three
 * actions are gated on DocumentAccess::canManageFolder() - the same check
 * DocumentFolderController's own mutating actions (destroy/move/update) use.
 */
class DocumentFolderShareController extends Controller
{
    use ResolvesApiIdentity;

    public function __construct(private readonly DocumentFolderShareService $shares)
    {
    }

    /** GET /api/account/documents/folders/{id}/shares — people with access. */
    public function index(Request $request, $id)
    {
        [$row, $error] = $this->authorize($request, $id);

        if ($error) {
            return $error;
        }

        $rows = $this->shares->listShares((int) $row->id)->map(fn ($r) => [
            'id' => (string) $r->id,
            'viewer_user_id' => (string) $r->viewer_user_id,
            'viewer_name' => $r->viewer_name ?: 'Unknown',
            'can_edit' => (bool) $r->can_edit,
        ]);

        return response()->json(['status' => 1, 'data' => ['shares' => $rows]]);
    }

    /** POST /api/account/documents/folders/{id}/shares — share with a person. */
    public function store(Request $request, $id)
    {
        [$row, $error, $identity] = $this->authorize($request, $id);

        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'viewer_user_id' => 'required|integer|min:1',
            'can_edit' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $viewerUserId = (int) $request->input('viewer_user_id');

        if ($viewerUserId === (int) ($row->owner_id ?? 0)) {
            return response()->json(['status' => 0, 'message' => 'This person already owns the folder.'], 422);
        }

        $viewerInTenant = DB::table('tbluser')
            ->where('id', $viewerUserId)->where('sub_institute_id', (int) $identity['sub_institute_id'])
            ->whereNull('deleted_at')->exists();

        if (!$viewerInTenant) {
            return $this->notFound();
        }

        $this->shares->share($row->id, $viewerUserId, $request->boolean('can_edit'), (int) $identity['sub_institute_id'], (int) $identity['user_id']);

        return response()->json(['status' => 1, 'message' => 'Folder shared.'], 201);
    }

    /** DELETE /api/account/documents/folders/{id}/shares/{shareId} — unshare. */
    public function destroy(Request $request, $id, $shareId)
    {
        [$row, $error] = $this->authorize($request, $id);

        if ($error) {
            return $error;
        }

        return $this->shares->unshare($row->id, (int) $shareId)
            ? response()->json(['status' => 1, 'message' => 'Share removed.'])
            : $this->notFound();
    }

    /**
     * Resolves identity, fetches the folder (tenant-scoped, not deleted), and
     * checks canManageFolder() - the one gate shared by all three actions
     * above, same as DocumentFolderController's own mutating actions.
     */
    private function authorize(Request $request, $id): array
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return [null, $identity, null];
        }

        $userId = (int) $identity['user_id'];
        $tenantId = (int) $identity['sub_institute_id'];
        $department = $this->callerDepartment($userId);

        $row = DB::table('document_folders')
            ->where('id', (int) $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();

        if (!$row) {
            return [null, $this->notFound(), null];
        }

        if (!DocumentAccess::canManageFolder($row, $userId, $tenantId, $department)) {
            // Never 403 - matches DocumentFolderController/DocumentShareController's
            // own convention, so a caller probing another tenant's folder id
            // can't tell "doesn't exist" apart from "exists, not yours."
            return [null, $this->notFound(), null];
        }

        return [$row, null, $identity];
    }

    private function callerDepartment(int $userId): ?int
    {
        $value = DB::table('tbluser')->where('id', $userId)->value('department_id');

        return $value ? (int) $value : null;
    }

    private function notFound()
    {
        return response()->json(['status' => 0, 'message' => 'That folder was not found.'], 404);
    }
}
