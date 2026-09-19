<?php
/**
 * Q8 step B. Rewrite each filed payslip to what the calculation produces.
 *
 *   DRY=1 php artisan tinker --execute="require '<abs>/Docs/hrit-audit/_evidence/q8-recompute-apply.php';"
 *         php artisan tinker --execute="require '<abs>/Docs/hrit-audit/_evidence/q8-recompute-apply.php';"
 *
 * Set DRY=1 in the environment to report and write nothing.
 *
 * WHAT THIS DOES NOT DO, AND WHY
 *
 * It refuses any payslip whose recomputed net pay is negative. Payslip 22
 * recomputes to -3,482: tenant 3 has "HRA" and "daycount1" configured as
 * DEDUCTIONS (payroll_type = 2), so the structure's 10,000 on head 4 becomes a
 * 9,677 deduction against 6,200 of earnings. That is a pay-head configuration
 * problem, and a negative number is not a payslip. Writing it would replace one
 * wrong figure with a more obviously wrong one and call it a correction.
 *
 * It also skips payslips whose stored value already equals the calculation -
 * three of the six - rather than rewriting them to themselves and emitting a
 * supersession event that records no change.
 *
 * BOTH HOSTS. employee_monthly_salary_data is byte-identical on mysql and live,
 * so a change to one alone would make them diverge.
 *
 * Every write is preceded by a `payroll.payslip.superseded` event carrying the
 * complete before-image - the same event and the same shape the deduplication
 * path already uses (PayrollController ~3437). g2g_event is append-only, and
 * AuditLogProjector picks it up on the next events:project run.
 *
 * Before-image: Docs/hrit-audit/_evidence/before-q8.json
 * Reversal:     Docs/hrit-audit/_reversals/REVERSAL-2026-09-19-q8-recompute.sql
 */

$dry = (bool) getenv('DRY');
$controller = app(\App\Http\Controllers\Payroll\PayrollController::class);

echo $dry ? "DRY RUN - nothing will be written" : "APPLYING";
echo PHP_EOL . PHP_EOL;

foreach (['mysql', 'live'] as $connection) {
    $db = \Illuminate\Support\Facades\DB::connection($connection);
    echo "=== {$connection} ===" . PHP_EOL;

    $payslips = $db->table('employee_monthly_salary_data')->orderBy('id')->get();

    foreach ($payslips as $p) {
        $label = sprintf('payslip %-3s t%-2s emp%-3s %s %s', $p->id, $p->sub_institute_id, $p->employee_id, $p->month, $p->year);

        $req = \Illuminate\Http\Request::create('/getMonthlyData', 'GET', [
            'emp_id'   => $p->employee_id,
            'month'    => $p->month,
            'year'     => $p->year,
            'totalDay' => $p->total_day,
        ]);

        // payrollTenantId() is token-first, session-fallback; no token here.
        $session = new \Illuminate\Session\Store('q8', new \Illuminate\Session\ArraySessionHandler(120));
        $session->put('sub_institute_id', $p->sub_institute_id);
        $session->put('syear', $p->year);
        $req->setLaravelSession($session);

        try {
            $res = $controller->getEmpMonthlyData($req);
        } catch (\Throwable $e) {
            echo "  {$label}  SKIPPED - calculation threw: " . substr($e->getMessage(), 0, 60) . PHP_EOL;
            continue;
        }

        if (is_array($res) && (int) ($res['status_code'] ?? 1) === 0) {
            echo "  {$label}  SKIPPED - " . ($res['message'] ?? 'refused') . PHP_EOL;
            continue;
        }

        $salary = (array) ($res['salaryData'] ?? []);

        if (!array_key_exists('total_payment', $salary)) {
            echo "  {$label}  SKIPPED - no total_payment in the response" . PHP_EOL;
            continue;
        }

        $newNet = (float) $salary['total_payment'];
        $newDed = (float) ($salary['total_deduction'] ?? 0);
        $oldNet = (float) $p->total_payment;

        // A negative payslip is not a correction.
        if ($newNet < 0) {
            printf("  %s  REFUSED - recomputes to %s, which is not a payslip%s",
                $label, number_format($newNet, 2),
                PHP_EOL . "      (tenant {$p->sub_institute_id} has deduction-type heads exceeding its earnings;"
                . " fix the pay heads, then re-run)" . PHP_EOL);
            continue;
        }

        if (abs($newNet - $oldNet) < 0.005 && abs($newDed - (float) $p->total_deduction) < 0.005) {
            printf("  %s  unchanged at %s%s", $label, number_format($oldNet, 2), PHP_EOL);
            continue;
        }

        // The components, without the two totals the calculation appends.
        $components = $salary;
        unset($components['total_payment'], $components['total_deduction']);

        printf("  %s  %s -> %s (deduction %s -> %s)%s",
            $label, number_format($oldNet, 2), number_format($newNet, 2),
            number_format((float) $p->total_deduction, 2), number_format($newDed, 2),
            $dry ? '   [dry]' . PHP_EOL : PHP_EOL);

        if ($dry) {
            continue;
        }

        $before = (array) $p;

        $db->transaction(function () use ($db, $p, $before, $components, $newNet, $newDed, $connection) {
            try {
                // Recorded BEFORE the write, so a failure to record is a failure
                // to change. The trace is the point of the change.
                app(\App\Services\Events\EventRecorder::class)->record(
                    'payroll.payslip.superseded',
                    (int) $p->sub_institute_id,
                    'employee_monthly_salary_data',
                    (int) $p->id,
                    null,                       // no acting user: this is a maintenance correction
                    [
                        'reason'      => 'Q8 recompute - stored payslip did not match the calculation',
                        'employee_id' => (int) $p->employee_id,
                        'month'       => $p->month,
                        'year'        => (int) $p->year,
                        'host'        => $connection,
                        'superseded'  => [$before],
                        'replaced_by' => [
                            'employee_salary_data' => $components,
                            'total_payment'        => $newNet,
                            'total_deduction'      => $newDed,
                        ],
                    ],
                    null,
                    'q8.recompute:' . $connection . ':' . $p->id
                );
            } catch (\Throwable $e) {
                // Loud, and fatal to this row's change.
                throw new \RuntimeException('Supersession event not recorded: ' . $e->getMessage(), 0, $e);
            }

            $db->table('employee_monthly_salary_data')->where('id', $p->id)->update([
                'employee_salary_data' => json_encode($components),
                'total_payment'        => $newNet,
                'total_deduction'      => $newDed,
                'updated_at'           => now(),
            ]);
        });
    }
}
