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
use App\Http\Controllers\AJAXController;
use App\Http\Controllers\Api\HRITDashboard\AttendanceApiController;
use App\Http\Controllers\Api\HRITDashboard\JobroleApiController;
use App\Http\Controllers\Api\HRITDashboard\LeaveDistribution;
use App\Http\Controllers\HRMS\HrmsLeaveController;
use App\Http\Controllers\HRMS\DepartmentManagementController;
use App\Http\Controllers\build_with_AI\buildwithAIController;
use App\Http\Controllers\Api\GammaApiController;

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
use App\Http\Controllers\JobRoleGraphController;
use App\Http\Controllers\OrganizationGraphController;
use App\Http\Controllers\DepartmentGraphController;

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

Route::post('talent-offers', [TalentOfferController::class, 'store']);

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
Route::get('/competency/role-similarity', [SubCompetencyDashboardController::class, 'getRoleSimilarity']);
Route::get('/competency/coverage-scorecards', [SubCompetencyDashboardController::class, 'getCoverageScorecards']);
Route::get('/competency/health-radar', [SubCompetencyDashboardController::class, 'getHealthRadar']);
Route::get('/competency/skills-management-funnel', [SubCompetencyDashboardController::class, 'getSkillsManagementFunnel']);
Route::get('/competency/alignment', [SubCompetencyDashboardController::class, 'getAlignment']);

//HRIT dashboard
Route::get('/attendance-weekly', [AttendanceApiController::class, 'weeklySummary']);

Route::get('/jobroles-by-department', [JobroleApiController::class, 'getDepartmentWise']);
Route::get('/leave-distribution', [LeaveDistribution::class, 'leaveDistribution']);






Route::get('/enroll', [LmsCourseEnrollController::class, 'index']);
Route::get('/enrolled_courses', [LmsCourseEnrollController::class, 'index']);
Route::post('/enroll', [LmsCourseEnrollController::class, 'store']);
Route::put('/enroll/{id}', [LmsCourseEnrollController::class, 'update']);
Route::delete('/enroll/{id}', [LmsCourseEnrollController::class, 'destroy']);


Route::resource('departments-management', DepartmentManagementController::class);

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
