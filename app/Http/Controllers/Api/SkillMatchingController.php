<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\school_setup\sub_std_mapModel;
use App\Models\front_desk\taskModel;
use App\Models\lms\contentModel;
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

            // For each task, find its courses
            foreach ($tasks as $task) {
                $skillIds = [];
                if ($task->skill_id) {
                    $skillIds = explode(',', $task->skill_id);
                    $skillIds = array_filter($skillIds);
                }

                $foundCourses = collect();
                $missingSkills = [];

                if (!empty($skillIds)) {
                    foreach ($skillIds as $skillId) {
                        $courses = contentModel::where('subject_id', $skillId)
                            ->where('show_hide', 1)
                            ->select('id', 'subject_id', 'title', 'description', 'content_category', 'syear', 'sub_institute_id')
                            ->get();

                        if ($courses->isNotEmpty()) {
                            $foundCourses = $foundCourses->merge($courses);
                        } else {
                            $skill = skillJobroleMap::with('userSkills')->find($skillId);
                            $missingSkills[] = [
                                'skill_id' => $skillId,
                                'skill_name' => $skill && $skill->userSkills ? $skill->userSkills->title : 'Unknown'
                            ];
                        }
                    }
                }

                $task->courses = $foundCourses;
                $task->missing_skills = $missingSkills;
            }

            return response()->json([
                'status' => 1,
                'message' => 'User rejected tasks retrieved successfully',
                'data' => $tasks
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'message' => 'Error retrieving tasks and courses',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getCoursesForUserRejectedTasksSkills(Request $request)
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

            // Get rejected tasks
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

            // Collect all unique skill_ids from tasks
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

            // Find courses where subject_id in skill_ids
            $courses = contentModel::whereIn('subject_id', $allSkillIds)
                ->where('show_hide', 1)
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