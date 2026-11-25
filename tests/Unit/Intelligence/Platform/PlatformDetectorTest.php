<?php

namespace Tests\Unit\Intelligence\Platform;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\DetectorRegistry;
use App\Domain\Intelligence\Detectors\Platform\PlatformDetector;
use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\InsightService;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Support\SmallGroupGuard;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Support\Facades\Log;

class PlatformDetectorTest extends PlatformTestCase
{
    private const IDS = [
        'platform.scheduler_reliability', 'platform.integration_status', 'platform.event_delivery', 'platform.ai_usage', 'platform.governance', 'agentic.run_health',
    ];

    private function throwing(): PlatformDetector
    {
        return new class($this->db(), $this->thresholds()) extends PlatformDetector {
            public function id(): string { return 'platform.stub'; }

            public function title(): string { return 'Stub'; }

            public function surfaces(): array { return [Taxonomy::SURFACE_PLATFORM_SCHEDULER]; }

            public function authorizes(IntelligenceCaller $caller): bool { return true; }

            protected function assess(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
            {
                throw new \RuntimeException("SQLSTATE[HY000] select * from g2g_integration_credentials where config = 'hunter2' on 10.9.8.7");
            }
        };
    }

    public function test_a_throwing_detector_is_an_error_not_zero_and_leaks_nothing(): void
    {
        Log::spy();

        $result = $this->throwing()->detect($this->platformCaller('administrator'), $this->clock());

        $this->assertSame(Taxonomy::STATE_ERROR, $result->state);
        $this->assertNull($result->summary()['insight_count'], 'an error is never zero records');
        $json = json_encode($result->summary());
        foreach (['hunter2', 'SQLSTATE', '10.9.8.7', 'g2g_integration_credentials'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context = []) => ($context['exception'] ?? null) === \RuntimeException::class
            && ! str_contains(json_encode($context), 'hunter2'));
    }

    public function test_the_registry_holds_the_six_platform_detectors_with_the_contracted_ids_and_the_platform_domain(): void
    {
        $byId = [];
        foreach (DetectorRegistry::forConnection($this->db(), $this->thresholds()) as $d) {
            $byId[$d->id()] = $d;
        }

        $this->assertCount(23, $byId);
        foreach (self::IDS as $id) {
            $this->assertArrayHasKey($id, $byId);
            $this->assertSame(Taxonomy::DOMAIN_PLATFORM, $byId[$id]->domain(), $id);
            $this->assertNotSame([], $byId[$id]->surfaces(), $id);
            foreach ($byId[$id]->surfaces() as $surface) {
                $this->assertContains($surface, Taxonomy::SURFACES, "$id: $surface");
            }
        }
    }

    public function test_the_platform_thresholds_are_provisional_defaults_mirrored_in_config_and_their_minimum_gates_cannot_be_configured_to_zero(): void
    {
        $keys = ['scheduler_window_days', 'scheduler_min_runs', 'scheduler_silent_hours', 'scheduler_skip_streak', 'scheduler_open_run_hours', 'integrations_min', 'integration_untested_days',
            'events_window_days', 'events_min_deliveries', 'events_failed_pct', 'events_pending_hours', 'ai_usage_window_days', 'ai_usage_min_events', 'ai_usage_failure_pct',
            'ai_usage_min_priced', 'ai_usage_cost_concentration_pct', 'governance_min_policies', 'agentic_window_days', 'agentic_min_runs', 'agentic_failure_pct',
            'agentic_stalled_hours', 'agentic_min_agents', 'agentic_cost_rise_pct'];
        $config = require config_path('intelligence.php');
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, \App\Domain\Intelligence\Support\Thresholds::DEFAULTS, $key);
            $this->assertArrayHasKey($key, $config['thresholds'], $key);
        }
        $zeroed = \App\Domain\Intelligence\Support\Thresholds::with(array_fill_keys(['scheduler_min_runs', 'integrations_min', 'events_min_deliveries', 'ai_usage_min_events', 'ai_usage_min_priced',
            'governance_min_policies', 'agentic_min_runs', 'agentic_min_agents'], 0));
        foreach (['scheduler_min_runs', 'integrations_min', 'events_min_deliveries', 'ai_usage_min_events', 'ai_usage_min_priced', 'governance_min_policies', 'agentic_min_runs', 'agentic_min_agents'] as $gate) {
            $this->assertSame(1, $zeroed->int($gate), "$gate must never be configurable below 1");
        }
    }

    public function test_every_platform_detector_authorises_nobody_without_a_right_and_one_failure_does_not_take_the_others_down(): void
    {
        $nobody = $this->platformCaller('administrator');
        $detectors = array_values(array_filter(DetectorRegistry::forConnection($this->db(), $this->thresholds()), fn ($d) => in_array($d->id(), self::IDS, true)));
        $this->assertCount(6, $detectors);
        foreach ($detectors as $d) {
            $this->assertFalse($d->authorizes($nobody), $d->id() . ': no administrator bypass');
        }

        // every one of them runs in isolation on EMPTY tables without throwing, and none claims a clean bill
        $all = $this->platformCaller('administrator', [
            ...\App\Domain\Intelligence\Detectors\Platform\PlatformAccess::platformModuleLinks(), '/platform-services/event-bus', '/ai/usage-cost', '/ai/policies',
            ...\App\Domain\Intelligence\Detectors\Platform\PlatformAccess::agenticLinks(),
        ]);
        $run = (new InsightService([$this->throwing(), ...$detectors], new SmallGroupGuard(5), $this->clock()))->run($all);

        $states = [];
        foreach ($run['results'] as $r) {
            $states[$r->detector] = $r->state;
        }
        $this->assertSame('error', $states['platform.stub']);
        foreach (self::IDS as $id) {
            $this->assertContains($states[$id], [Taxonomy::STATE_INSUFFICIENT, Taxonomy::STATE_NOT_APPLICABLE], "$id on empty tables");
        }
    }
}
