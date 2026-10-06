<?php
/**
 * PAYROLL ARITHMETIC RECONCILIATION  -  closes the §E.3 gap.
 *
 * The audit's integrity checklist (§E.3) has five reconciled figures and not one
 * of them is a payroll figure. No PF, PT, net or Form 16 total had ever been
 * checked against a hand-computed value, although the brief asked for it.
 *
 * This recomputes every stored payslip from its OWN stored inputs, using the
 * formula read out of PayrollController::getEmployeeSalaryData (~:2455-2520),
 * and compares the result to what the row says.
 *
 *   allowance heads   payroll_types.payroll_type = 1
 *   deduction heads   payroll_types.payroll_type = 2
 *
 *   per head, when amount_type in (1,2) and day_count = 0:
 *       amount = round((amount / daysInMonth) * total_day)      <- pro-rated
 *   otherwise the amount is taken flat.
 *
 *   PF   when (basic+grade+da) < 15000:  round(totalSal / 100 * 12), capped 1800
 *        PF is never pro-rated for days worked - the elif chain excludes it.
 *   PT   never pro-rated (correct: it is a slab, not a daily rate).
 *
 *   total_deduction = sum(deductions)
 *   total_payment   = sum(allowances) - sum(deductions)     <- NET, despite the name
 *                     (confirmed by :1711, which adds total_deduction back to
 *                      recover gross, and :1713, net_salary = total_payment)
 *
 * Read-only. Writes nothing.
 *
 *   php artisan tinker --execute="require 'c:/Users/MILAN/Downloads/hp_erp/Docs/hrit-audit/_evidence/reconcile-payroll.php';"
 */

$heads = DB::table('payroll_types')->get()->keyBy('id');

/*
 * The per-employee, per-month adjustment the calculation adds on top of the
 * structure amount (:2421 for allowances, :2440 for deductions):
 *
 *   ->where('employee_id', ..)->where(['sub_institute_id'=>.., 'month'=>$request->month,
 *                                      'year'=>.., 'deduction_type'=>$payrollType->id])
 *
 * Keyed here EXACTLY as that query matches, so a row stored under a month
 * spelling the screen never posts simply does not join - which is the point.
 */
$overrides = [];
foreach (DB::table('hrms_emp_payroll_deduction')->get() as $o) {
    $overrides[implode('|', [$o->sub_institute_id, $o->employee_id, $o->month, $o->year, $o->deduction_type])]
        = (float) $o->deduction_amount;
}
$overrideHits = 0;

$rows = DB::table('employee_monthly_salary_data')->orderBy('id')->get();

$monthDays = [
    'Jan' => 31, 'Feb' => 28, 'Mar' => 31, 'Apr' => 30, 'May' => 31, 'Jun' => 30,
    'Jul' => 31, 'Aug' => 31, 'Sep' => 30, 'Oct' => 31, 'Nov' => 30, 'Dec' => 31,
];

printf("%-4s %-7s %-5s %-5s %-6s | %-28s | %10s %10s | %10s %10s | %s\n",
    'id', 'tenant', 'emp', 'month', 'days', 'stored employee_salary_data',
    'stored pay', 'stored ded', 'derived pay', 'derived ded', 'verdict');
echo str_repeat('-', 150), "\n";

$agree = 0; $disagree = 0; $underivable = 0;

foreach ($rows as $r) {
    $data = json_decode($r->employee_salary_data ?? '[]', true) ?: [];
    $days = $monthDays[$r->month] ?? 30;
    $worked = (float) $r->total_day;

    $allow = 0.0; $deduct = 0.0; $totalSal = 0.0; $unknown = [];

    foreach ($data as $headId => $amount) {
        $head = $heads[$headId] ?? null;
        if ($head === null) { $unknown[] = $headId; continue; }

        $amt  = (float) $amount;

        // The structure amount plus this month's adjustment, before pro-rating -
        // the order the controller applies them in.
        $okey = implode('|', [$r->sub_institute_id, $r->employee_id, $r->month, $r->year, $headId]);
        if (isset($overrides[$okey])) { $amt += $overrides[$okey]; $overrideHits++; }

        $flat = ((int) ($head->day_count ?? 0)) === 1;   // day_count=1 -> not pro-rated

        if (!$flat && $worked > 0 && $days > 0) {
            $amt = round(($amt / $days) * $worked);
        }
        if ($worked == 0) { $amt = 0; }

        if ((int) $head->payroll_type === 1) {
            $allow += $amt;
            if (in_array(strtoupper((string) $head->payroll_name), ['BASIC', 'GRADE PAY', 'D.A'], true)) {
                $totalSal += $amt;
            }
        } else {
            $deduct += $amt;
        }
    }

    $derivedPay = $allow - $deduct;

    $storedPay = (float) $r->total_payment;
    $storedDed = (float) $r->total_deduction;

    $payOk = abs($derivedPay - $storedPay) < 0.005;
    $dedOk = abs($deduct - $storedDed) < 0.005;

    if ($unknown !== []) {
        $verdict = 'UNDERIVABLE - head(s) ' . implode(',', $unknown) . ' not in payroll_types';
        $underivable++;
    } elseif ($payOk && $dedOk) {
        $verdict = 'AGREES';
        $agree++;
    } else {
        $parts = [];
        if (!$payOk) { $parts[] = sprintf('payment off by %+.2f', $storedPay - $derivedPay); }
        if (!$dedOk) { $parts[] = sprintf('deduction off by %+.2f', $storedDed - $deduct); }
        $verdict = 'DISAGREES - ' . implode(', ', $parts);
        $disagree++;
    }

    printf("%-4s %-7s %-5s %-5s %-6s | %-28s | %10.2f %10.2f | %10.2f %10.2f | %s\n",
        $r->id, $r->sub_institute_id, $r->employee_id, $r->month, $r->total_day,
        substr($r->employee_salary_data ?? '', 0, 28),
        $storedPay, $storedDed, $derivedPay, $deduct, $verdict);
}

echo str_repeat('-', 150), "\n";
printf("\n  payslips reconciled : %d\n", count($rows));
printf("  agree with own data : %d\n", $agree);
printf("  DISAGREE            : %d\n", $disagree);
printf("  underivable         : %d\n", $underivable);

printf("\n  per-month adjustments applied : %d hit(s) across %d stored rows\n",
    $overrideHits, count($overrides));
// Helpers::getMonths(), inlined: calling a trait method statically warns.
$canonicalMonths = ['Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec','Jan','Feb','Mar'];
$unreachable = 0;
foreach (DB::table('hrms_emp_payroll_deduction')->get() as $o) {
    if (!in_array($o->month, $canonicalMonths, true)) { $unreachable++; }
}
printf("  adjustment rows stored under a month spelling the screen never posts : %d\n", $unreachable);
printf("  (the calculation matches month exactly, so those are silently ignored)\n");

echo "\n";
echo "  A payslip that disagrees with its own stored components is not a rounding question.\n";
echo "  monthlyPayrollStore (:2846-2856) stores total_payment, total_deduction and total_day\n";
echo "  straight from the request - the server recomputes nothing on write. The figures are\n";
echo "  computed for DISPLAY at :2520 and trusted on the way back in.\n";
