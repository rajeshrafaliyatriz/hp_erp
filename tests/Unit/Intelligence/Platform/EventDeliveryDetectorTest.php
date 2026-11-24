<?php

namespace Tests\Unit\Intelligence\Platform;

use App\Domain\Intelligence\Detectors\Platform\EventDeliveryDetector;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Support\Facades\Schema;

class EventDeliveryDetectorTest extends PlatformTestCase
{
    private const LINKS = ['/platform-services/event-bus'];

    private function detector(): EventDeliveryDetector
    {
        return new EventDeliveryDetector($this->db(), $this->thresholds());
    }

    private function admin(int $tenant = 1, string $role = 'administrator')
    {
        return $this->platformCaller($role, self::LINKS, $tenant);
    }

    private function done(int $tenant, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->addEventWithDelivery($tenant, 'done');
        }
    }

    public function test_failed_deliveries_are_counted_per_consumer_and_never_expose_errors_or_payloads(): void
    {
        $this->done(1, 12);
        $e1 = $this->addEventWithDelivery(1, 'failed', '2026-11-20 08:00:00', 'workflow_runner');
        $this->addEventWithDelivery(1, 'failed', '2026-11-20 09:00:00', 'workflow_runner');
        $this->addEventWithDelivery(1, 'failed', '2026-11-20 10:00:00', 'audit_projector');

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertCount(1, $result->insights);
        $insight = $result->insights[0];
        $this->assertSame('high', $insight->severity, '3 of 15 is 20%, above the 5% provisional threshold');
        $ev = $this->evidenceByLabel($insight);
        $this->assertSame(15, $ev['Deliveries in the window']['value']);
        $this->assertSame(3, $ev['Failed deliveries']['value']);
        $this->assertSame(2, $ev['Failed for consumer workflow_runner']['value']);
        $this->assertSame(1, $ev['Failed for consumer audit_projector']['value']);
        $this->assertTrue($this->hasEntityEvidence($insight));
        $this->assertContains($e1, array_column(array_filter($insight->toArray()['evidence'], fn ($e) => $e['kind'] === 'entity'), 'entity_id'));
        $this->assertStringNotContainsString(self::SECRET_SENTINEL, $this->jsonOf($result), 'last_error and payload are never read');
        $this->assertSame('/platform-services/event-bus', $insight->deepLink);
    }

    public function test_the_error_text_payload_and_actor_columns_are_never_selected(): void
    {
        $this->done(1, 12);
        $this->addEventWithDelivery(1, 'failed');

        $sql = strtolower(implode("\n", $this->sqlOf(fn () => $this->detector()->detect($this->admin(), $this->clock()))));

        foreach (['last_error', 'payload', 'actor_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sql);
        }
    }

    public function test_stale_pending_deliveries_are_reported_and_recent_pending_are_not(): void
    {
        $this->done(1, 12);
        $this->addEventWithDelivery(1, 'pending', '2026-11-19 08:00:00');   // older than 24 hours
        $this->addEventWithDelivery(1, 'pending', '2026-11-21 08:00:00');   // one hour old: normal

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertCount(1, $result->insights);
        $this->assertSame(1, $this->evidenceByLabel($result->insights[0])['Pending more than 24 hours']['value']);
    }

    public function test_a_few_failures_below_the_share_threshold_are_attention_not_high(): void
    {
        $this->done(1, 60);
        $this->addEventWithDelivery(1, 'failed');

        $this->assertSame('attention', $this->detector()->detect($this->admin(), $this->clock())->insights[0]->severity);
    }

    public function test_other_tenants_events_and_old_events_are_never_counted(): void
    {
        $this->done(1, 12);
        for ($i = 0; $i < 40; $i++) {
            $this->addEventWithDelivery(92, 'failed');
        }
        $this->addEventWithDelivery(1, 'failed', '2026-10-01 00:00:00');   // outside the 7-day window

        $result = $this->detector()->detect($this->admin(1), $this->clock());

        $this->assertSame([], $result->insights);
        $this->assertSame(12, $result->coverage->observed);
    }

    public function test_insufficient_below_the_delivery_minimum(): void
    {
        $this->done(1, 3);
        $this->addEventWithDelivery(1, 'failed');

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $this->detector()->detect($this->admin(), $this->clock())->state);
    }

    public function test_executive_and_auditor_get_no_record_references(): void
    {
        $this->done(1, 12);
        $this->addEventWithDelivery(1, 'failed');

        foreach (['executive', 'auditor'] as $role) {
            $result = $this->detector()->detect($this->admin(1, $role), $this->clock());
            $this->assertCount(1, $result->insights, $role);
            $this->assertFalse($this->hasEntityEvidence($result->insights[0]), $role);
        }
    }

    public function test_a_missing_event_store_is_data_unavailable(): void
    {
        Schema::drop('g2g_event_delivery');

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_DATA_UNAVAILABLE, $result->state);
        $this->assertNull($result->summary()['insight_count']);
    }

    public function test_authorization_needs_the_event_bus_right(): void
    {
        $this->assertFalse($this->detector()->authorizes($this->platformCaller('administrator')));
        $this->assertFalse($this->detector()->authorizes($this->platformCaller('administrator', ['/platform-services/workflow?module=crm'])));
        $this->assertTrue($this->detector()->authorizes($this->platformCaller('employee', self::LINKS)));
    }
}
