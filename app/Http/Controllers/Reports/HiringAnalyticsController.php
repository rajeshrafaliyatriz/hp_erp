<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class HiringAnalyticsController extends Controller
{
    public function getHiringTrends(Request $request)
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
        $departmentId = $request->department_id;
        try {
            // Fetch hires from talent_job_applications
            $hiresQuery = DB::table('talent_job_applications as tja')
                ->leftJoin('talent_job_postings as tjp', 'tja.job_id', '=', 'tjp.id')
                ->where('tja.status', 'hired')
                ->where('tja.sub_institute_id', $subInstituteId)
                ->selectRaw("DATE_FORMAT(tja.applied_date, '%b') as month, COUNT(*) as hires")
                ->groupByRaw("YEAR(tja.applied_date), MONTH(tja.applied_date)")
                ->when($departmentId, function($q) use ($departmentId) {
                    return $q->where('tjp.department_id', $departmentId);
                });

            // Fetch attrition from tbluser
            $attritionQuery = DB::table('tbluser')
                ->whereNotNull('terminated_date')
                ->where('sub_institute_id', $subInstituteId)
                ->selectRaw("DATE_FORMAT(terminated_date, '%b') as month, COUNT(*) as attrition")
                ->groupByRaw("YEAR(terminated_date), MONTH(terminated_date)")
                ->when($departmentId, function($q) use ($departmentId) {
                    return $q->where('department_id', $departmentId);
                });

            // Combine the results
            $hires = $hiresQuery->get()->keyBy('month');
            $attrition = $attritionQuery->get()->keyBy('month');

            $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            $data = [];
            foreach ($months as $month) {
                $data[] = [
                    'month' => $month,
                    'hires' => $hires->get($month)->hires ?? 0,
                    'attrition' => $attrition->get($month)->attrition ?? 0
                ];
            }

            return response()->json([
                "status" => true,
                "message" => "Hiring vs Attrition data fetched successfully",
                "data" => $data
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                "status" => false,
                "message" => "Something went wrong while fetching hiring data",
                "error" => $e->getMessage()
            ], 500);
        }
    }
}
