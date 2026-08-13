<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SkillDevelopmentController extends Controller
{
    use ResolvesApiIdentity;

    /** Resolved once per request; every endpoint here needs the same identity. */
    private ?array $identityCache = null;

    /**
     * @return array{user:object, user_id:int, sub_institute_id:int}|\Illuminate\Http\JsonResponse
     */
    private function identity(Request $request)
    {
        if ($this->identityCache !== null) {
            return $this->identityCache;
        }

        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        return $this->identityCache = $identity;
    }

    /**
     * Validate the Sanctum token on API calls.
     *
     * Was: `if ($request->input('type') !== 'API') return null;` - a caller who
     * omitted `type` skipped authentication entirely. A token is required now,
     * always, and it is what decides the caller's identity.
     */
    private function guardApiToken(Request $request)
    {
        $identity = $this->identity($request);

        return is_array($identity) ? null : $identity;
    }

    /**
     * The caller's own id and organisation.
     *
     * Both used to be read straight from the request, so changing `user_id`
     * returned another employee's learning progress, streak and achievements,
     * and changing `sub_institute_id` reached into another organisation.
     */
    private function contextUserId(Request $request): ?int
    {
        $identity = $this->identity($request);

        return is_array($identity) ? $identity['user_id'] : null;
    }

    private function contextTenantId(Request $request): ?int
    {
        $identity = $this->identity($request);

        return is_array($identity) ? $identity['sub_institute_id'] : null;
    }

    /**
     * Get skill development progress data from database
     */
    public function getSkillProgress(Request $request)
    {
        
        try {
            
            
            if ($tokenError = $this->guardApiToken($request)) {
                return $tokenError;
            }

            $userId = $this->contextUserId($request);
            $subInstituteId = $this->contextTenantId($request);

            if (!$userId) {
                return response()->json([
                    'status' => false,
                    'message' => 'user_id is required'
                ], 422);
            }

            // Get user's skills grouped by category and sub_category.
            //
            // The enrolment join used to key on user_id alone, with nothing tying
            // an enrolment to the skill row it sat beside. That produced a
            // cartesian product: every skill in a category was credited with the
            // learner's entire course list, so a user with 26 enrolments reported
            // 234 courses against "Functional Skills".
            //
            // Courses reach competency through sub_std_map: subject_id addresses
            // the skill and subject_category says which table it addresses.
            // 'Task', 'jobrole', 'course' and 'sub' point at other entities
            // entirely and must be excluded, or a task id would be read as a
            // skill id.
            $userSkills = DB::table('s_skill_matrix as sm')
                ->join('s_users_skills as sus', 'sus.id', '=', 'sm.skill_id')
                ->leftJoin('sub_std_map as ssm', function($join) {
                    $join->on('ssm.subject_id', '=', 'sus.id')
                         ->whereNotIn(DB::raw('LOWER(TRIM(ssm.subject_category))'), ['task', 'jobrole', 'course', 'sub'])
                         ->whereNull('ssm.deleted_at');
                })
                ->leftJoin('lms_course_enroll as lce', function($join) use ($userId) {
                    $join->on('lce.course_id', '=', 'ssm.id')
                         ->where('lce.user_id', '=', DB::raw($userId))
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
                    'sus.sub_category as sub_category',
                    DB::raw('COUNT(DISTINCT sus.id) as total_skills_in_category'),
                    DB::raw('AVG(sm.skill_level) as avg_skill_level'),
                    DB::raw('COUNT(CASE WHEN lce.status = "completed" THEN 1 END) as courses_completed'),
                    DB::raw('COUNT(lce.id) as total_enrolled_courses'),
                    DB::raw('MAX(sm.created_at) as created_at'),
                    DB::raw('MAX(sm.updated_at) as updated_at'),
                    DB::raw('MAX(sm.deleted_at) as deleted_at')
                ])
                ->groupBy(['sus.category', 'sus.sub_category'])
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
                    'sub_category' => $skill->sub_category,
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
                    'overall' => $overallData,
                    'timestamps' => [
                        'created_at' => now()->toDateTimeString(),
                        'updated_at' => now()->toDateTimeString(),
                        'deleted_at' => null
                    ]
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
     * Get learning streak data for a user
     */
    public function getLearningStreak(Request $request)
    {
        try {
            if ($tokenError = $this->guardApiToken($request)) {
                return $tokenError;
            }

            $userId = $this->contextUserId($request);
            $subInstituteId = $this->contextTenantId($request);

            if (!$userId) {
                return response()->json([
                    'status' => false,
                    'message' => 'user_id is required'
                ], 422);
            }

            // Get all attendance days for the user where they punched in, ordered by day desc
            $attendanceDays = DB::table('hrms_attendances')
                ->where('user_id', $userId)
                ->whereNotNull('punchin_time')
                ->whereNull('deleted_at')
                ->when($subInstituteId, function($query) use ($subInstituteId) {
                    return $query->where('sub_institute_id', $subInstituteId);
                })
                ->orderBy('day', 'desc')
                ->pluck('day')
                ->toArray();

            // Calculate current streak
            $currentStreak = $this->calculateCurrentStreak($attendanceDays);

            // Calculate best streak
            $bestStreak = $this->calculateBestStreak($attendanceDays);

            // Fixed goal
            $goal = 30;

            // Calculate progress percentage
            $progressPercentage = $goal > 0 ? round(($currentStreak / $goal) * 100) : 0;

            // Days to go
            $daysToGo = max(0, $goal - $currentStreak);

            return response()->json([
                'status' => true,
                'message' => 'Learning streak data retrieved successfully',
                'data' => [
                    'current_streak' => $currentStreak,
                    'goal' => $goal,
                    'progress_percentage' => $progressPercentage,
                    'best_streak' => $bestStreak,
                    'days_to_go' => $daysToGo,
                    'timestamps' => [
                        'created_at' => now()->toDateTimeString(),
                        'updated_at' => now()->toDateTimeString(),
                        'deleted_at' => null
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve learning streak data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate the current streak of consecutive learning days
     */
    private function calculateCurrentStreak($attendanceDays)
    {
        if (empty($attendanceDays)) {
            return 0;
        }

        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        // Check if today or yesterday has attendance
        $hasToday = in_array($today, $attendanceDays);
        $hasYesterday = in_array($yesterday, $attendanceDays);

        if (!$hasToday && !$hasYesterday) {
            return 0;
        }

        $streak = 0;
        $currentDate = $hasToday ? $today : $yesterday;

        while (in_array($currentDate, $attendanceDays)) {
            $streak++;
            $currentDate = date('Y-m-d', strtotime($currentDate . ' -1 day'));
        }

        return $streak;
    }

    /**
     * Calculate the best (longest) streak of consecutive learning days
     */
    private function calculateBestStreak($attendanceDays)
    {
        if (empty($attendanceDays)) {
            return 0;
        }

        sort($attendanceDays); // Sort ascending

        $maxStreak = 0;
        $currentStreak = 1;

        for ($i = 1; $i < count($attendanceDays); $i++) {
            $prevDate = date('Y-m-d', strtotime($attendanceDays[$i-1] . ' +1 day'));
            if ($attendanceDays[$i] === $prevDate) {
                $currentStreak++;
            } else {
                $maxStreak = max($maxStreak, $currentStreak);
                $currentStreak = 1;
            }
        }

        $maxStreak = max($maxStreak, $currentStreak);

        return $maxStreak;
    }

    /**
     * Get weekly learning hours goal data
     */
    public function getWeeklyLearningGoal(Request $request)
    {
        try {
            if ($tokenError = $this->guardApiToken($request)) {
                return $tokenError;
            }

            $userId = $this->contextUserId($request);
            $subInstituteId = $this->contextTenantId($request);

            if (!$userId) {
                return response()->json([
                    'status' => false,
                    'message' => 'user_id is required'
                ], 422);
            }

            // Get current week's start and end dates (Monday to Sunday)
            $startOfWeek = now()->startOfWeek(); // Monday
            $endOfWeek = now()->endOfWeek(); // Sunday

            // Calculate total learning hours for the current week
            // Assuming learning hours are based on attendance hours (punch in/out time)
            $weeklyAttendance = DB::table('hrms_attendances')
                ->where('user_id', $userId)
                ->whereNotNull('punchin_time')
                ->whereNotNull('punchout_time')
                ->whereNull('deleted_at')
                ->whereBetween('day', [$startOfWeek->format('Y-m-d'), $endOfWeek->format('Y-m-d')])
                ->when($subInstituteId, function($query) use ($subInstituteId) {
                    return $query->where('sub_institute_id', $subInstituteId);
                })
                ->get();

            $totalHours = 0;
            foreach ($weeklyAttendance as $attendance) {
                $punchIn = \Carbon\Carbon::parse($attendance->punchin_time);
                $punchOut = \Carbon\Carbon::parse($attendance->punchout_time);
                $hours = $punchOut->diffInHours($punchIn);
                $totalHours += $hours;
            }

            // Weekly goal (can be configurable, for now fixed at 12 hours)
            $weeklyGoal = 12;
            $currentHours = min($totalHours, $weeklyGoal); // Cap at goal
            $remainingHours = max(0, $weeklyGoal - $currentHours);
            $progressPercentage = $weeklyGoal > 0 ? round(($currentHours / $weeklyGoal) * 100) : 0;

            return response()->json([
                'status' => true,
                'message' => 'Weekly learning goal data retrieved successfully',
                'data' => [
                    'current_hours' => $currentHours,
                    'goal_hours' => $weeklyGoal,
                    'remaining_hours' => $remainingHours,
                    'progress_percentage' => $progressPercentage,
                    'week_start' => $startOfWeek->format('Y-m-d'),
                    'week_end' => $endOfWeek->format('Y-m-d'),
                    'timestamps' => [
                        'created_at' => now()->toDateTimeString(),
                        'updated_at' => now()->toDateTimeString(),
                        'deleted_at' => null
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve weekly learning goal data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user achievements and progress data
     */
    public function getUserAchievements(Request $request)
    {
        try {
            if ($tokenError = $this->guardApiToken($request)) {
                return $tokenError;
            }

            $userId = $this->contextUserId($request);
            $subInstituteId = $this->contextTenantId($request);

            if (!$userId) {
                return response()->json([
                    'status' => false,
                    'message' => 'user_id is required'
                ], 422);
            }
            // Fetch all achievements from database
            $achievementDefinitions = DB::table('lms_achievements')->get();

            $achievements = [];

            foreach ($achievementDefinitions as $achievement) {
                $earned = false;
                $earnedDate = null;
                $progress = 'In progress';

                switch ($achievement->criteria_type) {
                    case 'courses_completed_month':
                        $currentMonth = now()->format('Y-m');
                        $coursesCompletedThisMonth = DB::table('lms_course_enroll')
                            ->where('user_id', $userId)
                            ->where('status', 'completed')
                            ->whereNull('deleted_at')
                            ->whereRaw("DATE_FORMAT(end_date, '%Y-%m') = ?", [$currentMonth])
                            ->when($subInstituteId, function($query) use ($subInstituteId) {
                                return $query->where('sub_institute_id', $subInstituteId);
                            })
                            ->count();

                        $earned = $coursesCompletedThisMonth >= $achievement->criteria_value;
                        $progress = min($coursesCompletedThisMonth, $achievement->criteria_value) . '/' . $achievement->criteria_value . ' courses';
                        break;

                    case 'skill_level_advanced':
                        $advancedSkills = DB::table('s_skill_matrix')
                            ->where('user_id', $userId)
                            ->where('skill_level', '>=', 4) // Assuming 4+ is advanced
                            ->whereNull('deleted_at')
                            ->count();

                        $earned = $advancedSkills >= $achievement->criteria_value;
                        $progress = $earned ? 'Achieved' : 'In progress';
                        break;

                    case 'streak':
                        $currentStreak = $this->calculateCurrentStreak(DB::table('hrms_attendances')
                            ->where('user_id', $userId)
                            ->whereNotNull('punchin_time')
                            ->whereNull('deleted_at')
                            ->orderBy('day', 'desc')
                            ->pluck('day')
                            ->toArray());

                        $earned = $currentStreak >= $achievement->criteria_value;
                        $progress = $currentStreak . '/' . $achievement->criteria_value . ' days';
                        break;

                    // Add more criteria types as needed
                }

                if ($earned) {
                    $earnedDate = now()->format('d/m/Y');
                }

                $achievements[] = [
                    'title' => $achievement->title,
                    'description' => $achievement->description,
                    'earned' => $earned,
                    'earned_date' => $earnedDate,
                    'progress' => $progress,
                    'timestamps' => [
                        'created_at' => $achievement->created_at,
                        'updated_at' => $achievement->updated_at,
                        'deleted_at' => $achievement->deleted_at ?? null
                    ]
                ];
            }

            // Overall progress (example: average of skill levels)
            $totalSkills = DB::table('s_skill_matrix')
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->count();

            $avgSkillLevel = DB::table('s_skill_matrix')
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->avg('skill_level') ?? 0;

            $overallProgress = $totalSkills > 0 ? round(($avgSkillLevel / 5) * 100) : 0; // Assuming 5 levels

            return response()->json([
                'status' => true,
                'message' => 'User achievements and progress retrieved successfully',
                'data' => [
                    'achievements' => $achievements,
                    'overall_progress' => $overallProgress,
                    'timestamps' => [
                        'created_at' => now()->toDateTimeString(),
                        'updated_at' => now()->toDateTimeString(),
                        'deleted_at' => null
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve user achievements',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get peer comparison data for a user
     */
    public function getPeerComparison(Request $request)
    {
        try {
            if ($tokenError = $this->guardApiToken($request)) {
                return $tokenError;
            }

            $userId = $this->contextUserId($request);
            $subInstituteId = $this->contextTenantId($request);

            if (!$userId) {
                return response()->json([
                    'status' => false,
                    'message' => 'user_id is required'
                ], 422);
            }

            // Get user's overall progress
            $userProgressData = $this->calculateUserProgress($userId, $subInstituteId);
            $userProgress = $userProgressData['progress'];

            // Get all users in the same sub_institute (peers)
            $peerProgresses = DB::table('tbluser')
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->map(function($peerUserId) use ($subInstituteId) {
                    $peerData = $this->calculateUserProgress($peerUserId, $subInstituteId);
                    return [
                        'user_id' => $peerUserId,
                        'progress' => $peerData['progress']
                    ];
                })
                ->filter(function($peer) {
                    return $peer['progress'] > 0; // Only include users with some progress
                })
                ->sortByDesc('progress')
                ->values();

            if ($peerProgresses->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No peer data available'
                ], 404);
            }

            // Find user's rank
            $rank = null;
            foreach ($peerProgresses as $index => $peer) {
                if ($peer['user_id'] == $userId) {
                    $rank = $index + 1;
                    break;
                }
            }

            if ($rank === null) {
                // User not found in peers, add them
                $peerProgresses->push(['user_id' => $userId, 'progress' => $userProgressData['progress']]);
                $peerProgresses = $peerProgresses->sortByDesc('progress')->values();
                $rank = $peerProgresses->search(function($peer) use ($userId) {
                    return $peer['user_id'] == $userId;
                }) + 1;
            }

            $totalPeers = $peerProgresses->count();
            $peerAverage = $peerProgresses->avg('progress');

            // Calculate percentile (what percentage of peers the user is better than)
            $betterThan = $peerProgresses->filter(function($peer) use ($userProgress) {
                return $peer['progress'] < $userProgress;
            })->count();

            $percentile = $totalPeers > 0 ? round(($betterThan / $totalPeers) * 100) : 0;

            // Calculate what percentage are above the user
            $percentileAbove = $totalPeers > 0 ? round((($rank - 1) / $totalPeers) * 100) : 0;

            return response()->json([
                'status' => true,
                'message' => 'Peer comparison data retrieved successfully',
                'data' => [
                    'rank' => $rank,
                    'total_peers' => $totalPeers,
                    'your_progress' => round($userProgress),
                    'peer_average' => round($peerAverage),
                    'percentile' => $percentile,
                    'message' => "You're in the top " . $percentileAbove . "% of learners!",
                    'timestamps' => $userProgressData['timestamps']
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve peer comparison data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate overall progress for a user
     */
    private function calculateUserProgress($userId, $subInstituteId = null)
    {
        $userSkills = DB::table('s_skill_matrix as sm')
            ->join('s_users_skills as sus', 'sus.id', '=', 'sm.skill_id')
            ->where('sm.user_id', $userId)
            ->whereNull('sm.deleted_at')
            ->whereNull('sus.deleted_at')
            ->whereNotNull('sus.category')
            ->where('sus.category', '!=', '')
            ->when($subInstituteId, function($query) use ($subInstituteId) {
                $query->where('sus.sub_institute_id', $subInstituteId);
            })
            ->select([
                DB::raw('AVG(sm.skill_level) as avg_skill_level'),
                DB::raw('COUNT(DISTINCT sus.id) as total_skills'),
                DB::raw('MAX(sm.created_at) as created_at'),
                DB::raw('MAX(sm.updated_at) as updated_at'),
                DB::raw('MAX(sm.deleted_at) as deleted_at')
            ])
            ->first();

        if (!$userSkills || $userSkills->total_skills == 0) {
            return [
                'progress' => 0,
                'timestamps' => [
                    'created_at' => null,
                    'updated_at' => null,
                    'deleted_at' => null
                ]
            ];
        }

        // Progress based on average skill level (assuming 5 levels: 1-5)
        return [
            'progress' => ($userSkills->avg_skill_level / 5) * 100,
            'timestamps' => [
                'created_at' => $userSkills->created_at,
                'updated_at' => $userSkills->updated_at,
                'deleted_at' => $userSkills->deleted_at
            ]
        ];
    }

    /**
     * Get learning calendar events for a user
     */
    public function getLearningCalendar(Request $request)
    {
        try {
            if ($tokenError = $this->guardApiToken($request)) {
                return $tokenError;
            }

            $userId = $this->contextUserId($request);
            $subInstituteId = $this->contextTenantId($request);
            $month = $request->input('month', now()->format('m')); // Default to current month
            $year = $request->input('year', now()->format('Y')); // Default to current year

            if (!$userId) {
                return response()->json([
                    'status' => false,
                    'message' => 'user_id is required'
                ], 422);
            }

            // Get calendar events for the specified month and year
            $events = DB::table('calendar_events')
                ->whereYear('school_date', $year)
                ->whereMonth('school_date', $month)
                ->whereNull('deleted_at')
                ->when($subInstituteId, function($query) use ($subInstituteId) {
                    return $query->where('sub_institute_id', $subInstituteId);
                })
                ->orderBy('school_date', 'asc')
                ->get();

            $formattedEvents = [];
            foreach ($events as $event) {
                $formattedEvents[] = [
                    'title' => $event->title,
                    'description' => $event->description,
                    'current_datetime' => now()->format('M d \a\t g:i A'),
                    'school_date' => $event->school_date,
                    'priority' => $event->event_type ?? 'medium', // Assuming event_type maps to priority
                    'event_type' => $event->event_type,
                    'standard' => $event->standard,
                    'timestamps' => [
                        'created_at' => $event->created_at,
                        'updated_at' => $event->updated_at,
                        'deleted_at' => $event->deleted_at
                    ]
                ];
            }

            return response()->json([
                'status' => true,
                'message' => 'Learning calendar events retrieved successfully',
                'data' => [
                    'month' => $month,
                    'year' => $year,
                    'events' => $formattedEvents,
                    'timestamps' => [
                        'created_at' => now()->toDateTimeString(),
                        'updated_at' => now()->toDateTimeString(),
                        'deleted_at' => null
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve learning calendar events',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get recent learning activity.
     *
     * There is no dedicated LMS activity-log table, so the feed is derived from
     * the records that already change as the learner works: their enrolments
     * (lms_course_enroll) and the learning calendar (calendar_events).
     */
    public function getRecentActivity(Request $request)
    {
        try {
            if ($tokenError = $this->guardApiToken($request)) {
                return $tokenError;
            }

            $userId = $this->contextUserId($request);
            $subInstituteId = $this->contextTenantId($request);
            $limit = (int) $request->input('limit', 8);

            if (!$userId) {
                return response()->json([
                    'status' => false,
                    'message' => 'user_id is required'
                ], 422);
            }

            $now = now();
            $activities = [];

            // 1. Enrolment records - one entry for the enrolment, plus one for the
            //    completion or the approaching/overdue deadline.
            $enrollments = DB::table('lms_course_enroll as e')
                ->join('sub_std_map as s', 'e.course_id', '=', 's.id')
                ->leftJoin('subject as subj', 's.subject_id', '=', 'subj.id')
                ->where('e.user_id', $userId)
                ->whereNull('e.deleted_at')
                ->when($subInstituteId, function ($query) use ($subInstituteId) {
                    return $query->where('e.sub_institute_id', $subInstituteId);
                })
                ->select([
                    'e.id',
                    'e.status',
                    'e.end_date',
                    'e.created_at',
                    'e.updated_at',
                    DB::raw('COALESCE(subj.subject_name, s.display_name) as course_title'),
                ])
                ->orderByDesc('e.created_at')
                ->limit(50)
                ->get();

            foreach ($enrollments as $enrollment) {
                $title = $enrollment->course_title ?: 'a course';

                if ($enrollment->status === 'completed') {
                    $completedAt = $enrollment->updated_at ?? $enrollment->created_at;
                    $activities[] = [
                        'id' => 'enroll-completed-' . $enrollment->id,
                        'text' => 'You completed "' . $title . '"',
                        'type' => 'course_completed',
                        'tone' => 'success',
                        'timestamp' => $completedAt,
                        'sort' => $completedAt ? strtotime($completedAt) : 0,
                    ];
                } else {
                    $activities[] = [
                        'id' => 'enroll-started-' . $enrollment->id,
                        'text' => 'You enrolled in "' . $title . '"',
                        'type' => 'course_enrolled',
                        'tone' => 'primary',
                        'timestamp' => $enrollment->created_at,
                        'sort' => $enrollment->created_at ? strtotime($enrollment->created_at) : 0,
                    ];

                    if ($enrollment->end_date) {
                        $dueDate = \Carbon\Carbon::parse($enrollment->end_date);
                        $overdue = $dueDate->isPast();

                        // Only surface deadlines that are actionable: already
                        // overdue, or falling within the next two weeks.
                        if ($overdue || $dueDate->lte($now->copy()->addDays(14))) {
                            $activities[] = [
                                'id' => 'enroll-due-' . $enrollment->id,
                                'text' => $overdue
                                    ? '"' . $title . '" is overdue'
                                    : '"' . $title . '" is due ' . $dueDate->diffForHumans(),
                                'type' => 'deadline_due',
                                'tone' => 'warning',
                                'timestamp' => $dueDate->toDateTimeString(),
                                'sort' => $dueDate->getTimestamp(),
                            ];
                        }
                    }
                }
            }

            // 2. Learning calendar - sessions and events around today.
            $events = DB::table('calendar_events')
                ->whereNull('deleted_at')
                ->whereBetween('school_date', [
                    $now->copy()->subDays(30)->format('Y-m-d'),
                    $now->copy()->addDays(30)->format('Y-m-d'),
                ])
                ->when($subInstituteId, function ($query) use ($subInstituteId) {
                    return $query->where('sub_institute_id', $subInstituteId);
                })
                ->orderByDesc('school_date')
                ->limit(20)
                ->get();

            foreach ($events as $event) {
                $eventDate = \Carbon\Carbon::parse($event->school_date);
                $activities[] = [
                    'id' => 'event-' . $event->id,
                    'text' => $eventDate->isPast()
                        ? '"' . $event->title . '" took place'
                        : '"' . $event->title . '" is scheduled ' . $eventDate->diffForHumans(),
                    'type' => 'session_upcoming',
                    'tone' => 'neutral',
                    'timestamp' => $eventDate->toDateTimeString(),
                    'sort' => $eventDate->getTimestamp(),
                ];
            }

            // Most recent (or most imminent) first.
            usort($activities, function ($a, $b) {
                return $b['sort'] <=> $a['sort'];
            });

            // A learner with a long backlog can have dozens of overdue courses,
            // which would otherwise crowd every state change out of the feed.
            // Keep only the most pressing few; the deadline widgets list the rest.
            $deadlineCount = 0;
            $activities = array_values(array_filter($activities, function ($activity) use (&$deadlineCount) {
                if ($activity['type'] !== 'deadline_due') {
                    return true;
                }

                return ++$deadlineCount <= 3;
            }));

            $activities = array_map(function ($activity) {
                return [
                    'id' => $activity['id'],
                    'text' => $activity['text'],
                    'time' => $activity['timestamp']
                        ? \Carbon\Carbon::parse($activity['timestamp'])->diffForHumans()
                        : '',
                    'type' => $activity['type'],
                    'tone' => $activity['tone'],
                    'timestamp' => $activity['timestamp'],
                ];
            }, array_slice($activities, 0, max($limit, 1)));

            return response()->json([
                'status' => true,
                'data' => $activities
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve recent activity',
                'error' => $e->getMessage()
            ], 500);
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