<?php

namespace App\Http\Controllers\Api\HRITDashboard;

use App\Http\Controllers\Controller;  
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Carbon\Carbon;

class AttendanceApiController extends Controller
{
    public function weeklySummary(Request $request)
    {
        $type = $request->input('type');

        // Token validation for API type
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

        $subInstituteId = $request->sub_institute_id;
        $departmentId   = $request->department_id;  // <── NEW FILTER VARIABLE

        if (!$subInstituteId) {
            return response()->json(["error" => "sub_institute_id is required"], 400);
        }

        // DATE RANGE LOGIC
        if ($request->from_date && $request->to_date) {
            $start = Carbon::parse($request->from_date);
            $end   = Carbon::parse($request->to_date);
        } else {
            // Default: current week
            $start = Carbon::now()->subWeek()->startOfWeek();  // Monday
            $end   = Carbon::now()->subWeek()->endOfWeek();    // Sunday
        }

        // ============================================================
        //  FETCH ATTENDANCE RECORDS WITH OPTIONAL DEPARTMENT FILTER
        // ============================================================
        $attendanceQuery = DB::table('hrms_attendances')
            ->join('tbluser', 'hrms_attendances.user_id', '=', 'tbluser.id')
            ->where('hrms_attendances.sub_institute_id', $subInstituteId)
            ->whereBetween('hrms_attendances.day', [
                $start->format('Y-m-d'),
                $end->format('Y-m-d')
            ]);

        // APPLY DEPARTMENT FILTER ONLY IF PASSED IN REQUEST
        if ($request->filled('department_id')) {
            $attendanceQuery->where('tbluser.department_id', $departmentId);
        }

        $attendance = $attendanceQuery
            ->select('hrms_attendances.*', 'tbluser.first_name', 'tbluser.monday_in_date', 'tbluser.department_id')
            ->orderBy('hrms_attendances.day', 'ASC')
            ->get();

        // Labels
        $labels = ["Mon","Tue","Wed","Thu","Fri","Sat"];

        $present = [];
        $absent  = [];
        $late    = [];
        $dailyPunchData = [];

        // ============================================================
        //  TOTAL USERS (ALSO FILTER BY DEPARTMENT WHEN SELECTED)
        // ============================================================
        $userQuery = DB::table('tbluser')->where('sub_institute_id', $subInstituteId);

        // apply filter only when department_id is provided
        if ($request->filled('department_id')) {
            $userQuery->where('department_id', $departmentId);
        }

        $totalUsers = $userQuery->count();

        // ============================================================
        //  FOR EACH DAY — CALCULATE PRESENT, ABSENT, LATE
        // ============================================================
        foreach ($labels as $index => $dayName) {

            $dayDate = $start->copy()->addDays($index)->format('Y-m-d');

            $dayRecords = $attendance->where('day', $dayDate);

            // Present is when both entries exist
            $presentCount = $dayRecords
                ->whereNotNull('punchin_time')
                ->whereNotNull('punchout_time')
                ->count();

            // Late logic
            $lateCount = $dayRecords->filter(function ($rec) {
                if ($rec->punchin_time && $rec->monday_in_date) {
                    $punch = Carbon::parse($rec->punchin_time)->format('H:i:s');
                    return $punch > $rec->monday_in_date;
                }
                return false;
            })->count();

            // Absent = total - present
            $absentCount = $totalUsers - $presentCount;

            // Punch time list
            $punchTimes = [];
            foreach ($dayRecords as $rec) {

                if ($rec->punchin_time && $rec->punchout_time) {
                    // Present → punch-in
                    $punchTimes[] = [
                        "employee_id" => $rec->user_id,
                        "day"         => $rec->day,
                        "type"        => "present",
                        "time"        => $rec->punchin_time,
                    ];
                } elseif (!$rec->punchin_time) {
                    // Absent
                    $punchTimes[] = [
                        "employee_id" => $rec->user_id,
                        "day"         => $rec->day,
                        "type"        => "absent",
                        "time"        => null,
                    ];
                } else {
                    // Incomplete
                    $punchTimes[] = [
                        "employee_id" => $rec->user_id,
                        "day"         => $rec->day,
                        "type"        => "incomplete",
                        "time"        => $rec->punchin_time,
                    ];
                }
            }

            // Percent values
            $present[] = $totalUsers ? round(($presentCount / $totalUsers) * 100, 2) : 0;
            $absent[]  = $totalUsers ? round(($absentCount / $totalUsers) * 100, 2) : 0;
            $late[]    = $totalUsers ? round(($lateCount / $totalUsers) * 100, 2) : 0;

            // Save punch details
            $dailyPunchData[$dayName] = $punchTimes;
        }

        // ============================================================
        //     FINAL API RESPONSE
        // ============================================================
        return response()->json([
            "date_range" => [
                "start" => $start->format('Y-m-d'),
                "end"   => $end->format('Y-m-d')
            ],
            "department_filter" => $departmentId ?? "All",  // just for info
            "labels"      => $labels,
            "present"     => $present,
            "absent"      => $absent,
            "late"        => $late,
            "punch_times" => $dailyPunchData
        ]);
    }
}
