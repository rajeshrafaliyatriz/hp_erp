<?php

namespace Tests\Unit\Intelligence\Service;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\DetectorRegistry;
use App\Domain\Intelligence\InsightService;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Support\SmallGroupGuard;
use App\Domain\Intelligence\Support\Thresholds;
use App\Domain\Intelligence\Taxonomy;
use RuntimeException;
use Tests\Intelligence\Fixtures\ApiTables;
use Tests\Intelligence\IntelligenceTestCase;

class InsightServiceTest extends IntelligenceTestCase
{
    private function caller(string $role, int $tenant = 1): IntelligenceCaller
    {
        return new IntelligenceCaller($tenant, 10, 7, $role, 3, ApiTables::rights());
    }

    private function service(array $detectors, int $min = 5): InsightService
    {
        return new InsightService($detectors, new SmallGroupGuard($min), DetectionClock::at('2026-11-21 09:00:00'));
    }

    public function test_authorisation_happens_before_detection_and_unauthorised_detectors_are_reported_as_restricted_only(): void
    {
        $allowed = ApiTables::detector('hr.allowed', 'hr', [ApiTables::insight('a')]);
        $denied = ApiTables::detector('hr.denied', 'hr', [ApiTables::insight('b')], fn () => false);

        $run = $this->service([$allowed, $denied])->run($this->caller('hr_manager'));

        $this->assertSame(1, $allowed->detectCalls);
        $this->assertSame(0, $denied->detectCalls, 'detect() must never run for a detector that does not authorise the caller');
        // CONTRACT CHANGE (access_restricted): the denied detector now appears as a bare access_restricted entry (identity only, never run) instead of being omitted.
        $this->assertSame(['hr.allowed', 'hr.denied'], array_map(fn ($r) => $r->detector, $run['results']));
        $this->assertSame(['ok', 'access_restricted'], array_map(fn ($r) => $r->state, $run['results']));
        $this->assertNull($run['results'][1]->coverage);
        $this->assertSame([], $run['results'][1]->insights);
        $this->assertSame(1, $run['meta']['detectors_run'], 'only the authorised detector was run');
        $this->assertSame(1, $run['meta']['states']['access_restricted']);
        $this->assertStringNotContainsString('hr.denied', json_encode($run['meta']));
        $this->assertSame(['a'], array_map(fn ($i) => $i->key, $run['insights']));
    }

    public function test_an_unauthorised_detector_cannot_be_probed_through_the_detector_filter(): void
    {
        $denied = ApiTables::detector('hr.denied', 'hr', [ApiTables::insight('b')], fn () => false);
        $allowed = ApiTables::detector('hr.allowed', 'hr', [ApiTables::insight('a')]);

        $viaDenied = $this->service([$allowed, $denied])->run($this->caller('hr_manager'), ['detector' => 'hr.denied']);
        $viaUnknown = $this->service([$allowed, $denied])->run($this->caller('hr_manager'), ['detector' => 'does.not.exist']);

        $this->assertSame(0, $denied->detectCalls);
        $this->assertSame(array_map(fn ($i) => $i->key, $viaUnknown['insights']), array_map(fn ($i) => $i->key, $viaDenied['insights']), 'denied and unknown ids behave identically');
        $this->assertSame($viaUnknown['meta']['filters'], $viaDenied['meta']['filters']);
    }

    public function test_a_failing_detector_is_isolated_and_leaks_nothing(): void
    {
        $boom = ApiTables::detector('hr.boom', 'hr', [], null, fn () => throw new RuntimeException('SELECT secret FROM tbluser WHERE password = hunter2'));
        $fine = ApiTables::detector('hr.fine', 'hr', [ApiTables::insight('a')]);

        $logged = [];
        \Illuminate\Support\Facades\Log::shouldReceive('warning')->andReturnUsing(function ($message, $context = []) use (&$logged) {
            $logged[] = [$message, $context];
        });

        $run = $this->service([$boom, $fine])->run($this->caller('hr_manager'));

        $states = [];
        foreach ($run['results'] as $r) {
            $states[$r->detector] = $r->state;
        }
        $this->assertSame(['hr.boom' => 'error', 'hr.fine' => 'ok'], $states);
        $this->assertSame(1, $run['meta']['states']['error']);
        $this->assertSame(['a'], array_map(fn ($i) => $i->key, $run['insights']));

        $payload = json_encode([array_map(fn ($r) => $r->summary(), $run['results']), $run['meta'], $logged]);
        $this->assertStringNotContainsString('hunter2', $payload);
        $this->assertStringNotContainsString('SELECT', $payload);
        $this->assertStringNotContainsString('tbluser', $payload);
        $this->assertNotEmpty($logged);
        $this->assertSame(RuntimeException::class, $logged[0][1]['exception']);
    }

    public function test_small_group_suppression_applies_to_team_department_and_aggregate_only_but_not_to_self_or_hr(): void
    {
        $detector = fn () => ApiTables::detector('hr.pop', 'hr', [
            ApiTables::insight('withheld-key-7', ['population' => 3]),
            ApiTables::insight('big', ['population' => 5]),
            ApiTables::insight('nopeople', ['population' => null, 'domain' => 'commercial']),
        ]);

        foreach (['reporting_manager', 'department_head', 'executive', 'auditor'] as $role) {
            $run = $this->service([$detector()])->run($this->caller($role));
            $keys = array_map(fn ($i) => $i->key, $run['insights']);
            sort($keys);
            $this->assertSame(['big', 'nopeople'], $keys, $role);
            $this->assertSame(1, $run['suppressed'], $role . ': internal count stays true');
            $this->assertNull($run['meta']['suppressed_count'], $role . ': a caller under the rule is never told how many');
            $this->assertSame(InsightService::SUPPRESSION_NOTE, $run['meta']['suppression_note']);
            $this->assertStringNotContainsString('withheld-key-7', json_encode($run['meta']), 'what was suppressed is never named');
        }

        foreach (['employee', 'hr_manager', 'administrator'] as $role) {
            $run = $this->service([$detector()])->run($this->caller($role));
            $this->assertCount(3, $run['insights'], $role);
            $this->assertSame(0, $run['suppressed'], $role);
        }
    }

    public function test_results_are_rebuilt_without_the_suppressed_insights(): void
    {
        $d = ApiTables::detector('hr.pop', 'hr', [ApiTables::insight('small', ['population' => 1])]);

        $run = $this->service([$d])->run($this->caller('reporting_manager'));

        $this->assertSame(0, $run['results'][0]->summary()['insight_count']);
        $this->assertSame('ok', $run['results'][0]->state);
    }

    public function test_ordering_is_severity_then_newest_then_key_and_duplicates_collapse(): void
    {
        $d = ApiTables::detector('hr.o', 'hr', [
            ApiTables::insight('b-low', ['severity' => 'info', 'asOf' => '2026-11-21T10:00:00Z']),
            ApiTables::insight('a-high-old', ['severity' => 'high', 'asOf' => '2026-11-20T00:00:00Z']),
            ApiTables::insight('c-high-new', ['severity' => 'high', 'asOf' => '2026-11-21T00:00:00Z']),
            ApiTables::insight('z-crit', ['severity' => 'critical', 'asOf' => '2026-01-01T00:00:00Z']),
            ApiTables::insight('d-high-new', ['severity' => 'high', 'asOf' => '2026-11-21T00:00:00Z']),
            ApiTables::insight('z-crit', ['severity' => 'critical', 'asOf' => '2026-01-01T00:00:00Z']),
        ]);

        $run = $this->service([$d])->run($this->caller('administrator'));

        $this->assertSame(['z-crit', 'c-high-new', 'd-high-new', 'a-high-old', 'b-low'], array_map(fn ($i) => $i->key, $run['insights']));
    }

    public function test_filters(): void
    {
        $hr = ApiTables::detector('hr.x', 'hr', [
            ApiTables::insight('hr-att', ['category' => 'attendance', 'severity' => 'attention', 'surfaces' => ['attendance']]),
            ApiTables::insight('hr-cert', ['category' => 'certification', 'severity' => 'high', 'surfaces' => ['certifications']]),
        ]);
        $co = ApiTables::detector('commercial.y', 'commercial', [
            ApiTables::insight('co-deal', ['domain' => 'commercial', 'category' => 'deal', 'severity' => 'critical', 'surfaces' => ['gtm_deals']]),
        ]);
        $keys = fn (array $filters) => array_map(fn ($i) => $i->key, $this->service([$hr, $co])->run($this->caller('administrator'), $filters)['insights']);

        $this->assertSame(['co-deal'], $keys(['domain' => 'commercial']));
        $this->assertSame(['hr-cert', 'hr-att'], $keys(['domain' => 'hr']));
        $this->assertSame(['co-deal', 'hr-cert'], $keys(['severity' => 'high']), 'severity is a minimum');
        $this->assertSame(['hr-att'], $keys(['category' => 'attendance']));
        $this->assertSame(['hr-cert'], $keys(['surface' => 'certifications']));
        $this->assertSame(['co-deal'], $keys(['detector' => 'commercial.y']));
        $this->assertSame(['co-deal'], $keys(['domain' => 'commercial', 'severity' => 'critical', 'surface' => 'gtm_deals']));
    }

    public function test_unknown_filter_values_are_ignored_not_errors(): void
    {
        $d = ApiTables::detector('hr.x', 'hr', [ApiTables::insight('a'), ApiTables::insight('b')]);

        $run = $this->service([$d])->run($this->caller('administrator'), [
            'domain' => 'payroll', 'severity' => 'apocalyptic', 'surface' => 'nowhere', 'detector' => 'nope', 'category' => str_repeat('x', 200),
            'sub_institute_id' => 99, 'user_id' => 1, 'role' => 'administrator', 'scope' => 'organization', 'domain_array' => ['a'],
        ]);

        $this->assertCount(2, $run['insights']);
        $this->assertSame([], $run['meta']['filters']);
        $this->assertSame('organization', $run['meta']['scope']);

        $arrays = $this->service([$d])->run($this->caller('administrator'), ['domain' => ['hr'], 'severity' => ['high'], 'detector' => ['x']]);
        $this->assertCount(2, $arrays['insights']);
    }

    public function test_pagination_is_clamped(): void
    {
        $items = range(1, 120);

        $this->assertCount(50, InsightService::paginate($items, 1, 500)['items']);
        $this->assertSame(50, InsightService::paginate($items, 1, 500)['pagination']['per_page']);
        $this->assertSame(1, InsightService::paginate($items, 0, 0)['pagination']['page']);
        $this->assertSame(1, InsightService::paginate($items, -3, -3)['pagination']['per_page']);
        $this->assertSame(range(51, 100), InsightService::paginate($items, 2, 50)['items']);
        $this->assertSame([], InsightService::paginate($items, 99, 10)['items']);
        $this->assertSame(3, InsightService::paginate($items, 1, 50)['pagination']['total_pages']);
        $this->assertSame(1, InsightService::paginate([], 1, 20)['pagination']['total_pages']);
    }

    public function test_meta_shape_and_provisional_flag(): void
    {
        $run = $this->service([ApiTables::detector('hr.x', 'hr', [])])->run($this->caller('employee'));

        $this->assertSame('2026-11-21T09:00:00Z', $run['meta']['generated_at']);
        $this->assertSame('self', $run['meta']['scope']);
        $this->assertTrue($run['meta']['provisional']);
        $this->assertNotSame('', $run['meta']['thresholds_note']);
        $this->assertSame(Taxonomy::STATES, array_keys($run['meta']['states']));
        $this->assertSame(1, $run['meta']['states']['ok']);
    }

    public function test_a_detector_that_throws_while_authorising_is_skipped_not_fatal(): void
    {
        $bad = ApiTables::detector('hr.bad', 'hr', [ApiTables::insight('x')], fn () => throw new RuntimeException('rights table missing'));
        $good = ApiTables::detector('hr.good', 'hr', [ApiTables::insight('a')]);
        \Illuminate\Support\Facades\Log::shouldReceive('warning')->andReturnNull();

        $run = $this->service([$bad, $good])->run($this->caller('administrator'));

        $this->assertSame(0, $bad->detectCalls);
        $this->assertSame(['a'], array_map(fn ($i) => $i->key, $run['insights']));
    }

    /** NEEDS agents B and C to have delivered their detector classes. Reported separately in the hand-back. */
    public function test_registry_returns_the_twenty_one_detectors_with_unique_ids(): void
    {
        DetectorRegistry::overrideForTests(null);

        $detectors = DetectorRegistry::forConnection($this->db(), Thresholds::defaults());
        $ids = array_map(fn ($d) => $d->id(), $detectors);

        $this->assertCount(21, $detectors);
        $this->assertSame($ids, array_values(array_unique($ids)), 'detector ids must be unique');
        foreach ($detectors as $d) {
            $this->assertContains($d->domain(), Taxonomy::DOMAINS);
            $this->assertNotContains('payroll', [$d->domain()]);
        }
    }
}
