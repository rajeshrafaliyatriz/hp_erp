<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HRMS\departmentController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
    
Route::group(['prefix' => 'hrms', 'middleware' => ['auth','session','menu']], function () {
   
    Route::resource('add_department', departmentController::class);
    route::get('department-Emp-Lists',[departmentController::class, 'departmentEmpLists'])->name('departmentEmpLists');
    route::get('sub-department-list',[departmentController::class, 'subDepartmentList'])->name('subDepartmentList');
    route::get('department-employee-list',[departmentController::class, 'departmentEmployeeList'])->name('departmentEmployeeList');

});

