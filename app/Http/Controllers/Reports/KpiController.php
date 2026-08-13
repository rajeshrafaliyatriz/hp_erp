<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class KpiController extends Controller
{
    use ResolvesApiIdentity;

    public function index(Request $request)
    {
        $type = $request->type;

        if ($type == "API") {

            $token = $request->input('token');
            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            $accessToken = PersonalAccessToken::findToken($token);
            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }
        }

        $subInstituteId = $this->apiTenantId($request);
        $departmentId = $request->department_id;

        try {

            // Total current employees
            $totalEmployeesQuery = DB::table('tbluser')
                ->whereNull('terminated_date')
                ->where('sub_institute_id', $subInstituteId);
            if ($departmentId) {
                $totalEmployeesQuery->where('department_id', $departmentId);
            }
            $totalEmployees = $totalEmployeesQuery->count();

            // New hires in the last quarter
            $newHiresQuery = DB::table('talent_job_applications as tja')
                ->leftJoin('talent_job_postings as tjp', 'tja.job_id', '=', 'tjp.id')
                ->where('tja.status', 'hired')
                ->where('tja.applied_date', '>=', DB::raw('DATE_SUB(CURDATE(), INTERVAL 3 MONTH)'))
                ->where('tja.sub_institute_id', $subInstituteId);
            if ($departmentId) {
                $newHiresQuery->where('tjp.department_id', $departmentId);
            }
            $newHires = $newHiresQuery->count();

            // Employees who left in the last quarter
            $exitsQuery = DB::table('tbluser')
                ->where('terminated_date', '>=', DB::raw('DATE_SUB(CURDATE(), INTERVAL 3 MONTH)'))
                ->where('sub_institute_id', $subInstituteId);
            if ($departmentId) {
                $exitsQuery->where('department_id', $departmentId);
            }
            $exits = $exitsQuery->count();

            // Previous quarter employee count estimate
            $previousEmployees = max($totalEmployees - ($newHires - $exits), 1);

            // KPI Calculations
            $growthPercent = round((($newHires - $exits) / $previousEmployees) * 100, 2);
            $attritionRate = round(($exits / $previousEmployees) * 100, 2);

            return response()->json([
                'success' => true,
                'data' => [
                    'totalEmployees' => $totalEmployees,
                    'newHires' => $newHires,
                    'attritionRate' => $attritionRate,
                    'growthPercent' => $growthPercent
                ]
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Error fetching KPI data',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
