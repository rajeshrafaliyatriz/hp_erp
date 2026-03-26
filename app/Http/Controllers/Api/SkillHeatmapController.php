<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SkillHeatmapController extends Controller
{
    /**
     * GET /api/heatmap?sub_institute_id=1
     *
     * Returns department × level counts for the SkillsF Heatmap grid.
     *
     * Response:
     * {
     *   "departments": [
     *     {
     *       "department_id": 5,
     *       "department": "Admin & Maintenance Department",
     *       "levels": { "1": 3, "2": 7, "3": 2, "4": 0, "5": 0, "6": 0 }
     *     },
     *     ...
     *   ]
     * }
     */
    public function heatmap(Request $request)
    {
        $request->validate([
            'sub_institute_id' => 'required|integer',
        ]);

        $subInstituteId = $request->sub_institute_id;

        // Step 1: Get all departments where parent_id > 0 (exclude top-level with parent_id = 0)
        $departments = DB::table('hrms_departments')
            ->where('sub_institute_id', $subInstituteId)
            ->where('parent_id', '>', 0)
            ->orderBy('department')
            ->get(['id as department_id', 'department']);

        if ($departments->isEmpty()) {
            return response()->json(['departments' => []]);
        }

        $deptIds = $departments->pluck('department_id')->toArray();

        // Step 2: Get all users in these departments
        $users = DB::table('tbluser')
            ->where('sub_institute_id', $subInstituteId)
            ->whereIn('department_id', $deptIds)
            ->get(['id as user_id', 'department_id', 'allocated_standards as jobrole_id']);

        // Initialize level counts for every department
        $deptLevelCounts = [];
        foreach ($departments as $dept) {
            $deptLevelCounts[$dept->department_id] = [
                1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0,
            ];
        }

        if ($users->isEmpty()) {
            return response()->json([
                'departments' => $this->shapeDeptResponse($departments, $deptLevelCounts),
            ]);
        }

        $userIds = $users->pluck('user_id')->toArray();

        // Map user_id → department_id for quick lookup
        $userDeptMap = $users->pluck('department_id', 'user_id')->toArray();

        // Step 3: Fetch rating details for all users
        $ratings = DB::table('user_rating_details')
            ->where('sub_institute_id', $subInstituteId)
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'skill_ids']);

        // Step 4: Parse skill_ids JSON and count per department per level
        foreach ($ratings as $rating) {
            $skillMap = $this->parseSkillIds($rating->skill_ids);
            $deptId   = $userDeptMap[$rating->user_id] ?? null;

            if (!$deptId || !isset($deptLevelCounts[$deptId])) {
                continue;
            }

            foreach ($skillMap as $level) {
                $level = (int) $level;
                if ($level >= 1 && $level <= 6) {
                    $deptLevelCounts[$deptId][$level]++;
                }
            }
        }

        return response()->json([
            'departments' => $this->shapeDeptResponse($departments, $deptLevelCounts),
        ]);
    }

    /**
     * GET /api/heatmap/drill?sub_institute_id=1&department_id=5&level=2
     *
     * Returns drill-down detail for a clicked heatmap cell.
     *
     * Response:
     * {
     *   "department": "Admin & Maintenance Department",
     *   "level": 2,
     *   "total_count": 7,
     *   "jobroles": [
     *     {
     *       "jobrole_id": 3154,
     *       "jobrole": "Software Engineer",
     *       "skills": [
     *         {
     *           "skill_id": 2551,
     *           "skill_title": "React",
     *           "proficiency_level": 3,
     *           "users_at_this_level": [
     *             { "user_id": 6, "name": "John Doe", "rated_level": 2 }
     *           ]
     *         }
     *       ]
     *     }
     *   ]
     * }
     */
    public function drill(Request $request)
    {
        $request->validate([
            'sub_institute_id' => 'required|integer',
            'department_id'    => 'required|integer',
            'level'            => 'required|integer|min:1|max:6',
        ]);

        $subInstituteId = $request->sub_institute_id;
        $departmentId   = $request->department_id;
        $targetLevel    = (int) $request->level;

        // Get department name
        $dept = DB::table('hrms_departments')
            ->where('id', $departmentId)
            ->where('sub_institute_id', $subInstituteId)
            ->first(['id', 'department']);

        if (!$dept) {
            return response()->json(['error' => 'Department not found'], 404);
        }

        // Get users in this department with their basic info
        $users = DB::table('tbluser')
            ->where('sub_institute_id', $subInstituteId)
            ->where('department_id', $departmentId)
            ->get([
                'id as user_id',
                DB::raw("CONCAT(first_name, ' ', last_name) as name"),
                'email',
                'mobile',
            ]);

        // Debug info
        $debugInfo = [
            'users_count' => $users->count(),
            'user_ids' => $users->pluck('user_id')->toArray(),
        ];

        if ($users->isEmpty()) {
            return response()->json([
                'department'  => $dept->department,
                'level'       => $targetLevel,
                'total_count' => 0,
                'jobroles'    => [],
                'debug'       => $debugInfo,
            ]);
        }

        $userIds = $users->pluck('user_id')->toArray();
        $userMap = $users->keyBy('user_id'); // user_id → user object

        // Fetch rating details for these users - this contains jobrole_id and skill ratings
        $ratings = DB::table('user_rating_details')
            ->where('sub_institute_id', $subInstituteId)
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'jobrole_id', 'skill_ids']);

        $debugInfo['ratings_count'] = $ratings->count();
        $debugInfo['rating_jobrole_ids'] = $ratings->pluck('jobrole_id')->filter()->unique()->toArray();
        $debugInfo['sample_rating'] = $ratings->first() ? $ratings->first()->skill_ids : null;

        if ($ratings->isEmpty()) {
            return response()->json([
                'department'  => $dept->department,
                'level'       => $targetLevel,
                'total_count' => 0,
                'jobroles'    => [],
                'debug'       => $debugInfo,
            ]);
        }

        // Get unique jobrole IDs from ratings
        $ratingJobroleIds = $ratings->pluck('jobrole_id')->filter()->unique()->toArray();

        // Fetch jobrole names from s_user_jobrole
        $jobroleMap = [];
        if (!empty($ratingJobroleIds)) {
            $jobroleMap = DB::table('s_user_jobrole')
                ->whereIn('id', $ratingJobroleIds)
                ->where('sub_institute_id', $subInstituteId)
                ->pluck('jobrole', 'id')
                ->toArray();
        }

        // Fetch expected skills per jobrole from s_user_skill_jobrole
        // The jobrole field stores the jobrole ID, skill stores skill ID, proficiency_level is expected level
        $jobroleSkillMap = []; // jobrole_id → [ skill_id → proficiency_level from s_user_skill_jobrole ]
        $debugInfo['jobrole_ids_to_query'] = $ratingJobroleIds;
        
        if (!empty($ratingJobroleIds)) {
            // Convert to strings for query since jobrole might be stored as string
            $ratingJobroleIdsStr = array_map('strval', $ratingJobroleIds);
            
            $jSkills = DB::table('s_user_skill_jobrole')
                ->whereIn('jobrole', $ratingJobroleIdsStr)
                ->get(['jobrole', 'skill', 'proficiency_level']);

            $debugInfo['expected_skills_count'] = $jSkills->count();
            $debugInfo['expected_skills'] = $jSkills->toArray();

            foreach ($jSkills as $row) {
                // Store with both string and int keys for flexibility
                // Use proficiency_level from s_user_skill_jobrole table as the expected level
                $jobroleSkillMap[$row->jobrole][$row->skill] = $row->proficiency_level;
                $jobroleSkillMap[(int)$row->jobrole][$row->skill] = $row->proficiency_level;
                $jobroleSkillMap[$row->jobrole][(int)$row->skill] = $row->proficiency_level;
                $jobroleSkillMap[(int)$row->jobrole][(int)$row->skill] = $row->proficiency_level;
            }
        }

        // Collect all skill IDs from jobrole skills
        $allSkillIds = [];
        foreach ($jobroleSkillMap as $skills) {
            foreach ($skills as $skillId => $expectedLevel) {
                $allSkillIds[] = $skillId;
            }
        }
        // Also add skills from the ratings
        foreach ($ratings as $rating) {
            $skillMap = $this->parseSkillIds($rating->skill_ids);
            foreach ($skillMap as $skillId => $level) {
                $allSkillIds[] = $skillId;
            }
        }
        $allSkillIds = array_unique($allSkillIds);

        $debugInfo['all_skill_ids'] = $allSkillIds;
        $debugInfo['jobrole_skill_map'] = $jobroleSkillMap;

        // Fetch skill titles from s_users_skills
        $skillTitleMap = [];
        $skillProficiencyMap = []; // skill_id → proficiency_level from s_users_skills
        if (!empty($allSkillIds)) {
            // Convert string skill IDs to int for lookup
            $intSkillIds = array_map('intval', array_filter($allSkillIds, 'is_numeric'));
            if (!empty($intSkillIds)) {
                $skillData = DB::table('s_users_skills')
                    ->whereIn('id', $intSkillIds)
                    ->where('sub_institute_id', $subInstituteId)
                    ->get(['id', 'title', 'proficiency_level']);
                
                foreach ($skillData as $skill) {
                    $skillTitleMap[$skill->id] = $skill->title;
                    // Store proficiency_level from s_users_skills table
                    $skillProficiencyMap[$skill->id] = $skill->proficiency_level;
                }
                
                $debugInfo['skill_titles_found'] = $skillTitleMap;
                $debugInfo['skill_proficiency_found'] = $skillProficiencyMap;
            }
        }

        // Build drill-down result
        // Structure: jobrole_id → skill_id → [ ...users ]
        $result     = [];
        $totalCount = 0;

        foreach ($ratings as $rating) {
            $user = $userMap[$rating->user_id] ?? null;
            if (!$user) continue;

            $skillMap = $this->parseSkillIds($rating->skill_ids);
            $jobroleId = $rating->jobrole_id;
            
            if (!$jobroleId) continue;

            // Try all possible key combinations to get expected skills
            $expectedSkills = 
                $jobroleSkillMap[$jobroleId] ?? 
                $jobroleSkillMap[(int)$jobroleId] ?? 
                $jobroleSkillMap[(string)$jobroleId] ?? 
                [];

            $debugInfo['lookup_keys_tried'] = [$jobroleId, (int)$jobroleId, (string)$jobroleId];
            $debugInfo['expectedSkills_found'] = $expectedSkills;

            $hasExpectedSkills = !empty($expectedSkills);

            // If expected skills are defined, use them; otherwise use all skills from rating
            $skillsToCheck = $hasExpectedSkills ? $expectedSkills : $skillMap;

            foreach ($skillsToCheck as $skillId => $expectedLevel) {
                // skill_ids keys may be int or string — check both
                $ratedLevel = null;
                if (isset($skillMap[$skillId])) {
                    $ratedLevel = (int) $skillMap[$skillId];
                } elseif (isset($skillMap[(string)$skillId])) {
                    $ratedLevel = (int) $skillMap[(string)$skillId];
                } elseif (isset($skillMap[(int)$skillId])) {
                    $ratedLevel = (int) $skillMap[(int)$skillId];
                }

                // Only include users at the target level
                if ($ratedLevel !== $targetLevel) {
                    continue;
                }

                // Initialize jobrole bucket
                if (!isset($result[$jobroleId])) {
                    $result[$jobroleId] = [
                        'jobrole_id' => $jobroleId,
                        'jobrole'    => $jobroleMap[$jobroleId] ?? "Jobrole #{$jobroleId}",
                        'skills'     => [],
                    ];
                }

                // Initialize skill bucket
                $skillKey = is_numeric($skillId) ? (int)$skillId : $skillId;
                
                // Get proficiency_level from s_users_skills table first, fallback to jobrole skill
                $proficiencyLevel = null;
                if (isset($skillProficiencyMap[$skillKey])) {
                    $proficiencyLevel = $skillProficiencyMap[$skillKey];
                } elseif ($hasExpectedSkills) {
                    $proficiencyLevel = (int) $expectedLevel;
                }
                
                if (!isset($result[$jobroleId]['skills'][$skillKey])) {
                    $result[$jobroleId]['skills'][$skillKey] = [
                        'skill_id'            => $skillKey,
                        'skill_title'         => $skillTitleMap[$skillKey] ?? (is_numeric($skillId) ? "Skill #{$skillId}" : $skillId),
                        'proficiency_level'   => $proficiencyLevel, // Get from s_users_skills table
                        'users_at_this_level' => [],
                    ];
                }

                $result[$jobroleId]['skills'][$skillKey]['users_at_this_level'][] = [
                    'user_id'     => $user->user_id,
                    'name'        => $user->name,
                    'email'       => $user->email ?? null,
                    'mobile'      => $user->mobile ?? null,
                    'rated_level' => $ratedLevel,
                ];

                $totalCount++;
            }
        }

        // Re-index skills arrays (remove associative keys for JSON output)
        $jobrolesOut = array_values(array_map(function ($jr) {
            $jr['skills'] = array_values($jr['skills']);
            return $jr;
        }, $result));

        return response()->json([
            'department'  => $dept->department,
            'level'       => $targetLevel,
            'total_count' => $totalCount,
            'jobroles'    => $jobrolesOut,
        ]);
    }

    // ─── PRIVATE HELPERS ──────────────────────────────────────────────────────

    /**
     * Parse skill_ids column.
     * It is stored as a JSON object: { "skill_id": "level", ... }
     * mysql2/Laravel may return it as a string or already-decoded stdClass/array.
     *
     * Returns associative array: [ skill_id => level ]
     */
    private function parseSkillIds($raw): array
    {
        if (empty($raw)) return [];

        if (is_array($raw)) return $raw;

        if (is_object($raw)) return (array) $raw;

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Shape the final departments response array.
     */
    private function shapeDeptResponse($departments, array $deptLevelCounts): array
    {
        return $departments->map(function ($dept) use ($deptLevelCounts) {
            return [
                'department_id' => $dept->department_id,
                'department'    => $dept->department,
                'levels'        => $deptLevelCounts[$dept->department_id] ?? [
                    1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0,
                ],
            ];
        })->toArray();
    }
}