<?php

namespace Tests\Unit\Intelligence\Service;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Coverage;
use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\InsightDetector;
use App\Domain\Intelligence\InsightService;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Support\SmallGroupGuard;
use App\Domain\Intelligence\Taxonomy;
use RuntimeException;
use Tests\Intelligence\Fixtures\ApiTables;
use Tests\Intelligence\IntelligenceTestCase;

/**
 * THE STATE VOCABULARY of a detector result (documented on Taxonomy::STATE_OK):
 *   ok | insufficient_data | not_applicable | data_unavailable | access_restricted | error
 * and the guarantees that go with it: a restriction carries nothing but its identity, an error is never a count of zero, a missing source is not an error.
 */
class StateVocabularyTest extends IntelligenceTestCase
{
    private function caller(string $role = 'hr_manager'): IntelligenceCaller
    {
        return new IntelligenceCaller(1, 10, 7, $role, 3, ApiTables::rights());
    }

    private function service(array $detectors): InsightService
    {
        return new InsightService($detectors, new SmallGroupGuard(5), DetectionClock::at('2026-11-21 09:00:00'));
    }

    /** A detector that answers with an arbitrary DetectorResult factory and counts how often it ran. */
    private function stub(string $id, string $domain, \Closure $answer, bool $authorised = true): InsightDetector
    {
        return new class($id, $domain, $answer, $authorised) implements InsightDetector {
            public int $detectCalls = 0;

            public function __construct(private string $id, private string $domain, private \Closure $answer, private bool $authorised)
            {
            }

            public function id(): string { return $this->id; }

            public function title(): string { return 'Stub ' . $this->id; }

            public function domain(): string { return $this->domain; }

            public function surfaces(): array { return ['hr_dashboard']; }

            public function authorizes(IntelligenceCaller $caller): bool { return $this->authorised; }

            public function detect(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
            {
                $this->detectCalls++;

                return ($this->answer)($this, $clock->asOf());
            }
        };
    }

    /** @return list<InsightDetector> one detector per state */
    private function onePerState(): array
    {
        return [
            $this->stub('x.ok', 'hr', fn ($d, $at) => DetectorResult::ok($d, [ApiTables::insight('k1')], Coverage::of(5, 5, 'b', 1), $at)),
            $this->stub('x.insufficient', 'hr', fn ($d, $at) => DetectorResult::insufficient($d, Coverage::of(1, 5, 'b', 3), $at)),
            $this->stub('x.na', 'hr', fn ($d, $at) => DetectorResult::notApplicable($d, $at, 'Nothing relevant to you.')),
            $this->stub('x.unavailable', 'hr', fn ($d, $at) => DetectorResult::dataUnavailable($d, $at, 'The source is not available.')),
            $this->stub('x.restricted', 'platform', fn ($d, $at) => throw new \LogicException('must never run'), false),
            $this->stub('x.error', 'platform', fn ($d, $at) => throw new RuntimeException('SELECT secret FROM t')),
        ];
    }

    public function test_the_vocabulary_is_exactly_these_six_states_in_this_order(): void
    {
        $this->assertSame(['ok', 'insufficient_data', 'not_applicable', 'data_unavailable', 'access_restricted', 'error'], Taxonomy::STATES);
        $this->assertSame(['hr', 'commercial', 'platform'], Taxonomy::DOMAINS);
    }

    public function test_every_state_is_counted_in_meta_states_and_the_keys_are_stable(): void
    {
        $run = $this->service($this->onePerState())->run($this->caller());

        $this->assertSame(Taxonomy::STATES, array_keys($run['meta']['states']));
        $this->assertSame(['ok' => 1, 'insufficient_data' => 1, 'not_applicable' => 1, 'data_unavailable' => 1, 'access_restricted' => 1, 'error' => 1], $run['meta']['states']);
        $this->assertSame(5, $run['meta']['detectors_run'], 'the restricted detector was not run');
    }

    public function test_a_restricted_detector_is_never_run_and_its_entry_carries_identity_only(): void
    {
        $detectors = $this->onePerState();
        $run = $this->service($detectors)->run($this->caller());

        $this->assertSame(0, $detectors[4]->detectCalls, 'authorise before detect: nothing is read for a restricted detector');
        $summary = array_values(array_filter($this->service($detectors)->detectorSummaries($this->caller(), $run['results']), fn ($s) => $s['detector'] === 'x.restricted'))[0];
        $this->assertSame([
            'detector' => 'x.restricted', 'domain' => 'platform', 'title' => 'Stub x.restricted', 'state' => 'access_restricted', 'insight_count' => null,
            'coverage' => null, 'as_of' => '2026-11-21T09:00:00Z', 'reason' => 'Your role does not have access to this check.',
        ], $summary);
    }

    public function test_an_error_is_never_reported_as_zero_records_and_leaks_no_text(): void
    {
        $service = $this->service($this->onePerState());
        $run = $service->run($this->caller());
        $by = [];
        foreach ($service->detectorSummaries($this->caller(), $run['results']) as $s) {
            $by[$s['detector']] = $s;
        }

        $this->assertSame('error', $by['x.error']['state']);
        $this->assertNull($by['x.error']['insight_count'], 'an error is not zero');
        $this->assertNull($by['x.error']['coverage']);
        $this->assertSame('This check could not be completed right now.', $by['x.error']['reason']);
        $this->assertStringNotContainsString('SELECT', json_encode($by));
        // the states that DID assess keep a real count (zero is a meaningful answer for them)
        $this->assertSame(1, $by['x.ok']['insight_count']);
        $this->assertSame(0, $by['x.insufficient']['insight_count']);
        $this->assertSame(0, $by['x.na']['insight_count']);
        $this->assertNull($by['x.unavailable']['insight_count']);
        $this->assertSame('The source is not available.', $by['x.unavailable']['reason']);
    }

    public function test_data_unavailable_is_not_an_error_and_not_not_applicable(): void
    {
        $d = $this->stub('x.u', 'hr', fn ($d, $at) => DetectorResult::dataUnavailable($d, $at, 'Gone.'));
        $r = $d->detect($this->caller(), DetectionClock::at('2026-11-21 09:00:00'));

        $this->assertSame(Taxonomy::STATE_DATA_UNAVAILABLE, $r->state);
        $this->assertNotSame(Taxonomy::STATE_ERROR, $r->state);
        $this->assertNotSame(Taxonomy::STATE_NOT_APPLICABLE, $r->state);
        $this->assertNull($r->coverage);
        $this->assertSame([], $r->insights);
    }

    public function test_a_detector_whose_authorizes_throws_is_restricted_not_run_and_not_leaked(): void
    {
        $bad = new class implements InsightDetector {
            public function id(): string { return 'x.bad'; }
            public function title(): string { return 'Bad'; }
            public function domain(): string { return 'hr'; }
            public function surfaces(): array { return []; }
            public function authorizes(IntelligenceCaller $caller): bool { throw new RuntimeException('hunter2 on 10.9.8.7'); }
            public function detect(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult { throw new \LogicException('must not run'); }
        };

        $run = $this->service([$bad])->run($this->caller());

        $this->assertSame('access_restricted', $run['results'][0]->state);
        $this->assertStringNotContainsString('hunter2', json_encode($run['results'][0]->summary()));
    }

    public function test_the_detector_filter_cannot_probe_a_restricted_detector(): void
    {
        $detectors = $this->onePerState();

        $viaRestricted = $this->service($detectors)->run($this->caller(), ['detector' => 'x.restricted']);
        $viaUnknown = $this->service($detectors)->run($this->caller(), ['detector' => 'does.not.exist']);

        $this->assertSame($viaUnknown['meta']['filters'], $viaRestricted['meta']['filters']);
        $this->assertSame($viaUnknown['meta']['states'], $viaRestricted['meta']['states']);
        $this->assertSame(0, $detectors[4]->detectCalls);
    }

    public function test_the_domain_filter_applies_to_restricted_entries_too(): void
    {
        $run = $this->service($this->onePerState())->run($this->caller(), ['domain' => 'platform']);

        $this->assertEqualsCanonicalizing(['x.restricted', 'x.error'], array_map(fn ($r) => $r->detector, $run['results']));
        $this->assertSame(1, $run['meta']['states']['access_restricted']);
        $this->assertSame(0, $run['meta']['states']['ok']);
    }

    public function test_a_restricted_entry_never_changes_what_the_other_detectors_report(): void
    {
        $with = $this->service($this->onePerState())->run($this->caller());
        $without = $this->service(array_values(array_filter($this->onePerState(), fn ($d) => $d->id() !== 'x.restricted')))->run($this->caller());

        $this->assertSame(array_map(fn ($i) => $i->key, $without['insights']), array_map(fn ($i) => $i->key, $with['insights']));
        $this->assertSame($without['suppressed'], $with['suppressed']);
    }

    public function test_small_group_suppression_still_applies_with_restricted_entries_present(): void
    {
        $small = $this->stub('x.small', 'hr', fn ($d, $at) => DetectorResult::ok($d, [ApiTables::insight('s', ['population' => 2])], Coverage::of(2, 2, 'b', 1), $at));

        $run = $this->service([$small, ...$this->onePerState()])->run($this->caller('reporting_manager'));

        $this->assertSame(1, $run['suppressed']);
        $this->assertNull($run['meta']['suppressed_count'], 'a caller under the small-group rule is not told how many were withheld');
    }
}
