<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCampaignsWorld;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/**
 * What the CRM campaign screens read and write (slice 9, the CONTRACT): every campaign gains `product` ({id, key, name} or null); the detail also gains `funnel`,
 * `gtm_campaign` and `leads`; the store, update and import validators accept `product_master_id`. Nothing that was there changes.
 */
class CampaignApiContractTest extends TestCase
{
    use BuildsCampaignsWorld;
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    /** The keys a campaign had before this slice: they must all still be there, with the same names. */
    private const LEGACY_KEYS = [
        'id', 'campaignNo', 'name', 'campaignType', 'campaignStatus', 'expectedRevenue', 'budgetCost', 'actualCost', 'expectedResponse', 'numSent', 'sponsor',
        'targetAudience', 'targetSize', 'expectedResponseCount', 'expectedSalesCount', 'actualResponseCount', 'actualSalesCount', 'expectedRoi', 'actualRoi',
        'closingDate', 'productId', 'description', 'assignedTo', 'createdBy', 'createdAt', 'updatedAt',
    ];

    private array $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCampaignsWorld();
        $this->user = $this->campaignsUser();
    }

    /** @param array<string, mixed> $over */
    private function body(array $over = []): array
    {
        return $over + ['name' => 'Diwali outreach', 'assignedTo' => $this->user['id']];
    }

    // ── the presenter ─────────────────────────────────────────────────────────

    public function test_every_old_key_is_still_there_and_the_new_one_is_null_for_a_campaign_with_no_product(): void
    {
        $id = $this->makeCampaign(self::T, ['name' => 'Kept', 'budget_cost' => '1500.50', 'product_id' => 77, 'num_sent' => 4, 'assigned_to' => $this->user['id']]);

        $item = $this->getJson('/api/crm/campaigns', $this->user['h'])->assertOk()->json('data.items.0');
        $detail = $this->getJson("/api/crm/campaigns/{$id}", $this->user['h'])->assertOk()->json('data');

        foreach ([$item, $detail] as $campaign) {
            $this->assertSame([], array_values(array_diff(self::LEGACY_KEYS, array_keys($campaign))), 'no key that was there is gone or renamed');
            $this->assertSame('Kept', $campaign['name']);
            $this->assertSame(1500.5, $campaign['budgetCost']);
            $this->assertSame('77', $campaign['productId'], 'the old loose pointer is untouched and still a string');
            $this->assertSame(4, $campaign['numSent']);
            $this->assertNull($campaign['product']);
        }
        $this->assertSame(['product'], array_slice(array_keys($item), count(self::LEGACY_KEYS)), 'the list adds exactly this one');
        $this->assertSame(['product', 'funnel', 'gtm_campaign', 'leads'], array_slice(array_keys($detail), count(self::LEGACY_KEYS)), 'the detail adds exactly these three more');
        $this->assertSame(['count' => 0], $detail['leads']);
        $this->assertNull($detail['gtm_campaign']);
        $this->assertSame(0, $detail['funnel']['targets']);
    }

    public function test_a_campaign_shows_its_product_whether_the_product_is_its_own_or_shared_and_even_when_archived(): void
    {
        $own = $this->makeProductRow(self::T, 'ScholarClone', ['product_key' => 'scholarclone']);
        $shared = $this->makeProductRow(0, 'Enterprise Brain', ['product_key' => 'eb']);
        $archived = $this->makeProductRow(self::T, 'Old Product', ['product_key' => 'old', 'status' => 'archived']);
        $a = $this->makeCampaign(self::T, ['name' => 'A', 'product_master_id' => $own]);
        $b = $this->makeCampaign(self::T, ['name' => 'B', 'product_master_id' => $shared]);
        $c = $this->makeCampaign(self::T, ['name' => 'C', 'product_master_id' => $archived]);

        $items = collect($this->getJson('/api/crm/campaigns?sort_by=name', $this->user['h'])->assertOk()->json('data.items'))->keyBy('name');

        $this->assertSame(['id' => $own, 'key' => 'scholarclone', 'name' => 'ScholarClone'], $items['A']['product']);
        $this->assertSame(['id' => $shared, 'key' => 'eb', 'name' => 'Enterprise Brain'], $items['B']['product']);
        $this->assertSame(['id' => $archived, 'key' => 'old', 'name' => 'Old Product'], $items['C']['product']);
        $this->assertSame($own, $this->getJson("/api/crm/campaigns/{$a}", $this->user['h'])->json('data.product.id'));
        $this->assertSame($shared, $this->getJson("/api/crm/campaigns/{$b}", $this->user['h'])->json('data.product.id'));
        $this->assertSame($archived, $this->getJson("/api/crm/campaigns/{$c}", $this->user['h'])->json('data.product.id'));
    }

    public function test_another_organisations_product_planted_in_the_column_shows_nothing(): void
    {
        $theirs = $this->makeProductRow(self::T2, 'Their Secret Product');
        $id = $this->makeCampaign(self::T, ['product_master_id' => $theirs]);

        $detail = $this->getJson("/api/crm/campaigns/{$id}", $this->user['h'])->assertOk()->json('data');

        $this->assertNull($detail['product']);
        $this->assertStringNotContainsString('Secret', json_encode($detail));
    }

    public function test_a_list_reads_the_products_of_its_page_with_one_query(): void
    {
        foreach (range(1, 5) as $i) {
            $this->makeCampaign(self::T, ['name' => "C{$i}", 'product_master_id' => $this->makeProductRow(self::T, "P{$i}")]);
        }

        DB::enableQueryLog();
        $this->getJson('/api/crm/campaigns', $this->user['h'])->assertOk();
        $productQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "g2g_product_master"'))->count();
        DB::disableQueryLog();

        $this->assertSame(1, $productQueries, 'five campaigns with five products are named with one query, not five');
    }

    // ── the detail ────────────────────────────────────────────────────────────

    public function test_the_detail_shows_the_gtm_campaign_that_is_the_same_campaign_and_only_this_organisations_live_one(): void
    {
        $id = $this->makeCampaign();
        $other = $this->makeCampaign();
        $onlyTheirs = $this->makeCampaign();
        // Stray rows that point at these campaign ids and must never show. They are made FIRST so that, if the organisation were not part of the lookup, they would
        // be the oldest match and win: another organisation's GTM campaign (twice, once beside mine and once alone), and one of mine in the Recycle Bin.
        $this->makeGtmCampaign(self::T2, ['name' => 'Their GTM campaign', 'crm_campaign_id' => $id]);
        $this->makeGtmCampaign(self::T2, ['name' => 'Their lonely GTM campaign', 'crm_campaign_id' => $onlyTheirs]);
        $this->makeGtmCampaign(self::T, ['name' => 'Deleted GTM campaign', 'crm_campaign_id' => $other, 'deleted_at' => now()]);
        $mine = $this->makeGtmCampaign(self::T, ['name' => 'Same campaign, GTM side', 'status' => 'active', 'crm_campaign_id' => $id]);

        $detail = $this->getJson("/api/crm/campaigns/{$id}", $this->user['h'])->assertOk()->json('data');
        $none = $this->getJson("/api/crm/campaigns/{$other}", $this->user['h'])->assertOk()->json('data');
        $theirsOnly = $this->getJson("/api/crm/campaigns/{$onlyTheirs}", $this->user['h'])->assertOk()->json('data');

        $this->assertSame(['id' => $mine, 'name' => 'Same campaign, GTM side', 'status' => 'active'], $detail['gtm_campaign']);
        $this->assertNull($none['gtm_campaign'], 'a deleted GTM campaign is not shown');
        $this->assertNull($theirsOnly['gtm_campaign'], 'another organisation\'s GTM campaign is not shown, even when it is the only one');
        $this->assertStringNotContainsString('Their', json_encode([$detail, $theirsOnly]));
    }

    public function test_the_detail_counts_the_leads_that_first_came_from_this_campaign_in_this_organisation_only(): void
    {
        $id = $this->makeCampaign();
        $this->makeLead(self::T, ['campaign_id' => $id]);
        $this->makeLead(self::T, ['campaign_id' => $id, 'converted' => true]);                       // a converted lead is still attributed
        $this->makeLead(self::T, ['campaign_id' => $id, 'deleted_at' => now()]);                      // one in the Recycle Bin is not
        $this->makeLead(self::T, ['campaign_id' => $this->makeCampaign()]);                          // another campaign of mine
        $this->makeLead(self::T2, ['campaign_id' => $id]);                                           // stray: another organisation's lead pointing at my campaign id
        $this->makeLead(self::T);                                                                    // none

        $detail = $this->getJson("/api/crm/campaigns/{$id}", $this->user['h'])->assertOk()->json('data');

        $this->assertSame(['count' => 2], $detail['leads']);
    }

    // ── the writes ────────────────────────────────────────────────────────────

    public function test_a_campaign_can_be_made_and_changed_with_a_product_of_its_own_or_a_shared_one(): void
    {
        $own = $this->makeProductRow(self::T, 'ScholarClone');
        $shared = $this->makeProductRow(0, 'Enterprise Brain');

        $created = $this->postJson('/api/crm/campaigns', $this->body(['product_master_id' => $own]), $this->user['h'])->assertCreated()->json('data');
        $this->assertSame($own, $created['product']['id']);
        $this->assertSame($own, (int) DB::table('crm_campaigns')->where('id', $created['id'])->value('product_master_id'));

        $this->putJson("/api/crm/campaigns/{$created['id']}", ['product_master_id' => $shared], $this->user['h'])->assertOk()->assertJsonPath('data.product.id', $shared);
        $this->assertSame($shared, (int) DB::table('crm_campaigns')->where('id', $created['id'])->value('product_master_id'));
    }

    public function test_the_camel_case_spelling_is_accepted_and_the_snake_case_one_wins_if_both_are_sent(): void
    {
        $a = $this->makeProductRow(self::T, 'A');
        $b = $this->makeProductRow(self::T, 'B');

        $camel = $this->postJson('/api/crm/campaigns', $this->body(['productMasterId' => $a]), $this->user['h'])->assertCreated()->json('data');
        $both = $this->postJson('/api/crm/campaigns', $this->body(['product_master_id' => $b, 'productMasterId' => $a]), $this->user['h'])->assertCreated()->json('data');

        $this->assertSame($a, $camel['product']['id']);
        $this->assertSame($b, $both['product']['id']);
    }

    public function test_a_product_of_another_organisation_is_422_on_store_update_and_import_and_nothing_is_written(): void
    {
        $theirs = $this->makeProductRow(self::T2, 'Their Product');
        $mine = $this->makeProductRow(self::T, 'My Product');
        $campaign = $this->makeCampaign(self::T, ['product_master_id' => $mine, 'name' => 'Untouched']);
        $before = DB::table('crm_campaigns')->count();

        $store = $this->postJson('/api/crm/campaigns', $this->body(['product_master_id' => $theirs]), $this->user['h']);
        $update = $this->putJson("/api/crm/campaigns/{$campaign}", ['name' => 'Renamed', 'product_master_id' => $theirs], $this->user['h']);
        $missing = $this->postJson('/api/crm/campaigns', $this->body(['product_master_id' => 9_999_999]), $this->user['h']);
        $import = $this->postJson('/api/crm/campaigns/import', ['rows' => [$this->body(['name' => 'Good', 'product_master_id' => $mine]), $this->body(['name' => 'Bad', 'product_master_id' => $theirs])]], $this->user['h'])->assertOk();

        $store->assertStatus(422)->assertJsonPath('code', 'product_not_found');
        $update->assertStatus(422)->assertJsonPath('code', 'product_not_found');
        $this->assertSame($store->json('message'), $missing->assertStatus(422)->json('message'), 'a product of another organisation and one that does not exist are answered alike');
        $this->assertSame(2, $import->json('data.created') + 1, 'one row in, one row rejected');
        $this->assertSame([true, false], array_column($import->json('data.results'), 'ok'));
        $this->assertSame('Product not found.', $import->json('data.results.1.reason'));
        $this->assertSame($before + 1, DB::table('crm_campaigns')->count(), 'only the good import row was made');
        $row = DB::table('crm_campaigns')->where('id', $campaign)->first();
        $this->assertSame('Untouched', $row->name, 'a refused update changes nothing, not even the name sent beside it');
        $this->assertSame($mine, (int) $row->product_master_id);
    }

    public function test_a_malformed_product_is_422_and_an_empty_one_clears_it(): void
    {
        $mine = $this->makeProductRow(self::T, 'My Product');
        $campaign = $this->makeCampaign(self::T, ['product_master_id' => $mine]);

        foreach (['abc', 0, -3, 1.5, [1], true] as $bad) {
            $this->putJson("/api/crm/campaigns/{$campaign}", ['product_master_id' => $bad], $this->user['h'])->assertStatus(422)->assertJsonPath('code', 'product_invalid');
        }
        $this->assertSame($mine, (int) DB::table('crm_campaigns')->where('id', $campaign)->value('product_master_id'));

        $this->putJson("/api/crm/campaigns/{$campaign}", ['product_master_id' => null], $this->user['h'])->assertOk()->assertJsonPath('data.product', null);
        $this->assertNull(DB::table('crm_campaigns')->where('id', $campaign)->value('product_master_id'));
    }

    public function test_an_update_that_does_not_name_a_product_leaves_it_alone(): void
    {
        $mine = $this->makeProductRow(self::T, 'My Product');
        $campaign = $this->makeCampaign(self::T, ['product_master_id' => $mine]);

        $this->putJson("/api/crm/campaigns/{$campaign}", ['name' => 'New name'], $this->user['h'])->assertOk()->assertJsonPath('data.product.id', $mine);

        $this->assertSame($mine, (int) DB::table('crm_campaigns')->where('id', $campaign)->value('product_master_id'));
    }

    public function test_the_old_product_id_pointer_is_untouched_by_the_new_one(): void
    {
        $mine = $this->makeProductRow(self::T, 'My Product');

        $created = $this->postJson('/api/crm/campaigns', $this->body(['productId' => 42, 'product_master_id' => $mine]), $this->user['h'])->assertCreated()->json('data');

        $this->assertSame('42', $created['productId']);
        $this->assertSame($mine, $created['product']['id']);
    }

    // ── another organisation's campaign ───────────────────────────────────────

    public function test_another_organisations_campaign_is_a_404_on_every_route_touched_and_never_in_the_list(): void
    {
        $theirs = $this->makeCampaign(self::T2, ['name' => 'Their Secret Campaign']);
        $mine = $this->makeProductRow(self::T, 'My Product');

        $this->getJson("/api/crm/campaigns/{$theirs}", $this->user['h'])->assertNotFound();
        $this->putJson("/api/crm/campaigns/{$theirs}", ['product_master_id' => $mine], $this->user['h'])->assertNotFound();
        $list = $this->getJson('/api/crm/campaigns', $this->user['h'])->assertOk();

        $this->assertStringNotContainsString('Secret', $list->getContent());
        $this->assertNull(DB::table('crm_campaigns')->where('id', $theirs)->value('product_master_id'), 'the refused update wrote nothing to the other organisation\'s row');
    }
}
