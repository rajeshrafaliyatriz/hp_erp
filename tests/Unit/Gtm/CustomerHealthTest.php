<?php

namespace Tests\Unit\Gtm;

use App\Domain\Gtm\Customers\CustomerHealth;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerHealthTest extends TestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-10-10 09:00:00');
    }

    /** @param array<string, mixed> $over */
    private function facts(array $over = []): array
    {
        return $over + [
            'days_since_activity' => 5, 'activities_total' => 10, 'inbound_90d' => 3, 'outbound_90d' => 4,
            'contacts' => 3, 'active_contacts' => 3, 'unreachable_contacts' => 0, 'has_decision_maker' => true,
            'renewal' => ['date' => '2027-03-01', 'status' => 'upcoming'],
        ];
    }

    public function test_a_fully_engaged_customer_is_healthy_and_every_component_is_explained(): void
    {
        $h = CustomerHealth::assess($this->facts(), $this->now);

        $this->assertSame(100, $h['score']);
        $this->assertSame('healthy', $h['band']);
        $this->assertSame([], $h['risks']);
        $this->assertSame([], $h['missing']);
        foreach (['recency', 'responsiveness', 'coverage', 'contact_health', 'renewal'] as $k) {
            $this->assertNotNull($h['components'][$k]['score'], $k);
            $this->assertNotSame('', $h['components'][$k]['detail'], "$k must say why");
            $this->assertArrayHasKey('weight', $h['components'][$k]);
        }
    }

    public function test_missing_data_is_left_out_not_scored_as_zero(): void
    {
        // No renewal recorded, no messages either way: those components are unavailable.
        $h = CustomerHealth::assess($this->facts(['renewal' => null, 'inbound_90d' => 0, 'outbound_90d' => 0]), $this->now);

        $this->assertNull($h['components']['renewal']['score']);
        $this->assertNull($h['components']['responsiveness']['score']);
        $this->assertSame(100, $h['score'], 'the remaining components are all perfect, so the mean is 100 - not dragged down by data nobody entered');
        $this->assertCount(2, $h['missing'], 'each gap is named so an administrator knows what to record');
    }

    public function test_fewer_than_two_available_components_gives_no_score(): void
    {
        $h = CustomerHealth::assess([
            'days_since_activity' => null, 'activities_total' => 0, 'inbound_90d' => 0, 'outbound_90d' => 0,
            'contacts' => 0, 'active_contacts' => 0, 'unreachable_contacts' => 0, 'has_decision_maker' => false, 'renewal' => null,
        ], $this->now);

        // coverage is the only component with data here ("no contacts" is a real, scored fact)
        $this->assertNull($h['score']);
        $this->assertSame('unknown', $h['band']);
        $this->assertContains('Fewer than two health components have data, so no score is given.', $h['missing']);
    }

    public function test_outreach_without_any_reply_is_a_risk_not_silence(): void
    {
        $h = CustomerHealth::assess($this->facts(['inbound_90d' => 0, 'outbound_90d' => 5]), $this->now);
        $this->assertSame(0, $h['components']['responsiveness']['score']);
        $this->assertContains('We reached out in the last 90 days and no reply is recorded.', $h['risks']);
    }

    #[DataProvider('recencyCases')]
    public function test_recency_steps(int $days, int $expected): void
    {
        $h = CustomerHealth::assess($this->facts(['days_since_activity' => $days]), $this->now);
        $this->assertSame($expected, $h['components']['recency']['score']);
    }

    public static function recencyCases(): array
    {
        return ['14d' => [14, 100], '15d' => [15, 80], '30d' => [30, 80], '31d' => [31, 50], '60d' => [60, 50], '61d' => [61, 25], '90d' => [90, 25], '91d' => [91, 0]];
    }

    public function test_a_quiet_account_is_listed_as_a_risk(): void
    {
        $h = CustomerHealth::assess($this->facts(['days_since_activity' => 75]), $this->now);
        $this->assertContains('No activity logged for 75 days.', $h['risks']);
    }

    public function test_renewal_rules(): void
    {
        $overdue = CustomerHealth::assess($this->facts(['renewal' => ['date' => '2026-09-30', 'status' => 'upcoming']]), $this->now);
        $this->assertSame(0, $overdue['components']['renewal']['score']);
        $this->assertStringContainsString('passed 10 day(s) ago', $overdue['components']['renewal']['detail']);

        $soon = CustomerHealth::assess($this->facts(['renewal' => ['date' => '2026-10-25', 'status' => 'upcoming']]), $this->now);
        $this->assertSame(30, $soon['components']['renewal']['score'], 'due within 30 days and not being worked');
        $this->assertNotEmpty(array_filter($soon['risks'], fn ($r) => str_contains($r, 'not yet in progress')));

        $worked = CustomerHealth::assess($this->facts(['renewal' => ['date' => '2026-10-25', 'status' => 'in_progress']]), $this->now);
        $this->assertSame(80, $worked['components']['renewal']['score'], 'the same date, but someone is on it');

        $far = CustomerHealth::assess($this->facts(['renewal' => ['date' => '2027-03-01', 'status' => 'upcoming']]), $this->now);
        $this->assertSame(100, $far['components']['renewal']['score']);
    }

    public function test_contact_coverage_and_reachability(): void
    {
        $none = CustomerHealth::assess($this->facts(['contacts' => 0, 'active_contacts' => 0, 'has_decision_maker' => false]), $this->now);
        $this->assertSame(0, $none['components']['coverage']['score']);
        $this->assertNull($none['components']['contact_health']['score']);
        $this->assertContains('No contacts are recorded at this account.', $none['risks']);

        $noDm = CustomerHealth::assess($this->facts(['has_decision_maker' => false]), $this->now);
        $this->assertSame(50, $noDm['components']['coverage']['score']);
        $this->assertContains('No economic buyer or decision maker is recorded.', $noDm['risks']);

        $bad = CustomerHealth::assess($this->facts(['contacts' => 4, 'active_contacts' => 2, 'unreachable_contacts' => 2]), $this->now);
        $this->assertSame(50, $bad['components']['contact_health']['score']);
        $this->assertContains('2 contact(s) are bounced, unsubscribed or do-not-contact.', $bad['risks']);
    }

    public function test_bands_follow_the_configured_thresholds(): void
    {
        $facts = $this->facts(['days_since_activity' => 45, 'inbound_90d' => 1, 'has_decision_maker' => false, 'renewal' => ['date' => '2026-10-20', 'status' => 'upcoming']]);

        config(['gtm.health.healthy_at' => 70, 'gtm.health.watch_at' => 40]);
        $default = CustomerHealth::assess($facts, $this->now);
        $this->assertContains($default['band'], ['watch', 'at_risk']);

        config(['gtm.health.healthy_at' => 5, 'gtm.health.watch_at' => 1]);
        $this->assertSame('healthy', CustomerHealth::assess($facts, $this->now)['band'], 'thresholds are configuration, not hard-coded');
    }

    public function test_the_score_is_the_weighted_mean_of_available_components(): void
    {
        // recency 30d => 80 (w30); responsiveness 0 inbound/ 2 outbound => 0 (w20); coverage dm+3 active => 100 (w20); contact_health 100 (w10); renewal none => unavailable.
        $h = CustomerHealth::assess($this->facts(['days_since_activity' => 30, 'inbound_90d' => 0, 'outbound_90d' => 2, 'renewal' => null]), $this->now);
        $expected = (int) round((80 * 30 + 0 * 20 + 100 * 20 + 100 * 10) / (30 + 20 + 20 + 10));
        $this->assertSame($expected, $h['score']);
        $this->assertSame(68, $expected, 'worked by hand: 5400 / 80 = 67.5, rounded');
    }
}
