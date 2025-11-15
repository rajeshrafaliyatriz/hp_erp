<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\talent_management\talentmanagementController;

Route::Resource('talents', talentmanagementController::class);
