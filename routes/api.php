<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\IndustryController;
use App\Http\Controllers\Api\jobrolecontroller;
use App\Http\Controllers\Api\skillcontroller;
use App\Http\Controllers\libraries\jobroletexonomycontroller;
use App\Http\Controllers\libraries\jobroletaskcontroller;
use App\Http\Controllers\libraries\jobroleskillcontroller;
use App\Http\Controllers\HRMS\HrmsController;

use App\Http\Controllers\talent_management\talentmanagement_jobpostingController;
use App\Http\Controllers\talent_management\talentmanagement_jobapplicationController;


Route::Resource('job-applications', talentmanagement_jobapplicationController::class);


Route::Resource('talents', talentmanagement_jobpostingController::class);
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

?>