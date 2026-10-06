<?php
/**
 * Scale, at the QUERY level — the companion probe-scale.sh promises at its head.
 *
 *   php artisan tinker --execute="require '<abs path>/Docs/hrit-audit/_evidence/probe-scale-query.php';"
 *
 * probe-scale.sh times ENDPOINTS, and can only reach tenants that have a token:
 * 3 (122 users) and 6 (939 attendance rows). The largest organisation on the
 * platform, tenant 1000000 with 1001 active users, has none - and minting one
 * would be an INSERT against a live tenant, which the scale work is not
 * entitled to do.
 *
 * So its numbers come from here instead: the same shapes the controllers run,
 * timed directly. Stated plainly because it matters when reading the results -
 * THESE ARE QUERY TIMINGS, NOT ENDPOINT TIMINGS. They exclude framework boot,
 * auth, serialisation and network.
 *
 * That distinction is not pedantry. Endpoint medians came back UNIFORM across
 * tenants of wildly different sizes (600-735 ms whether the tenant had 22 users
 * or 122), which means the endpoint number is dominated by fixed overhead and
 * cannot show how cost grows with data. The figures below can.
 *
 * Read-only. Every statement is a SELECT.
 */

$reps = 5;

/** median of $reps runs, in ms, plus the row count */
$time = function (string $label, string $sql, array $bind = []) use ($reps) {
    $times = []; $n = 0;
    for ($i = 0; $i < $reps; $i++) {
        $start = microtime(true);
        $rows  = DB::select($sql, $bind);
        $times[] = (microtime(true) - $start) * 1000;
        $n = count($rows);
    }
    sort($times);
    printf("  %-46s %6d rows   median %8.1f ms\n", $label, $n, $times[(int) floor($reps / 2)]);
    return $times[(int) floor($reps / 2)];
};

echo "\n";
echo "==============================================================================\n";
echo " HRIT scale - QUERY level (not endpoint level). median of $reps.\n";
echo "==============================================================================\n";

/* ---------------------------------------------------------------------------
 * A. The employee directory.
 *
 * EmployeeDirectoryController::index ends ->orderBy(first_name)->orderBy(last_name)
 * ->get() with NO pagination and NO LIMIT (F-145), so this IS the whole response
 * for that organisation. Two left joins, both already tenant-scoped in the join
 * condition.
 * ------------------------------------------------------------------------- */
echo "\n-- A. Employee directory, unbounded (F-145) ---------------------------------\n\n";

$directory = "select u.id, u.first_name, u.last_name, u.email, u.employee_no,
                     d.department, j.id as jobrole_id
                from tbluser u
                left join hrms_departments d
                       on u.department_id = d.id and d.sub_institute_id = ? and d.deleted_at is null
                left join s_user_jobrole j
                       on j.sub_institute_id = ? and j.deleted_at is null and j.id = u.jobtitle_id
               where u.sub_institute_id = ? and u.deleted_at is null
               order by u.first_name, u.last_name";

foreach ([6, 3, 7, 1000018, 1000000] as $t) {
    $time("tenant $t", $directory, [$t, $t, $t]);
}

echo "\n  Linear in headcount, and the query itself is healthy - one statement, no\n";
echo "  N+1 (the rows are returned directly, with no per-employee follow-up).\n";
echo "  The finding is the absence of a ceiling, not the cost at today's size.\n";

/* ---------------------------------------------------------------------------
 * B. Attendance. Tenant 6 holds 939 of the platform's ~994 rows.
 * ------------------------------------------------------------------------- */
echo "\n-- B. Attendance joined to the employee -------------------------------------\n\n";

$attendance = "select a.id, a.day, a.timestamp_diff, u.first_name
                 from hrms_attendances a
                 join tbluser u on u.id = a.user_id
                where a.sub_institute_id = ? and a.deleted_at is null";

foreach ([1000000, 3, 6] as $t) {
    $time("tenant $t", $attendance, [$t]);
}

/* ---------------------------------------------------------------------------
 * C. Leave and approvals - where the volume ISN'T.
 * ------------------------------------------------------------------------- */
echo "\n-- C. Leave register and the approval queue ---------------------------------\n\n";

$register = "select l.id, l.from_date, l.to_date, l.status, u.first_name, lt.leave_type
               from hrms_emp_leaves l
               join tbluser u on u.id = l.user_id
               left join hrms_leave_types lt on lt.id = l.leave_type_id
              where l.sub_institute_id = ? and l.deleted_at is null";

foreach ([3, 6, 1000000] as $t) {
    $time("leave register, tenant $t", $register, [$t]);
}

$queue = "select s.id, s.step_order, s.approver_role, s.status
            from hrms_leave_approval_steps s
            join hrms_emp_leaves l on l.id = s.leave_id
           where s.sub_institute_id = ? and s.status = 'pending' and l.deleted_at is null";

foreach ([3, 6] as $t) {
    $time("pending approval steps, tenant $t", $queue, [$t]);
}

/* ---------------------------------------------------------------------------
 * D. What this probe CANNOT answer. Stated, not omitted.
 * ------------------------------------------------------------------------- */
$maxLeave   = DB::selectOne("select count(*) c from hrms_emp_leaves where deleted_at is null
                             group by sub_institute_id order by c desc limit 1");
$maxPayroll = DB::selectOne("select count(*) c from employee_monthly_salary_data
                             group by sub_institute_id order by c desc limit 1");

echo "\n==============================================================================\n";
echo " THE LIMIT OF THIS MEASUREMENT\n";
echo "==============================================================================\n\n";
printf("  Largest leave register on the platform : %d rows\n", $maxLeave->c ?? 0);
printf("  Largest payroll month on the platform  : %d payslips\n", $maxPayroll->c ?? 0);
echo "\n";
echo "  Employee count and attendance rows ARE measurable here and the numbers are\n";
echo "  healthy. Leave, payroll and approvals are NOT: no organisation on this\n";
echo "  deployment has enough rows for a timing to mean anything.\n\n";
echo "  So the release gate's scale line stays PARTIAL. Marking it closed on a\n";
echo "  sample this small would repeat F-132 exactly - Monthly Payroll passed every\n";
echo "  check this project ran for eight sprints while returning two of 122 rows.\n";
echo "  A fast query over 13 rows proves nothing about 13,000.\n\n";
