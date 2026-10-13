<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Services\Documents\DocumentAccess;
use App\Services\Documents\DocumentShareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Per-person document sharing - the "People with access" list and controls
 * in the Share dialog. All three actions are gated on
 * DocumentAccess::canManage() (owner, creator, or an elevated role) - the
 * same check DocumentLibraryController::update() uses, since managing who
 * else can see a document is a sharing-management action, not something a
 * shared editor gets to do to their own grant. See
 * DocumentLibraryController::update()'s own docblock for the counterpart
 * that lets a `can_edit` share actually edit the document itself.
 */
class DocumentShareController extends Controller
{
    use ResolvesApiIdentity;

    public function __construct(private readonly DocumentShareService $shares)
    {
    }

    /** GET /api/account/documents/{id}/shares — people with access. */
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

    /** POST /api/account/documents/{id}/shares — share with a person. */
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

        if ($viewerUserId === (int) $row->owner_id) {
            return response()->json(['status' => 0, 'message' => 'This person already owns the document.'], 422);
        }

        $viewerInTenant = DB::table('tbluser')
            ->where('id', $viewerUserId)->where('sub_institute_id', (int) $identity['sub_institute_id'])
            ->whereNull('deleted_at')->exists();

        if (!$viewerInTenant) {
            return $this->notFound();
        }

        $this->shares->share($row->id, $viewerUserId, $request->boolean('can_edit'), (int) $identity['sub_institute_id'], (int) $identity['user_id']);

        return response()->json(['status' => 1, 'message' => 'Document shared.'], 201);
    }

    /** DELETE /api/account/documents/{id}/shares/{shareId} — unshare. */
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
     * Resolves identity, fetches the document (tenant-scoped, not deleted),
     * and checks canManage() - the one gate shared by all three actions
     * above. Returns [row, null, identity] on success, or [null, response,
     * null] on failure (identity omitted there since index()/destroy() don't
     * need it on the failure path).
     */
    private function authorize(Request $request, $id): array
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return [null, $identity, null];
        }

        $userId = (int) $identity['user_id'];
        $tenantId = (int) $identity['sub_institute_id'];

        $row = DB::table('document_library')
            ->where('id', (int) $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();

        if (!$row) {
            return [null, $this->notFound(), null];
        }

        if (!DocumentAccess::canManage($row, $userId, $tenantId)) {
            // Same reasoning as DocumentLibraryController::notFound() - 404,
            // never 403, so a caller probing another tenant's document id
            // can't tell "doesn't exist" apart from "exists, not yours."
            return [null, $this->notFound(), null];
        }

        return [$row, null, $identity];
    }

    private function notFound()
    {
        return response()->json(['status' => 0, 'message' => 'That document was not found.'], 404);
    }
}
