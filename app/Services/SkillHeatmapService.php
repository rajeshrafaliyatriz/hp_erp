<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class SkillHeatmapService
{
    /**
     * Build the full department × skill heatmap matrix.
     */
    public function getHeatmapMatrix(
        array $departmentIds = [],
        ?int  $jobroleId     = null,
        int   $gapThreshold  = 3,
        ?int  $subInstituteId = null,
        bool  $showAllDepartments = true,
    ): array {
        $rows = $this->fetchMatrix($departmentIds, $jobroleId, $gapThreshold, $subInstituteId);

        // Collect unique departments and skills from the result set
        $departments = $this->extractDimension($rows, 'dept_id', 'dept_name');
        $skills      = $this->extractDimension($rows, 'skill_id', 'skill_name');

        // If show_all_departments is enabled, fetch all top-level departments
        if ($showAllDepartments && $subInstituteId) {
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

        $ratings = array_map(function ($row) {
            return [
                'dept_id'    => (int) $row->dept_id,
                'skill_id'   => (int) $row->skill_id,
                'avg_rating' => round((float) $row->avg_rating, 2),
                'user_count' => (int) $row->user_count,
                'gap_count'  => (int) $row->gap_count,
            ];
        }, $rows);

        return [
            'departments' => $departments,
            'skills'      => $skills,
            'ratings'     => $ratings,
            'meta'        => [
                'gap_threshold' => $gapThreshold,
                'generated_at'  => Carbon::now()->toISOString(),
            ],
        ];
    }

    /**
     * Full skill breakdown for one department.
     */
    public function getDepartmentBreakdown(int $departmentId, int $gapThreshold = 3, ?int $subInstituteId = null): array
    {
        $summary = $this->fetchMatrix([$departmentId], null, $gapThreshold, $subInstituteId);
        if (empty($summary)) return [];

        $dept   = DB::table('hrms_departments')->find($departmentId);
        $skills = $this->extractDimension($summary, 'skill_id', 'skill_name');

        return [
            'department' => [
                'id'   => $departmentId,
                'name' => $dept?->department ?? 'Unknown',
            ],
            'skills'     => $skills,
            'ratings'    => array_map(fn($r) => $this->formatRow($r), $summary),
            'meta'       => [
                'gap_threshold' => $gapThreshold,
                'generated_at'  => Carbon::now()->toISOString(),
            ],
        ];
    }

    /**
     * One skill across all departments.
     */
    public function getSkillBreakdown(int $skillId, int $gapThreshold = 3, ?int $subInstituteId = null): array
    {
        $subInstituteFilter = '';
        $bindings = [$gapThreshold, $skillId];
        
        if ($subInstituteId) {
            $subInstituteFilter = 'AND u.sub_institute_id = ?';
            $bindings[] = $subInstituteId;
        }

        $rows = DB::select("
            SELECT
                d.id                                           AS dept_id,
                d.department                                    AS dept_name,
                ss.id                                          AS skill_id,
                ss.title                                       AS skill_name,
                ROUND(AVG(
                    CAST(JSON_UNQUOTE(
                        JSON_EXTRACT(urd.skill_ids, CONCAT('$.\"', ss.id, '\"'))
                    ) AS DECIMAL(4,2))
                ), 2)                                          AS avg_rating,
                COUNT(DISTINCT urd.user_id)                    AS user_count,
                SUM(
                    CASE 
                        WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(urd.skill_ids, CONCAT('$.\"', ss.id, '\"'))) AS DECIMAL(4,2)) < ?
                        THEN 1 ELSE 0 END
                )                                              AS gap_count
            FROM hrms_departments d
            JOIN tbluser u ON u.department_id = d.id
            JOIN user_rating_details urd ON urd.user_id = u.id
            CROSS JOIN s_users_skills ss
            WHERE ss.id = ?
              AND JSON_CONTAINS_PATH(urd.skill_ids, 'one', CONCAT('$.\"', ss.id, '\"'))
              AND JSON_UNQUOTE(JSON_EXTRACT(urd.skill_ids, CONCAT('$.\"', ss.id, '\"'))) != 'null'
            GROUP BY d.id, d.department, ss.id, ss.title
            ORDER BY d.department
        ", $bindings);

        if (empty($rows)) return [];

        $skill = DB::table('s_users_skills')->find($skillId);

        return [
            'skill'       => ['id' => $skillId, 'name' => $skill?->title ?? 'Unknown'],
            'ratings'     => array_map(fn($r) => $this->formatRow($r), $rows),
            'meta'        => [
                'gap_threshold' => $gapThreshold,
                'generated_at'  => Carbon::now()->toISOString(),
            ],
        ];
    }

    /**
     * Top critical gaps across the entire org.
     */
    public function getCriticalGaps(int $gapThreshold = 3, int $limit = 20, ?int $subInstituteId = null): array
    {
        $subInstituteFilter = '';
        $bindings = [$gapThreshold, $gapThreshold, $limit];
        
        if ($subInstituteId) {
            $subInstituteFilter = 'AND u.sub_institute_id = ?';
            $bindings[] = $subInstituteId;
        }

        $rows = DB::select("
            SELECT
                d.id                                           AS dept_id,
                d.department                                    AS dept_name,
                ss.id                                          AS skill_id,
                ss.title                                       AS skill_name,
                ROUND(AVG(
                    CAST(JSON_UNQUOTE(
                        JSON_EXTRACT(urd.skill_ids, CONCAT('$.\"', ss.id, '\"'))
                    ) AS DECIMAL(4,2))
                ), 2)                                          AS avg_rating,
                COUNT(DISTINCT urd.user_id)                    AS user_count,
                SUM(
                    CASE 
                        WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(urd.skill_ids, CONCAT('$.\"', ss.id, '\"'))) AS DECIMAL(4,2)) < ?
                        THEN 1 ELSE 0 END
                )                                              AS gap_count,
                ROUND(100.0 * SUM(
                    CASE 
                        WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(urd.skill_ids, CONCAT('$.\"', ss.id, '\"'))) AS DECIMAL(4,2)) < ?
                        THEN 1 ELSE 0 END
                ) / NULLIF(COUNT(DISTINCT urd.user_id), 0), 1) AS gap_percentage
            FROM hrms_departments d
            JOIN tbluser u ON u.department_id = d.id
            JOIN user_rating_details urd ON urd.user_id = u.id
            CROSS JOIN s_users_skills ss
            WHERE JSON_CONTAINS_PATH(urd.skill_ids, 'one', CONCAT('$.\"', ss.id, '\"'))
              AND JSON_UNQUOTE(JSON_EXTRACT(urd.skill_ids, CONCAT('$.\"', ss.id, '\"'))) != 'null'
              {$subInstituteFilter}
            GROUP BY d.id, d.department, ss.id, ss.title
            HAVING gap_count > 0
            ORDER BY gap_percentage DESC, avg_rating ASC
            LIMIT ?
        ", $bindings);

        return [
            'gaps' => array_map(function ($r) {
                return [
                    'dept_id'     => (int)   $r->dept_id,
                    'dept_name'   =>         $r->dept_name,
                    'skill_id'    => (int)   $r->skill_id,
                    'skill_name'  =>         $r->skill_name,
                    'avg_rating'  => round((float) $r->avg_rating, 2),
                    'user_count'  => (int)   $r->user_count,
                    'gap_count'   => (int)   $r->gap_count,
                    'gap_pct'     => round((float) $r->gap_percentage, 1),
                ];
            }, $rows),
            'meta' => [
                'gap_threshold' => $gapThreshold,
                'limit'         => $limit,
                'generated_at'  => Carbon::now()->toISOString(),
            ],
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Core matrix query — one row per (dept, skill).
     */
    private function fetchMatrix(
        array $departmentIds = [],
        ?int  $jobroleId     = null,
        int   $gapThreshold  = 3,
        ?int  $subInstituteId = null,
    ): array {
        $subInstituteFilter = '';
        $bindings = [$gapThreshold];
        
        if ($subInstituteId) {
            $subInstituteFilter = 'AND u.sub_institute_id = ?';
            array_push($bindings, $subInstituteId);
        }

        // With department filter
        if (!empty($departmentIds)) {
            $placeholders = implode(',', array_fill(0, count($departmentIds), '?'));
            $allBindings = array_merge([$gapThreshold], $departmentIds);
            if ($subInstituteId) {
                $allBindings[] = $subInstituteId;
            }
            
            return DB::select("
                SELECT
                    d.id                                               AS dept_id,
                    d.department                                       AS dept_name,
                    ss.id                                              AS skill_id,
                    ss.title                                           AS skill_name,
                    ROUND(AVG(
                        CAST(JSON_UNQUOTE(
                            JSON_EXTRACT(urd.skill_ids, CONCAT('$.\"', ss.id, '\"'))
                        ) AS DECIMAL(4,2))
                    ), 2)                                              AS avg_rating,
                    COUNT(DISTINCT urd.user_id)                       AS user_count,
                    SUM(
                        CASE 
                            WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(urd.skill_ids, CONCAT('$.\"', ss.id, '\"'))) AS DECIMAL(4,2)) < ?
                            THEN 1 ELSE 0 END
                    )                                                  AS gap_count
                FROM hrms_departments d
                JOIN tbluser u ON u.department_id = d.id
                JOIN user_rating_details urd ON urd.user_id = u.id
                CROSS JOIN s_users_skills ss
                WHERE d.id IN ({$placeholders})
                  AND JSON_CONTAINS_PATH(urd.skill_ids, 'one', CONCAT('$.\"', ss.id, '\"'))
                  AND JSON_UNQUOTE(JSON_EXTRACT(urd.skill_ids, CONCAT('$.\"', ss.id, '\"'))) != 'null'
                  {$subInstituteFilter}
                GROUP BY d.id, d.department, ss.id, ss.title
                ORDER BY d.department, ss.title
            ", $allBindings);
        }

        // Without department filter
        return DB::select("
            SELECT
                d.id                                               AS dept_id,
                d.department                                       AS dept_name,
                ss.id                                              AS skill_id,
                ss.title                                           AS skill_name,
                ROUND(AVG(
                    CAST(JSON_UNQUOTE(
                        JSON_EXTRACT(urd.skill_ids, CONCAT('$.\"', ss.id, '\"'))
                    ) AS DECIMAL(4,2))
                ), 2)                                              AS avg_rating,
                COUNT(DISTINCT urd.user_id)                       AS user_count,
                SUM(
                    CASE 
                        WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(urd.skill_ids, CONCAT('$.\"', ss.id, '\"'))) AS DECIMAL(4,2)) < ?
                        THEN 1 ELSE 0 END
                )                                                  AS gap_count
            FROM hrms_departments d
            JOIN tbluser u ON u.department_id = d.id
            JOIN user_rating_details urd ON urd.user_id = u.id
            CROSS JOIN s_users_skills ss
            WHERE JSON_CONTAINS_PATH(urd.skill_ids, 'one', CONCAT('$.\"', ss.id, '\"'))
              AND JSON_UNQUOTE(JSON_EXTRACT(urd.skill_ids, CONCAT('$.\"', ss.id, '\"'))) != 'null'
              {$subInstituteFilter}
            GROUP BY d.id, d.department, ss.id, ss.title
            ORDER BY d.department, ss.title
        ", $bindings);
    }

    /**
     * Pull unique {id, name} pairs from a result set.
     */
    private function extractDimension(array $rows, string $idCol, string $nameCol): array
    {
        $seen   = [];
        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row->{$idCol};
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $result[]  = ['id' => $id, 'name' => $row->{$nameCol}];
            }
        }
        return array_values($result);
    }

    private function formatRow(object $row): array
    {
        return [
            'dept_id'    => (int)   $row->dept_id,
            'skill_id'   => (int)   $row->skill_id,
            'avg_rating' => round((float) $row->avg_rating, 2),
            'user_count' => (int)   $row->user_count,
            'gap_count'  => (int)   $row->gap_count,
        ];
    }
}
