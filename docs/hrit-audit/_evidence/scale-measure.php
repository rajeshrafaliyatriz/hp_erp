<?php
/**
 * STAGE 5. Measure the HRIT surface at scale, against real endpoints.
 *
 *   php artisan tinker --execute="require '<abs>/Docs/hrit-audit/_evidence/scale-measure.php';"
 *
 * READ-ONLY. Every statement here is a SELECT.
 *
 * What this is NOT: a benchmark of the HTTP stack. It measures the QUERIES the
 * HRIT screens run, which is where a scale problem would actually live - an
 * N+1, a missing index, a full scan that was invisible at 23 rows.
 *
 * Each measurement runs twice and reports the SECOND run, so the first query's
 * cold buffer pool does not get reported as the cost of the feature.
 *
 * Timings are milliseconds, measured on the app's own database
 * (202.47.117.220), so they include real network round-trips rather than a
 * loopback.
 */

$tenant = 6;
$db = \Illuminate\Support\Facades\DB::connection('mysql');

function ms(callable $fn): array
{
    $fn();                      // warm
    $start = microtime(true);
    $result = $fn();
    return [round((microtime(true) - $start) * 1000, 1), $result];
}

echo 'Tenant ' . $tenant . ' volumes:' . PHP_EOL;
foreach ([
    'tbluser'                      => 'employees',
    'hrms_emp_leaves'              => 'leave requests',
    'employee_monthly_salary_data' => 'payslips',
    'employee_salary_structures'   => 'salary structures',
    'hrms_attendances'             => 'attendance rows',
] as $table => $label) {
    printf("  %-20s %s%s", $label, $db->table($table)->where('sub_institute_id', $tenant)->count(), PHP_EOL);
}

echo PHP_EOL . 'Query timings (2nd run, milliseconds):' . PHP_EOL;

$checks = [
    'employee list (active)' => fn () => $db->table('tbluser')
        ->where('sub_institute_id', $tenant)->where('status', 1)->count(),

    'leave list, one page of 10' => fn () => $db->table('hrms_emp_leaves as l')
        ->join('tbluser as u', 'u.id', '=', 'l.user_id')
        ->where('l.sub_institute_id', $tenant)->whereNull('l.deleted_at')
        ->orderByDesc('l.created_at')->limit(10)
        ->get(['l.id', 'l.from_date', 'l.status', 'u.first_name', 'u.last_name'])->count(),

    'leave counts by status' => fn () => $db->table('hrms_emp_leaves')
        ->where('sub_institute_id', $tenant)->whereNull('deleted_at')
        ->selectRaw('status, count(*) c')->groupBy('status')->get()->count(),

    'leave trend, 12 months' => fn () => $db->table('hrms_emp_leaves')
        ->where('sub_institute_id', $tenant)->whereNull('deleted_at')
        ->selectRaw('MONTH(from_date) m, count(*) c')->groupBy('m')->get()->count(),

    'payroll register, one month' => fn () => $db->table('employee_monthly_salary_data as e')
        ->join('tbluser as u', 'u.id', '=', 'e.employee_id')
        ->where('e.sub_institute_id', $tenant)->where('e.month', 'Aug')->where('e.year', 2026)
        ->get(['e.id', 'e.total_payment', 'u.employee_no'])->count(),

    'bank advice, one month' => fn () => $db->table('employee_monthly_salary_data')
        ->where('sub_institute_id', $tenant)->where('month', 'Aug')->where('year', 2026)
        ->whereNotNull('total_payment')->get()->count(),

    'salary structures, one year' => fn () => $db->table('employee_salary_structures as s')
        ->join('tbluser as u', 'u.id', '=', 's.employee_id')
        ->join('hrms_departments as d', 'd.id', '=', 'u.department_id')
        ->where('s.sub_institute_id', $tenant)->where('s.year', 2026)
        ->get(['s.id', 'u.employee_no', 'd.department'])->count(),

    'department attendance summary' => fn () => $db->table('hrms_attendances as a')
        ->join('tbluser as u', 'u.id', '=', 'a.user_id')
        ->where('a.sub_institute_id', $tenant)
        ->selectRaw('u.department_id, count(*) c')->groupBy('u.department_id')->get()->count(),
];

$slow = [];
foreach ($checks as $label => $fn) {
    [$took, $rows] = ms($fn);
    printf("  %-32s %8s ms   (%s rows)%s", $label, $took, $rows, PHP_EOL);
    if ($took > 1000) {
        $slow[$label] = $took;
    }
}

/*
 * F-207. The N+1 that this seeding exercise actually found.
 *
 * payrollBankWiseReport called employeeDetails() ONCE PER PAYSLIP - a joined
 * query against tbluser and tbluserprofilemaster for every row. At 500
 * employees that was ~503 queries and 2,085 ms of sequential round-trips,
 * against 16 ms for the payslip query it was decorating.
 *
 * It was invisible before this cohort existed: the largest tenant on the
 * deployment had ONE payslip, so the loop ran once.
 *
 * Counting QUERIES rather than timing is the durable check - a timing varies
 * with the network and a cold buffer pool, a query count does not. If this ever
 * climbs back toward the row count, the per-row lookup is back.
 */
echo PHP_EOL . 'Bank-wise Payment Advice - query count (the N+1 guard):' . PHP_EOL;

$controller = app(\App\Http\Controllers\Payroll\PayrollController::class);
$req = \Illuminate\Http\Request::create('/payroll-bank-wise-report', 'POST', [
    'month' => 'Aug', 'year' => 2026, 'type' => 'web',
]);
$session = new \Illuminate\Session\Store('scale', new \Illuminate\Session\ArraySessionHandler(120));
$session->put('sub_institute_id', $tenant);
$req->setLaravelSession($session);

$controller->payrollBankWiseReport($req);          // warm

$db->flushQueryLog();
$db->enableQueryLog();
$start = microtime(true);
$controller->payrollBankWiseReport($req);
$controllerMs = round((microtime(true) - $start) * 1000, 1);
$queries = count($db->getQueryLog());
$db->disableQueryLog();

$payslipCount = $db->table('employee_monthly_salary_data')
    ->where('sub_institute_id', $tenant)->where('month', 'Aug')->where('year', 2026)->count();

printf("  %s payslips decorated in %s queries, %s ms%s", $payslipCount, $queries, $controllerMs, PHP_EOL);

if ($queries > 10) {
    $slow['bank advice query count'] = $queries . ' queries for ' . $payslipCount . ' rows';
    echo '  PER-ROW LOOKUP IS BACK - this should be a small constant, not ~' . $payslipCount . PHP_EOL;
} else {
    echo '  Constant, not proportional to row count. Good.' . PHP_EOL;
}

echo PHP_EOL;
if ($slow) {
    echo 'WORTH ATTENTION:' . PHP_EOL;
    foreach ($slow as $label => $took) {
        printf("  %-34s %s%s", $label, $took, PHP_EOL);
    }
} else {
    echo 'Nothing measured above one second, and no per-row lookups.' . PHP_EOL;
}
