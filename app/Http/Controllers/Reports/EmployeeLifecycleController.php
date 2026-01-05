<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Validator;

class EmployeeLifecycleController extends Controller
{
    public function getEmployeeLifecycle(Request $request)
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
            // Applicants: from talent_job_applications with status applied or pending
            $applicantsQuery = DB::table('talent_job_applications as tja')
                ->leftJoin('talent_job_postings as tjp', 'tja.job_id', '=', 'tjp.id')
                ->where('tja.sub_institute_id', $sub_institute_id)
                ->whereIn('tja.status', ['applied', 'under_review']);
            if ($departmentId) {
                $applicantsQuery->where('tjp.department_id', $departmentId);
            }
            $applicants = $applicantsQuery->count();

            // Shortlisted
            $shortlistedQuery = DB::table('talent_job_applications as tja')
                ->leftJoin('talent_job_postings as tjp', 'tja.job_id', '=', 'tjp.id')
                ->where('tja.sub_institute_id', $sub_institute_id)
                ->where('tja.status', 'shortlisted');
            if ($departmentId) {
                $shortlistedQuery->where('tjp.department_id', $departmentId);
            }
            $shortlisted = $shortlistedQuery->count();

            // Hired
            $hiredQuery = DB::table('talent_job_applications as tja')
                ->leftJoin('talent_job_postings as tjp', 'tja.job_id', '=', 'tjp.id')
                ->where('tja.sub_institute_id', $sub_institute_id)
                ->where('tja.status', 'hired');
            if ($departmentId) {
                $hiredQuery->where('tjp.department_id', $departmentId);
            }
            $hired = $hiredQuery->count();

            // Active: from tbluser without terminated_date
            $activeQuery = DB::table('tbluser')
                ->where('sub_institute_id', $sub_institute_id)
                ->whereNull('terminated_date');
            if ($departmentId) {
                $activeQuery->where('department_id', $departmentId);
            }
            $active = $activeQuery->count();

            // Resigned: from tbluser with terminated_date
            $resignedQuery = DB::table('tbluser')
                ->where('sub_institute_id', $sub_institute_id)
                ->whereNotNull('terminated_date');
            if ($departmentId) {
                $resignedQuery->where('department_id', $departmentId);
            }
            $resigned = $resignedQuery->count();

            $result = [
                ["stage" => "Applicants", "value" => $applicants, "color" => "#0da2e7"],
                ["stage" => "Shortlisted", "value" => $shortlisted, "color" => "#10b77f"],
                ["stage" => "Hired", "value" => $hired, "color" => "#fb923c"],
                ["stage" => "Active", "value" => $active, "color" => "#7c3bed"],
                ["stage" => "Resigned", "value" => $resigned, "color" => "#e92063"],
            ];

            return response()->json([
                "success" => true,
                "data" => $result
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => "Failed to fetch lifecycle data",
                "error" => $e->getMessage(),
            ], 500);
        }
    }
}