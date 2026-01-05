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
}
