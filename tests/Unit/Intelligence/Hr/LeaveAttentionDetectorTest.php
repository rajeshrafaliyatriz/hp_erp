<?php

namespace Tests\Unit\Intelligence\Hr;

use App\Domain\Intelligence\Detectors\Hr\LeaveAttentionDetector;
use App\Domain\Intelligence\Support\Thresholds;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Support\Facades\Schema;
use Tests\Intelligence\Fixtures\HrTables;
use Tests\Intelligence\IntelligenceTestCase;

class LeaveAttentionDetectorTest extends IntelligenceTestCase
{
    use HrTables;

    // The fixed clock is 2026-11-21 10:00:00 UTC; the provisional limit is 48 hours, so the cutoff is 2026-11-19 10:00:00.
    private const OLD = '2026-11-10 09:00:00';
    private const RECENT = '2026-11-21 08:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPeopleTables();
        $this->createHrTables();
    }

    private function detector(array $t = ['leave_min_steps' => 3]): LeaveAttentionDetector
    {
        return new LeaveAttentionDetector($this->db(), Thresholds::with($t));
    }

    /** A leave of `$user` with one approval step; returns the leave id. */
    private function leave(int $user, string $stepStatus = 'pending', ?string $pendingSince = self::OLD, ?int $tenant = 1, array $leave = [], array $step = []): int
    {
        $id = (int) $this->db()->table('hrms_emp_leaves')->insertGetId(array_merge(
            ['user_id' => $user, 'sub_institute_id' => $tenant, 'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'status' => 'pending', 'created_at' => '2026-11-09 08:00:00'],
            $leave,
        ));
        $this->insertRow('hrms_leave_approval_steps', array_merge(
            ['leave_id' => $id, 'sub_institute_id' => $tenant ?? 1, 'status' => $stepStatus, 'pending_since' => $pendingSince, 'created_at' => '2026-11-09 08:00:00'],
            $step,
        ));

        return $id;
    }

    public function test_every_people_role_is_authorized_but_not_recruiters(): void
    {
        foreach (['employee', 'reporting_manager', 'department_head', 'hr_executive', 'hr_manager', 'administrator', 'executive', 'auditor'] as $role) {
            $this->assertTrue($this->detector()->authorizes($this->callerAs($role)), $role);
        }
        $this->assertFalse($this->detector()->authorizes($this->callerAs('recruiter')));
    }

    public function test_an_employee_sees_only_their_own_waiting_request(): void
    {
        $this->people(3);
        $mine = $this->leave(100);
        $this->leave(101);                       // a colleague's overdue request
        $this->leave(102);

        $r = $this->detector()->detect($this->callerAs('employee', user: 100), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $r->state);
        $this->assertCount(1, $r->insights);
        $i = $r->insights[0]->toArray();
        $this->assertSame('self', $i['audience']);
        $this->assertSame(1, $this->metric($i, 'Approval steps waiting more than'));
        $this->assertStringContainsString('of yours', $i['title']);
        $this->assertSame([$mine], array_values(array_map(fn ($e) => $e['entity_id'], array_filter($i['evidence'], fn ($e) => $e['kind'] === 'entity'))));
        $this->assertSame('/dashboard/leave-management', $i['deep_link']);
    }

    public function test_a_manager_sees_direct_reports_only(): void
    {
        $this->people(3, firstId: 100, manager: 10);
        $this->people(2, firstId: 120, manager: 77);          // someone else's team
        foreach ([100, 101, 102, 120, 121] as $u) {
            $this->leave($u);
        }

        $r = $this->detector()->detect($this->callerAs('reporting_manager', user: 10), $this->clock());

        $this->assertCount(1, $r->insights);
        $this->assertSame(3, $this->metric($r->insights[0]->toArray(), 'Approval steps waiting more than'));
        $this->assertSame(3, $r->insights[0]->population);
        $this->assertSame('team', $r->insights[0]->audience);
    }

    public function test_hr_sees_the_organisation_with_record_references_and_an_executive_sees_none(): void
    {
        $this->people(6);
        foreach (range(100, 105) as $u) {
            $this->leave($u);
        }

        $hr = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock())->insights[0]->toArray();
        $exec = $this->detector()->detect($this->callerAs('executive'), $this->clock())->insights[0]->toArray();

        $this->assertCount(6, $this->entityTypes($hr));
        $this->assertSame([], $this->entityTypes($exec), 'executives get aggregates only');
        $this->assertSame(6, $this->metric($exec, 'Approval steps waiting more than'));
        $this->assertSame('high', $hr['severity'], 'five or more waiting steps is high');
    }

    public function test_a_recent_wait_a_waiting_step_and_a_decided_step_are_not_overdue(): void
    {
        $this->people(5);
        $this->leave(100, 'pending', self::RECENT);          // pending but only a couple of hours old
        $this->leave(101, 'waiting', self::OLD);             // not the current step yet
        $this->leave(102, 'approved', self::OLD, step: ['decided_at' => '2026-11-11 09:00:00']);
        $this->leave(103, 'rejected', self::OLD);

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $r->state);
        $this->assertSame([], $r->insights);
        $this->assertSame(4, $r->coverage->observed);
    }

    public function test_a_pending_step_with_no_start_time_is_reported_as_not_measured_never_as_overdue(): void
    {
        $this->people(5);
        $this->leave(100, 'pending', null);
        $this->leave(101, 'pending', null);
        $this->leave(102, 'pending', self::OLD);

        $i = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock())->insights[0]->toArray();

        $this->assertSame(1, $this->metric($i, 'Approval steps waiting more than'));
        $this->assertSame(3, $this->metric($i, 'Approval steps waiting in total'));
        $this->assertSame(2, $this->metric($i, 'Waiting steps with no start time'));
    }

    public function test_escalated_overdue_steps_raise_the_severity(): void
    {
        $this->people(4);
        $this->leave(100, step: ['escalated_at' => '2026-11-15 00:00:00']);
        $this->leave(101);
        $this->leave(102);

        $i = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock())->insights[0]->toArray();

        $this->assertSame('high', $i['severity']);
        $this->assertSame(1, $this->metric($i, 'Already escalated'));
    }

    public function test_too_little_history_is_insufficient_for_groups_but_not_for_the_person_themself(): void
    {
        $this->people(3);
        $this->leave(100);

        $org = $this->detector(['leave_min_steps' => 5])->detect($this->callerAs('hr_manager'), $this->clock());
        $self = $this->detector(['leave_min_steps' => 5])->detect($this->callerAs('employee', user: 100), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $org->state);
        $this->assertSame(Taxonomy::STATE_OK, $self->state);
        $this->assertCount(1, $self->insights);
    }

    public function test_another_tenants_steps_and_leaves_never_count(): void
    {
        $this->people(3);
        $this->people(3, tenant: 2, firstId: 200, manager: 50);
        $this->leave(100);                                                       // tenant 1: one overdue
        $this->leave(200, tenant: 2);                                            // tenant 2 leave of a tenant 2 person
        $this->leave(101, tenant: 2);                                            // tenant 2 stamp on a leave of a tenant 1 person
        $this->leave(102, tenant: 1, step: ['sub_institute_id' => 2]);          // tenant 1 leave whose STEP is stamped tenant 2
        $this->leave(103, tenant: null);                                         // no tenant at all

        $i = $this->detector(['leave_min_steps' => 0])->detect($this->callerAs('hr_manager', tenant: 1), $this->clock())->insights[0]->toArray();

        $this->assertSame(1, $this->metric($i, 'Approval steps waiting more than'));
    }

    public function test_deleted_leaves_and_inactive_or_terminated_owners_are_excluded(): void
    {
        $this->people(2);
        $this->insertUser(150, 1, ['status' => 0, 'reporting_manager_id' => 10, 'department_id' => 3]);
        $this->insertUser(151, 1, ['terminated_date' => '2026-02-01', 'reporting_manager_id' => 10, 'department_id' => 3]);
        $this->leave(100);
        $this->leave(101, leave: ['deleted_at' => '2026-11-12 00:00:00']);
        $this->leave(150);
        $this->leave(151);

        $i = $this->detector(['leave_min_steps' => 0])->detect($this->callerAs('hr_manager'), $this->clock())->insights[0]->toArray();

        $this->assertSame(1, $this->metric($i, 'Approval steps waiting more than'));
    }

    public function test_a_department_head_sees_their_department_only(): void
    {
        $this->people(2, firstId: 100, dept: 3);
        $this->people(2, firstId: 120, dept: 4);
        foreach ([100, 101, 120, 121] as $u) {
            $this->leave($u);
        }

        $i = $this->detector(['leave_min_steps' => 0])->detect($this->callerAs('department_head', user: 11, dept: 3), $this->clock())->insights[0]->toArray();

        $this->assertSame(2, $this->metric($i, 'Approval steps waiting more than'));
        $this->assertSame('department', $i['audience']);
    }

    public function test_missing_tables_are_data_unavailable(): void
    {
        Schema::drop('hrms_leave_approval_steps');

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_DATA_UNAVAILABLE, $r->state);
    }

    public function test_confidence_is_never_an_ai_estimate(): void
    {
        $this->people(3);
        $this->leave(100);

        $i = $this->detector(['leave_min_steps' => 0])->detect($this->callerAs('hr_manager'), $this->clock())->insights[0];

        $this->assertSame(Taxonomy::CONFIDENCE_RULE_BASED, $i->confidence);
        $this->assertTrue($i->provisional);
    }

    /** M3: a step still reads `pending` after the leave itself was cancelled, rejected or approved out of band; that is not a waiting request. */
    public function test_a_leave_that_is_no_longer_pending_does_not_count_as_waiting(): void
    {
        $this->people(8);
        $waiting = $this->leave(100);                                          // status 'pending'
        $caps = $this->leave(101, leave: ['status' => 'Pending']);             // spelling differs: still waiting
        $this->leave(102, leave: ['status' => 'cancelled']);                   // cancelled out of band
        $this->leave(103, leave: ['status' => 'rejected']);
        $this->leave(104, leave: ['status' => 'approved']);
        $this->leave(105, leave: ['status' => null]);                          // unknown state: not asserted as waiting

        $i = $this->detector(['leave_min_steps' => 0])->detect($this->callerAs('hr_manager'), $this->clock())->insights[0]->toArray();

        $this->assertSame(2, $this->metric($i, 'Approval steps waiting more than'));
        $this->assertSame(2, $this->metric($i, 'Approval steps waiting in total'));
        $this->assertEqualsCanonicalizing([$waiting, $caps], array_map(fn ($e) => $e['entity_id'], array_filter($i['evidence'], fn ($e) => $e['kind'] === 'entity')));
    }

    public function test_when_every_pending_step_belongs_to_a_closed_leave_nothing_is_reported(): void
    {
        $this->people(4);
        foreach ([100, 101, 102] as $u) {
            $this->leave($u, leave: ['status' => 'cancelled']);
        }

        $r = $this->detector(['leave_min_steps' => 0])->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $r->state);
        $this->assertSame([], $r->insights);
    }
}
