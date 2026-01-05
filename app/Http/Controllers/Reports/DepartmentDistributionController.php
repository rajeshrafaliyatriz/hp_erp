<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Validator;

class DepartmentDistributionController extends Controller
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

            $validator = Validator::make($request->all(), [
                'sub_institute_id' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 0,
                    'message' => $validator->errors()->first()
                ], 400);
            }

            $sub_institute_id = $request->sub_institute_id;
            $departmentId = $request->department_id;
        } else {
            $sub_institute_id = $request->sub_institute_id ?? null;
            $departmentId = $request->department_id;
        }

        try {
            // Fetch month-wise distribution from talent_job_applications
            $query = DB::table('talent_job_applications as tja')
                ->leftJoin('talent_job_postings as tjp', 'tja.job_id', '=', 'tjp.id')
                ->selectRaw("
                    DATE_FORMAT(tja.applied_date, '%b') as month,
                    COUNT(*) as applicants,
                    SUM(CASE WHEN tja.status = 'shortlisted' THEN 1 ELSE 0 END) as shortlisted,
                    SUM(CASE WHEN tja.status = 'hired' THEN 1 ELSE 0 END) as hired
                ")
                ->where('tja.sub_institute_id', $sub_institute_id)
                ->when($departmentId, function($q) use ($departmentId) {
                    return $q->where('tjp.department_id', $departmentId);
                })
                ->groupByRaw("YEAR(tja.applied_date), MONTH(tja.applied_date)")
                ->orderByRaw("YEAR(tja.applied_date), MONTH(tja.applied_date)")
                ->get();
            $data = $query;

            return response()->json([
                'status' => true,
                'message' => 'Department distribution data fetched successfully.',
                'data' => $data
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch department distribution data.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getDepartmentSummary(Request $request)
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

            $validator = Validator::make($request->all(), [
                'sub_institute_id' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 0,
                    'message' => $validator->errors()->first()
                ], 400);
            }

            $sub_institute_id = $request->sub_institute_id;
        } else {
            $sub_institute_id = $request->sub_institute_id ?? null;
        }

        try {
            $data = DB::table('hrms_departments as d')
                ->leftJoin('tbluser as u', function($join) use ($sub_institute_id) {
                    $join->on('d.id', '=', 'u.department_id')
                         ->where('u.sub_institute_id', $sub_institute_id)
                         ->whereNull('u.terminated_date');
                })
                ->leftJoin('talent_job_applications as tja', function($join) use ($sub_institute_id) {
                    $join->on('d.id', '=', DB::raw('(SELECT department_id FROM talent_job_postings WHERE id = tja.job_id)'))
                         ->where('tja.sub_institute_id', $sub_institute_id)
                         ->where('tja.status', 'hired');
                })
                ->leftJoin('tbluser as u_exit', function($join) use ($sub_institute_id) {
                    $join->on('d.id', '=', 'u_exit.department_id')
                         ->where('u_exit.sub_institute_id', $sub_institute_id)
                         ->whereNotNull('u_exit.terminated_date');
                })
                ->select(
                    'd.department as department',
                    DB::raw('COUNT(DISTINCT u.id) as headcount'),
                    DB::raw("COUNT(DISTINCT CASE WHEN tja.applied_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN tja.id END) as hires"),
                    DB::raw('COUNT(DISTINCT u_exit.id) as exits')
                )
                ->groupBy('d.id', 'd.department')
                ->get()
                ->map(function ($dept) {
                    $totalEvents = $dept->hires + $dept->exits;
                    $attritionRate = $totalEvents > 0 ? round(($dept->exits / $totalEvents) * 100, 2) : 0;
                    $growth = $dept->headcount > 0 ? round((($dept->hires - $dept->exits) / $dept->headcount) * 100, 2) : 0;

                    return [
                        'department' => $dept->department,
                        'headcount' => $dept->headcount,
                        'hires' => $dept->hires,
                        'attrition' => $attritionRate,
                        'growth' => $growth,
                    ];
                });

            return response()->json($data, 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch department summary data.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
