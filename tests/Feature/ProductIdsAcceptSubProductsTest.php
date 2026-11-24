<?php

namespace Tests\Feature;

use App\Domain\Campaigns\CampaignLinks;
use App\Domain\Crm\Leads\LeadLinks;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCampaignsWorld;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/**
 * The lead, campaign and GTM campaign forms store the MASTER id of what the person chose, and since the S15 follow-up (24 Nov 2026, D-7; contract section 4.4) that may be a
 * Sub-product or an item as well as a Product: "all products and sub products should be selectable, not sold and sold both". The validators were never restricted to
 * `kind = product` (they all ask LeadLinks::productVisible, which accepts any master row of the ORGANISATION); this file pins that, so a later tightening of the validators to
 * Products only is a red test, and pins that another organisation's Sub-product is "not found" the same way a missing one is. The opportunity form has its own file
 * (ProductIdsAcceptSubProductsOpportunityTest).
 */
class ProductIdsAcceptSubProductsTest extends TestCase
{
    use BuildsCampaignsWorld;
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    private array $user;

    private array $gtm;

    /** @var array<string, int> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCampaignsWorld();
        $this->buildSignalsWorld();
        $this->user = $this->campaignsUser();
        $this->gtm = $this->gtmAdmin();
        $this->p['product'] = $this->makeProductRow(self::T, 'Payroll Cloud');
        $this->p['sub'] = $this->makeProductRow(self::T, 'Payroll one', ['kind' => 'sub_product', 'parent_id' => $this->p['product']]);
        $this->p['item'] = $this->makeProductRow(self::T, 'Starter kit', ['kind' => 'item', 'parent_id' => $this->p['product']]);
        $this->p['archived'] = $this->makeProductRow(self::T, 'Old one', ['kind' => 'sub_product', 'parent_id' => $this->p['product'], 'status' => 'archived']);
        $this->p['theirProduct'] = $this->makeProductRow(self::T2, 'Their Product');
        $this->p['theirSub'] = $this->makeProductRow(self::T2, 'Their sub', ['kind' => 'sub_product', 'parent_id' => $this->p['theirProduct']]);
    }

    // ================================================================== the one rule they all ask

    public function test_the_rule_every_form_asks_accepts_a_product_a_sub_product_an_item_and_an_archived_one_of_the_organisation_and_nothing_of_another(): void
    {
        foreach (['product', 'sub', 'item', 'archived'] as $key) {
            $this->assertTrue(LeadLinks::productVisible(self::T, $this->p[$key]), "{$key} of the organisation");
            $this->assertTrue(CampaignLinks::productVisible(self::T, $this->p[$key]), "{$key} of the organisation, for a campaign");
        }
        foreach (['theirProduct', 'theirSub'] as $key) {
            $this->assertFalse(LeadLinks::productVisible(self::T, $this->p[$key]), $key);
            $this->assertFalse(CampaignLinks::productVisible(self::T, $this->p[$key]), $key);
        }
        $this->assertFalse(LeadLinks::productVisible(self::T, 987654));
        $this->assertFalse(LeadLinks::productVisible(0, $this->p['sub']), 'a caller with no organisation reads no Sub-product');
    }

    // ================================================================== the lead form

    public function test_a_lead_is_made_and_changed_with_a_sub_product_or_an_item_and_stores_the_master_id_it_was_given(): void
    {
        $sub = $this->postJson('/api/crm/leads', ['lastName' => 'OnSub', 'assignedTo' => $this->user['id'], 'productMasterId' => $this->p['sub']], $this->user['h'])->assertCreated();
        $item = $this->postJson('/api/crm/leads', ['lastName' => 'OnItem', 'assignedTo' => $this->user['id'], 'product_master_id' => $this->p['item']], $this->user['h'])->assertCreated();

        $this->assertSame($this->p['sub'], (int) DB::table('crm_leads')->where('id', $sub->json('data.id'))->value('product_master_id'));
        $this->assertSame($this->p['sub'], (int) $sub->json('data.product.id'));
        $this->assertSame($this->p['item'], (int) DB::table('crm_leads')->where('id', $item->json('data.id'))->value('product_master_id'));

        $this->putJson('/api/crm/leads/' . $item->json('data.id'), ['productMasterId' => $this->p['sub']], $this->user['h'])->assertOk();
        $this->assertSame($this->p['sub'], (int) DB::table('crm_leads')->where('id', $item->json('data.id'))->value('product_master_id'));
    }

    public function test_a_lead_with_a_sub_product_of_another_organisation_is_not_found_and_nothing_is_stored(): void
    {
        $this->postJson('/api/crm/leads', ['lastName' => 'Theirs', 'assignedTo' => $this->user['id'], 'productMasterId' => $this->p['theirSub']], $this->user['h'])
            ->assertStatus(422)->assertJsonPath('message', 'Product not found.');
        $this->assertSame(0, DB::table('crm_leads')->where('last_name', 'Theirs')->count());

        $mine = $this->postJson('/api/crm/leads', ['lastName' => 'Mine', 'assignedTo' => $this->user['id'], 'productMasterId' => $this->p['sub']], $this->user['h'])->assertCreated();
        $this->putJson('/api/crm/leads/' . $mine->json('data.id'), ['productMasterId' => $this->p['theirSub']], $this->user['h'])->assertStatus(422);
        $this->assertSame($this->p['sub'], (int) DB::table('crm_leads')->where('id', $mine->json('data.id'))->value('product_master_id'));
    }

    public function test_a_lead_on_a_sub_product_is_found_by_the_facet_of_its_product_and_by_the_facet_of_the_sub_product_itself(): void
    {
        $onSub = $this->makeLead(self::T, ['last_name' => 'OnTheSub', 'product_master_id' => $this->p['sub'], 'assigned_to' => $this->user['id'], 'email' => 'a@school.example']);
        $onItem = $this->makeLead(self::T, ['last_name' => 'OnTheItem', 'product_master_id' => $this->p['item'], 'assigned_to' => $this->user['id'], 'email' => 'b@school.example']);
        $names = fn (string $query) => collect($this->getJson('/api/crm/leads' . $query, $this->user['h'])->assertOk()->json('data.items'))->pluck('lastName')->sort()->values()->all();

        $this->assertSame(['OnTheItem', 'OnTheSub'], $names('?product_id=' . $this->p['product']), 'the facet of the Product finds the leads under its Sub-products and items');
        $this->assertSame(['OnTheSub'], $names('?product_id=' . $this->p['sub']), 'and the facet of the Sub-product finds its own');
        $this->assertSame(['OnTheItem'], $names('?product_id=' . $this->p['item']));
        $this->assertGreaterThan(0, $onSub + $onItem);
    }

    // ================================================================== the CRM campaign form

    public function test_a_crm_campaign_is_made_with_a_sub_product_and_another_organisations_is_refused(): void
    {
        $created = $this->postJson('/api/crm/campaigns', ['name' => 'On a Sub-product', 'assignedTo' => $this->user['id'], 'product_master_id' => $this->p['sub']], $this->user['h'])->assertCreated()->json('data');

        $this->assertSame($this->p['sub'], $created['product']['id']);
        $this->assertSame($this->p['sub'], (int) DB::table('crm_campaigns')->where('id', $created['id'])->value('product_master_id'));
        $this->putJson("/api/crm/campaigns/{$created['id']}", ['product_master_id' => $this->p['item']], $this->user['h'])->assertOk()->assertJsonPath('data.product.id', $this->p['item']);

        $before = DB::table('crm_campaigns')->count();
        $this->postJson('/api/crm/campaigns', ['name' => 'Theirs', 'assignedTo' => $this->user['id'], 'product_master_id' => $this->p['theirSub']], $this->user['h'])->assertStatus(422);
        $this->assertSame($before, DB::table('crm_campaigns')->count());
    }

    // ================================================================== the GTM campaign form

    public function test_a_gtm_campaign_is_made_with_a_sub_product_or_an_item_and_another_organisations_is_refused(): void
    {
        foreach (['sub', 'item'] as $key) {
            $campaign = $this->postJson('/api/gtm/campaigns', ['name' => "ABM {$key}", 'kind' => 'abm', 'product_master_id' => $this->p[$key]], $this->gtm)->assertCreated()->json('data.campaign');
            $this->assertSame($this->p[$key], (int) $campaign['product_master_id']);
        }

        $this->postJson('/api/gtm/campaigns', ['name' => 'ABM theirs', 'kind' => 'abm', 'product_master_id' => $this->p['theirSub']], $this->gtm)->assertStatus(422)->assertJsonPath('message', 'Product not found.');
        $this->assertSame(0, DB::table('gtm_campaigns')->where('name', 'ABM theirs')->count());
    }
}
