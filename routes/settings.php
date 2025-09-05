<?php

use App\Http\Controllers\settings\instituteDetailController;
use App\Http\Controllers\settings\organizationDetailsController;
use App\Http\Controllers\settings\discliplinaryManagementController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'settings', 'middleware' => ['auth','session','menu']], function () {
    Route::resource('institute_detail', instituteDetailController::class);
    Route::resource('organization_data', organizationDetailsController::class);
    Route::resource('discliplinary_management', discliplinaryManagementController::class);
});
