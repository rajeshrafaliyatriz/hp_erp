<?php

namespace Tests\Unit\Intelligence\Hr;

use App\Domain\Intelligence\Detectors\Hr\AttendanceHealthDetector;
use App\Domain\Intelligence\Support\Thresholds;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Support\Facades\Schema;
use Tests\Intelligence\Fixtures\HrTables;
use Tests\Intelligence\IntelligenceTestCase;

class AttendanceHealthDetectorTest extends IntelligenceTestCase
{
    use HrTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPeopleTables();
        $this->createHrTables();
    }

    private function detector(array $thresholds = ['attendance_min_employees' => 5, 'attendance_min_days' => 3]): AttendanceHealthDetector
    {
        return new AttendanceHealthDetector($this->db(), Thresholds::with($thresholds));
    }

    /** One complete row (punch in and out) per person per day, for the given days. */
    private function fullDays(array $ids, array $days, int $tenant = 1): void
    {
        foreach ($ids as $id) {
            foreach ($days as $day) {
                $this->insertRow('hrms_attendances', ['user_id' => $id, 'day' => $day, 'punchin_time' => "$day 09:00:00", 'punchout_time' => "$day 17:00:00", 'sub_institute_id' => $tenant]);
            }
        }
    }

    private const DAYS = ['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13'];

    public function test_authorization_is_for_managers_and_above_not_for_employees_or_recruiters(): void
    {
        $d = $this->detector();
        foreach (['reporting_manager', 'department_head', 'hr_executive', 'hr_manager', 'administrator', 'executive', 'auditor'] as $role) {
            $this->assertTrue($d->authorizes($this->callerAs($role)), $role);
        }
        $this->assertFalse($d->authorizes($this->callerAs('employee')));
        $this->assertFalse($d->authorizes($this->callerAs('recruiter')));
    }

    public function test_few_people_in_scope_is_insufficient_not_zero(): void
    {
        $this->people(3);
        $this->fullDays([100, 101, 102], self::DAYS);

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $r->state);
        $this->assertSame([], $r->insights);
        $this->assertFalse($r->coverage->sufficient);
        $this->assertSame(3, $r->coverage->expected);
    }

    public function test_few_attendance_days_is_insufficient(): void
    {
        $ids = $this->people(6);
        $this->fullDays($ids, ['2026-11-10', '2026-11-11']);

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $r->state);
        $this->assertStringContainsString('2 day(s)', (string) $r->reason);
    }

    public function test_complete_attendance_is_ok_with_no_insights_and_full_coverage(): void
    {
        $ids = $this->people(6);
        $this->fullDays($ids, self::DAYS);

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $r->state);
        $this->assertSame([], $r->insights);
        $this->assertSame(100.0, $r->coverage->percent());
        $this->assertTrue($r->coverage->sufficient);
    }

    public function test_low_participation_is_flagged_high_with_evidence_coverage_and_no_entity_references(): void
    {
        $ids = $this->people(10);
        $this->fullDays(array_slice($ids, 0, 3), self::DAYS);

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $r->state);
        $this->assertCount(1, $r->insights);
        $i = $r->insights[0];
        $this->assertSame('attendance_participation', $i->category);
        $this->assertSame('high', $i->severity);
        $this->assertSame(Taxonomy::CONFIDENCE_RULE_BASED, $i->confidence);
        $this->assertSame('organization', $i->audience);
        $this->assertSame(10, $i->population);
        $a = $i->toArray();
        $this->assertSame(30.0, $r->coverage->percent());
        $this->assertSame(3, $this->metric($a, 'People with attendance recorded'));
        $this->assertSame([], $this->entityTypes($a), 'attendance insights are aggregates');
        $this->assertSame('/dashboard/attendance', $i->deepLink);
        $this->assertContains(Taxonomy::SURFACE_ATTENDANCE, $i->surfaces);
    }

    public function test_missing_punch_out_counts_past_days_only_never_today(): void
    {
        $ids = $this->people(10);
        $this->fullDays($ids, self::DAYS);                                   // 40 complete past rows
        foreach (array_slice($ids, 0, 10) as $id) {                          // 10 past rows with no punch-out = 10 of 50 rows = 20%
            $this->insertRow('hrms_attendances', ['user_id' => $id, 'day' => '2026-11-14', 'punchin_time' => '2026-11-14 09:00:00', 'punchout_time' => null, 'sub_institute_id' => 1]);
        }
        foreach ($ids as $id) {                                              // TODAY with no punch-out: a person still at work, never counted
            $this->insertRow('hrms_attendances', ['user_id' => $id, 'day' => '2026-11-21', 'punchin_time' => '2026-11-21 09:00:00', 'punchout_time' => null, 'sub_institute_id' => 1]);
        }

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertCount(1, $r->insights);
        $i = $r->insights[0]->toArray();
        $this->assertSame('attendance_missing_punch_out', $i['category']);
        $this->assertSame('attention', $i['severity']);
        $this->assertSame(10, $this->metric($i, 'Past-day records with no punch-out'));
        $this->assertSame(50, $this->metric($i, 'Past-day records reviewed'));
        $this->assertSame(20.0, $this->metric($i, 'Share without punch-out'));
    }

    public function test_duplicate_person_days_are_flagged(): void
    {
        $ids = $this->people(10);
        $this->fullDays($ids, self::DAYS);                                   // 40 rows
        foreach (array_slice($ids, 0, 3) as $id) {                           // 3 duplicates
            $this->insertRow('hrms_attendances', ['user_id' => $id, 'day' => '2026-11-10', 'punchin_time' => '2026-11-10 09:05:00', 'punchout_time' => '2026-11-10 17:00:00', 'sub_institute_id' => 1]);
        }

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertCount(1, $r->insights);
        $i = $r->insights[0]->toArray();
        $this->assertSame('attendance_duplicates', $i['category']);
        $this->assertSame(3, $this->metric($i, 'Person-days with duplicate rows'));
        $this->assertSame(3, $this->metric($i, 'Surplus rows'));
    }

    public function test_other_tenants_rows_never_count_even_for_the_same_user_ids(): void
    {
        $ids = $this->people(6);
        $this->fullDays($ids, ['2026-11-10', '2026-11-11', '2026-11-12']);   // exactly 3 genuine days
        // Tenant 2: its own people, plus rows stamped tenant 2 that name tenant 1's user ids on four more days.
        $other = $this->people(6, tenant: 2, firstId: 200, manager: 20);
        $this->fullDays($other, self::DAYS, 2);
        $this->fullDays($ids, ['2026-11-15', '2026-11-16', '2026-11-17', '2026-11-18'], 2);
        // Tenant 2 duplicates must not create duplicates for tenant 1.
        $this->fullDays([100], ['2026-11-10'], 2);

        $r = $this->detector(['attendance_min_employees' => 5, 'attendance_min_days' => 4])->detect($this->callerAs('hr_manager', tenant: 1), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $r->state, 'tenant 2 days must not make tenant 1 look sufficient');
        $this->assertSame(6, $r->coverage->expected);

        $ok = $this->detector()->detect($this->callerAs('hr_manager', tenant: 1), $this->clock());
        $this->assertSame([], $ok->insights, 'tenant 2 duplicates must not appear in tenant 1');
    }

    public function test_null_tenant_deleted_inactive_and_terminated_rows_are_excluded(): void
    {
        $ids = $this->people(6);
        $this->insertUser(150, 1, ['status' => 0, 'reporting_manager_id' => 10, 'department_id' => 3]);
        $this->insertUser(151, 1, ['terminated_date' => '2026-01-01', 'reporting_manager_id' => 10, 'department_id' => 3]);
        $this->fullDays($ids, ['2026-11-10', '2026-11-11', '2026-11-12']);                  // 3 genuine days
        $this->fullDays([150, 151], self::DAYS);                                            // not active: ignored
        $this->insertRow('hrms_attendances', ['user_id' => 100, 'day' => '2026-11-15', 'punchin_time' => '2026-11-15 09:00:00', 'punchout_time' => '2026-11-15 17:00:00', 'sub_institute_id' => null]);
        $this->insertRow('hrms_attendances', ['user_id' => 100, 'day' => '2026-11-16', 'punchin_time' => '2026-11-16 09:00:00', 'punchout_time' => '2026-11-16 17:00:00', 'sub_institute_id' => 1, 'deleted_at' => '2026-11-17 00:00:00']);

        $r = $this->detector(['attendance_min_employees' => 5, 'attendance_min_days' => 4])->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $r->state, 'NULL-tenant and soft-deleted days must not count');
        $this->assertSame(6, $r->coverage->expected, 'inactive and terminated people are not in scope');
    }

    public function test_a_manager_sees_only_direct_reports_and_a_head_only_their_department(): void
    {
        $team = $this->people(6, firstId: 100, manager: 10, dept: 3);
        $this->people(6, firstId: 120, manager: 99, dept: 3);       // same department, another manager
        $this->people(6, firstId: 140, manager: 98, dept: 4);       // another department
        foreach ([[100, 6], [120, 6], [140, 6]] as [$first, $n]) {
            $this->fullDays(range($first, $first + $n - 1), self::DAYS);
        }

        $manager = $this->detector()->detect($this->callerAs('reporting_manager', user: 10, dept: 3), $this->clock());
        $head = $this->detector()->detect($this->callerAs('department_head', user: 11, dept: 3), $this->clock());
        $org = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(6, $manager->coverage->expected);
        $this->assertSame(12, $head->coverage->expected);
        $this->assertSame(18, $org->coverage->expected);
        $this->assertSame(Taxonomy::STATE_OK, $manager->state);
    }

    public function test_a_department_head_with_no_department_sees_nobody_and_is_insufficient(): void
    {
        $this->people(8);
        $this->fullDays(range(100, 107), self::DAYS);

        $r = $this->detector()->detect($this->callerAs('department_head', dept: null), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $r->state);
        $this->assertSame(0, $r->coverage->expected);
    }

    public function test_missing_attendance_table_is_data_unavailable(): void
    {
        Schema::drop('hrms_attendances');
        $this->people(6);

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_DATA_UNAVAILABLE, $r->state);
    }

    public function test_insight_keys_are_stable_and_scope_aware(): void
    {
        $ids = $this->people(10);
        $this->fullDays(array_slice($ids, 0, 3), self::DAYS);

        $a = $this->detector()->detect($this->callerAs('hr_manager', tenant: 1), $this->clock())->insights[0]->key;
        $b = $this->detector()->detect($this->callerAs('hr_manager', tenant: 1), $this->clock())->insights[0]->key;

        $this->assertSame($a, $b);
        $this->assertStringContainsString('t1', $a);
        $this->assertStringContainsString('organization0', $a);
    }

    /** The real service and the real small-group rule, over a detector with a small minimum so a team of 12 is assessed. */
    private function through(string $role, array $caller = []): array
    {
        $service = new \App\Domain\Intelligence\InsightService([$this->detector()], new \App\Domain\Intelligence\Support\SmallGroupGuard(5), $this->clock());

        return $service->run($this->callerAs($role, ...$caller));
    }

    /** H2: the population of a finding is the people the FINDING is about, not everyone who has any row. */
    public function test_one_persons_duplicate_punches_in_a_team_of_twelve_is_suppressed_for_every_role_the_small_group_rule_covers(): void
    {
        $ids = $this->people(12);
        $this->fullDays($ids, self::DAYS);                                   // 48 clean rows
        foreach ([self::DAYS[0], self::DAYS[1], self::DAYS[2]] as $day) {    // ONE person (100) duplicates on three days: 3 surplus rows = 5.9%
            $this->insertRow('hrms_attendances', ['user_id' => 100, 'day' => $day, 'punchin_time' => "$day 09:05:00", 'punchout_time' => "$day 17:00:00", 'sub_institute_id' => 1]);
        }

        $detected = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());
        $dup = collect($detected->insights)->firstWhere('category', 'attendance_duplicates');
        $this->assertNotNull($dup, 'the finding exists');
        $this->assertSame(1, $dup->population, 'it is about one person, not the twelve who have rows');

        foreach (['reporting_manager' => ['user' => 10], 'department_head' => ['user' => 11, 'dept' => 3], 'executive' => [], 'auditor' => []] as $role => $caller) {
            $out = $this->through($role, $caller);
            $this->assertNotContains('attendance_duplicates', array_map(fn ($i) => $i->category, $out['insights']), "$role must not receive a finding about one person");
            $this->assertGreaterThanOrEqual(1, $out['suppressed'], $role);
        }

        $hr = $this->through('hr_manager');
        $this->assertContains('attendance_duplicates', array_map(fn ($i) => $i->category, $hr['insights']), 'HR may open the records anyway: not suppressed');
    }

    public function test_one_persons_missing_punch_outs_are_about_one_person_too(): void
    {
        $ids = $this->people(12);
        $this->fullDays($ids, self::DAYS);                                   // 48 clean rows
        foreach (['2026-11-14', '2026-11-15', '2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20'] as $day) {   // seven past rows with no punch-out, and two more below: 9 of 57 past rows = 15.8% (over the 15% limit)
            $this->insertRow('hrms_attendances', ['user_id' => 100, 'day' => $day, 'punchin_time' => "$day 09:00:00", 'punchout_time' => null, 'sub_institute_id' => 1]);
        }
        foreach (['2026-11-04', '2026-11-05'] as $day) {
            $this->insertRow('hrms_attendances', ['user_id' => 100, 'day' => $day, 'punchin_time' => "$day 09:00:00", 'punchout_time' => null, 'sub_institute_id' => 1]);
        }

        $missing = collect($this->detector()->detect($this->callerAs('hr_manager'), $this->clock())->insights)->firstWhere('category', 'attendance_missing_punch_out');

        $this->assertNotNull($missing);
        $this->assertSame(1, $missing->population);
        $this->assertNotContains('attendance_missing_punch_out', array_map(fn ($i) => $i->category, $this->through('reporting_manager', ['user' => 10])['insights']));
    }

    public function test_a_finding_spread_over_many_people_is_not_suppressed(): void
    {
        $ids = $this->people(12);
        $this->fullDays($ids, self::DAYS);
        foreach (array_slice($ids, 0, 6) as $id) {                           // six different people each duplicate one day: 6 surplus of 54 = 11%
            $this->insertRow('hrms_attendances', ['user_id' => $id, 'day' => '2026-11-10', 'punchin_time' => '2026-11-10 09:05:00', 'punchout_time' => '2026-11-10 17:00:00', 'sub_institute_id' => 1]);
        }

        $dup = collect($this->detector()->detect($this->callerAs('hr_manager'), $this->clock())->insights)->firstWhere('category', 'attendance_duplicates');

        $this->assertSame(6, $dup->population);
        $this->assertContains('attendance_duplicates', array_map(fn ($i) => $i->category, $this->through('reporting_manager', ['user' => 10])['insights']));
    }
}
