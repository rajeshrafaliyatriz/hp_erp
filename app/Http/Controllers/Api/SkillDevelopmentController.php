<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SkillDevelopmentController extends Controller
{
    /**
     * Get skill development progress data from database
     */
    public function getSkillProgress(Request $request)
    {
        try {
            $userId = $request->user_id ?? $request->header('user_id');
            $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id');

            if (!$userId) {
                return response()->json([
                    'status' => false,
                    'message' => 'user_id is required'
                ], 422);
            }

            // Get user's skills grouped by category
            $userSkills = DB::table('s_skill_matrix as sm')
                ->join('s_users_skills as sus', 'sus.id', '=', 'sm.skill_id')
                ->leftJoin('lms_course_enroll as lce', function($join) use ($userId) {
                    $join->on('lce.user_id', '=', DB::raw($userId))
                         ->whereNull('lce.deleted_at');
                })
                ->where('sm.user_id', $userId)
                ->whereNull('sm.deleted_at')
                ->whereNull('sus.deleted_at')
                ->whereNotNull('sus.category')
                ->where('sus.category', '!=', '')
                ->where(function($query) use ($subInstituteId) {
                    if ($subInstituteId) {
                        $query->where('sus.sub_institute_id', $subInstituteId);
                    }
                })
                ->select([
                    'sus.category as skill_category',
                    DB::raw('COUNT(DISTINCT sus.id) as total_skills_in_category'),
                    DB::raw('AVG(sm.skill_level) as avg_skill_level'),
                    DB::raw('COUNT(CASE WHEN lce.status = "completed" THEN 1 END) as courses_completed'),
                    DB::raw('COUNT(lce.id) as total_enrolled_courses')
                ])
                ->groupBy('sus.category')
                ->get();

            $skillProgress = [];
            $totalProgress = 0;
            $skillsInProgress = 0;

            foreach ($userSkills as $skill) {
                // Calculate progress based on average skill level (assuming 5 levels: 1-5)
                $progressPercentage = ($skill->avg_skill_level / 5) * 100;

                // Determine proficiency level based on average skill level
                $proficiencyLevel = $this->getProficiencyLevel($skill->avg_skill_level);

                // For courses, we'll use enrolled vs completed for this category
                $coursesCompleted = $skill->courses_completed ?? 0;
                $totalCourses = max($skill->total_enrolled_courses ?? 1, 1); // Avoid division by zero

                // If no courses enrolled, set total courses to a default based on skills in category
                if ($totalCourses == 0) {
                    $totalCourses = $skill->total_skills_in_category; // Use number of skills as default courses
                }

                // Map category names to match user's expected format
                $skillName = $this->mapCategoryName($skill->skill_category);

                $skillProgress[] = [
                    'skill_name' => $skillName,
                    'progress_percentage' => round($progressPercentage),
                    'proficiency_level' => $proficiencyLevel,
                    'courses_completed' => $coursesCompleted,
                    'total_courses' => $totalCourses,
                    'status' => $progressPercentage < 100 ? 'in-progress' : 'completed'
                ];

                $totalProgress += $progressPercentage;
                if ($progressPercentage < 100) {
                    $skillsInProgress++;
                }
            }

            // If no skills found in database, return empty result
            if (empty($skillProgress)) {
                return response()->json([
                    'status' => true,
                    'message' => 'No skill progress data found for this user',
                    'data' => [
                        'skill_progress' => [],
                        'overall' => [
                            'overall_progress_percentage' => 0,
                            'total_skills' => 0,
                            'skills_in_progress' => 0,
                            'average_progress' => 0
                        ]
                    ]
                ], 200);
            }

            $totalSkills = count($skillProgress);
            $averageProgress = $totalProgress / $totalSkills;

            $overallData = [
                'overall_progress_percentage' => round($averageProgress),
                'total_skills' => $totalSkills,
                'skills_in_progress' => $skillsInProgress,
                'average_progress' => round($averageProgress)
            ];

            return response()->json([
                'status' => true,
                'message' => 'Skill development progress retrieved successfully',
                'data' => [
                    'skill_progress' => $skillProgress,
                    'overall' => $overallData
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve skill progress',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Helper method to determine proficiency level based on skill level
     */
    private function getProficiencyLevel($skillLevel)
    {
        if ($skillLevel >= 4) {
            return 'Advanced';
        } elseif ($skillLevel >= 3) {
            return 'Intermediate';
        } else {
            return 'Beginner';
        }
    }

    /**
     * Helper method to map category names to user-friendly names
     */
    private function mapCategoryName($category)
    {
        $categoryMap = [
            'Frontend' => 'React Development',
            'React' => 'React Development',
            'JavaScript' => 'React Development',
            'Analytics' => 'Data Analysis',
            'Data' => 'Data Analysis',
            'Analysis' => 'Data Analysis',
            'Leadership' => 'Project Management',
            'Management' => 'Project Management',
            'Project' => 'Project Management',
            'AI/ML' => 'Machine Learning',
            'AI' => 'Machine Learning',
            'Machine Learning' => 'Machine Learning',
            'ML' => 'Machine Learning',
        ];

        return $categoryMap[$category] ?? $category;
    }
}