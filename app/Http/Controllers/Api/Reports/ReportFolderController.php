<?php

namespace App\Http\Controllers\Api\Reports;

use App\Domain\Reports\SystemReportFolders;
use App\Domain\Reports\SystemReportSeeds;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Report folders — the left-rail categories. "All Reports" and "Shared
 * With Me" are deliberately absent here (computed views served by
 * ReportController::index, see Docs/reports-engine/PLAN.md §2/§4) rather
 * than rows a caller could fetch/edit/delete through this controller.
 */
class ReportFolderController extends Controller
{
    use ResolvesApiIdentity;

    private const SCOPES = ['task_management', 'crm'];

    /** GET /api/reports/folders?scope={task_management|crm} */
    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $scope = $this->requireScope($request);

        if ($scope instanceof \Illuminate\Http\JsonResponse) {
            return $scope;
        }

        SystemReportFolders::ensure((int) $identity['sub_institute_id'], $scope);
        SystemReportSeeds::ensure((int) $identity['sub_institute_id'], (int) $identity['user_id'], $scope);

        $folders = DB::table('report_folders')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->where('module_scope', $scope)
            ->whereNull('deleted_at')
            ->orderBy('is_system', 'desc')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'is_system', 'owner_id', 'sort_order']);

        return response()->json(['status' => true, 'data' => ['folders' => $folders]]);
    }

    /** POST /api/reports/folders — a custom folder only; system folders are seeded, never created here. */
    public function store(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $scope = $this->requireScope($request);

        if ($scope instanceof \Illuminate\Http\JsonResponse) {
            return $scope;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191', function ($attribute, $value, $fail) {
                // Mirrors the real vtiger engine's own reserved-name guard
                // (Reports/models/Folder.php::checkDuplicate()) — these two
                // names are computed views, never a real folder.
                if (in_array(trim($value), ['All Reports', 'Shared With Me'], true)) {
                    $fail('That name is reserved.');
                }
            }],
        ]);

        $id = DB::table('report_folders')->insertGetId([
            'sub_institute_id' => $identity['sub_institute_id'],
            'module_scope' => $scope,
            'name' => $data['name'],
            'is_system' => false,
            'owner_id' => $identity['user_id'],
            'sort_order' => 0,
            'created_by' => $identity['user_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => true, 'message' => 'Folder created.', 'data' => ['id' => $id]], 201);
    }

    /** PUT /api/reports/folders/{id} — rename a custom folder; system folders cannot be renamed. */
    public function update(Request $request, int $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $folder = $this->ownFolder($id, (int) $identity['sub_institute_id']);

        if (!$folder) {
            return response()->json(['status' => false, 'message' => 'Folder not found.'], 404);
        }

        if ($folder->is_system) {
            return response()->json(['status' => false, 'message' => 'System folders cannot be renamed.'], 422);
        }

        $data = $request->validate(['name' => ['required', 'string', 'max:191']]);

        DB::table('report_folders')->where('id', $id)->update([
            'name' => $data['name'],
            'updated_by' => $identity['user_id'],
            'updated_at' => now(),
        ]);

        return response()->json(['status' => true, 'message' => 'Folder renamed.']);
    }

    /** DELETE /api/reports/folders/{id} — a custom, empty folder only. */
    public function destroy(Request $request, int $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $folder = $this->ownFolder($id, (int) $identity['sub_institute_id']);

        if (!$folder) {
            return response()->json(['status' => false, 'message' => 'Folder not found.'], 404);
        }

        if ($folder->is_system) {
            return response()->json(['status' => false, 'message' => 'System folders cannot be removed.'], 422);
        }

        $hasReports = DB::table('reports')->where('folder_id', $id)->whereNull('deleted_at')->exists();

        if ($hasReports) {
            return response()->json(['status' => false, 'message' => 'Move or delete the reports in this folder first.'], 422);
        }

        DB::table('report_folders')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $identity['user_id'],
        ]);

        return response()->json(['status' => true, 'message' => 'Folder removed.']);
    }

    private function ownFolder(int $id, int $subInstituteId): ?object
    {
        return DB::table('report_folders')
            ->where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first(['id', 'is_system', 'owner_id']);
    }

    private function requireScope(Request $request)
    {
        $scope = (string) $request->input('scope');

        if (!in_array($scope, self::SCOPES, true)) {
            return response()->json(['status' => false, 'message' => 'Unknown scope.'], 422);
        }

        return $scope;
    }
}
