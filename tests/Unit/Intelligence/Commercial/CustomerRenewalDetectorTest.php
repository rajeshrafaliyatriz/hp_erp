<?php

namespace Tests\Unit\Intelligence\Commercial;

use App\Domain\Intelligence\Detectors\Commercial\CustomerRenewalDetector;
use App\Domain\Intelligence\Taxonomy;

class CustomerRenewalDetectorTest extends CommercialTestCase
{
    private const LINKS = ['/gtm/customers'];

    private function detector(): CustomerRenewalDetector
    {
        return new CustomerRenewalDetector($this->db(), $this->thresholds());
    }

    private function renewal(int $tenant, array $o = []): void
    {
        $this->insertRow('gtm_renewals', array_merge(['sub_institute_id' => $tenant, 'account_id' => 1, 'renewal_date' => '2026-12-01', 'status' => 'upcoming', 'owner_user_id' => 5], $o));
    }

    /** A live account (created once per tenant+id) with a health snapshot on $day. */
    private function snapshot(int $tenant, int $account, string $day, string $band, ?int $score = 50): void
    {
        $exists = $this->db()->table('gtm_accounts')->where('sub_institute_id', $tenant)->where('id', $account)->exists();
        if (! $exists) {
            $this->insertRow('gtm_accounts', ['id' => $account, 'sub_institute_id' => $tenant, 'deleted_at' => null]);
        }
        $this->insertRow('gtm_health_snapshots', ['sub_institute_id' => $tenant, 'account_id' => $account, 'snapshot_date' => $day, 'band' => $band, 'score' => $score]);
    }

    public function test_reports_overdue_and_due_soon_renewals_and_excludes_closed_deleted_and_far_ones(): void
    {
        $this->renewal(1, ['renewal_date' => '2026-11-10']);                              // overdue
        $this->renewal(1, ['renewal_date' => '2026-12-05', 'owner_user_id' => null]);      // due soon, unowned
        $this->renewal(1, ['renewal_date' => '2026-12-20', 'status' => 'in_progress']);    // due soon
        $this->renewal(1, ['renewal_date' => '2027-03-01']);                              // beyond the alert window
        $this->renewal(1, ['renewal_date' => '2026-11-10', 'status' => 'renewed']);        // closed
        $this->renewal(1, ['renewal_date' => '2026-11-10', 'deleted_at' => '2026-11-01 00:00:00']);
        $this->renewal(2, ['renewal_date' => '2026-11-10']);                              // another tenant

        $result = $this->detector()->detect($this->commercialCaller('administrator', self::LINKS), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertCount(1, $result->insights);
        $insight = $result->insights[0];
        $this->assertSame('high', $insight->severity, 'an overdue renewal is high');
        $ev = $this->evidenceByLabel($insight);
        $this->assertSame(1, $ev['Renewals past their date and still open']['value']);
        $this->assertSame(2, $ev['Renewals due in the next 60 days']['value']);
        $this->assertSame(1, $ev['Of these, with no owner']['value']);
        $this->assertSame('/gtm/customers', $insight->deepLink);
        $this->assertTrue($this->hasEntityEvidence($insight));
    }

    public function test_at_risk_uses_the_latest_day_only_and_unknown_is_never_at_risk_or_healthy(): void
    {
        $this->snapshot(1, 1, '2026-11-19', 'at_risk');
        $this->snapshot(1, 2, '2026-11-19', 'healthy');
        $this->snapshot(1, 1, '2026-11-20', 'healthy');
        $this->snapshot(1, 2, '2026-11-20', 'at_risk');
        $this->snapshot(1, 3, '2026-11-20', 'at_risk');
        $this->snapshot(1, 4, '2026-11-20', 'unknown', null);
        $this->snapshot(1, 5, '2026-11-20', 'watch', null);
        $this->snapshot(2, 9, '2026-11-21', 'at_risk');                                   // another tenant, a later day

        $result = $this->detector()->detect($this->commercialCaller('administrator', self::LINKS), $this->clock());

        $insight = $result->insights[0];
        $ev = $this->evidenceByLabel($insight);
        $this->assertSame('rule_based', $insight->confidence);
        $this->assertSame(5, $ev['Accounts with a health snapshot on the latest day']['value']);
        $this->assertSame(2, $ev['At risk']['value']);
        $this->assertSame(2, $ev['Not enough data to score (not counted as healthy or at risk)']['value']);
        $this->assertSame('2026-11-20', $ev['Latest snapshot day']['value']);
    }

    public function test_no_trend_is_claimed_below_the_snapshot_day_minimum_and_one_is_given_above_it(): void
    {
        $this->snapshot(1, 1, '2026-11-20', 'at_risk');
        $few = $this->detector()->detect($this->commercialCaller('administrator', self::LINKS), $this->clock());
        $labels = implode('|', array_keys($this->evidenceByLabel($few->insights[0])));
        $this->assertStringContainsString('Snapshot days recorded', $labels);
        $this->assertStringNotContainsString('earliest snapshot day', $labels);

        foreach (['2026-11-01', '2026-11-05', '2026-11-08', '2026-11-12', '2026-11-15', '2026-11-18'] as $day) {
            $this->snapshot(1, 1, $day, 'healthy');
        }
        $many = $this->detector()->detect($this->commercialCaller('administrator', self::LINKS), $this->clock());
        $ev = $this->evidenceByLabel($many->insights[0]);
        $trend = array_values(array_filter(array_keys($ev), fn ($l) => str_contains($l, 'earliest snapshot day')));
        $this->assertCount(1, $trend);
        $this->assertSame(0, $ev[$trend[0]]['value']);
    }

    public function test_insufficient_when_nothing_is_recorded_and_ok_but_empty_when_all_is_calm(): void
    {
        $empty = $this->detector()->detect($this->commercialCaller('administrator', self::LINKS), $this->clock());
        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $empty->state);

        $this->renewal(1, ['renewal_date' => '2027-06-01']);
        $this->snapshot(1, 1, '2026-11-20', 'healthy');
        $calm = $this->detector()->detect($this->commercialCaller('administrator', self::LINKS), $this->clock());
        $this->assertSame(Taxonomy::STATE_OK, $calm->state);
        $this->assertSame([], $calm->insights);
    }

    public function test_a_stale_latest_snapshot_is_never_reported_as_current_at_risk(): void
    {
        $this->snapshot(1, 1, '2026-11-10', 'at_risk');     // 11 days old at the fixed clock (3 is the provisional limit)
        $this->snapshot(1, 2, '2026-11-10', 'at_risk');

        $result = $this->detector()->detect($this->commercialCaller('administrator', self::LINKS), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $result->state);
        $this->assertSame([], $result->insights);
        $this->assertStringContainsString('The latest health snapshot is 11 days old', (string) $result->reason);
    }

    public function test_a_snapshot_exactly_at_the_staleness_limit_is_still_used_and_one_day_older_is_not(): void
    {
        $this->snapshot(1, 1, '2026-11-18', 'at_risk');     // 3 days old: allowed
        $this->assertCount(1, $this->detector()->detect($this->commercialCaller('administrator', self::LINKS), $this->clock())->insights);

        $this->db()->table('gtm_health_snapshots')->update(['snapshot_date' => '2026-11-17']);   // 4 days old: stale
        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $this->detector()->detect($this->commercialCaller('administrator', self::LINKS), $this->clock())->state);
    }

    public function test_renewals_are_still_reported_when_only_the_health_snapshot_is_stale(): void
    {
        $this->snapshot(1, 1, '2026-11-01', 'at_risk');
        $this->renewal(1, ['renewal_date' => '2026-11-10']);

        $result = $this->detector()->detect($this->commercialCaller('administrator', self::LINKS), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertCount(1, $result->insights, 'the renewals insight only; no at-risk claim from stale data');
        $this->assertSame('renewals', $result->insights[0]->category);
        $this->assertStringContainsString('The latest health snapshot is 20 days old', (string) $result->coverage->reason);
    }

    public function test_snapshots_of_deleted_accounts_and_of_other_tenants_accounts_are_not_counted(): void
    {
        $this->snapshot(1, 1, '2026-11-20', 'at_risk');
        $this->snapshot(1, 2, '2026-11-20', 'at_risk');
        $this->snapshot(1, 3, '2026-11-20', 'healthy');
        $this->db()->table('gtm_accounts')->where('sub_institute_id', 1)->where('id', 2)->update(['deleted_at' => '2026-11-15 00:00:00']);
        // A snapshot of tenant 1 whose account id exists ONLY in tenant 2: it must not be counted as a live tenant-1 account.
        $this->insertRow('gtm_accounts', ['id' => 77, 'sub_institute_id' => 2, 'deleted_at' => null]);
        $this->insertRow('gtm_health_snapshots', ['sub_institute_id' => 1, 'account_id' => 77, 'snapshot_date' => '2026-11-20', 'band' => 'at_risk', 'score' => 20]);

        $ev = $this->evidenceByLabel($this->detector()->detect($this->commercialCaller('administrator', self::LINKS), $this->clock())->insights[0]);

        $this->assertSame(2, $ev['Accounts with a health snapshot on the latest day']['value']);
        $this->assertSame(1, $ev['At risk']['value']);
    }

    public function test_executive_gets_no_entity_references(): void
    {
        $this->renewal(1, ['renewal_date' => '2026-11-10']);
        $this->snapshot(1, 1, '2026-11-20', 'at_risk');

        $result = $this->detector()->detect($this->commercialCaller('executive', self::LINKS), $this->clock());

        $this->assertCount(2, $result->insights);
        foreach ($result->insights as $insight) {
            $this->assertFalse($this->hasEntityEvidence($insight));
        }
    }

    public function test_insight_prose_states_observations_not_predictions(): void
    {
        $this->renewal(1, ['renewal_date' => '2026-11-10']);
        $this->snapshot(1, 1, '2026-11-20', 'at_risk');

        $text = strtolower(json_encode(array_map(fn ($i) => $i->toArray(), $this->detector()->detect($this->commercialCaller('administrator', self::LINKS), $this->clock())->insights)));

        foreach (['likely', 'probably', 'predict', 'churn', 'will '] as $word) {
            $this->assertStringNotContainsString($word, $text);
        }
    }

    public function test_authorization_needs_the_customers_right(): void
    {
        $this->assertFalse($this->detector()->authorizes($this->commercialCaller('administrator')));
        $this->assertFalse($this->detector()->authorizes($this->commercialCaller('administrator', ['/gtm/deals'])));
        $this->assertTrue($this->detector()->authorizes($this->commercialCaller('employee', self::LINKS)));
    }
}
