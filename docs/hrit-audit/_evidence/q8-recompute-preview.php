<?php
/**
 * Q8 preview. What the payroll calculation ACTUALLY returns today for each of
 * the six filed payslips.
 *
 *   php artisan tinker --execute="require '<abs>/Docs/hrit-audit/_evidence/q8-recompute-preview.php';"
 *
 * READ-ONLY. Writes nothing. It exists because the recompute figures in the
 * remediation plan could not be reproduced: getEmpMonthlyData resolves the
 * salary structure for (employee, year, tenant) and REFUSES when there is none,
 * and two of the six payslips have no structure for their year at all.
 *
 * No `use` statements: this is required into tinker's eval context, where they
 * are not at the top of a compilation unit.
 */

$controller = app(\App\Http\Controllers\Payroll\PayrollController::class);
$rows = \Illuminate\Support\Facades\DB::table('employee_monthly_salary_data')->orderBy('id')->get();

printf("%-4s %-7s %-5s %-9s %-11s %s\n", 'id', 'tenant', 'emp', 'period', 'stored_net', 'what the calculation returns now');
echo str_repeat('-', 104) . PHP_EOL;

foreach ($rows as $p) {
    $req = \Illuminate\Http\Request::create('/getMonthlyData', 'GET', [
        'emp_id'   => $p->employee_id,
        'month'    => $p->month,
        'year'     => $p->year,
        'totalDay' => $p->total_day,
    ]);

    // payrollTenantId() is token-first, session-fallback. No token here, so it
    // reads the session - the same path the Blade screen uses.
    $session = new \Illuminate\Session\Store('probe', new \Illuminate\Session\ArraySessionHandler(120));
    $session->put('sub_institute_id', $p->sub_institute_id);
    $session->put('syear', $p->year);
    $req->setLaravelSession($session);

    try {
        $res = $controller->getEmpMonthlyData($req);

        if (is_array($res) && (int) ($res['status_code'] ?? 1) === 0) {
            $out = 'REFUSED: ' . ($res['message'] ?? '?');
        } else {
            // The figures live under salaryData, not at the top level.
            $salary = (array) ($res['salaryData'] ?? []);
            $net = $salary['total_payment'] ?? null;
            $ded = $salary['total_deduction'] ?? null;

            if ($net === null) {
                $out = 'NO total_payment in response';
            } else {
                $delta = (float) $net - (float) $p->total_payment;
                $out = sprintf('would store %-10s (deduction %-6s)  change %+.2f',
                    number_format((float) $net, 2), (string) $ded, $delta);
            }
        }
    } catch (\Throwable $e) {
        $out = 'THREW ' . class_basename($e) . ': ' . substr($e->getMessage(), 0, 58);
    }

    printf("%-4s %-7s %-5s %-9s %-11s %s\n",
        $p->id, $p->sub_institute_id, $p->employee_id,
        $p->month . ' ' . $p->year, $p->total_payment, $out);
}
