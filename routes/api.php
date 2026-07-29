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
use App\Http\Controllers\Api\Competency\CompetencyController as CompetencyCrudController;
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
use App\Http\Controllers\Api\TaskManagement\ProjectController;
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
Route::get('/skill-development/recent-activity', [SkillDevelopmentController::class, 'getRecentActivity']);

Route::get('/competency/workload-heatmap', [SubCompetencyDashboardController::class, 'getWorkloadHeatmap']);
Route::get('/competency/kpi', [SubCompetencyDashboardController::class, 'getKPI']);
Route::get('/competency/role-similarity', [SubCompetencyDashboardController::class, 'getRoleSimilarity']);
Route::get('/competency/coverage-scorecards', [SubCompetencyDashboardController::class, 'getCoverageScorecards']);
Route::get('/competency/health-radar', [SubCompetencyDashboardController::class, 'getHealthRadar']);
Route::get('/competency/skills-management-funnel', [SubCompetencyDashboardController::class, 'getSkillsManagementFunnel']);
Route::get('/competency/alignment', [SubCompetencyDashboardController::class, 'getAlignment']);

/*
| Competency Command Center + domain CRUD (token authenticated, tenant scoped
| via App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext).
| Additive - does not touch the read-only /competency/* analytics routes above.
*/
Route::get('/competency/command-center', [CompetencyCommandCenterController::class, 'index']);
Route::get('/competency/command-center/filters', [CompetencyCommandCenterController::class, 'filters']);

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
Route::post('/competency/employee-profiles/{id}/skills', [EmployeeCompetencyProfileController::class, 'addSkill'])->whereNumber('id');
Route::put('/competency/employee-profiles/{id}/skills/{matrixId}', [EmployeeCompetencyProfileController::class, 'updateSkill'])->whereNumber('id')->whereNumber('matrixId');
Route::get('/competency/employee-profiles/{id}/skills/{skillId}/history', [EmployeeCompetencyProfileController::class, 'skillHistory'])->whereNumber('id')->whereNumber('skillId');
Route::get('/competency/employee-profiles/{id}/notes', [EmployeeCompetencyProfileController::class, 'notes'])->whereNumber('id');
Route::put('/competency/employee-profiles/{id}/notes', [EmployeeCompetencyProfileController::class, 'saveNotes'])->whereNumber('id');
Route::get('/competency/employee-profiles/{id}/certifications', [EmployeeCompetencyProfileController::class, 'certifications'])->whereNumber('id');
Route::get('/competency/employee-profiles/{id}/development-plans', [EmployeeCompetencyProfileController::class, 'developmentPlans'])->whereNumber('id');
Route::get('/competency/employee-profiles/{id}/evidence', [EmployeeCompetencyProfileController::class, 'evidence'])->whereNumber('id');
Route::post('/competency/employee-profiles/{id}/evidence', [EmployeeCompetencyProfileController::class, 'storeEvidence'])->whereNumber('id');
Route::delete('/competency/employee-profiles/{id}/evidence/{evidenceId}', [EmployeeCompetencyProfileController::class, 'deleteEvidence'])->whereNumber('id')->whereNumber('evidenceId');
Route::get('/competency/employee-profiles/{id}/career-path', [EmployeeCompetencyProfileController::class, 'careerPath'])->whereNumber('id');

Route::get('/competency/competencies', [CompetencyCrudController::class, 'index']);
Route::post('/competency/competencies', [CompetencyCrudController::class, 'store']);
Route::delete('/competency/competencies/{id}', [CompetencyCrudController::class, 'destroy'])->whereNumber('id');

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

Route::resource('skill_library', skillLibraryController::class);
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
Route::prefix('task-management')->group(function () {
    Route::get('/my-tasks', [MyTasksController::class, 'index']);
    Route::get('/my-tasks/{id}', [MyTasksController::class, 'show'])->whereNumber('id');
    Route::patch('/my-tasks/{id}/status', [MyTasksController::class, 'updateStatus'])->whereNumber('id');
    Route::get('/projects/options', [ProjectController::class, 'options']);
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{id}', [ProjectController::class, 'show'])->whereNumber('id');
    Route::put('/projects/{id}', [ProjectController::class, 'update'])->whereNumber('id');
    Route::patch('/projects/{id}/archive', [ProjectController::class, 'archive'])->whereNumber('id');
    Route::put('/projects/{id}/members', [ProjectController::class, 'syncProjectMembers'])->whereNumber('id');
    Route::put('/projects/{id}/tasks', [ProjectController::class, 'syncTasks'])->whereNumber('id');
    Route::post('/projects/{id}/workstreams', [ProjectController::class, 'storeWorkstream'])->whereNumber('id');
    Route::put('/projects/{projectId}/workstreams/{workstreamId}', [ProjectController::class, 'updateWorkstream'])->whereNumber('projectId')->whereNumber('workstreamId');
    Route::delete('/projects/{projectId}/workstreams/{workstreamId}', [ProjectController::class, 'destroyWorkstream'])->whereNumber('projectId')->whereNumber('workstreamId');
});
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
