<?php
/**
 * Q8 step A. Create the two salary structures the recompute needs.
 *
 *   php artisan tinker --execute="require '<abs>/Docs/hrit-audit/_evidence/q8-create-structures.php';"
 *
 * Two of the six filed payslips have no salary structure for their year, so
 * getEmpMonthlyData refuses them outright with "Salary Structure Not Found !!":
 *
 *   payslip 5   tenant 1, employee 1, Jul 2026   (structures exist for 2024, 2025 only)
 *   payslip 22  tenant 3, employee 6, Aug 2025   (this employee has none at all)
 *
 * NOTHING IS INVENTED HERE. Each structure is built from its own payslip's
 * stored `employee_salary_data`, verbatim - that is what the payslip was
 * actually paid against, and it is the only defensible source. Choosing
 * amounts myself would be guessing at money.
 *
 * Idempotent, and applied to BOTH hosts because their payroll tables are
 * byte-identical. Reversal:
 * Docs/hrit-audit/_reversals/REVERSAL-2026-09-19-q8-recompute.sql
 */

$targets = [
    ['payslip' => 5,  'tenant' => 1, 'employee' => 1, 'year' => 2026],
    ['payslip' => 22, 'tenant' => 3, 'employee' => 6, 'year' => 2025],
];

foreach (['mysql', 'live'] as $connection) {
    $db = \Illuminate\Support\Facades\DB::connection($connection);
    echo "=== {$connection} ===" . PHP_EOL;

    foreach ($targets as $t) {
        $exists = $db->table('employee_salary_structures')
            ->where(['employee_id' => $t['employee'], 'year' => $t['year'], 'sub_institute_id' => $t['tenant']])
            ->exists();

        if ($exists) {
            echo "  emp {$t['employee']} / {$t['year']} / tenant {$t['tenant']}: already present, left alone" . PHP_EOL;
            continue;
        }

        $payslip = $db->table('employee_monthly_salary_data')->where('id', $t['payslip'])->first();

        if (!$payslip) {
            echo "  payslip {$t['payslip']}: NOT FOUND on this host - skipped" . PHP_EOL;
            continue;
        }

        // Verbatim. The payslip's own components, normalised only from string
        // to number so the JSON matches the shape every other structure uses.
        $components = json_decode($payslip->employee_salary_data, true) ?: [];
        $normalised = [];
        foreach ($components as $headId => $amount) {
            $normalised[(string) $headId] = is_numeric($amount) ? 0 + $amount : 0;
        }

        $db->table('employee_salary_structures')->insert([
            'employee_id'          => $t['employee'],
            'employee_salary_data' => json_encode($normalised),
            'year'                 => $t['year'],
            'sub_institute_id'     => $t['tenant'],
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        echo "  emp {$t['employee']} / {$t['year']} / tenant {$t['tenant']}: created from payslip "
           . "{$t['payslip']} -> " . json_encode($normalised) . PHP_EOL;
    }

    echo "  structures now: " . $db->table('employee_salary_structures')->count() . PHP_EOL;
}
