<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Services\Documents\DocumentAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Personal bookmarks on documents — a Drive-style "Starred", split out as
 * its own small controller the same way DocumentFolderController was split
 * out of DocumentLibraryController: starring is its own cohesive concern,
 * not a reason to keep growing the big one.
 *
 * Gated on `canView()`, not ownership or `canManage()` — you can star
 * anything shared with you, same as Drive lets you star a file someone else
 * owns. No `document_library_history` audit row is written here: starring
 * is personal, frequent, toggled on a whim — writing it to the tenant-wide
 * activity feed (GET /documents/activity) would surface private bookmarking
 * behavior to every other viewer of the same document, for an action that
 * never happened TO the document in any shared sense.
 */
class DocumentStarController extends Controller
{
    use ResolvesApiIdentity;

    /** POST /api/documents/{id}/star — idempotent; starring an already-starred document is a no-op. */
    public function star(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];
        $tenantId = (int) $identity['sub_institute_id'];
        $department = $this->callerDepartment($userId);

        $row = DB::table('document_library')->where('id', (int) $id)->whereNull('deleted_at')->first();

        if (!$row || !DocumentAccess::canView($row, $userId, $tenantId, $department)) {
            return $this->notFound();
        }

        DB::table('document_library_stars')->insertOrIgnore([
            'sub_institute_id' => $tenantId,
            'document_id' => $row->id,
            'user_id' => $userId,
            'created_at' => now(),
        ]);

        return response()->json(['status' => 1, 'message' => 'Starred.']);
    }

    /** DELETE /api/documents/{id}/star — idempotent the same way. */
    public function unstar(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];

        DB::table('document_library_stars')
            ->where('document_id', (int) $id)
            ->where('user_id', $userId)
            ->delete();

        return response()->json(['status' => 1, 'message' => 'Unstarred.']);
    }

    /** GET /api/documents/starred — this caller's starred documents, newest-starred first. */
    public function starred(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];
        $tenantId = (int) $identity['sub_institute_id'];
        $department = $this->callerDepartment($userId);

        $starredAt = DB::table('document_library_stars')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(200)
            ->pluck('created_at', 'document_id');

        if ($starredAt->isEmpty()) {
            return response()->json(['status' => 1, 'data' => []]);
        }

        $query = DB::table('document_library')->whereIn('id', $starredAt->keys())->whereNull('deleted_at');
        DocumentAccess::visibleTo($query, $userId, $tenantId, $department);

        $rows = $query->get([
            'id', 'title', 'original_file_name', 'mime_type', 'size', 'category',
            'document_type', 'department_id', 'document_date', 'period_label',
            'visibility', 'owner_id', 'source_system', 'tags', 'created_at',
            'processing_status',
        ]);

        $data = $rows->map(function ($row) {
            $out = (array) $row;
            $out['snippet'] = null;
            $out['starred'] = true;

            return $out;
        })->sortByDesc(fn ($row) => $starredAt[$row['id']])->values()->all();

        return response()->json(['status' => 1, 'data' => $data]);
    }

    private function callerDepartment(int $userId): ?int
    {
        $value = DB::table('tbluser')->where('id', $userId)->value('department_id');

        return $value ? (int) $value : null;
    }

    /** 404 for both "no such document" and "not yours to star" — never 403, which would confirm cross-tenant existence. */
    private function notFound()
    {
        return response()->json(['status' => 0, 'message' => 'That document was not found.'], 404);
    }
}
