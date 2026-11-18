<?php

namespace Tests\Feature;

use App\Domain\Campaigns\CampaignFunnel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsCampaignsWorld;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/**
 * The funnel of a campaign (slice 9): counted from the touchpoint timestamps its targets keep, through the campaign's OWN organisation. `crm_campaign_targets` has
 * no organisation column, so the campaign row is what ties a target to an organisation: a test with a stray target (one under another organisation's campaign,
 * pointing at this organisation's records) is mandatory, and so is a hand count over fixture targets.
 */
class CampaignFunnelTest extends TestCase
{
    use BuildsCampaignsWorld;
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    private array $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCampaignsWorld();
        $this->user = $this->campaignsUser();
    }

    private function stamp(string ...$columns): array
    {
        return array_fill_keys($columns, '2026-11-01 10:00:00');
    }

    // ── the hand count ────────────────────────────────────────────────────────

    public function test_the_funnel_equals_a_hand_count_over_fixture_targets(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeTarget($campaign, 'lead', 1, $this->stamp('sent_at', 'opened_at', 'replied_at'));                                                     // three stages
        $this->makeTarget($campaign, 'lead', 2, $this->stamp('sent_at'));                                                                                   // one stage
        $this->makeTarget($campaign, 'contact', 3);                                                                                                          // none at all
        $this->makeTarget($campaign, 'organization', 4, $this->stamp('sent_at', 'opted_out_at'));                                                          // opted out
        $this->makeTarget($campaign, 'lead', 5, $this->stamp('sent_at', 'opened_at', 'replied_at', 'meeting_booked_at', 'converted_at'));                 // the whole way

        $expected = ['targets' => 5, 'sent' => 4, 'opened' => 2, 'replied' => 2, 'meetings' => 1, 'converted' => 1, 'opted_out' => 1];

        $this->assertSame($expected, CampaignFunnel::for(self::T, $campaign));
        $this->assertSame($expected, $this->getJson("/api/crm/campaigns/{$campaign}", $this->user['h'])->assertOk()->json('data.funnel'));
        $this->assertSame(['targets', 'sent', 'opened', 'replied', 'meetings', 'converted', 'opted_out'], array_keys($expected), 'the contract order');
    }

    public function test_one_campaign_never_counts_the_targets_of_another_campaign_of_the_same_organisation(): void
    {
        $a = $this->makeCampaign();
        $b = $this->makeCampaign();
        $this->makeTarget($a, 'lead', 1, $this->stamp('sent_at'));
        foreach ([10, 11, 12] as $id) {
            $this->makeTarget($b, 'lead', $id, $this->stamp('sent_at', 'opened_at', 'replied_at', 'meeting_booked_at', 'converted_at', 'opted_out_at'));
        }

        $this->assertSame(['targets' => 1, 'sent' => 1, 'opened' => 0, 'replied' => 0, 'meetings' => 0, 'converted' => 0, 'opted_out' => 0], CampaignFunnel::for(self::T, $a));
        $this->assertSame(['targets' => 3, 'sent' => 3, 'opened' => 3, 'replied' => 3, 'meetings' => 3, 'converted' => 3, 'opted_out' => 3], CampaignFunnel::for(self::T, $b));
    }

    public function test_a_campaign_with_no_targets_has_a_funnel_of_zeros_not_null(): void
    {
        $campaign = $this->makeCampaign();

        $this->assertSame(['targets' => 0, 'sent' => 0, 'opened' => 0, 'replied' => 0, 'meetings' => 0, 'converted' => 0, 'opted_out' => 0], CampaignFunnel::for(self::T, $campaign));
    }

    public function test_a_target_counts_whatever_has_since_happened_to_the_person_it_points_at(): void
    {
        $campaign = $this->makeCampaign();
        $lead = $this->makeLead(self::T, ['email' => 'gone@school.example']);
        $this->makeTarget($campaign, 'lead', $lead, $this->stamp('sent_at'));
        DB::table('crm_leads')->where('id', $lead)->update(['deleted_at' => now()]);

        $this->assertSame(1, CampaignFunnel::for(self::T, $campaign)['sent'], 'a message that was sent was sent: the funnel counts what was recorded');
    }

    // ── the organisation join (the mandatory stray-target test) ──────────────

    public function test_a_stray_target_under_another_organisations_campaign_is_never_counted_and_never_read(): void
    {
        $mine = $this->makeCampaign(self::T);
        $theirs = $this->makeCampaign(self::T2);
        $myLead = $this->makeLead(self::T);
        $this->makeTarget($mine, 'lead', $myLead, $this->stamp('sent_at'));
        // Stray: under the OTHER organisation's campaign, but pointing at THIS organisation's lead, and with every stage recorded.
        $this->makeTarget($theirs, 'lead', $myLead, $this->stamp('sent_at', 'opened_at', 'replied_at', 'meeting_booked_at', 'converted_at', 'opted_out_at'));

        $this->assertSame(1, CampaignFunnel::for(self::T, $mine)['targets'], 'my funnel holds my campaign\'s one target');
        $this->assertNull(CampaignFunnel::for(self::T, $theirs), 'the other organisation\'s campaign id is not a campaign of mine: no funnel, no counts');
        $this->assertNull(CampaignFunnel::for(self::T2, $mine), 'and the other way round');
        $this->assertSame(['targets' => 1, 'sent' => 1, 'opened' => 1, 'replied' => 1, 'meetings' => 1, 'converted' => 1, 'opted_out' => 1], CampaignFunnel::for(self::T2, $theirs));
    }

    public function test_another_organisations_campaign_is_a_404_on_the_detail_route(): void
    {
        $theirs = $this->makeCampaign(self::T2, ['name' => 'Their Secret Campaign']);
        $this->makeTarget($theirs, 'lead', 99, $this->stamp('sent_at'));

        $response = $this->getJson("/api/crm/campaigns/{$theirs}", $this->user['h']);

        $response->assertNotFound();
        $this->assertStringNotContainsString('Secret', $response->getContent());
        $this->assertArrayNotHasKey('funnel', $response->json() ?? []);
    }

    public function test_a_campaign_in_the_recycle_bin_has_no_funnel(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeTarget($campaign, 'lead', 1, $this->stamp('sent_at'));
        DB::table('crm_campaigns')->where('id', $campaign)->update(['deleted_at' => now()]);

        $this->assertNull(CampaignFunnel::for(self::T, $campaign));
        $this->getJson("/api/crm/campaigns/{$campaign}", $this->user['h'])->assertNotFound();
    }

    public function test_an_organisation_of_zero_or_a_campaign_of_zero_is_null(): void
    {
        $campaign = $this->makeCampaign(self::T);

        $this->assertNull(CampaignFunnel::for(0, $campaign));
        $this->assertNull(CampaignFunnel::for(self::T, 0));
        $this->assertNull(CampaignFunnel::for(self::T, 999_999));
    }

    // ── a host that has not run the touchpoint migration ─────────────────────

    public function test_a_stage_column_this_host_does_not_have_counts_as_zero_not_an_error(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeTarget($campaign, 'lead', 1, $this->stamp('sent_at', 'opened_at', 'replied_at'));
        Schema::table('crm_campaign_targets', fn ($t) => $t->dropColumn('opened_at'));

        $funnel = CampaignFunnel::for(self::T, $campaign);

        $this->assertSame(['targets' => 1, 'sent' => 1, 'opened' => 0, 'replied' => 1, 'meetings' => 0, 'converted' => 0, 'opted_out' => 0], $funnel);
    }
}
