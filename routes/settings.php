<?php

use App\Http\Controllers\settings\instituteDetailController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'settings', 'middleware' => ['auth','session','menu']], function () {
    Route::resource('institute_detail', instituteDetailController::class);
});
