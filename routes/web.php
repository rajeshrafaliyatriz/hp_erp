<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\auth\authController;
use App\Http\Controllers\AJAXController;
use App\Http\Controllers\libraries\skillLibraryController;
use App\Http\Controllers\libraries\jobroleLibraryController;
use App\Http\Controllers\libraries\SkillMatrixController;
use App\Http\Controllers\custom_module\CustomModuleController;
use App\Http\Controllers\school_setup\masterSetupController;
use App\Http\Controllers\school_setup\sub_std_mapController;
use App\Http\Controllers\CkeditorFileUploadController;
use App\Http\Controllers\front_desk\syllabus\syllabusController;
use App\Http\Controllers\libraries\levelOfResponsibilityController;

Route::get('/', function () {
    return view('welcome');
});

Route::resource('login', authController::class);

Route::middleware(['auth','session','menu'])->group(function () {
    
    Route::get('menu_lists', [authController::class, 'menu_lists'])->name('menu_lists');
    Route::resource('skill_library', skillLibraryController::class);
    Route::resource('jobrole_library', jobroleLibraryController::class);
    Route::resource('level_of_responsibility', levelOfResponsibilityController::class);

    Route::post('skill_library/add_category', [skillLibraryController::class, 'AddCategory'])->name('add_category');

    Route::get('search_data', [AJAXController::class, 'searchSkill'])->name('search_skill');

    Route::resource('jobrole', skillLibraryController::class);

    Route::resource('profeciency_level', skillLibraryController::class);
    Route::resource('knowledge_ability', skillLibraryController::class);
    Route::resource('application', skillLibraryController::class);
});

// Route::group(['prefix' => 'school_setup', 'middleware' => ['auth','session','menu']]), function () {


Route::group(['prefix' => 'school_setup', 'middleware' => ['auth','session','menu']], function () {
    Route::resource('master_setup', masterSetupController::class);
    Route::post('insert_data', [masterSetupController::class, 'insert_data'])->name('insert_data');
    Route::resource('sub_std_map', sub_std_mapController::class);
});
Route::post('collectsct', [AJAXController::class, 'collectsct'])->name('collectsct');

Route::get('table_data',[AJAXController::class, 'GetTableData'])->name('table_data');

Route::group(['prefix' => 'custom-module'], function () {
    Route::get('/tables',[CustomModuleController::class,'tables'])->name('custom-module.tables');
    Route::get('/table-create/{id?}',[CustomModuleController::class,'tableCreate'])->name('custom_module_table.create');
    Route::post('/table-store',[CustomModuleController::class,'tableStore'])->name('custom_module_table.store');
    Route::delete('/table-delete/{id}',[CustomModuleController::class,'tableDelete'])->name('custom_module_table.delete');


    Route::get('/table-column-create/{id}',[CustomModuleController::class,'tableColumnCreate'])->name('custom_module_table_column.create');
    Route::post('/table-column-store/{id}',[CustomModuleController::class,'tableColumnStore'])->name('custom_module_table_column.store');
    Route::get('/table-column-create/{id}/column/{colId}',[CustomModuleController::class,'tableColumnCreate']);
    Route::delete('/table-column-delete/{id}/column/{colId}',[CustomModuleController::class,'tableColumnDelete'])->name('custom_module_table_column.delete');

    Route::get('/create-db-table/{id}',[CustomModuleController::class,'createDBTable']);
   
    Route::get('/{id}',[CustomModuleController::class,'crudIndex'])->name('custom_module_crud.index');
    Route::get('/create-view/{id}',[CustomModuleController::class,'crudCreate'])->name('custom_module_crud.create');
    Route::get('/create-view/{id}/update/{recordId}',[CustomModuleController::class,'crudCreate']);
    Route::post('/create-view-store/{id}',[CustomModuleController::class,'crudStore'])->name('custom_module_crud.store');
    Route::delete('/view-delete/{id}',[CustomModuleController::class,'viewDelete'])->name('custom_module_crud.delete');
    Route::get('ajax_StandardwiseSubject', [chapterController::class, 'StandardwiseSubject'])->name('ajax_StandardwiseSubject');
     Route::get('/matrix', [SkillMatrixController::class, 'index'])->name('matrix');
    Route::post('/matrix/save', [SkillMatrixController::class, 'store'])->name('matrix.save');
});
Route::get('studentLists', [AJAXController::class, 'studentLists'])->name('studentLists');

Route::get('menuLevel2', [CustomModuleController::class, 'menuLevel2'])->name('menuLevel2.index');

Route::get('api/get-standard-list', [AJAXController::class, 'getStandardList']);
Route::get('api/get-subject-list', [AJAXController::class, 'getSubjectList']);
Route::get('api/get-all-subject-list', [AJAXController::class, 'getAllSubjectList']);
/** get exam list */
Route::get('api/get-exam-name-list', [AJAXController::class, 'getExamsList']);
Route::get('api/get-exam-master-list', [AJAXController::class, 'getExamsMasterList']);

Route::get('ckeditor/create', [CkeditorFileUploadController::class, 'create'])->name('ckeditor.create');
Route::post('ckeditor', [CkeditorFileUploadController::class, 'store'])->name('uploadimage');
Route::get('ajax_checkEmailExist', [AJAXController::Class, 'ajax_checkEmailExist'])->name('ajax_checkEmailExist');
Route::get('getUsersMappings', [AJAXController::Class, 'getUsersMappings'])->name('getUsersMappings');
Route::get('DeepSeekChat', [AJAXController::Class, 'DeepSeekChat'])->name('DeepSeekChat');
Route::get('AIassignTask', [AJAXController::Class, 'AIassignTask'])->name('AIassignTask');
