<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\IndustryController;
use App\Http\Controllers\Api\jobrolecontroller;
use App\Http\Controllers\Api\skillcontroller;
use App\Http\Controllers\Api\SkillDevelopmentController;
use App\Http\Controllers\libraries\jobroletexonomycontroller;
use App\Http\Controllers\libraries\jobroletaskcontroller;
use App\Http\Controllers\libraries\jobroleskillcontroller;
use App\Http\Controllers\HRMS\HrmsController;
use App\Http\Controllers\Api\CompetencyDashboardController;
use App\Http\Controllers\Api\CompetencyDashboard\CompetencyDashboardController as SubCompetencyDashboardController;
use App\Http\Controllers\Api\DBController;
use App\Http\Controllers\talent\talent_jobpostingcontroller;
use App\Http\Controllers\talent\talent_jobapplicationcontroller;
use App\Http\Controllers\talent\talent_interviewschedulescontroller;
use App\Http\Controllers\talent\talent_screening_results_controller;
use App\Http\Controllers\talent\TalentOfferController;
use App\Http\Controllers\talent\TalentAcquisition\TalentAcquisitionController;
use App\Http\Controllers\talent\TalentAcquisition\CandidateDropoffController;
use App\Http\Controllers\AJAXController;
use App\Http\Controllers\Api\HRITDashboard\AttendanceApiController;
use App\Http\Controllers\Api\HRITDashboard\JobroleApiController;
use App\Http\Controllers\Api\HRITDashboard\LeaveDistribution;
use App\Http\Controllers\HRMS\HrmsLeaveController;
use App\Http\Controllers\HRMS\DepartmentManagementController;
use App\Http\Controllers\build_with_AI\buildwithAIController;
use App\Http\Controllers\Api\GammaApiController;
use App\Http\Controllers\Api\Gemini\AnalyzeJDController;
use App\Http\Controllers\Api\Gemini\SaveJDController;
use App\Http\Controllers\Api\Gemini\GenerateQuestionsController;
use App\Http\Controllers\Api\SkillMatchingController;
use App\Http\Controllers\Api\SuggestedCourseController;
use App\Http\Controllers\user\UserSkillController;
use App\Http\Controllers\lms_course_enroll\LmsCourseEnrollController;
use App\Http\Controllers\ai_generated_assessment\generateQuestionController;
use App\Http\Controllers\ai_generated_assessment\generateAssessmentController;
use App\Http\Controllers\libraries\skillLibraryController;
use App\Http\Controllers\talent\InterviewController;
use App\Http\Controllers\talent\interview_panel\talent_interviewpanelController;
use App\Http\Controllers\talent\feedback\feedbackController;
use App\Http\Controllers\talent\candidate\candidateController;
use App\Http\Controllers\Reports\KpiController;
use App\Http\Controllers\Reports\HiringAnalyticsController;
use App\Http\Controllers\Reports\DepartmentDistributionController;
use App\Http\Controllers\Reports\DepartmentSizeController;
use App\Http\Controllers\Reports\EmployeeLifecycleController;
use App\Http\Controllers\Reports\OrganizationGrowthController;
use App\Http\Controllers\Reports\EmployeeSkillCoverageMatrix\EmployeeSkillCoverageMatrixController;
use App\Http\Controllers\Reports\EmployeeDirectoryAnalytics\EmployeeDirectoryAnalyticsController;
use App\Http\Controllers\front_desk\BulkTaskController;
use App\Http\Controllers\JobRoleGraphController;
use App\Http\Controllers\OrganizationGraphController;
use App\Http\Controllers\DepartmentGraphController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UserJourneyLogController;
use App\Http\Controllers\Api\signup_api\SchoolSetupController;
use App\Http\Controllers\Api\signup_api\UserSignupController;
use App\Http\Controllers\Api\SkillHeatmapController;
use App\Http\Controllers\signupOtpController;
use App\Http\Controllers\Api\UserImportController;
use App\Http\Controllers\user\tbluserController;
use App\Http\Controllers\Api\ExcelAutomationAgentController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\HRMS\DepartmentJobRoleExportController;
use App\Http\Controllers\HRMS\DepartmentSkillController;
use App\Http\Controllers\HRTemplates\TemplateController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\CareerJourneyController;
use App\Http\Controllers\Api\Leave\LeaveDashboardController;
use App\Http\Controllers\Api\Leave\LeaveRequestApiController;
use App\Http\Controllers\Api\Leave\LeaveOptionsController;
use App\Http\Controllers\Api\Leave\LeaveReportApiController;
use App\Http\Controllers\Api\Leave\LeaveTypeApiController;
use App\Http\Controllers\Api\Leave\HolidayApiController;
use App\Http\Controllers\Api\Leave\LeaveWorkflowApiController;
use App\Http\Controllers\Api\Leave\LeaveDistributionApiController;
use App\Http\Controllers\Api\Attendance\AttendanceTrackingApiController;
use App\Http\Controllers\Api\Attendance\AttendanceReportApiController;
use App\Http\Controllers\Api\Attendance\AttendanceDashboardApiController;


Route::post('/send-otp', [signupOtpController::class, 'sendOtp']);
Route::post('/verify-otp', [signupOtpController::class, 'verifyOtp']);
Route::post('/newsletter/send', [NewsletterController::class, 'sendNewsletter']);
Route::match(['get', 'post'], '/user/profile', [UserProfileController::class, 'show']);

Route::get('/jobroles/{jobRoleId}/graph', [JobRoleGraphController::class, 'show']);
Route::get('/organizations/{orgId}/graph', [OrganizationGraphController::class, 'show']);
Route::get('/departments/{deptId}/graph', [DepartmentGraphController::class, 'show']);
Route::post('/ai-generated-assessment/question/store',[generateQuestionController::class, 'store']);
Route::get('/ai-generated-assessment/question/index',[generateQuestionController::class, 'index']);

Route::post('/ai-generated-assessment/assessment/store', [generateAssessmentController::class, 'store']);
Route::get('/ai-generated-assessment/assessment/index',[generateAssessmentController::class, 'index']);


Route::resource('interview-schedules', talent_interviewschedulescontroller::class);
Route::get('/candidate-pipeline', [talent_interviewschedulescontroller::class, 'candidatepipeline']);

Route::get('job-applications/shortlisted', [talent_jobapplicationcontroller::class, 'getShortlistedCandidates']);
Route::resource('job-applications', talent_jobapplicationcontroller::class);

Route::resource('job-postings', talent_jobpostingcontroller::class);
Route::get('/talent/team-overview', [talent_jobpostingcontroller::class, 'getHiringStatus']);

Route::post('talent-screening-results', [talent_screening_results_controller::class, 'store']);
Route::get('talent-screening-results/candidate/{candidate_id}', [talent_screening_results_controller::class, 'show']);

Route::get('offers', [TalentOfferController::class, 'index']);
Route::post('talent-offers', [TalentOfferController::class, 'store']);
Route::post('talent-offers/{id}/reject', [TalentOfferController::class, 'reject']);
Route::get('talent-offer-letter/{offerId}', [TalentOfferController::class, 'getOfferLetter']);
Route::get('talent-templates', [TalentOfferController::class, 'getTemplates']);

Route::post('/talent-acquisition/kpis', [TalentAcquisitionController::class, 'getKpis']);
Route::post('/talent-acquisition/dropoff', [CandidateDropoffController::class, 'getDropoff']);
Route::post('/talent-acquisition/funnel', [CandidateDropoffController::class, 'getFunnelData']);
Route::post('/talent-acquisition/requisitions', [CandidateDropoffController::class, 'getRequisitions']);

Route::post('designation_leave', [HrmsLeaveController::class, 'store']);

Route::post('/jobrole-skill/store', [jobroleskillcontroller::class, 'storeSkill']);


Route::resource('job-role-tasks', jobroletaskcontroller::class);


Route::resource('jobroletexonomies', jobroletexonomycontroller::class);

Route::resource('skills', skillcontroller::class);

Route::resource('interview-schedules', talent_interviewschedulescontroller::class);
Route::put('/interview-schedules', [talent_interviewschedulescontroller::class, 'customUpdate']);
Route::post('job-applications/{id}/status', [talent_jobapplicationcontroller::class, 'updateStatus']);
Route::get('job-applications/candidate/{candidate_id}', [talent_jobapplicationcontroller::class, 'getCandidateApplications']);
Route::resource('job-postings', talent_jobpostingcontroller::class);
Route::post('designation_leave', [HrmsController::class, 'store']);
Route::post('/jobrole-skill/store', [jobroleskillcontroller::class, 'storeSkill']);
Route::resource('job-role-tasks', jobroletaskcontroller::class);
Route::resource('jobroletexonomies', jobroletexonomycontroller::class);
Route::resource('skills', skillcontroller::class);
Route::get('skills/search', [jobrolecontroller::class, 'searchskills']);
Route::get('jobrole/{id}/skills', [jobrolecontroller::class, 'skills']);
Route::get('/department/{id}/jobroles', [jobrolecontroller::class, 'getJobRolesByDepartment']);
Route::get('/industry/{id}/departments', [IndustryController::class, 'departments']);
Route::get('/industries', [IndustryController::class, 'index']);
Route::get('/competency-dashboard', [CompetencyDashboardController::class, 'index']);
Route::get('/skill-development/progress', [SkillDevelopmentController::class, 'getSkillProgress']);
Route::get('/skill-development/streak', [SkillDevelopmentController::class, 'getLearningStreak']);
Route::get('/skill-development/weekly-goal', [SkillDevelopmentController::class, 'getWeeklyLearningGoal']);
Route::get('/skill-development/achievements', [SkillDevelopmentController::class, 'getUserAchievements']);
Route::get('/skill-development/peer-comparison', [SkillDevelopmentController::class, 'getPeerComparison']);
Route::get('/skill-development/calendar', [SkillDevelopmentController::class, 'getLearningCalendar']);

Route::get('/competency/workload-heatmap', [SubCompetencyDashboardController::class, 'getWorkloadHeatmap']);
Route::get('/competency/kpi', [SubCompetencyDashboardController::class, 'getKPI']);
Route::get('/competency/role-similarity', [SubCompetencyDashboardController::class, 'getRoleSimilarity']);
Route::get('/competency/coverage-scorecards', [SubCompetencyDashboardController::class, 'getCoverageScorecards']);
Route::get('/competency/health-radar', [SubCompetencyDashboardController::class, 'getHealthRadar']);
Route::get('/competency/skills-management-funnel', [SubCompetencyDashboardController::class, 'getSkillsManagementFunnel']);
Route::get('/competency/alignment', [SubCompetencyDashboardController::class, 'getAlignment']);

//HRIT dashboard
Route::get('/attendance-weekly', [AttendanceApiController::class, 'weeklySummary']);
Route::get('/KPI-HRITDashboard', [AttendanceApiController::class, 'KPI']);
Route::get('/employee-attendance-monthly-report', [AttendanceApiController::class, 'employeeMonthlyReport']);

Route::get('/jobroles-by-department', [JobroleApiController::class, 'getDepartmentWise']);
Route::get('/leave-distribution', [LeaveDistribution::class, 'leaveDistribution']);

/*
|--------------------------------------------------------------------------
| Leave Management API
|--------------------------------------------------------------------------
| Token authenticated endpoints backing the Next.js Leave Management module
| (Dashboard, Leave Requests, Reports, Configuration). Every endpoint is
| scoped by sub_institute_id and the April-March leave year - see
| App\Http\Controllers\Api\Leave\Concerns\ResolvesLeaveContext.
*/
Route::prefix('leave')->group(function () {
    // Dashboard
    Route::get('/dashboard', [LeaveDashboardController::class, 'index']);
    Route::get('/trend', [LeaveDashboardController::class, 'trend']);
    Route::get('/department-summary', [LeaveDashboardController::class, 'departmentSummary']);
    Route::get('/type-distribution', [LeaveDashboardController::class, 'typeDistribution']);
    Route::get('/holidays/upcoming', [LeaveDashboardController::class, 'upcomingHolidays']);

    // Shared lookups
    Route::get('/options', [LeaveOptionsController::class, 'index']);
    Route::get('/balances', [LeaveOptionsController::class, 'balances']);

    // Leave requests
    Route::get('/requests', [LeaveRequestApiController::class, 'index']);
    Route::post('/requests', [LeaveRequestApiController::class, 'store']);
    Route::post('/requests/bulk-decision', [LeaveRequestApiController::class, 'bulkDecision']);
    Route::get('/requests/{id}', [LeaveRequestApiController::class, 'show'])->whereNumber('id');
    Route::post('/requests/{id}/decision', [LeaveRequestApiController::class, 'decision'])->whereNumber('id');
    Route::delete('/requests/{id}', [LeaveRequestApiController::class, 'destroy'])->whereNumber('id');

    // Reports
    Route::get('/reports/summary', [LeaveReportApiController::class, 'summary']);
    Route::get('/reports/register', [LeaveReportApiController::class, 'register']);
    Route::get('/reports/balance', [LeaveReportApiController::class, 'balance']);

    // Configuration - leave types
    Route::get('/leave-types', [LeaveTypeApiController::class, 'index']);
    Route::post('/leave-types', [LeaveTypeApiController::class, 'store']);
    Route::put('/leave-types/{id}', [LeaveTypeApiController::class, 'store'])->whereNumber('id');
    Route::patch('/leave-types/{id}/status', [LeaveTypeApiController::class, 'toggleStatus'])->whereNumber('id');
    Route::delete('/leave-types/{id}', [LeaveTypeApiController::class, 'destroy'])->whereNumber('id');

    // Configuration - holidays and weekly off pattern
    Route::get('/holidays', [HolidayApiController::class, 'index']);
    Route::post('/holidays', [HolidayApiController::class, 'store']);
    Route::put('/holidays/{id}', [HolidayApiController::class, 'update'])->whereNumber('id');
    Route::delete('/holidays/{id}', [HolidayApiController::class, 'destroy']);
    Route::get('/weekdays', [HolidayApiController::class, 'weekdays']);
    Route::post('/weekdays', [HolidayApiController::class, 'storeWeekdays']);

    // Configuration - approval workflow and role access
    Route::get('/workflow', [LeaveWorkflowApiController::class, 'workflow']);
    Route::put('/workflow', [LeaveWorkflowApiController::class, 'saveWorkflow']);
    Route::get('/roles', [LeaveWorkflowApiController::class, 'roles']);
    Route::put('/roles', [LeaveWorkflowApiController::class, 'saveRoles']);

    // Distribution - new controller, GET /api/leave-distribution above is
    // untouched and still serves its existing consumers.
    Route::get('/distribution', [LeaveDistributionApiController::class, 'index']);
});

/*
|--------------------------------------------------------------------------
| Attendance Management API
|--------------------------------------------------------------------------
| Token authenticated, session free endpoints backing the Next.js Attendance
| Management module (Attendance Tracking + Attendance Reports).
|
| These are additive: the legacy web routes hrms-attendance,
| hrms-attendance-in-time/store, hrms-attendance-out-time/store,
| hrms-attendance-report and get-employees-list still point at
| App\Http\Controllers\HRMS\HrmsController, and /api/attendance-weekly plus
| /api/KPI-HRITDashboard still point at
| App\Http\Controllers\Api\HRITDashboard\AttendanceApiController.
*/
Route::prefix('attendance')->group(function () {
    // Self service - my attendance calendar and punches
    Route::get('/my-attendance', [AttendanceTrackingApiController::class, 'myAttendance']);
    Route::post('/punch-in', [AttendanceTrackingApiController::class, 'punchIn']);
    Route::post('/punch-out', [AttendanceTrackingApiController::class, 'punchOut']);

    // Report lookups
    Route::get('/report-filters', [AttendanceReportApiController::class, 'filters']);
    Route::get('/employees', [AttendanceReportApiController::class, 'employees']);

    // Dashboard analytics (department + employee scoped)
    Route::get('/weekly-summary', [AttendanceDashboardApiController::class, 'weeklySummary']);
    Route::get('/kpi', [AttendanceDashboardApiController::class, 'kpi']);
});






Route::get('/enroll', [LmsCourseEnrollController::class, 'index']);
Route::get('/enrolled_courses', [LmsCourseEnrollController::class, 'index']);
Route::post('/enroll', [LmsCourseEnrollController::class, 'store']);
Route::put('/enroll/{id}', [LmsCourseEnrollController::class, 'update']);
Route::delete('/enroll/{id}', [LmsCourseEnrollController::class, 'destroy']);


Route::resource('departments-management', DepartmentManagementController::class);

// Department Skills API Routes
Route::get('/department-skills', [DepartmentSkillController::class, 'index']);

Route::post('/save-generated-course', [buildwithAIController::class, 'store']);
Route::get('/index', [buildwithAIController::class, 'index']);

Route::resource('gamma-api', GammaApiController::class);
Route::get('gamma-api/sub-institute/{subInstituteId}', [GammaApiController::class, 'getBySubInstituteId']);

Route::resource('skill_library', skillLibraryController::class);
Route::get('/positions', [InterviewController::class, 'getPositions']);
Route::get('/interviewers', [InterviewController::class, 'getInterviewers']);
Route::get('/get-employee-tasks', [AJAXController::class, 'getUsersMappings']);

Route::get('/interview-panel/users', [talent_interviewpanelController::class, 'getInterviewers']);
Route::post('/interview-panel/store', [talent_interviewpanelController::class, 'storeinterviewer']);
Route::put('/interview-panel/update/{id}', [talent_interviewpanelController::class, 'update']);
Route::delete('/interview-panel/delete/{id}', [talent_interviewpanelController::class, 'destroy']);
Route::get('/interview-panel/list', [talent_interviewpanelController::class, 'getInterviewPanel']);
Route::get('/candidate', [candidateController::class,'getCandidate']);
Route::get('/feedback', [feedbackController::class, 'getAllFeedback']);
Route::get('/feedback/{id}', [feedbackController::class, 'getFeedback']);
Route::post('/evaluation', [feedbackController::class, 'storeFeedback']);
Route::get('/pending-feedback', [feedbackController::class, 'getPendingFeedback']);
Route::get('/interview-details', [talent_interviewschedulescontroller::class, 'index']);
Route::put('/feedback/{id}', [feedbackController::class, 'updateFeedback']);
Route::post('/interviews/{id}/decision', [InterviewController::class, 'recordDecision']);

Route::get('/kpis', [EmployeeSkillCoverageMatrixController::class, 'getKpiMetrics']);
Route::get('/skill-gaps', [EmployeeSkillCoverageMatrixController::class, 'skillGaps']);

Route::group(['prefix' => 'reports'], function () {
    Route::get('/kpi', [KpiController::class, 'index']);
    Route::get('/hiring-analytics', [HiringAnalyticsController::class, 'getHiringTrends']);
    Route::get('/departments/distribution', [DepartmentDistributionController::class, 'index']);
    Route::get('/departments/summary', [DepartmentDistributionController::class, 'getDepartmentSummary']);
    Route::get('/departments/sizes', [DepartmentSizeController::class, 'getDepartmentSizes']);
    Route::get('/employees/lifecycle', [EmployeeLifecycleController::class, 'getEmployeeLifecycle']);
    Route::get('/organization/growth', [OrganizationGrowthController::class, 'index']);
    Route::get('/skill-coverage/matrix', [EmployeeSkillCoverageMatrixController::class, 'index']);
    Route::get('/skill-trends', [EmployeeSkillCoverageMatrixController::class, 'skillTrends']);
    Route::get('/employee-directory-analytics', [EmployeeDirectoryAnalyticsController::class, 'getKPIs']);
    Route::get('/employee-directory/growth', [EmployeeDirectoryAnalyticsController::class, 'getGrowthData']);
    Route::get('/employee-directory/growth/stacked', [EmployeeDirectoryAnalyticsController::class, 'getStackedGrowth']);
    Route::get('/employee-directory/departments/distribution', [EmployeeDirectoryAnalyticsController::class, 'getDepartmentDistribution']);
    Route::get('/employee-directory/job-roles/distribution', [EmployeeDirectoryAnalyticsController::class, 'getJobRoleDistribution']);
    Route::get('/employee-directory/lifecycle', [EmployeeDirectoryAnalyticsController::class, 'getLifecycle']);
    Route::get('/employee-directory/attrition', [EmployeeDirectoryAnalyticsController::class, 'getAttritionBreakdown']);
    Route::get('/employee-directory/skills/matrix', [EmployeeDirectoryAnalyticsController::class, 'getSkillMatrix']);
});

Route::post('/gemini/analyze-jd', [AnalyzeJDController::class, 'analyze']);
Route::post('/gemini/save-jd', [SaveJDController::class, 'save']);
Route::post('/gemini/generate-questions', [GenerateQuestionsController::class, 'generate']);

Route::get('/user-rejected-tasks', [SkillMatchingController::class, 'getUserRejectedTasks']);
Route::get('/user-rejected-tasks-courses', [SkillMatchingController::class, 'getCoursesForUserRejectedTasksSkills']);

Route::post('/employee/course-suggestions', [SuggestedCourseController::class, 'store']);

// Task API Routes
Route::get('/tasks/counts', [TaskController::class, 'getTaskCounts']);
Route::get('/tasks/daily', [TaskController::class, 'getDailyTasks']);
Route::get('/tasks/weekly', [TaskController::class, 'getWeeklyTasks']);
Route::get('/tasks/monthly', [TaskController::class, 'getMonthlyTasks']);
Route::get('/user-skills/{user_id}', [UserSkillController::class, 'getUserSkills']);

// User Journey Log API Routes
Route::post('/user-journey-logs', [UserJourneyLogController::class, 'store']);
Route::post('/user-journey-logs/bulk', [UserJourneyLogController::class, 'storeBulk']);

// School Setup API Routes
Route::post('/school-setup', [SchoolSetupController::class, 'store']);


Route::post('/user-signup', [UserSignupController::class, 'store']);
Route::get('/user-signup/{id}', [UserSignupController::class, 'show']);
Route::put('/user-signup/{id}', [UserSignupController::class, 'update']);
Route::delete('/user-signup/{id}', [UserSignupController::class, 'destroy']);

Route::post('/update-fcm-token', [tbluserController::class, 'updateFcmToken']);

// Skill Heatmap API Routes
Route::prefix('skill-heatmap')->group(function () {
    // Main heatmap data — departments × skills matrix
    Route::get('/', [SkillHeatmapController::class, 'heatmap']);

    // Drill-down detail for a heatmap cell
    Route::get('/drill', [SkillHeatmapController::class, 'drill']);

});

Route::post('/import-users', [UserImportController::class, 'importUsers']);
Route::get('/excel-agent/credentials', [ExcelAutomationAgentController::class, 'credentialStatus']);
Route::post('/excel-agent/credentials', [ExcelAutomationAgentController::class, 'saveCredentials']);
Route::post('/excel-agent/test-connection', [ExcelAutomationAgentController::class, 'testConnection']);
Route::post('/excel-agent/upload', [ExcelAutomationAgentController::class, 'upload']);
// Course Recommendation API - Get courses based on logged-in user's job role

// Department Job Role Export API - Export department and job role data to CSV
Route::get('/export-department-jobroles/{subInstituteId}', [DepartmentJobRoleExportController::class, 'exportToCsv']);

// Template API Routes
Route::resource('templates', TemplateController::class);
Route::get('templates/{id}/versions', [TemplateController::class, 'versions']);
Route::post('templates/{id}/restore/{version}', [TemplateController::class, 'restore']);

Route::get('/career-journey', [CareerJourneyController::class, 'getCareerJourney']);

// Bulk Task Import API
Route::post('bulk-task/import', [BulkTaskController::class, 'import']);

// Nango Google Calendar OAuth API
Route::post('nango/google/check-connection', [App\Http\Controllers\NangoController::class, 'checkConnection']);
Route::post('nango/google/oauth-url', [App\Http\Controllers\NangoController::class, 'getOauthUrl']);

// Task Google Calendar Re-sync API
Route::post('task/resync-google-calendar', [App\Http\Controllers\front_desk\taskController::class, 'resyncTaskToGoogleCalendar']);

Route::post('/auth/google', [GoogleAuthController::class, 'login']);
