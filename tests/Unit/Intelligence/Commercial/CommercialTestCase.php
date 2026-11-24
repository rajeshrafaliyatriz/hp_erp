<?php

namespace Tests\Unit\Intelligence\Commercial;

use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Support\Thresholds;
use Tests\Intelligence\Fixtures\CommercialTables;
use Tests\Intelligence\IntelligenceTestCase;

/** Shared setup of the commercial detector tests: fixed clock (2026-11-21 09:00 UTC), provisional default thresholds, the commercial fixture tables. */
abstract class CommercialTestCase extends IntelligenceTestCase
{
    use CommercialTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCommercialTables();
    }

    protected function clock(): DetectionClock
    {
        return DetectionClock::at('2026-11-21 09:00:00');
    }

    protected function thresholds(): Thresholds
    {
        return Thresholds::defaults();
    }

    /** @return array<string, mixed> label => evidence line */
    protected function evidenceByLabel(Insight $insight): array
    {
        $out = [];
        foreach ($insight->toArray()['evidence'] as $line) {
            $out[$line['label']] = $line;
        }

        return $out;
    }

    protected function hasEntityEvidence(Insight $insight): bool
    {
        foreach ($insight->toArray()['evidence'] as $line) {
            if ($line['kind'] === 'entity') {
                return true;
            }
        }

        return false;
    }
}
