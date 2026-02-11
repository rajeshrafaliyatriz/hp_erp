<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\school_setup\sub_std_mapModel;
use App\Models\front_desk\taskModel;
use App\Models\lms\contentModel;
use App\Models\libraries\userSkills;
use App\Models\libraries\skillJobroleMap;

class SkillMatchingController extends Controller
{
    public function getUserRejectedTasks(Request $request)
    {
        try {
            $userId = $request->input('user_id');
            $subInstituteId = $request->input('sub_institute_id');

            if (!$userId || !$subInstituteId) {
                return response()->json([
                    'status' => 0,
                    'message' => 'user_id and sub_institute_id are required'
                ], 400);
            }

            $tasks = taskModel::where('task_allocated_to', $userId)
                ->where('sub_institute_id', $subInstituteId)
                ->where('approve_status', 'Rejected')
                ->whereNull('deleted_at')
                ->get()
                ->makeHidden(['created_by', 'updated_by', 'deleted_by', 'created_at', 'updated_at', 'deleted_at']);

            if ($tasks->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No rejected tasks found for this user'
                ], 404);
            }

            // Get user's skills (titles) from s_users_skills via s_skill_matrix
            $userSkillsList = DB::table('s_skill_matrix as sm')
                ->join('s_users_skills as sus', 'sm.skill_id', '=', 'sus.id')
                ->where('sm.user_id', $userId)
                ->whereNull('sm.deleted_at')
                ->whereNull('sus.deleted_at')
                ->pluck('sus.title')
                ->toArray();

            $userSkillsLower = array_map('strtolower', array_map('trim', $userSkillsList));

            // For each task → analyze skills & find matching courses
            foreach ($tasks as $task) {
                $skillIds = [];
                if ($task->skill_id) {
                    $skillIds = explode(',', $task->skill_id);
                    $skillIds = array_filter(array_map('trim', $skillIds));
                }

                $foundCourses = collect();
                $matchedSkills = [];
                $unmatchedSkills = [];
                $skillDetails = [];

                if (!empty($skillIds)) {
                    foreach ($skillIds as $skillId) {
                        $skillMatch = null;
                        $skillName = $skillId;
                        $skillDbId = null;

                        // Try to resolve skill name / ID
                        if (is_numeric($skillId)) {
                            $skillMatch = userSkills::find($skillId);
                        } else {
                            $skillMatch = userSkills::where(DB::raw('LOWER(TRIM(title))'), strtolower(trim($skillId)))
                                ->first();
                        }

                        if ($skillMatch) {
                            $skillName = $skillMatch->title;
                            $skillDbId = $skillMatch->id;
                        }

                        $normalizedSkill = strtolower(trim($skillName));
                        $userHasSkill = in_array($normalizedSkill, $userSkillsLower);

                        $skillDetails[] = [
                            'skill_id'         => $skillDbId,
                            'skill_name'       => $skillName,
                            'is_user_skill'    => $userHasSkill,
                            'match_percentage' => $userHasSkill ? 100 : 0,
                        ];

                        if ($userHasSkill) {
                            $matchedSkills[] = $skillName;
                        } else {
                            $unmatchedSkills[] = $skillName;
                        }

                        // ───────────────────────────────────────────────
                        // Find courses — prioritize display_name
                        // ───────────────────────────────────────────────
                        $courses = collect();

                        $searchStrategies = [
                            // 0: Exact match on display_name (best)
                            [
                                'type' => 'exact_display',
                                'closure' => fn($q) => $q->where(DB::raw('LOWER(TRIM(ssm.display_name))'), $normalizedSkill)
                            ],
                            // 1: Contains display_name
                            [
                                'type' => 'like_display',
                                'closure' => fn($q) => $q->where(DB::raw('LOWER(ssm.display_name)'), 'like', "%{$normalizedSkill}%")
                            ],
                            // 2: Exact subject_name
                            // [
                            //     'type' => 'exact_subject',
                            //     'closure' => fn($q) => $q->where(DB::raw('LOWER(TRIM(s.subject_name))'), $normalizedSkill)
                            // ],
                            // 3: Contains subject_name
                            // [
                            //     'type' => 'like_subject',
                            //     'closure' => fn($q) => $q->where(DB::raw('LOWER(s.subject_name)'), 'like', "%{$normalizedSkill}%")
                            // ],
                        ];

                        foreach ($searchStrategies as $strategy) {
                            if ($courses->isNotEmpty()) break;

                            $query = DB::table('content_master as cm')
                                ->join('sub_std_map as ssm', function ($join) {
                                    $join->on('cm.standard_id', '=', 'ssm.standard_id')
                                         ->on('cm.subject_id', '=', 'ssm.id');
                                })
                                ->leftJoin('subject as s', 'ssm.subject_id', '=', 's.id')
                                ->where('cm.sub_institute_id', $subInstituteId)
                                ->where('cm.show_hide', 1)
                                ->whereNull('cm.deleted_at')
                                ->select([
                                    'cm.id',
                                    'ssm.id as course_id',
                                    'ssm.display_name as title',
                                    'cm.description',
                                    'cm.content_category',
                                    'cm.syear',
                                    'cm.sub_institute_id',
                                    'cm.show_hide',
                                    'ssm.display_name',
                                ]);

                            $query->where(function ($q) use ($strategy) {
                                $strategy['closure']($q);
                            });

                            $result = $query->get();

                            if ($result->isNotEmpty()) {
                                $courses = $result;
                                $skillDetails[count($skillDetails) - 1]['course_strategy'] = $strategy['type'];
                                $skillDetails[count($skillDetails) - 1]['courses_found'] = $courses->count();
                            }
                        }

                        // Very last resort: if numeric skill ID and no match yet → try as subject_id
                        if ($courses->isEmpty() && is_numeric($skillId) && $skillDbId) {
                            $courses = DB::table('content_master as cm')
                                ->join('sub_std_map as ssm', function ($join) {
                                    $join->on('cm.standard_id', '=', 'ssm.standard_id')
                                         ->on('cm.subject_id', '=', 'ssm.id');
                                })
                                ->where('ssm.subject_id', $skillDbId)
                                ->where('cm.sub_institute_id', $subInstituteId)
                                ->where('cm.show_hide', 1)
                                ->whereNull('cm.deleted_at')
                                ->select([
                                    'cm.id',
                                    'cm.subject_id',
                                    'ssm.display_name as title',
                                    'cm.description',
                                    'cm.content_category',
                                    'cm.syear',
                                    'cm.sub_institute_id',
                                    'cm.show_hide',
                                    'ssm.display_name',
                                    //'s.subject_name as subject_table_name',
                                ])
                                ->get();

                            if ($courses->isNotEmpty()) {
                                $skillDetails[count($skillDetails) - 1]['course_strategy'] = 'direct_subject_id';
                                $skillDetails[count($skillDetails) - 1]['courses_found'] = $courses->count();
                            }
                        }

                        $foundCourses = $foundCourses->merge($courses);
                    }
                }

                // Overall match percentage
                $totalSkills = count($skillDetails);
                $matchedCount = count($matchedSkills);
                $matchPercentage = $totalSkills > 0 ? round(($matchedCount / $totalSkills) * 100, 2) : 0;

                $task->skill_details = $skillDetails;
                $task->matched_skills = $matchedSkills;
                $task->unmatched_skills = $unmatchedSkills;
                $task->skill_match_percentage = $matchPercentage;
                $task->courses = $foundCourses->unique('id'); // avoid duplicates if same course matches multiple skills
                $task->total_courses_found = $task->courses->count();
            }

            return response()->json([
                'status' => 1,
                'message' => 'User rejected tasks retrieved successfully',
                'data' => $tasks
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 0,
                'message' => 'Error retrieving tasks and courses',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function getCoursesForUserRejectedTasksSkills(Request $request)
    {
        // This method remains unchanged — or can be deprecated if the first method is sufficient
        // Keeping it as-is for backward compatibility
        try {
            $userId = $request->input('user_id');
            $subInstituteId = $request->input('sub_institute_id');

            if (!$userId || !$subInstituteId) {
                return response()->json([
                    'status' => 0,
                    'message' => 'user_id and sub_institute_id are required'
                ], 400);
            }

            $tasks = taskModel::where('task_allocated_to', $userId)
                ->where('sub_institute_id', $subInstituteId)
                ->where('approve_status', 'Rejected')
                ->whereNull('deleted_at')
                ->get();

            if ($tasks->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No rejected tasks found for this user'
                ], 404);
            }

            $allSkillIds = [];
            foreach ($tasks as $task) {
                if ($task->skill_id) {
                    $skillIds = explode(',', $task->skill_id);
                    $allSkillIds = array_merge($allSkillIds, $skillIds);
                }
            }
            $allSkillIds = array_unique(array_filter($allSkillIds));

            if (empty($allSkillIds)) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No skills found in rejected tasks'
                ], 400);
            }

            $courses = contentModel::whereIn('subject_id', $allSkillIds)
                ->get()
                ->makeHidden(['created_by', 'updated_by', 'deleted_by', 'created_at', 'updated_at', 'deleted_at']);
            if ($courses->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No course found for these skills'
                ], 404);
            }

            return response()->json([
                'status' => 1,
                'message' => 'Courses found for the skills in rejected tasks',
                'data' => $courses
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'message' => 'Error retrieving courses',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}