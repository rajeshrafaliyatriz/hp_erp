<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\IndustryController;
use App\Http\Controllers\Api\jobrolecontroller;

Route::get('/department/{id}/jobroles', [jobrolecontroller::class, 'getJobRolesByDepartment']);


Route::get('/industry/{id}/departments', [IndustryController::class, 'departments']);


Route::get('/industries', [IndustryController::class, 'index']);

?>