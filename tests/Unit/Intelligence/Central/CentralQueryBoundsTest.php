<?php

namespace Tests\Unit\Intelligence\Central;

use App\Domain\Intelligence\Detectors\Hr\CapabilityDevelopmentDetector;
use App\Domain\Intelligence\Detectors\Hr\LearningAssignmentsDetector;
use App\Domain\Intelligence\Detectors\Hr\OrganisationStructureDetector;
use App\Domain\Intelligence\Detectors\Hr\TaskWorkflowDetector;
use App\Domain\Intelligence\InsightDetector;
use App\Domain\Intelligence\Support\Thresholds;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Database\Events\QueryExecuted;
use Tests\Intelligence\Fixtures\CentralTables;
use Tests\Intelligence\Fixtures\HrTables;
use Tests\Intelligence\IntelligenceTestCase;

/**
 * The four central detectors, on a tenant of 300 people and then twice that: every statement they run is a read, and the number of data statements is a small
 * constant that does not grow with the data (no query inside a loop over records).
 */
class CentralQueryBoundsTest extends IntelligenceTestCase
{
    use HrTables, CentralTables;

    private const MAX_DATA_QUERIES = 12;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPeopleTables();
        $this->createCentralTables();
    }

    private function load(int $round): void
    {
        $base = $round * 1000;
        $tasks = $assignments = $enrols = $plans = $assessments = $history = [];
        for ($i = 0; $i < 300; $i++) {
            $id = $base + 100 + $i;
            $this->insertUser($id, 1, ['reporting_manager_id' => $i % 7 === 0 ? null : 10, 'department_id' => 3, 'jobtitle_id' => $i % 5 === 0 ? null : 7]);
            $tasks[] = ['sub_institute_id' => 1, 'task_allocated_to' => $id, 'task_date' => $i % 2 ? '2026-11-01' : '2026-12-01', 'status' => $i % 9 === 0 ? 'ON HOLD' : 'PENDING'];
            $assignments[] = ['sub_institute_id' => 1, 'user_id' => $id, 'course_id' => $id, 'due_date' => $i % 2 ? '2026-11-01' : '2026-12-01', 'status' => 'Not Started', 'approval_status' => 'approved'];
            $enrols[] = ['sub_institute_id' => 1, 'user_id' => $id, 'course_id' => $id, 'status' => $i % 3 ? 'in-progress' : 'completed', 'updated_at' => '2026-10-01 00:00:00'];
            $plans[] = ['sub_institute_id' => 1, 'user_id' => $id, 'status' => 'active', 'due_date' => $i % 2 ? '2026-11-01' : '2026-12-01'];
            $assessments[] = ['sub_institute_id' => 1, 'user_id' => $id, 'status' => 'open', 'due_date' => $i % 2 ? '2026-11-01' : '2026-12-01'];
        }
        for ($d = 1; $d <= 20; $d++) {
            $this->insertRow('hrms_departments', ['id' => $base + $d, 'department' => 'D', 'status' => 1, 'head_user_id' => $d % 2 ? null : 5, 'sub_institute_id' => 1]);
        }
        foreach (array_chunk($tasks, 100) as $c) { $this->db()->table('task')->insert($c); }
        foreach (array_chunk($assignments, 100) as $c) { $this->db()->table('lms_assignments')->insert($c); }
        foreach (array_chunk($enrols, 100) as $c) { $this->db()->table('lms_course_enroll')->insert($c); }
        foreach (array_chunk($plans, 100) as $c) { $this->db()->table('s_competency_development_plans')->insert($c); }
        foreach (array_chunk($assessments, 100) as $c) { $this->db()->table('s_competency_assessments')->insert($c); }
        for ($h = 1; $h <= 50; $h++) {
            $history[] = ['sub_institute_id' => 1, 'task_id' => $h, 'is_reopen' => 1, 'occurred_at' => '2026-11-10 00:00:00'];
        }
        $this->db()->table('task_status_history')->insert($history);
    }

    /** @return array<string, array{data: int, writes: int, insights: int}> */
    private function measure(): array
    {
        $detectors = [
            new OrganisationStructureDetector($this->db(), Thresholds::defaults()),
            new TaskWorkflowDetector($this->db(), Thresholds::defaults()),
            new LearningAssignmentsDetector($this->db(), Thresholds::defaults()),
            new CapabilityDevelopmentDetector($this->db(), Thresholds::defaults()),
        ];
        $out = [];
        foreach ($detectors as $d) {
            /** @var InsightDetector $d */
            $log = [];
            $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $q) use (&$log): void {
                $log[] = $q->sql;
            });
            try {
                $result = $d->detect($this->callerAs('hr_manager'), $this->clock());
            } finally {
                $this->app['events']->forget(QueryExecuted::class);
            }
            $this->assertSame(Taxonomy::STATE_OK, $result->state, $d->id());
            $data = array_filter($log, static fn ($sql) => ! preg_match('/sqlite_master|pragma_table_xinfo/i', $sql));
            $out[$d->id()] = [
                'data' => count($data),
                'writes' => count(array_filter($log, static fn ($sql) => ! preg_match('/^\s*(select|pragma)\b/i', $sql))),
                'insights' => count($result->insights),
            ];
        }

        return $out;
    }

    public function test_queries_are_read_only_and_do_not_grow_with_the_data(): void
    {
        $this->load(0);
        $small = $this->measure();
        $this->load(1);
        $large = $this->measure();

        $this->assertSame(600, $this->db()->table('tbluser')->count());
        $this->assertCount(4, $small);
        foreach ($small as $id => $s) {
            $this->assertSame(0, $s['writes'], "$id ran a statement that is not a read");
            $this->assertGreaterThan(0, $s['insights'], "$id: the load should make it speak, otherwise this measures nothing");
            $this->assertLessThanOrEqual(self::MAX_DATA_QUERIES, $s['data'], "$id: too many queries");
            $this->assertSame($s['data'], $large[$id]['data'], "$id: the number of queries grew when the data doubled (an N+1)");
        }
    }
}
