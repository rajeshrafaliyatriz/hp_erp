<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Validator;

class DepartmentSizeController extends Controller
{
    public function getDepartmentSizes(Request $request)
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
            // Get total employees for percentage calculation
            $totalEmployees = DB::table('tbluser')
                ->whereNull('terminated_date')
                ->where('sub_institute_id', $sub_institute_id)
                ->count();

            // Fetch department sizes
            $data = DB::table('hrms_departments as d')
                ->leftJoin('tbluser as e', 'd.id', '=', 'e.department_id')
                ->select(
                    'd.department',
                    DB::raw('COUNT(e.id) AS employee_count'),
                    DB::raw('ROUND((COUNT(e.id) / ' . $totalEmployees . ') * 100, 2) AS value')
                )
                ->where('e.sub_institute_id', $sub_institute_id)
                ->whereNull('e.terminated_date')
                ->groupBy('d.id', 'd.department')
                ->get()
                ->map(function ($dept) {
                    return [
                        'name' => $dept->department,
                        'value' => (float) $dept->value
                    ];
                });

            return response()->json([
                'status' => true,
                'message' => 'Department size distribution fetched successfully.',
                'data' => $data
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error fetching department size data.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}