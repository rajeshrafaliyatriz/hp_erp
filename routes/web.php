<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\auth\authController;
use App\Http\Controllers\api\NewLMS_ApiController;
use App\Http\Controllers\api\NewLMS_StudentApiController;
use App\Http\Controllers\AJAXController;
use App\Http\Controllers\dashboardController;
use App\Http\Controllers\loginController;
use App\Http\Controllers\leave\HrmsDepartment;
use App\Http\Controllers\school_setup\batchController;
use App\Http\Controllers\school_setup\changePasswordController;
use App\Http\Controllers\school_setup\chapterController;
use App\Http\Controllers\school_setup\classteacherController;
use App\Http\Controllers\school_setup\classteacherReportController;
use App\Http\Controllers\easy_com\send_birthday_notification\send_birthday_notification_controller;
use App\Http\Controllers\dashboards\orgDashboardContorller;
use App\Http\Controllers\libraries\skillLibraryController;
use App\Http\Controllers\libraries\jobroleLibraryController;
use App\Http\Controllers\libraries\SkillMatrixController;
use App\Http\Controllers\custom_module\CustomModuleController;
use App\Http\Controllers\school_setup\masterSetupController;
use App\Http\Controllers\school_setup\sub_std_mapController;
use App\Http\Controllers\CkeditorFileUploadController;
use App\Http\Controllers\front_desk\syllabus\syllabusController;
use App\Http\Controllers\libraries\levelOfResponsibilityController;
use App\Http\Controllers\auth\ForgotPasswordController;
use App\Http\Controllers\dashboards\SkillDashboardController;
use App\Http\Controllers\school_setup\classwisetimetableController;
use App\Http\Controllers\school_setup\dashboardSettingController;
use App\Http\Controllers\school_setup\divisionCapacityMasterController;
use App\Http\Controllers\school_setup\erpstatusController;
use App\Http\Controllers\school_setup\facultywisetimetableController;
use App\Http\Controllers\school_setup\lessonplanningController;
use App\Http\Controllers\school_setup\lessonplanningReportController;
use App\Http\Controllers\school_setup\periodController;
use App\Http\Controllers\school_setup\proxyController;
use App\Http\Controllers\school_setup\proxyReportController;
use App\Http\Controllers\school_setup\schoolController;
use App\Http\Controllers\school_setup\std_divController;
use App\Http\Controllers\school_setup\subject1Controller;
use App\Http\Controllers\school_setup\teacherdailyReportController;
use App\Http\Controllers\school_setup\teachertransferController;
use App\Http\Controllers\school_setup\timetableController;
use App\Http\Controllers\school_setup\todaysproxyReportController;
use App\Http\Controllers\school_setup\topicController;
use App\Http\Controllers\school_setup\workflowController;
use App\Http\Controllers\school_setup\subjectElectiveController;
use App\Http\Controllers\school_setup\mapTeacherController;
use App\Http\Controllers\school_setup\docStdMappingController;
use App\Http\Controllers\signupController;
use App\Http\Controllers\institute_detail;
use App\Http\Controllers\normClatureController;
use App\Http\Controllers\lms\questionWiseReportController;
use App\Http\Controllers\template_result\TemplateResult;
use App\Http\Controllers\tourController;
use App\Http\Controllers\used_storage_graphController;
use App\Http\Controllers\UserFormbuilderController;
use App\Models\general_dataModel;
use Illuminate\Http\Request;
use App\Http\Controllers\Import\ImportController;
use App\Http\Controllers\Import\questionExcelDownloadController;
use App\Http\Controllers\leave\ApplyLeaveController;
use App\Http\Controllers\leave\HolidayController;
use App\Http\Controllers\leave\LeaveController;
use App\Http\Controllers\leave\LeaveTypeController;
use App\Http\Controllers\Payroll\PayrollController;
use App\Http\Controllers\HRMS\HrmsController;
use App\Http\Controllers\library\BookController;
use App\Http\Controllers\library\LibraryReportController;
use App\Http\Controllers\sqaa\sqaa_controller;
use App\Http\Controllers\sqaa\sqaaReportController;
use App\Http\Controllers\sqaa\sqaaScoreReportController;
use App\Http\Controllers\leave\LeaveAuthorisationController;
use App\Http\Controllers\leave\leave_report\LeaveReportController;
use App\Http\Controllers\leave\leave_summary_report\LeaveSummaryReportController;
use App\Http\Controllers\superAdminController;
use App\Http\Controllers\WhatsappController;
use App\Http\Controllers\oldDocumentTransfer;
use App\Http\Controllers\BotManController;
use App\Http\Controllers\reuirementController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\lms\chapterController as LmsChapterController;
use App\Http\Controllers\library\itemVerificationController;
use App\Http\Controllers\library\itemScanController;
use App\Http\Controllers\DataMigrationController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\library\LostandDamage;




Route::resource('skill_dashboard', SkillDashboardController::class);



Route::get('/', function () {
    return view('welcome');
});

Route::resource('login', authController::class);

Route::middleware(['auth','session','menu'])->group(function () {
    Route::resource('dashboard', dashboardController::class);
    Route::resource('organization_dashboard', orgDashboardContorller::class);
    
    Route::get('menu_lists', [authController::class, 'menu_lists'])->name('menu_lists');
    Route::resource('skill_library', skillLibraryController::class);
    Route::resource('jobrole_library', jobroleLibraryController::class);
    Route::resource('level_of_responsibility', levelOfResponsibilityController::class);

    Route::post('skill_library/add_category', [skillLibraryController::class, 'AddCategory'])->name('add_category');

    Route::post('skill_library/attributes_taxonomy', [skillLibraryController::class, 'AddAttributeTaxonomy'])->name('attributes_taxonomy');

    Route::get('search_data', [AJAXController::class, 'searchSkill'])->name('search_skill');

    Route::resource('jobrole', skillLibraryController::class);

    Route::resource('profeciency_level', skillLibraryController::class);
    Route::resource('knowledge_ability', skillLibraryController::class);
    Route::resource('application', skillLibraryController::class);

    Route::resource('leave-type', LeaveTypeController::class);
    Route::resource('holiday', HolidayController::class);
    Route::resource('leave-apply', ApplyLeaveController::class);
    Route::post('/get-employees', [ApplyLeaveController::class, 'getEmployees'])->name('get-employees');
    Route::get('my-leave', [ApplyLeaveController::class,'myLeave'])->name('my-leave');
    Route::post('my-leave-update', [ApplyLeaveController::class,'updateLeave'])->name('my_leave_update');
    Route::get('get-leave', [ApplyLeaveController::class,'getYearwiseleave'])->name('get-leave');
    Route::get('import-leave', [ApplyLeaveController::class,'importLeave'])->name('import-leave');
    Route::post('import-leave', [ApplyLeaveController::class,'importOldLeave'])->name('import-leave');
    Route::get('holiday.weekdays', [HolidayController::class,'getWeekdays'])->name('holiday.weekdays');
    Route::post('holiday.weekdays', [HolidayController::class,'storeWeekdays'])->name('holiday.weekdays');
    
    //Get Holiday Ajax
    Route::get('/getHolidays',[ApplyLeaveController::Class,'getHolidays'])->name('getHolidays');

    //Leave Authorisation
    Route::resource('leave-authorisation', LeaveAuthorisationController::class);
    Route::get('show-leave-authorisation', [LeaveAuthorisationController::class,'leaveAuthorisation'])->name('leave.authorisation.index');
    Route::post('leave-authorisation-store', [LeaveAuthorisationController::class,'leaveAuthorisationStore'])->name('leave.authorisation.store');

    //Leave Report
    Route::get('leave-report', [LeaveReportController::class,'leaveReport'])->name('leave.report');
    Route::post('leave-report-show', [LeaveReportController::class,'leaveReportShow'])->name('leave.report.show');
    Route::post('/get-emp-list', [LeaveReportController::class, 'getEmpLists'])->name('get-emp-list');

    //Leave Summary Report
    Route::get('leave-summary-report', [LeaveSummaryReportController::class,'leaveSummaryReport'])->name('leave.summary.report');
    Route::post('leave-summary-report-show', [LeaveSummaryReportController::class,'leaveSummaryReportShow'])->name('leave.summary.report.show');
    Route::post('/emp-list', [LeaveSummaryReportController::class, 'EmpLists'])->name('emp-list');
    Route::get('leave-summary-report/create', [LeaveSummaryReportController::class,'leaveSummaryReportShow'])->name('leavesummary.create');
    Route::get('leave-list', [LeaveSummaryReportController::class,'leaveLists'])->name('leavelist');
});

// Route::group(['prefix' => 'school_setup', 'middleware' => ['auth','session','menu']]), function () {
// route::resource('forget_password',ForgotPasswordController::class);
Route::get('forget-password', [ForgotPasswordController::class, 'showForgetPasswordForm'])->name('forget.password.get');
Route::post('forget-password', [ForgotPasswordController::class, 'submitForgetPasswordForm'])->name('forget.password.post');
Route::get('reset-password/{token}/{email}', [ForgotPasswordController::class, 'showResetPasswordForm'])->name('reset.password.get');
Route::post('reset-password', [ForgotPasswordController::class, 'submitResetPasswordForm'])->name('reset.password.post');

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
});

    Route::post('/skill-matrix/store-bulk', [SkillMatrixController::class, 'storeBulk'])->name('skill-matrix.store-bulk');

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
Route::get('ajax_checkEmailExist', [AJAXController::class, 'ajax_checkEmailExist'])->name('ajax_checkEmailExist');
Route::get('getUsersMappings', [AJAXController::class, 'getUsersMappings'])->name('getUsersMappings');
Route::get('DeepSeekChat', [AJAXController::class, 'DeepSeekChat'])->name('DeepSeekChat');
Route::get('AIassignTask', [AJAXController::class, 'AIassignTask'])->name('AIassignTask');

/*Rajesh for only API temporary created for data fetch*/
Route::get('getSkillCompetency', [AJAXController::class, 'getSkillCompetency'])->name('getSkillCompetency');
Route::get('sendEmail', [AJAXController::class, 'sendEmail'])->name('sendEmail');

Route::post('gemini_chat',[AJAXController::class,'geminiChat']);
Route::get('AICourseGeneration',[AJAXController::class,'AICourseGeneration']);
Route::get('gammaContent',[AJAXController::class,'GammaContentGeneration']);