<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\IndustryController;
use App\Http\Controllers\Api\jobrolecontroller;
use App\Http\Controllers\Api\skillcontroller;
use App\Http\Controllers\libraries\jobroletexonomycontroller;
use App\Http\Controllers\libraries\jobroletaskcontroller;
use App\Http\Controllers\libraries\jobroleskillcontroller;
use App\Http\Controllers\HRMS\HrmsController;
use App\Http\Controllers\talent\talent_jobpostingcontroller;
use App\Http\Controllers\talent\talent_jobapplicationcontroller;
use App\Http\Controllers\talent\talent_interviewschedulescontroller;
use App\Http\Controllers\build_with_AI\buildwithAIController;


use App\Http\Controllers\lms_course_enroll\LmsCourseEnrollController;


Route::resource('interview-schedules', talent_interviewschedulescontroller::class);

Route::resource('job-applications', talent_jobapplicationcontroller::class);

Route::resource('job-postings', talent_jobpostingcontroller::class);

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
Route::post('designation_leave', [HrmsLeaveController::class, 'store']);
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





Route::get('/enroll', [LmsCourseEnrollController::class, 'index']);
Route::post('/enroll', [LmsCourseEnrollController::class, 'store']);
Route::put('/enroll/{id}', [LmsCourseEnrollController::class, 'update']);
Route::delete('/enroll/{id}', [LmsCourseEnrollController::class, 'destroy']);

Route::post('/save-generated-course', [buildwithAIController::class, 'store']);
Route::get('/index', [buildwithAIController::class, 'index']);
?>