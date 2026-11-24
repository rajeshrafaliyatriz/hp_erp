<?php

namespace Tests\Unit\Intelligence\Hr;

use App\Domain\Intelligence\Detectors\Hr\OnboardingProbationDetector;
use App\Domain\Intelligence\Support\Thresholds;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Support\Facades\Schema;
use Tests\Intelligence\Fixtures\HrTables;
use Tests\Intelligence\IntelligenceTestCase;

class OnboardingProbationDetectorTest extends IntelligenceTestCase
{
    use HrTables;

    // Fixed today = 2026-11-21; "ending" means probation_end 2026-11-21 .. 2026-12-21 (30 days); "overdue" means before today.
    protected function setUp(): void
    {
        parent::setUp();
        $this->createPeopleTables();
        $this->createHrTables();
    }

    private function detector(array $t = ['onboarding_min_journeys' => 3]): OnboardingProbationDetector
    {
        return new OnboardingProbationDetector($this->db(), Thresholds::with($t));
    }

    private function journey(?int $employee, ?string $probationEnd, string $confirmation = 'pending', int $tenant = 1, ?int $manager = null, array $extra = []): int
    {
        return (int) $this->db()->table('talent_onboarding_journeys')->insertGetId(array_merge(
            ['sub_institute_id' => $tenant, 'employee_id' => $employee, 'manager_id' => $manager, 'joining_date' => '2026-06-01', 'probation_end' => $probationEnd, 'confirmation_status' => $confirmation], $extra,
        ));
    }

    private function task(int $journey, ?string $due, ?string $completedAt = null, string $status = 'pending', int $tenant = 1, array $extra = []): void
    {
        $this->insertRow('talent_onboarding_tasks', array_merge(
            ['journey_id' => $journey, 'sub_institute_id' => $tenant, 'due_date' => $due, 'status' => $status, 'completed_at' => $completedAt], $extra,
        ));
    }

    private function byCategory(array $insights): array
    {
        $out = [];
        foreach ($insights as $i) {
            $out[$i->category] = $i->toArray();
        }

        return $out;
    }

    public function test_probation_buckets_ignore_confirmed_journeys_and_use_the_date_boundaries(): void
    {
        $this->people(8);
        $this->journey(100, '2026-11-21');               // ends today: ending
        $this->journey(101, '2026-12-21');               // last day of the window: ending
        $this->journey(102, '2026-12-22');               // beyond the window
        $this->journey(103, '2026-11-20');               // ended yesterday: overdue
        $this->journey(104, '2026-09-01', 'Pending');    // overdue (spelling)
        $this->journey(105, '2026-11-25', 'confirmed');  // already confirmed
        $this->journey(106, null);                       // no probation date

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());
        $by = $this->byCategory($r->insights);

        $this->assertSame(Taxonomy::STATE_OK, $r->state);
        $this->assertSame(2, $this->metric($by['probation_ending'], 'Journeys matching'));
        $this->assertSame(2, $this->metric($by['probation_overdue'], 'Journeys matching'));
        $this->assertSame('attention', $by['probation_ending']['severity']);
        $this->assertSame('high', $by['probation_overdue']['severity']);
        $this->assertStringContainsString('within 30 days', $by['probation_ending']['what_happened']);
        $this->assertSame(7, $r->coverage->observed);
        $this->assertSame('/module/talent-management/onboarding', $by['probation_ending']['deep_link']);
    }

    public function test_scope_self_team_department_and_organisation(): void
    {
        $this->people(2, firstId: 100, manager: 10, dept: 3);
        $this->people(2, firstId: 120, manager: 77, dept: 3);
        $this->people(2, firstId: 140, manager: 78, dept: 4);
        $this->journey(100, '2026-11-25');                         // direct report of 10
        $this->journey(120, '2026-11-25');                         // same department, other manager
        $this->journey(140, '2026-11-25');                         // other department
        $this->journey(null, '2026-11-25', manager: 10);           // a joiner not yet an employee, but 10 is the named manager
        $this->journey(null, '2026-11-25', manager: 99);           // a joiner of someone else

        $count = fn (string $role, int $user, ?int $dept = 3) => (int) $this->metric(
            $this->byCategory($this->detector(['onboarding_min_journeys' => 1])->detect($this->callerAs($role, user: $user, dept: $dept), $this->clock())->insights)['probation_ending'] ?? [],
            'Journeys matching',
        );

        $this->assertSame(1, $count('employee', 100));
        $this->assertSame(2, $count('reporting_manager', 10), 'direct report plus the journey that names the manager');
        $this->assertSame(2, $count('department_head', 11, 3), 'department 3 only; pre-joining journeys have no department');
        $this->assertSame(5, $count('hr_manager', 12), 'the organisation, including joiners with no employee id');
    }

    public function test_overdue_tasks_skip_completed_and_cancelled_ones_and_respect_scope(): void
    {
        $this->people(4);
        $j1 = $this->journey(100, '2027-03-01');
        $j2 = $this->journey(101, '2027-03-01');
        $j3 = $this->journey(102, '2027-03-01');
        $this->task($j1, '2026-11-10');                                          // overdue
        $this->task($j1, '2026-11-12');                                          // overdue
        $this->task($j2, '2026-11-01');                                          // overdue
        $this->task($j1, '2026-11-05', '2026-11-04 10:00:00', 'completed');      // done
        $this->task($j2, '2026-11-05', null, 'Cancelled');                       // cancelled: left out
        $this->task($j2, '2026-11-05', null, 'skipped');
        $this->task($j3, '2026-11-21');                                          // due today: not overdue yet
        $this->task($j3, null);                                                  // no due date

        $hr = $this->byCategory($this->detector()->detect($this->callerAs('hr_manager'), $this->clock())->insights)['onboarding_tasks_overdue'];
        $self = $this->byCategory($this->detector(['onboarding_min_journeys' => 1])->detect($this->callerAs('employee', user: 100), $this->clock())->insights)['onboarding_tasks_overdue'];

        $this->assertSame(3, $this->metric($hr, 'Onboarding tasks past their due date'));
        $this->assertSame(2, $this->metric($hr, 'Journeys affected'));
        $this->assertSame(2, $this->metric($self, 'Onboarding tasks past their due date'));
        $this->assertStringContainsString('your onboarding tasks', strtolower($self['title']));
    }

    public function test_executives_get_counts_without_references_hr_gets_them_capped_at_ten(): void
    {
        $this->people(12);
        foreach (range(100, 111) as $u) {
            $this->journey($u, '2026-11-25');
        }

        $hr = $this->byCategory($this->detector()->detect($this->callerAs('hr_manager'), $this->clock())->insights)['probation_ending'];
        $exec = $this->byCategory($this->detector()->detect($this->callerAs('executive'), $this->clock())->insights)['probation_ending'];

        $this->assertCount(10, $this->entityTypes($hr));
        $this->assertSame(['onboarding_journey'], array_values(array_unique($this->entityTypes($hr))));
        $this->assertSame([], $this->entityTypes($exec));
        $this->assertSame(12, $this->metric($exec, 'Journeys matching'));
    }

    public function test_too_few_journeys_is_insufficient_and_a_self_with_none_is_not_applicable(): void
    {
        $this->people(3);
        $this->journey(100, '2026-11-25');

        $org = $this->detector(['onboarding_min_journeys' => 5])->detect($this->callerAs('hr_manager'), $this->clock());
        $none = $this->detector()->detect($this->callerAs('employee', user: 101), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $org->state);
        $this->assertSame(1, $org->coverage->observed);
        $this->assertSame(Taxonomy::STATE_NOT_APPLICABLE, $none->state);
    }

    public function test_other_tenants_deleted_and_inactive_rows_never_count(): void
    {
        $this->people(3);
        $this->people(3, tenant: 2, firstId: 200, manager: 50);
        $this->insertUser(150, 1, ['status' => 0, 'reporting_manager_id' => 10, 'department_id' => 3]);
        $this->journey(100, '2026-11-25');
        $this->journey(101, '2026-11-25');
        $this->journey(102, '2026-11-25', extra: ['deleted_at' => '2026-11-01 00:00:00']);
        $this->journey(200, '2026-11-25', tenant: 2);
        $this->journey(100, '2026-11-25', tenant: 2);                               // tenant 2 stamp on a tenant 1 person
        $this->journey(null, '2026-11-25', tenant: 2, manager: 10);                 // tenant 2 journey naming tenant 1's manager id
        $other = $this->journey(100, '2027-03-01', tenant: 2);
        $this->task($other, '2026-11-01', tenant: 2);                               // tenant 2 overdue task on a tenant 2 journey
        $mine = $this->journey(101, '2027-03-01');
        $this->task($mine, '2026-11-01', tenant: 2);                                // a task stamped tenant 2 on a tenant 1 journey
        $this->task($mine, '2026-11-01', extra: ['deleted_at' => '2026-11-02 00:00:00']);

        $r = $this->detector(['onboarding_min_journeys' => 1])->detect($this->callerAs('hr_manager', tenant: 1), $this->clock());
        $by = $this->byCategory($r->insights);

        $this->assertSame(2, $this->metric($by['probation_ending'], 'Journeys matching'));
        $this->assertArrayNotHasKey('onboarding_tasks_overdue', $by, 'no tenant 1 task is overdue');
        $mgr = $this->detector(['onboarding_min_journeys' => 1])->detect($this->callerAs('reporting_manager', tenant: 1, user: 10), $this->clock());
        $this->assertSame(2, $this->metric($this->byCategory($mgr->insights)['probation_ending'], 'Journeys matching'), 'a tenant 2 journey that names manager 10 is not tenant 1 data');
    }

    public function test_missing_tables_or_later_added_columns_are_data_unavailable(): void
    {
        $this->people(3);
        Schema::drop('talent_onboarding_tasks');
        Schema::drop('talent_onboarding_journeys');
        Schema::create('talent_onboarding_journeys', function ($t) {             // an installation without the probation_* columns
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('employee_id')->nullable();
            $t->unsignedBigInteger('manager_id')->nullable();
            $t->date('joining_date')->nullable();
            $t->softDeletes();
        });
        $this->assertSame(Taxonomy::STATE_DATA_UNAVAILABLE, $this->detector()->detect($this->callerAs('hr_manager'), $this->clock())->state);

        Schema::drop('talent_onboarding_journeys');
        $this->assertSame(Taxonomy::STATE_DATA_UNAVAILABLE, $this->detector()->detect($this->callerAs('hr_manager'), $this->clock())->state);
    }

    public function test_probation_findings_are_rule_based_and_the_tasks_finding_is_measured(): void
    {
        $this->people(3);
        $j = $this->journey(100, '2026-11-25');
        $this->journey(101, '2026-11-26');
        $this->journey(102, '2026-11-27');
        $this->task($j, '2026-11-01');

        $by = $this->byCategory($this->detector()->detect($this->callerAs('hr_manager'), $this->clock())->insights);

        $this->assertSame(Taxonomy::CONFIDENCE_RULE_BASED, $by['probation_ending']['confidence']);
        $this->assertSame(Taxonomy::CONFIDENCE_MEASURED, $by['onboarding_tasks_overdue']['confidence']);
        $this->assertTrue($by['probation_ending']['provisional']);
    }

    /**
     * H3 (1)+(3): only the product's OPEN journeys count. The set is copied from the product's own definition of a live journey,
     * App\Http\Controllers\Api\Onboarding\OnboardingOverviewController::OPEN_STATUSES = ['not-started', 'in-progress', 'on-hold'].
     * A cancelled or terminated journey keeps the column default confirmation_status 'pending'; it must never raise a probation alarm.
     */
    public function test_only_open_journeys_are_assessed_and_closed_ones_never_raise_a_probation_alarm(): void
    {
        $this->people(10);
        $this->journey(100, '2026-09-01', extra: ['status' => 'cancelled']);       // overdue-looking, but cancelled
        $this->journey(101, '2026-09-01', extra: ['status' => 'terminated']);
        $this->journey(102, '2026-09-01', extra: ['status' => 'completed']);
        $this->journey(103, '2026-11-25', extra: ['status' => 'cancelled']);       // ending-looking, but cancelled
        $this->journey(104, '2026-09-01', extra: ['status' => 'IN-PROGRESS']);     // open (case-insensitive): overdue
        $this->journey(105, '2026-11-25', extra: ['status' => 'on-hold']);         // open: ending
        $this->journey(106, '2026-11-26', extra: ['status' => 'not-started']);     // open: ending
        $this->journey(107, '2026-12-30', extra: ['status' => 'in-progress']);     // open, nothing due

        $r = $this->detector(['onboarding_min_journeys' => 3])->detect($this->callerAs('hr_manager'), $this->clock());
        $by = $this->byCategory($r->insights);

        $this->assertSame(1, $this->metric($by['probation_overdue'], 'Journeys matching'), 'only the open journey');
        $this->assertSame(2, $this->metric($by['probation_ending'], 'Journeys matching'));
        $this->assertSame(4, $r->coverage->observed, 'coverage counts open journeys only');
    }

    public function test_only_closed_journeys_means_insufficient_not_a_high_severity_alarm(): void
    {
        $this->people(5);
        foreach ([100, 101, 102, 103] as $u) {
            $this->journey($u, '2026-08-01', extra: ['status' => 'cancelled']);
        }

        $r = $this->detector(['onboarding_min_journeys' => 3])->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $r->state);
        $this->assertSame([], $r->insights);
        $this->assertSame(0, $r->coverage->observed);
    }

    public function test_tasks_of_closed_journeys_are_not_overdue_either(): void
    {
        $this->people(4);
        $open = $this->journey(100, '2027-03-01', extra: ['status' => 'in-progress']);
        $closed = $this->journey(101, '2027-03-01', extra: ['status' => 'cancelled']);
        $this->journey(102, '2027-03-01', extra: ['status' => 'in-progress']);
        $this->journey(103, '2027-03-01', extra: ['status' => 'in-progress']);
        $this->task($open, '2026-11-01');
        $this->task($closed, '2026-11-01');
        $this->task($closed, '2026-11-02');

        $by = $this->byCategory($this->detector()->detect($this->callerAs('hr_manager'), $this->clock())->insights);

        $this->assertSame(1, $this->metric($by['onboarding_tasks_overdue'], 'Onboarding tasks past their due date'));
    }

    /** H3 (2): the product's rule is "complete = status 'completed'"; completed_at alone is not the only signal. */
    public function test_a_task_is_complete_when_its_status_says_so_even_with_no_completion_time(): void
    {
        $this->people(4);
        $j = $this->journey(100, '2027-03-01', extra: ['status' => 'in-progress']);
        $this->journey(101, '2027-03-01', extra: ['status' => 'in-progress']);
        $this->journey(102, '2027-03-01', extra: ['status' => 'in-progress']);
        $this->task($j, '2026-11-01', null, 'completed');                           // done by status, no timestamp: NOT overdue
        $this->task($j, '2026-11-02', null, 'Completed');                           // spelling
        $this->task($j, '2026-11-03', '2026-11-03 09:00:00', 'pending');            // done by timestamp: NOT overdue
        $this->task($j, '2026-11-04', null, 'cancelled');                           // excluded
        $this->task($j, '2026-11-05', null, 'skipped');                             // excluded
        $this->task($j, '2026-11-06', null, 'in-progress');                         // the only overdue one
        $this->task($j, '2026-11-21', null, 'pending');                             // due today: not overdue yet

        $by = $this->byCategory($this->detector()->detect($this->callerAs('hr_manager'), $this->clock())->insights);

        $this->assertSame(1, $this->metric($by['onboarding_tasks_overdue'], 'Onboarding tasks past their due date'));
    }
}
