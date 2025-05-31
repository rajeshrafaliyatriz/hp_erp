<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\auth\authController;
use App\Http\Controllers\AJAXController;
use App\Http\Controllers\libraries\skillLibraryController;
use App\Http\Controllers\custom_module\CustomModuleController;

Route::get('/', function () {
    return view('welcome');
});

Route::resource('login', authController::class);

Route::middleware(['auth','session','menu'])->group(function () {
    
    Route::get('menu_lists', [authController::class, 'menu_lists'])->name('menu_lists');
    Route::resource('skill_library', skillLibraryController::class);
    Route::get('search_skill', [AJAXController::class, 'searchSkill'])->name('search_skill');

    Route::resource('jobrole', skillLibraryController::class);

    Route::resource('profeciency_level', skillLibraryController::class);
    Route::resource('knowledge_ability', skillLibraryController::class);
    Route::resource('application', skillLibraryController::class);
});

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

});
Route::get('studentLists', [AJAXController::class, 'studentLists'])->name('studentLists');

Route::get('menuLevel2', [CustomModuleController::class, 'menuLevel2'])->name('menuLevel2.index');