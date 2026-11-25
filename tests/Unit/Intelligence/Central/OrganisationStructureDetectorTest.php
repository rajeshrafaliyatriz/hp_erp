<?php

namespace Tests\Unit\Intelligence\Central;

use App\Domain\Intelligence\Detectors\Hr\OrganisationStructureDetector;
use App\Domain\Intelligence\InsightService;
use App\Domain\Intelligence\Support\SmallGroupGuard;
use App\Domain\Intelligence\Support\Thresholds;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Support\Facades\Schema;
use Tests\Intelligence\Fixtures\CentralTables;
use Tests\Intelligence\Fixtures\HrTables;
use Tests\Intelligence\IntelligenceTestCase;

class OrganisationStructureDetectorTest extends IntelligenceTestCase
{
    use HrTables, CentralTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPeopleTables();
        $this->createCentralTables();
    }

    private function detector(array $t = []): OrganisationStructureDetector
    {
        return new OrganisationStructureDetector($this->db(), Thresholds::with($t));
    }

    /** A person with every structure field filled in, unless overridden. */
    private function person(int $id, int $tenant = 1, array $over = []): void
    {
        $this->insertUser($id, $tenant, array_merge(['reporting_manager_id' => 10, 'department_id' => 3, 'jobtitle_id' => 7], $over));
    }

    private function dept(int $id, ?int $head, int $tenant = 1, array $extra = []): void
    {
        $this->insertRow('hrms_departments', array_merge(['id' => $id, 'department' => 'D' . $id, 'status' => 1, 'head_user_id' => $head, 'sub_institute_id' => $tenant], $extra));
    }

    /** 12 people; 3 without manager (one NULL, two 0), 2 without department, 4 without job title; 3 active departments, 2 of them headless. */
    private function seedHappy(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $over = [];
            if ($i < 3) {
                $over['reporting_manager_id'] = $i === 0 ? null : 0;
            }
            if ($i >= 3 && $i < 5) {
                $over['department_id'] = $i === 3 ? null : 0;
            }
            if ($i >= 5 && $i < 9) {
                $over['jobtitle_id'] = $i === 5 ? null : 0;
            }
            $this->person(100 + $i, 1, $over);
        }
        $this->dept(1, 100);
        $this->dept(2, null);
        $this->dept(3, 0);
        $this->dept(4, null, 1, ['deleted_at' => '2026-01-01 00:00:00']);   // deleted: not counted
        $this->dept(5, null, 1, ['status' => 0]);                           // inactive: not counted
        $this->dept(6, null, 2);                                            // another tenant's
    }

    public function test_happy_path_counts_each_gap_with_evidence_and_coverage(): void
    {
        $this->seedHappy();

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());
        $by = $this->byCategory($r->insights);

        $this->assertSame(Taxonomy::STATE_OK, $r->state);
        $this->assertSame(12, $r->coverage->observed);
        $this->assertTrue($r->coverage->sufficient);
        $this->assertSame(['org_no_reporting_manager', 'org_no_department', 'org_no_job_title', 'org_department_without_head'], array_keys($by));

        $this->assertSame(3, $this->metric($by['org_no_reporting_manager'], 'People with no reporting manager'));
        $this->assertSame(2, $this->metric($by['org_no_department'], 'People with no department'));
        $this->assertSame(4, $this->metric($by['org_no_job_title'], 'People with no job title'));
        $this->assertEquals(25.0, $this->metric($by['org_no_reporting_manager'], 'Share of active people'));
        $this->assertSame('attention', $by['org_no_reporting_manager']['severity'], '25% reaches the 20% line');
        $this->assertSame('info', $by['org_no_department']['severity'], '16.7% is below it');
        $this->assertSame('attention', $by['org_no_job_title']['severity']);
        $this->assertSame(2, $this->metric($by['org_department_without_head'], 'Departments with no head'));
        $this->assertSame(3, $this->metric($by['org_department_without_head'], 'Active departments assessed'), 'deleted, inactive and other-tenant departments are not counted');
        $this->assertStringContainsString('2 of 3', $by['org_department_without_head']['what_happened']);

        foreach ($by as $i) {
            $this->assertSame('measured', $i['confidence']);
            $this->assertSame('hr', $i['domain']);
            $this->assertSame(['organisation', 'hr_dashboard'], $i['surfaces']);
        }
        $this->assertSame(['employee'], array_values(array_unique($this->entityTypes($by['org_no_reporting_manager']))));
        $this->assertSame(['department'], array_values(array_unique($this->entityTypes($by['org_department_without_head']))));
        $this->assertSame('/module/organizational-management/user-management/employee-directory', $by['org_no_job_title']['deep_link']);
        $this->assertSame('/module/organizational-management/organization-setup/department-management', $by['org_department_without_head']['deep_link']);
        $this->assertStringContainsString('job-title field', $by['org_no_job_title']['what_happened'], 'only what jobtitle_id shows is claimed, and it says so');
    }

    public function test_entity_references_are_capped_at_ten(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->person(100 + $i, 1, ['reporting_manager_id' => null]);
        }

        $r = $this->detector()->detect($this->callerAs('administrator'), $this->clock());
        $by = $this->byCategory($r->insights);

        $this->assertCount(10, $this->entityTypes($by['org_no_reporting_manager']));
        $this->assertSame(25, $this->metric($by['org_no_reporting_manager'], 'People with no reporting manager'));
    }

    public function test_insufficient_below_the_minimum_people_says_so_with_coverage(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->person(100 + $i, 1, ['reporting_manager_id' => null]);
        }

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $r->state);
        $this->assertSame([], $r->insights);
        $this->assertSame(4, $r->coverage->observed);
        $this->assertFalse($r->coverage->sufficient);
        $this->assertNotNull($r->reason);
    }

    public function test_complete_structure_is_ok_with_no_insights(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->person(100 + $i);
        }
        $this->dept(3, 100);

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $r->state);
        $this->assertSame([], $r->insights, 'checked, nothing needs attention');
        $this->assertSame(6, $r->coverage->observed);
    }

    public function test_another_tenants_people_and_departments_never_count_even_with_colliding_numbers(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->person(100 + $i);
        }
        $this->dept(3, 100);
        // Tenant 2 uses the SAME manager id and department number, and every one of its people is incomplete.
        for ($i = 0; $i < 20; $i++) {
            $this->person(300 + $i, 2, ['reporting_manager_id' => null, 'department_id' => null, 'jobtitle_id' => null]);
        }
        $this->dept(30, null, 2);
        $this->dept(31, null, 2);

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame([], $r->insights);
        $this->assertSame(6, $r->coverage->observed);
        $other = $this->detector()->detect($this->callerAs('hr_manager', 2, 10), $this->clock());
        $this->assertSame(20, $other->coverage->observed);
        $this->assertSame(20, $this->metric($this->byCategory($other->insights)['org_no_reporting_manager'], 'People with no reporting manager'));
    }

    public function test_inactive_terminated_and_deleted_people_are_not_assessed(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->person(100 + $i);
        }
        $this->person(150, 1, ['status' => 0, 'reporting_manager_id' => null]);
        $this->person(151, 1, ['terminated_date' => '2026-03-01', 'reporting_manager_id' => null]);
        $this->person(152, 1, ['deleted_at' => '2026-03-01 00:00:00', 'reporting_manager_id' => null]);
        $this->person(153, 1, ['status' => null, 'reporting_manager_id' => null]);

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(6, $r->coverage->observed);
        $this->assertSame([], $r->insights);
    }

    public function test_only_hr_administrator_executive_and_auditor_are_authorised(): void
    {
        $d = $this->detector();
        foreach (['hr_manager', 'hr_executive', 'administrator', 'executive', 'auditor'] as $role) {
            $this->assertTrue($d->authorizes($this->callerAs($role)), $role);
        }
        foreach (['employee', 'reporting_manager', 'department_head', 'recruiter', 'wizard'] as $role) {
            $this->assertFalse($d->authorizes($this->callerAs($role)), $role);
        }
    }

    public function test_hr_executive_sees_only_their_department_and_only_its_head_gap(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->person(100 + $i, 1, ['reporting_manager_id' => null]);                    // department 3
        }
        for ($i = 0; $i < 8; $i++) {
            $this->person(200 + $i, 1, ['department_id' => 4, 'reporting_manager_id' => null]);   // department 4: not theirs
        }
        $this->dept(3, null);
        $this->dept(4, null);

        $r = $this->detector()->detect($this->callerAs('hr_executive', 1, 10, 3), $this->clock());
        $by = $this->byCategory($r->insights);

        $this->assertSame(6, $r->coverage->observed);
        $this->assertSame(6, $this->metric($by['org_no_reporting_manager'], 'People with no reporting manager'));
        $this->assertSame(1, $this->metric($by['org_department_without_head'], 'Departments with no head'));
        $this->assertSame(1, $this->metric($by['org_department_without_head'], 'Active departments assessed'));
        $ids = array_map(static fn ($e) => $e['entity_id'], array_filter($by['org_no_reporting_manager']['evidence'], static fn ($e) => $e['kind'] === 'entity'));
        $this->assertSame(range(100, 105), array_values($ids));
        $this->assertSame('department', $by['org_no_reporting_manager']['audience']);
    }

    public function test_a_department_scope_caller_with_no_department_sees_nobody(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->person(100 + $i, 1, ['reporting_manager_id' => null]);
        }
        $this->dept(3, null);

        $r = $this->detector()->detect($this->callerAs('hr_executive', 1, 10, null), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $r->state);
        $this->assertSame(0, $r->coverage->observed);
        $this->assertSame([], $r->insights);
    }

    public function test_executive_and_auditor_get_aggregates_with_no_entity_references(): void
    {
        $this->seedHappy();
        foreach (['executive', 'auditor'] as $role) {
            $r = $this->detector()->detect($this->callerAs($role), $this->clock());
            $this->assertNotEmpty($r->insights, $role);
            foreach ($r->insights as $i) {
                $this->assertSame([], $this->entityTypes($i->toArray()), "$role must get no record references");
                $this->assertSame('organization', $i->audience);
            }
        }
    }

    public function test_small_groups_are_withheld_from_aggregate_only_roles_but_not_from_hr(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->person(100 + $i, 1, $i < 2 ? ['reporting_manager_id' => null] : []);   // only 2 people lack a manager
        }
        $guard = SmallGroupGuard::fromThresholds(Thresholds::defaults());
        $service = new InsightService([$this->detector()], $guard, $this->clock());

        $exec = $service->run($this->callerAs('executive'));
        $hr = $service->run($this->callerAs('hr_manager'));

        $this->assertSame([], $exec['insights'], 'a gap affecting 2 people is withheld from an aggregate-only caller');
        $this->assertSame(1, $exec['suppressed']);
        $this->assertCount(1, $hr['insights']);
        $this->assertSame(0, $hr['suppressed']);
    }

    public function test_missing_departments_table_still_reports_the_people_measures(): void
    {
        $this->seedHappy();
        Schema::drop('hrms_departments');

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $r->state);
        $this->assertSame(['org_no_reporting_manager', 'org_no_department', 'org_no_job_title'], array_keys($this->byCategory($r->insights)));
        $this->assertNoSqlLeak($this->serialised($r));
    }

    public function test_a_missing_people_column_is_data_unavailable_and_leaks_no_sql(): void
    {
        $this->seedHappy();
        Schema::table('tbluser', fn ($t) => $t->dropColumn('jobtitle_id'));

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_DATA_UNAVAILABLE, $r->state);
        $json = $this->serialised($r);
        $this->assertNoSqlLeak($json);
        $this->assertStringNotContainsString('jobtitle_id', $json);
        $this->assertStringNotContainsString('tbluser', $json);
    }

    public function test_the_result_names_no_table_and_no_person(): void
    {
        $this->seedHappy();
        $json = $this->serialised($this->detector()->detect($this->callerAs('hr_manager'), $this->clock()));

        foreach (['tbluser', 'hrms_departments', 'reporting_manager_id', 'head_user_id', 'department_id'] as $needle) {
            $this->assertStringNotContainsString($needle, $json, $needle);
        }
        $this->assertStringContainsString('People records', $json);
        $this->assertStringContainsString('Departments', $json);
    }
}
