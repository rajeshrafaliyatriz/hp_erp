<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;

class SkillHeatmapController extends Controller
{
    private const GAP_THRESHOLD_DEFAULT = 3;

    /**
     * GET /api/skill-heatmap
     *
     * Single endpoint — returns everything the heatmap component needs.
     *
     * Query params (all optional):
     *   ?department_ids=1,2,3   Filter to specific departments
     *   ?jobrole_id=5           Filter to a specific job role
     *   ?gap_threshold=3        Rating below this = gap  (default: 3)
     *   ?limit=20               Top N critical gaps returned (default: 20, max: 100)
     *   ?sub_institute_id=1     Filter by organization
     *   ?show_all_departments=1 Show all departments (parent_id=0), not just rated ones
     *
     * Response shape:
     * {
     *   "departments": [ { "id": 1, "name": "Engineering" }, ... ],
     *   "skills":      [ { "id": 101, "name": "Python" }, ... ],
     *
     *   "ratings": [                         ← main matrix (dept × skill)
     *     {
     *       "dept_id":    1,
     *       "skill_id":   101,
     *       "avg_rating": 4.8,
     *       "user_count": 14,
     *       "gap_count":  2
     *     }, ...
     *   ],
     *
     *   "critical_gaps": [                   ← top N worst cells, ranked by gap %
     *     {
     *       "dept_id":    3,  "dept_name":  "HR",
     *       "skill_id":   101, "skill_name": "Python",
     *       "avg_rating": 1.5, "user_count": 8,
     *       "gap_count":  7,  "gap_pct":    87.5
     *     }, ...
     *   ],
     *
     *   "summary": {                         ← org-level aggregates
     *     "overall_avg_rating": 3.6,
     *     "overall_gap_pct":    34,
     *     "total_users_assessed": 186,
     *     "critical_cell_count": 5           ← cells with avg < 2.5
     *   },
     *
     *   "meta": {
     *     "gap_threshold": 3,
     *     "generated_at":  "2026-03-24T10:00:00Z"
     *   }
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'department_ids'    => 'nullable|string',
            'jobrole_id'       => 'nullable|integer',
            'gap_threshold'    => 'nullable|integer|min:1|max:6',
            'limit'             => 'nullable|integer|min:1|max:100',
            'sub_institute_id'  => 'required|integer',
            'show_all_departments' => 'nullable|integer|min:0|max:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error'   => 'Invalid parameters',
                'details' => $validator->errors(),
            ], 422);
        }

        $departmentIds        = $this->parseCsv($request->get('department_ids'));
        $jobroleId            = $request->get('jobrole_id');
        $threshold            = (int) $request->get('gap_threshold', self::GAP_THRESHOLD_DEFAULT);
        $limit                = min((int) $request->get('limit', 20), 100);
        $subInstituteId       = $request->get('sub_institute_id');
        $showAllDepartments  = (bool) $request->get('show_all_departments', 1);

        // ── 1. Main matrix ────────────────────────────────────────────────────
        $matrixRows = $this->fetchMatrix($departmentIds, $jobroleId, $threshold, $subInstituteId);

        $departments  = $this->extractDimension($matrixRows, 'dept_id',  'dept_name');
        $skills       = $this->extractDimension($matrixRows, 'skill_id', 'skill_name');

        // If show_all_departments is enabled, fetch all top-level departments
        if ($showAllDepartments) {
            $allDepartments = DB::table('hrms_departments')
                ->where('parent_id', 0)
                ->where('sub_institute_id', $subInstituteId)
                ->where('status', 1)
                ->orderBy('department')
                ->select('id', 'department as name')
                ->get()
                ->map(fn($d) => ['id' => (int) $d->id, 'name' => $d->name])
                ->toArray();

            if (!empty($allDepartments)) {
                $departments = $allDepartments;
            }
        }

        $ratings = array_map(fn($r) => [
            'dept_id'     => (int)   $r->dept_id,
            'skill_id'    => (int)   $r->skill_id,
            'avg_rating'  => round((float) $r->avg_rating, 2),
            'user_count'  => (int)   $r->user_count,
            'gap_count'   => (int)   $r->gap_count,
        ], $matrixRows);

        // ── 2. Critical gaps (sorted by gap % desc) ───────────────────────────
        $gapsArray = array_map(fn($r) => [
            'dept_id'    => (int)   $r->dept_id,
            'dept_name'  =>         $r->dept_name,
            'skill_id'   => (int)   $r->skill_id,
            'skill_name' =>         $r->skill_name,
            'avg_rating' => round((float) $r->avg_rating, 2),
            'user_count' => (int)   $r->user_count,
            'gap_count'  => (int)   $r->gap_count,
            'gap_pct'    => $r->user_count > 0
                ? round((int) $r->gap_count / (int) $r->user_count * 100, 1)
                : 0,
        ], array_filter($matrixRows, fn($r) => (int) $r->gap_count > 0));

        usort($gapsArray, fn($a, $b) =>
            $b['gap_pct'] <=> $a['gap_pct'] ?: $a['avg_rating'] <=> $b['avg_rating']
        );
        $criticalGaps = array_slice($gapsArray, 0, $limit);

        // ── 3. Org-level summary ──────────────────────────────────────────────
        $totalAvg    = 0;
        $totalGap    = 0;
        $totalUsers  = 0;
        $critCells   = 0;

        foreach ($matrixRows as $r) {
            $totalAvg   += (float) $r->avg_rating;
            $totalGap   += (int)   $r->gap_count;
            $totalUsers += (int)   $r->user_count;
            if ((float) $r->avg_rating < 2.5) $critCells++;
        }

        $count   = count($matrixRows);
        $summary = [
            'overall_avg_rating'    => $count   ? round($totalAvg / $count, 2) : 0,
            'overall_gap_pct'       => $totalUsers ? round($totalGap / $totalUsers * 100) : 0,
            'total_users_assessed'  => $totalUsers,
            'critical_cell_count'   => $critCells,
        ];

        // ── 4. Build response ─────────────────────────────────────────────────
        return response()->json([
            'departments'   => $departments,
            'skills'        => $skills,
            'ratings'       => $ratings,
            'critical_gaps' => $criticalGaps,
            'summary'       => $summary,
            'meta'          => [
                'gap_threshold' => $threshold,
                'generated_at'  => Carbon::now()->toISOString(),
            ],
        ]);
    }

    // =========================================================================
    // Query
    // =========================================================================

    /**
     * Core matrix query — one row per (department × skill).
     */
    private function fetchMatrix(
        array $departmentIds = [],
        ?int  $jobroleId     = null,
        int   $threshold     = 3,
        ?int  $subInstituteId = null,
    ): array {
        $where    = [];
        $bindings = [];

        // Department filter
        if (!empty($departmentIds)) {
            $ph       = implode(',', array_fill(0, count($departmentIds), '?'));
            $where[]  = "d.id IN ({$ph})";
            $bindings = array_merge($bindings, $departmentIds);
        }

        // Jobrole filter
        if ($jobroleId) {
            $where[]    = 'suj.jobrole_id = ?';
            $bindings[] = $jobroleId;
        }

        // Sub-institute filter
        if ($subInstituteId) {
            $where[]    = 'u.sub_institute_id = ?';
            $bindings[] = $subInstituteId;
        }

        // Always exclude rows where the skill key is absent from skill_ids
        $where[] = "JSON_CONTAINS_PATH(urd.skill_ids, 'one', CONCAT('$.\"', ss.id, '\"'))";
        $where[] = "JSON_UNQUOTE(JSON_EXTRACT(urd.skill_ids, CONCAT('$.\"', ss.id, '\"'))) != 'null'";

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        // gap_count threshold binding
        $bindings[] = $threshold;

        return DB::select("
            SELECT
                d.id                                                        AS dept_id,
                d.department                                               AS dept_name,
                ss.id                                                       AS skill_id,
                ss.title                                                   AS skill_name,
                ROUND(
                    AVG(
                        CAST(
                            JSON_UNQUOTE(
                                JSON_EXTRACT(urd.skill_ids, CONCAT('$.\"', ss.id, '\"'))
                            ) AS DECIMAL(4,2)
                        )
                    ), 2
                )                                                           AS avg_rating,
                COUNT(DISTINCT urd.user_id)                                AS user_count,
                SUM(
                    CASE 
                        WHEN CAST(
                            JSON_UNQUOTE(
                                JSON_EXTRACT(urd.skill_ids, CONCAT('$.\"', ss.id, '\"'))
                            ) AS DECIMAL(4,2)
                        ) < ?
                        THEN 1 ELSE 0 END
                )                                                           AS gap_count
            FROM hrms_departments d
            JOIN tbluser u ON u.department_id = d.id
            JOIN user_rating_details urd ON urd.user_id = u.id
            CROSS JOIN s_users_skills ss
            {$whereClause}
            GROUP BY d.id, d.department, ss.id, ss.title
            ORDER BY d.department, ss.title
        ", $bindings);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function extractDimension(array $rows, string $idCol, string $nameCol): array
    {
        $seen = $out = [];
        foreach ($rows as $r) {
            $id = (int) $r->{$idCol};
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $out[]     = ['id' => $id, 'name' => $r->{$nameCol}];
            }
        }
        return $out;
    }

    private function parseCsv(?string $value): array
    {
        if (empty($value)) return [];
        return array_values(array_filter(
            array_map('intval', explode(',', $value)),
            fn($id) => $id > 0
        ));
    }
}
