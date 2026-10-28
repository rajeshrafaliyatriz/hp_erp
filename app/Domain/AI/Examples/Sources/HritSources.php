<?php

namespace App\Domain\AI\Examples\Sources;

use App\Services\Ai\AiRequestScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Hrit module's data sources and page examples. See SourceGroup for the rules every source follows.
 *
 * `hrms.leave_requests` and `hrms.attendance` already live in ModuleDataSourceCatalog and are reused here
 * by name. Payroll sources are deliberately aggregates (per period / payment mode / year): they never list
 * one person's salary and never touch bank, PAN, Aadhaar or UAN fields. The single per-person pay source,
 * `hrms.my_payslips`, only ever returns the caller's own rows (pay is read from `$scope->userId`, never an argument).
 */
final class HritSources extends SourceGroup
{
    public function definitions(): array
    {
        $limitArg = self::limitArg();

        return [
            [
                'name' => 'hrms.leave_types',
                'module' => 'hrit_management',
                'label' => 'Leave types',
                'description' => 'The leave types the organisation offers (casual, sick, paid...), with their code, whether unused days carry forward and whether the type is active.',
                'arguments' => [
                    self::arg('status', 'integer', '1 for active leave types, 0 for inactive.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('hrms_leave_types as t')
                        ->where('t.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('t.deleted_at')
                        ->select(['t.id as leave_type_pk', 't.leave_type_id as code', 't.leave_type', 't.carry_forward', 't.status', 't.sort_order'])
                        ->orderBy('t.sort_order')->orderBy('t.leave_type');

                    if (isset($a['status']) && is_numeric($a['status'])) {
                        $q->where('t.status', (int) $a['status']);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'hrms.holidays',
                'module' => 'hrit_management',
                'label' => 'Holiday calendar',
                'description' => 'Company holidays with the name, date range, whether it is a full or half day and which department it applies to.',
                'arguments' => [
                    self::arg('from_date', 'string', 'Only holidays ending on or after this date (YYYY-MM-DD).'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('hrms_holidays as h')
                        ->where('h.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('h.deleted_at')
                        ->select(['h.id as holiday_id', 'h.holiday_name', 'h.from_date', 'h.to_date', 'h.day_type', 'h.department', 'h.description'])
                        ->orderBy('h.from_date');

                    if (($f = $this->text($a, 'from_date')) !== null) {
                        $q->where('h.to_date', '>=', $f);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'hrms.weekly_off',
                'module' => 'hrit_management',
                'label' => 'Weekly working pattern',
                'description' => 'For each day of the week, whether it is a full working day, a half day or a weekly off for the organisation.',
                'arguments' => [$limitArg],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    return DB::table('hrms_weekdays as w')
                        ->where('w.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('w.deleted_at')
                        ->select(['w.day', 'w.day_type'])
                        ->orderBy('w.id');
                },
            ],
            [
                'name' => 'hrms.leave_allocations',
                'module' => 'hrit_management',
                'label' => 'Leave entitlement by type',
                'description' => 'Yearly leave entitlement per leave type: how many employees hold an allocation and the total days granted.',
                'arguments' => [
                    self::arg('year', 'string', 'Only this allocation year, e.g. 2026.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('hrms_leave_allocation as a')
                        ->leftJoin('hrms_leave_types as t', 't.id', '=', 'a.leave_type_id')
                        ->where('a.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('a.deleted_at')
                        ->groupBy('a.year', 'a.leave_type_id', 't.leave_type')
                        ->select(['a.year', 't.leave_type'])
                        ->selectRaw('count(distinct a.employee_id) as employees')
                        ->selectRaw('sum(a.value) as total_days_granted')
                        ->selectRaw('round(avg(a.value), 2) as average_days_per_employee')
                        ->orderByDesc('a.year')->orderBy('t.leave_type');

                    if (($y = $this->text($a, 'year')) !== null) {
                        $q->where('a.year', $y);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'hrms.leave_access_roles',
                'module' => 'hrit_management',
                'label' => 'Leave roles and access',
                'description' => 'Which roles can approve leave, view leave reports, change leave settings, run bulk operations or escalate, and the scope each role covers.',
                'arguments' => [$limitArg],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    return DB::table('hrms_leave_role_permissions as r')
                        ->where('r.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('r.deleted_at')
                        ->select(['r.role_name', 'r.scope', 'r.approve_leave', 'r.view_reports', 'r.configure_settings', 'r.bulk_operations', 'r.escalation_rights', 'r.status'])
                        ->orderBy('r.sort_order');
                },
            ],
            [
                'name' => 'hrms.leave_workflow',
                'module' => 'hrit_management',
                'label' => 'Leave approval workflow',
                'description' => 'How leave requests are approved: whether the reporting manager, department head or HR approve, how many levels, and when an unanswered request escalates.',
                'arguments' => [$limitArg],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    return DB::table('hrms_leave_workflow_settings as s')
                        ->where('s.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('s.deleted_at')
                        ->select([
                            's.reporting_manager_enabled', 's.department_head_enabled', 's.hr_enabled',
                            's.multi_level_enabled', 's.multi_level_count',
                            's.escalation_enabled', 's.escalation_time', 's.escalation_unit', 's.escalate_to',
                        ]);
                },
            ],
            [
                'name' => 'hrms.attendance_monthly',
                'module' => 'hrit_management',
                'label' => 'Attendance by month',
                'description' => 'Month-by-month attendance totals: how many employees punched in, how many daily records, office versus other work modes, and records with no punch-out.',
                'arguments' => [
                    self::arg('from_month', 'string', 'Only months on or after this month (YYYY-MM).'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('hrms_attendances as a')
                        ->where('a.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('a.deleted_at')
                        ->groupByRaw("DATE_FORMAT(a.day, '%Y-%m')")
                        ->selectRaw("DATE_FORMAT(a.day, '%Y-%m') as month")
                        ->selectRaw('count(distinct a.user_id) as employees')
                        ->selectRaw('count(*) as day_records')
                        ->selectRaw("sum(lower(coalesce(a.work_mode, '')) = 'office') as office_records")
                        ->selectRaw("sum(lower(coalesce(a.work_mode, '')) <> 'office') as other_mode_records")
                        ->selectRaw('sum(a.punchout_time is null) as without_punch_out')
                        ->orderByDesc('month');

                    if (($m = $this->text($a, 'from_month')) !== null) {
                        $q->havingRaw("DATE_FORMAT(min(a.day), '%Y-%m') >= ?", [$m]);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'hrms.attendance_edits',
                'module' => 'hrit_management',
                'label' => 'Attendance corrections',
                'description' => 'Corrections made to attendance records by an administrator: the employee, the day, the punch times before and after, and the reason given.',
                'arguments' => [
                    self::arg('user_id', 'integer', 'Only this employee\'s corrections.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('hrms_attendance_edits as e')
                        ->leftJoin('tbluser as u', 'u.id', '=', 'e.user_id')
                        ->where('e.sub_institute_id', $scope->selectedInstituteId)
                        ->select(['e.id as edit_id', 'e.day', 'e.before_in_time', 'e.before_out_time', 'e.after_in_time', 'e.after_out_time', 'e.reason', 'e.source'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as employee")
                        ->orderByDesc('e.id');

                    if (($u = $this->int($a, 'user_id')) !== null) {
                        $q->where('e.user_id', $u);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'hrms.attendance_regularisations',
                'module' => 'hrit_management',
                'label' => 'Attendance regularisation requests',
                'description' => 'Requests from employees to correct a missed or wrong punch: the day, the times asked for, the reason and whether it is pending, approved or rejected.',
                'arguments' => [
                    self::arg('status', 'string', 'Request status as stored, e.g. pending.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('hrms_attendance_regularisations as r')
                        ->leftJoin('tbluser as u', 'u.id', '=', 'r.user_id')
                        ->where('r.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('r.deleted_at')
                        ->select(['r.id as request_id', 'r.day', 'r.requested_in_time', 'r.requested_out_time', 'r.reason', 'r.status'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as employee")
                        ->orderByDesc('r.day');

                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('r.status', $s);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'hrms.payroll_types',
                'module' => 'hrit_management',
                'label' => 'Payroll components',
                'description' => 'The pay components the organisation uses (basic, allowances, deductions) with whether each is an earning or a deduction, fixed or percentage, and the percentage.',
                'arguments' => [
                    self::arg('status', 'integer', '1 for active components, 0 for inactive.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('payroll_types as p')
                        ->where('p.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('p.deleted_at')
                        ->select(['p.id as component_id', 'p.payroll_name as component', 'p.payroll_type', 'p.amount_type', 'p.payroll_percentage', 'p.day_count', 'p.status'])
                        ->orderBy('p.sort_order');

                    if (isset($a['status']) && is_numeric($a['status'])) {
                        $q->where('p.status', (int) $a['status']);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'hrms.salary_structures',
                'module' => 'hrit_management',
                'label' => 'Salary structure coverage',
                'description' => 'For each year, how many employees have a salary structure set up and when it was last updated. Amounts are not listed.',
                'arguments' => [$limitArg],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    return DB::table('employee_salary_structures as s')
                        ->where('s.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('s.deleted_at')
                        ->groupBy('s.year')
                        ->select(['s.year'])
                        ->selectRaw('count(distinct s.employee_id) as employees_with_structure')
                        ->selectRaw('max(coalesce(s.updated_at, s.created_at)) as last_updated')
                        ->orderByDesc('s.year');
                },
            ],
            [
                'name' => 'hrms.payroll_monthly',
                'module' => 'hrit_management',
                'label' => 'Monthly payroll totals',
                'description' => 'Payroll run totals per month: how many employees were paid, the total paid, the total deducted and the average days counted. No individual salaries.',
                'arguments' => [
                    self::arg('year', 'string', 'Only this payroll year, e.g. 2026.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('employee_monthly_salary_data as m')
                        ->where('m.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('m.deleted_at')
                        ->groupBy('m.year', 'm.month')
                        ->select(['m.year', 'm.month'])
                        ->selectRaw('count(distinct m.employee_id) as employees_paid')
                        ->selectRaw('sum(m.total_payment) as total_paid')
                        ->selectRaw('sum(m.total_deduction) as total_deducted')
                        ->selectRaw('round(avg(m.total_day), 1) as average_days')
                        ->orderByDesc('m.year')
                        ->orderByRaw('max(m.created_at) desc');

                    if (($y = $this->text($a, 'year')) !== null) {
                        $q->where('m.year', $y);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'hrms.payroll_by_payment_mode',
                'module' => 'hrit_management',
                'label' => 'Payroll by payment mode',
                'description' => 'Payroll totals per month split by how salary was paid (bank transfer, self, and so on): employees and total paid. No account details.',
                'arguments' => [
                    self::arg('year', 'string', 'Only this payroll year, e.g. 2026.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('employee_monthly_salary_data as m')
                        ->where('m.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('m.deleted_at')
                        ->groupBy('m.year', 'm.month', 'm.received_by')
                        ->select(['m.year', 'm.month'])
                        ->selectRaw("coalesce(nullif(m.received_by, ''), 'Not recorded') as payment_mode")
                        ->selectRaw('count(distinct m.employee_id) as employees')
                        ->selectRaw('sum(m.total_payment) as total_paid')
                        ->orderByDesc('m.year')
                        ->orderByRaw('max(m.created_at) desc');

                    if (($y = $this->text($a, 'year')) !== null) {
                        $q->where('m.year', $y);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'hrms.payroll_deductions',
                'module' => 'hrit_management',
                'label' => 'Payroll deductions',
                'description' => 'Extra deductions entered for payroll, totalled per month and deduction type: how many employees and the total amount. No per-person amounts.',
                'arguments' => [
                    self::arg('year', 'string', 'Only this year, e.g. 2025.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('hrms_emp_payroll_deduction as d')
                        ->leftJoin('payroll_types as p', 'p.id', '=', 'd.deduction_type')
                        ->where('d.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('d.deleted_at')
                        ->groupBy('d.year', 'd.month', 'd.deduction_type', 'p.payroll_name')
                        ->select(['d.year', 'd.month', 'p.payroll_name as deduction'])
                        ->selectRaw('count(distinct d.employee_id) as employees')
                        ->selectRaw('sum(d.deduction_amount) as total_amount')
                        ->orderByDesc('d.year')->orderByDesc('d.month');

                    if (($y = $this->text($a, 'year')) !== null) {
                        $q->where('d.year', $y);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'hrms.salary_certificates',
                'module' => 'hrit_management',
                'label' => 'Salary certificates issued',
                'description' => 'How many salary certificates have been generated, per year and month. The certificate text itself is not included.',
                'arguments' => [$limitArg],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    return DB::table('hrms_salary_certificate as c')
                        ->where('c.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('c.deleted_at')
                        ->groupBy('c.year', 'c.month')
                        ->select(['c.year', 'c.month'])
                        ->selectRaw('count(*) as certificates')
                        ->selectRaw('count(distinct c.employee_id) as employees')
                        ->orderByDesc('c.year');
                },
            ],
            [
                'name' => 'hrms.my_payslips',
                'module' => 'hrit_management',
                'label' => 'My payslips',
                'description' => 'The signed-in employee\'s own monthly pay: month, days counted, total paid and total deducted. Only ever the caller\'s own rows.',
                'arguments' => [$limitArg],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    return DB::table('employee_monthly_salary_data as m')
                        ->where('m.sub_institute_id', $scope->selectedInstituteId)
                        ->where('m.employee_id', $scope->userId)
                        ->whereNull('m.deleted_at')
                        ->select(['m.year', 'm.month', 'm.total_day', 'm.total_payment', 'm.total_deduction'])
                        ->orderByDesc('m.year')
                        ->orderByDesc('m.created_at');
                },
            ],
        ];
    }

    public function pages(): array
    {
        $base = '/module/hrit-solutions/';

        return [
            $base . 'attendance-management' => [
                'sources' => ['hrms.attendance_monthly', 'hrms.attendance'],
                'purpose' => 'Entry point for attendance: tracking, reports, the monthly report and corrections.',
                'action' => null,
            ],
            $base . 'attendance-management/attendance-tracking' => [
                'sources' => ['hrms.attendance', 'hrms.attendance_monthly', 'hrms.attendance_regularisations'],
                'purpose' => 'Shows today\'s and recent punch-ins and punch-outs, attendance KPIs and the queue of regularisation requests.',
                'action' => null,
            ],
            $base . 'attendance-management/attendance-reports' => [
                'sources' => ['hrms.attendance_monthly', 'hrms.attendance'],
                'purpose' => 'Reports on employee attendance across a chosen period.',
                'action' => null,
            ],
            $base . 'attendance-management/monthly-attendance-report' => [
                'sources' => ['hrms.attendance_monthly', 'hrms.attendance'],
                'purpose' => 'A month-wise attendance sheet for every employee.',
                'action' => null,
            ],
            $base . 'attendance-management/manage-employee-attendance' => [
                'sources' => ['hrms.attendance_edits', 'hrms.attendance'],
                'purpose' => 'Lets an administrator correct an employee\'s punch times and keeps a trail of each correction.',
                'action' => null,
            ],
            $base . 'leave-management' => [
                'sources' => ['hrms.leave_requests', 'hrms.leave_types'],
                'purpose' => 'Entry point for leave: dashboard, requests, reports and configuration.',
                'action' => null,
            ],
            $base . 'leave-management/leave-dashboard' => [
                'sources' => ['hrms.leave_requests', 'hrms.holidays', 'hrms.leave_allocations'],
                'purpose' => 'A snapshot of leave balances, pending approvals, recent activity and upcoming holidays.',
                'action' => null,
            ],
            $base . 'leave-management/leave-requests' => [
                'sources' => ['hrms.leave_requests', 'hrms.leave_types'],
                'purpose' => 'Where employees apply for leave and approvers approve or reject requests.',
                'action' => 'apply_leave',
            ],
            $base . 'leave-management/leave-reports' => [
                'sources' => ['hrms.leave_requests', 'hrms.leave_allocations'],
                'purpose' => 'Leave summaries, registers and balances by employee, type and period.',
                'action' => null,
            ],
            $base . 'leave-management/leave-configuration' => [
                'sources' => ['hrms.leave_types', 'hrms.holidays', 'hrms.weekly_off', 'hrms.leave_access_roles', 'hrms.leave_workflow'],
                'purpose' => 'Sets up leave types, the holiday calendar, the weekly working pattern, role permissions and the approval workflow.',
                'action' => null,
            ],
            $base . 'payroll-management' => [
                'sources' => ['hrms.payroll_monthly', 'hrms.payroll_types'],
                'purpose' => 'Entry point for payroll: components, salary structures, deductions, monthly runs and statements.',
                'action' => null,
            ],
            $base . 'payroll-management/payroll-type' => [
                'sources' => ['hrms.payroll_types'],
                'purpose' => 'Defines the earning and deduction components that make up a salary.',
                'action' => null,
            ],
            $base . 'payroll-management/salary-structure' => [
                'sources' => ['hrms.salary_structures', 'hrms.payroll_types'],
                'purpose' => 'Sets each employee\'s salary breakdown by component for a year.',
                'action' => null,
            ],
            $base . 'payroll-management/payroll-deduction' => [
                'sources' => ['hrms.payroll_deductions', 'hrms.payroll_types'],
                'purpose' => 'Records extra one-off deductions per employee for a payroll month.',
                'action' => null,
            ],
            $base . 'payroll-management/form-16' => [
                'sources' => ['hrms.payroll_monthly'],
                'purpose' => 'Generates the Form 16 tax statement for an employee from that year\'s payroll.',
                'action' => null,
            ],
            $base . 'payroll-management/salary-certificate' => [
                'sources' => ['hrms.salary_certificates'],
                'purpose' => 'Generates a salary certificate for an employee and keeps a copy of each one issued.',
                'action' => null,
            ],
            $base . 'payroll-management/monthly-payroll-report' => [
                'sources' => ['hrms.payroll_monthly'],
                'purpose' => 'Runs and reviews the payroll for a month.',
                'action' => null,
            ],
            $base . 'payroll-management/payroll-bank-report' => [
                'sources' => ['hrms.payroll_by_payment_mode'],
                'purpose' => 'Payment advice listing what is to be paid for a month, grouped by how it is paid.',
                'action' => null,
            ],
            $base . 'payroll-management/payroll-history' => [
                'sources' => ['hrms.payroll_monthly'],
                'purpose' => 'Shows one employee\'s payroll month by month (the source gives period totals only).',
                'action' => null,
            ],
            $base . 'payroll-management/payroll-register' => [
                'sources' => ['hrms.payroll_monthly', 'hrms.payroll_types'],
                'purpose' => 'A register of all employees\' pay for a period, component by component.',
                'action' => null,
            ],
            $base . 'payroll-management/salary-structure-report' => [
                'sources' => ['hrms.salary_structures', 'hrms.payroll_types'],
                'purpose' => 'A report of salary structures by component across employees.',
                'action' => null,
            ],
            $base . 'my-hr' => [
                'sources' => ['hrms.my_payslips', 'hrms.leave_requests', 'hrms.attendance'],
                'purpose' => 'The employee\'s own HR page: leave, attendance, payslips and tax documents.',
                'action' => null,
            ],
        ];
    }
}
