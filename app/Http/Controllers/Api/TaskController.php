<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    use ResolvesApiIdentity;

    /**
     * Validate the API token
     */
    private function validateToken($request)
    {
        // Was findToken() with the owner discarded, and no expiry check.
        $identity = $this->resolveApiIdentity($request);

        return is_array($identity) ? null : $identity;
    }

    /**
     * The tenant every query MUST be filtered by.
     *
     * @return int|\Illuminate\Http\JsonResponse
     *
     * This was request-FIRST: whatever sub_institute_id arrived in the query
     * string won, and the token's own user was consulted only as a fallback.
     * That is the same defect the module traits had - one query parameter
     * reached another organisation's tasks - written out by hand, which is why
     * fixing the traits did not fix it here.
     */
    private function resolveTenant($request)
    {
        $identity = $this->resolveApiIdentity($request);

        return is_array($identity) ? $identity['sub_institute_id'] : $identity;
    }

    /**
     * The caller themselves. These endpoints report "my" task counts and daily,
     * weekly and monthly tasks, so user_id was never meant to name anyone else;
     * taken from the request, it returned any colleague's workload.
     */
    private function resolveUserId($request): ?int
    {
        return $this->apiUserId($request);
    }

    /**
     * Get task counts and details for daily, weekly, and monthly periods
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTaskCounts(Request $request)
    {
        $validationError = $this->validateToken($request);
        if ($validationError) {
            return $validationError;
        }

        $userId = $this->resolveUserId($request);
        $subInstituteId = $this->resolveTenant($request);
        if (!is_int($subInstituteId)) {
            return $subInstituteId;
        }

        // Get date ranges
        $today = now()->toDateString();
        $startOfWeek = now()->startOfWeek()->toDateString();
        $endOfWeek = now()->endOfWeek()->toDateString();
        $startOfMonth = now()->startOfMonth()->toDateString();
        $endOfMonth = now()->endOfMonth()->toDateString();

        // Allowed statuses
        $allowedStatuses = ['Completed', 'In Progress', 'Pending'];

        // Build base query with optional user filter
        $dailyQuery = DB::table('task')
            ->whereDate('created_at', $today)
            ->whereIn('status', $allowedStatuses);
        
        $weeklyQuery = DB::table('task')
            ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
            ->whereIn('status', $allowedStatuses);
        
        $monthlyQuery = DB::table('task')
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->whereIn('status', $allowedStatuses);

        // Apply user_id filter if provided
        if ($userId) {
            $dailyQuery->where('task_allocated_to', $userId);
            $weeklyQuery->where('task_allocated_to', $userId);
            $monthlyQuery->where('task_allocated_to', $userId);
        }

        // Apply sub_institute_id filter if provided
        if ($subInstituteId) {
            $dailyQuery->where('sub_institute_id', $subInstituteId);
            $weeklyQuery->where('sub_institute_id', $subInstituteId);
            $monthlyQuery->where('sub_institute_id', $subInstituteId);
        }

        // Get tasks with details
        $dailyTasks = (clone $dailyQuery)
            ->select('id', 'task_title', 'status', 'created_at', 'updated_at')
            ->orderBy('created_at', 'desc')
            ->get();

        $weeklyTasks = (clone $weeklyQuery)
            ->select('id', 'task_title', 'status', 'created_at', 'updated_at')
            ->orderBy('created_at', 'desc')
            ->get();

        $monthlyTasks = (clone $monthlyQuery)
            ->select('id', 'task_title', 'status', 'created_at', 'updated_at')
            ->orderBy('created_at', 'desc')
            ->get();

        // Get daily date-wise counts with status breakdown
        $dailyDateWiseCounts = (clone $dailyQuery)
            ->select(DB::raw('DATE(created_at) as date'), 'status', DB::raw('COUNT(*) as count'))
            ->groupBy(DB::raw('DATE(created_at)'), 'status')
            ->orderBy('date', 'asc')
            ->orderBy('status', 'asc')
            ->get();

        // Transform the counts into a structured format
        $dailyDateWiseFormatted = [];
        foreach ($dailyDateWiseCounts as $item) {
            $date = $item->date;
            if (!isset($dailyDateWiseFormatted[$date])) {
                $dailyDateWiseFormatted[$date] = [
                    'date' => $date,
                    'Completed' => 0,
                    'Pending' => 0,
                    'In Progress' => 0,
                    'total' => 0
                ];
            }
            $dailyDateWiseFormatted[$date][$item->status] = (int) $item->count;
            $dailyDateWiseFormatted[$date]['total'] += (int) $item->count;
        }
        $dailyDateWiseCounts = array_values($dailyDateWiseFormatted);

        // Get weekly date-wise counts with status breakdown
        $weeklyDateWiseCounts = (clone $weeklyQuery)
            ->select(DB::raw('DATE(created_at) as date'), 'status', DB::raw('COUNT(*) as count'))
            ->groupBy(DB::raw('DATE(created_at)'), 'status')
            ->orderBy('date', 'asc')
            ->orderBy('status', 'asc')
            ->get();

        // Transform the counts into a structured format
        $weeklyDateWiseFormatted = [];
        foreach ($weeklyDateWiseCounts as $item) {
            $date = $item->date;
            if (!isset($weeklyDateWiseFormatted[$date])) {
                $weeklyDateWiseFormatted[$date] = [
                    'date' => $date,
                    'Completed' => 0,
                    'Pending' => 0,
                    'In Progress' => 0,
                    'total' => 0
                ];
            }
            $weeklyDateWiseFormatted[$date][$item->status] = (int) $item->count;
            $weeklyDateWiseFormatted[$date]['total'] += (int) $item->count;
        }
        $weeklyDateWiseCounts = array_values($weeklyDateWiseFormatted);

        // Get monthly month-wise counts with status breakdown (yearly view)
        $yearlyQuery = DB::table('task')
            ->whereYear('created_at', now()->year)
            ->whereIn('status', $allowedStatuses);

        // Apply user_id filter if provided
        if ($userId) {
            $yearlyQuery->where('task_allocated_to', $userId);
        }

        // Apply sub_institute_id filter if provided
        if ($subInstituteId) {
            $yearlyQuery->where('sub_institute_id', $subInstituteId);
        }

        $monthlyDateWiseCounts = $yearlyQuery
            ->select(
                DB::raw('MONTH(created_at) as month_number'),
                DB::raw('MONTHNAME(created_at) as month_name'),
                'status', 
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('month_number', 'month_name', 'status')
            ->orderBy('month_number', 'asc')
            ->orderBy('status', 'asc')
            ->get();

        // Transform the counts into a structured format
        $monthlyDateWiseFormatted = [];
        foreach ($monthlyDateWiseCounts as $item) {
            $monthKey = $item->month_name;
            if (!isset($monthlyDateWiseFormatted[$monthKey])) {
                $monthlyDateWiseFormatted[$monthKey] = [
                    'month' => $item->month_name,
                    'Completed' => 0,
                    'Pending' => 0,
                    'In Progress' => 0,
                    'total' => 0
                ];
            }
            $monthlyDateWiseFormatted[$monthKey][$item->status] = (int) $item->count;
            $monthlyDateWiseFormatted[$monthKey]['total'] += (int) $item->count;
        }
        $monthlyDateWiseCounts = array_values($monthlyDateWiseFormatted);

        // Build response
        $response = [
            'success' => true,
            'data' => [
                'daily' => [
                    'count' => $dailyTasks->count(),
                    'tasks' => $dailyTasks,
                    'date_wise_counts' => $dailyDateWiseCounts
                ],
                'weekly' => [
                    'count' => $weeklyTasks->count(),
                    'tasks' => $weeklyTasks,
                    'date_range' => [
                        'start' => $startOfWeek,
                        'end' => $endOfWeek
                    ],
                    'date_wise_counts' => $weeklyDateWiseCounts
                ],
                'monthly' => [
                    'count' => $monthlyTasks->count(),
                    'tasks' => $monthlyTasks,
                    'date_range' => [
                        'start' => $startOfMonth,
                        'end' => $endOfMonth
                    ],
                    'month_wise_counts' => $monthlyDateWiseCounts
                ]
            ],
            'message' => 'Task counts retrieved successfully'
        ];

        return response()->json($response, 200);
    }

    /**
     * Get daily tasks only
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDailyTasks(Request $request)
    {
        $validationError = $this->validateToken($request);
        if ($validationError) {
            return $validationError;
        }

        $today = now()->toDateString();
        $userId = $this->resolveUserId($request);
        $subInstituteId = $this->resolveTenant($request);
        if (!is_int($subInstituteId)) {
            return $subInstituteId;
        }
        $allowedStatuses = ['Completed', 'In Progress', 'Pending'];

        $query = DB::table('task')
            ->whereDate('created_at', $today)
            ->whereIn('status', $allowedStatuses);

        if ($userId) {
            $query->where('task_allocated_to', $userId);
        }

        if ($subInstituteId) {
            $query->where('sub_institute_id', $subInstituteId);
        }

        $tasks = $query
            ->select('id', 'task_title', 'status', 'created_at', 'updated_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $today,
                'count' => $tasks->count(),
                'tasks' => $tasks
            ],
            'message' => 'Daily tasks retrieved successfully'
        ], 200);
    }

    /**
     * Get weekly tasks only
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getWeeklyTasks(Request $request)
    {
        $validationError = $this->validateToken($request);
        if ($validationError) {
            return $validationError;
        }

        $startOfWeek = now()->startOfWeek()->toDateString();
        $endOfWeek = now()->endOfWeek()->toDateString();
        $userId = $this->resolveUserId($request);
        $subInstituteId = $this->resolveTenant($request);
        if (!is_int($subInstituteId)) {
            return $subInstituteId;
        }
        $allowedStatuses = ['Completed', 'In Progress', 'Pending'];

        $query = DB::table('task')
            ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
            ->whereIn('status', $allowedStatuses);

        if ($userId) {
            $query->where('task_allocated_to', $userId);
        }

        if ($subInstituteId) {
            $query->where('sub_institute_id', $subInstituteId);
        }

        $tasks = $query
            ->select('id', 'task_title', 'status', 'created_at', 'updated_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'date_range' => [
                    'start' => $startOfWeek,
                    'end' => $endOfWeek
                ],
                'count' => $tasks->count(),
                'tasks' => $tasks
            ],
            'message' => 'Weekly tasks retrieved successfully'
        ], 200);
    }

    /**
     * Get monthly tasks only
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getMonthlyTasks(Request $request)
    {
        $validationError = $this->validateToken($request);
        if ($validationError) {
            return $validationError;
        }

        $startOfMonth = now()->startOfMonth()->toDateString();
        $endOfMonth = now()->endOfMonth()->toDateString();
        $userId = $this->resolveUserId($request);
        $subInstituteId = $this->resolveTenant($request);
        if (!is_int($subInstituteId)) {
            return $subInstituteId;
        }
        $allowedStatuses = ['Completed', 'In Progress', 'Pending'];

        $query = DB::table('task')
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->whereIn('status', $allowedStatuses);

        if ($userId) {
            $query->where('task_allocated_to', $userId);
        }

        if ($subInstituteId) {
            $query->where('sub_institute_id', $subInstituteId);
        }

        $tasks = $query
            ->select('id', 'task_title', 'status', 'created_at', 'updated_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'date_range' => [
                    'start' => $startOfMonth,
                    'end' => $endOfMonth
                ],
                'count' => $tasks->count(),
                'tasks' => $tasks
            ],
            'message' => 'Monthly tasks retrieved successfully'
        ], 200);
    }
}
