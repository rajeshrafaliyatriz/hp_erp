<?php

namespace Tests\Unit\Intelligence\Commercial;

use App\Domain\Intelligence\Detectors\Commercial\DealHealthDetector;
use App\Domain\Intelligence\Taxonomy;

class DealHealthDetectorTest extends CommercialTestCase
{
    private const GTM = ['/gtm/deals'];
    private const CRM = ['/module/crm/sales/opportunities'];

    private function detector(): DealHealthDetector
    {
        return new DealHealthDetector($this->db(), $this->thresholds());
    }

    /** Ten healthy open deals for $tenant (own account each, a decision maker and a recent human activity); returns nothing. */
    private function healthyDeals(int $tenant, int $n = 10, int $firstAccount = 1): void
    {
        for ($i = 0; $i < $n; $i++) {
            $account = $firstAccount + $i;
            $this->addDeal($tenant, ['account_id' => $account]);
            $this->insertRow('gtm_contacts', ['sub_institute_id' => $tenant, 'account_id' => $account, 'role_in_deal' => 'decision_maker']);
            $this->insertRow('gtm_activities', ['sub_institute_id' => $tenant, 'account_id' => $account, 'deal_id' => $i + 1, 'type' => 'email', 'occurred_at' => '2026-11-18 10:00:00']);
        }
    }

    public function test_flags_open_gtm_deals_with_evidence_coverage_and_entity_references(): void
    {
        $this->healthyDeals(1);
        $this->db()->table('gtm_deals')->whereIn('id', [1, 2, 3, 4])->update(['next_step' => '']);

        $result = $this->detector()->detect($this->commercialCaller('administrator', self::GTM), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertCount(1, $result->insights);
        $insight = $result->insights[0];
        $this->assertSame('rule_based', $insight->confidence);
        $this->assertSame('/gtm/deals', $insight->deepLink);
        $this->assertSame('attention', $insight->severity);
        $ev = $this->evidenceByLabel($insight);
        $this->assertSame(10, $ev['Open GTM deals']['value']);
        $this->assertSame(4, $ev['Deals with at least one flag']['value']);
        $this->assertSame(4, $ev['No next step recorded']['value']);
        $this->assertTrue($this->hasEntityEvidence($insight));
        $this->assertSame(10, $result->coverage->observed);
    }

    public function test_executive_gets_aggregates_without_any_entity_reference(): void
    {
        $this->healthyDeals(1);
        $this->db()->table('gtm_deals')->whereIn('id', [1, 2])->update(['next_step' => '']);

        $result = $this->detector()->detect($this->commercialCaller('executive', self::GTM), $this->clock());

        $this->assertCount(1, $result->insights);
        $this->assertFalse($this->hasEntityEvidence($result->insights[0]));
    }

    public function test_account_level_activity_counts_and_system_activity_does_not(): void
    {
        $this->healthyDeals(1);
        // Deal 1: its only activity is a system row, plus a recent human activity on the ACCOUNT -> not quiet.
        $this->db()->table('gtm_activities')->where('deal_id', 1)->update(['type' => 'system']);
        $this->insertRow('gtm_activities', ['sub_institute_id' => 1, 'account_id' => 1, 'deal_id' => null, 'type' => 'call', 'occurred_at' => '2026-11-20 10:00:00']);
        // Deal 2: only a system activity -> counts as no activity ever.
        $this->db()->table('gtm_activities')->where('deal_id', 2)->update(['type' => 'system']);

        $result = $this->detector()->detect($this->commercialCaller('administrator', self::GTM), $this->clock());

        $ev = $this->evidenceByLabel($result->insights[0]);
        $this->assertSame(1, $ev['Deals with at least one flag']['value']);
        $this->assertSame(1, $ev['No activity ever logged']['value']);
    }

    public function test_quiet_deals_are_flagged_after_the_provisional_threshold(): void
    {
        $this->healthyDeals(1);
        $this->db()->table('gtm_activities')->where('deal_id', 3)->update(['occurred_at' => '2026-10-01 10:00:00']);

        $result = $this->detector()->detect($this->commercialCaller('administrator', self::GTM), $this->clock());

        $ev = $this->evidenceByLabel($result->insights[0]);
        $this->assertSame(1, $ev['No recent activity']['value']);
    }

    public function test_activity_flags_are_withheld_when_activity_is_not_logged_at_all(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->addDeal(1, ['account_id' => $i + 1]);
            $this->insertRow('gtm_contacts', ['sub_institute_id' => 1, 'account_id' => $i + 1, 'role_in_deal' => 'economic_buyer']);
        }

        $result = $this->detector()->detect($this->commercialCaller('administrator', self::GTM), $this->clock());

        $this->assertSame([], $result->insights, 'with no activity logged anywhere the activity flags are noise and are withheld');
    }

    public function test_decision_maker_missing_and_stuck_and_close_date_flags(): void
    {
        $this->healthyDeals(1);
        $this->db()->table('gtm_contacts')->where('account_id', 1)->delete();
        $this->db()->table('gtm_deals')->where('id', 2)->update(['stage_changed_at' => '2026-09-01 00:00:00']);
        $this->db()->table('gtm_deals')->where('id', 3)->update(['expected_close_date' => '2026-11-01']);
        $this->db()->table('gtm_deals')->where('id', 4)->update(['expected_close_date' => null]);
        $this->db()->table('gtm_deals')->where('id', 5)->update(['next_step_due' => '2026-11-01']);

        $ev = $this->evidenceByLabel($this->detector()->detect($this->commercialCaller('administrator', self::GTM), $this->clock())->insights[0]);

        $this->assertSame(1, $ev['No decision maker on the account']['value']);
        $this->assertSame(1, $ev['Stuck in the same stage']['value']);
        $this->assertSame(1, $ev['Expected close date passed']['value']);
        $this->assertSame(1, $ev['No expected close date']['value']);
        $this->assertSame(1, $ev['Next step overdue']['value']);
    }

    public function test_insufficient_below_the_open_deal_minimum(): void
    {
        $this->healthyDeals(1, 3);

        $result = $this->detector()->detect($this->commercialCaller('administrator', self::GTM), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $result->state);
        $this->assertSame([], $result->insights);
    }

    public function test_other_tenants_and_soft_deleted_and_closed_deals_are_never_counted(): void
    {
        $this->healthyDeals(1, 9);
        $this->healthyDeals(2, 20, 1);                                        // colliding account ids in another tenant
        $this->addDeal(1, ['account_id' => 50, 'deleted_at' => '2026-11-01 00:00:00']);
        $this->addDeal(1, ['account_id' => 51, 'stage' => 'won']);
        $this->addDeal(1, ['account_id' => 52, 'stage' => 'lost']);

        $result = $this->detector()->detect($this->commercialCaller('administrator', self::GTM, 1), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $result->state);
        $this->assertSame(9, $result->coverage->observed, 'only this tenant\'s open, live deals');
    }

    public function test_tenant_two_activity_on_a_colliding_account_id_does_not_rescue_a_tenant_one_deal(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->addDeal(1, ['account_id' => $i + 1]);
            $this->insertRow('gtm_contacts', ['sub_institute_id' => 1, 'account_id' => $i + 1, 'role_in_deal' => 'decision_maker']);
        }
        // Real activity for tenant 1 on nine accounts, none for account 10; tenant 2 has fresh activity on account 10.
        for ($i = 1; $i <= 9; $i++) {
            $this->insertRow('gtm_activities', ['sub_institute_id' => 1, 'account_id' => $i, 'deal_id' => null, 'type' => 'email', 'occurred_at' => '2026-11-19 10:00:00']);
        }
        $this->insertRow('gtm_activities', ['sub_institute_id' => 2, 'account_id' => 10, 'deal_id' => null, 'type' => 'email', 'occurred_at' => '2026-11-20 10:00:00']);

        $ev = $this->evidenceByLabel($this->detector()->detect($this->commercialCaller('administrator', self::GTM, 1), $this->clock())->insights[0]);

        $this->assertSame(1, $ev['No activity ever logged']['value']);
    }

    public function test_crm_opportunities_stale_or_past_closing_with_proxy_label_and_exclusions(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->addCrmOpp(1);
        }
        $this->db()->table('crm_opportunities')->whereIn('id', [1, 2, 3])->update(['updated_at' => '2026-10-01 00:00:00']);
        $this->db()->table('crm_opportunities')->where('id', 4)->update(['closing_date' => '2026-11-01']);
        $this->addCrmOpp(1, ['sales_stage' => 'Closed Won', 'updated_at' => '2026-01-01 00:00:00']);
        $this->addCrmOpp(1, ['sales_stage' => 'Closed Lost', 'closing_date' => '2026-01-01']);
        $this->addCrmOpp(1, ['updated_at' => '2026-01-01 00:00:00', 'deleted_at' => '2026-11-01 00:00:00']);
        $this->addCrmOpp(2, ['updated_at' => '2026-01-01 00:00:00']);

        $result = $this->detector()->detect($this->commercialCaller('administrator', self::CRM), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertCount(1, $result->insights);
        $insight = $result->insights[0];
        $this->assertSame('/module/crm/sales/opportunities', $insight->deepLink);
        $labels = implode('|', array_keys($this->evidenceByLabel($insight)));
        $this->assertStringContainsString('PROXY', $labels);
        $ev = $this->evidenceByLabel($insight);
        $this->assertSame(10, $ev['Open opportunities']['value']);
        $this->assertSame(1, $ev['Closing date already passed']['value']);
        $this->assertStringContainsString('4 CRM opportunities', $insight->title, 'title counts the UNION (3 untouched + 1 past closing), not the larger of the two');
    }

    public function test_crm_union_is_distinct_and_a_null_updated_at_is_unknown_not_untouched_and_not_dropped(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->addCrmOpp(1);
        }
        $this->db()->table('crm_opportunities')->whereIn('id', [1, 2, 3])->update(['updated_at' => '2026-10-01 00:00:00']);   // untouched
        $this->db()->table('crm_opportunities')->whereIn('id', [3, 4])->update(['closing_date' => '2026-11-01']);              // 3 is BOTH untouched and past closing
        $this->db()->table('crm_opportunities')->whereIn('id', [5, 6])->update(['updated_at' => null]);                       // unknown
        $this->db()->table('crm_opportunities')->where('id', 6)->update(['closing_date' => '2026-11-02']);                      // unknown AND past closing

        $result = $this->detector()->detect($this->commercialCaller('administrator', self::CRM), $this->clock());

        $insight = $result->insights[0];
        $ev = $this->evidenceByLabel($insight);
        // distinct union = {1,2,3} untouched + {4} past closing + {6} past closing (unknown updated_at) = 5; opp 5 (unknown only) is NOT in it.
        $this->assertStringContainsString('5 CRM opportunities', $insight->title);
        $this->assertSame(3, $ev[array_values(preg_grep('/^Not updated for/', array_keys($ev)))[0]]['value'], 'a NULL updated_at is not counted as untouched');
        $this->assertSame(2, $ev['Open opportunities with no last-updated date (cannot be assessed for inactivity)']['value']);
        $this->assertSame(10, $result->coverage->expected);
        $this->assertSame(8, $result->coverage->observed, 'assessable for inactivity: 10 open minus the 2 with no date');
    }

    public function test_an_insufficient_side_keeps_its_reason_in_the_combined_coverage_note(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->addCrmOpp(1, ['updated_at' => '2026-01-01 00:00:00']);
        }
        $this->healthyDeals(1, 3);                                               // GTM side: 3 open deals, below the provisional minimum

        $result = $this->detector()->detect($this->commercialCaller('administrator', ['/gtm/deals', '/module/crm/sales/opportunities']), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertCount(1, $result->insights);
        $this->assertStringContainsString('GTM deals', (string) $result->coverage->reason);
        $this->assertStringContainsString('3 record', (string) $result->coverage->reason);
    }

    public function test_the_free_text_next_step_column_is_never_fetched(): void
    {
        $this->healthyDeals(1);
        $this->db()->table('gtm_deals')->where('id', 1)->update(['next_step' => 'call the buyer about the confidential price']);
        $this->db()->enableQueryLog();

        $this->detector()->detect($this->commercialCaller('administrator', self::GTM), $this->clock());

        $selects = array_values(array_filter(array_map(fn ($q) => $q['query'], $this->db()->getQueryLog()), fn ($sql) => stripos($sql, 'from "gtm_deals"') !== false && stripos($sql, 'select') === 0));
        $this->assertNotEmpty($selects);
        $withDeals = array_values(array_filter($selects, fn ($sql) => stripos($sql, 'no_next_step') !== false));
        $this->assertNotEmpty($withDeals, 'next_step emptiness is computed in SQL');
        foreach ($selects as $sql) {
            // Take away the CASE expression that tests emptiness; no bare `next_step` column may remain in any select list.
            $list = preg_replace('/case when.*?end as "?no_next_step"?/is', '', substr($sql, 0, stripos($sql, ' from ')));
            $this->assertSame(0, preg_match('/\bnext_step\b/i', $list), 'free text must not be selected: ' . $list);
        }
    }

    public function test_insight_prose_states_observations_not_predictions(): void
    {
        $this->healthyDeals(1);
        $this->db()->table('gtm_deals')->whereIn('id', [1, 2])->update(['next_step' => '']);
        for ($i = 0; $i < 10; $i++) {
            $this->addCrmOpp(1, ['updated_at' => '2026-01-01 00:00:00']);
        }

        $result = $this->detector()->detect($this->commercialCaller('administrator', ['/gtm/deals', '/module/crm/sales/opportunities']), $this->clock());
        $text = strtolower(json_encode(array_map(fn ($i) => $i->toArray(), $result->insights)));

        foreach (['likely', 'probably', 'predict', 'tend to', 'will '] as $word) {
            $this->assertStringNotContainsString($word, $text);
        }
    }

    public function test_authorization_requires_a_deal_screen_right_and_roles_alone_grant_nothing(): void
    {
        $this->assertFalse($this->detector()->authorizes($this->commercialCaller('administrator')));
        $this->assertFalse($this->detector()->authorizes($this->commercialCaller('executive', ['/gtm/forecast'])));
        $this->assertTrue($this->detector()->authorizes($this->commercialCaller('employee', self::GTM)));
        $this->assertTrue($this->detector()->authorizes($this->commercialCaller('employee', ['/gtm/command-center'])));
        $this->assertTrue($this->detector()->authorizes($this->commercialCaller('employee', self::CRM)));
    }

    public function test_a_gtm_only_profile_does_not_see_crm_insights_and_vice_versa(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->addCrmOpp(1, ['updated_at' => '2026-01-01 00:00:00']);
        }
        $gtmOnly = $this->detector()->detect($this->commercialCaller('administrator', self::GTM), $this->clock());
        $crmOnly = $this->detector()->detect($this->commercialCaller('administrator', self::CRM), $this->clock());

        $this->assertSame([], $gtmOnly->insights);
        $this->assertCount(1, $crmOnly->insights);
    }
}
