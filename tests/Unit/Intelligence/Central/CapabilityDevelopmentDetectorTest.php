<?php

namespace Tests\Unit\Intelligence\Central;

use App\Domain\Intelligence\Detectors\Hr\CapabilityDevelopmentDetector;
use App\Domain\Intelligence\InsightService;
use App\Domain\Intelligence\Support\SmallGroupGuard;
use App\Domain\Intelligence\Support\Thresholds;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Support\Facades\Schema;
use Tests\Intelligence\Fixtures\CentralTables;
use Tests\Intelligence\Fixtures\HrTables;
use Tests\Intelligence\IntelligenceTestCase;

/** Fixed today = 2026-11-21. */
class CapabilityDevelopmentDetectorTest extends IntelligenceTestCase
{
    use HrTables, CentralTables;

    private const PLANS = 's_competency_development_plans';
    private const ASSESSMENTS = 's_competency_assessments';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPeopleTables();
        $this->createCentralTables();
    }

    private function detector(array $t = ['capability_min_records' => 3]): CapabilityDevelopmentDetector
    {
        return new CapabilityDevelopmentDetector($this->db(), Thresholds::with($t));
    }

    /** @return array<string, int> People 100..105 report to manager 10 in department 3. */
    private function seedCapability(): array
    {
        $this->people(6);
        $r = [];
        $r['plan_a'] = $this->record(self::PLANS, 100, '2026-11-01', 'active');
        $r['plan_b'] = $this->record(self::PLANS, 101, '2026-11-10', 'overdue');             // a stored 'overdue' is still open
        $this->record(self::PLANS, 102, '2026-11-05', 'completed');
        $this->record(self::PLANS, 103, '2026-11-05', 'on_hold');                            // parked on purpose
        $this->record(self::PLANS, 104, '2026-12-01', 'active');
        $this->record(self::PLANS, 105, null, 'active');                                     // open, no due date: not measured
        $this->record(self::PLANS, 100, '2026-11-01', 'active', 1, ['completed_at' => '2026-11-03 00:00:00']);   // closed by its completion time
        $this->record(self::PLANS, 100, '2026-11-01', 'active', 1, ['deleted_at' => '2026-11-03 00:00:00']);
        $this->record(self::PLANS, 101, '2026-11-01', 'archived');
        $this->record(self::PLANS, 101, '2026-11-01', 'something_new');                      // an unknown status is never claimed to be open
        $this->record(self::PLANS, 100, '2026-10-01', 'active', 2);                          // another tenant's

        $r['assessment_a'] = $this->record(self::ASSESSMENTS, 100, '2026-11-02', 'open');
        $r['assessment_b'] = $this->record(self::ASSESSMENTS, 101, '2026-11-12', 'in_progress');
        $this->record(self::ASSESSMENTS, 102, '2026-11-05', 'completed');
        $this->record(self::ASSESSMENTS, 103, '2026-12-05', 'open');
        $this->record(self::ASSESSMENTS, 100, '2026-10-01', 'open', 2);

        return $r;
    }

    public function test_happy_path_reports_plans_and_assessments_past_due_with_evidence_and_coverage(): void
    {
        $x = $this->seedCapability();

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());
        $by = $this->byCategory($r->insights);

        $this->assertSame(Taxonomy::STATE_OK, $r->state);
        $this->assertSame(6, $r->coverage->observed, 'open records that carry a due date');
        $this->assertSame(7, $r->coverage->expected, 'open records in scope');
        $this->assertSame(['development_plan_overdue', 'assessment_overdue'], array_keys($by));

        $this->assertSame(2, $this->metric($by['development_plan_overdue'], 'Open development plans past their due date'));
        $this->assertSame(4, $this->metric($by['development_plan_overdue'], 'Development plans still open'));
        $this->assertSame(1, $this->metric($by['development_plan_overdue'], 'Open records with no due date'));
        $this->assertSame('2026-11-01', $this->metric($by['development_plan_overdue'], 'Oldest due date'));
        $this->assertSame(2, $this->metric($by['assessment_overdue'], 'Open assessments past their due date'));
        $this->assertSame(3, $this->metric($by['assessment_overdue'], 'Assessments still open'));
        $this->assertSame('high', $by['development_plan_overdue']['severity']);
        $this->assertSame('rule_based', $by['development_plan_overdue']['confidence']);
        $this->assertStringContainsString('2 person(s)', $by['assessment_overdue']['what_happened']);

        $planRefs = array_map(static fn ($e) => $e['entity_id'], array_filter($by['development_plan_overdue']['evidence'], static fn ($e) => $e['kind'] === 'entity'));
        $this->assertSame([$x['plan_a'], $x['plan_b']], array_values($planRefs));
        $this->assertSame(['development_plan'], array_values(array_unique($this->entityTypes($by['development_plan_overdue']))));
        $this->assertSame(['competency_assessment'], array_values(array_unique($this->entityTypes($by['assessment_overdue']))));

        $this->assertSame('/module/capability-intelligence/dashboard', $by['development_plan_overdue']['deep_link']);
        $this->assertSame('/module/lms/assessments', $by['assessment_overdue']['deep_link']);
        foreach ($by as $i) {
            $this->assertSame(['capability', 'me_dashboard'], $i['surfaces']);
        }
    }

    public function test_the_employee_sees_only_their_own_records(): void
    {
        $this->seedCapability();

        $r = $this->detector()->detect($this->callerAs('employee', 1, 100), $this->clock());
        $by = $this->byCategory($r->insights);

        $this->assertSame(1, $this->metric($by['development_plan_overdue'], 'Open development plans past their due date'));
        $this->assertSame(1, $this->metric($by['assessment_overdue'], 'Open assessments past their due date'));
        $this->assertSame('self', $by['development_plan_overdue']['audience']);
        $this->assertSame('/profile', $by['development_plan_overdue']['deep_link']);
        $this->assertSame('/module/lms/assessments', $by['assessment_overdue']['deep_link']);
        $this->assertSame('A development plan of yours is past its due date', $by['development_plan_overdue']['title']);
    }

    public function test_each_role_sees_the_people_its_scope_allows(): void
    {
        $this->seedCapability();
        $this->insertUser(120, 1, ['reporting_manager_id' => 100, 'department_id' => 3]);
        $this->insertUser(130, 1, ['reporting_manager_id' => 11, 'department_id' => 4]);
        $mine = $this->record(self::PLANS, 120, '2026-10-01', 'active');
        $theirs = $this->record(self::PLANS, 130, '2026-10-02', 'active');
        $d = $this->detector();
        $overdue = function (string $role) use ($d): array {
            $by = $this->byCategory($d->detect($this->callerAs($role, 1, 10, 3), $this->clock())->insights);
            $ids = array_map(static fn ($e) => $e['entity_id'], array_filter($by['development_plan_overdue']['evidence'], static fn ($e) => $e['kind'] === 'entity'));

            return [$this->metric($by['development_plan_overdue'], 'Open development plans past their due date'), $ids];
        };

        [$n, $ids] = $overdue('reporting_manager');
        $this->assertSame(2, $n);
        $this->assertNotContains($mine, $ids);
        $this->assertNotContains($theirs, $ids);

        foreach (['department_head', 'hr_executive'] as $role) {
            [$n, $ids] = $overdue($role);
            $this->assertSame(3, $n, $role);
            $this->assertContains($mine, $ids, $role);
            $this->assertNotContains($theirs, $ids, $role);
        }
        foreach (['hr_manager', 'administrator'] as $role) {
            [$n, $ids] = $overdue($role);
            $this->assertSame(4, $n, $role);
            $this->assertContains($theirs, $ids, $role);
        }
    }

    public function test_executive_and_auditor_get_aggregates_without_references(): void
    {
        $this->seedCapability();
        foreach (['executive', 'auditor'] as $role) {
            $r = $this->detector()->detect($this->callerAs($role), $this->clock());
            $this->assertCount(2, $r->insights, $role);
            foreach ($r->insights as $i) {
                $this->assertSame([], $this->entityTypes($i->toArray()), "$role must get no record references");
            }
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

    public function test_insufficient_when_too_few_open_records_have_a_due_date(): void
    {
        $this->people(6);
        $this->record(self::PLANS, 100, '2026-11-01', 'active');
        $this->record(self::ASSESSMENTS, 101, '2026-11-01', 'open');
        $this->record(self::PLANS, 102, null, 'active');   // undated: does not add to the measurable base

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $r->state);
        $this->assertSame(2, $r->coverage->observed);
        $this->assertSame(3, $r->coverage->expected);
        $this->assertFalse($r->coverage->sufficient);
        $this->assertSame([], $r->insights);
    }

    public function test_an_employee_with_nothing_open_is_not_applicable(): void
    {
        $this->seedCapability();

        $r = $this->detector()->detect($this->callerAs('employee', 1, 102), $this->clock());   // only completed records

        $this->assertSame(Taxonomy::STATE_NOT_APPLICABLE, $r->state);
    }

    public function test_enough_records_and_nothing_overdue_is_ok_with_no_insights(): void
    {
        $this->people(6);
        $this->record(self::PLANS, 100, '2026-12-01', 'active');
        $this->record(self::PLANS, 101, '2026-11-21', 'active');               // due today is not overdue
        $this->record(self::ASSESSMENTS, 102, '2026-12-02', 'open');
        $this->record(self::ASSESSMENTS, 103, '2026-10-01', 'completed');

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $r->state);
        $this->assertSame([], $r->insights);
        $this->assertSame(3, $r->coverage->observed);
    }

    public function test_another_tenants_records_never_count_even_when_they_name_this_tenants_people(): void
    {
        $this->people(6);
        $this->record(self::PLANS, 100, '2026-12-01', 'active');
        $this->record(self::PLANS, 101, '2026-12-01', 'active');
        $this->record(self::ASSESSMENTS, 102, '2026-12-01', 'open');
        $this->people(6, 2, 300, 20, 3);
        for ($i = 0; $i < 6; $i++) {
            $this->record(self::PLANS, 300 + $i, '2026-10-01', 'active', 2);
            $this->record(self::ASSESSMENTS, 100 + $i, '2026-10-01', 'open', 2);   // tenant 2 rows naming tenant 1's people
        }

        $mine = $this->detector()->detect($this->callerAs('hr_manager', 1), $this->clock());
        $theirs = $this->detector()->detect($this->callerAs('hr_manager', 2, 20), $this->clock());

        $this->assertSame([], $mine->insights);
        $this->assertSame(3, $mine->coverage->observed);
        $by = $this->byCategory($theirs->insights);
        $this->assertSame(6, $this->metric($by['development_plan_overdue'], 'Open development plans past their due date'));
        $this->assertArrayNotHasKey('assessment_overdue', $by, "tenant 2's assessments all name tenant 1's people, who are not in tenant 2's scope");
    }

    public function test_inactive_terminated_and_deleted_people_are_excluded(): void
    {
        $this->people(4);
        $this->insertUser(150, 1, ['status' => 0, 'reporting_manager_id' => 10, 'department_id' => 3]);
        $this->insertUser(151, 1, ['terminated_date' => '2026-03-01', 'reporting_manager_id' => 10, 'department_id' => 3]);
        $this->insertUser(152, 1, ['deleted_at' => '2026-03-01 00:00:00', 'reporting_manager_id' => 10, 'department_id' => 3]);
        $this->record(self::PLANS, 100, '2026-11-01', 'active');
        $this->record(self::PLANS, 101, '2026-12-01', 'active');
        $this->record(self::ASSESSMENTS, 102, '2026-12-01', 'open');
        foreach ([150, 151, 152] as $u) {
            $this->record(self::PLANS, $u, '2026-11-01', 'active');
        }
        $this->record(self::PLANS, null, '2026-11-01', 'active');   // no person on the record

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());
        $by = $this->byCategory($r->insights);

        $this->assertSame(3, $r->coverage->observed);
        $this->assertSame(1, $this->metric($by['development_plan_overdue'], 'Open development plans past their due date'));
        $this->assertArrayNotHasKey('assessment_overdue', $by);
    }

    public function test_one_source_missing_still_reports_the_other(): void
    {
        $this->seedCapability();
        Schema::drop(self::ASSESSMENTS);
        $noAssessments = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());
        Schema::table(self::PLANS, fn ($t) => $t->dropColumn('completed_at'));
        $this->assertNoSqlLeak($this->serialised($noAssessments));

        $this->assertSame(Taxonomy::STATE_OK, $noAssessments->state);
        $this->assertSame(['development_plan_overdue'], array_keys($this->byCategory($noAssessments->insights)));

        // With the plans' completion column also gone, no source is readable at all.
        $none = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());
        $this->assertSame(Taxonomy::STATE_DATA_UNAVAILABLE, $none->state);
        $json = $this->serialised($none);
        $this->assertNoSqlLeak($json);
        $this->assertStringNotContainsString('completed_at', $json);
        $this->assertStringNotContainsString('s_competency', $json);
    }

    public function test_the_result_names_no_table_column_title_or_person(): void
    {
        $this->seedCapability();
        $json = $this->serialised($this->detector()->detect($this->callerAs('hr_manager'), $this->clock()));

        foreach (['s_competency_development_plans', 's_competency_assessments', 'due_date', 'completed_at', 'user_id', 'sub_institute_id'] as $needle) {
            $this->assertStringNotContainsString($needle, $json, $needle);
        }
        $this->assertStringContainsString('Development plans', $json);
        $this->assertStringContainsString('Competency assessments', $json);
    }

    public function test_it_makes_no_claim_about_gaps_or_skills(): void
    {
        $this->seedCapability();
        $json = strtolower($this->serialised($this->detector()->detect($this->callerAs('hr_manager'), $this->clock())));

        foreach (['gap', 'proficiency', 'kasba', 'readiness', 'will '] as $needle) {
            $this->assertStringNotContainsString($needle, $json, $needle);
        }
    }

    public function test_a_small_group_is_withheld_from_a_team_manager_but_not_from_the_person_or_hr(): void
    {
        $this->people(3);
        foreach ([100, 101, 102] as $u) {
            $this->record(self::PLANS, $u, '2026-11-01', 'active');
        }
        $service = new InsightService([$this->detector()], SmallGroupGuard::fromThresholds(Thresholds::defaults()), $this->clock());

        $manager = $service->run($this->callerAs('reporting_manager'));
        $self = $service->run($this->callerAs('employee', 1, 100));
        $hr = $service->run($this->callerAs('hr_manager'));

        $this->assertSame([], $manager['insights']);
        $this->assertSame(1, $manager['suppressed']);
        $this->assertCount(1, $self['insights']);
        $this->assertCount(1, $hr['insights']);
    }
}
