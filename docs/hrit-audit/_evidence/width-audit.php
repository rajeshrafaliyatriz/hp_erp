<?php
/**
 * F-106, generalised. Find EVERY varchar in the HRIT tables that a controller
 * validates more loosely than the column can hold.
 *
 * One instance was reported by the audit. Fixing one line answers the report;
 * finding the class answers the defect.
 */
$env = [];
foreach (file('C:/Users/MILAN/Downloads/hp_erp/.env') as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    $parts = explode('=', $line, 2);
    if (count($parts) < 2) {
        continue;
    }
    $env[trim($parts[0])] = trim($parts[1], " \"'");
}

$pdo = new PDO(
    "mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_DATABASE']}",
    $env['DB_USERNAME'],
    $env['DB_PASSWORD']
);
$pdo->exec('SET NAMES utf8mb4');

$tables = [
    'hrms_leave_types', 'hrms_holidays', 'hrms_emp_leaves', 'hrms_attendances',
    'hrms_leave_allocation', 'hrms_leave_role_permissions', 'hrms_leave_workflow_settings',
    'hrms_attendance_regularisations', 'hrms_leave_approval_steps', 'payroll_types',
    'employee_salary_structures', 'hrms_emp_payroll_deduction', 'payroll_month_locks',
];

$in = "'" . implode("','", $tables) . "'";
$sql = "select table_name t, column_name c, character_maximum_length len
          from information_schema.columns
         where table_schema = '{$env['DB_DATABASE']}'
           and table_name in ($in)
           and data_type = 'varchar'";

$cols = [];
foreach ($pdo->query($sql) as $row) {
    $cols[$row['c']][] = [$row['t'], (int) $row['len']];
}

$dir = 'C:/Users/MILAN/Downloads/hp_erp/app/Http/Controllers';
$walker = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

$bad = [];
foreach ($walker as $file) {
    if ($file->isDir() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = str_replace('\\', '/', $file->getPathname());
    if (!preg_match('#/(Leave|Attendance|Payroll|HRMS)#i', $path)) {
        continue;
    }

    $src = file_get_contents($path);
    $found = preg_match_all("/'([a-z_]+)'\s*=>\s*'([^']*max:(\d+)[^']*)'/", $src, $matches, PREG_SET_ORDER);
    if (!$found) {
        continue;
    }

    foreach ($matches as $hit) {
        $field = $hit[1];
        $max   = (int) $hit[3];

        if (!isset($cols[$field])) {
            continue;
        }

        foreach ($cols[$field] as $pair) {
            [$table, $len] = $pair;
            if ($len > 0 && $max > $len) {
                $bad[] = sprintf(
                    '%-44s %-18s max:%-5s > %s.%s varchar(%d)',
                    basename($path), $field, $max, $table, $field, $len
                );
            }
        }
    }
}

$bad = array_values(array_unique($bad));
echo $bad === [] ? "no width mismatches found\n" : implode("\n", $bad) . "\n";
echo count($bad) . " mismatch(es)\n";
