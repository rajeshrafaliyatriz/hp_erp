<?php

namespace App\Http\Controllers\api\HRITDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class LeaveDistribution extends Controller
{
    public function leaveDistribution(Request $request)
    {
        $type = $request->query('type');
        $token = $request->query('token');

        // Token validation for API type
        if ($type == "api") {

            if (!$token || $token == "") {
                return response()->json([
                    'status' => 0,
                    'message' => 'Token not provided'
                ], 401);
            }
    
            $accessToken = PersonalAccessToken::findToken($token);
    
            if (!$accessToken) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Invalid token'
                ], 401);
            }
        }

        // Get sub institute id (header or params)
        $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id');

        if (!$subInstituteId) {
            return response()->json([
                'status' => 0,
                'message' => 'sub_institute_id is required'
            ], 400);
        }

        // Main query
        $results = DB::table('tbluser as u')
            ->leftJoin(DB::raw("(SELECT department_id, MIN(department) AS department 
                                FROM s_user_jobrole 
                                GROUP BY department_id) AS j"),
                    fn($join) => $join->on('j.department_id', '=', 'u.department_id'))
            ->join(DB::raw("(SELECT * FROM hrms_emp_leaves 
                            WHERE id IN (
                                SELECT MAX(id) 
                                FROM hrms_emp_leaves 
                                GROUP BY user_id
                            )) AS l"),
                fn($join) => $join->on('l.user_id', '=', 'u.id'))
            ->leftJoin('hrms_leave_types as lt', 'lt.leave_type_id', '=', 'l.leave_type_id')
            ->where('u.sub_institute_id', $subInstituteId)
            ->select(
                'lt.leave_type AS leave_type_name',
                DB::raw("CASE 
                            WHEN l.day_type = 1 THEN 'Full Day Leave'
                            WHEN l.day_type = 0.5 THEN 'Half Day Leave'
                            ELSE 'Unknown'
                        END AS leave_day_type"),
                DB::raw("COUNT(*) AS leave_count")
            )
            ->groupBy('lt.leave_type', 'leave_day_type')
            ->get();

        return response()->json([
            'status' => 1,
            'message' => 'Leave distribution fetched successfully',
            'data' => $results
        ]);
    }
}
