<?php
/**
 * STAGE 5. Remove everything scale-seed.php created from tenant 6.
 *
 *   php artisan tinker --execute="require '<abs>/Docs/hrit-audit/_evidence/scale-teardown.php';"
 *
 * BY MARKER, NEVER BY ID.
 *
 * That is F-157's lesson, and it cost a whole phase to learn: probe-sprint6
 * tore down by id, one id came back empty, `id in (424,425,)` was a SQL syntax
 * ERROR, the delete silently removed nothing, and the leftovers then poisoned
 * every later run. A marker cannot be empty and cannot be malformed.
 *
 * The markers, each of which only this seeder writes:
 *   users        email LIKE 'zzprobe.%@scale.invalid'
 *   department   department = 'ZZPROBE Scale Test'
 *   leave        comment = 'ZZPROBE scale seed'
 *   payroll      employee_id IN (the marked users)
 *
 * Deletion order matters: payroll and leave reference users, so they go first.
 * The user rows are the marker's anchor, so they go last - if this script is
 * interrupted, the anchor survives and a re-run finishes the job.
 *
 * SAFE TO RUN WHEN NOTHING IS SEEDED. Every step reports 0 and changes nothing.
 */

$tenant = 6;
$marker = 'ZZPROBE';
$emailLike = 'zzprobe.%@scale.invalid';

$db = \Illuminate\Support\Facades\DB::connection('mysql');

$userIds = $db->table('tbluser')
    ->where('sub_institute_id', $tenant)
    ->where('email', 'like', $emailLike)
    ->pluck('id')
    ->all();

echo 'Tearing down the scale cohort in tenant ' . $tenant . PHP_EOL;
echo '  marked users found: ' . count($userIds) . PHP_EOL;

if ($userIds) {
    foreach (array_chunk($userIds, 500) as $chunk) {
        $n = $db->table('employee_monthly_salary_data')
            ->where('sub_institute_id', $tenant)->whereIn('employee_id', $chunk)->delete();
        echo '  payslips removed:           ' . $n . PHP_EOL;

        $n = $db->table('employee_salary_structures')
            ->where('sub_institute_id', $tenant)->whereIn('employee_id', $chunk)->delete();
        echo '  salary structures removed:  ' . $n . PHP_EOL;

        // Approval steps reference the leave rows, so they go before them.
        $leaveIds = $db->table('hrms_emp_leaves')
            ->where('sub_institute_id', $tenant)->whereIn('user_id', $chunk)->pluck('id')->all();

        if ($leaveIds) {
            $db->table('hrms_leave_approval_steps')->whereIn('leave_id', $leaveIds)->delete();
        }

        $n = $db->table('hrms_emp_leaves')
            ->where('sub_institute_id', $tenant)->whereIn('user_id', $chunk)->delete();
        echo '  leave removed:              ' . $n . PHP_EOL;

        $n = $db->table('hrms_attendances')
            ->where('sub_institute_id', $tenant)->whereIn('user_id', $chunk)->delete();
        echo '  attendance removed:         ' . $n . PHP_EOL;
    }
}

// Belt and braces: anything still carrying the leave comment, in case a user
// row was removed by hand first and orphaned its leave.
$n = $db->table('hrms_emp_leaves')
    ->where('sub_institute_id', $tenant)
    ->where('comment', $marker . ' scale seed')
    ->delete();
echo '  leave removed by comment:   ' . $n . PHP_EOL;

// The anchor, last.
$n = $db->table('tbluser')
    ->where('sub_institute_id', $tenant)
    ->where('email', 'like', $emailLike)
    ->delete();
echo '  users removed:              ' . $n . PHP_EOL;

$n = $db->table('hrms_departments')
    ->where('sub_institute_id', $tenant)
    ->where('department', $marker . ' Scale Test')
    ->delete();
echo '  department removed:         ' . $n . PHP_EOL;

echo PHP_EOL . 'Tenant ' . $tenant . ' now: '
    . 'users=' . $db->table('tbluser')->where('sub_institute_id', $tenant)->count()
    . '  depts=' . $db->table('hrms_departments')->where('sub_institute_id', $tenant)->count()
    . '  leave=' . $db->table('hrms_emp_leaves')->where('sub_institute_id', $tenant)->count()
    . '  payslips=' . $db->table('employee_monthly_salary_data')->where('sub_institute_id', $tenant)->count()
    . '  structures=' . $db->table('employee_salary_structures')->where('sub_institute_id', $tenant)->count()
    . PHP_EOL;

echo 'Baseline before any seeding was: users=23  depts=50  leave=5  payslips=0  structures=0' . PHP_EOL;
