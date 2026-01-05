<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class KpiController extends Controller
{
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

        $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id');

        try {

            // Total current employees
            $totalEmployees = DB::table('tbluser')
                ->whereNull('terminated_date')
                ->where('sub_institute_id', $subInstituteId)
                ->count();

            // New hires in the last quarter
            $newHires = DB::table('tbluser')
                ->where('joined_date', '>=', DB::raw('DATE_SUB(CURDATE(), INTERVAL 3 MONTH)'))
                ->where('sub_institute_id', $subInstituteId)
                ->count();

            // Employees who left in the last quarter
            $exits = DB::table('tbluser')
                ->where('terminated_date', '>=', DB::raw('DATE_SUB(CURDATE(), INTERVAL 3 MONTH)'))
                ->where('sub_institute_id', $subInstituteId)
                ->count();

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
