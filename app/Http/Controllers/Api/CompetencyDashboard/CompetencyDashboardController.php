<?php

namespace App\Http\Controllers\Api\CompetencyDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompetencyDashboardController extends Controller
{
    public function getWorkloadHeatmap(Request $request)
    {
        $subInstituteId = $request->sub_institute_id;

        if (!$subInstituteId) {
            return response()->json([
                "status" => false,
                "message" => "sub_institute_id is required"
            ], 400);
        }

        // Get distinct jobroles
        $jobroles = DB::table('s_user_jobrole')
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->select('jobrole')
            ->distinct()
            ->get();

        $results = [];

        foreach ($jobroles as $jr) {
            // Count tasks for this jobrole
            $tasks = DB::table('s_user_jobrole_task')
                ->where('jobrole', $jr->jobrole)
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->count();
            
            // Count skills for this jobrole
            $skills = DB::table('s_user_skill_jobrole')
                ->where('jobrole', $jr->jobrole)
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->count();
            
            // Calculate workloadIndex as (tasks / skills) * 100
            $workloadIndex = $skills > 0 ? round(($tasks / $skills) * 10,2) : 0;
        

            $results[] = [
                'role' => $jr->jobrole,
                'workloadIndex' => $workloadIndex,
                'tasks' => $tasks,
                'skills' => $skills
            ];
        }

        // Sort by workloadIndex descending and take top 10
        usort($results, function($a, $b) {
            return $b['workloadIndex'] <=> $a['workloadIndex'];
        });
        $results = array_slice($results, 0, 10);

        return response()->json($results);
    }
    public function getRoleSimilarity(Request $request)
    {
        $threshold = $request->input('threshold', 0.5); // 50% default similarity
        $department = $request->input('department', null);
        $subInstituteId = $request->input('sub_institute_id', null);
        $limit = $request->input('limit', 50); // Limit nodes to prevent large responses

        try {
            // 1️⃣ First get jobroles with their skill counts and take top 10
            $rolesWithSkillsQuery = DB::table('s_jobrole_skills as sjs')
                ->join('s_user_jobrole as jr', 'sjs.jobrole', '=', 'jr.jobrole')
                ->leftJoin('hrms_departments as hd', 'jr.department_id', '=', 'hd.id')
                ->select('jr.id', 'jr.jobrole as name', 'hd.department as department_name', 'jr.department',
                    DB::raw('COUNT(DISTINCT sjs.skill) as skill_count'))
                ->whereNull('jr.deleted_at')
                ->groupBy('jr.id', 'jr.jobrole', 'hd.department', 'jr.department');

            // Add sub_institute_id filter if provided
            if ($subInstituteId) {
                $rolesWithSkillsQuery->where('jr.sub_institute_id', $subInstituteId);
            }

            if ($department && $department !== 'All') {
                $rolesWithSkillsQuery->where('hd.department', $department);
            }

            // Order by skill count descending and take top 10
            $rolesWithSkills = $rolesWithSkillsQuery->orderByDesc('skill_count')->limit(20)->get();

            // If no roles found with skills, try to get any roles
            if ($rolesWithSkills->isEmpty()) {
                $rolesQuery = DB::table('s_user_jobrole as jr')
                    ->leftJoin('hrms_departments as hd', 'jr.department_id', '=', 'hd.id')
                    ->select('jr.id', 'jr.jobrole as name', 'hd.department as department_name', 'jr.department')
                    ->whereNull('jr.deleted_at')
                    ->limit(20);

                if ($subInstituteId) {
                    $rolesQuery->where('jr.sub_institute_id', $subInstituteId);
                }

                if ($department && $department !== 'All') {
                    $rolesQuery->where('hd.department', $department);
                }

                $roles = $rolesQuery->get();
            } else {
                $roles = $rolesWithSkills;
            }

            // 2️⃣ Fetch jobrole-skill mappings only for the selected roles
            $roleNames = $roles->pluck('name')->toArray();
            $roleSkills = DB::table('s_jobrole_skills')
                ->whereIn('jobrole', $roleNames)
                ->select('jobrole', 'skill')
                ->get();

            // 3️⃣ Group skills by jobrole
            $roleSkillMap = [];
            foreach ($roleSkills as $rs) {
                $roleSkillMap[$rs->jobrole][] = $rs->skill;
            }

            // 4️⃣ Calculate similarity between jobroles (Jaccard index)
            $edges = [];
            $roleNames = $roles->pluck('name')->toArray();
            $maxEdges = min(200, count($roleNames) * 2); // Limit edges to prevent large responses

            foreach ($roleNames as $i => $r1) {
                for ($j = $i + 1; $j < count($roleNames); $j++) {
                    $r2 = $roleNames[$j];
                    $skills1 = $roleSkillMap[$r1] ?? [];
                    $skills2 = $roleSkillMap[$r2] ?? [];

                    if (empty($skills1) || empty($skills2)) continue;

                    $intersection = count(array_intersect($skills1, $skills2));
                    $union = count(array_unique(array_merge($skills1, $skills2)));
                    $similarity = $union > 0 ? $intersection / $union : 0;

                    if ($similarity >= $threshold) {
                        $edges[] = [
                            'source' => $r1,
                            'target' => $r2,
                            'similarity' => round($similarity * 100, 2)
                        ];

                        // Break if we've reached the edge limit
                        if (count($edges) >= $maxEdges) break 2;
                    }
                }
            }

            // 5️⃣ Format nodes with colors based on department
            $departmentColors = [];
            $colorPalette = ['#1A5276', '#922B21', '#1B4F72', '#7D3C98', '#196F3D', '#B7950B', '#784212', '#5D6D7E', '#C0392B', '#2E86C1'];
            $colorIndex = 0;

            $nodes = $roles->map(function ($role) use (&$departmentColors, &$colorPalette, &$colorIndex) {
                $deptName = $role->department_name ?? $role->department ?? 'Unknown';

                if (!isset($departmentColors[$deptName])) {
                    $departmentColors[$deptName] = $colorPalette[$colorIndex % count($colorPalette)];
                    $colorIndex++;
                }

                return [
                    'id' => $role->name,
                    'label' => $role->name,
                    'department' => $deptName,
                    'color' => $departmentColors[$deptName],
                    'importance' => rand(1, 5)
                ];
            });

            // 6️⃣ Final response
            return response()->json([
                'nodes' => $nodes,
                'edges' => $edges
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch role similarity data',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function getCoverageScorecards(Request $request)
    {
        $subInstituteId = $request->input('sub_institute_id', null);

        try {
            // Base query for filtering by sub_institute_id if provided
            $baseQuery = DB::table('s_user_jobrole as jr')->whereNull('jr.deleted_at');
            if ($subInstituteId) {
                $baseQuery->where('jr.sub_institute_id', $subInstituteId);
            }

            // 1. Roles with Mapped Tasks (87% / 95%)
            $totalRoles = (clone $baseQuery)->count();
            $rolesWithTasks = (clone $baseQuery)
                ->join('s_user_jobrole_task as jt', function($join) {
                    $join->on('jr.jobrole', '=', 'jt.jobrole')
                         ->whereNull('jt.deleted_at');
                })
                ->distinct('jr.id')
                ->count();

            $rolesWithTasksPercentage = $totalRoles > 0 ? min(100, round(($rolesWithTasks / $totalRoles) * 100, 1)) : 0;
            $rolesWithTasksTarget = 95;

            // 2. Tasks with Mapped Skills (92% / 95%)
            $totalTasks = DB::table('s_user_jobrole_task')
                ->whereNull('deleted_at')
                ->when($subInstituteId, function($query) use ($subInstituteId) {
                    return $query->where('sub_institute_id', $subInstituteId);
                })
                ->count();

            $tasksWithSkills = DB::table('s_user_jobrole_task as t')
                ->whereNull('t.deleted_at')
                ->when($subInstituteId, function($query) use ($subInstituteId) {
                    return $query->where('t.sub_institute_id', $subInstituteId);
                })
                ->whereExists(function($query) {
                    $query->select(DB::raw(1))
                          ->from('s_jobrole_skills as s')
                          ->whereRaw('t.jobrole = s.jobrole');
                })
                ->count();

            $tasksWithSkillsPercentage = $totalTasks > 0 ? min(100, round(($tasksWithSkills / $totalTasks) * 100, 1)) : 0;
            $tasksWithSkillsTarget = 95;

            // 3. Skills with Proficiency Levels (74% / 85%)
            $totalSkills = DB::table('s_jobrole_skills')
                ->when($subInstituteId, function($query) use ($subInstituteId) {
                    return $query->join('s_user_jobrole', 's_jobrole_skills.jobrole', '=', 's_user_jobrole.jobrole')
                                 ->where('s_user_jobrole.sub_institute_id', $subInstituteId)
                                 ->whereNull('s_user_jobrole.deleted_at');
                })
                ->count();

            $skillsWithProficiency = DB::table('s_jobrole_skills as sjs')
                ->whereNotNull('sjs.proficiency_level')
                ->where('sjs.proficiency_level', '!=', '')
                ->when($subInstituteId, function($query) use ($subInstituteId) {
                    return $query->join('s_user_jobrole as jr', 'sjs.jobrole', '=', 'jr.jobrole')
                                 ->where('jr.sub_institute_id', $subInstituteId)
                                 ->whereNull('jr.deleted_at');
                })
                ->count();

            $skillsWithProficiencyPercentage = $totalSkills > 0 ? min(100, round(($skillsWithProficiency / $totalSkills) * 100, 1)) : 0;
            $skillsWithProficiencyTarget = 85;

            // 4. Attitudes/Behavior Mapped (45% / 70%)
            // This might be stored in a separate table or field - using placeholder for now
            $attitudesBehaviorPercentage = 45; // Placeholder - needs actual table/field
            $attitudesBehaviorTarget = 70;

            // 5. External Framework Alignment (89% / 90%)
            // This might involve checking if skills are mapped to external standards
            $externalFrameworkPercentage = 89; // Placeholder - needs actual logic
            $externalFrameworkTarget = 90;

            // 6. Competency Documentation (91% / 95%)
            $rolesWithDescription = (clone $baseQuery)
                ->whereNotNull('description')
                ->where('description', '!=', '')
                ->count();

            $competencyDocumentationPercentage = $totalRoles > 0 ? min(100, round(($rolesWithDescription / $totalRoles) * 100, 1)) : 0;
            $competencyDocumentationTarget = 95;

            // Calculate overall percentage (ensure it's also capped at 100%)
            $metrics = [
                $rolesWithTasksPercentage,
                $tasksWithSkillsPercentage,
                $skillsWithProficiencyPercentage,
                $attitudesBehaviorPercentage,
                $externalFrameworkPercentage,
                $competencyDocumentationPercentage
            ];

            $overallPercentage = min(100, round(array_sum($metrics) / count($metrics), 1));

            $response = [
                'overall' => $overallPercentage,
                'metrics' => [
                    [
                        'name' => 'Roles with Mapped Tasks',
                        'current' => $rolesWithTasksPercentage,
                        'target' => $rolesWithTasksTarget,
                        'display' => $rolesWithTasksPercentage . '% / ' . $rolesWithTasksTarget . '%'
                    ],
                    [
                        'name' => 'Tasks with Mapped Skills',
                        'current' => $tasksWithSkillsPercentage,
                        'target' => $tasksWithSkillsTarget,
                        'display' => $tasksWithSkillsPercentage . '% / ' . $tasksWithSkillsTarget . '%'
                    ],
                    [
                        'name' => 'Skills with Proficiency Levels',
                        'current' => $skillsWithProficiencyPercentage,
                        'target' => $skillsWithProficiencyTarget,
                        'display' => $skillsWithProficiencyPercentage . '% / ' . $skillsWithProficiencyTarget . '%'
                    ],
                    [
                        'name' => 'Attitudes/Behavior Mapped',
                        'current' => $attitudesBehaviorPercentage,
                        'target' => $attitudesBehaviorTarget,
                        'display' => $attitudesBehaviorPercentage . '% / ' . $attitudesBehaviorTarget . '%'
                    ],
                    [
                        'name' => 'External Framework Alignment',
                        'current' => $externalFrameworkPercentage,
                        'target' => $externalFrameworkTarget,
                        'display' => $externalFrameworkPercentage . '% / ' . $externalFrameworkTarget . '%'
                    ],
                    [
                        'name' => 'Competency Documentation',
                        'current' => $competencyDocumentationPercentage,
                        'target' => $competencyDocumentationTarget,
                        'display' => $competencyDocumentationPercentage . '% / ' . $competencyDocumentationTarget . '%'
                    ]
                ],
                'raw_data' => [
                    'total_roles' => $totalRoles,
                    'roles_with_tasks' => $rolesWithTasks,
                    'total_tasks' => $totalTasks,
                    'tasks_with_skills' => $tasksWithSkills,
                    'total_skills' => $totalSkills,
                    'skills_with_proficiency' => $skillsWithProficiency,
                    'roles_with_description' => $rolesWithDescription
                ]
            ];

            return response()->json($response);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch coverage scorecards',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function getHealthRadar(Request $request)
    {
        $subInstituteId = $request->input('sub_institute_id', null);

        try {
            // Base query for filtering by sub_institute_id if provided
            $baseQuery = DB::table('s_user_jobrole as jr')->whereNull('jr.deleted_at');
            if ($subInstituteId) {
                $baseQuery->where('jr.sub_institute_id', $subInstituteId);
            }

            // Compute metrics
            $totalRoles = (clone $baseQuery)->count();
            $rolesWithTasks = (clone $baseQuery)
                ->join('s_user_jobrole_task as jt', function($join) {
                    $join->on('jr.jobrole', '=', 'jt.jobrole')
                         ->whereNull('jt.deleted_at');
                })
                ->distinct('jr.id')
                ->count();
            $taskMappingCurrent = $totalRoles > 0 ? round(($rolesWithTasks / $totalRoles) * 100, 1) : 0;
            $taskMappingTarget = 95;

            $totalTasks = DB::table('s_user_jobrole_task')
                ->whereNull('deleted_at')
                ->when($subInstituteId, function($query) use ($subInstituteId) {
                    return $query->where('sub_institute_id', $subInstituteId);
                })
                ->count();
            $tasksWithSkills = DB::table('s_user_jobrole_task as t')
                ->whereNull('t.deleted_at')
                ->when($subInstituteId, function($query) use ($subInstituteId) {
                    return $query->where('t.sub_institute_id', $subInstituteId);
                })
                ->whereExists(function($query) {
                    $query->select(DB::raw(1))
                          ->from('s_jobrole_skills as s')
                          ->whereRaw('t.jobrole = s.jobrole');
                })
                ->count();
            $skillCoverageCurrent = $totalTasks > 0 ? round(($tasksWithSkills / $totalTasks) * 100, 1) : 0;
            $skillCoverageTarget = 95;

            // Behavior Mapping: skills with behaviour mapped
            $totalSkillsForBehavior = DB::table('s_skill_knowledge_ability')
                ->where('classification', 'behaviour')
                ->when($subInstituteId, function($query) use ($subInstituteId) {
                    return $query->where('sub_institute_id', $subInstituteId);
                })
                ->whereNull('deleted_at')
                ->distinct('skill_id')
                ->count('skill_id');
            $skillsWithBehavior = DB::table('s_skill_knowledge_ability')
                ->where('classification', 'behaviour')
                ->whereNotNull('classification_item')
                ->where('classification_item', '!=', '')
                ->when($subInstituteId, function($query) use ($subInstituteId) {
                    return $query->where('sub_institute_id', $subInstituteId);
                })
                ->whereNull('deleted_at')
                ->distinct('skill_id')
                ->count('skill_id');
            $behaviorMappingCurrent = $totalSkillsForBehavior > 0 ? round(($skillsWithBehavior / $totalSkillsForBehavior) * 100, 1) : 0;
            $behaviorMappingTarget = 70;

            $externalAlignmentCurrent = 89; // Placeholder - needs actual logic for O*NET mapping
            $externalAlignmentTarget = 90;

            $totalSkills = DB::table('s_jobrole_skills')
                ->when($subInstituteId, function($query) use ($subInstituteId) {
                    return $query->join('s_user_jobrole', 's_jobrole_skills.jobrole', '=', 's_user_jobrole.jobrole')
                                 ->where('s_user_jobrole.sub_institute_id', $subInstituteId)
                                 ->whereNull('s_user_jobrole.deleted_at');
                })
                ->count();
            $skillsWithProficiency = DB::table('s_jobrole_skills as sjs')
                ->whereNotNull('sjs.proficiency_level')
                ->where('sjs.proficiency_level', '!=', '')
                ->when($subInstituteId, function($query) use ($subInstituteId) {
                    return $query->join('s_user_jobrole as jr', 'sjs.jobrole', '=', 'jr.jobrole')
                                 ->where('jr.sub_institute_id', $subInstituteId)
                                 ->whereNull('jr.deleted_at');
                })
                ->count();
            $riskAssessmentCurrent = $totalSkills > 0 ? round(($skillsWithProficiency / $totalSkills) * 100, 1) : 0;
            $riskAssessmentTarget = 85;

            $rolesWithDescription = (clone $baseQuery)
                ->whereNotNull('description')
                ->where('description', '!=', '')
                ->count();
            $futureReadinessCurrent = $totalRoles > 0 ? round(($rolesWithDescription / $totalRoles) * 100, 1) : 0;
            $futureReadinessTarget = 95;

            $overallCurrent = round(($taskMappingCurrent + $skillCoverageCurrent + $behaviorMappingCurrent + $externalAlignmentCurrent + $riskAssessmentCurrent + $futureReadinessCurrent) / 6, 1);
            $overallTarget = 90;

            $data = [
                [
                    'area' => 'Current vs Target Coverage',
                    'current' => $overallCurrent,
                    'target' => $overallTarget,
                    'fullMark' => 100
                ],
                [
                    'area' => 'Task Mapping',
                    'current' => $taskMappingCurrent,
                    'target' => $taskMappingTarget,
                    'fullMark' => 100
                ],
                [
                    'area' => 'Skill Coverage',
                    'current' => $skillCoverageCurrent,
                    'target' => $skillCoverageTarget,
                    'fullMark' => 100
                ],
                [
                    'area' => 'Behavior Mapping',
                    'current' => $behaviorMappingCurrent,
                    'target' => $behaviorMappingTarget,
                    'fullMark' => 100
                ],
                [
                    'area' => 'External Alignment',
                    'current' => $externalAlignmentCurrent,
                    'target' => $externalAlignmentTarget,
                    'fullMark' => 100
                ],
                [
                    'area' => 'Risk Assessment',
                    'current' => $riskAssessmentCurrent,
                    'target' => $riskAssessmentTarget,
                    'fullMark' => 100
                ],
                [
                    'area' => 'Future Readiness',
                    'current' => $futureReadinessCurrent,
                    'target' => $futureReadinessTarget,
                    'fullMark' => 100
                ]
            ];

            // Process data to compute differences & classifications
            $processed = collect($data)->map(function ($item) {
                $difference = $item['current'] - $item['target'];
                $classification = $difference >= 0 ? 'strength' : 'gap';

                return [
                    'area' => $item['area'],
                    'current' => $item['current'],
                    'target' => $item['target'],
                    'fullMark' => $item['fullMark'],
                    'difference' => $difference,
                    'classification' => $classification
                ];
            });

            // Separate strengths & coverage gaps
            $strengths = $processed->where('classification', 'strength')
                                   ->sortByDesc('current')
                                   ->values()
                                   ->take(3); // top 3 strengths
            $gaps = $processed->where('classification', 'gap')
                              ->sortBy('difference')
                              ->values()
                              ->take(3); // top 3 weakest

            // Compute overall health score
            $avgCurrent = round($processed->avg('current'), 2);
            $avgTarget = round($processed->avg('target'), 2);
            $healthScore = round(($avgCurrent / $avgTarget) * 100, 1);

            // Return formatted JSON
            return response()->json([
                'status' => 'success',
                'summary' => [
                    'average_current' => $avgCurrent,
                    'average_target' => $avgTarget,
                    'health_score' => $healthScore,
                ],
                'data' => $processed,
                'strengths' => $strengths,
                'gaps' => $gaps
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch competency health radar data.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getSkillsManagementFunnel(Request $request)
    {
        $subInstituteId = $request->input('sub_institute_id', null);

        try {
            // Base query for s_users_skills
            $skillsQuery = DB::table('s_users_skills')->whereNull('deleted_at');
            if ($subInstituteId) {
                $skillsQuery->where('sub_institute_id', $subInstituteId);
            }

            // Identified Orphans: total skills
            $total = (clone $skillsQuery)->count();

            // In Review: skills with approve_status = 'Pending'
            $inReview = (clone $skillsQuery)->where('approve_status', 'Pending')->count();

            // Mapped Candidates: distinct skills mapped to users in s_skill_matrix
            $mappedQuery = DB::table('s_skill_matrix')
                ->join('s_users_skills', 's_skill_matrix.skill_id', '=', 's_users_skills.id')
                ->whereNull('s_skill_matrix.deleted_at')
                ->whereNull('s_users_skills.deleted_at');
            if ($subInstituteId) {
                $mappedQuery->where('s_users_skills.sub_institute_id', $subInstituteId);
            }
            $mapped = $mappedQuery->distinct('s_skill_matrix.skill_id')->count('s_skill_matrix.skill_id');

            // Approved & Integrated: distinct skills in s_jobrole_skills for jobroles in sub_institute, joined to s_users_skills
            $approvedQuery = DB::table('s_jobrole_skills')
                ->join('s_user_jobrole', 's_jobrole_skills.jobrole', '=', 's_user_jobrole.jobrole')
                ->join('s_users_skills', 's_jobrole_skills.skill', '=', 's_users_skills.title')
                ->whereNull('s_user_jobrole.deleted_at')
                ->whereNull('s_users_skills.deleted_at');
            if ($subInstituteId) {
                $approvedQuery->where('s_users_skills.sub_institute_id', $subInstituteId);
            }
            $approved = $approvedQuery->distinct('s_jobrole_skills.skill')->count('s_jobrole_skills.skill');

            // Calculate filtered and percentages
            $filteredIdentified = $total - $inReview;
            $filteredIdentifiedPercentage = $total > 0 ? round(($filteredIdentified / $total) * 100, 0) : 0;

            $filteredInReview = $inReview - $mapped;
            $filteredInReviewPercentage = $inReview > 0 ? round(($filteredInReview / $inReview) * 100, 0) : 0;

            $filteredMapped = $mapped - $approved;
            $filteredMappedPercentage = $mapped > 0 ? round(($filteredMapped / $mapped) * 100, 0) : 0;

            $completionRate = $total > 0 ? round(($approved / $total) * 100, 0) : 0;

            $data = [
                [
                    'stage' => 'Identified Orphans',
                    'count' => $total,
                    'percentage' => '100% of total',
                    'filtered' => -$filteredIdentified,
                    'filteredPercentage' => '(' . min(100, $filteredIdentifiedPercentage) . '%)'
                ],
                [
                    'stage' => 'In Review',
                    'count' => $inReview,
                    'percentage' => ($total > 0 ? min(100, round(($inReview / $total) * 100, 0)) : 0) . '% of total',
                    'filtered' => -$filteredInReview,
                    'filteredPercentage' => '(' . min(100, $filteredInReviewPercentage) . '%)'
                ],
                [
                    'stage' => 'Mapped Candidates',
                    'count' => $mapped,
                    'percentage' => ($total > 0 ? min(100, round(($mapped / $total) * 100, 0)) : 0) . '% of total',
                    'filtered' => -$filteredMapped,
                    'filteredPercentage' => '(' . min(100, $filteredMappedPercentage) . '%)'
                ],
                [
                    'stage' => 'Approved & Integrated',
                    'count' => $approved,
                    'percentage' => ($total > 0 ? min(100, round(($approved / $total) * 100, 0)) : 0) . '% of total'
                ]
            ];

            return response()->json([
                'title' => 'Skills Management Funnel',
                'completionRate' => $completionRate . '%',
                'data' => $data
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch skills management funnel data.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getAlignment(Request $request)
    {
        $type = $request->query('type', 'o'); // default type
        $type = strtolower($type);
        $subInstituteId = $request->input('sub_institute_id', null);

        // Map type to framework
        $frameworkMap = [
            'o' => 'onet',
            's' => 'singapore'
        ];
        $framework = $frameworkMap[$type] ?? 'onet';

        try {
            // Validate supported types
            $validTypes = ['o', 's'];
            if (!in_array($type, $validTypes)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid type. Supported: o (onet), s (skillsfuture).'
                ], 400);
            }

            // Fetch aggregated alignment data from s_jobrole_skills
            $result = DB::table('s_jobrole_skills')
                ->join('s_user_jobrole', 's_jobrole_skills.jobrole', '=', 's_user_jobrole.jobrole')
                ->whereNull('s_user_jobrole.deleted_at')
                ->where('s_jobrole_skills.type', $type)
                ->when($subInstituteId, function($query) use ($subInstituteId) {
                    return $query->where('s_user_jobrole.sub_institute_id', $subInstituteId);
                })
                ->selectRaw('
                    COUNT(DISTINCT s_jobrole_skills.skill) AS aligned,
                    0 AS partial,
                    0 AS not_aligned,
                    COUNT(DISTINCT s_jobrole_skills.skill) AS total
                ')
                ->first();

            if (!$result || $result->total == 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No data found for selected framework.',
                ], 404);
            }

            // Calculate percentage aligned (including partial alignment)
            $percentage = round((($result->aligned + $result->partial) / $result->total) * 100, 2);

            // Framework meta versions
            $frameworkVersions = [
                'onet' => 'v28.0',
                'singapore' => 'v2024',
                'esco' => 'v2025'
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'framework' => strtoupper($framework),
                    'version' => $frameworkVersions[$framework] ?? 'v1.0',
                    'percentage' => $percentage,
                    'aligned' => (int)$result->aligned,
                    'partial' => (int)$result->partial,
                    'notAligned' => (int)$result->not_aligned,
                    'total' => (int)$result->total
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getKPI(Request $request)
    {
        $subInstituteId = $request->input('sub_institute_id');
        $userId = $request->input('user_id');

        try {
            // Base query for s_user_jobrole
            $rolesQuery = DB::table('s_user_jobrole as jr')->whereNull('jr.deleted_at');
            if ($subInstituteId) {
                $rolesQuery->where('jr.sub_institute_id', $subInstituteId);
            }

            // Total roles count
            $totalRoles = $rolesQuery->count();

            // Count distinct departments from hrms_departments via s_user_jobrole
            $departmentsCount = DB::table('hrms_departments as d')
                ->join('s_user_jobrole as jr', 'd.id', '=', 'jr.department_id')
                ->whereNull('jr.deleted_at')
                ->when($subInstituteId, function ($q) use ($subInstituteId) {
                    return $q->where('jr.sub_institute_id', $subInstituteId);
                })
                ->distinct('d.id')
                ->count('d.id');

            // Total mapped tasks count from s_user_jobrole_task
            $totalTasks = DB::table('s_user_jobrole_task')
                ->whereNull('deleted_at')
                ->when($subInstituteId, function ($query) use ($subInstituteId) {
                    return $query->where('sub_institute_id', $subInstituteId);
                })
                ->count();

            // Total mapped skills count from s_user_skill_jobrole
            $totalSkills = DB::table('s_user_skill_jobrole')
                ->whereNull('deleted_at')
                ->when($subInstituteId, function ($query) use ($subInstituteId) {
                    return $query->where('sub_institute_id', $subInstituteId);
                })
                ->count();

            // Roles with mapped tasks
            $rolesWithTasks = (clone $rolesQuery)
                ->join('s_user_jobrole_task as jt', function($join) {
                    $join->on('jr.jobrole', '=', 'jt.jobrole')
                         ->whereNull('jt.deleted_at');
                })
                ->when($subInstituteId, function ($query) use ($subInstituteId) {
                    return $query->where('jt.sub_institute_id', $subInstituteId);
                })
                ->distinct('jr.id')
                ->count();

            $taskCoveragePercentage = $totalRoles > 0 ? round(($rolesWithTasks / $totalRoles) * 100, 1) : 0;

            // Roles with mapped skills
            $rolesWithSkills = (clone $rolesQuery)
                ->join('s_user_skill_jobrole as sj', function($join) {
                    $join->on('jr.jobrole', '=', 'sj.jobrole')
                         ->whereNull('sj.deleted_at');
                })
                ->when($subInstituteId, function ($query) use ($subInstituteId) {
                    return $query->where('sj.sub_institute_id', $subInstituteId);
                })
                ->distinct('jr.id')
                ->count();

            $skillCoveragePercentage = $totalRoles > 0 ? round(($rolesWithSkills / $totalRoles) * 100, 1) : 0;

            return response()->json([
                'status' => 'success',
                'total_roles' => $totalRoles,
                'total_departments' => $departmentsCount,
                'total_mapped_tasks' => $totalTasks,
                'task_coverage_percentage' => $taskCoveragePercentage,
                'total_mapped_skills' => $totalSkills,
                'skill_coverage_percentage' => $skillCoveragePercentage,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch KPI data.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}

