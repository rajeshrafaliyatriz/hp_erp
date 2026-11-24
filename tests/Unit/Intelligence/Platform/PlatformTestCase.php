<?php

namespace Tests\Unit\Intelligence\Platform;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Access\RightsChecker;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Support\Thresholds;
use Tests\Intelligence\Fixtures\PlatformTables;
use Tests\Intelligence\IntelligenceTestCase;

/** Shared setup of the platform detector tests: fixed clock (2026-11-21 09:00 UTC), provisional default thresholds, the platform fixture tables. */
abstract class PlatformTestCase extends IntelligenceTestCase
{
    use PlatformTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPlatformTables();
    }

    protected function clock(): DetectionClock
    {
        return DetectionClock::at('2026-11-21 09:00:00');
    }

    protected function thresholds(): Thresholds
    {
        return Thresholds::defaults();
    }

    /** A caller holding `view` on exactly the given menu links. */
    protected function platformCaller(string $role, array $links = [], int $tenant = 1, int $user = 10): IntelligenceCaller
    {
        $rights = new class($links) implements RightsChecker {
            public function __construct(private array $links)
            {
            }

            public function can(int $profileId, ?int $tenantId, string $accessLink, string $action = 'view'): bool
            {
                return $action === 'view' && in_array($accessLink, $this->links, true);
            }
        };

        return new IntelligenceCaller($tenant, $user, 7, $role, null, $rights);
    }

    /** @return array<string, array> label => evidence line */
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

    /** @return list<string> the SQL of every statement run while `$fn` ran */
    protected function sqlOf(callable $fn): array
    {
        $log = [];
        $this->app['events']->listen(\Illuminate\Database\Events\QueryExecuted::class, static function ($q) use (&$log): void {
            if (! preg_match('/sqlite_master|pragma_table_xinfo|pragma_table_info/i', $q->sql)) {   // schema introspection of the probe, not a data read
                $log[] = $q->sql;
            }
        });
        try {
            $fn();
        } finally {
            $this->app['events']->forget(\Illuminate\Database\Events\QueryExecuted::class);
        }

        return $log;
    }

    /** Every string the response of one result could carry. */
    protected function jsonOf(\App\Domain\Intelligence\DetectorResult $r): string
    {
        return json_encode([$r->summary(), array_map(static fn ($i) => $i->toArray(), $r->insights)]);
    }
}
