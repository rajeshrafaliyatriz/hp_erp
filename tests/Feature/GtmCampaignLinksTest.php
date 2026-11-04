<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCampaignsWorld;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/**
 * The GTM campaign and its two new pointers (slice 9): the Product Master row it sells (`product_master_id`) and the CRM campaign it is the same campaign as
 * (`crm_campaign_id`, a bridge, never a merge). Both are optional, both are checked against the caller's organisation, and a CRM campaign has at most one GTM
 * campaign because the CRM campaign screen shows one.
 */
class GtmCampaignLinksTest extends TestCase
{
    use BuildsCampaignsWorld;
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCampaignsWorld();
        $this->buildSignalsWorld();                       // for signalsAdmin()'s profile and for a realistic tenant
        $this->h = $this->gtmAdmin();
    }

    /** @param array<string, mixed> $over */
    private function body(array $over = []): array
    {
        return $over + ['name' => 'ABM push', 'kind' => 'abm'];
    }

    // ── the presenter ─────────────────────────────────────────────────────────

    public function test_the_detail_keeps_every_old_key_and_adds_the_product_and_the_crm_campaign_by_name(): void
    {
        $product = $this->makeProductRow(self::T, 'ScholarClone', ['product_key' => 'scholarclone']);
        $crm = $this->makeCampaign(self::T, ['name' => 'Diwali outreach', 'campaign_status' => 'Active']);
        $id = $this->makeGtmCampaign(self::T, ['product_master_id' => $product, 'crm_campaign_id' => $crm]);

        $data = $this->getJson("/api/gtm/campaigns/{$id}", $this->h)->assertOk()->json('data');

        $this->assertSame(['campaign', 'members', 'metrics', 'industry_pack', 'transitions', 'product', 'crm_campaign'], array_keys($data), 'five old keys, then the two new ones');
        $this->assertSame(['id' => $product, 'key' => 'scholarclone', 'name' => 'ScholarClone'], $data['product']);
        $this->assertSame(['id' => $crm, 'name' => 'Diwali outreach', 'status' => 'Active'], $data['crm_campaign']);
        $this->assertSame($product, (int) $data['campaign']['product_master_id']);
        $this->assertSame($crm, (int) $data['campaign']['crm_campaign_id']);
    }

    public function test_pointers_that_are_not_this_organisations_to_show_show_nothing(): void
    {
        $theirsProduct = $this->makeProductRow(self::T2, 'Their Secret Product');
        $theirsCrm = $this->makeCampaign(self::T2, ['name' => 'Their Secret Campaign']);
        $deletedCrm = $this->makeCampaign(self::T, ['name' => 'Binned Campaign', 'deleted_at' => now()]);
        $planted = $this->makeGtmCampaign(self::T, ['product_master_id' => $theirsProduct, 'crm_campaign_id' => $theirsCrm]);
        $binned = $this->makeGtmCampaign(self::T, ['crm_campaign_id' => $deletedCrm]);

        $a = $this->getJson("/api/gtm/campaigns/{$planted}", $this->h)->assertOk()->json('data');
        $b = $this->getJson("/api/gtm/campaigns/{$binned}", $this->h)->assertOk()->json('data');

        $this->assertNull($a['product']);
        $this->assertNull($a['crm_campaign']);
        $this->assertNull($b['crm_campaign']);
        $this->assertStringNotContainsString('Secret', json_encode([$a['product'], $a['crm_campaign']]));
    }

    // ── the writes ────────────────────────────────────────────────────────────

    public function test_a_gtm_campaign_can_be_made_with_a_product_and_a_crm_campaign_of_its_own(): void
    {
        $product = $this->makeProductRow(0, 'Shared Product');                       // shared rows are visible to every organisation
        $crm = $this->makeCampaign(self::T);

        $campaign = $this->postJson('/api/gtm/campaigns', $this->body(['product_master_id' => $product, 'crm_campaign_id' => $crm]), $this->h)->assertCreated()->json('data.campaign');

        $this->assertSame($product, (int) $campaign['product_master_id']);
        $this->assertSame($crm, (int) $campaign['crm_campaign_id']);
    }

    public function test_a_product_or_a_crm_campaign_of_another_organisation_is_422_on_store_and_update_and_nothing_is_written(): void
    {
        $theirsProduct = $this->makeProductRow(self::T2, 'Their Product');
        $theirsCrm = $this->makeCampaign(self::T2);
        $mine = $this->makeGtmCampaign(self::T, ['name' => 'Untouched']);
        $before = DB::table('gtm_campaigns')->count();

        $a = $this->postJson('/api/gtm/campaigns', $this->body(['product_master_id' => $theirsProduct]), $this->h);
        $b = $this->postJson('/api/gtm/campaigns', $this->body(['crm_campaign_id' => $theirsCrm]), $this->h);
        $c = $this->patchJson("/api/gtm/campaigns/{$mine}", ['name' => 'Renamed', 'product_master_id' => $theirsProduct], $this->h);
        $d = $this->patchJson("/api/gtm/campaigns/{$mine}", ['name' => 'Renamed', 'crm_campaign_id' => $theirsCrm], $this->h);
        $missing = $this->postJson('/api/gtm/campaigns', $this->body(['crm_campaign_id' => 9_999_999]), $this->h);

        foreach ([$a, $c] as $response) {
            $response->assertStatus(422)->assertJsonPath('message', 'Product not found.');
        }
        foreach ([$b, $d] as $response) {
            $response->assertStatus(422)->assertJsonPath('message', 'CRM campaign not found.');
        }
        $this->assertSame($b->json('message'), $missing->assertStatus(422)->json('message'), 'another organisation\'s campaign and a missing one are answered alike');
        $this->assertSame($before, DB::table('gtm_campaigns')->count(), 'no campaign was made');
        $row = DB::table('gtm_campaigns')->where('id', $mine)->first();
        $this->assertSame('Untouched', $row->name);
        $this->assertNull($row->product_master_id);
        $this->assertNull($row->crm_campaign_id);
    }

    public function test_a_crm_campaign_in_the_recycle_bin_cannot_be_linked(): void
    {
        $binned = $this->makeCampaign(self::T, ['deleted_at' => now()]);

        $this->postJson('/api/gtm/campaigns', $this->body(['crm_campaign_id' => $binned]), $this->h)->assertStatus(422)->assertJsonPath('message', 'CRM campaign not found.');
    }

    public function test_a_crm_campaign_has_at_most_one_live_gtm_campaign(): void
    {
        $crm = $this->makeCampaign(self::T);
        $first = $this->makeGtmCampaign(self::T, ['crm_campaign_id' => $crm]);
        $second = $this->makeGtmCampaign(self::T);

        $this->postJson('/api/gtm/campaigns', $this->body(['crm_campaign_id' => $crm]), $this->h)->assertStatus(422)->assertJsonPath('message', 'That CRM campaign is already linked to another GTM campaign.');
        $this->patchJson("/api/gtm/campaigns/{$second}", ['crm_campaign_id' => $crm], $this->h)->assertStatus(422);
        $this->assertNull(DB::table('gtm_campaigns')->where('id', $second)->value('crm_campaign_id'));

        // The campaign that already holds the link may send it again (an edit of another field with the form's full body), and once it is in the Recycle Bin the link is free.
        $this->patchJson("/api/gtm/campaigns/{$first}", ['name' => 'Same, renamed', 'crm_campaign_id' => $crm], $this->h)->assertOk();
        DB::table('gtm_campaigns')->where('id', $first)->update(['deleted_at' => now()]);
        $this->patchJson("/api/gtm/campaigns/{$second}", ['crm_campaign_id' => $crm], $this->h)->assertOk();
        $this->assertSame($crm, (int) DB::table('gtm_campaigns')->where('id', $second)->value('crm_campaign_id'));
    }

    public function test_another_organisations_gtm_link_does_not_block_this_organisations_link(): void
    {
        $crm = $this->makeCampaign(self::T);
        $this->makeGtmCampaign(self::T2, ['crm_campaign_id' => $crm]);                // stray: another organisation's GTM campaign pointing at my CRM campaign id

        $this->postJson('/api/gtm/campaigns', $this->body(['crm_campaign_id' => $crm]), $this->h)->assertCreated();
    }

    public function test_a_link_can_be_cleared_and_an_edit_that_does_not_name_one_leaves_it_alone(): void
    {
        $crm = $this->makeCampaign(self::T);
        $product = $this->makeProductRow(self::T, 'P');
        $id = $this->makeGtmCampaign(self::T, ['crm_campaign_id' => $crm, 'product_master_id' => $product]);

        $this->patchJson("/api/gtm/campaigns/{$id}", ['name' => 'Renamed'], $this->h)->assertOk();
        $row = DB::table('gtm_campaigns')->where('id', $id)->first();
        $this->assertSame([$crm, $product], [(int) $row->crm_campaign_id, (int) $row->product_master_id]);

        $this->patchJson("/api/gtm/campaigns/{$id}", ['crm_campaign_id' => null, 'product_master_id' => null], $this->h)->assertOk();
        $row = DB::table('gtm_campaigns')->where('id', $id)->first();
        $this->assertSame([null, null], [$row->crm_campaign_id, $row->product_master_id]);
    }

    public function test_a_malformed_value_is_422(): void
    {
        foreach (['abc', 0, -1, 1.5] as $bad) {
            $this->postJson('/api/gtm/campaigns', $this->body(['product_master_id' => $bad]), $this->h)->assertStatus(422);
            $this->postJson('/api/gtm/campaigns', $this->body(['crm_campaign_id' => $bad]), $this->h)->assertStatus(422);
        }
        $this->assertSame(0, DB::table('gtm_campaigns')->count());
    }

    public function test_another_organisations_gtm_campaign_is_a_404_on_every_route_touched(): void
    {
        $theirs = $this->makeGtmCampaign(self::T2, ['name' => 'Their Secret GTM Campaign']);
        $crm = $this->makeCampaign(self::T);

        $show = $this->getJson("/api/gtm/campaigns/{$theirs}", $this->h)->assertNotFound();
        $this->patchJson("/api/gtm/campaigns/{$theirs}", ['crm_campaign_id' => $crm], $this->h)->assertNotFound();

        $this->assertStringNotContainsString('Secret', $show->getContent());
        $this->assertNull(DB::table('gtm_campaigns')->where('id', $theirs)->value('crm_campaign_id'));
    }
}
