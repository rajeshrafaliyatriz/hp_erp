<?php

namespace App\Domain\AI\Examples\Sources;

use App\Services\Ai\AiRequestScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Task module's data sources and page examples. See SourceGroup for the rules every source follows.
 *
 * Task counts that break down by person (workload, status, priority, schedule) pin a caller who is not an
 * administrator to the tasks allocated to them. The audit trail is an administration screen on the page
 * itself, so it returns nothing for a non-administrator. Projects, dependencies, milestones and the status
 * and priority definitions are shown to every user of the Task module and are not pinned.
 */
final class TaskSources extends SourceGroup
{
    /** SQL for a task's status as the module reads it: upper-case, with NULL meaning pending. */
    private const STATUS = "UPPER(COALESCE(t.status, 'PENDING'))";

    /** SQL for "not finished and its date has passed". */
    private const OVERDUE = "CASE WHEN UPPER(COALESCE(t.status, 'PENDING')) <> 'COMPLETED' AND t.task_date < CURDATE() THEN 1 ELSE 0 END";

    public function definitions(): array
    {
        $limit = self::limitArg();

        return [
            [
                'name' => 'tasks.workload',
                'module' => 'task_management',
                'label' => 'Task workload by person',
                'description' => 'For each person, how many tasks they hold: pending, in progress, completed and overdue.',
                'arguments' => [
                    self::arg('department_id', 'integer', 'Only people in this department.'),
                    $limit,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('task as t')
                        ->join('tbluser as u', function ($join) {
                            $join->on('u.id', '=', 't.task_allocated_to')->on('u.sub_institute_id', '=', 't.sub_institute_id');
                        })
                        ->leftJoin('hrms_departments as d', 'd.id', '=', 'u.department_id')
                        ->where('t.sub_institute_id', $tenant)
                        ->whereNull('t.deleted_at')
                        ->groupBy('u.id', 'u.first_name', 'u.last_name', 'd.department')
                        ->selectRaw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as assignee")
                        ->addSelect('d.department')
                        ->selectRaw('COUNT(*) as total_tasks')
                        ->selectRaw("SUM(CASE WHEN " . self::STATUS . " = 'PENDING' THEN 1 ELSE 0 END) as pending")
                        ->selectRaw("SUM(CASE WHEN " . self::STATUS . " = 'IN-PROGRESS' THEN 1 ELSE 0 END) as in_progress")
                        ->selectRaw("SUM(CASE WHEN " . self::STATUS . " = 'COMPLETED' THEN 1 ELSE 0 END) as completed")
                        ->selectRaw('SUM(' . self::OVERDUE . ') as overdue')
                        ->orderByDesc('total_tasks');

                    if (! $scope->isAdmin) {
                        $q->where('t.task_allocated_to', $scope->userId);
                    }
                    if (($d = $this->int($a, 'department_id')) !== null) {
                        $q->where('u.department_id', $d);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'tasks.status_summary',
                'module' => 'task_management',
                'label' => 'Tasks by status',
                'description' => 'How many tasks are in each status, and how many of them are overdue.',
                'arguments' => [],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('task as t')
                        ->where('t.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('t.deleted_at')
                        ->groupByRaw(self::STATUS)
                        ->selectRaw(self::STATUS . ' as status')
                        ->selectRaw('COUNT(*) as tasks')
                        ->selectRaw('SUM(' . self::OVERDUE . ') as overdue')
                        ->selectRaw('MIN(t.task_date) as earliest_date')
                        ->selectRaw('MAX(t.task_date) as latest_date')
                        ->orderByDesc('tasks');

                    if (! $scope->isAdmin) {
                        $q->where('t.task_allocated_to', $scope->userId);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'tasks.priority_summary',
                'module' => 'task_management',
                'label' => 'Tasks by priority',
                'description' => 'How many tasks carry each priority, how many are completed and how many are overdue.',
                'arguments' => [],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('task as t')
                        ->where('t.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('t.deleted_at')
                        ->groupByRaw("COALESCE(t.task_type, 'Not set')")
                        ->selectRaw("COALESCE(t.task_type, 'Not set') as priority")
                        ->selectRaw('COUNT(*) as tasks')
                        ->selectRaw("SUM(CASE WHEN " . self::STATUS . " = 'COMPLETED' THEN 1 ELSE 0 END) as completed")
                        ->selectRaw('SUM(' . self::OVERDUE . ') as overdue')
                        ->orderByDesc('tasks');

                    if (! $scope->isAdmin) {
                        $q->where('t.task_allocated_to', $scope->userId);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'tasks.schedule',
                'module' => 'task_management',
                'label' => 'Tasks by date',
                'description' => 'For each date, how many tasks fall on it and how many are completed or overdue. Defaults to 30 days back and 60 days ahead.',
                'arguments' => [
                    self::arg('from', 'string', 'First date (YYYY-MM-DD). Default 30 days ago.'),
                    self::arg('to', 'string', 'Last date (YYYY-MM-DD). Default 60 days ahead.'),
                    $limit,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $from = $this->text($a, 'from') ?? now()->subDays(30)->toDateString();
                    $to = $this->text($a, 'to') ?? now()->addDays(60)->toDateString();
                    $q = DB::table('task as t')
                        ->where('t.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('t.deleted_at')
                        ->whereBetween('t.task_date', [$from, $to])
                        ->groupBy('t.task_date')
                        ->select('t.task_date')
                        ->selectRaw('COUNT(*) as tasks')
                        ->selectRaw("SUM(CASE WHEN " . self::STATUS . " = 'COMPLETED' THEN 1 ELSE 0 END) as completed")
                        ->selectRaw('SUM(' . self::OVERDUE . ') as overdue')
                        ->orderBy('t.task_date');

                    if (! $scope->isAdmin) {
                        $q->where('t.task_allocated_to', $scope->userId);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'tasks.statuses',
                'module' => 'task_management',
                'label' => 'Custom task statuses',
                'description' => 'Statuses this organisation added to the built-in ones, with their category, order, whether they are active and how many tasks use them.',
                'arguments' => [],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    return DB::table('task_management_statuses as s')
                        ->where('s.sub_institute_id', $scope->selectedInstituteId)
                        ->select(['s.id as status_id', 's.name', 's.category', 's.color', 's.sort_order', 's.active'])
                        ->selectRaw('(SELECT COUNT(*) FROM task t WHERE t.sub_institute_id = s.sub_institute_id AND t.deleted_at IS NULL AND t.status_label = s.name) as tasks_using')
                        ->orderBy('s.sort_order');
                },
            ],
            [
                'name' => 'tasks.priorities',
                'module' => 'task_management',
                'label' => 'Custom task priorities',
                'description' => 'Priorities this organisation added to the built-in ones, with their response time in hours, whether they are active and how many tasks use them.',
                'arguments' => [],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    return DB::table('task_management_priorities as p')
                        ->where('p.sub_institute_id', $scope->selectedInstituteId)
                        ->select(['p.id as priority_id', 'p.name', 'p.color', 'p.sla_hours', 'p.sort_order', 'p.active'])
                        ->selectRaw('(SELECT COUNT(*) FROM task t WHERE t.sub_institute_id = p.sub_institute_id AND t.deleted_at IS NULL AND t.task_type = p.name) as tasks_using')
                        ->orderBy('p.sort_order');
                },
            ],
            [
                'name' => 'tasks.roles',
                'module' => 'task_management',
                'label' => 'Roles in the permission matrix',
                'description' => 'The active roles that the Task permission matrix grants abilities to.',
                'arguments' => [],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    return DB::table('tbluserprofilemaster as p')
                        ->where('p.sub_institute_id', $scope->selectedInstituteId)
                        ->where('p.status', 1)
                        ->whereNull('p.deleted_at')
                        ->select(['p.id as role_id', 'p.name as role', 'p.sort_order'])
                        ->orderBy('p.sort_order')
                        ->orderBy('p.id');
                },
            ],
            [
                'name' => 'tasks.projects',
                'module' => 'task_management',
                'label' => 'Projects',
                'description' => 'Projects with code, category, status, priority, manager, dates and how many workstreams and tasks each has.',
                'arguments' => [
                    self::arg('status', 'string', 'For example PLANNING, IN PROGRESS or COMPLETED.'),
                    $limit,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('task_management_projects as p')
                        ->leftJoin('tbluser as m', function ($join) use ($tenant) {
                            $join->on('m.id', '=', 'p.manager_id')->where('m.sub_institute_id', '=', $tenant);
                        })
                        ->where('p.sub_institute_id', $tenant)
                        ->whereNull('p.archived_at')
                        ->select(['p.id as project_id', 'p.code', 'p.name as project', 'p.category', 'p.status', 'p.priority', 'p.start_date', 'p.due_date'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(m.first_name, ''), ' ', COALESCE(m.last_name, ''))) as manager")
                        ->selectRaw('(SELECT COUNT(*) FROM task_management_workstreams w WHERE w.project_id = p.id) as workstreams')
                        ->selectRaw('(SELECT COUNT(*) FROM task_management_project_tasks pt WHERE pt.project_id = p.id) as tasks')
                        ->orderByDesc('p.id');

                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('p.status', $s);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'tasks.dependencies',
                'module' => 'task_management',
                'label' => 'Task dependencies',
                'description' => 'Which task has to finish before another can start, with the dependency type, the lag in days and the project.',
                'arguments' => [$limit],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;

                    return DB::table('task_management_dependencies as d')
                        ->join('task as pre', 'pre.id', '=', 'd.predecessor_task_id')
                        ->join('task as suc', 'suc.id', '=', 'd.successor_task_id')
                        ->leftJoin('task_management_projects as p', function ($join) use ($tenant) {
                            $join->on('p.id', '=', 'd.project_id')->where('p.sub_institute_id', '=', $tenant);
                        })
                        ->where('d.sub_institute_id', $tenant)
                        ->where('pre.sub_institute_id', $tenant)
                        ->where('suc.sub_institute_id', $tenant)
                        ->select(['d.id as dependency_id', 'pre.task_title as predecessor', 'suc.task_title as successor', 'd.dependency_type', 'd.lag_days', 'p.name as project'])
                        ->selectRaw("UPPER(COALESCE(pre.status, 'PENDING')) as predecessor_status")
                        ->selectRaw("UPPER(COALESCE(suc.status, 'PENDING')) as successor_status")
                        ->orderByDesc('d.id');
                },
            ],
            [
                'name' => 'tasks.milestones',
                'module' => 'task_management',
                'label' => 'Milestones',
                'description' => 'Project milestones with their target date and status.',
                'arguments' => [$limit],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;

                    return DB::table('task_management_milestones as m')
                        ->leftJoin('task_management_projects as p', function ($join) use ($tenant) {
                            $join->on('p.id', '=', 'm.project_id')->where('p.sub_institute_id', '=', $tenant);
                        })
                        ->where('m.sub_institute_id', $tenant)
                        ->select(['m.id as milestone_id', 'm.name as milestone', 'p.name as project', 'm.target_date', 'm.status'])
                        ->orderBy('m.target_date');
                },
            ],
            [
                'name' => 'tasks.audit_log',
                'module' => 'task_management',
                'label' => 'Task audit log',
                'description' => 'What happened to tasks, which task, who did it and when. Administrators only; empty for everyone else.',
                'arguments' => [
                    self::arg('event', 'string', 'Only this event, for example created, updated or legacy_updated.'),
                    $limit,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('g2g_audit_log as a')
                        ->leftJoin('task as t', function ($join) use ($tenant) {
                            $join->on('t.id', '=', 'a.entity_id')->where('t.sub_institute_id', '=', $tenant);
                        })
                        ->leftJoin('tbluser as actor', function ($join) use ($tenant) {
                            $join->on('actor.id', '=', 'a.actor_id')->where('actor.sub_institute_id', '=', $tenant);
                        })
                        ->where('a.sub_institute_id', $tenant)
                        ->where('a.entity_type', 'task')
                        ->select(['a.id as event_id'])
                        ->selectRaw("SUBSTRING(a.type, LOCATE('.', a.type) + 1) as event")
                        ->addSelect('t.task_title as task')
                        ->selectRaw("TRIM(CONCAT(COALESCE(actor.first_name, ''), ' ', COALESCE(actor.last_name, ''))) as done_by")
                        ->addSelect('a.occurred_at')
                        ->orderByDesc('a.id');

                    if (! $scope->isAdmin) {
                        $q->whereRaw('1 = 0');
                    }
                    if (($e = $this->text($a, 'event')) !== null) {
                        $q->where('a.type', 'task.' . $e);
                    }

                    return $q;
                },
            ],
        ];
    }

    public function pages(): array
    {
        return [
            '/module/task-management/task-management-dashboard' => [
                'sources' => ['tasks.status_summary', 'tasks.workload', 'tasks.my_tasks'],
                'purpose' => 'The task workspace: every task with its status, priority and owner, plus the team\'s workload.',
                'action' => 'add_backlog_item',
            ],
            '/module/task-management/my-tasks' => [
                'sources' => ['tasks.my_tasks'],
                'purpose' => 'Your own tasks, where you update their status and add quick tasks.',
                'action' => 'update_my_task_status',
            ],
            '/module/task-management/projects-and-workstreams' => [
                'sources' => ['tasks.projects'],
                'purpose' => 'Projects and the workstreams and tasks inside them.',
                'action' => null,
            ],
            '/module/task-management/dependencies-and-workstreams' => [
                'sources' => ['tasks.dependencies', 'tasks.milestones'],
                'purpose' => 'Which tasks wait on which, and the milestones that mark project progress.',
                'action' => null,
            ],
            '/module/task-management/task-calendar' => [
                'sources' => ['tasks.schedule'],
                'purpose' => 'A calendar of tasks by date, so you can see what is due and what is overdue.',
                'action' => null,
            ],
            '/module/task-management/reports-and-analysis' => [
                'sources' => ['tasks.status_summary', 'tasks.priority_summary', 'tasks.workload'],
                'purpose' => 'Productivity and delay reports built from the tasks by status, priority and person.',
                'action' => null,
            ],
            '/module/task-management/task-status' => [
                'sources' => ['tasks.status_summary', 'tasks.statuses'],
                'purpose' => 'Define the task statuses your organisation uses, on top of the built-in ones.',
                'action' => null,
            ],
            '/module/task-management/task-priority' => [
                'sources' => ['tasks.priority_summary', 'tasks.priorities'],
                'purpose' => 'Define the task priorities and their response times, on top of the built-in ones.',
                'action' => null,
            ],
            '/module/task-management/task-permission' => [
                'sources' => ['tasks.roles'],
                'purpose' => 'The matrix of what each role may do with tasks.',
                'action' => null,
            ],
            '/module/task-management/task-integrations' => [
                'sources' => [],
                'purpose' => 'Shows whether the n8n webhook, Gemini task drafting and push notifications are configured.',
                'action' => null,
                'no_data_reason' => 'These are on/off flags read from the server\'s configuration, not rows in any table, and they stand for keys and webhook addresses that must never be shown.',
            ],
            '/module/task-management/task-audit-logs' => [
                'sources' => ['tasks.audit_log'],
                'purpose' => 'The trail of changes made to tasks, filterable by task, person and event.',
                'action' => null,
            ],
        ];
    }
}
