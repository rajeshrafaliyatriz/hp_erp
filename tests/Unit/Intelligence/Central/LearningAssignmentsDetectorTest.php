<?php

namespace Tests\Unit\Intelligence\Central;

use App\Domain\Intelligence\Detectors\Hr\LearningAssignmentsDetector;
use App\Domain\Intelligence\InsightService;
use App\Domain\Intelligence\Support\SmallGroupGuard;
use App\Domain\Intelligence\Support\Thresholds;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Support\Facades\Schema;
use Tests\Intelligence\Fixtures\CentralTables;
use Tests\Intelligence\Fixtures\HrTables;
use Tests\Intelligence\IntelligenceTestCase;

/** Fixed now = 2026-11-21 10:00:00. The stall window (30 days) therefore starts 2026-10-22 10:00:00. */
class LearningAssignmentsDetectorTest extends IntelligenceTestCase
{
    use HrTables, CentralTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPeopleTables();
        $this->createCentralTables();
    }

    private function detector(array $t = ['learning_min_assignments' => 3]): LearningAssignmentsDetector
    {
        return new LearningAssignmentsDetector($this->db(), Thresholds::with($t));
    }

    private function enrol(int $user, int $course, string $status, ?string $updated, int $tenant = 1, array $extra = []): int
    {
        return (int) $this->db()->table('lms_course_enroll')->insertGetId(array_merge(
            ['sub_institute_id' => $tenant, 'user_id' => $user, 'course_id' => $course, 'status' => $status, 'updated_at' => $updated], $extra,
        ));
    }

    private function progress(int $user, int $course, string $updated, int $tenant = 1): void
    {
        $this->insertRow('lms_content_progress', ['sub_institute_id' => $tenant, 'user_id' => $user, 'course_id' => $course, 'updated_at' => $updated]);
    }

    /** @return array<string, int> People 100..105 report to manager 10 in department 3. An assignment's course id is 500 + the user id. */
    private function seedLearning(): array
    {
        $this->people(6);
        $a = [];
        $a['overdue_a'] = $this->assignment(100, '2026-11-01');
        $a['overdue_b'] = $this->assignment(101, '2026-11-10', 'In Progress');
        $a['done_by_enrolment'] = $this->assignment(102, '2026-11-05');                    // its own status is stale: the enrolment says completed
        $a['done_by_status'] = $this->assignment(103, '2026-11-05', 'Completed');
        $a['future'] = $this->assignment(104, '2026-12-01');
        $a['undated'] = $this->assignment(105, null);
        $this->assignment(100, '2026-11-02', 'Not Started', 1, ['approval_status' => 'pending', 'course_id' => 901]);     // not approved: no obligation
        $this->assignment(101, '2026-11-02', 'Not Started', 1, ['approval_status' => 'rejected', 'course_id' => 902]);
        $this->assignment(100, '2026-11-02', 'Not Started', 1, ['deleted_at' => '2026-11-03 00:00:00', 'course_id' => 903]);
        $this->assignment(100, '2026-11-02', 'Not Started', 2, ['course_id' => 904]);                                      // another tenant's

        $this->enrol(102, 602, 'Completed', '2026-11-06 00:00:00');
        $this->enrol(100, 600, 'completed', '2026-11-06 00:00:00', 2);                       // ANOTHER tenant's completion of the same user/course numbers

        $a['stalled_a'] = $this->enrol(100, 700, 'in-progress', '2026-10-01 00:00:00');
        $this->enrol(101, 701, 'in-progress', '2026-10-01 00:00:00');                        // enrolment is old but content was touched recently
        $this->progress(101, 701, '2026-11-15 00:00:00');
        $this->enrol(102, 702, 'in-progress', '2026-11-18 00:00:00');                        // enrolment itself is recent
        $a['stalled_b'] = $this->enrol(103, 703, 'in-progress', '2026-10-01 00:00:00');
        $this->progress(103, 703, '2026-10-05 00:00:00');                                    // old activity only
        $this->enrol(104, 704, 'enrolled', '2026-10-01 00:00:00');                           // not started: not "in progress"
        $this->enrol(105, 705, 'in-progress', '2026-10-01 00:00:00', 1, ['deleted_at' => '2026-10-02 00:00:00']);
        $this->enrol(100, 706, 'in-progress', '2026-10-01 00:00:00', 2);
        $this->progress(100, 700, '2026-11-20 00:00:00', 2);                                 // another tenant's recent activity must not rescue tenant 1's stall

        return $a;
    }

    public function test_happy_path_uses_the_enrolment_as_the_completion_signal(): void
    {
        $a = $this->seedLearning();

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());
        $by = $this->byCategory($r->insights);

        $this->assertSame(Taxonomy::STATE_OK, $r->state);
        $this->assertSame(6, $r->coverage->observed, 'approved, undeleted assignments of this tenant in scope');
        $this->assertSame(['learning_overdue', 'learning_stalled'], array_keys($by));

        $this->assertSame(2, $this->metric($by['learning_overdue'], 'Assignments past their due date'));
        $this->assertSame(4, $this->metric($by['learning_overdue'], 'Assignments not yet completed'));
        $this->assertSame(1, $this->metric($by['learning_overdue'], 'Open assignments with no due date'));
        $this->assertSame('2026-11-01', $this->metric($by['learning_overdue'], 'Oldest due date'));
        $this->assertSame('high', $by['learning_overdue']['severity'], '2 of 4 open assignments is over the 25% line');
        $this->assertSame('rule_based', $by['learning_overdue']['confidence']);
        $this->assertStringContainsString('2 person(s)', $by['learning_overdue']['what_happened']);
        $refs = array_map(static fn ($e) => $e['entity_id'], array_filter($by['learning_overdue']['evidence'], static fn ($e) => $e['kind'] === 'entity'));
        $this->assertSame([$a['overdue_a'], $a['overdue_b']], array_values($refs));
        $this->assertSame(['learning_assignment'], array_values(array_unique($this->entityTypes($by['learning_overdue']))));

        $this->assertSame(2, $this->metric($by['learning_stalled'], 'Started courses with no recorded activity in 30 days'));
        $this->assertSame(4, $this->metric($by['learning_stalled'], 'Courses in progress'));
        $this->assertSame('info', $by['learning_stalled']['severity']);
        $refs = array_map(static fn ($e) => $e['entity_id'], array_filter($by['learning_stalled']['evidence'], static fn ($e) => $e['kind'] === 'entity'));
        $this->assertSame([$a['stalled_a'], $a['stalled_b']], array_values($refs));
        $this->assertSame(['course_enrolment'], array_values(array_unique($this->entityTypes($by['learning_stalled']))));

        foreach ($by as $i) {
            $this->assertSame(['learning', 'hr_dashboard', 'me_dashboard'], $i['surfaces']);
            $this->assertSame('/module/lms/training-and-records/assignments', $i['deep_link']);
        }
    }

    public function test_the_employee_sees_only_their_own_learning_and_a_link_to_my_learning(): void
    {
        $this->seedLearning();

        $r = $this->detector()->detect($this->callerAs('employee', 1, 100), $this->clock());
        $by = $this->byCategory($r->insights);

        $this->assertSame(1, $r->coverage->observed, 'the pending and deleted assignments are not theirs to answer for');
        $this->assertSame(1, $this->metric($by['learning_overdue'], 'Assignments past their due date'), "the other tenant's completion does not clear it");
        $this->assertSame(1, $this->metric($by['learning_stalled'], 'Started courses with no recorded activity in 30 days'));
        $this->assertSame('self', $by['learning_overdue']['audience']);
        $this->assertSame('/module/lms/learning/my-learning', $by['learning_overdue']['deep_link']);
        $this->assertSame('Learning assigned to you is past its due date', $by['learning_overdue']['title']);
    }

    public function test_each_role_sees_the_people_its_scope_allows(): void
    {
        $this->seedLearning();
        $this->insertUser(120, 1, ['reporting_manager_id' => 100, 'department_id' => 3]);
        $this->insertUser(130, 1, ['reporting_manager_id' => 11, 'department_id' => 4]);
        $mine = $this->assignment(120, '2026-10-01');
        $theirs = $this->assignment(130, '2026-10-02');
        $d = $this->detector();
        $overdue = function (string $role) use ($d): array {
            $by = $this->byCategory($d->detect($this->callerAs($role, 1, 10, 3), $this->clock())->insights);
            $ids = array_map(static fn ($e) => $e['entity_id'], array_filter($by['learning_overdue']['evidence'], static fn ($e) => $e['kind'] === 'entity'));

            return [$this->metric($by['learning_overdue'], 'Assignments past their due date'), $ids];
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
        $this->seedLearning();
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

    public function test_insufficient_when_too_few_assignments_exist(): void
    {
        $this->people(6);
        $this->assignment(100, '2026-11-01');
        $this->assignment(101, '2026-11-01');

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $r->state);
        $this->assertSame(2, $r->coverage->observed);
        $this->assertFalse($r->coverage->sufficient);
        $this->assertSame([], $r->insights);
    }

    public function test_an_employee_with_nothing_assigned_is_not_applicable(): void
    {
        $this->people(6);

        $r = $this->detector()->detect($this->callerAs('employee', 1, 100), $this->clock());

        $this->assertSame(Taxonomy::STATE_NOT_APPLICABLE, $r->state);
    }

    public function test_enough_assignments_and_nothing_overdue_is_ok_with_no_insights(): void
    {
        $this->people(6);
        $this->assignment(100, '2026-12-01');
        $this->assignment(101, '2026-12-02');
        $this->assignment(102, '2026-11-21');          // due today is not overdue
        $this->assignment(103, '2026-11-01', 'Completed');

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $r->state);
        $this->assertSame([], $r->insights);
        $this->assertSame(4, $r->coverage->observed);
    }

    public function test_another_tenants_rows_never_count_even_with_colliding_user_and_course_numbers(): void
    {
        $this->people(6);
        $this->assignment(100, '2026-12-01');
        $this->assignment(101, '2026-12-01');
        $this->assignment(102, '2026-12-01');
        $this->people(6, 2, 300, 20, 3);
        for ($i = 0; $i < 6; $i++) {
            $this->assignment(300 + $i, '2026-10-01', 'Not Started', 2);
            $this->assignment(100 + $i, '2026-10-01', 'Not Started', 2);      // tenant 2 rows that name tenant 1's people
        }

        $mine = $this->detector()->detect($this->callerAs('hr_manager', 1), $this->clock());
        $theirs = $this->detector()->detect($this->callerAs('hr_manager', 2, 20), $this->clock());

        $this->assertSame([], $mine->insights);
        $this->assertSame(3, $mine->coverage->observed);
        $this->assertSame(6, $this->metric($this->byCategory($theirs->insights)['learning_overdue'], 'Assignments past their due date'));
    }

    public function test_inactive_terminated_and_deleted_people_are_excluded(): void
    {
        $this->people(4);
        $this->insertUser(150, 1, ['status' => 0, 'reporting_manager_id' => 10, 'department_id' => 3]);
        $this->insertUser(151, 1, ['terminated_date' => '2026-03-01', 'reporting_manager_id' => 10, 'department_id' => 3]);
        $this->insertUser(152, 1, ['deleted_at' => '2026-03-01 00:00:00', 'reporting_manager_id' => 10, 'department_id' => 3]);
        $this->assignment(100, '2026-11-01');
        $this->assignment(101, '2026-12-01');
        $this->assignment(102, '2026-12-01');
        foreach ([150, 151, 152] as $u) {
            $this->assignment($u, '2026-11-01');
            $this->enrol($u, 800 + $u, 'in-progress', '2026-10-01 00:00:00');
        }

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());
        $by = $this->byCategory($r->insights);

        $this->assertSame(3, $r->coverage->observed);
        $this->assertSame(1, $this->metric($by['learning_overdue'], 'Assignments past their due date'));
        $this->assertArrayNotHasKey('learning_stalled', $by);
    }

    public function test_without_the_enrolment_table_only_the_assignments_own_status_is_used_and_no_stall_is_reported(): void
    {
        $this->seedLearning();
        Schema::drop('lms_course_enroll');

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());
        $by = $this->byCategory($r->insights);

        $this->assertSame(Taxonomy::STATE_OK, $r->state);
        $this->assertSame(['learning_overdue'], array_keys($by));
        $this->assertSame(3, $this->metric($by['learning_overdue'], 'Assignments past their due date'), 'the assignment whose only completion signal was the enrolment now counts');
        $this->assertNoSqlLeak($this->serialised($r));
    }

    public function test_without_the_content_progress_table_the_assignments_are_still_assessed(): void
    {
        $this->seedLearning();
        Schema::drop('lms_content_progress');

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $r->state);
        $this->assertSame(['learning_overdue'], array_keys($this->byCategory($r->insights)));
        $this->assertNoSqlLeak($this->serialised($r));
    }

    public function test_a_missing_assignments_table_or_column_is_data_unavailable_and_leaks_no_sql(): void
    {
        $this->seedLearning();
        Schema::table('lms_assignments', fn ($t) => $t->dropColumn('approval_status'));
        $missingColumn = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());
        Schema::drop('lms_assignments');
        $missingTable = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        foreach ([$missingColumn, $missingTable] as $r) {
            $this->assertSame(Taxonomy::STATE_DATA_UNAVAILABLE, $r->state);
            $json = $this->serialised($r);
            $this->assertNoSqlLeak($json);
            $this->assertStringNotContainsString('lms_assignments', $json);
            $this->assertStringNotContainsString('approval_status', $json);
        }
    }

    public function test_the_result_names_no_table_column_or_person(): void
    {
        $this->seedLearning();
        $json = $this->serialised($this->detector()->detect($this->callerAs('hr_manager'), $this->clock()));

        foreach (['lms_assignments', 'lms_course_enroll', 'approval_status', 'user_id', 'course_id', 'sub_institute_id'] as $needle) {
            $this->assertStringNotContainsString($needle, $json, $needle);
        }
        $this->assertStringContainsString('Learning assignments', $json);
        $this->assertStringContainsString('Course enrolments', $json);
    }

    public function test_a_small_group_is_withheld_from_a_team_manager_but_not_from_the_learner_or_hr(): void
    {
        $this->people(3);
        foreach ([100, 101, 102] as $u) {
            $this->assignment($u, '2026-11-01');
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
