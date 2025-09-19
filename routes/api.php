<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\IndustryController;
use App\Http\Controllers\Api\jobrolecontroller;
use App\Http\Controllers\Api\skillcontroller;

Route::resource('skills', skillcontroller::class);

Route::get('skills/search', [jobrolecontroller::class, 'searchskills']);

Route::get('jobrole/{id}/skills', [jobrolecontroller::class, 'skills']);

Route::get('/department/{id}/jobroles', [jobrolecontroller::class, 'getJobRolesByDepartment']);


Route::get('/industry/{id}/departments', [IndustryController::class, 'departments']);


Route::get('/industries', [IndustryController::class, 'index']);

?>