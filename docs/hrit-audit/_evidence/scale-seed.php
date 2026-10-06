<?php
/**
 * STAGE 5. Seed a marked cohort into tenant 6 so HRIT can be measured at scale.
 *
 *   SCALE_EMPLOYEES=500 php artisan tinker --execute="require '<abs>/Docs/hrit-audit/_evidence/scale-seed.php';"
 *
 * WHY THIS EXISTS
 *   Scale is one of the release-gate lines and it cannot be closed with the
 *   data on this deployment: the largest real tenant has 90 leave rows and one
 *   payslip. The two big tenants - 1000000 "Sunrise" (1001 users) and 1000018
 *   "fibervalley" (963) - are real organisations with zero HRIT data, so
 *   seeding those is not acceptable.
 *
 *   Tenant 6 is "Scholar Clone Pvt. Ltd.", the customer's own organisation, and
 *   they chose it for this.
 *
 * EVERY ROW IS MARKED, AND REMOVABLE BY THAT MARKER
 *   users        email zzprobe.<n>@scale.invalid, first_name ZZPROBE
 *   department   "ZZPROBE Scale Test"
 *   leave        comment "ZZPROBE scale seed"
 *   payroll      structures and payslips for the seeded user ids only
 *
 *   `.invalid` is a reserved TLD (RFC 2606) and can never resolve, so no
 *   seeded address can reach a real inbox.
 *
 *   Teardown is BY MARKER, never by id - F-157's lesson, where a teardown
 *   keyed on ids deleted nothing when one id came back empty and left the suite
 *   permanently red. See scale-teardown.php.
 *
 * IDEMPOTENT. Re-running tops the cohort up to the requested size rather than
 * duplicating it.
 */

$target = (int) (getenv('SCALE_EMPLOYEES') ?: 500);
$tenant = 6;
$profileId = 17;            // tenant 6's `employee` profile
$leaveTypes = [8, 9];       // Casual Leave, Earned Leave
$marker = 'ZZPROBE';
$emailDomain = '@scale.invalid';

$db = \Illuminate\Support\Facades\DB::connection('mysql');

echo "Seeding tenant {$tenant} to {$target} probe employees" . PHP_EOL;

/* ---------------------------------------------------------------- department */
$deptId = $db->table('hrms_departments')
    ->where('sub_institute_id', $tenant)
    ->where('department', $marker . ' Scale Test')
    ->value('id');

if (!$deptId) {
    $deptId = $db->table('hrms_departments')->insertGetId([
        'department'       => $marker . ' Scale Test',
        'status'           => 1,
        'is_calculated'    => 0,
        'sub_institute_id' => $tenant,
        'created_at'       => now(),
    ]);
    echo "  department created: {$deptId}" . PHP_EOL;
} else {
    echo "  department reused: {$deptId}" . PHP_EOL;
}

/* -------------------------------------------------------------------- users */
$existing = $db->table('tbluser')
    ->where('sub_institute_id', $tenant)
    ->where('email', 'like', 'zzprobe.%' . $emailDomain)
    ->count();

$toCreate = max(0, $target - $existing);
echo "  users: {$existing} present, creating {$toCreate}" . PHP_EOL;

// One hash for the whole cohort - these accounts are never signed into, and
// hashing 500 times is slow for no benefit.
$password = \Illuminate\Support\Facades\Hash::make('probe-' . bin2hex(random_bytes(8)));

$batch = [];
for ($i = $existing + 1; $i <= $target; $i++) {
    $batch[] = [
        'first_name'       => $marker,
        'last_name'        => 'Employee ' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
        'email'            => 'zzprobe.' . $i . $emailDomain,
        'password'         => $password,
        'employee_no'      => 'ZZP-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
        'user_profile_id'  => $profileId,
        'department_id'    => $deptId,
        'status'           => 1,
        'sub_institute_id' => $tenant,
        'created_at'       => now(),
    ];

    if (count($batch) >= 200) {
        $db->table('tbluser')->insert($batch);
        $batch = [];
    }
}
if ($batch) {
    $db->table('tbluser')->insert($batch);
}

$userIds = $db->table('tbluser')
    ->where('sub_institute_id', $tenant)
    ->where('email', 'like', 'zzprobe.%' . $emailDomain)
    ->pluck('id')
    ->all();

echo '  users now: ' . count($userIds) . PHP_EOL;

/* -------------------------------------------------------------------- leave */
$leaveWanted = count($userIds) * 6;   // six requests each
$leaveHave = $db->table('hrms_emp_leaves')
    ->where('sub_institute_id', $tenant)
    ->where('comment', $marker . ' scale seed')
    ->count();

echo "  leave: {$leaveHave} present, target {$leaveWanted}" . PHP_EOL;

if ($leaveHave < $leaveWanted) {
    $statuses = ['pending', 'approved', 'rejected', 'cancelled', 'approved', 'approved'];
    $rows = [];
    $made = 0;

    foreach ($userIds as $index => $userId) {
        for ($n = 0; $n < 6; $n++) {
            if ($leaveHave + $made >= $leaveWanted) {
                break 2;
            }

            // Spread across the year deterministically - no randomness, so a
            // re-run produces the same shape and timings are comparable.
            $start = \Carbon\Carbon::create(2026, 1, 1)->addDays((($index * 7) + ($n * 53)) % 330);

            $rows[] = [
                'sub_institute_id' => $tenant,
                'department_id'    => $deptId,
                'user_id'          => $userId,
                'leave_type_id'    => $leaveTypes[$n % count($leaveTypes)],
                'day_type'         => 'full',
                'from_date'        => $start->toDateString(),
                'to_date'          => $start->copy()->addDay()->toDateString(),
                'chargeable_days'  => 2,
                'comment'          => $marker . ' scale seed',
                'status'           => $statuses[$n % count($statuses)],
                'created_at'       => now(),
                'updated_at'       => now(),
            ];
            $made++;

            if (count($rows) >= 500) {
                $db->table('hrms_emp_leaves')->insert($rows);
                $rows = [];
            }
        }
    }
    if ($rows) {
        $db->table('hrms_emp_leaves')->insert($rows);
    }
    echo "  leave created: {$made}" . PHP_EOL;
}

/* ------------------------------------------------------- payroll structures */
$activeHeads = $db->table('payroll_types')
    ->where('sub_institute_id', $tenant)
    ->where('status', 1)
    ->pluck('id')
    ->all();

if (!$activeHeads) {
    echo '  payroll: tenant has no active pay heads - structures and payslips SKIPPED' . PHP_EOL;
} else {
    $structureData = json_encode(array_fill_keys(array_map('strval', $activeHeads), 5000));

    $haveStructures = $db->table('employee_salary_structures')
        ->where('sub_institute_id', $tenant)
        ->whereIn('employee_id', $userIds)
        ->count();

    echo "  salary structures: {$haveStructures} present" . PHP_EOL;

    if ($haveStructures < count($userIds)) {
        $withStructure = $db->table('employee_salary_structures')
            ->where('sub_institute_id', $tenant)
            ->whereIn('employee_id', $userIds)
            ->pluck('employee_id')
            ->all();

        $rows = [];
        foreach (array_diff($userIds, $withStructure) as $userId) {
            $rows[] = [
                'employee_id'          => $userId,
                'employee_salary_data' => $structureData,
                'year'                 => 2026,
                'sub_institute_id'     => $tenant,
                'created_at'           => now(),
                'updated_at'           => now(),
            ];
            if (count($rows) >= 200) {
                $db->table('employee_salary_structures')->insert($rows);
                $rows = [];
            }
        }
        if ($rows) {
            $db->table('employee_salary_structures')->insert($rows);
        }
        echo '  salary structures created: ' . (count($userIds) - $haveStructures) . PHP_EOL;
    }

    /* ------------------------------------------------------------ payslips */
    $havePayslips = $db->table('employee_monthly_salary_data')
        ->where('sub_institute_id', $tenant)
        ->whereIn('employee_id', $userIds)
        ->where('month', 'Aug')
        ->where('year', 2026)
        ->count();

    echo "  payslips (Aug 2026): {$havePayslips} present" . PHP_EOL;

    if ($havePayslips < count($userIds)) {
        $withPayslip = $db->table('employee_monthly_salary_data')
            ->where('sub_institute_id', $tenant)
            ->whereIn('employee_id', $userIds)
            ->where('month', 'Aug')->where('year', 2026)
            ->pluck('employee_id')
            ->all();

        $gross = 5000 * count($activeHeads);
        $rows = [];
        foreach (array_diff($userIds, $withPayslip) as $userId) {
            $rows[] = [
                'sub_institute_id'     => $tenant,
                'employee_id'          => $userId,
                'month'                => 'Aug',
                'year'                 => 2026,
                'total_day'            => 31,
                'total_deduction'      => 0,
                'total_payment'        => $gross,
                'employee_salary_data' => $structureData,
                'received_by'          => 'Bank Transfer',
                'created_at'           => now(),
                'updated_at'           => now(),
            ];
            if (count($rows) >= 200) {
                $db->table('employee_monthly_salary_data')->insert($rows);
                $rows = [];
            }
        }
        if ($rows) {
            $db->table('employee_monthly_salary_data')->insert($rows);
        }
        echo '  payslips created: ' . (count($userIds) - $havePayslips) . PHP_EOL;
    }
}

echo PHP_EOL . 'DONE. Remove it all with:' . PHP_EOL;
echo '  php artisan tinker --execute="require \'' . base_path() . '/Docs/hrit-audit/_evidence/scale-teardown.php\';"' . PHP_EOL;
