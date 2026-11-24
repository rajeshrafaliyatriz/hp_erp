<?php

namespace Tests\Unit\Intelligence\Cross;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\InsightDetector;
use App\Domain\Intelligence\InsightService;
use App\Domain\Intelligence\Support\SmallGroupGuard;
use Tests\Intelligence\Fixtures\CentralTables;
use Tests\Intelligence\Fixtures\CrossTables;
use Tests\Unit\Intelligence\Adversarial\AdversarialTestCase;

/**
 * Shared machinery of the cross-functional detector tests: the full hostile two-tenant fixture set (tenants 71 and 92, see AdversarialTestCase) plus the
 * central tables (learning, capability) and the cross tables (capability-gap requests, CRM organisations). Fixed clock: 2026-11-21 10:00 UTC.
 */
abstract class CrossTestCase extends AdversarialTestCase
{
    use CentralTables, CrossTables;

    protected const LEARNING = 'cross.learning_capability_gap';
    protected const RENEWAL = 'cross.renewal_invoice_overlap';
    protected const CUSTOMER_LINKS = ['/gtm/customers', '/module/crm/sales/invoices'];

    /** Phrases that would turn an observed overlap into a claimed cause. None may appear in any cross insight. */
    protected const CAUSAL_PHRASES = ['caused by', 'because', 'due to', 'as a result', 'leads to', 'led to', 'results in', 'resulting from', 'explains', 'driven by', 'responsible for', 'root cause', 'therefore'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCentralTables();
        $this->createCrossTables();
    }

    protected function detectorById(string $id): InsightDetector
    {
        foreach ($this->detectors() as $detector) {
            if ($detector->id() === $id) {
                return $detector;
            }
        }
        $this->fail("detector $id is not registered");
    }

    protected function detect(string $id, IntelligenceCaller $caller): DetectorResult
    {
        return $this->detectorById($id)->detect($caller, $this->fixedClock());
    }

    /** The service run with ONLY the given detector (so a restricted detector's queries are not mixed with other detectors'). @return array<string, mixed> */
    protected function runOnly(string $id, IntelligenceCaller $caller): array
    {
        $guard = SmallGroupGuard::fromThresholds($this->thresholds());

        return (new InsightService([$this->detectorById($id)], $guard, $this->fixedClock()))->run($caller);
    }

    /** One result + its insights as plain arrays, for equality and leak checks. */
    protected function payload(DetectorResult $result): array
    {
        return ['summary' => $result->summary(), 'insights' => array_map(static fn ($i) => $i->toArray(), $result->insights)];
    }

    protected function assertNoCausalWording(array $insight): void
    {
        $text = strtolower(implode(' ', [$insight['title'], $insight['what_happened'], $insight['why_it_matters'], (string) $insight['suggested_action'],
            ...array_map(static fn ($e) => $e['label'] . ' ' . (is_string($e['value'] ?? null) ? $e['value'] : ''), $insight['evidence'])]));
        foreach (self::CAUSAL_PHRASES as $phrase) {
            $this->assertStringNotContainsString($phrase, $text, "cross insight {$insight['key']} uses causal wording: $phrase");
        }
    }

    /** The shape every cross insight must have. */
    protected function assertCrossContract(array $insight, string $detectorId): void
    {
        $this->assertSame($detectorId, $insight['detector']);
        $this->assertSame('cross', $insight['domain']);
        $this->assertStringStartsWith('cross_', $insight['category']);
        $this->assertSame('rule_based', $insight['confidence'], 'a relationship is never "measured"');
        $this->assertTrue($insight['provisional']);
        $this->assertStringContainsString('possible relationship requiring verification', $insight['why_it_matters']);
        $this->assertStringContainsString('No cause is established', $insight['why_it_matters']);
        $this->assertNotSame('', trim((string) $insight['suggested_action']));
        $this->assertNotEmpty($insight['evidence']);
        $this->assertNotNull($this->metric($insight, 'Link used'), 'the join used must be stated as evidence');
        $this->assertNoCausalWording($insight);
    }

    /** Callers. */
    protected function hr(int $tenant = self::T1, int $user = 1022): IntelligenceCaller
    {
        return $this->callerAs('hr_manager', $tenant, $user, 3, []);
    }

    protected function admin(array $links = self::CUSTOMER_LINKS, int $tenant = self::T1): IntelligenceCaller
    {
        return $this->callerAs('administrator', $tenant, 1023, 3, $links);
    }
}
