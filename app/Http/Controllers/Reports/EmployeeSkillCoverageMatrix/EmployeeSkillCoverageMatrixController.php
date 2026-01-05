<?php

namespace App\Http\Controllers\Reports\EmployeeSkillCoverageMatrix;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class EmployeeSkillCoverageMatrixController extends Controller
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
            $department     = $request->query('department', 'all');
            $role           = $request->query('role', 'all');
            $skillCategory  = $request->query('skillCategory', 'all');

            $results = DB::table('s_skill_matrix as sm')
                ->join('tbluser as e', 'sm.user_id', '=', 'e.id')
                ->join('s_users_skills as s', 'sm.skill_id', '=', 's.id')
                ->leftJoin('s_user_jobrole as jt', 'e.allocated_standards', '=', 'jt.id')
                ->leftJoin('hrms_departments as d', 'e.department_id', '=', 'd.id')
                ->whereNull('e.terminated_date')
                ->where('e.sub_institute_id', $subInstituteId)
                ->when($department !== 'all', fn($q) => $q->where('d.department', $department))
                ->when($role !== 'all', fn($q) => $q->whereRaw('COALESCE(jt.jobrole, ?) = ?', ['No Role', $role]))
                ->when($skillCategory !== 'all', fn($q) => $q->where('s.category', $skillCategory))
                ->select(
                    's.title as skill',
                    DB::raw('COALESCE(jt.jobrole, "No Role") as role'),
                    DB::raw('COUNT(*) as total_employees'),
                    DB::raw('SUM(CASE WHEN sm.skill_level >= 3 THEN 1 ELSE 0 END) as meeting_target'),
                    DB::raw('(SUM(CASE WHEN sm.skill_level >= 3 THEN 1 ELSE 0 END) / COUNT(*) * 100) as coverage'),
                    DB::raw('AVG(3) as expected_avg'),
                    DB::raw('AVG(sm.skill_level) as actual_avg')
                )
                ->groupBy('s.title', DB::raw('COALESCE(jt.jobrole, "No Role")'))
                ->orderBy('s.title')
                ->get();

            // Format frontend matrix structure
            $response = [];
            foreach ($results as $row) {
                if (!isset($response[$row->skill])) {
                    $response[$row->skill] = [
                        'skill' => $row->skill,
                        'roles' => []
                    ];
                }
                $response[$row->skill]['roles'][$row->role] = [
                    'coverage' => round($row->coverage, 1),
                    'expected' => round($row->expected_avg, 1),
                    'actual'   => round($row->actual_avg, 1),
                ];
            }

            return response()->json(array_values($response), 200);

        } catch (\Exception $e) {
            return response()->json([
                "error" => true,
                "message" => "Failed to fetch skill coverage data.",
                "details" => $e->getMessage()
            ], 500);
        }
    }

    public function skillGaps(Request $request)
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
            $department = $request->query('department', 'all');
            $role = $request->query('role', 'all');
            $skillCategory = $request->query('skillCategory', 'all');
            $sort = $request->query('sort', 'gap');
            $order = $request->query('order', 'desc');

            $results = DB::select("
                SELECT department, skill, category, expectedScore, actualScore, gap
                FROM (
                    SELECT d.department,
                           s.title as skill,
                           s.category,
                           AVG(3) as expectedScore,
                           AVG(sm.skill_level) as actualScore,
                           (AVG(3) - AVG(sm.skill_level)) as gap,
                           ROW_NUMBER() OVER (PARTITION BY d.department ORDER BY (AVG(3) - AVG(sm.skill_level)) DESC) as rn
                    FROM s_skill_matrix sm
                    JOIN tbluser e ON sm.user_id = e.id
                    JOIN s_users_skills s ON sm.skill_id = s.id
                    LEFT JOIN s_user_jobrole jt ON e.allocated_standards = jt.id
                    LEFT JOIN hrms_departments d ON e.department_id = d.id
                    WHERE e.terminated_date IS NULL
                      AND e.sub_institute_id = ?
                      " . ($department !== 'all' ? " AND d.department = ?" : "") . "
                      " . ($role !== 'all' ? " AND COALESCE(jt.jobrole, 'No Role') = ?" : "") . "
                      " . ($skillCategory !== 'all' ? " AND s.category = ?" : "") . "
                    GROUP BY d.department, s.id, s.title, s.category
                ) as sub
                WHERE rn = 1
                ORDER BY gap DESC
                LIMIT 5
            ", array_filter([$subInstituteId, $department !== 'all' ? $department : null, $role !== 'all' ? $role : null, $skillCategory !== 'all' ? $skillCategory : null]));

            return response()->json([
                'success' => true,
                'count' => count($results),
                'data' => $results
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve skill gap data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getKpiMetrics(Request $request)
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
            $department = $request->query('department', 'all');

            // Overall Skill Coverage
            $coverage = DB::table('s_skill_matrix as es')
                ->join('tbluser as e', 'e.id', '=', 'es.user_id')
                ->join('s_users_skills as s', 's.id', '=', 'es.skill_id')
                ->where('e.terminated_date', null)
                ->where('e.sub_institute_id', $subInstituteId)
                ->when($department !== 'all', fn($q) => $q->where('e.department_id', $department))
                ->selectRaw("ROUND((SUM(CASE WHEN es.skill_level >= 3 THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as coverage")
                ->first()->coverage ?? 0;

            // Avg Skill Gap
            $avgGap = DB::table('s_skill_matrix as es')
                ->join('tbluser as e', 'e.id', '=', 'es.user_id')
                ->join('s_users_skills as s', 's.id', '=', 'es.skill_id')
                ->where('e.terminated_date', null)
                ->where('e.sub_institute_id', $subInstituteId)
                ->when($department !== 'all', fn($q) => $q->where('e.department_id', $department))
                ->selectRaw("ROUND(AVG(GREATEST(3 - es.skill_level, 0)), 2) as avg_gap")
                ->first()->avg_gap ?? 0;

            // Critical Deficiencies
            $criticalDeficiencies = DB::table('s_skill_matrix as es')
                ->join('tbluser as e', 'e.id', '=', 'es.user_id')
                ->join('s_users_skills as s', 's.id', '=', 'es.skill_id')
                ->where('e.terminated_date', null)
                ->where('e.sub_institute_id', $subInstituteId)
                ->when($department !== 'all', fn($q) => $q->where('e.department_id', $department))
                ->whereRaw("(3 - es.skill_level) >= 2")
                ->count();

            // Training urgency (example scoring formula)
            $trainingUrgency = min(100, ($criticalDeficiencies * 5) + ($avgGap * 10));

            return response()->json([
                'status' => true,
                'department' => $department,
                'metrics' => [
                    'overallSkillCoverage' => $coverage,
                    'avgSkillGap' => $avgGap,
                    'criticalDeficiencies' => $criticalDeficiencies,
                    'trainingUrgencyIndex' => round($trainingUrgency, 0),
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}