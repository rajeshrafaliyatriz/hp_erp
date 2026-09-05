<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HRMS\HrmsController;
use App\Http\Controllers\HRMS\HrmsLeaveController;
use App\Http\Controllers\Payroll\PayrollController;
use App\Http\Controllers\AJAXController;
use App\Http\Controllers\leave\leaveEncashmentController;
use App\Http\Controllers\HRMS\departmentController;
use App\Http\Controllers\HRMS\shiftMasterController;
use App\Http\Controllers\HRMS\bulkUserShiftUpdateController;
use App\Http\Controllers\leave\HolidayController;

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

Route::group(['prefix' => 'hrms', 'middleware' => ['auth', 'session', 'menu']], function () {

    // ->except(['show','edit']): departmentController has no show() or edit()
    // method. Route::resource registered both anyway, so /hrms/add_department/5
    // and /hrms/add_department/5/edit were live URLs that could only ever throw.
    Route::resource('add_department', departmentController::class)->except(['show', 'edit']);
    Route::resource('holiday', HolidayController::class);
    route::get('department-Emp-Lists', [departmentController::class, 'departmentEmpLists'])->name('departmentEmpLists');
    route::get('sub-department-list', [departmentController::class, 'subDepartmentList'])->name('subDepartmentList');
    route::get('department-employee-list', [departmentController::class, 'departmentEmployeeList'])->name('departmentEmployeeList');
    route::get('department-jobroles', [departmentController::class, 'departmentJobRoles'])->name('departmentJobRoles');
    route::get('jobrole-tasks', [departmentController::class, 'jobRoleTasks'])->name('jobRoleTasks');
    Route::get('holiday_weekdays', [HolidayController::class,'getWeekdays'])->name('holiday.weekdays');
    Route::post('holiday_weekdays', [HolidayController::class,'storeWeekdays'])->name('holiday.weekdays.store');
});


//PAYROLL SYSTEM
Route::group(['middleware' => ['auth', 'session', 'menu']], function () {

    /*
    |----------------------------------------------------------------------
    | Payroll - admin and HR only. HRIT Sprint 1, closing F-91 and F-92.
    |----------------------------------------------------------------------
    |
    | These routes had NO role gate. `auth` accepts any valid token, and
    | MenuMiddleware returns early for type=API, so the only thing standing
    | between an ordinary employee and every salary in the organisation was
    | payroll-shell.tsx - a React component, in the caller's own browser.
    |
    | Proven, not assumed: with an `employee` token from tenant 3,
    | GET /employee-salary-structure answered 200 with the full salary table,
    | and so did department_head, reporting_manager, recruiter and auditor.
    | The response also carried every employee's bcrypt password hash (F-92,
    | fixed separately in the tbluser models).
    |
    | `hrit.role` rather than `profile` because these same URLs serve the
    | session-authenticated Blade screens, which carry no token; see
    | App\Http\Middleware\RequireHritRole.
    |
    | The attendance routes further down this file are deliberately NOT in
    | this group: employees punch in and out through some of them. Their own
    | over-exposure is filed as F-120 and closed in Sprint 3, where the
    | screens that call them are being reworked anyway.
    */
    Route::middleware('hrit.role:admin,hr')->group(function () {

    Route::get('/payroll-type', [PayrollController::class, 'payrollType'])->name('payroll_type.index');
    Route::get('/payroll-type/create', [PayrollController::class, 'payrollCreate'])->name('payroll_type.create');
    Route::post('/payroll-type/store', [PayrollController::class, 'payrollStore'])->name('payroll_type.store');
    Route::get('/payroll-type/create/{id}', [PayrollController::class, 'payrollCreate']);
    Route::post('/payroll-type/destroy/{id}', [PayrollController::class, 'payrollDestroy'])->name('payroll_type.destroy');

    Route::get('/payroll-type-report', [PayrollController::class, 'payrollTypeReport'])->name('payrollTypeReport.index');
    Route::get('/payroll-type-report/create', [PayrollController::class, 'payrollTypeReportCreate'])->name('payrollTypeReport.create');

    Route::get('/employee-salary-structure', [PayrollController::class, 'employeeSalaryStructure'])->name('employee_salary_structure.index');
    Route::post('/employee-salary-structure', [PayrollController::class, 'employeeSalaryStructure'])->name('payroll.show_employee_salary_structure');
    Route::get('/roll-over', [PayrollController::class, 'rollOver'])->name('employee_salary_structure.rollover');
    Route::post('/employee-salary-structure/store', [PayrollController::class, 'employeeSalaryStructureStore'])->name('employee_salary_structure.store');
    Route::post('/rollover-employee-salary-structure/store', [PayrollController::class, 'rolloverEmployeeSalaryStructure'])->name('rollover_employee_salary_structure.store');

    Route::get('/salary-structure-report', [PayrollController::class, 'salaryStructureReport'])->name('salary_structure_report.index');
    Route::post('/salary-structure-report', [PayrollController::class, 'showSalaryStructureReport']);

    Route::get('/form16', [PayrollController::class, 'form16'])->name('form16.index');
    Route::post('/form16-get-employees-list', [PayrollController::class, 'getEmployeeLists'])->name('form16.get.employees.list');
    Route::post('/form16-report', [PayrollController::class, 'form16Report'])->name('form16.report');

    Route::get('/payroll-deduction', [PayrollController::class, 'payrollDeduction'])->name('payroll_deduction.index');
    Route::post('/payroll-deduction/store', [PayrollController::class, 'payrollDeductionStore'])->name('payroll_deduction.store');

    Route::get('/monthly-payroll-report', [PayrollController::class, 'monthlyPayrollReport'])->name('monthly_payroll_report.index');
    Route::post('/monthly-payroll-report', [PayrollController::class, 'monthlyPayrollReport'])->name('payroll.store_monthly_payroll_report');

    Route::post('/show-monthly-payroll-report', [PayrollController::class, 'monthlyPayrollReport'])->name('payroll.show_monthly_payroll_report');
    Route::get('/monthly-payroll-report/pdf/{id}/{month}/{year}', [PayrollController::class, 'monthlyPayrollPdf']);

    Route::get('/payroll-report', [PayrollController::class, 'payrollReport'])->name('payroll_report.index');
    Route::post('/payroll-report', [PayrollController::class, 'payrollReport'])->name('payroll.show_payroll_report');

    Route::get('/employee-payroll-history', [PayrollController::class, 'employeePayrollHistory'])->name('employee_payroll_history.index');
    Route::post('/employee-payroll-history', [PayrollController::class, 'employeePayrollHistory'])->name('payroll.show_employee_payroll_history');

    Route::get('/payroll-bank-wise-report', [PayrollController::class, 'payrollBankWiseReport'])->name('payroll_bankwise_report.index');
    Route::post('/payroll-bank-wise-report', [PayrollController::class, 'payrollBankWiseReport'])->name('payroll.show_payroll_bankwise_report');

    Route::get('hrms-salary-certificate', [PayrollController::class, 'hrmsSalaryCertificateIndex'])->name('hrms_salary_certificate.index');
    Route::post('/hrms-salary-certificate-report/{id}', [PayrollController::class, 'hrmsSalaryCertificateReport'])->name('hrms_salary_certificate.report.byId');
    Route::post('/hrms-salary-certificate-report', [PayrollController::class, 'hrmsSalaryCertificateReport'])->name('hrms_salary_certificate.report');
    Route::get('salary-certificate-pdf-download', [PayrollController::class, 'SalaryCertificatePdfDownload'])->name('salary_certificate_pdf_download');

    }); // end hrit.role:admin,hr - payroll configuration and reporting

    Route::get('hrms-job-title', [HrmsController::class, 'hrmsJobTitle'])->name('hrms_job_title.index');
    Route::get('/hrms-job-title/create', [HrmsController::class, 'hrmsCreate'])->name('hrms_job_title.create');
    Route::get('/hrms-job-title/create/{id}', [HrmsController::class, 'hrmsCreate']);
    Route::post('/hrms-job-title/store', [HrmsController::class, 'hrmsStore'])->name('hrms_job_title.store');
    Route::delete('/hrms-job-title/destroy/{id}', [HrmsController::class, 'hrmsDestroy'])->name('hrms_job_title.destroy');

    /*
    |----------------------------------------------------------------------
    | Attendance: self service stays open, reporting is gated. F-120.
    |----------------------------------------------------------------------
    |
    | Same class as F-91, different module: these routes had no role gate, so
    | an `employee` token returned 200 with every colleague's attendance totals
    | (probe-reads2.out, section F).
    |
    | Sprint 1 deliberately did NOT gate this block, because employees punch in
    | and out through some of it and the gate has to be drawn per route rather
    | than per group. That is what this is.
    |
    | OPEN - the employee's own attendance, and their own punches:
    |     hrms-attendance, hrms-attendance-in-time/store,
    |     hrms-attendance-out-time/store
    |
    | The role list matches the REPORTING group in the frontend's
    | gtg-nav-visibility.ts exactly - administrator, hr_manager, hr_executive,
    | executive, auditor - so the menu and the API agree about who may read a
    | report. Auditor and executive are read-only oversight roles; excluding
    | them here would hide from them the one thing they exist to look at.
    */
    Route::get('hrms-attendance', [HrmsController::class, 'hrmsAttendance'])->name('hrms_attendance.index');
    Route::post('hrms-attendance-in-time/store', [HrmsController::class, 'hrmsAttendanceInTimeStore'])->name('hrms_attendance_in_time.store');
    Route::post('hrms-attendance-out-time/store', [HrmsController::class, 'hrmsAttendanceOutTimeStore'])->name('hrms_attendance_out_time.store');

    Route::middleware('hrit.role:admin,hr,executive,auditor')->group(function () {

    // Shift in/out times - administrative configuration, not self service.
    Route::get('hrms-inout-time', [HrmsController::class, 'hrmsInOutTime'])->name('hrms_inout_time.index');
    Route::post('hrms-in-time/store', [HrmsController::class, 'hrmsInTimeStore'])->name('hrms_in_time.store');
    Route::post('hrms-out-time/store', [HrmsController::class, 'hrmsOutTimeStore'])->name('hrms_out_time.store');

    Route::get('hrms-attendance-report', [HrmsController::class, 'hrmsAttendanceReportIndex'])->name('hrms_attendance_report.index');
    Route::post('/show-hrms-attendance-report', [HrmsController::class, 'hrmsAttendanceReport'])->name('hrms.show_hrms_attendance_report');
    Route::post('/get-employees-list', [HrmsController::class, 'getEmployeeLists'])->name('get.employees.list');

    /**
     * R8 - DELETED 2026-08-12, and what it MEANT recorded before it went.
     *
     * Route::get('early-going-hrms-attendance-report/create',
     *     [HrmsController::class, 'earlyGoingHrmsAttendanceReportCreate'])
     *     ->name('hrms_attendance_report.early_going_report.create');
     *
     * INTENDED: the create-form companion to the `early_going_report` index on the
     * next line - the same Index/create pairing used across this file.
     *
     * WHY IT WENT: `earlyGoingHrmsAttendanceReportCreate` EXISTS NOWHERE IN app/.
     * The route fataled on every call, and had since it was written. Nothing
     * referenced it - not the route name, not the URI, in either repo.
     *
     * The sibling index above it has no create route either, so the pattern this
     * was reaching for was never built on either side.
     *
     * FOUND BY: counting O-05's residue in ROUTES rather than in sites. The site
     * count said HrmsController was 100% handled - a method that does not exist
     * has no sites to count. THE UNIT YOU COUNT IN DECIDES WHAT YOU CAN FIND.
     *
     * IT IS NOT ALONE: 197 of 1709 registered routes name a missing method
     * (30 bespoke, 167 Route::resource verbs). See `_evidence/route-method-exists.php`.
     * Only this one is deleted here - it is the one that was ordered.
     */
    Route::get('early-going-hrms-attendance-report', [HrmsController::class, 'earlyGoingHrmsAttendanceReportIndex'])->name('hrms_attendance_report.early_going_report');

    Route::get('/show-early-going-hrms-attendance-report', [HrmsController::class, 'earlyGoingHrmsAttendanceReport'])->name('hrms.show_early_going_hrms_attendance_report');
    Route::get('hrms-general-setting', [HrmsController::class, 'generalSettingIndex'])->name('hrms_general_setting.index');
    Route::post('hrms-general-setting/store', [HrmsController::class, 'generalSettingStore'])->name('hrms_general_setting.store');

    Route::get('departmentwise-attendance-report', [HrmsController::class, 'departmentAttendanceReport'])->name('department_attendance_report.index');
    Route::get('departmentwise-attendance-report/create', [HrmsController::class, 'departmentAttendanceReportCreate'])->name('department_attendance_report.create');

    Route::get('departmentwise-emplist', [AJAXController::class, 'getDepEmployeeLists'])->name('departmentwise-emplist');

    Route::get('get-holidays', [HrmsController::class, 'getHolidays']);
    Route::get('get-present-days', [HrmsController::class, 'getPresentDays']);
    Route::get('get-absent-days', [HrmsController::class, 'getAbsentDays']);
    Route::get('get-half-day', [HrmsController::class, 'getHalfDays']);

    }); // end hrit.role - attendance reporting and shift configuration

    // new monthly payroll report - same admin/hr gate as the payroll block above.
    Route::middleware('hrit.role:admin,hr')->group(function () {
        Route::get('/monthly-payroll', [PayrollController::class, 'monthlyPayroll'])->name('monthly_payroll.index');
        Route::get('/monthly-payroll/create', [PayrollController::class, 'monthlyPayrollCreate'])->name('monthly_payroll.create');
        Route::post('/monthly-payroll-store', [PayrollController::class, 'monthlyPayrollStore'])->name('monthly_payroll.store');

        // F-129. The month lock. Same hrit.role:admin,hr gate as the save it
        // guards - a lock enforced by a different gate than the write it
        // protects is a lock with a way round it.
        Route::match(['get', 'post'], '/monthly-payroll-lock', [PayrollController::class, 'monthlyPayrollLock'])
            ->name('monthly_payroll.lock');

        Route::post('/monthly-payroll-delete/{month}', [PayrollController::class, 'deleteMonthlyPayrolls'])->name('monthly_payroll.delete');

        Route::get('/getMonthlyData', [PayrollController::class, 'getEmpMonthlyData'])->name('getMonthlyData');

        Route::get('getTotalDays', [PayrollController::class, 'getTotalDays'])->name('getTotalDays');
    });
});

Route::group(['prefix' => 'hrms', 'middleware' => ['auth', 'session', 'menu']], function () {
    Route::resource('designation_leave', HrmsLeaveController::class);
    Route::resource('leave_encashment', leaveEncashmentController::class);
    // Removed duplicate route declaration - exact duplicate of line 27.
    Route::resource('user_shift_master', shiftMasterController::class);
    Route::resource('user_bulk_shift_update', bulkUserShiftUpdateController::class);
    // Removed duplicate route declaration - exact duplicate of line 29.
    // Removed duplicate route declaration - exact duplicate of line 30.
    // Removed duplicate route declaration - exact duplicate of line 31.
    // Removed duplicate route declaration - exact duplicate of line 32.
    // Removed duplicate route declaration - exact duplicate of line 33.

    route::get('attendance-by-id', [HrmsController::class, 'getAttandanceData'])->name('attendance_by_id');

    // multiple employee hrms attendance
    Route::get('multiple_attendance_report', [HrmsController::class, 'multipleAttendanceReportIndex'])->name('multiple_attendance_report.index');
    Route::get('multiple_attendance_report/create', [HrmsController::class, 'multipleAttendanceReportCreate'])->name('multiple_attendance_report.create');
    // daywise employee attendance altius
    Route::get('daywise_attendance_report', [HrmsController::class, 'DaywiseAttendanceReportIndex'])->name('daywise_attendance_report.index');
    Route::get('daywise_attendance_report/create', [HrmsController::class, 'DaywiseAttendanceportCreate'])->name('daywise_attendance_report.create');

    Route::post('update_user_att', [HrmsController::class, 'updateUserAttendance'])->name('update_user_att');
});

/**
 * F-100 - DELETED 2026-09-05 (HRIT Sprint 1), with what they MEANT recorded.
 *
 * Route::get('hrms/myleave/{employeeId}',      [HrmsLeaveController::class, 'getLeaveDashboard']);
 * Route::get('hrms/leavehistory/{employeeId}', [HrmsLeaveController::class, 'getLeaveHistory']);
 *
 * INTENDED: a self-service leave summary and history for one employee - the
 * mobile-shaped counterpart to the Leave Dashboard.
 *
 * WHY THEY WENT: neither returned an employee's leave. Both returned a
 * hardcoded block introduced by the comment `// Sample data`:
 *
 *     total_leaves 20, used_leaves 5, remaining_leaves 15
 *     Casual Leave 7/14, Medical Leave 10/14, Earn Leave 40/60, ...
 *
 * The same numbers for every employee, in every tenant, forever. Verified live
 * during the audit: GET /hrms/myleave/7 answered 200 with exactly that.
 *
 * They also took {employeeId} straight from the URL and never compared it to
 * the caller, so the day they were connected to real data they would have been
 * an identity hole as well.
 *
 * NOTHING CALLED THEM: no reference in g2gv0 (services, hooks, components, lib,
 * app), and none in this repo outside their own definition.
 *
 * THE REAL IMPLEMENTATION ALREADY EXISTS and is unrelated to these:
 *   GET /api/leave/dashboard  App\Http\Controllers\Api\Leave\LeaveDashboardController@index
 *   GET /api/leave/balances   App\Http\Controllers\Api\Leave\LeaveOptionsController@balances
 * Point any mobile client at those.
 */

