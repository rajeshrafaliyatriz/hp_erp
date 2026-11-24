<?php

namespace Tests\Unit\Intelligence\Service;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Coverage;
use App\Domain\Intelligence\InsightService;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Support\SmallGroupGuard;
use Tests\Intelligence\Fixtures\ApiTables;
use Tests\Intelligence\IntelligenceTestCase;

/** L1: callers under the small-group rule must not learn that something was withheld or how large their group is; others keep exact counts. */
class SmallGroupDisclosureTest extends IntelligenceTestCase
{
    private const GENERIC_NOTE = 'Some results are withheld to protect small groups.';
    private const GENERIC_REASON = 'Not enough people to show without identifying individuals.';

    private function caller(string $role): IntelligenceCaller
    {
        return new IntelligenceCaller(1, 10, 7, $role, 3, ApiTables::rights());
    }

    private function service(array $detectors): InsightService
    {
        return new InsightService($detectors, new SmallGroupGuard(5), DetectionClock::at('2026-11-21 09:00:00'));
    }

    private function withheldDetector()
    {
        return ApiTables::detector('hr.pop', 'hr', [ApiTables::insight('small', ['population' => 3]), ApiTables::insight('big', ['population' => 9])]);
    }

    public function test_callers_under_the_rule_get_no_count_and_a_generic_note_only_when_something_was_withheld(): void
    {
        foreach (['reporting_manager', 'department_head', 'hr_executive', 'executive', 'auditor'] as $role) {
            // hr_executive has department scope and is subject to the rule.
            $run = $this->service([$this->withheldDetector()])->run($this->caller($role));

            $this->assertNull($run['meta']['suppressed_count'], $role);
            $this->assertSame(self::GENERIC_NOTE, $run['meta']['suppression_note'], $role);
            $this->assertArrayHasKey('suppression_note', $run['meta']);

            $none = $this->service([ApiTables::detector('hr.fine', 'hr', [ApiTables::insight('big', ['population' => 9])])])->run($this->caller($role));
            $this->assertNull($none['meta']['suppressed_count'], $role);
            $this->assertNull($none['meta']['suppression_note'], $role . ': no note when nothing was withheld');
        }
    }

    public function test_callers_not_under_the_rule_keep_exact_counts(): void
    {
        foreach (['employee', 'hr_manager', 'administrator'] as $role) {
            $run = $this->service([$this->withheldDetector()])->run($this->caller($role));

            $this->assertSame(0, $run['meta']['suppressed_count'], $role);
            $this->assertNull($run['meta']['suppression_note'], $role);
        }

        // An HR/admin org caller sees a population-3 insight (not suppressed), so a non-zero count needs a guard larger than the population AND the rule to apply:
        // org-scope HR is exempt by design, hence always 0 here.
        $this->assertSame(0, $this->service([$this->withheldDetector()])->run($this->caller('hr_manager'))['suppressed']);
    }

    public function test_the_internal_return_value_keeps_the_true_count_for_callers_under_the_rule(): void
    {
        $run = $this->service([$this->withheldDetector()])->run($this->caller('reporting_manager'));

        $this->assertSame(1, $run['suppressed'], 'internal only: never serialised');
    }

    public function test_detector_coverage_is_hidden_when_a_group_is_below_the_minimum_for_callers_under_the_rule(): void
    {
        $tinyObserved = ApiTables::detector('hr.tiny', 'hr', [], null, null, new Coverage(3, 40, 'people with attendance', true, null));
        $tinyExpected = ApiTables::detector('hr.tinyexp', 'hr', [], null, null, new Coverage(40, 4, 'people in scope', true, null));
        $big = ApiTables::detector('hr.big', 'hr', [], null, null, new Coverage(6, 8, 'people with attendance', true, null));
        $thin = ApiTables::detector('hr.thin', 'hr', [], null, null, Coverage::of(2, 10, 'people with attendance', 5), true);
        $service = $this->service([$tinyObserved, $tinyExpected, $big, $thin]);

        foreach (['reporting_manager', 'executive'] as $role) {
            $caller = $this->caller($role);
            $summaries = $service->detectorSummaries($caller, $service->run($caller)['results']);
            $byId = array_column($summaries, null, 'detector');

            foreach (['hr.tiny', 'hr.tinyexp', 'hr.thin'] as $id) {
                $coverage = $byId[$id]['coverage'];
                $this->assertNull($coverage['observed'], "$role $id");
                $this->assertNull($coverage['expected'], "$role $id");
                $this->assertNull($coverage['percent'], "$role $id");
                $this->assertArrayHasKey('basis', $coverage);
                $this->assertArrayHasKey('sufficient', $coverage);
                $this->assertSame(self::GENERIC_REASON, $coverage['reason'], "$role $id");
            }
            $this->assertSame(self::GENERIC_REASON, $byId['hr.thin']['reason'], 'the insufficient-data sentence would otherwise state the count');
            $this->assertStringNotContainsString('2', (string) $byId['hr.thin']['reason']);

            $this->assertSame(6, $byId['hr.big']['coverage']['observed'], 'a group at or above the minimum keeps exact numbers');
            $this->assertSame(8, $byId['hr.big']['coverage']['expected']);
        }
    }

    public function test_each_insights_own_coverage_is_masked_for_callers_under_the_rule(): void
    {
        $insights = [
            ApiTables::insight('tiny-observed', ['population' => 9, 'coverage' => new Coverage(3, 40, 'people with attendance', true, null)]),
            ApiTables::insight('tiny-expected', ['population' => 9, 'coverage' => new Coverage(40, 4, 'people in scope', true, null)]),
            ApiTables::insight('thin', ['population' => 9, 'coverage' => Coverage::of(2, 10, 'people with attendance', 5)]),
            ApiTables::insight('big', ['population' => 9, 'coverage' => new Coverage(6, 8, 'people with attendance', true, null)]),
        ];
        $service = $this->service([ApiTables::detector('hr.cov', 'hr', $insights)]);

        foreach (['reporting_manager', 'department_head', 'executive', 'auditor'] as $role) {
            $caller = $this->caller($role);
            $run = $service->run($caller);
            $byKey = array_column($service->serialiseInsights($caller, $run['insights']), null, 'key');

            foreach (['tiny-observed', 'tiny-expected', 'thin'] as $key) {
                $c = $byKey[$key]['coverage'];
                $this->assertNull($c['observed'], "$role $key");
                $this->assertNull($c['expected'], "$role $key");
                $this->assertNull($c['percent'], "$role $key");
                $this->assertSame(self::GENERIC_REASON, $c['reason'], "$role $key");
                $this->assertArrayHasKey('basis', $c);
                $this->assertArrayHasKey('sufficient', $c);
            }
            $this->assertFalse($byKey['thin']['coverage']['sufficient'], 'sufficient is kept as it was');
            $this->assertStringNotContainsString('Only 2', json_encode($byKey['thin']), 'the count-stating reason must not survive anywhere in the insight');

            $this->assertSame(6, $byKey['big']['coverage']['observed'], "$role: at or above the minimum, exact numbers stay");
            $this->assertSame(8, $byKey['big']['coverage']['expected']);
            $this->assertSame(75.0, $byKey['big']['coverage']['percent']);
        }
    }

    public function test_each_insights_own_coverage_stays_exact_for_callers_not_under_the_rule(): void
    {
        $insights = [
            ApiTables::insight('tiny', ['coverage' => new Coverage(3, 4, 'people', true, null)]),
            ApiTables::insight('thin', ['coverage' => Coverage::of(2, 10, 'people', 5)]),
        ];
        $service = $this->service([ApiTables::detector('hr.cov', 'hr', $insights)]);

        foreach (['employee', 'hr_manager', 'administrator'] as $role) {
            $caller = $this->caller($role);
            $byKey = array_column($service->serialiseInsights($caller, $service->run($caller)['insights']), null, 'key');

            $this->assertSame(3, $byKey['tiny']['coverage']['observed'], $role);
            $this->assertSame(4, $byKey['tiny']['coverage']['expected'], $role);
            $this->assertSame(75.0, $byKey['tiny']['coverage']['percent'], $role);
            $this->assertStringContainsString('Only 2', (string) $byKey['thin']['coverage']['reason'], $role);
        }
    }

    public function test_masking_does_not_mutate_the_insight(): void
    {
        $insight = ApiTables::insight('keep', ['population' => 9, 'coverage' => new Coverage(3, 40, 'people', true, null)]);
        $service = $this->service([ApiTables::detector('hr.cov', 'hr', [$insight])]);

        $service->serialiseInsights($this->caller('reporting_manager'), [$insight]);

        $this->assertSame(3, $insight->coverage->observed);
        $this->assertSame(3, $insight->toArray()['coverage']['observed']);
    }

    public function test_detector_coverage_stays_exact_for_callers_not_under_the_rule(): void
    {
        $tiny = ApiTables::detector('hr.tiny', 'hr', [], null, null, new Coverage(3, 4, 'people', true, null));
        $thin = ApiTables::detector('hr.thin', 'hr', [], null, null, Coverage::of(2, 10, 'people', 5), true);
        $service = $this->service([$tiny, $thin]);

        foreach (['hr_manager', 'administrator', 'employee'] as $role) {
            $caller = $this->caller($role);
            $byId = array_column($service->detectorSummaries($caller, $service->run($caller)['results']), null, 'detector');

            $this->assertSame(3, $byId['hr.tiny']['coverage']['observed'], $role);
            $this->assertSame(4, $byId['hr.tiny']['coverage']['expected'], $role);
            $this->assertStringContainsString('2', (string) $byId['hr.thin']['reason'], $role);
        }
    }
}
