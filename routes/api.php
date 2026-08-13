<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\IndustryController;
use App\Http\Controllers\Api\jobrolecontroller;
use App\Http\Controllers\Api\skillcontroller;
use App\Http\Controllers\Api\SkillDevelopmentController;
use App\Http\Controllers\Api\LmsAssessmentController;
use App\Http\Controllers\Api\LmsCourseController;
use App\Http\Controllers\Api\LmsGovernanceController;
use App\Http\Controllers\Api\LmsPartnerController;
use App\Http\Controllers\Api\AiCourseController;
use App\Http\Controllers\Api\LmsLearningController;
use App\Http\Controllers\Api\LmsSessionController;
use App\Http\Controllers\libraries\jobroletexonomycontroller;
use App\Http\Controllers\libraries\jobroletaskcontroller;
use App\Http\Controllers\libraries\jobroleskillcontroller;
use App\Http\Controllers\HRMS\HrmsController;
use App\Http\Controllers\Api\CompetencyDashboardController;
use App\Http\Controllers\Api\CompetencyDashboard\CompetencyDashboardController as SubCompetencyDashboardController;
use App\Http\Controllers\Api\Competency\CommandCenterController as CompetencyCommandCenterController;
// NAMED FOR WHAT IT DOES. CompetencyController manages SKILL LIBRARY entries -
// its store() inserts into s_users_skills, a flat skill row. It does NOT create a
// competency in Q-A2's sense (a named bundle of KASBA items); that is
// CompetencyDefinitionController, on /competency/definitions.
use App\Http\Controllers\Api\Competency\CompetencyController as SkillLibraryCrudController;
use App\Http\Controllers\Api\Competency\FrameworkController as CompetencyFrameworkController;
use App\Http\Controllers\Api\Competency\AssessmentController as CompetencyAssessmentController;
use App\Http\Controllers\Api\Competency\AssessmentCycleController as CompetencyAssessmentCycleController;
use App\Http\Controllers\Api\Competency\EmployeeCompetencyProfileController;
use App\Http\Controllers\Api\Competency\CertificationController as CompetencyCertificationController;
use App\Http\Controllers\Api\Competency\CertificationRequirementController as CompetencyCertificationRequirementController;
use App\Http\Controllers\Api\Competency\DevelopmentPlanController as CompetencyDevelopmentPlanController;
use App\Http\Controllers\Api\Competency\StudioController as CompetencyStudioController;
use App\Http\Controllers\Api\Competency\RoleMappingController as CompetencyRoleMappingController;
use App\Http\Controllers\Api\Competency\MappingReviewController as CompetencyMappingReviewController;
use App\Http\Controllers\Api\Competency\CareerPathController as CompetencyCareerPathController;
use App\Http\Controllers\Api\Competency\LearningAssignmentController as CompetencyLearningAssignmentController;
use App\Http\Controllers\Api\Competency\AuditController as CompetencyAuditController;
use App\Http\Controllers\Api\Competency\LibraryController as CompetencyLibraryController;
use App\Http\Controllers\Api\Competency\ApprovalController as CompetencyApprovalController;
use App\Http\Controllers\Api\Agentic\AgentController as AgenticAgentController;
use App\Http\Controllers\Api\Agentic\ConfigController as AgenticConfigController;
use App\Http\Controllers\Api\Agentic\RunController as AgenticRunController;
use App\Http\Controllers\Api\Agentic\ToolController as AgenticToolController;
use App\Http\Controllers\Api\Agentic\WorkflowController as AgenticWorkflowController;
use App\Http\Controllers\Api\Agentic\AnalyticsController as AgenticAnalyticsController;
use App\Http\Controllers\Api\Agentic\ReflectionController as AgenticReflectionController;
// Talent Management -> Performance & Rewards Center (new module, see the route
// block at the end of this file).
use App\Http\Controllers\Api\Performance\PerformanceOverviewController;
use App\Http\Controllers\Api\Performance\PerformanceCycleController;
use App\Http\Controllers\Api\Performance\PerformanceReviewController;
use App\Http\Controllers\Api\Performance\PerformanceGoalController;
use App\Http\Controllers\Api\Performance\PerformanceAppraisalController;
use App\Http\Controllers\Api\Performance\PerformanceCompensationController;
use App\Http\Controllers\Api\Performance\PerformanceBonusController;
use App\Http\Controllers\Api\Performance\PerformanceCalibrationController;
use App\Http\Controllers\Api\Performance\PerformanceActivityController;
use App\Http\Controllers\Api\Performance\PerformanceSavedViewController;
// Talent Management: dashboard, onboarding, mobility & succession, offboarding
// (routes in the "Talent Management -> Lifecycle" block at the end of this file).
use App\Http\Controllers\Api\TalentDashboardController;
use App\Http\Controllers\Api\Talent\OnboardingJourneyController;
use App\Http\Controllers\Api\Talent\OnboardingTaskController;
use App\Http\Controllers\Api\Talent\OnboardingDocumentController;
use App\Http\Controllers\Api\Talent\InternalJobController;
use App\Http\Controllers\Api\Talent\MobilityRequestController;
use App\Http\Controllers\Api\Talent\SuccessionPlanController;
use App\Http\Controllers\Api\Talent\OffboardingCaseController;
use App\Http\Controllers\Api\Talent\OffboardingClearanceController;
use App\Http\Controllers\Api\Talent\ExitInterviewController;
use App\Http\Controllers\Api\Talent\AdminWorkflowController;
// Talent Management -> Onboarding & Employee Lifecycle Center (route block at the
// end of this file).
use App\Http\Controllers\Api\Onboarding\OnboardingOverviewController;
use App\Http\Controllers\Api\Onboarding\OnboardingJourneyController as V2OnboardingJourneyController;
use App\Http\Controllers\Api\Onboarding\OnboardingTaskController as V2OnboardingTaskController;
use App\Http\Controllers\Api\Onboarding\OnboardingDocumentController as V2OnboardingDocumentController;
use App\Http\Controllers\Api\Onboarding\OnboardingNoteController;
use App\Http\Controllers\Api\Onboarding\OnboardingProbationController;
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
use App\Http\Controllers\Api\TaskManagement\MyTasksController;
use App\Http\Controllers\Api\TaskManagement\CapacityController;
use App\Http\Controllers\Api\TaskManagement\ActivityController;
use App\Http\Controllers\Api\TaskManagement\TaskListController;
use App\Http\Controllers\Api\TaskManagement\ReportController;
use App\Http\Controllers\Api\TaskManagement\AuditLogController;
use App\Http\Controllers\Api\TaskManagement\TaskAttachmentVersionController;
use App\Http\Controllers\Api\TaskManagement\GlobalSearchController;
use App\Http\Controllers\Api\TaskManagement\TaskScheduleController;
use App\Http\Controllers\Api\TaskManagement\VersionedLegacyTaskController;
use App\Http\Controllers\Api\TaskManagement\IdempotentTaskController;
use App\Http\Controllers\Api\TaskManagement\TaskRecurrenceController;
use App\Http\Controllers\Api\TaskManagement\TaskTemplateController;
use App\Http\Controllers\Api\TaskManagement\TaskSubtaskController;
use App\Http\Controllers\Api\TaskManagement\TaskTimeTrackingController;
use App\Http\Controllers\Api\TaskManagement\NotificationController;
use App\Http\Controllers\Api\TaskManagement\LegacyTaskController;
use App\Http\Controllers\Api\TaskManagement\SessionController;
use App\Http\Controllers\Api\TaskManagement\ProjectController;
use App\Http\Controllers\Api\TaskManagement\WorkspaceController;
use App\Http\Controllers\Api\TaskManagement\DependencyController;
use App\Http\Controllers\Api\TaskManagement\DeadlineExtensionController;
use App\Http\Controllers\Api\TaskManagement\TaskOptionController;
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
Route::post('/newsletter/send', [NewsletterController::class, 'sendNewsletter'])->middleware('api.token');
Route::match(['get', 'post'], '/user/profile', [UserProfileController::class, 'show']);

Route::get('/jobroles/{jobRoleId}/graph', [JobRoleGraphController::class, 'show'])->middleware('api.token');
Route::get('/organizations/{orgId}/graph', [OrganizationGraphController::class, 'show'])->middleware('api.token');
Route::get('/departments/{deptId}/graph', [DepartmentGraphController::class, 'show'])->middleware('api.token');
Route::post('/ai-generated-assessment/question/store',[generateQuestionController::class, 'store']);
Route::get('/ai-generated-assessment/question/index',[generateQuestionController::class, 'index']);

Route::post('/ai-generated-assessment/assessment/store', [generateAssessmentController::class, 'store'])->middleware('api.token');
Route::get('/ai-generated-assessment/assessment/index',[generateAssessmentController::class, 'index'])->middleware('api.token');


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

Route::post('/talent-acquisition/kpis', [TalentAcquisitionController::class, 'getKpis'])->middleware('api.token');
Route::post('/talent-acquisition/dropoff', [CandidateDropoffController::class, 'getDropoff']);
Route::post('/talent-acquisition/funnel', [CandidateDropoffController::class, 'getFunnelData']);
Route::post('/talent-acquisition/requisitions', [CandidateDropoffController::class, 'getRequisitions']);

Route::post('designation_leave', [HrmsLeaveController::class, 'store'])->middleware('api.token');

Route::post('/jobrole-skill/store', [jobroleskillcontroller::class, 'storeSkill']);


Route::resource('job-role-tasks', jobroletaskcontroller::class);


Route::resource('jobroletexonomies', jobroletexonomycontroller::class);

Route::resource('skills', skillcontroller::class);

// Removed duplicate route declaration - exact duplicate of the declaration above.
Route::put('/interview-schedules', [talent_interviewschedulescontroller::class, 'customUpdate']);
Route::post('job-applications/{id}/status', [talent_jobapplicationcontroller::class, 'updateStatus']);
Route::get('job-applications/candidate/{candidate_id}', [talent_jobapplicationcontroller::class, 'getCandidateApplications']);
// Removed duplicate route declaration - exact duplicate.
Route::post('designation_leave', [HrmsController::class, 'store']);
Route::post('/jobrole-skill/store', [jobroleskillcontroller::class, 'storeSkill']);
// Removed duplicate route declaration - exact duplicate.
// Removed duplicate route declaration - exact duplicate.
// Removed duplicate route declaration - exact duplicate.
Route::get('skills/search', [jobrolecontroller::class, 'searchskills']);
Route::get('jobrole/{id}/skills', [jobrolecontroller::class, 'skills']);
Route::get('/department/{id}/jobroles', [jobrolecontroller::class, 'getJobRolesByDepartment']);
Route::get('/industry/{id}/departments', [IndustryController::class, 'departments']);
Route::get('/industries', [IndustryController::class, 'index']);
Route::get('/competency-dashboard', [CompetencyDashboardController::class, 'index'])->middleware('api.token');
Route::get('/skill-development/progress', [SkillDevelopmentController::class, 'getSkillProgress']);
Route::get('/skill-development/streak', [SkillDevelopmentController::class, 'getLearningStreak']);
Route::get('/skill-development/weekly-goal', [SkillDevelopmentController::class, 'getWeeklyLearningGoal']);
Route::get('/skill-development/achievements', [SkillDevelopmentController::class, 'getUserAchievements']);
Route::get('/skill-development/peer-comparison', [SkillDevelopmentController::class, 'getPeerComparison']);
Route::get('/skill-development/calendar', [SkillDevelopmentController::class, 'getLearningCalendar']);
Route::get('/skill-development/recent-activity', [SkillDevelopmentController::class, 'getRecentActivity']);

Route::get('/competency/workload-heatmap', [SubCompetencyDashboardController::class, 'getWorkloadHeatmap'])->middleware('api.token');
Route::get('/competency/kpi', [SubCompetencyDashboardController::class, 'getKPI'])->middleware('api.token');
Route::get('/competency/role-similarity', [SubCompetencyDashboardController::class, 'getRoleSimilarity'])->middleware('api.token');
Route::get('/competency/coverage-scorecards', [SubCompetencyDashboardController::class, 'getCoverageScorecards'])->middleware('api.token');
Route::get('/competency/health-radar', [SubCompetencyDashboardController::class, 'getHealthRadar'])->middleware('api.token');
Route::get('/competency/skills-management-funnel', [SubCompetencyDashboardController::class, 'getSkillsManagementFunnel'])->middleware('api.token');
Route::get('/competency/alignment', [SubCompetencyDashboardController::class, 'getAlignment'])->middleware('api.token');

/*
| Competency Command Center + domain CRUD (token authenticated, tenant scoped
| via App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext).
| Additive - does not touch the read-only /competency/* analytics routes above.
*/
Route::get('/competency/command-center', [CompetencyCommandCenterController::class, 'index']);
Route::get('/competency/command-center/filters', [CompetencyCommandCenterController::class, 'filters']);

/*
| Libraries & Taxonomy - the eight library tabs (Skill, Jobrole, Jobrole Task,
| Knowledge, Ability, Attitude, Behaviour, Invisible), their taxonomy editors
| and the skill taxonomy tree.
|
| Fixed segments (meta, taxonomy, skill-taxonomy-tree) are declared before the
| {id} routes so they are never read as an id, and every {id} is whereNumber.
*/
/*
| Approval queue. One inbox governing competencies and frameworks; the existing
| role-mapping reviews are unioned in for reading and still actioned through
| /competency/mapping-reviews.
*/
Route::get('/competency/approvals', [CompetencyApprovalController::class, 'index']);
Route::post('/competency/approvals', [CompetencyApprovalController::class, 'store']);
Route::post('/competency/approvals/bulk-approve', [CompetencyApprovalController::class, 'bulkApprove']);
Route::get('/competency/approvals/for/{type}/{id}', [CompetencyApprovalController::class, 'forSubject'])->whereNumber('id');
Route::put('/competency/approvals/{id}', [CompetencyApprovalController::class, 'update'])->whereNumber('id');

Route::get('/competency/library/meta', [CompetencyLibraryController::class, 'meta']);
Route::get('/competency/library/skill-taxonomy-tree', [CompetencyLibraryController::class, 'skillTaxonomyTree']);
Route::get('/competency/library/levels-of-responsibility', [CompetencyLibraryController::class, 'levelsOfResponsibility']);
Route::get('/competency/library/work-functions', [CompetencyLibraryController::class, 'workFunctions']);

Route::get('/competency/library/taxonomy/{type}', [CompetencyLibraryController::class, 'taxonomy']);
Route::post('/competency/library/taxonomy/{type}', [CompetencyLibraryController::class, 'storeTaxonomy']);
Route::put('/competency/library/taxonomy/{type}', [CompetencyLibraryController::class, 'updateTaxonomy']);
Route::delete('/competency/library/taxonomy/{type}', [CompetencyLibraryController::class, 'destroyTaxonomy']);

Route::get('/competency/library/skills', [CompetencyLibraryController::class, 'skills']);
Route::post('/competency/library/skills', [CompetencyLibraryController::class, 'storeSkill']);
Route::get('/competency/library/skills/{id}', [CompetencyLibraryController::class, 'showSkill'])->whereNumber('id');
Route::put('/competency/library/skills/{id}', [CompetencyLibraryController::class, 'updateSkill'])->whereNumber('id');
Route::delete('/competency/library/skills/{id}', [CompetencyLibraryController::class, 'destroySkill'])->whereNumber('id');

Route::get('/competency/library/jobroles', [CompetencyLibraryController::class, 'jobroles']);
Route::post('/competency/library/jobroles', [CompetencyLibraryController::class, 'storeJobrole']);
Route::get('/competency/library/jobroles/{id}', [CompetencyLibraryController::class, 'showJobrole'])->whereNumber('id');
Route::put('/competency/library/jobroles/{id}', [CompetencyLibraryController::class, 'updateJobrole'])->whereNumber('id');
Route::delete('/competency/library/jobroles/{id}', [CompetencyLibraryController::class, 'destroyJobrole'])->whereNumber('id');

Route::get('/competency/library/jobrole-tasks', [CompetencyLibraryController::class, 'jobroleTasks']);
Route::post('/competency/library/jobrole-tasks', [CompetencyLibraryController::class, 'storeJobroleTask']);
Route::get('/competency/library/jobrole-tasks/{id}', [CompetencyLibraryController::class, 'showJobroleTask'])->whereNumber('id');
Route::put('/competency/library/jobrole-tasks/{id}', [CompetencyLibraryController::class, 'updateJobroleTask'])->whereNumber('id');
Route::delete('/competency/library/jobrole-tasks/{id}', [CompetencyLibraryController::class, 'destroyJobroleTask'])->whereNumber('id');

// One set of routes for all four KASA tabs: {type} is knowledge|ability|attitude|behaviour.
Route::get('/competency/library/kasa/{type}', [CompetencyLibraryController::class, 'kasa']);
Route::post('/competency/library/kasa/{type}', [CompetencyLibraryController::class, 'storeKasa']);
// Where a knowledge / ability / attitude / behaviour item is actually used:
// which skills reference it, at which levels, and the job roles that inherit
// it. Declared before the {id} show route so 'usage' is not read as an id.
Route::get('/competency/library/kasa/{type}/{id}/usage', [CompetencyLibraryController::class, 'usageKasa'])->whereNumber('id');
Route::get('/competency/library/kasa/{type}/{id}', [CompetencyLibraryController::class, 'showKasa'])->whereNumber('id');
Route::put('/competency/library/kasa/{type}/{id}', [CompetencyLibraryController::class, 'updateKasa'])->whereNumber('id');
Route::delete('/competency/library/kasa/{type}/{id}', [CompetencyLibraryController::class, 'destroyKasa'])->whereNumber('id');

Route::get('/competency/library/invisible', [CompetencyLibraryController::class, 'invisible']);
Route::post('/competency/library/invisible', [CompetencyLibraryController::class, 'storeInvisible']);
Route::post('/competency/library/invisible/{id}/clone', [CompetencyLibraryController::class, 'cloneInvisible'])->whereNumber('id');
Route::get('/competency/library/invisible/{id}', [CompetencyLibraryController::class, 'showInvisible'])->whereNumber('id');
Route::put('/competency/library/invisible/{id}', [CompetencyLibraryController::class, 'updateInvisible'])->whereNumber('id');
Route::delete('/competency/library/invisible/{id}', [CompetencyLibraryController::class, 'destroyInvisible'])->whereNumber('id');

Route::get('/competency/assessment-cycles', [CompetencyAssessmentCycleController::class, 'index']);
Route::post('/competency/assessment-cycles', [CompetencyAssessmentCycleController::class, 'store']);
Route::get('/competency/assessment-cycles/metrics', [CompetencyAssessmentCycleController::class, 'metrics']);
Route::get('/competency/assessment-cycles/participant-ratings', [CompetencyAssessmentCycleController::class, 'participantRatings']);
Route::get('/competency/assessment-cycles/calibration', [CompetencyAssessmentCycleController::class, 'calibration']);
Route::get('/competency/assessment-cycles/approvals', [CompetencyAssessmentCycleController::class, 'approvals']);
Route::get('/competency/assessment-cycles/closed', [CompetencyAssessmentCycleController::class, 'closed']);
Route::put('/competency/assessment-cycles/assessments/{id}/review', [CompetencyAssessmentCycleController::class, 'reviewAssessment'])->whereNumber('id');
// "View Configuration" - declared BEFORE /{id} so the word is not read as an id.
Route::get('/competency/assessment-cycles/configuration', [CompetencyAssessmentCycleController::class, 'configuration']);
Route::put('/competency/assessment-cycles/configuration', [CompetencyAssessmentCycleController::class, 'saveConfiguration']);
Route::get('/competency/assessment-cycles/{id}/participants', [CompetencyAssessmentCycleController::class, 'participants'])->whereNumber('id');
// Campaign detail panel: Overview / Edit / Ratings / Calibration / Audit Trail.
Route::get('/competency/assessment-cycles/{id}/ratings', [CompetencyAssessmentCycleController::class, 'ratings'])->whereNumber('id');
Route::get('/competency/assessment-cycles/{id}/calibration-queue', [CompetencyAssessmentCycleController::class, 'calibrationQueue'])->whereNumber('id');
Route::get('/competency/assessment-cycles/{id}/audit-trail', [CompetencyAssessmentCycleController::class, 'auditTrail'])->whereNumber('id');
Route::get('/competency/assessment-cycles/{id}', [CompetencyAssessmentCycleController::class, 'show'])->whereNumber('id');
Route::put('/competency/assessment-cycles/{id}', [CompetencyAssessmentCycleController::class, 'update'])->whereNumber('id');

Route::get('/competency/employee-profiles/{id}', [EmployeeCompetencyProfileController::class, 'show'])->whereNumber('id');
Route::get('/competency/employee-profiles/{id}/available-skills', [EmployeeCompetencyProfileController::class, 'availableSkills'])->whereNumber('id');
Route::post('/competency/employee-profiles/{id}/skills', [EmployeeCompetencyProfileController::class, 'addSkill'])->whereNumber('id')->middleware('profile:admin,hr,manager');
Route::put('/competency/employee-profiles/{id}/skills/{matrixId}', [EmployeeCompetencyProfileController::class, 'updateSkill'])->whereNumber('id')->whereNumber('matrixId')->middleware('profile:admin,hr,manager');
Route::get('/competency/employee-profiles/{id}/skills/{skillId}/history', [EmployeeCompetencyProfileController::class, 'skillHistory'])->whereNumber('id')->whereNumber('skillId');
Route::get('/competency/employee-profiles/{id}/notes', [EmployeeCompetencyProfileController::class, 'notes'])->whereNumber('id');
Route::put('/competency/employee-profiles/{id}/notes', [EmployeeCompetencyProfileController::class, 'saveNotes'])->whereNumber('id')->middleware('profile:admin,hr,manager');
Route::get('/competency/employee-profiles/{id}/certifications', [EmployeeCompetencyProfileController::class, 'certifications'])->whereNumber('id');
Route::get('/competency/employee-profiles/{id}/development-plans', [EmployeeCompetencyProfileController::class, 'developmentPlans'])->whereNumber('id');
Route::get('/competency/employee-profiles/{id}/evidence', [EmployeeCompetencyProfileController::class, 'evidence'])->whereNumber('id');
Route::post('/competency/employee-profiles/{id}/evidence', [EmployeeCompetencyProfileController::class, 'storeEvidence'])->whereNumber('id')->middleware('profile:admin,hr,manager');
Route::delete('/competency/employee-profiles/{id}/evidence/{evidenceId}', [EmployeeCompetencyProfileController::class, 'deleteEvidence'])->whereNumber('id')->whereNumber('evidenceId')->middleware('profile:admin,hr,manager');
Route::get('/competency/employee-profiles/{id}/career-path', [EmployeeCompetencyProfileController::class, 'careerPath'])->whereNumber('id');

/* SLICE 1 item 1 - competency DEFINITIONS (competency + competency_kasba_item).
 * Distinct from /competency/competencies above, which serves the SKILL library
 * (s_users_skills). Writes are HR/Admin only; the gate is RequireProfile, exact
 * role_key matching since G-AUTH-02. */
/* SLICE 1 item 7 - THE GAP. Read-only, so no profile gate: an employee may read
 * their OWN gap (competencySubject), anyone else needs an elevated role_key. */
Route::get('/competency/gap', [\App\Http\Controllers\Api\Competency\CompetencyGapController::class, 'show']);

/* SLICE 1 item 3 - what a job role REQUIRES. jobrole_competency_map holds NO
 * text key, which is what makes the rename proof possible. Writes are HR/Admin. */
Route::get('/competency/role-map', [\App\Http\Controllers\Api\Competency\RoleCompetencyMapController::class, 'index']);
Route::post('/competency/role-map', [\App\Http\Controllers\Api\Competency\RoleCompetencyMapController::class, 'store'])->middleware('profile:admin,hr');
Route::delete('/competency/role-map/{id}', [\App\Http\Controllers\Api\Competency\RoleCompetencyMapController::class, 'destroy'])->whereNumber('id')->middleware('profile:admin,hr');

// COURSE -> COMPETENCY. The table had two shipped consumers (LearningAssigner,
// RemediationRecommender) and NO writer: 56 seeded rows and no way to add a 57th.
// R-03 - the development plan report. Built STANDALONE: the plan says it gates
// on R-01 (a 'consolidated reporting home'), and measurement says R-01 is a
// container rather than a gate. 160 real plans, never reported on.
Route::get('/competency/reports/development-plans', [\App\Http\Controllers\Api\Competency\DevelopmentPlanReportController::class, 'index']);
Route::get('/competency/course-map', [\App\Http\Controllers\Api\Competency\CourseCompetencyMapController::class, 'index']);
Route::post('/competency/course-map', [\App\Http\Controllers\Api\Competency\CourseCompetencyMapController::class, 'store'])->middleware('profile:admin,hr');
Route::delete('/competency/course-map/{id}', [\App\Http\Controllers\Api\Competency\CourseCompetencyMapController::class, 'destroy'])->whereNumber('id')->middleware('profile:admin,hr');

// KASBA RATINGS - the write half of the last link before the gap.
// competency_kasba_rating had 160 seeded rows and NO writer anywhere: both
// existing rating routes are GET and ProficiencyService only LEFT JOINs it.
// These are NEW routes; the assessment-cycle GETs are untouched.
// The candidate list the write half never had. Guarded the same as the write:
// a rating names a person, so reading who can be rated is not a public question.
Route::get('/competency/kasba-rating', [\App\Http\Controllers\Api\Competency\KasbaRatingController::class, 'index'])->middleware('profile:admin,hr');
Route::post('/competency/kasba-rating', [\App\Http\Controllers\Api\Competency\KasbaRatingController::class, 'store'])->middleware('profile:admin,hr');
Route::delete('/competency/kasba-rating', [\App\Http\Controllers\Api\Competency\KasbaRatingController::class, 'destroy'])->middleware('profile:admin,hr');

// L-14: the TASK CATALOGUE -> COMPETENCY write path. Mirrors role-map one level
// down. jobrole_task_id points at s_jobrole_task, a GLOBAL seed library with no
// tenant column, so the task is checked for EXISTENCE and the competency for
// OWNERSHIP - see the controller header.
// Per-role browse for the task->competency panel. `index()` answers what is
// MAPPED; these answer what EXISTS, including the unmapped tasks that by
// definition have no row in the map.
Route::get('/competency/task-map/roles', [\App\Http\Controllers\Api\Competency\JobroleTaskCompetencyMapController::class, 'roles']);
Route::get('/competency/task-map/tasks', [\App\Http\Controllers\Api\Competency\JobroleTaskCompetencyMapController::class, 'tasks']);
Route::get('/competency/task-map', [\App\Http\Controllers\Api\Competency\JobroleTaskCompetencyMapController::class, 'index']);
Route::post('/competency/task-map', [\App\Http\Controllers\Api\Competency\JobroleTaskCompetencyMapController::class, 'store'])->middleware('profile:admin,hr');
Route::delete('/competency/task-map/{id}', [\App\Http\Controllers\Api\Competency\JobroleTaskCompetencyMapController::class, 'destroy'])->middleware('profile:admin,hr');

Route::get('/competency/definitions', [\App\Http\Controllers\Api\Competency\CompetencyDefinitionController::class, 'index']);
Route::post('/competency/definitions', [\App\Http\Controllers\Api\Competency\CompetencyDefinitionController::class, 'store'])->middleware('profile:admin,hr');

Route::get('/competency/competencies', [SkillLibraryCrudController::class, 'index']);
Route::post('/competency/competencies', [SkillLibraryCrudController::class, 'store']);
Route::delete('/competency/competencies/{id}', [SkillLibraryCrudController::class, 'destroy'])->whereNumber('id');

Route::get('/competency/frameworks', [CompetencyFrameworkController::class, 'index']);
Route::post('/competency/frameworks', [CompetencyFrameworkController::class, 'store']);
Route::delete('/competency/frameworks/{id}', [CompetencyFrameworkController::class, 'destroy'])->whereNumber('id');

Route::get('/competency/assessments', [CompetencyAssessmentController::class, 'index']);
Route::post('/competency/assessments', [CompetencyAssessmentController::class, 'store']);
Route::delete('/competency/assessments/{id}', [CompetencyAssessmentController::class, 'destroy'])->whereNumber('id');

Route::get('/competency/certifications', [CompetencyCertificationController::class, 'index']);
Route::post('/competency/certifications', [CompetencyCertificationController::class, 'store']);
Route::delete('/competency/certifications/{id}', [CompetencyCertificationController::class, 'destroy'])->whereNumber('id');

Route::get('/competency/development-plans', [CompetencyDevelopmentPlanController::class, 'index']);
Route::post('/competency/development-plans', [CompetencyDevelopmentPlanController::class, 'store']);
Route::delete('/competency/development-plans/{id}', [CompetencyDevelopmentPlanController::class, 'destroy'])->whereNumber('id');

/*
| Development & Career Path Workspace (token authenticated, tenant scoped).
| Additive on top of the three development-plan routes above, which keep their
| existing contract. Reuses s_skill_matrix + s_user_skill_jobrole for gaps,
| s_competency_activity_log for history, career_journey + s_user_jobrole for the
| progression graph and sub_std_map + lms_assignments for learning; adds
| s_competency_plan_actions and s_competency_career_paths/_steps.
*/

// Static segments first so they cannot be swallowed by /{id}.
Route::get('/competency/development-plans/metrics', [CompetencyDevelopmentPlanController::class, 'metrics']);
Route::get('/competency/development-plans/owners', [CompetencyDevelopmentPlanController::class, 'owners']);
Route::get('/competency/employee-options', [CompetencyDevelopmentPlanController::class, 'employees']);

Route::get('/competency/development-plans/{id}', [CompetencyDevelopmentPlanController::class, 'show'])->whereNumber('id');
Route::put('/competency/development-plans/{id}', [CompetencyDevelopmentPlanController::class, 'update'])->whereNumber('id');
Route::get('/competency/development-plans/{id}/gaps', [CompetencyDevelopmentPlanController::class, 'gaps'])->whereNumber('id');
Route::get('/competency/development-plans/{id}/history', [CompetencyDevelopmentPlanController::class, 'history'])->whereNumber('id');
Route::get('/competency/development-plans/{id}/actions', [CompetencyDevelopmentPlanController::class, 'actions'])->whereNumber('id');
Route::post('/competency/development-plans/{id}/actions', [CompetencyDevelopmentPlanController::class, 'storeAction'])->whereNumber('id');
Route::put('/competency/development-plans/{id}/actions/{actionId}', [CompetencyDevelopmentPlanController::class, 'updateAction'])->whereNumber('id')->whereNumber('actionId');
Route::delete('/competency/development-plans/{id}/actions/{actionId}', [CompetencyDevelopmentPlanController::class, 'destroyAction'])->whereNumber('id')->whereNumber('actionId');

// Named career paths + the Career Path Explorer.
Route::get('/competency/career-paths/explorer', [CompetencyCareerPathController::class, 'explorer']);
Route::get('/competency/career-paths/role-options', [CompetencyCareerPathController::class, 'roleOptions']);
Route::get('/competency/career-paths', [CompetencyCareerPathController::class, 'index']);
Route::post('/competency/career-paths', [CompetencyCareerPathController::class, 'store']);
Route::get('/competency/career-paths/{id}', [CompetencyCareerPathController::class, 'show'])->whereNumber('id');
Route::put('/competency/career-paths/{id}', [CompetencyCareerPathController::class, 'update'])->whereNumber('id');
Route::delete('/competency/career-paths/{id}', [CompetencyCareerPathController::class, 'destroy'])->whereNumber('id');

// Learning assignments (lms_assignments rows tagged source='competency').
Route::get('/competency/learning-assignments/courses', [CompetencyLearningAssignmentController::class, 'courses']);
Route::get('/competency/learning-assignments', [CompetencyLearningAssignmentController::class, 'index']);
Route::post('/competency/learning-assignments', [CompetencyLearningAssignmentController::class, 'store']);
Route::put('/competency/learning-assignments/{id}', [CompetencyLearningAssignmentController::class, 'update'])->whereNumber('id');
Route::delete('/competency/learning-assignments/{id}', [CompetencyLearningAssignmentController::class, 'destroy'])->whereNumber('id');

/*
| Framework & Role Mapping Studio (token authenticated, tenant scoped).
| Additive: reuses existing tables (s_users_skills, s_user_jobrole,
| s_user_skill_jobrole, s_proficiency_levels, s_competency_frameworks/_items)
| plus two new studio tables (s_competency_framework_weights, _mapping_reviews).
*/
Route::get('/competency/studio/summary', [CompetencyStudioController::class, 'summary']);
Route::get('/competency/studio/framework-structure', [CompetencyStudioController::class, 'frameworkStructure']);
Route::get('/competency/studio/proficiency-scale', [CompetencyStudioController::class, 'proficiencyScale']);
Route::post('/competency/studio/proficiency-scale', [CompetencyStudioController::class, 'storeLevel']);
Route::put('/competency/studio/proficiency-scale/{id}', [CompetencyStudioController::class, 'updateLevel'])->whereNumber('id');
Route::delete('/competency/studio/proficiency-scale/{id}', [CompetencyStudioController::class, 'deleteLevel'])->whereNumber('id');
Route::get('/competency/studio/weights', [CompetencyStudioController::class, 'weights']);
Route::put('/competency/studio/weights', [CompetencyStudioController::class, 'saveWeights']);
// Scoring rules behind the weights (s_competency_settings, scope='weighting').
Route::get('/competency/studio/weighting-config', [CompetencyStudioController::class, 'weightingConfig']);
Route::put('/competency/studio/weighting-config', [CompetencyStudioController::class, 'saveWeightingConfig']);

// Framework show / update / clone / items / weighting (list/create/delete are above).
Route::get('/competency/frameworks/{id}', [CompetencyFrameworkController::class, 'show'])->whereNumber('id');
Route::put('/competency/frameworks/{id}', [CompetencyFrameworkController::class, 'update'])->whereNumber('id');
Route::post('/competency/frameworks/{id}/clone', [CompetencyFrameworkController::class, 'clone'])->whereNumber('id');
Route::get('/competency/frameworks/{id}/items', [CompetencyFrameworkController::class, 'items'])->whereNumber('id');
Route::post('/competency/frameworks/{id}/items', [CompetencyFrameworkController::class, 'storeItem'])->whereNumber('id');
Route::delete('/competency/frameworks/{id}/items/{itemId}', [CompetencyFrameworkController::class, 'destroyItem'])->whereNumber('id')->whereNumber('itemId');
Route::get('/competency/frameworks/{id}/weights', [CompetencyFrameworkController::class, 'weights'])->whereNumber('id');
Route::put('/competency/frameworks/{id}/weights', [CompetencyFrameworkController::class, 'saveWeights'])->whereNumber('id');

// Role mapping matrix (cells live on s_user_skill_jobrole).
Route::get('/competency/role-mapping/roles', [CompetencyRoleMappingController::class, 'roles']);
Route::get('/competency/role-mapping/matrix', [CompetencyRoleMappingController::class, 'matrix']);
Route::put('/competency/role-mapping/cell', [CompetencyRoleMappingController::class, 'upsertCell']);
Route::delete('/competency/role-mapping/cell', [CompetencyRoleMappingController::class, 'deleteCell']);

// Mapping-change approval workflow.
Route::get('/competency/mapping-reviews', [CompetencyMappingReviewController::class, 'index']);
Route::post('/competency/mapping-reviews', [CompetencyMappingReviewController::class, 'store']);
Route::put('/competency/mapping-reviews/{id}', [CompetencyMappingReviewController::class, 'update'])->whereNumber('id');
Route::post('/competency/mapping-reviews/bulk-approve', [CompetencyMappingReviewController::class, 'bulkApprove']);

/*
| Certification & Compliance Center (token authenticated, tenant scoped).
| Additive on top of the three certification routes above, which keep their
| paths and response envelope. Reads/writes s_competency_certifications, the
| new s_competency_certification_requirements policy table, the shared
| s_competency_evidence table for documents and s_competency_activity_log for
| history. Static segments are declared BEFORE the /{id} routes so the numeric
| show/update route cannot swallow metrics / filters / export / bulk.
*/
Route::get('/competency/certifications/metrics', [CompetencyCertificationController::class, 'metrics']);
Route::get('/competency/certifications/filters', [CompetencyCertificationController::class, 'filters']);
Route::get('/competency/certifications/export', [CompetencyCertificationController::class, 'export']);
Route::post('/competency/certifications/bulk', [CompetencyCertificationController::class, 'bulk']);

Route::get('/competency/certifications/{id}', [CompetencyCertificationController::class, 'show'])->whereNumber('id');
Route::put('/competency/certifications/{id}', [CompetencyCertificationController::class, 'update'])->whereNumber('id');
Route::post('/competency/certifications/{id}/notes', [CompetencyCertificationController::class, 'addNote'])->whereNumber('id');
Route::get('/competency/certifications/{id}/compliance', [CompetencyCertificationController::class, 'compliance'])->whereNumber('id');
Route::get('/competency/certifications/{id}/requirements', [CompetencyCertificationController::class, 'requirements'])->whereNumber('id');
Route::get('/competency/certifications/{id}/history', [CompetencyCertificationController::class, 'history'])->whereNumber('id');
Route::get('/competency/certifications/{id}/documents', [CompetencyCertificationController::class, 'documents'])->whereNumber('id');
Route::post('/competency/certifications/{id}/documents', [CompetencyCertificationController::class, 'storeDocument'])->whereNumber('id');
Route::delete('/competency/certifications/{id}/documents/{documentId}', [CompetencyCertificationController::class, 'destroyDocument'])->whereNumber('id')->whereNumber('documentId');

// Certification requirements - the "which role must hold what" policy master.
Route::get('/competency/certification-requirements', [CompetencyCertificationRequirementController::class, 'index']);
Route::post('/competency/certification-requirements', [CompetencyCertificationRequirementController::class, 'store']);
Route::put('/competency/certification-requirements/{id}', [CompetencyCertificationRequirementController::class, 'update'])->whereNumber('id');
Route::delete('/competency/certification-requirements/{id}', [CompetencyCertificationRequirementController::class, 'destroy'])->whereNumber('id');

/*
| Audit & Activity Center (token authenticated, tenant scoped).
| Read-only over s_competency_activity_log - the feed every competency
| controller already writes to via ResolvesCompetencyContext - plus
| tbl_user_journey_logs for the User Actions Log tab's screen-access history.
| The only write is the export event the export endpoint logs about itself.
| Static segments are declared BEFORE /{id} so user-actions is not swallowed.
*/
Route::get('/competency/audit/metrics', [CompetencyAuditController::class, 'metrics']);
Route::get('/competency/audit/filters', [CompetencyAuditController::class, 'filters']);
Route::get('/competency/audit/export', [CompetencyAuditController::class, 'export']);
Route::get('/competency/audit/user-actions', [CompetencyAuditController::class, 'userActions']);
Route::get('/competency/audit/user-actions/{userId}', [CompetencyAuditController::class, 'userActivity'])->whereNumber('userId');
Route::get('/competency/audit', [CompetencyAuditController::class, 'index']);
Route::get('/competency/audit/{id}', [CompetencyAuditController::class, 'show'])->whereNumber('id');

//HRIT dashboard
Route::get('/attendance-weekly', [AttendanceApiController::class, 'weeklySummary']);
Route::get('/KPI-HRITDashboard', [AttendanceApiController::class, 'KPI']);
Route::get('/employee-attendance-monthly-report', [AttendanceApiController::class, 'employeeMonthlyReport']);

Route::get('/jobroles-by-department', [JobroleApiController::class, 'getDepartmentWise'])->middleware('api.token');
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
Route::get('/available_courses', [LmsCourseEnrollController::class, 'available']);

/*
| LMS Assignments – token-authenticated assignment management.
| These sit in api.php (not lms.php) so they resolve under the /api prefix
| that the Next.js frontend expects.
*/
Route::get('/lmsAssignment/stats', [\App\Http\Controllers\lms\assignment\assignmentController::class, 'stats']);
Route::post('/lmsAssignment/bulkUpdateStatus', [\App\Http\Controllers\lms\assignment\assignmentController::class, 'bulkUpdateStatus']);
Route::post('/lmsAssignment/updateStatus/{id}', [\App\Http\Controllers\lms\assignment\assignmentController::class, 'updateStatus']);
Route::post('/lmsAssignment/import', [\App\Http\Controllers\lms\assignment\assignmentController::class, 'import']);
Route::get('/lmsAssignment/learners', [\App\Http\Controllers\lms\assignment\assignmentController::class, 'learners']);
Route::get('/lmsAssignment/enrollments', [\App\Http\Controllers\lms\assignment\assignmentController::class, 'enrollments']);
Route::post('/lmsAssignment/request', [\App\Http\Controllers\lms\assignment\assignmentController::class, 'requestEnrollment']);
Route::post('/lmsAssignment/review/{id}', [\App\Http\Controllers\lms\assignment\assignmentController::class, 'review']);
Route::post('/lmsAssignment/bulkReview', [\App\Http\Controllers\lms\assignment\assignmentController::class, 'bulkReview']);
Route::get('/lmsAssignment', [\App\Http\Controllers\lms\assignment\assignmentController::class, 'index']);
Route::post('/lmsAssignment', [\App\Http\Controllers\lms\assignment\assignmentController::class, 'store']);

/*
| Learning Catalog - token-authenticated course management. The equivalent
| school_setup/sub_std_map web routes stay untouched for the Blade admin UI.
| Static segments are declared before /{id} so they are not captured by it.
*/
/*
| Administration & Governance.
|
| Users, roles and the permission matrix all have controllers already, but
| those live in routes/user.php behind ['auth','session','menu'] - session
| authenticated and CSRF protected, so unusable cross-origin for writes.
| Trainers, vendors and integrations are new entities with no prior model.
| routes/user.php is untouched, so the old frontend keeps working.
*/
Route::get('/lms/governance/kpis', [LmsGovernanceController::class, 'kpis']);
Route::get('/lms/governance/system-health', [LmsGovernanceController::class, 'systemHealth']);
Route::get('/lms/governance/audit-logs', [LmsGovernanceController::class, 'auditLogs']);

Route::get('/lms/governance/users', [LmsGovernanceController::class, 'users']);
Route::post('/lms/governance/users', [LmsGovernanceController::class, 'storeUser']);
// Stays ahead of /users/{id} so the wildcard does not swallow it.
Route::post('/lms/governance/users/import', [LmsGovernanceController::class, 'importUsers']);
Route::put('/lms/governance/users/{id}', [LmsGovernanceController::class, 'updateUser']);
Route::delete('/lms/governance/users/{id}', [LmsGovernanceController::class, 'destroyUser']);

Route::get('/lms/governance/roles', [LmsGovernanceController::class, 'roles']);
Route::post('/lms/governance/roles', [LmsGovernanceController::class, 'storeRole']);
Route::put('/lms/governance/roles/{id}', [LmsGovernanceController::class, 'updateRole']);
Route::delete('/lms/governance/roles/{id}', [LmsGovernanceController::class, 'destroyRole']);

Route::get('/lms/governance/permissions', [LmsGovernanceController::class, 'permissions']);
Route::post('/lms/governance/permissions', [LmsGovernanceController::class, 'savePermissions']);

Route::get('/lms/governance/trainers', [LmsPartnerController::class, 'trainers']);
Route::post('/lms/governance/trainers', [LmsPartnerController::class, 'storeTrainer']);
Route::put('/lms/governance/trainers/{id}', [LmsPartnerController::class, 'updateTrainer']);
Route::delete('/lms/governance/trainers/{id}', [LmsPartnerController::class, 'destroyTrainer']);

Route::get('/lms/governance/vendors', [LmsPartnerController::class, 'vendors']);
Route::post('/lms/governance/vendors', [LmsPartnerController::class, 'storeVendor']);
Route::put('/lms/governance/vendors/{id}', [LmsPartnerController::class, 'updateVendor']);
Route::delete('/lms/governance/vendors/{id}', [LmsPartnerController::class, 'destroyVendor']);

Route::get('/lms/governance/integrations', [LmsPartnerController::class, 'integrations']);
Route::post('/lms/governance/integrations', [LmsPartnerController::class, 'storeIntegration']);
Route::put('/lms/governance/integrations/{id}', [LmsPartnerController::class, 'updateIntegration']);
Route::delete('/lms/governance/integrations/{id}', [LmsPartnerController::class, 'destroyIntegration']);

/*
| Course Builder assessments. An additive /api surface over question_paper -
| that table's own routes live in routes/lms.php as CSRF-protected web routes,
| which a cross-origin call cannot use. routes/lms.php is left untouched, so the
| old frontend's Assessment Library is unaffected.
| The static /questions segment stays ahead of the /{id} wildcard.
*/
Route::get('/lms/assessments/questions', [LmsAssessmentController::class, 'questions']);
Route::get('/lms/assessments', [LmsAssessmentController::class, 'index']);
Route::post('/lms/assessments', [LmsAssessmentController::class, 'store']);
Route::put('/lms/assessments/{id}', [LmsAssessmentController::class, 'update']);
Route::delete('/lms/assessments/{id}', [LmsAssessmentController::class, 'destroy']);

Route::get('/lms/courses/kpis', [LmsCourseController::class, 'kpis']);
Route::get('/lms/courses/filters', [LmsCourseController::class, 'filters']);
Route::post('/lms/courses/bulk', [LmsCourseController::class, 'bulk']);
Route::get('/lms/courses', [LmsCourseController::class, 'index']);
Route::post('/lms/courses', [LmsCourseController::class, 'store']);
Route::get('/lms/courses/{id}', [LmsCourseController::class, 'show']);
Route::put('/lms/courses/{id}', [LmsCourseController::class, 'update']);
Route::delete('/lms/courses/{id}', [LmsCourseController::class, 'destroy']);

/*
| Build with AI - outline generation (DeepSeek) and presentation rendering
| (Gamma). Both previously lived in the old frontend's Next.js API routes.
*/
/*
| My Learning - the course player. Progress and notes are new entities; the
| chapter/content writes exist as web routes but are CSRF-blocked cross-origin.
*/
Route::get('/lms/learning/courses', [LmsLearningController::class, 'courses']);
Route::get('/lms/learning/assessments', [LmsLearningController::class, 'assessments']);
Route::post('/lms/learning/progress', [LmsLearningController::class, 'saveProgress']);
Route::get('/lms/learning/notes', [LmsLearningController::class, 'notes']);
Route::post('/lms/learning/notes', [LmsLearningController::class, 'storeNote']);
Route::put('/lms/learning/notes/{id}', [LmsLearningController::class, 'updateNote']);
Route::delete('/lms/learning/notes/{id}', [LmsLearningController::class, 'destroyNote']);
Route::post('/lms/learning/chapters', [LmsLearningController::class, 'storeChapter']);
Route::put('/lms/learning/chapters/{id}', [LmsLearningController::class, 'updateChapter']);
Route::delete('/lms/learning/chapters/{id}', [LmsLearningController::class, 'destroyChapter']);
Route::post('/lms/learning/content', [LmsLearningController::class, 'storeContent']);
Route::put('/lms/learning/content/{id}', [LmsLearningController::class, 'updateContent']);
Route::delete('/lms/learning/content/{id}', [LmsLearningController::class, 'destroyContent']);
Route::get('/lms/learning/certificates', [LmsLearningController::class, 'certificates']);
Route::post('/lms/learning/certificates', [LmsLearningController::class, 'issueCertificate']);
// Public by design: checking whether a credential is genuine must not require
// the checker to hold an account. Returns only the fields printed on the
// certificate itself, never the wider learner record.
Route::get('/lms/learning/certificates/verify/{code}', [LmsLearningController::class, 'verifyCertificate']);
Route::get('/lms/learning/certificates/{id}/download', [LmsLearningController::class, 'downloadCertificate']);
Route::post('/lms/learning/certificates/{id}/reissue', [LmsLearningController::class, 'reissueCertificate']);
// Public: a credential nobody outside the org can check is worth nothing.
Route::get('/verify/certificate/{code}', [LmsLearningController::class, 'verifyCertificate']);
Route::get('/lms/learning/discussions', [LmsLearningController::class, 'discussions']);
Route::post('/lms/learning/discussions', [LmsLearningController::class, 'storeDiscussion']);
Route::post('/lms/learning/discussions/{id}/replies', [LmsLearningController::class, 'replyToDiscussion']);
Route::delete('/lms/learning/discussions/{id}', [LmsLearningController::class, 'destroyDiscussion']);
Route::get('/lms/learning/courses/{courseId}', [LmsLearningController::class, 'course']);

/*
| Sessions & Calendar. Sessions live in lms_virtual_classroom; attendees in
| lms_session_registrations. Static segments precede /{id}.
*/
// Both static segments stay ahead of /lms/sessions/{id} so they are not
// swallowed by the wildcard.
Route::get('/lms/sessions/stats', [LmsSessionController::class, 'stats']);
Route::get('/lms/sessions/deadlines', [LmsSessionController::class, 'deadlines']);
Route::get('/lms/sessions', [LmsSessionController::class, 'index']);
Route::post('/lms/sessions', [LmsSessionController::class, 'store']);
Route::get('/lms/sessions/{id}/attendees', [LmsSessionController::class, 'attendees']);
Route::post('/lms/sessions/{id}/register', [LmsSessionController::class, 'register']);
Route::delete('/lms/sessions/{id}/register', [LmsSessionController::class, 'cancelRegistration']);
Route::put('/lms/sessions/{id}', [LmsSessionController::class, 'update']);
Route::delete('/lms/sessions/{id}', [LmsSessionController::class, 'destroy']);

Route::get('/lms/ai/status', [AiCourseController::class, 'status']);
Route::post('/lms/ai/outline', [AiCourseController::class, 'generateOutline']);
Route::get('/lms/ai/outlines', [AiCourseController::class, 'outlines']);
Route::post('/lms/ai/outlines/{id}/publish', [AiCourseController::class, 'publish']);
Route::post('/lms/ai/presentation', [AiCourseController::class, 'generatePresentation']);
Route::get('/lms/ai/presentation/{generationId}', [AiCourseController::class, 'generationStatus']);
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

// Competency Library JSON API (additive; a competency == an approved skill on
// s_users_skills). Registered BEFORE the skill_library resource so these paths
// are not swallowed by the resource's /skill_library/{id} show route.
Route::get('skill_library/competency-list', [skillLibraryController::class, 'competencyLibraryIndex']);
Route::get('skill_library/competency-export', [skillLibraryController::class, 'competencyLibraryExport']);
Route::post('skill_library/competency-import', [skillLibraryController::class, 'competencyLibraryImport']);
Route::get('skill_library/competency/{id}/detail', [skillLibraryController::class, 'competencyLibraryDetail'])->whereNumber('id');
Route::post('skill_library/competency/{id}/clone', [skillLibraryController::class, 'competencyLibraryClone'])->whereNumber('id');
Route::put('skill_library/competency/{id}/archive', [skillLibraryController::class, 'competencyLibraryArchive'])->whereNumber('id');
Route::get('skill_library/competency/{id}', [skillLibraryController::class, 'competencyLibraryShow'])->whereNumber('id');
Route::post('skill_library/competency', [skillLibraryController::class, 'competencyLibraryStore']);
Route::put('skill_library/competency/{id}', [skillLibraryController::class, 'competencyLibraryUpdate'])->whereNumber('id');
Route::delete('skill_library/competency/{id}', [skillLibraryController::class, 'competencyLibraryDestroy'])->whereNumber('id');

// Removed duplicate route declaration - unnamed duplicate; the ->names('api.skill_library') declaration below is the one to keep, and this one collided with web.php's holiday/skill_library names.
/*
| Named api.skill_library, not skill_library.
|
| routes/web.php registers a resource on the same name, so both generated
| skill_library.index / .store / ... and `php artisan route:cache` aborted with
| "Another route has already been assigned name [skill_library.index]" - which
| breaks any deploy that caches routes.
|
| Renaming the API copy also fixes the blade views under
| resources/views/lms/library/skill_library/, whose {{ route('skill_library.store') }}
| form actions were resolving to whichever registration happened to win. URLs
| are unchanged; only the generated route name differs.
*/
Route::resource('skill_library', skillLibraryController::class)->names('api.skill_library');
Route::get('/positions', [InterviewController::class, 'getPositions']);
Route::get('/interviewers', [InterviewController::class, 'getInterviewers']);
Route::get('/get-employee-tasks', [AJAXController::class, 'getUsersMappings'])->middleware('api.token');

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

// Workforce reporting: headcount, growth, attrition, lifecycle, skill coverage.
// Every endpoint in here reads organisation-wide employee data, so all of them
// need a token. Without one the controllers already resolved a null tenant and
// returned an empty set - safe, but a 200 with `data: []` reads as "your
// organisation has no employees" rather than "you are not signed in", which
// hides authentication failures from callers.
Route::group(['prefix' => 'reports', 'middleware' => 'api.token'], function () {
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

Route::post('/gemini/analyze-jd', [AnalyzeJDController::class, 'analyze'])->middleware('api.token');
Route::post('/gemini/save-jd', [SaveJDController::class, 'save'])->middleware('api.token');
Route::post('/gemini/generate-questions', [GenerateQuestionsController::class, 'generate'])->middleware('api.token');

Route::get('/user-rejected-tasks', [SkillMatchingController::class, 'getUserRejectedTasks'])->middleware('api.token');
Route::get('/user-rejected-tasks-courses', [SkillMatchingController::class, 'getCoursesForUserRejectedTasksSkills'])->middleware('api.token');

Route::post('/employee/course-suggestions', [SuggestedCourseController::class, 'store'])->middleware('api.token');

// Task API Routes
Route::get('/tasks/counts', [TaskController::class, 'getTaskCounts']);
Route::get('/tasks/daily', [TaskController::class, 'getDailyTasks']);
Route::get('/tasks/weekly', [TaskController::class, 'getWeeklyTasks']);
Route::get('/tasks/monthly', [TaskController::class, 'getMonthlyTasks']);
Route::prefix('task-management')->middleware('task.sanitize')->group(function () {
    Route::get('/session', [SessionController::class, 'show']);
    // Module metadata for the Administration screens: the permission matrix
    // as enforced, and which integrations are configured (never their keys).
    Route::get('/permissions', [SessionController::class, 'permissions']);
    // Tenant status/priority vocabularies. System entries are constants; the
    // CRUD below manages the tenant's custom additions.
    Route::get('/statuses', [TaskOptionController::class, 'statuses']);
    Route::post('/statuses', [TaskOptionController::class, 'storeStatus'])->middleware('task.permission:notification.manage');
    Route::put('/statuses/{id}', [TaskOptionController::class, 'updateStatus'])->middleware('task.permission:notification.manage')->whereNumber('id');
    Route::delete('/statuses/{id}', [TaskOptionController::class, 'destroyStatus'])->middleware('task.permission:notification.manage')->whereNumber('id');
    Route::get('/priorities', [TaskOptionController::class, 'priorities']);
    Route::post('/priorities', [TaskOptionController::class, 'storePriority'])->middleware('task.permission:notification.manage');
    Route::put('/priorities/{id}', [TaskOptionController::class, 'updatePriority'])->middleware('task.permission:notification.manage')->whereNumber('id');
    Route::delete('/priorities/{id}', [TaskOptionController::class, 'destroyPriority'])->middleware('task.permission:notification.manage')->whereNumber('id');
    Route::get('/integrations', [SessionController::class, 'integrations']);
    Route::delete('/session', [SessionController::class, 'destroy'])->middleware('task.permission:notification.manage');
    Route::post('/bulk-tasks/import', [BulkTaskController::class, 'import'])->middleware('task.permission:task.create');
    Route::post('/assignment-capacity', [CapacityController::class, 'check'])->middleware('task.permission:task.create');
    Route::post('/legacy-tasks', [LegacyTaskController::class, 'store'])->middleware('task.permission:task.create');
    Route::post('/legacy-tasks/idempotent', [IdempotentTaskController::class, 'store'])->middleware('task.permission:task.create');
    Route::put('/legacy-tasks/{id}', [LegacyTaskController::class, 'update'])->middleware('task.permission:task.update')->whereNumber('id');
    Route::put('/legacy-tasks/{id}/versioned', [VersionedLegacyTaskController::class, 'update'])->middleware('task.permission:task.update')->whereNumber('id');
    Route::delete('/legacy-tasks/{id}', [LegacyTaskController::class, 'destroy'])->middleware('task.permission:task.delete')->whereNumber('id');
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead'])->middleware('task.permission:notification.manage');
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markRead'])->middleware('task.permission:notification.manage')->whereNumber('id');
    Route::get('/tasks', [TaskListController::class, 'index']);
    Route::get('/search', [GlobalSearchController::class, 'index']);
    Route::get('/templates', [TaskTemplateController::class, 'index']);
    Route::post('/templates', [TaskTemplateController::class, 'store'])->middleware('task.permission:task.create');
    Route::delete('/templates/{id}', [TaskTemplateController::class, 'destroy'])->middleware('task.permission:task.create')->whereNumber('id');
    Route::get('/reports/productivity', [ReportController::class, 'productivity']);
    Route::get('/reports/delays', [ReportController::class, 'delays']);
    // Admin-only: the audit trail is org-wide and the export is the whole
    // thing as a file. Gated with the same privileged ability the other
    // Administration routes use.
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('task.permission:notification.manage');
    Route::get('/audit-logs/export', [AuditLogController::class, 'export'])->middleware('task.permission:notification.manage');
    Route::get('/workspace', [WorkspaceController::class, 'index']);
    Route::get('/workspace/workload', [WorkspaceController::class, 'workload']);
    Route::get('/workspace/{id}', [WorkspaceController::class, 'show'])->whereNumber('id');
    Route::get('/workspace/{id}/activity', [ActivityController::class, 'index'])->whereNumber('id');
    Route::put('/workspace/{id}', [WorkspaceController::class, 'update'])->middleware('task.permission:task.update')->whereNumber('id');
    Route::delete('/workspace/{id}', [WorkspaceController::class, 'destroy'])->middleware('task.permission:task.delete')->whereNumber('id');
    Route::patch('/workspace/{id}/approval', [WorkspaceController::class, 'approve'])->middleware('task.permission:task.approve')->whereNumber('id');
    Route::post('/workspace/{id}/comments', [WorkspaceController::class, 'comment'])->middleware('task.permission:task.comment')->whereNumber('id');
    Route::get('/workspace/{id}/time-entries', [TaskTimeTrackingController::class, 'index'])->whereNumber('id');
    Route::post('/workspace/{id}/time-entries/start', [TaskTimeTrackingController::class, 'start'])->middleware('task.permission:task.status')->whereNumber('id');
    Route::post('/workspace/{id}/time-entries/stop', [TaskTimeTrackingController::class, 'stop'])->middleware('task.permission:task.status')->whereNumber('id');
    Route::get('/workspace/{id}/subtasks', [TaskSubtaskController::class, 'index'])->whereNumber('id');
    Route::post('/workspace/{id}/subtasks', [TaskSubtaskController::class, 'store'])->middleware('task.permission:task.update')->whereNumber('id');
    Route::patch('/workspace/{id}/subtasks/{subtask}', [TaskSubtaskController::class, 'toggle'])->middleware('task.permission:task.status')->whereNumber(['id','subtask']);
    Route::delete('/workspace/{id}/subtasks/{subtask}', [TaskSubtaskController::class, 'destroy'])->middleware('task.permission:task.update')->whereNumber(['id','subtask']);
    Route::get('/workspace/{id}/recurrence', [TaskRecurrenceController::class, 'show'])->whereNumber('id');
    Route::put('/workspace/{id}/recurrence', [TaskRecurrenceController::class, 'upsert'])->middleware('task.permission:task.update')->whereNumber('id');
    Route::delete('/workspace/{id}/recurrence', [TaskRecurrenceController::class, 'destroy'])->middleware('task.permission:task.update')->whereNumber('id');
    Route::get('/workspace/{id}/schedule', [TaskScheduleController::class, 'show'])->whereNumber('id');
    Route::put('/workspace/{id}/schedule', [TaskScheduleController::class, 'update'])->middleware('task.permission:task.update')->whereNumber('id');
    Route::get('/workspace/{id}/attachments', [TaskAttachmentVersionController::class, 'index'])->whereNumber('id');
    Route::post('/workspace/{id}/attachments', [TaskAttachmentVersionController::class, 'store'])->middleware('task.permission:task.update')->whereNumber('id');
    Route::get('/workspace/{id}/attachments/{version}', [TaskAttachmentVersionController::class, 'download'])->whereNumber(['id', 'version']);
    Route::post('/workspace/{id}/attachments/{version}/restore', [TaskAttachmentVersionController::class, 'restore'])->middleware('task.permission:task.update')->whereNumber(['id', 'version']);
    // Deadline extensions: the executor requests more time, the observer
    // decides. The old frontend had this whole flow pointed at an endpoint
    // that never existed; these are its backend.
    Route::get('/deadline-extensions', [DeadlineExtensionController::class, 'index']);
    Route::post('/deadline-extensions', [DeadlineExtensionController::class, 'store']);
    Route::patch('/deadline-extensions/{id}/decision', [DeadlineExtensionController::class, 'decide'])->middleware('task.permission:task.approve')->whereNumber('id');

    Route::get('/dependencies', [DependencyController::class, 'index']);
    Route::post('/dependencies', [DependencyController::class, 'store'])->middleware('task.permission:dependency.manage');
    Route::put('/dependencies/{id}', [DependencyController::class, 'update'])->middleware('task.permission:dependency.manage')->whereNumber('id');
    Route::delete('/dependencies/{id}', [DependencyController::class, 'destroy'])->middleware('task.permission:dependency.manage')->whereNumber('id');
    Route::post('/milestones', [DependencyController::class, 'storeMilestone'])->middleware('task.permission:milestone.manage');
    Route::put('/milestones/{id}', [DependencyController::class, 'updateMilestone'])->middleware('task.permission:milestone.manage')->whereNumber('id');
    Route::delete('/milestones/{id}', [DependencyController::class, 'destroyMilestone'])->middleware('task.permission:milestone.manage')->whereNumber('id');
    Route::get('/my-tasks', [MyTasksController::class, 'index']);
    Route::get('/my-tasks/{id}', [MyTasksController::class, 'show'])->whereNumber('id');
    Route::patch('/my-tasks/{id}/status', [MyTasksController::class, 'updateStatus'])->middleware('task.permission:task.status')->whereNumber('id');
    Route::get('/projects/options', [ProjectController::class, 'options']);
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store'])->middleware('task.permission:project.create');
    Route::get('/projects/{id}', [ProjectController::class, 'show'])->whereNumber('id');
    Route::put('/projects/{id}', [ProjectController::class, 'update'])->middleware('task.permission:project.manage')->whereNumber('id');
    Route::patch('/projects/{id}/archive', [ProjectController::class, 'archive'])->middleware('task.permission:project.manage')->whereNumber('id');
    Route::put('/projects/{id}/members', [ProjectController::class, 'syncProjectMembers'])->middleware('task.permission:project.manage')->whereNumber('id');
    Route::put('/projects/{id}/tasks', [ProjectController::class, 'syncTasks'])->middleware('task.permission:project.manage')->whereNumber('id');
    // Attach a single task without disturbing the project's other tasks.
    Route::post('/projects/{id}/tasks', [ProjectController::class, 'attachTask'])->middleware('task.permission:project.manage')->whereNumber('id');
    Route::post('/projects/{id}/workstreams', [ProjectController::class, 'storeWorkstream'])->middleware('task.permission:workstream.manage')->whereNumber('id');
    Route::put('/projects/{projectId}/workstreams/{workstreamId}', [ProjectController::class, 'updateWorkstream'])->middleware('task.permission:workstream.manage')->whereNumber('projectId')->whereNumber('workstreamId');
    Route::delete('/projects/{projectId}/workstreams/{workstreamId}', [ProjectController::class, 'destroyWorkstream'])->middleware('task.permission:workstream.manage')->whereNumber('projectId')->whereNumber('workstreamId');
});
Route::get('/user-skills/{user_id}', [UserSkillController::class, 'getUserSkills']);

// User Journey Log API Routes
Route::post('/user-journey-logs', [UserJourneyLogController::class, 'store']);
Route::post('/user-journey-logs/bulk', [UserJourneyLogController::class, 'storeBulk']);

// School Setup API Routes
Route::post('/school-setup', [SchoolSetupController::class, 'store']);


Route::post('/user-signup', [UserSignupController::class, 'store']);
Route::get('/user-signup/{id}', [UserSignupController::class, 'show'])->middleware('api.token');
Route::put('/user-signup/{id}', [UserSignupController::class, 'update'])->middleware('api.token');
Route::delete('/user-signup/{id}', [UserSignupController::class, 'destroy'])->middleware('api.token');

Route::post('/update-fcm-token', [tbluserController::class, 'updateFcmToken'])->middleware('api.token');

// Skill Heatmap API Routes
Route::prefix('skill-heatmap')->middleware('api.token')->group(function () {
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
// Blank workbook using this organisation's own template headers, so the file
// a user downloads is always the file upload() will accept.
Route::get('/excel-agent/template', [ExcelAutomationAgentController::class, 'downloadTemplate']);
// Course Recommendation API - Get courses based on logged-in user's job role

// Department Job Role Export API - Export department and job role data to CSV
// The tenant is a path segment here and the controller has no token check of
// its own, so this exported any organisation's departments and job roles to
// anyone who could guess an id.
Route::get('/export-department-jobroles/{subInstituteId}', [DepartmentJobRoleExportController::class, 'exportToCsv'])->middleware('api.token');

// Template API Routes
Route::resource('templates', TemplateController::class)->middleware('api.token');
Route::get('templates/{id}/versions', [TemplateController::class, 'versions'])->middleware('api.token');
Route::post('templates/{id}/restore/{version}', [TemplateController::class, 'restore'])->middleware('api.token');

Route::get('/career-journey', [CareerJourneyController::class, 'getCareerJourney'])->middleware('api.token');

// Bulk Task Import API
Route::post('bulk-task/import', [BulkTaskController::class, 'import'])->middleware('api.token');
// Path the legacy frontend posts deadline-extension requests to.
Route::post('/deadline-extension', [App\Http\Controllers\Api\TaskManagement\DeadlineExtensionController::class, 'store']);

// Nango Google Calendar OAuth API
Route::post('nango/google/check-connection', [App\Http\Controllers\NangoController::class, 'checkConnection']);
Route::post('nango/google/oauth-url', [App\Http\Controllers\NangoController::class, 'getOauthUrl']);

// Task Google Calendar Re-sync API
Route::post('task/resync-google-calendar', [App\Http\Controllers\front_desk\taskController::class, 'resyncTaskToGoogleCalendar']);

Route::post('/auth/google', [GoogleAuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Talent Management -> Performance & Rewards Center
|--------------------------------------------------------------------------
| Token authenticated (Sanctum token as the `token` query param) and tenant
| scoped by sub_institute_id, exactly like /api/competency/* and /api/leave/*.
|
| Entirely NEW surface: this module had no routes, controllers, models or tables
| before. Nothing below touches an existing endpoint, so no current consumer is
| affected. Backed by the 11 s_performance_* tables created in
| 2026_07_30_100000_create_performance_module_tables, plus READ-ONLY reuse of
| tbluser, hrms_departments, org_designation, s_competency_assessments (job-role
| derivation) and employee_salary_structures (current CTC).
|
| NOTE: `user_id` on every route here is the CONTEXT ACTOR. The subject employee
| travels as `user_id_target` on writes and `user_id_filter` on reads.
*/

// Header: KPI cards, shared filter options, team comparison, cycle timeline.
Route::get('/performance/overview', [PerformanceOverviewController::class, 'index']);
Route::get('/performance/filters', [PerformanceOverviewController::class, 'filters']);
Route::get('/performance/team-comparison', [PerformanceOverviewController::class, 'teamComparison']);
Route::get('/performance/timeline', [PerformanceOverviewController::class, 'timeline']);

// Review cycles - the cycle selector and the Create / Launch button.
Route::get('/performance/cycles', [PerformanceCycleController::class, 'index']);
Route::post('/performance/cycles', [PerformanceCycleController::class, 'store'])->middleware('profile:admin,hr');
Route::get('/performance/cycles/{id}', [PerformanceCycleController::class, 'show'])->whereNumber('id');
Route::put('/performance/cycles/{id}', [PerformanceCycleController::class, 'update'])->whereNumber('id')->middleware('profile:admin,hr');
Route::post('/performance/cycles/{id}/launch', [PerformanceCycleController::class, 'launch'])->whereNumber('id')->middleware('profile:admin,hr');
Route::post('/performance/cycles/{id}/close', [PerformanceCycleController::class, 'close'])->whereNumber('id')->middleware('profile:admin,hr');
Route::delete('/performance/cycles/{id}', [PerformanceCycleController::class, 'destroy'])->whereNumber('id')->middleware('profile:admin,hr');

// Employee reviews - the main table, the Review Board and the sidebar.
// Static segments are registered BEFORE /{id} so the wildcard cannot swallow them.
Route::get('/performance/reviews/board', [PerformanceReviewController::class, 'board']);
Route::post('/performance/reviews/bulk', [PerformanceReviewController::class, 'bulk'])->middleware('profile:admin,hr');
Route::get('/performance/reviews', [PerformanceReviewController::class, 'index']);
Route::get('/performance/reviews/{id}', [PerformanceReviewController::class, 'show'])->whereNumber('id');
Route::put('/performance/reviews/{id}', [PerformanceReviewController::class, 'update'])->whereNumber('id');
Route::post('/performance/reviews/{id}/advance', [PerformanceReviewController::class, 'advance'])->whereNumber('id')->middleware('profile:admin,hr,manager');
Route::post('/performance/reviews/{id}/reminder', [PerformanceReviewController::class, 'sendReminder'])->whereNumber('id');
Route::delete('/performance/reviews/{id}', [PerformanceReviewController::class, 'destroy'])->whereNumber('id')->middleware('profile:admin,hr');

// Comments / Notes and Attachments, both scoped to a review.
Route::get('/performance/reviews/{reviewId}/notes', [PerformanceActivityController::class, 'notes'])->whereNumber('reviewId');
Route::post('/performance/reviews/{reviewId}/notes', [PerformanceActivityController::class, 'storeNote'])->whereNumber('reviewId');
Route::put('/performance/notes/{id}', [PerformanceActivityController::class, 'updateNote'])->whereNumber('id');
Route::delete('/performance/notes/{id}', [PerformanceActivityController::class, 'destroyNote'])->whereNumber('id');

Route::get('/performance/reviews/{reviewId}/attachments', [PerformanceActivityController::class, 'attachments'])->whereNumber('reviewId');
Route::post('/performance/reviews/{reviewId}/attachments', [PerformanceActivityController::class, 'storeAttachment'])->whereNumber('reviewId');
Route::delete('/performance/attachments/{id}', [PerformanceActivityController::class, 'destroyAttachment'])->whereNumber('id');

// Goals tab (KRA / KPI / OKR).
Route::get('/performance/goals', [PerformanceGoalController::class, 'index']);
Route::post('/performance/goals', [PerformanceGoalController::class, 'store']);
Route::put('/performance/goals/{id}', [PerformanceGoalController::class, 'update'])->whereNumber('id');
Route::delete('/performance/goals/{id}', [PerformanceGoalController::class, 'destroy'])->whereNumber('id');

// Appraisals tab.
Route::post('/performance/appraisals/bulk', [PerformanceAppraisalController::class, 'bulk']);
Route::get('/performance/appraisals', [PerformanceAppraisalController::class, 'index']);
Route::post('/performance/appraisals', [PerformanceAppraisalController::class, 'store']);
Route::put('/performance/appraisals/{id}', [PerformanceAppraisalController::class, 'update'])->whereNumber('id');
Route::put('/performance/appraisals/{id}/decision', [PerformanceAppraisalController::class, 'decision'])->whereNumber('id');
Route::delete('/performance/appraisals/{id}', [PerformanceAppraisalController::class, 'destroy'])->whereNumber('id');

// Compensation tab.
Route::post('/performance/compensation/bulk', [PerformanceCompensationController::class, 'bulk']);
Route::get('/performance/compensation', [PerformanceCompensationController::class, 'index']);
Route::post('/performance/compensation', [PerformanceCompensationController::class, 'store']);
Route::put('/performance/compensation/{id}', [PerformanceCompensationController::class, 'update'])->whereNumber('id');
Route::put('/performance/compensation/{id}/decision', [PerformanceCompensationController::class, 'decision'])->whereNumber('id');
Route::delete('/performance/compensation/{id}', [PerformanceCompensationController::class, 'destroy'])->whereNumber('id');

// Bonus tab.
Route::post('/performance/bonus/bulk', [PerformanceBonusController::class, 'bulk']);
Route::get('/performance/bonus', [PerformanceBonusController::class, 'index']);
Route::post('/performance/bonus', [PerformanceBonusController::class, 'store']);
Route::put('/performance/bonus/{id}', [PerformanceBonusController::class, 'update'])->whereNumber('id');
Route::put('/performance/bonus/{id}/decision', [PerformanceBonusController::class, 'decision'])->whereNumber('id');
Route::delete('/performance/bonus/{id}', [PerformanceBonusController::class, 'destroy'])->whereNumber('id');

// Calibration tab.
Route::get('/performance/calibration-sessions', [PerformanceCalibrationController::class, 'index']);
Route::post('/performance/calibration-sessions', [PerformanceCalibrationController::class, 'store']);
Route::get('/performance/calibration-sessions/{id}/grid', [PerformanceCalibrationController::class, 'grid'])->whereNumber('id');
Route::put('/performance/calibration-sessions/{id}/calibrate', [PerformanceCalibrationController::class, 'calibrate'])->whereNumber('id');
Route::post('/performance/calibration-sessions/{id}/lock', [PerformanceCalibrationController::class, 'lock'])->whereNumber('id');
Route::put('/performance/calibration-sessions/{id}', [PerformanceCalibrationController::class, 'update'])->whereNumber('id');
Route::delete('/performance/calibration-sessions/{id}', [PerformanceCalibrationController::class, 'destroy'])->whereNumber('id');

// Activity Feed / Audit Trail.
Route::get('/performance/activity/filters', [PerformanceActivityController::class, 'filters']);
Route::get('/performance/activity', [PerformanceActivityController::class, 'index']);

// Saved Views (named filter presets per tab).
Route::get('/performance/saved-views', [PerformanceSavedViewController::class, 'index']);
Route::post('/performance/saved-views', [PerformanceSavedViewController::class, 'store']);
Route::put('/performance/saved-views/{id}', [PerformanceSavedViewController::class, 'update'])->whereNumber('id');
Route::delete('/performance/saved-views/{id}', [PerformanceSavedViewController::class, 'destroy'])->whereNumber('id');

/*
|--------------------------------------------------------------------------
| Talent Management -> Lifecycle (Dashboard, Onboarding, Mobility, Offboarding)
|--------------------------------------------------------------------------
| Token authenticated (Sanctum token as the `token` query param) and tenant
| scoped by sub_institute_id, exactly like /api/performance/*, /api/competency/*
| and /api/leave/*.
|
| Entirely NEW surface. None of these modules had routes, controllers, models or
| tables before: grepping the backend for onboarding-journey, mobility,
| succession, resignation or clearance returned only menu labels. Nothing below
| touches an existing endpoint, so no current consumer is affected - in
| particular /api/talent-acquisition/{kpis,funnel,dropoff,requisitions},
| /api/job-postings, /api/job-applications, /api/interview-schedules and
| /api/talent-offers are all left exactly as they were.
|
| Backed by the 9 tables created in:
|   2026_07_30_120000_create_talent_onboarding_tables
|   2026_07_30_130000_create_talent_mobility_tables
|   2026_07_30_140000_create_talent_offboarding_tables
| plus READ-ONLY reuse of talent_job_postings, talent_job_applications,
| talent_offers, talent_interview_schedules, talent_evaluation_form,
| s_performance_*, tbluser, hrms_departments and org_designation.
|
| NOTE: `user_id` on every route here is the CONTEXT ACTOR. The subject employee
| always travels as an explicit field (employee_id, owner_id, incumbent_id).
*/

// Executive dashboard - one aggregate across all five talent modules.
Route::get('/talent/dashboard', [TalentDashboardController::class, 'index']);
Route::get('/talent/dashboard/filters', [TalentDashboardController::class, 'filters']);

// Onboarding: journeys, their checklist tasks and their documents.
// Static segments are registered BEFORE /{id} so the wildcard cannot swallow them.
Route::get('/talent/onboarding/journeys', [OnboardingJourneyController::class, 'index']);
Route::post('/talent/onboarding/journeys', [OnboardingJourneyController::class, 'store']);
Route::get('/talent/onboarding/journeys/{id}', [OnboardingJourneyController::class, 'show'])->whereNumber('id');
Route::put('/talent/onboarding/journeys/{id}', [OnboardingJourneyController::class, 'update'])->whereNumber('id');
Route::post('/talent/onboarding/journeys/{id}/complete', [OnboardingJourneyController::class, 'complete'])->whereNumber('id');
Route::delete('/talent/onboarding/journeys/{id}', [OnboardingJourneyController::class, 'destroy'])->whereNumber('id');

Route::get('/talent/onboarding/tasks', [OnboardingTaskController::class, 'index']);
Route::post('/talent/onboarding/tasks', [OnboardingTaskController::class, 'store']);
Route::put('/talent/onboarding/tasks/{id}', [OnboardingTaskController::class, 'update'])->whereNumber('id');
Route::post('/talent/onboarding/tasks/{id}/complete', [OnboardingTaskController::class, 'complete'])->whereNumber('id');
Route::delete('/talent/onboarding/tasks/{id}', [OnboardingTaskController::class, 'destroy'])->whereNumber('id');

Route::get('/talent/onboarding/documents', [OnboardingDocumentController::class, 'index']);
Route::post('/talent/onboarding/documents', [OnboardingDocumentController::class, 'store']);
Route::put('/talent/onboarding/documents/{id}', [OnboardingDocumentController::class, 'update'])->whereNumber('id');
Route::delete('/talent/onboarding/documents/{id}', [OnboardingDocumentController::class, 'destroy'])->whereNumber('id');

// Mobility: internal-only job postings and the requests raised against them.
Route::get('/talent/mobility/internal-jobs', [InternalJobController::class, 'index']);
Route::post('/talent/mobility/internal-jobs', [InternalJobController::class, 'store'])->middleware('profile:admin,hr');
Route::get('/talent/mobility/internal-jobs/{id}', [InternalJobController::class, 'show'])->whereNumber('id');
Route::put('/talent/mobility/internal-jobs/{id}', [InternalJobController::class, 'update'])->whereNumber('id')->middleware('profile:admin,hr');
Route::delete('/talent/mobility/internal-jobs/{id}', [InternalJobController::class, 'destroy'])->whereNumber('id')->middleware('profile:admin,hr');

Route::get('/talent/mobility/requests', [MobilityRequestController::class, 'index']);
Route::post('/talent/mobility/requests', [MobilityRequestController::class, 'store']);
Route::get('/talent/mobility/requests/{id}', [MobilityRequestController::class, 'show'])->whereNumber('id');
Route::put('/talent/mobility/requests/{id}', [MobilityRequestController::class, 'update'])->whereNumber('id');
Route::put('/talent/mobility/requests/{id}/decision', [MobilityRequestController::class, 'decision'])->whereNumber('id')->middleware('profile:admin,hr');
Route::delete('/talent/mobility/requests/{id}', [MobilityRequestController::class, 'destroy'])->whereNumber('id');

// Succession: critical roles and the bench behind them (the 9-box matrix).
Route::get('/talent/succession/plans', [SuccessionPlanController::class, 'index']);
Route::post('/talent/succession/plans', [SuccessionPlanController::class, 'store'])->middleware('profile:admin,hr');
Route::get('/talent/succession/plans/{id}', [SuccessionPlanController::class, 'show'])->whereNumber('id');
Route::put('/talent/succession/plans/{id}', [SuccessionPlanController::class, 'update'])->whereNumber('id')->middleware('profile:admin,hr');
Route::delete('/talent/succession/plans/{id}', [SuccessionPlanController::class, 'destroy'])->whereNumber('id')->middleware('profile:admin,hr');
Route::post('/talent/succession/plans/{id}/candidates', [SuccessionPlanController::class, 'storeCandidate'])->whereNumber('id')->middleware('profile:admin,hr');
Route::put('/talent/succession/candidates/{id}', [SuccessionPlanController::class, 'updateCandidate'])->whereNumber('id')->middleware('profile:admin,hr');
Route::delete('/talent/succession/candidates/{id}', [SuccessionPlanController::class, 'destroyCandidate'])->whereNumber('id')->middleware('profile:admin,hr');

// Offboarding: exit cases, their clearance checklist and the exit interview.
Route::get('/talent/offboarding/cases', [OffboardingCaseController::class, 'index']);
Route::post('/talent/offboarding/cases', [OffboardingCaseController::class, 'store']);
Route::get('/talent/offboarding/cases/{id}', [OffboardingCaseController::class, 'show'])->whereNumber('id');
Route::put('/talent/offboarding/cases/{id}', [OffboardingCaseController::class, 'update'])->whereNumber('id');
Route::post('/talent/offboarding/cases/{id}/advance', [OffboardingCaseController::class, 'advance'])->whereNumber('id');
Route::delete('/talent/offboarding/cases/{id}', [OffboardingCaseController::class, 'destroy'])->whereNumber('id');

Route::get('/talent/offboarding/clearances', [OffboardingClearanceController::class, 'index']);
Route::post('/talent/offboarding/clearances', [OffboardingClearanceController::class, 'store']);
Route::put('/talent/offboarding/clearances/{id}', [OffboardingClearanceController::class, 'update'])->whereNumber('id');
Route::post('/talent/offboarding/clearances/{id}/clear', [OffboardingClearanceController::class, 'clear'])->whereNumber('id');
Route::delete('/talent/offboarding/clearances/{id}', [OffboardingClearanceController::class, 'destroy'])->whereNumber('id');

Route::get('/talent/offboarding/exit-interviews', [ExitInterviewController::class, 'index']);
Route::post('/talent/offboarding/exit-interviews', [ExitInterviewController::class, 'store']);
Route::put('/talent/offboarding/exit-interviews/{id}', [ExitInterviewController::class, 'update'])->whereNumber('id');
Route::delete('/talent/offboarding/exit-interviews/{id}', [ExitInterviewController::class, 'destroy'])->whereNumber('id');

// Administration & Governance: Workflows
Route::get('/talent/admin/workflows', [AdminWorkflowController::class, 'index']);
/*
|--------------------------------------------------------------------------
| Talent Management -> Onboarding & Employee Lifecycle Center
|--------------------------------------------------------------------------
| Token authenticated (Sanctum token as the `token` query param) and tenant
| scoped by sub_institute_id, exactly like /api/performance/* and
| /api/competency/*.
|
| Entirely NEW surface: this module had no routes, controllers or models before.
| Nothing below touches an existing endpoint, so no current consumer is affected.
| Backed by 2026_07_31_100000_create_onboarding_module_tables, which ADOPTS the
| two orphan tables talent_onboarding_journeys / talent_onboarding_tasks (present
| in the database with 0 rows, no migration and zero code references) and adds
| talent_onboarding_journey_stages / _documents / _notes / _activity_log.
| Read-only reuse of tbluser, hrms_departments, org_designation, document_type,
| talent_offers and talent_job_applications; the ONLY write outside this module's
| own tables is tbluser.probation_period_from/to, set on an explicit probation
| decision by OnboardingProbationController.
|
| NOTE: `user_id` on every route here is the CONTEXT ACTOR, never the subject.
| The subject employee is `employee_id` on a journey and `owner_id` on a task.
*/

// Header: the 5 KPI cards and every dropdown on the screen.
Route::get('/onboarding/overview', [OnboardingOverviewController::class, 'index']);
Route::get('/onboarding/filters', [OnboardingOverviewController::class, 'filters']);

// Journeys - the journey list sheet, the profile sidebar and "Start onboarding".
Route::get('/onboarding/journeys', [V2OnboardingJourneyController::class, 'index']);
Route::post('/onboarding/journeys', [V2OnboardingJourneyController::class, 'store']);
Route::post('/onboarding/journeys/from-offer/{offerId}', [V2OnboardingJourneyController::class, 'storeFromOffer'])->whereNumber('offerId');
Route::get('/onboarding/journeys/{id}', [V2OnboardingJourneyController::class, 'show'])->whereNumber('id');
Route::put('/onboarding/journeys/{id}', [V2OnboardingJourneyController::class, 'update'])->whereNumber('id');
Route::delete('/onboarding/journeys/{id}', [V2OnboardingJourneyController::class, 'destroy'])->whereNumber('id');

// Journey stages - the "Onboarding Journey Progress" timeline.
Route::get('/onboarding/journeys/{journeyId}/stages', [V2OnboardingJourneyController::class, 'stages'])->whereNumber('journeyId');
Route::put('/onboarding/stages/{id}', [V2OnboardingJourneyController::class, 'updateStage'])->whereNumber('id');
Route::post('/onboarding/stages/{id}/complete', [V2OnboardingJourneyController::class, 'completeStage'])->whereNumber('id');

// Key Contacts card and the Lifecycle Timeline tab.
Route::get('/onboarding/journeys/{journeyId}/contacts', [V2OnboardingJourneyController::class, 'contacts'])->whereNumber('journeyId');
Route::get('/onboarding/journeys/{journeyId}/timeline', [V2OnboardingJourneyController::class, 'timeline'])->whereNumber('journeyId');

// Preboarding tasks - the main table, its row actions and the Add Task sheet.
// Static segments are registered BEFORE /{id} so the wildcard cannot swallow them.
Route::get('/onboarding/workstreams', [V2OnboardingTaskController::class, 'workstreams']);
Route::post('/onboarding/tasks/bulk', [V2OnboardingTaskController::class, 'bulk']);
Route::get('/onboarding/tasks', [V2OnboardingTaskController::class, 'index']);
Route::post('/onboarding/tasks', [V2OnboardingTaskController::class, 'store']);
Route::put('/onboarding/tasks/{id}', [V2OnboardingTaskController::class, 'update'])->whereNumber('id');
Route::post('/onboarding/tasks/{id}/complete', [V2OnboardingTaskController::class, 'complete'])->whereNumber('id');
Route::delete('/onboarding/tasks/{id}', [V2OnboardingTaskController::class, 'destroy'])->whereNumber('id');

// Documents card. POST accepts multipart; PUT doubles as the upload endpoint for
// an existing request (browsers cannot send multipart PUT, so the frontend posts
// with _method=PUT, which Laravel's method spoofing resolves).
Route::get('/onboarding/journeys/{journeyId}/documents', [V2OnboardingDocumentController::class, 'index'])->whereNumber('journeyId');
Route::post('/onboarding/journeys/{journeyId}/documents', [V2OnboardingDocumentController::class, 'store'])->whereNumber('journeyId');
Route::match(['put', 'post'], '/onboarding/documents/{id}', [V2OnboardingDocumentController::class, 'update'])->whereNumber('id');
Route::delete('/onboarding/documents/{id}', [V2OnboardingDocumentController::class, 'destroy'])->whereNumber('id');

// Notes card.
Route::get('/onboarding/journeys/{journeyId}/notes', [OnboardingNoteController::class, 'index'])->whereNumber('journeyId');
Route::post('/onboarding/journeys/{journeyId}/notes', [OnboardingNoteController::class, 'store'])->whereNumber('journeyId');
Route::put('/onboarding/notes/{id}', [OnboardingNoteController::class, 'update'])->whereNumber('id');
Route::delete('/onboarding/notes/{id}', [OnboardingNoteController::class, 'destroy'])->whereNumber('id');

// Probation & Confirmation tab.
Route::get('/onboarding/probation', [OnboardingProbationController::class, 'index']);
Route::put('/onboarding/probation/{journeyId}', [OnboardingProbationController::class, 'update'])->whereNumber('journeyId');
Route::post('/onboarding/probation/{journeyId}/confirm', [OnboardingProbationController::class, 'confirm'])->whereNumber('journeyId');
Route::post('/onboarding/probation/{journeyId}/extend', [OnboardingProbationController::class, 'extend'])->whereNumber('journeyId');
Route::post('/onboarding/probation/{journeyId}/terminate', [OnboardingProbationController::class, 'terminate'])->whereNumber('journeyId');

/*
|--------------------------------------------------------------------------
| Talent Management -> Internal Mobility & Succession Center
|--------------------------------------------------------------------------
| Sanctum token query param authenticated and tenant scoped by sub_institute_id.
*/
Route::prefix('mobility')->group(function () {
    Route::get('/overview', [App\Http\Controllers\Api\Mobility\MobilityOverviewController::class, 'index']);
    Route::get('/filters', [App\Http\Controllers\Api\Mobility\MobilityOverviewController::class, 'filters']);


    Route::get('/jobs', [App\Http\Controllers\Api\Mobility\MobilityJobController::class, 'index']);
    Route::post('/jobs', [App\Http\Controllers\Api\Mobility\MobilityJobController::class, 'store']);
    Route::get('/jobs/{id}', [App\Http\Controllers\Api\Mobility\MobilityJobController::class, 'show'])->whereNumber('id');
    Route::put('/jobs/{id}', [App\Http\Controllers\Api\Mobility\MobilityJobController::class, 'update'])->whereNumber('id');
    Route::delete('/jobs/{id}', [App\Http\Controllers\Api\Mobility\MobilityJobController::class, 'destroy'])->whereNumber('id');

    Route::get('/applications', [App\Http\Controllers\Api\Mobility\MobilityApplicationController::class, 'index']);
    Route::post('/applications', [App\Http\Controllers\Api\Mobility\MobilityApplicationController::class, 'store']);
    Route::put('/applications/{id}', [App\Http\Controllers\Api\Mobility\MobilityApplicationController::class, 'update'])->whereNumber('id');

    Route::get('/transfers', [App\Http\Controllers\Api\Mobility\MobilityTransferController::class, 'index']);
    Route::post('/transfers', [App\Http\Controllers\Api\Mobility\MobilityTransferController::class, 'store']);
    Route::put('/transfers/{id}', [App\Http\Controllers\Api\Mobility\MobilityTransferController::class, 'update'])->whereNumber('id');

    Route::get('/promotions', [App\Http\Controllers\Api\Mobility\MobilityPromotionController::class, 'index']);
    Route::post('/promotions', [App\Http\Controllers\Api\Mobility\MobilityPromotionController::class, 'store']);
    Route::put('/promotions/{id}', [App\Http\Controllers\Api\Mobility\MobilityPromotionController::class, 'update'])->whereNumber('id');

    Route::get('/successions', [App\Http\Controllers\Api\Mobility\MobilitySuccessionController::class, 'index']);
    Route::post('/successions', [App\Http\Controllers\Api\Mobility\MobilitySuccessionController::class, 'store']);
    Route::put('/successions/{id}', [App\Http\Controllers\Api\Mobility\MobilitySuccessionController::class, 'update'])->whereNumber('id');
    Route::delete('/successions/{id}', [App\Http\Controllers\Api\Mobility\MobilitySuccessionController::class, 'destroy'])->whereNumber('id');

    Route::get('/pools', [App\Http\Controllers\Api\Mobility\MobilityTalentPoolController::class, 'index']);
    Route::post('/pools', [App\Http\Controllers\Api\Mobility\MobilityTalentPoolController::class, 'store']);
    Route::get('/pools/{id}/members', [App\Http\Controllers\Api\Mobility\MobilityTalentPoolController::class, 'members'])->whereNumber('id');
    Route::post('/pools/{id}/members', [App\Http\Controllers\Api\Mobility\MobilityTalentPoolController::class, 'addMember'])->whereNumber('id');
    Route::delete('/pools/{id}/members/{userId}', [App\Http\Controllers\Api\Mobility\MobilityTalentPoolController::class, 'removeMember'])->whereNumber('id')->whereNumber('userId');
});


/*
|--------------------------------------------------------------------------
| Talent Management -> Offboarding Center
|--------------------------------------------------------------------------
*/
Route::prefix('offboarding')->group(function () {
    Route::get('/overview', [App\Http\Controllers\Api\Offboarding\OffboardingController::class, 'overview']);
    Route::get('/filters', [App\Http\Controllers\Api\Offboarding\OffboardingController::class, 'filters']);
    Route::get('/cases', [App\Http\Controllers\Api\Offboarding\OffboardingController::class, 'index']);
    Route::post('/cases', [App\Http\Controllers\Api\Offboarding\OffboardingController::class, 'store']);
    Route::get('/cases/{id}', [App\Http\Controllers\Api\Offboarding\OffboardingController::class, 'show'])->whereNumber('id');
    Route::put('/cases/{id}', [App\Http\Controllers\Api\Offboarding\OffboardingController::class, 'update'])->whereNumber('id');
    Route::post('/cases/{id}/status', [App\Http\Controllers\Api\Offboarding\OffboardingController::class, 'updateStatus'])->whereNumber('id');
    Route::post('/cases/{id}/clearance', [App\Http\Controllers\Api\Offboarding\OffboardingController::class, 'updateClearance'])->whereNumber('id');
    Route::post('/cases/{id}/documents', [App\Http\Controllers\Api\Offboarding\OffboardingController::class, 'updateDocuments'])->whereNumber('id');
    Route::post('/cases/{id}/comments', [App\Http\Controllers\Api\Offboarding\OffboardingController::class, 'addComment'])->whereNumber('id');
    Route::post('/cases/{id}/exit-interview', [App\Http\Controllers\Api\Offboarding\OffboardingController::class, 'updateExitInterview'])->whereNumber('id');
    Route::delete('/cases/{id}', [App\Http\Controllers\Api\Offboarding\OffboardingController::class, 'destroy'])->whereNumber('id');
});



/*
|--------------------------------------------------------------------------
| Agentic AI (module m7)
|--------------------------------------------------------------------------
| Agent registry, runs and traces, tool invocations, multi-agent workflows,
| analytics and the reflection system.
|
| Token authenticated + tenant scoped through
| App\Http\Controllers\Api\Agentic\Concerns\ResolvesAgenticContext. The screens
| this serves previously talked to two public HuggingFace Spaces with neither,
| so any browser could read or delete any organisation's agents.
|
| Fixed segments are declared before the {id} routes so they are never read as
| an id, and every {id} is whereNumber.
*/
Route::prefix('agentic')->group(function () {
    // Agents
    Route::get('/agents/meta', [AgenticAgentController::class, 'meta']);
    Route::get('/agents', [AgenticAgentController::class, 'index']);
    Route::post('/agents', [AgenticAgentController::class, 'store']);
    Route::post('/agents/{id}/clone', [AgenticAgentController::class, 'clone'])->whereNumber('id');
    Route::patch('/agents/{id}/status', [AgenticAgentController::class, 'setStatus'])->whereNumber('id');
    Route::post('/agents/{id}/run', [AgenticRunController::class, 'start'])->whereNumber('id');

    // Per-tenant setup. A shared catalogue agent is connected to each
    // organisation's own sheet / workspace / key here rather than by cloning it.
    Route::get('/agents/{id}/config', [AgenticConfigController::class, 'show'])->whereNumber('id');
    Route::post('/agents/{id}/config', [AgenticConfigController::class, 'update'])->whereNumber('id');
    Route::put('/agents/{id}/config', [AgenticConfigController::class, 'update'])->whereNumber('id');
    Route::delete('/agents/{id}/config', [AgenticConfigController::class, 'destroy'])->whereNumber('id');

    Route::get('/agents/{id}', [AgenticAgentController::class, 'show'])->whereNumber('id');
    Route::put('/agents/{id}', [AgenticAgentController::class, 'update'])->whereNumber('id');
    Route::delete('/agents/{id}', [AgenticAgentController::class, 'destroy'])->whereNumber('id');

    // Runs + traces
    Route::get('/runs', [AgenticRunController::class, 'index']);
    Route::get('/runs/{id}/trace', [AgenticRunController::class, 'trace'])->whereNumber('id');
    Route::post('/runs/{id}/tasks', [AgenticRunController::class, 'addTask'])->whereNumber('id');
    Route::post('/runs/{id}/cancel', [AgenticRunController::class, 'cancel'])->whereNumber('id');
    Route::get('/runs/{id}', [AgenticRunController::class, 'show'])->whereNumber('id');
    Route::put('/runs/{id}', [AgenticRunController::class, 'update'])->whereNumber('id');
    Route::delete('/runs/{id}', [AgenticRunController::class, 'destroy'])->whereNumber('id');

    // Tools
    Route::get('/tools', [AgenticToolController::class, 'catalogue']);
    Route::get('/tools/invocations', [AgenticToolController::class, 'invocations']);
    Route::get('/tools/invocations/{id}', [AgenticToolController::class, 'showInvocation'])->whereNumber('id');
    Route::post('/tools/{tool}/invoke', [AgenticToolController::class, 'invoke']);

    // Analytics
    Route::get('/analytics/dashboard', [AgenticAnalyticsController::class, 'dashboard']);
    Route::get('/analytics/overview', [AgenticAnalyticsController::class, 'overview']);

    // Multi-agent workflows
    Route::get('/workflows', [AgenticWorkflowController::class, 'index']);
    Route::post('/workflows', [AgenticWorkflowController::class, 'store']);
    Route::post('/workflows/{id}/steps', [AgenticWorkflowController::class, 'addStep'])->whereNumber('id');
    Route::put('/workflows/{id}/steps/{stepId}', [AgenticWorkflowController::class, 'updateStep'])->whereNumber('id')->whereNumber('stepId');
    Route::delete('/workflows/{id}/steps/{stepId}', [AgenticWorkflowController::class, 'deleteStep'])->whereNumber('id')->whereNumber('stepId');
    Route::post('/workflows/{id}/run', [AgenticWorkflowController::class, 'run'])->whereNumber('id');
    Route::get('/workflows/{id}', [AgenticWorkflowController::class, 'show'])->whereNumber('id');
    Route::put('/workflows/{id}', [AgenticWorkflowController::class, 'update'])->whereNumber('id');
    Route::delete('/workflows/{id}', [AgenticWorkflowController::class, 'destroy'])->whereNumber('id');

    Route::get('/workflow-runs/{id}', [AgenticWorkflowController::class, 'showRun'])->whereNumber('id');
    Route::put('/workflow-runs/{id}/steps/{stepRunId}', [AgenticWorkflowController::class, 'updateStepRun'])->whereNumber('id')->whereNumber('stepRunId');

    // Inter-agent messages
    Route::get('/messages', [AgenticWorkflowController::class, 'messages']);
    Route::post('/messages', [AgenticWorkflowController::class, 'storeMessage']);

    // Reflection
    Route::get('/reflection', [AgenticReflectionController::class, 'index']);
    Route::post('/reflection/analyse', [AgenticReflectionController::class, 'analyse']);
    Route::put('/reflection/optimizations/{id}', [AgenticReflectionController::class, 'updateOptimization'])->whereNumber('id');
});

/*
|--------------------------------------------------------------------------
| X-06 — notifications and terminology
|--------------------------------------------------------------------------
| NO MIDDLEWARE GROUP, DELIBERATELY. Every method resolves the caller from
| their own token and scopes to that person's inbox, so there is no "who may
| call this" question separate from "whose rows come back" - the two are the
| same question here, and the controller is the only place that can answer it.
|
| /api/terminology is read by screen labels and report headings, not only by
| notifications. It is placed here because X-06 built it; the contract is the
| path, not the namespace behind it.
*/
Route::get('/notifications', [App\Http\Controllers\Api\Notifications\NotificationController::class, 'index']);
Route::get('/notifications/unread-count', [App\Http\Controllers\Api\Notifications\NotificationController::class, 'unreadCount']);
Route::patch('/notifications/read-all', [App\Http\Controllers\Api\Notifications\NotificationController::class, 'markAllRead']);
Route::patch('/notifications/{id}/read', [App\Http\Controllers\Api\Notifications\NotificationController::class, 'markRead'])->whereNumber('id');

Route::get('/terminology', [App\Http\Controllers\Api\Notifications\TerminologyController::class, 'index']);
Route::put('/terminology', [App\Http\Controllers\Api\Notifications\TerminologyController::class, 'update'])->middleware('profile:admin,hr');

/*
|--------------------------------------------------------------------------
| X-16 — reporting-line assignment
|--------------------------------------------------------------------------
| THE WRITE PATH ReportingLineValidator NEVER HAD. F-05a asked for the
| validator to be called from every write path that sets reporting_manager_id;
| there were none, which is why it sat NOT STARTED from Gate B (G-ORG-01/02).
|
| Coverage is readable by anyone authenticated - it is a health figure, not a
| secret. Writes need admin/hr: a reporting line decides whose data a manager
| can see, so assigning one is a permission change in effect.
*/
Route::get('/reporting-line/coverage', [App\Http\Controllers\Api\Org\ReportingLineController::class, 'coverage']);
Route::post('/reporting-line/assign', [App\Http\Controllers\Api\Org\ReportingLineController::class, 'assign'])->middleware('profile:admin,hr');
Route::post('/reporting-line/bulk', [App\Http\Controllers\Api\Org\ReportingLineController::class, 'bulkAssign'])->middleware('profile:admin,hr');
Route::post('/reporting-line/department-head', [App\Http\Controllers\Api\Org\ReportingLineController::class, 'setDepartmentHead'])->middleware('profile:admin,hr');

// L-06 — what depends on a library row, counted BY KEY (G-LIB-09). Read-only and
// authenticated; the controller scopes the subject to the caller's organisation.
Route::get('/competency/library/dependants', [\App\Http\Controllers\Api\Competency\LibraryDependantsController::class, 'index']);

// The 9-box's second axis (G-FLOW-26). Read-only; the controller scopes to the
// caller's organisation. Elevated roles only - it shows every employee's rating.
Route::get('/competency/nine-box', [\App\Http\Controllers\Api\Competency\NineBoxController::class, 'index'])->middleware('profile:admin,hr');

// X-08(a) — what a seed-library import would give you, before you run it.
// Reports only; imports nothing (G-SEED-01 R5).
Route::get('/competency/seed-library/preview', [\App\Http\Controllers\Api\Competency\SeedLibraryPreviewController::class, 'index'])->middleware('profile:admin,hr');

// X-08(b) part 1 — bring-your-own framework, DRY RUN ONLY. Writes nothing.
// Not skillLibraryController::competencyLibraryImport, which writes flat skill
// rows; this one is KASBA-aware across all five dimensions.
Route::post('/competency/framework-import/dry-run', [\App\Http\Controllers\Api\Competency\FrameworkImportController::class, 'dryRun'])->middleware('profile:admin,hr');
Route::post('/competency/framework-import/commit', [\App\Http\Controllers\Api\Competency\FrameworkImportController::class, 'commitImport'])->middleware('profile:admin,hr');

// X-07d - readiness gates, admin surface. The guard is the EXISTING
// profile:admin,hr middleware (exact role_key match, alias map for legacy
// profiles); the controller deliberately does not re-implement it.
Route::get('/readiness/gates', [\App\Http\Controllers\Api\Readiness\ReadinessGateController::class, 'index'])->middleware('profile:admin,hr');   // menuright:225,view RE-ADD WITH THE MENU
// ⚠ THE MATRIX GUARD IS TEMPORARILY UNWIRED FROM THESE TWO ROUTES.
//
// They carried menuright:225,view / :225,edit. Menu 225 was created to prove the
// guard and then ROLLED BACK - so no rights row exists, and the precedence tail
// is DENY. The result: /api/readiness/gates returned 403 TO EVERYONE, the
// administrator included.
//
// A GUARD THAT NAMES A MENU IS A DEPENDENCY ON A ROW. Committing the guard while
// rolling back the row left a correct guard pointing at nothing, and "deny when
// undeclared" - which is the right default - turned that into a dead endpoint.
//
// RE-ADD BOTH when G-NAV-02 is re-run. The guard itself is unchanged and proven;
// only its wiring is deferred, and it is deferred because the data it depends on
// is deliberately absent.
// MATRIX-ENFORCED. `menuright:225,edit` consults tblgroupwise_rights_g2g: menu 225 is
// Readiness Gates, and acknowledging is an EDIT. hr_manager holds can_view=1 and
// can_edit=0 there, so HR is refused BY THE ROW - flip the row and the answer
// flips. profile:admin,hr STAYS as the outer coarse guard; the menu right is the
// finer one inside it.
Route::post('/readiness/gates/acknowledge', [\App\Http\Controllers\Api\Readiness\ReadinessGateController::class, 'acknowledge'])->middleware('profile:admin,hr');   // menuright:225,edit RE-ADD WITH THE MENU
