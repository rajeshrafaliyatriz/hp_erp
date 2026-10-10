<?php

namespace App\Domain\AI\Examples\Sources;

use App\Services\Ai\AiRequestScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Dashboard module's data sources and page examples. See SourceGroup for the rules every source follows.
 *
 * The HR home dashboard (/dashboard) is built from App\Services\Dashboard\HrDashboardService; each source
 * below exposes the rows behind one of its cards, from the same tables and with the same filters.
 */
final class DashboardSources extends SourceGroup
{
    private const MODULE = 'main_dashboard';

    public function definitions(): array
    {
        $limit = self::limitArg();

        return [
            [
                'name' => 'dashboard.headcount_by_department',
                'module' => self::MODULE,
                'label' => 'Headcount by department',
                'description' => 'Active employees (not terminated) counted per department - the figure behind Total Employees and Top Departments by Headcount.',
                'arguments' => [$limit],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    return DB::table('tbluser as u')
                        ->leftJoin('hrms_departments as d', 'd.id', '=', 'u.department_id')
                        ->where('u.sub_institute_id', $scope->selectedInstituteId)
                        ->where('u.status', 1)
                        ->whereNull('u.terminated_date')
                        ->whereNull('u.deleted_at')
                        ->groupBy('u.department_id', 'd.department')
                        ->selectRaw("COALESCE(d.department, 'No department') as department")
                        ->selectRaw('count(*) as active_employees')
                        ->orderByDesc('active_employees');
                },
            ],
            [
                'name' => 'dashboard.pending_approvals',
                'module' => self::MODULE,
                'label' => 'Pending approvals',
                'description' => 'How many items are waiting for a decision: leave requests, capability approvals and performance reviews - the Pending Approvals card.',
                'arguments' => [$limit],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $sid = $scope->selectedInstituteId;
                    $leave = DB::table('hrms_emp_leaves')->where('sub_institute_id', $sid)->whereNull('deleted_at')
                        ->whereRaw('LOWER(status) = ?', ['pending'])
                        ->selectRaw("'Leave requests' as approval_type, count(*) as pending");
                    $capability = DB::table('s_competency_approvals')->where('sub_institute_id', $sid)->whereNull('deleted_at')
                        ->whereRaw('LOWER(status) = ?', ['pending'])
                        ->selectRaw("'Capability approvals' as approval_type, count(*) as pending");
                    $reviews = DB::table('s_performance_reviews')->where('sub_institute_id', $sid)->whereNull('deleted_at')
                        ->whereRaw("LOWER(COALESCE(status, '')) = ?", ['pending'])
                        ->selectRaw("'Performance reviews' as approval_type, count(*) as pending");

                    return DB::query()->fromSub($leave->unionAll($capability)->unionAll($reviews), 'p')
                        ->select(['p.approval_type', 'p.pending']);
                },
            ],
            [
                'name' => 'dashboard.overdue_tasks',
                'module' => self::MODULE,
                'label' => 'Overdue tasks',
                'description' => 'Tasks whose date has passed and that are not completed - the Overdue Tasks card - with title, due date, status and who they are allocated to.',
                'arguments' => [
                    self::arg('syear', 'string', 'Academic year of the tasks, e.g. 2026. All years when omitted.'),
                    $limit,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('task as t')
                        ->leftJoin('tbluser as u', 'u.id', '=', 't.task_allocated_to')
                        ->where('t.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('t.deleted_at')
                        ->whereDate('t.task_date', '<', date('Y-m-d'))
                        ->whereRaw("UPPER(COALESCE(t.status, 'PENDING')) <> 'COMPLETED'")
                        ->select(['t.id as task_id', 't.task_title', 't.task_date as due_date', 't.status', 't.SYEAR as syear'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as allocated_to")
                        ->orderBy('t.task_date');

                    if (($y = $this->text($a, 'syear')) !== null) {
                        $q->where('t.SYEAR', $y);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'dashboard.task_status',
                'module' => self::MODULE,
                'label' => 'Tasks by status',
                'description' => 'Tasks counted by status (pending, on hold, completed ...) - the task figures in Module Summary and the blocked-tasks card.',
                'arguments' => [
                    self::arg('syear', 'string', 'Academic year of the tasks, e.g. 2026. All years when omitted.'),
                    $limit,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('task as t')
                        ->where('t.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('t.deleted_at')
                        ->groupBy('t.status')
                        ->selectRaw("COALESCE(NULLIF(TRIM(t.status), ''), 'PENDING') as status")
                        ->selectRaw('count(*) as tasks')
                        ->orderByDesc('tasks');

                    if (($y = $this->text($a, 'syear')) !== null) {
                        $q->where('t.SYEAR', $y);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'dashboard.expiring_certifications',
                'module' => self::MODULE,
                'label' => 'Certifications expiring soon',
                'description' => 'Certifications that expire from today onward within a number of days (default 30) - the expiring item in the Action Center.',
                'arguments' => [
                    self::arg('within_days', 'integer', 'Look this many days ahead (default 30).'),
                    $limit,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $days = $this->int($a, 'within_days') ?? 30;

                    return DB::table('s_competency_certifications as c')
                        ->leftJoin('tbluser as u', 'u.id', '=', 'c.user_id')
                        ->where('c.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('c.deleted_at')
                        ->whereNotNull('c.expiry_date')
                        ->whereBetween('c.expiry_date', [date('Y-m-d'), date('Y-m-d', strtotime("+{$days} days"))])
                        ->select(['c.id as certification_id', 'c.name as certification', 'c.issuing_body', 'c.status', 'c.expiry_date'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as employee")
                        ->orderBy('c.expiry_date');
                },
            ],
            [
                'name' => 'dashboard.upcoming_events',
                'module' => self::MODULE,
                'label' => 'Upcoming holidays',
                'description' => 'Holidays that have not ended yet, soonest first - the Upcoming Events card.',
                'arguments' => [$limit],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    return DB::table('hrms_holidays as h')
                        ->where('h.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('h.deleted_at')
                        ->where('h.to_date', '>=', date('Y-m-d'))
                        ->select(['h.id as holiday_id', 'h.holiday_name', 'h.from_date', 'h.to_date', 'h.day_type'])
                        ->orderBy('h.from_date');
                },
            ],
            [
                'name' => 'dashboard.hiring_funnel',
                'module' => self::MODULE,
                'label' => 'Hiring funnel',
                'description' => 'Job applications counted by stage - the Talent Acquisition Funnel card.',
                'arguments' => [$limit],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    return DB::table('talent_job_applications as a')
                        ->where('a.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('a.deleted_at')
                        ->groupBy('a.status')
                        ->selectRaw("COALESCE(NULLIF(TRIM(a.status), ''), 'unknown') as stage")
                        ->selectRaw('count(*) as applications')
                        ->orderByDesc('applications');
                },
            ],
            [
                'name' => 'dashboard.learning_progress',
                'module' => self::MODULE,
                'label' => 'Learning progress',
                'description' => 'Course enrolments counted by status (in progress, completed ...) - the Learning Progress Overview card.',
                'arguments' => [$limit],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    return DB::table('lms_course_enroll as e')
                        ->where('e.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('e.deleted_at')
                        ->groupBy('e.status')
                        ->selectRaw("COALESCE(NULLIF(TRIM(e.status), ''), 'unknown') as status")
                        ->selectRaw('count(*) as enrolments')
                        ->orderByDesc('enrolments');
                },
            ],
            [
                'name' => 'dashboard.attendance_trend',
                'module' => self::MODULE,
                'label' => 'Attendance by day',
                'description' => 'How many different employees were marked present each day over the last days (default 30) - the Workforce and Attendance Overview.',
                'arguments' => [
                    self::arg('days', 'integer', 'How many days back to look (default 30).'),
                    $limit,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $days = $this->int($a, 'days') ?? 30;

                    return DB::table('hrms_attendances as t')
                        ->where('t.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('t.deleted_at')
                        ->where('t.status', 1)
                        ->where('t.day', '>=', date('Y-m-d', strtotime("-{$days} days")))
                        ->groupBy('t.day')
                        ->selectRaw('DATE(t.day) as day')
                        ->selectRaw('count(distinct t.user_id) as present_employees')
                        ->orderByDesc('day');
                },
            ],
        ];
    }

    public function pages(): array
    {
        return [
            '/dashboard' => [
                'sources' => [
                    'dashboard.headcount_by_department',
                    'dashboard.pending_approvals',
                    'dashboard.overdue_tasks',
                    'dashboard.expiring_certifications',
                    'dashboard.attendance_trend',
                    'dashboard.hiring_funnel',
                    'dashboard.learning_progress',
                    'dashboard.upcoming_events',
                    'dashboard.task_status',
                ],
                'purpose' => 'The HR home page: headcount, attendance, pending approvals, overdue tasks, hiring, learning and upcoming events at a glance.',
                'action' => null,
            ],
        ];
    }
}
