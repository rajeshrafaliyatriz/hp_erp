<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\IndustryController;
use App\Http\Controllers\Api\jobrolecontroller;
use App\Http\Controllers\Api\skillcontroller;
use App\Http\Controllers\libraries\jobroletexonomycontroller;
use App\Http\Controllers\libraries\jobroletaskcontroller;
use App\Http\Controllers\libraries\jobroleskillcontroller;
use App\Http\Controllers\HRMS\HrmsController;
use App\Http\Controllers\Api\CompetencyDashboardController;
use App\Http\Controllers\Api\DBController;
use App\Http\Controllers\talent\talent_jobpostingcontroller;
use App\Http\Controllers\talent\talent_jobapplicationcontroller;
use App\Http\Controllers\talent\talent_interviewschedulescontroller;
use App\Http\Controllers\talent\talent_screening_results_controller;

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

Route::post('/ai-generated-assessment/question/store',[generateQuestionController::class, 'store']);
Route::get('/ai-generated-assessment/question/index',[generateQuestionController::class, 'index']);

Route::post('/ai-generated-assessment/assessment/store', [generateAssessmentController::class, 'store']);
Route::get('/ai-generated-assessment/assessment/index',[generateAssessmentController::class, 'index']);


Route::resource('interview-schedules', talent_interviewschedulescontroller::class);

Route::resource('job-applications', talent_jobapplicationcontroller::class);

Route::resource('job-postings', talent_jobpostingcontroller::class);

Route::post('talent-screening-results', [talent_screening_results_controller::class, 'store']);
Route::get('talent-screening-results/candidate/{candidate_id}', [talent_screening_results_controller::class, 'show']);

Route::post('designation_leave', [HrmsLeaveController::class, 'store']);

Route::post('/jobrole-skill/store', [jobroleskillcontroller::class, 'storeSkill']);


Route::resource('job-role-tasks', jobroletaskcontroller::class);


Route::resource('jobroletexonomies', jobroletexonomycontroller::class);

Route::resource('skills', skillcontroller::class);

Route::resource('interview-schedules', talent_interviewschedulescontroller::class);
Route::resource('job-applications', talent_jobapplicationcontroller::class);
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
//HRIT dashboard
Route::get('/attendance-weekly', [AttendanceApiController::class, 'weeklySummary']);

Route::get('/jobroles-by-department', [JobroleApiController::class, 'getDepartmentWise']);
Route::get('/leave-distribution', [LeaveDistribution::class, 'leaveDistribution']);






Route::get('/enroll', [LmsCourseEnrollController::class, 'index']);
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

Route::get('/interview-panel/users', [talent_interviewpanelController::class, 'getInterviewers']);
Route::post('/interview-panel/store', [talent_interviewpanelController::class, 'storeinterviewer']);
Route::put('/interview-panel/update/{id}', [talent_interviewpanelController::class, 'update']);
Route::delete('/interview-panel/delete/{id}', [talent_interviewpanelController::class, 'destroy']);
Route::get('/interview-panel/list', [talent_interviewpanelController::class, 'getInterviewPanel']);
Route::get('/candidate', [candidateController::class,'getCandidate']);
Route::get('/feedback/{id}', [feedbackController::class, 'getFeedback']);
Route::post('/evaluation', [feedbackController::class, 'storeFeedback']);


