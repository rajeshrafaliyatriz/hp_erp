<?php

namespace Tests\Unit\Intelligence\Adversarial;

use App\Domain\Intelligence\InsightService;
use App\Domain\Intelligence\Support\SmallGroupGuard;
use Illuminate\Support\Facades\Log;
use Tests\Intelligence\Fixtures\ApiTables;

/**
 * 8. RESPONSE SAFETY. The exact JSON an API caller would receive (the controller serialises `Insight::toArray()`, `DetectorResult::summary()` and `meta`), for
 * every role, over a rich world: nothing internal, nothing personal, nothing that could send a person elsewhere, and nothing mislabelled.
 */
class ResponseSafetyTest extends AdversarialTestCase
{
    private const INSIGHT_KEYS = ['key', 'detector', 'domain', 'category', 'severity', 'title', 'what_happened', 'why_it_matters', 'evidence', 'coverage', 'as_of', 'suggested_action',
        'deep_link', 'confidence', 'audience', 'surfaces', 'status', 'provisional'];

    /** @return array{json: string, insights: list<array>, results: list<array>, meta: array} */
    private function response(string $role): array
    {
        $caller = $this->callerFor($role, self::T1, self::B1);
        $service = $this->service();
        $run = $service->run($caller);
        $insights = array_map(static fn ($i) => $i->toArray(), $run['insights']);
        $results = $service->detectorSummaries($caller, $run['results']);   // exactly what the detectors endpoint serialises

        return ['json' => json_encode(['insights' => $insights, 'detectors' => $results, 'meta' => $run['meta']], JSON_THROW_ON_ERROR), 'insights' => $insights, 'results' => $results, 'meta' => $run['meta']];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $world = $this->seedWorld(self::T1, self::B1);
        $this->seedWorld(self::T2, self::B2, ['manager' => self::B1 + 10, 'sentinel' => true]);
        $this->seedCrossReferences(self::T2, $world, self::T1);
    }

    public function test_population_and_internals_never_appear(): void
    {
        foreach (self::ROLES as $role) {
            $r = $this->response($role);
            $this->assertStringNotContainsString('population', $r['json'], "$role: the size of a small group must not be serialised");
            $this->assertDoesNotMatchRegularExpression('/sqlstate|queryexception|pdoexception|syntax error|no such (table|column)|stack trace|#\d+ \/|\.php\b|select .* from /i', $r['json'], "$role: an internal leaked");
            $this->assertDoesNotMatchRegularExpression('/\b\d{1,3}(\.\d{1,3}){3}\b|localhost|\b[a-z0-9-]+\.(com|net|org|io|dev|local|internal|co\.in)\b/i', $r['json'], "$role: a host or address leaked");
            $this->assertDoesNotMatchRegularExpression('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $r['json'], "$role: an e-mail-like string");
            $this->assertDoesNotMatchRegularExpression('/\+\d{10,15}\b|\b\d{10,}\b|\(\d{3}\)\s?\d{3}[- ]\d{4}|\b\d{3}[- ]\d{3}[- ]\d{4}\b/', $r['json'], "$role: a phone-number-like string");
        }
    }

    public function test_every_insight_has_exactly_the_contract_shape(): void
    {
        $seen = 0;
        foreach (self::ROLES as $role) {
            foreach ($this->response($role)['insights'] as $i) {
                $seen++;
                $this->assertSame(self::INSIGHT_KEYS, array_keys($i), "$role: the insight shape drifted");
                $this->assertNotEmpty($i['evidence'], "{$i['key']}: an insight without evidence is an opinion");
                $this->assertSame(['observed', 'expected', 'percent', 'basis', 'sufficient', 'reason'], array_keys($i['coverage']), $i['key']);
                $this->assertTrue($i['coverage']['sufficient'], $i['key']);
                $this->assertTrue($i['provisional'], $i['key']);
                $this->assertSame('open', $i['status']);
                $this->assertContains($i['severity'], ['info', 'attention', 'high', 'critical']);
                $this->assertContains($i['domain'], ['hr', 'commercial', 'platform', 'cross']);
                $this->assertContains($i['audience'], ['self', 'team', 'department', 'organization']);
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $i['as_of']);
                foreach ($i['surfaces'] as $surface) {
                    $this->assertContains($surface, \App\Domain\Intelligence\Taxonomy::SURFACES);
                }
                foreach ($i['evidence'] as $e) {
                    $this->assertContains($e['kind'], ['metric', 'window', 'entity']);
                    if ($e['kind'] === 'entity') {
                        $this->assertSame(['kind', 'label', 'entity_type', 'entity_id'], array_keys($e), 'an entity reference carries a kind and an id and nothing else');
                    }
                }
            }
        }
        $this->assertGreaterThan(50, $seen);
    }

    public function test_confidence_is_never_an_ai_estimate_and_never_unlabelled(): void
    {
        foreach (self::ROLES as $role) {
            foreach ($this->response($role)['insights'] as $i) {
                $this->assertContains($i['confidence'], ['measured', 'rule_based'], "$role / {$i['key']}: Phase 1 produces no model output");
            }
        }
    }

    public function test_every_deep_link_is_an_in_app_path(): void
    {
        $links = [];
        foreach (self::ROLES as $role) {
            foreach ($this->response($role)['insights'] as $i) {
                $link = $i['deep_link'];
                $this->assertNotNull($link, $i['key']);
                $this->assertMatchesRegularExpression('#^/[a-z0-9][a-z0-9/_-]*$#i', $link, "$role / {$i['key']}: not a plain in-app path");
                $this->assertStringNotContainsString('//', $link);
                $this->assertStringNotContainsString(':', $link);
                $this->assertStringNotContainsString('..', $link);
                $links[$link] = true;
            }
        }
        $this->assertGreaterThanOrEqual(8, count($links), 'links to many different screens: ' . implode(', ', array_keys($links)));
    }

    /**
     * DEFECT CANDIDATE (see the report): the evidence `source` is documented as "the source table in words", but the HR detectors put the PHYSICAL table name
     * (hrms_attendances, talent_job_applications ...) in the response, where every employee who may see an insight can read it.
     */
    public function test_defect_evidence_sources_are_words_not_physical_table_names(): void
    {
        $leaks = [];
        foreach (self::ROLES as $role) {
            foreach ($this->response($role)['insights'] as $i) {
                foreach ($i['evidence'] as $e) {
                    if (isset($e['source']) && preg_match('/^[a-z0-9]+(_[a-z0-9]+)+$/', $e['source'])) {
                        $leaks[$e['source']] = $i['detector'];
                    }
                }
            }
        }

        $this->assertSame([], $leaks, 'physical table names reach the API response: ' . json_encode($leaks));
    }

    public function test_a_detector_that_throws_sql_and_hostnames_leaks_none_of_it_into_the_response_or_the_log(): void
    {
        Log::spy();
        $secret = "SQLSTATE[HY000]: SELECT * FROM payroll_secrets WHERE password = 'hunter2' on host 10.9.8.7 in /var/www/app/Secret.php:42";
        $boom = ApiTables::detector('stub.boom', 'hr', [], null, function () use ($secret) {
            throw new \RuntimeException($secret);
        });
        $badAuth = new class implements \App\Domain\Intelligence\InsightDetector {
            public function id(): string { return 'stub.badauth'; }
            public function title(): string { return 'x'; }
            public function domain(): string { return 'hr'; }
            public function surfaces(): array { return []; }
            public function authorizes(\App\Domain\Intelligence\Access\IntelligenceCaller $caller): bool { throw new \RuntimeException('authorizes exploded: 10.9.8.7 hunter2'); }
            public function detect(\App\Domain\Intelligence\Access\IntelligenceCaller $caller, \App\Domain\Intelligence\Support\DetectionClock $clock): \App\Domain\Intelligence\DetectorResult { throw new \LogicException('must not run'); }
        };
        $service = new InsightService([$boom, $badAuth, ...$this->detectors()], SmallGroupGuard::fromThresholds($this->thresholds()), $this->fixedClock());

        $run = $service->run($this->callerFor('administrator', self::T1, self::B1));
        $json = json_encode(['r' => array_map(fn ($r) => $r->summary(), $run['results']), 'i' => array_map(fn ($i) => $i->toArray(), $run['insights']), 'm' => $run['meta']]);

        foreach (['hunter2', 'payroll_secrets', '10.9.8.7', 'Secret.php', 'SQLSTATE', 'exploded'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
        $failed = array_values(array_filter($run['results'], fn ($r) => $r->detector === 'stub.boom'));
        $this->assertSame('error', $failed[0]->state);
        $this->assertGreaterThan(5, count($run['insights']), 'the other detectors were not taken down');
        // CONTRACT CHANGE (access_restricted): a detector whose authorizes() fails is still treated as not authorised and never run, but it is now reported as a bare access_restricted entry.
        $bad = array_values(array_filter($run['results'], fn ($r) => $r->detector === 'stub.badauth'));
        $this->assertCount(1, $bad);
        $this->assertSame('access_restricted', $bad[0]->state);
        $this->assertNull($bad[0]->coverage);
        $this->assertSame([], $bad[0]->insights);

        Log::shouldHaveReceived('warning')->atLeast()->once()->withArgs(function ($message, $context = []) use ($secret) {
            $flat = json_encode([$message, $context]);

            return ! str_contains($flat, 'hunter2') && ! str_contains($flat, 'payroll_secrets') && ! str_contains($flat, '10.9.8.7') && ! str_contains($flat, 'SQLSTATE')
                && isset($context['exception']) && $context['exception'] === \RuntimeException::class;
        });
    }

    public function test_detector_summaries_say_why_without_internals(): void
    {
        foreach (self::ROLES as $role) {
            foreach ($this->response($role)['results'] as $r) {
                $this->assertContains($r['state'], ['ok', 'insufficient_data', 'not_applicable', 'data_unavailable', 'access_restricted', 'error']);
                if ($r['state'] !== 'ok') {
                    $this->assertNotEmpty($r['reason'], "$role / {$r['detector']}: a non-ok state must say why");
                }
                $this->assertDoesNotMatchRegularExpression('/sqlstate|select |hrms_|talent_|gtm_|crm_|g2g_/i', (string) $r['reason']);
            }
        }
    }
}
