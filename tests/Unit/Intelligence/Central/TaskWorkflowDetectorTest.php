<?php

namespace Tests\Unit\Intelligence\Central;

use App\Domain\Intelligence\Detectors\Hr\TaskWorkflowDetector;
use App\Domain\Intelligence\InsightService;
use App\Domain\Intelligence\Support\SmallGroupGuard;
use App\Domain\Intelligence\Support\Thresholds;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Support\Facades\Schema;
use Tests\Intelligence\Fixtures\CentralTables;
use Tests\Intelligence\Fixtures\HrTables;
use Tests\Intelligence\IntelligenceTestCase;

/** Fixed today = 2026-11-21. The reopen window (30 days) therefore starts 2026-10-22. */
class TaskWorkflowDetectorTest extends IntelligenceTestCase
{
    use HrTables, CentralTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPeopleTables();
        $this->createCentralTables();
    }

    private function detector(array $t = ['tasks_min_assigned' => 3]): TaskWorkflowDetector
    {
        return new TaskWorkflowDetector($this->db(), Thresholds::with($t));
    }

    private function history(int $task, bool $reopen, string $at, int $tenant = 1): void
    {
        $this->insertRow('task_status_history', ['sub_institute_id' => $tenant, 'task_id' => $task, 'is_reopen' => $reopen ? 1 : 0, 'occurred_at' => $at]);
    }

    /** @return array<string, int> name => task id. People 100..105 report to manager 10 in department 3. */
    private function seedTasks(): array
    {
        $this->people(6);
        $t = [];
        $t['overdue_pending'] = $this->task(100, '2026-11-01', 'PENDING');
        $t['overdue_null_status'] = $this->task(101, '2026-11-10', null);          // NULL status is PENDING in the module: open
        $t['overdue_in_progress'] = $this->task(100, '2026-11-15', 'IN-PROGRESS');
        $t['overdue_on_hold'] = $this->task(102, '2026-11-19', 'ON HOLD');         // overdue AND blocked
        $t['done_past_due'] = $this->task(103, '2026-11-01', 'COMPLETED');
        $t['done_lower'] = $this->task(103, '2026-11-02', 'completed');            // the module compares upper-case
        $t['future'] = $this->task(104, '2026-12-01', 'PENDING');
        $t['undated'] = $this->task(104, null, 'PENDING');
        $t['due_today'] = $this->task(105, '2026-11-21', 'PENDING');               // not yet past due
        $t['blocked_future'] = $this->task(105, '2026-12-05', ' on hold ');        // blocked (trimmed, case-insensitive)
        $t['deleted'] = $this->task(100, '2026-11-01', 'PENDING', 1, ['deleted_at' => '2026-11-05 00:00:00']);

        $this->history($t['done_past_due'], true, '2026-11-10 09:00:00');           // reopened in window
        $this->history($t['overdue_pending'], true, '2026-11-15 09:00:00');         // reopened in window
        $this->history($t['done_lower'], true, '2026-09-01 09:00:00');              // before the window
        $this->history($t['future'], false, '2026-11-12 09:00:00');                 // a status change that is not a reopen
        $this->history($t['overdue_in_progress'], true, '2026-11-12 09:00:00', 2);  // another tenant's history row naming this tenant's task id

        return $t;
    }

    public function test_happy_path_mirrors_the_modules_overdue_and_blocked_definitions(): void
    {
        $t = $this->seedTasks();

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());
        $by = $this->byCategory($r->insights);

        $this->assertSame(Taxonomy::STATE_OK, $r->state);
        $this->assertSame(10, $r->coverage->observed, 'tasks assigned to people in scope; the deleted one is not counted');
        $this->assertTrue($r->coverage->sufficient);
        $this->assertSame(['task_overdue', 'task_blocked', 'task_reopened'], array_keys($by));

        $this->assertSame(4, $this->metric($by['task_overdue'], 'Open tasks past their due date'));
        $this->assertSame(8, $this->metric($by['task_overdue'], 'Open tasks in total'));
        $this->assertSame(20, $this->metric($by['task_overdue'], 'Longest overdue'));
        $this->assertSame(1, $this->metric($by['task_overdue'], 'Open tasks with no due date'));
        $this->assertSame('high', $by['task_overdue']['severity'], '4 of 8 open tasks is over the 25% line');
        $this->assertSame('rule_based', $by['task_overdue']['confidence']);
        $this->assertStringContainsString('3 person(s)', $by['task_overdue']['what_happened']);

        $this->assertSame(2, $this->metric($by['task_blocked'], 'Tasks on hold'));
        $this->assertSame('measured', $by['task_blocked']['confidence']);

        $this->assertSame(2, $this->metric($by['task_reopened'], 'Tasks reopened in the last 30 days'), 'in-window reopens of this tenant only');
        $this->assertSame('info', $by['task_reopened']['severity']);

        $refs = array_map(static fn ($e) => $e['entity_id'], array_filter($by['task_overdue']['evidence'], static fn ($e) => $e['kind'] === 'entity'));
        $this->assertSame([$t['overdue_pending'], $t['overdue_null_status'], $t['overdue_in_progress'], $t['overdue_on_hold']], array_values($refs), 'oldest due date first');
        foreach ($by as $i) {
            $this->assertSame(['task'], array_values(array_unique($this->entityTypes($i))));
            $this->assertSame(['tasks'], $i['surfaces']);
            $this->assertSame('/module/task-management/task-management-dashboard', $i['deep_link']);
        }
        $this->assertStringContainsString('all academic years', $r->coverage->basis);
    }

    public function test_the_employee_sees_only_their_own_tasks_and_a_link_to_my_tasks(): void
    {
        $this->seedTasks();

        $r = $this->detector()->detect($this->callerAs('employee', 1, 100), $this->clock());
        $by = $this->byCategory($r->insights);

        $this->assertSame(2, $r->coverage->observed, 'own non-deleted tasks only');
        $this->assertSame(2, $this->metric($by['task_overdue'], 'Open tasks past their due date'));
        $this->assertArrayNotHasKey('task_blocked', $by);
        $this->assertSame('self', $by['task_overdue']['audience']);
        $this->assertSame('/module/task-management/my-tasks', $by['task_overdue']['deep_link']);
        $this->assertSame('Tasks of yours are past their due date', $by['task_overdue']['title']);
    }

    public function test_each_role_sees_the_people_its_scope_allows(): void
    {
        $this->seedTasks();
        $this->insertUser(120, 1, ['reporting_manager_id' => 100, 'department_id' => 3]);   // a report of a report, same department
        $this->insertUser(130, 1, ['reporting_manager_id' => 11, 'department_id' => 4]);    // another manager's report, other department
        $mine = $this->task(120, '2026-10-01', 'PENDING');
        $theirs = $this->task(130, '2026-10-02', 'PENDING');
        $d = $this->detector();
        $overdue = function (string $role, ?int $dept = 3) use ($d): array {
            $by = $this->byCategory($d->detect($this->callerAs($role, 1, 10, $dept), $this->clock())->insights);
            $ids = array_map(static fn ($e) => $e['entity_id'], array_filter($by['task_overdue']['evidence'] ?? [], static fn ($e) => $e['kind'] === 'entity'));

            return [$this->metric($by['task_overdue'], 'Open tasks past their due date'), $ids];
        };

        [$n, $ids] = $overdue('reporting_manager');                       // direct reports 100..105 (120 reports to 100, 130 to someone else)
        $this->assertSame(4, $n);
        $this->assertNotContains($mine, $ids);
        $this->assertNotContains($theirs, $ids);

        [$n, $ids] = $overdue('department_head');                         // the whole of department 3, so the report of a report too
        $this->assertSame(5, $n);
        $this->assertContains($mine, $ids);
        $this->assertNotContains($theirs, $ids);

        [$n, $ids] = $overdue('hr_executive');
        $this->assertSame(5, $n);
        $this->assertNotContains($theirs, $ids);

        foreach (['hr_manager', 'administrator'] as $role) {
            [$n, $ids] = $overdue($role);
            $this->assertSame(6, $n, $role);
            $this->assertContains($theirs, $ids, $role);
        }
    }

    public function test_executive_and_auditor_get_aggregates_without_task_references(): void
    {
        $this->seedTasks();
        foreach (['executive', 'auditor'] as $role) {
            $r = $this->detector()->detect($this->callerAs($role), $this->clock());
            $this->assertNotEmpty($r->insights, $role);
            foreach ($r->insights as $i) {
                $this->assertSame([], $this->entityTypes($i->toArray()), "$role must get no task references");
            }
            $this->assertSame(4, $this->metric($this->byCategory($r->insights)['task_overdue'], 'Open tasks past their due date'));
        }
    }

    public function test_recruiter_and_unknown_roles_are_not_authorised(): void
    {
        foreach (['recruiter', 'wizard'] as $role) {
            $this->assertFalse($this->detector()->authorizes($this->callerAs($role)), $role);
        }
        foreach (['employee', 'reporting_manager', 'department_head', 'hr_executive', 'hr_manager', 'administrator', 'executive', 'auditor'] as $role) {
            $this->assertTrue($this->detector()->authorizes($this->callerAs($role)), $role);
        }
    }

    public function test_insufficient_when_too_few_tasks_are_assigned(): void
    {
        $this->people(6);
        $this->task(100, '2026-11-01');
        $this->task(101, '2026-11-01');

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $r->state);
        $this->assertSame(2, $r->coverage->observed);
        $this->assertFalse($r->coverage->sufficient);
        $this->assertSame([], $r->insights);
    }

    public function test_an_employee_with_one_task_is_assessed_and_with_none_is_not_applicable(): void
    {
        $this->people(6);
        $none = $this->detector()->detect($this->callerAs('employee', 1, 100), $this->clock());
        $this->assertSame(Taxonomy::STATE_NOT_APPLICABLE, $none->state);

        $this->task(100, '2026-11-01');
        $one = $this->detector()->detect($this->callerAs('employee', 1, 100), $this->clock());
        $this->assertSame(Taxonomy::STATE_OK, $one->state);
        $this->assertCount(1, $one->insights);
    }

    public function test_enough_tasks_and_nothing_wrong_is_ok_with_no_insights(): void
    {
        $this->people(6);
        $this->task(100, '2026-12-01');
        $this->task(101, '2026-12-02', 'IN-PROGRESS');
        $this->task(102, '2026-11-01', 'COMPLETED');
        $this->task(103, '2026-11-21');            // due today is not overdue

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $r->state);
        $this->assertSame([], $r->insights);
        $this->assertSame(4, $r->coverage->observed);
    }

    public function test_another_tenants_tasks_never_count_even_when_they_name_this_tenants_people(): void
    {
        $this->people(6);
        $this->task(100, '2026-12-01');
        $this->task(101, '2026-12-01');
        $this->task(102, '2026-12-01');
        // Tenant 2: its own people, plus tasks that name tenant 1's people (corrupt or hostile rows), all overdue and on hold.
        $this->people(6, 2, 300, 20, 3);
        for ($i = 0; $i < 8; $i++) {
            $this->task(300 + $i % 6, '2026-10-01', 'ON HOLD', 2);
            $this->task(100 + $i % 6, '2026-10-01', 'ON HOLD', 2);
        }
        $this->task(300, '2026-10-01', 'PENDING', 1);   // tenant 1's task assigned to a tenant 2 person: that person is not in scope

        $mine = $this->detector()->detect($this->callerAs('hr_manager', 1), $this->clock());
        $theirs = $this->detector()->detect($this->callerAs('hr_manager', 2, 20), $this->clock());

        $this->assertSame([], $mine->insights);
        $this->assertSame(3, $mine->coverage->observed);
        $this->assertSame(8, $this->metric($this->byCategory($theirs->insights)['task_overdue'], 'Open tasks past their due date'));
    }

    public function test_inactive_terminated_and_deleted_assignees_and_deleted_tasks_are_excluded(): void
    {
        $this->people(4);
        $this->insertUser(150, 1, ['status' => 0, 'reporting_manager_id' => 10, 'department_id' => 3]);
        $this->insertUser(151, 1, ['terminated_date' => '2026-03-01', 'reporting_manager_id' => 10, 'department_id' => 3]);
        $this->insertUser(152, 1, ['deleted_at' => '2026-03-01 00:00:00', 'reporting_manager_id' => 10, 'department_id' => 3]);
        $this->task(100, '2026-11-01');
        $this->task(101, '2026-12-01');
        $this->task(102, '2026-12-01');
        foreach ([150, 151, 152] as $u) {
            $this->task($u, '2026-11-01', 'ON HOLD');
        }
        $this->task(100, '2026-11-01', 'PENDING', 1, ['deleted_at' => '2026-11-02 00:00:00']);

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());
        $by = $this->byCategory($r->insights);

        $this->assertSame(3, $r->coverage->observed);
        $this->assertSame(1, $this->metric($by['task_overdue'], 'Open tasks past their due date'));
        $this->assertArrayNotHasKey('task_blocked', $by);
    }

    public function test_reopened_work_is_limited_to_the_window_and_to_people_in_scope(): void
    {
        $this->people(6);
        $a = $this->task(100, '2026-12-01', 'IN-PROGRESS');
        $b = $this->task(101, '2026-12-01', 'IN-PROGRESS');
        $c = $this->task(102, '2026-12-01', 'IN-PROGRESS');
        $this->history($a, true, '2026-10-22 00:00:00');   // first moment of the window: counted
        $this->history($a, true, '2026-11-02 00:00:00');   // the same task again: one task
        $this->history($b, true, '2026-10-21 23:59:59');   // just before the window
        $this->history($c, true, '2026-11-05 00:00:00');

        $team = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());
        $self = $this->detector()->detect($this->callerAs('employee', 1, 100), $this->clock());

        $this->assertSame(2, $this->metric($this->byCategory($team->insights)['task_reopened'], 'Tasks reopened in the last 30 days'));
        $this->assertSame(1, $this->metric($this->byCategory($self->insights)['task_reopened'], 'Tasks reopened in the last 30 days'));
    }

    public function test_without_the_history_table_the_other_measures_still_work(): void
    {
        $this->seedTasks();
        Schema::drop('task_status_history');

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $r->state);
        $this->assertSame(['task_overdue', 'task_blocked'], array_keys($this->byCategory($r->insights)));
        $this->assertNoSqlLeak($this->serialised($r));
    }

    public function test_a_missing_task_table_or_column_is_data_unavailable_and_leaks_no_sql(): void
    {
        $this->seedTasks();
        Schema::table('task', fn ($t) => $t->dropColumn('task_date'));
        $missingColumn = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());
        Schema::drop('task');
        $missingTable = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        foreach ([$missingColumn, $missingTable] as $r) {
            $this->assertSame(Taxonomy::STATE_DATA_UNAVAILABLE, $r->state);
            $json = $this->serialised($r);
            $this->assertNoSqlLeak($json);
            $this->assertStringNotContainsString('task_date', $json);
            $this->assertStringNotContainsString('task_allocated_to', $json);
        }
    }

    public function test_the_result_names_no_table_column_title_or_person(): void
    {
        $this->seedTasks();
        $json = $this->serialised($this->detector()->detect($this->callerAs('hr_manager'), $this->clock()));

        foreach (['task_status_history', 'task_allocated_to', 'task_date', 'sub_institute_id', 'task_title'] as $needle) {
            $this->assertStringNotContainsString($needle, $json, $needle);
        }
        $this->assertStringContainsString('Tasks', $json);
        $this->assertStringContainsString('Task status changes', $json);
    }

    public function test_a_small_group_is_withheld_from_a_team_manager_but_not_from_the_employee_themselves(): void
    {
        $this->people(3);
        foreach ([100, 101, 102] as $u) {
            $this->task($u, '2026-11-01');
        }
        $service = new InsightService([$this->detector()], SmallGroupGuard::fromThresholds(Thresholds::defaults()), $this->clock());

        $manager = $service->run($this->callerAs('reporting_manager'));
        $self = $service->run($this->callerAs('employee', 1, 100));
        $hr = $service->run($this->callerAs('hr_manager'));

        $this->assertSame([], $manager['insights'], '3 people with overdue tasks are below the minimum of 5 for a team manager');
        $this->assertSame(1, $manager['suppressed']);
        $this->assertCount(1, $self['insights'], 'a person is always told about their own data');
        $this->assertCount(1, $hr['insights'], 'HR at organisation scope may open the records anyway');
    }
}
