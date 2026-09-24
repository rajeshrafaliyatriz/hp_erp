<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Attendance\Concerns\ResolvesAttendanceContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Attendance analytics for the HRIT dashboard / attendance tracking header.
 *
 * Legacy equivalents: Api\HRITDashboard\AttendanceApiController::weeklySummary
 * (GET /api/attendance-weekly) and ::KPI (GET /api/KPI-HRITDashboard), which
 * stay untouched and still serve every existing consumer. This controller
 * keeps the same response contract and adds the optional employee_id filter so
 * a single selected employee makes the denominator 1 and the series read as
 * that person's own percentages.
 */
class AttendanceDashboardApiController extends Controller
{
    use ResolvesAttendanceContext;

    /**
     * GET /api/attendance/weekly-summary
     */
    public function weeklySummary(Request $request)
    {
        $context = $this->attendanceContext($request);

        if (!is_array($context)) {
            return $context;
        }

        $subInstituteId = $context['sub_institute_id'];
        $departmentId = $this->activeFilter($request->input('department_id'));
        $employeeId = $this->activeFilter($request->input('employee_id'));

        // DATE RANGE LOGIC
        if ($request->filled('from_date') && $request->filled('to_date')) {
            $start = Carbon::parse($request->input('from_date'));
            $end   = Carbon::parse($request->input('to_date'));
        } else {
            // Default: previous week, Monday to Sunday.
            $start = Carbon::now()->subWeek()->startOfWeek();
            $end   = Carbon::now()->subWeek()->endOfWeek();
        }

        $attendanceQuery = DB::table('hrms_attendances')
            ->join('tbluser', 'hrms_attendances.user_id', '=', 'tbluser.id')
            ->where('hrms_attendances.sub_institute_id', $subInstituteId)
            ->whereBetween('hrms_attendances.day', [
                $start->format('Y-m-d'),
                $end->format('Y-m-d'),
            ]);

        if ($departmentId) {
            $attendanceQuery->where('tbluser.department_id', $departmentId);
        }

        if ($employeeId) {
            $attendanceQuery->where('hrms_attendances.user_id', $employeeId);
        }

        $attendance = $attendanceQuery
            ->select('hrms_attendances.*', 'tbluser.first_name', 'tbluser.monday_in_date', 'tbluser.department_id')
            ->orderBy('hrms_attendances.day', 'ASC')
            ->get();

        /*
         * THE DAYS THE CALLER ACTUALLY ASKED ABOUT.
         *
         * This was ['Mon','Tue','Wed','Thu','Fri','Sat'] - six labels, always -
         * walked as $start->copy()->addDays($index) whatever the range. Select
         * "Today" and the series still ran six days forward: five of them lay
         * outside the queried window, so $presentCount was 0 for each, and
         * $absentCount = $totalUsers - 0 made every one of them 100% absent.
         *
         * That is the "Absent 100%" sitting next to "Attendance 5%" on the same
         * row of cards. Neither number was wrong about its own question; the
         * series was answering about days nobody had asked for.
         */
        $days = [];
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $days[] = $cursor->copy();
        }

        /*
         * A day per point is right for a week or a month and unreadable for a
         * year, so anything longer than 31 days is bucketed into weeks. The
         * response says which was used rather than leaving the reader to infer
         * it from the label count.
         */
        $granularity = count($days) > 31 ? 'week' : 'day';

        $buckets = [];
        if ($granularity === 'day') {
            foreach ($days as $day) {
                $buckets[] = [
                    'label' => $day->format('D j M'),
                    'from'  => $day->format('Y-m-d'),
                    'to'    => $day->format('Y-m-d'),
                ];
            }
        } else {
            for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addWeek()) {
                $weekEnd = $cursor->copy()->addDays(6);
                if ($weekEnd->gt($end)) {
                    $weekEnd = $end->copy();
                }
                $buckets[] = [
                    'label' => $cursor->format('j M'),
                    'from'  => $cursor->format('Y-m-d'),
                    'to'    => $weekEnd->format('Y-m-d'),
                ];
            }
        }

        $labels = array_column($buckets, 'label');

        $present = [];
        $absent  = [];
        $late    = [];
        $dailyPunchData = [];

        $userQuery = DB::table('tbluser')
            ->where('sub_institute_id', $subInstituteId)
            ->where('status', 1)
            ->whereNull('terminated_date');

        if ($departmentId) {
            $userQuery->where('department_id', $departmentId);
        }

        // A single selected employee makes the denominator 1, so the
        // present/absent/late series read as that person's own percentages.
        if ($employeeId) {
            $userQuery->where('id', $employeeId);
        }

        $totalUsers = $userQuery->count();

        foreach ($buckets as $bucket) {
            $dayName = $bucket['label'];

            $dayRecords = $attendance->filter(
                fn ($rec) => $rec->day >= $bucket['from'] && $rec->day <= $bucket['to']
            );

            // Present is when both entries exist.
            $presentCount = $dayRecords
                ->whereNotNull('punchin_time')
                ->whereNotNull('punchout_time')
                ->pluck('user_id')
                ->unique()
                ->count();

            $lateCount = $dayRecords->filter(function ($rec) {
                if ($rec->punchin_time && $rec->monday_in_date) {
                    $punch = Carbon::parse($rec->punchin_time)->format('H:i:s');

                    return $punch > $rec->monday_in_date;
                }

                return false;
            })->pluck('user_id')->unique()->count();

            /*
             * DISTINCT people, because a bucket can span several days and the
             * same employee appears once per day in it. Counting rows made
             * $presentCount exceed $totalUsers over a week, which drove
             * $absentCount negative and the "present" percentage above 100.
             */
            $presentCount = min($presentCount, $totalUsers);
            $absentCount = max(0, $totalUsers - $presentCount);

            $punchTimes = [];
            foreach ($dayRecords as $rec) {
                if ($rec->punchin_time && $rec->punchout_time) {
                    $punchTimes[] = [
                        'employee_id' => $rec->user_id,
                        'day'         => $rec->day,
                        'type'        => 'present',
                        'time'        => $rec->punchin_time,
                    ];
                } elseif (!$rec->punchin_time) {
                    $punchTimes[] = [
                        'employee_id' => $rec->user_id,
                        'day'         => $rec->day,
                        'type'        => 'absent',
                        'time'        => null,
                    ];
                } else {
                    $punchTimes[] = [
                        'employee_id' => $rec->user_id,
                        'day'         => $rec->day,
                        'type'        => 'incomplete',
                        'time'        => $rec->punchin_time,
                    ];
                }
            }

            $present[] = $totalUsers ? round(($presentCount / $totalUsers) * 100, 2) : 0;
            $absent[]  = $totalUsers ? round(($absentCount / $totalUsers) * 100, 2) : 0;
            $late[]    = $totalUsers ? round(($lateCount / $totalUsers) * 100, 2) : 0;

            $dailyPunchData[$dayName] = $punchTimes;
        }

        return response()->json([
            'date_range' => [
                'start' => $start->format('Y-m-d'),
                'end'   => $end->format('Y-m-d'),
            ],
            'department_filter' => $departmentId ?? 'All',
            'employee_filter'   => $employeeId ?? 'All',
            // 'day' or 'week' - the screen should say which it is plotting.
            'granularity' => $granularity,
            'labels'      => $labels,
            'present'     => $present,
            'absent'      => $absent,
            'late'        => $late,
            'punch_times' => $dailyPunchData,
        ]);
    }

    /**
     * GET /api/attendance/kpi
     */
    public function kpi(Request $request)
    {
        $context = $this->attendanceContext($request);

        if (!is_array($context)) {
            return $context;
        }

        $subInstituteId = $context['sub_institute_id'];
        $departmentId = $this->activeFilter($request->input('department_id'));
        $employeeId = $this->activeFilter($request->input('employee_id'));

        /*
         * THE RANGE THE CALLER ASKED FOR, not today.
         *
         * This read Carbon::today() and filtered attendance on that one date,
         * while the screen above it offers Today / This Week / This Month / This
         * Quarter / This Year / Custom. Every one of those produced the same
         * number, and that number described today. A user selecting a quarter
         * and reading "Attendance 5%" was being shown this morning's punch-in
         * rate with a quarter's label over it.
         *
         * Defaults to today when no range is given, so the previous behaviour is
         * still what an un-filtered caller gets.
         */
        $fromDate = $request->input('from_date') ?: Carbon::today()->format('Y-m-d');
        $toDate   = $request->input('to_date') ?: $fromDate;

        /*
         * `status = 1` and not soft-deleted. tbluser.status is 1/0, and this
         * counted every row - disabled accounts and deleted ones included - so
         * "Total Employees" was larger than the headcount any other screen
         * reports, and every percentage computed against it was correspondingly
         * understated.
         */
        $userQuery = DB::table('tbluser')
            ->where('sub_institute_id', $subInstituteId)
            ->where('status', 1)
            ->whereNull('deleted_at');

        if ($departmentId) {
            $userQuery->where('department_id', $departmentId);
        }

        if ($employeeId) {
            $userQuery->where('id', $employeeId);
        }

        $totalUsers = $userQuery->count();

        $attendanceQuery = DB::table('hrms_attendances')
            ->join('tbluser', 'hrms_attendances.user_id', '=', 'tbluser.id')
            ->where('hrms_attendances.sub_institute_id', $subInstituteId)
            ->whereBetween('hrms_attendances.day', [$fromDate, $toDate])
            ->where('hrms_attendances.status', 1)
            ->where('tbluser.status', 1)
            ->whereNull('tbluser.deleted_at');

        if ($departmentId) {
            $attendanceQuery->where('tbluser.department_id', $departmentId);
        }

        if ($employeeId) {
            $attendanceQuery->where('hrms_attendances.user_id', $employeeId);
        }

        $presentCount = $attendanceQuery
            ->distinct()
            ->count('hrms_attendances.user_id');

        /*
         * Over a multi-day range this is "how many people were present at all",
         * not an average daily attendance - $presentCount is DISTINCT user_id.
         * Named accordingly in the response so the screen cannot label it as
         * something it is not.
         */
        $presentPercentage = $totalUsers
            ? round(($presentCount / $totalUsers) * 100, 1)
            : 0;

        // LEAVE UTILIZATION over the April-March leave year.
        $currentYear = Carbon::now()->year;
        $startDate   = $currentYear . '-04-01';
        $endDate     = ($currentYear + 1) . '-03-31';

        $leaveQuery = DB::table('hrms_emp_leaves')
            ->join('tbluser', 'hrms_emp_leaves.user_id', '=', 'tbluser.id')
            ->where('hrms_emp_leaves.sub_institute_id', $subInstituteId)
            ->where('hrms_emp_leaves.status', 'approved')
            ->where('hrms_emp_leaves.from_date', '>=', $startDate)
            ->where('hrms_emp_leaves.to_date', '<=', $endDate);

        if ($departmentId) {
            $leaveQuery->where('tbluser.department_id', $departmentId);
        }

        if ($employeeId) {
            $leaveQuery->where('hrms_emp_leaves.user_id', $employeeId);
        }

        $totalLeaveDaysTaken = $leaveQuery
            ->selectRaw('SUM((DATEDIFF(to_date, from_date) + 1) * day_type) as total_days')
            ->value('total_days') ?? 0;

        // ASSUMING 30 LEAVE DAYS PER EMPLOYEE PER YEAR
        $totalAllocatedDays = $totalUsers * 30;

        $leaveUtilization = $totalAllocatedDays
            ? round(($totalLeaveDaysTaken / $totalAllocatedDays) * 100, 1)
            : 0;

        return response()->json([
            'present_today'     => $presentPercentage . '%',
            'leave_utilization' => $leaveUtilization . '%',
            'active_employees'  => $totalUsers,
            // Echoed so the screen can state the period it is reporting rather
            // than the period the filter happens to be set to.
            'from_date'         => $fromDate,
            'to_date'           => $toDate,
        ]);
    }
}
