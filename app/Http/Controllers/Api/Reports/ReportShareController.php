<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Services\Reports\ReportShareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportShareController extends Controller
{
    use ResolvesApiIdentity;

    public function __construct(private readonly ReportShareService $shares)
    {
    }

    /** GET /api/reports/{id}/shares */
    public function index(Request $request, int $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $report = $this->ownedReport($id, (int) $identity['sub_institute_id'], (int) $identity['user_id']);

        if (!$report) {
            return response()->json(['status' => false, 'message' => 'Report not found.'], 404);
        }

        return response()->json(['status' => true, 'data' => ['shares' => $this->shares->listShares($id)]]);
    }

    /** POST /api/reports/{id}/shares */
    public function store(Request $request, int $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];
        $report = $this->ownedReport($id, $tenantId, (int) $identity['user_id']);

        if (!$report) {
            return response()->json(['status' => false, 'message' => 'Report not found.'], 404);
        }

        $data = $request->validate([
            'viewer_user_id' => ['required', 'integer'],
            'can_edit' => ['nullable', 'boolean'],
        ]);

        $viewerBelongsToTenant = DB::table('tbluser')
            ->where('id', $data['viewer_user_id'])
            ->where('sub_institute_id', $tenantId)
            ->exists();

        if (!$viewerBelongsToTenant) {
            return response()->json(['status' => false, 'message' => 'That person is not in this organisation.'], 422);
        }

        $this->shares->share($id, (int) $data['viewer_user_id'], (bool) ($data['can_edit'] ?? false), $tenantId, (int) $identity['user_id']);

        return response()->json(['status' => true, 'message' => 'Report shared.'], 201);
    }

    /** DELETE /api/reports/{id}/shares/{shareId} */
    public function destroy(Request $request, int $id, int $shareId)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $report = $this->ownedReport($id, (int) $identity['sub_institute_id'], (int) $identity['user_id']);

        if (!$report) {
            return response()->json(['status' => false, 'message' => 'Report not found.'], 404);
        }

        $this->shares->unshare($id, $shareId);

        return response()->json(['status' => true, 'message' => 'Share removed.']);
    }

    /** Only the owner may manage sharing — an edit-level share lets you edit the report's content, not re-share it. */
    private function ownedReport(int $id, int $tenantId, int $userId): ?object
    {
        return DB::table('reports')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->where('owner_id', $userId)
            ->whereNull('deleted_at')
            ->first(['id']);
    }
}
