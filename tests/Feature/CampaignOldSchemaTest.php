<?php

namespace Tests\Feature;

use App\Domain\Crm\Leads\LeadFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsCampaignsWorld;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/**
 * A host that has the code of slice 9 but not its three migrations yet (the code reaches a server before its database does): the campaign, GTM campaign and lead
 * routes that already existed must answer exactly as they did, the new keys read as "nothing", and a write that names a link says plainly that this server cannot
 * store it instead of answering as if it had. The world here is built with buildCampaignsWorld(false): none of the new columns exists.
 */
class CampaignOldSchemaTest extends TestCase
{
    use BuildsCampaignsWorld;
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    private array $user;

    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCampaignsWorld(false);
        $this->buildSignalsWorld();
        $this->user = $this->campaignsUser();
        $this->admin = $this->gtmAdmin();
    }

    public function test_the_world_really_is_the_old_schema(): void
    {
        $this->assertFalse(Schema::hasColumn('crm_campaigns', 'product_master_id'));
        $this->assertFalse(Schema::hasColumn('gtm_campaigns', 'product_master_id'));
        $this->assertFalse(Schema::hasColumn('gtm_campaigns', 'crm_campaign_id'));
        $this->assertFalse(Schema::hasColumn('crm_leads', 'campaign_id'));
    }

    // ── CRM campaigns ─────────────────────────────────────────────────────────

    public function test_the_campaign_list_and_detail_answer_as_before_and_the_new_keys_read_as_nothing(): void
    {
        $id = $this->makeCampaign(self::T, ['name' => 'Old schema campaign', 'budget_cost' => '10.00', 'product_id' => 5, 'assigned_to' => $this->user['id']]);
        $this->makeTarget($id, 'lead', 1, ['sent_at' => '2026-11-01 10:00:00']);

        $item = $this->getJson('/api/crm/campaigns', $this->user['h'])->assertOk()->json('data.items.0');
        $detail = $this->getJson("/api/crm/campaigns/{$id}", $this->user['h'])->assertOk()->json('data');

        $this->assertSame('Old schema campaign', $item['name']);
        $this->assertEquals(10.0, $item['budgetCost']);
        $this->assertSame('5', $item['productId']);
        $this->assertNull($item['product']);
        $this->assertNull($detail['product']);
        $this->assertNull($detail['gtm_campaign']);
        $this->assertSame(['count' => 0], $detail['leads']);
        $this->assertSame(['targets' => 1, 'sent' => 1, 'opened' => 0, 'replied' => 0, 'meetings' => 0, 'converted' => 0, 'opted_out' => 0], $detail['funnel'], 'the funnel needs only the targets table');
    }

    public function test_a_campaign_can_still_be_made_changed_imported_and_have_targets_without_naming_a_product(): void
    {
        $created = $this->postJson('/api/crm/campaigns', ['name' => 'Made', 'assignedTo' => $this->user['id']], $this->user['h'])->assertCreated()->json('data');
        $id = (int) $created['id'];
        $this->assertNull($created['product']);

        $this->putJson("/api/crm/campaigns/{$id}", ['name' => 'Renamed'], $this->user['h'])->assertOk()->assertJsonPath('data.name', 'Renamed');
        $import = $this->postJson('/api/crm/campaigns/import', ['rows' => [['name' => 'Imported', 'assignedTo' => $this->user['id']]]], $this->user['h'])->assertOk();
        $lead = $this->makeLead(self::T, ['assigned_to' => $this->user['id']]);
        $this->postJson("/api/crm/campaigns/{$id}/targets", ['targetType' => 'lead', 'targetId' => $lead], $this->user['h'])->assertOk();
        $row = DB::table('crm_campaign_targets')->where('campaign_id', $id)->value('id');
        $this->postJson("/api/crm/campaigns/{$id}/targets/{$row}/events", ['event' => 'replied'], $this->user['h'])->assertOk();

        $this->assertSame(1, $import->json('data.created'));
        $this->assertSame(1, $this->getJson("/api/crm/campaigns/{$id}", $this->user['h'])->json('data.funnel.replied'));
        $this->deleteJson("/api/crm/campaigns/{$id}", [], $this->user['h'])->assertOk();
    }

    public function test_naming_a_product_says_this_server_cannot_store_it_and_clearing_one_is_accepted(): void
    {
        $product = $this->makeProductRow(self::T, 'ScholarClone');
        $id = $this->makeCampaign(self::T, ['name' => 'Kept', 'assigned_to' => $this->user['id']]);
        $before = DB::table('crm_campaigns')->count();

        $store = $this->postJson('/api/crm/campaigns', ['name' => 'New', 'assignedTo' => $this->user['id'], 'product_master_id' => $product], $this->user['h']);
        $update = $this->putJson("/api/crm/campaigns/{$id}", ['name' => 'Renamed', 'product_master_id' => $product], $this->user['h']);

        $store->assertStatus(422)->assertJsonPath('code', 'product_link_unavailable');
        $update->assertStatus(422)->assertJsonPath('code', 'product_link_unavailable');
        $this->assertStringContainsString('not available yet', $store->json('message'));
        $this->assertSame($before, DB::table('crm_campaigns')->count());
        $this->assertSame('Kept', DB::table('crm_campaigns')->where('id', $id)->value('name'), 'a refused update changes nothing');

        $this->putJson("/api/crm/campaigns/{$id}", ['name' => 'Renamed', 'product_master_id' => null], $this->user['h'])->assertOk();
        $this->postJson('/api/crm/campaigns', ['name' => 'New', 'assignedTo' => $this->user['id'], 'product_master_id' => ''], $this->user['h'])->assertCreated();
        $this->assertSame('Renamed', DB::table('crm_campaigns')->where('id', $id)->value('name'));
    }

    // ── GTM campaigns ─────────────────────────────────────────────────────────

    public function test_gtm_campaigns_answer_as_before(): void
    {
        $id = $this->makeGtmCampaign(self::T, ['name' => 'Old schema GTM']);

        $this->getJson('/api/gtm/campaigns', $this->admin)->assertOk();
        $detail = $this->getJson("/api/gtm/campaigns/{$id}", $this->admin)->assertOk()->json('data');
        $created = $this->postJson('/api/gtm/campaigns', ['name' => 'Made', 'kind' => 'abm'], $this->admin)->assertCreated()->json('data.campaign');
        $this->patchJson("/api/gtm/campaigns/{$id}", ['name' => 'Renamed'], $this->admin)->assertOk();

        $this->assertSame(['campaign', 'members', 'metrics', 'industry_pack', 'transitions', 'product', 'crm_campaign'], array_keys($detail));
        $this->assertNull($detail['product']);
        $this->assertNull($detail['crm_campaign']);
        $this->assertSame('Made', $created['name']);
        $this->assertSame('Renamed', DB::table('gtm_campaigns')->where('id', $id)->value('name'));
    }

    public function test_gtm_links_say_this_server_cannot_store_them_and_clearing_is_accepted(): void
    {
        $product = $this->makeProductRow(self::T, 'P');
        $crm = $this->makeCampaign(self::T);
        $id = $this->makeGtmCampaign(self::T, ['name' => 'Kept']);
        $before = DB::table('gtm_campaigns')->count();

        $a = $this->postJson('/api/gtm/campaigns', ['name' => 'New', 'kind' => 'abm', 'product_master_id' => $product], $this->admin);
        $b = $this->patchJson("/api/gtm/campaigns/{$id}", ['name' => 'Renamed', 'crm_campaign_id' => $crm], $this->admin);

        $a->assertStatus(422);
        $b->assertStatus(422);
        $this->assertStringContainsString('not available yet', $a->json('message'));
        $this->assertStringContainsString('not available yet', $b->json('message'));
        $this->assertSame($before, DB::table('gtm_campaigns')->count());
        $this->assertSame('Kept', DB::table('gtm_campaigns')->where('id', $id)->value('name'));

        $this->patchJson("/api/gtm/campaigns/{$id}", ['name' => 'Renamed', 'crm_campaign_id' => null, 'product_master_id' => null], $this->admin)->assertOk();
        $this->postJson('/api/gtm/campaigns', ['name' => 'New', 'kind' => 'abm', 'crm_campaign_id' => null], $this->admin)->assertCreated();
        $this->assertSame('Renamed', DB::table('gtm_campaigns')->where('id', $id)->value('name'));
    }

    // ── leads ─────────────────────────────────────────────────────────────────

    public function test_the_lead_routes_answer_as_before_and_campaign_reads_null(): void
    {
        $id = $this->makeLead(self::T, ['assigned_to' => $this->user['id'], 'company' => 'Old Co']);

        $item = $this->getJson('/api/crm/leads', $this->user['h'])->assertOk()->json('data.items.0');
        $detail = $this->getJson("/api/crm/leads/{$id}", $this->user['h'])->assertOk()->json('data');
        $created = $this->postJson('/api/crm/leads', ['lastName' => 'Made', 'leadStatus' => 'New', 'assignedTo' => $this->user['id']], $this->user['h'])->assertCreated()->json('data');
        $this->putJson("/api/crm/leads/{$id}", ['company' => 'Changed'], $this->user['h'])->assertOk();
        $import = $this->postJson('/api/crm/leads/import', ['rows' => [['lastName' => 'Imported', 'assignedTo' => $this->user['id']]]], $this->user['h'])->assertOk();

        $this->assertSame('Old Co', $item['company']);
        $this->assertNull($item['campaign']);
        $this->assertNull($detail['campaign']);
        $this->assertNull($created['campaign']);
        $this->assertSame(1, $import->json('data.created'));
        $this->assertSame('Changed', DB::table('crm_leads')->where('id', $id)->value('company'));
    }

    public function test_naming_a_campaign_on_a_new_lead_says_this_server_cannot_store_it(): void
    {
        $campaign = $this->makeCampaign(self::T);
        $before = DB::table('crm_leads')->count();

        $store = $this->postJson('/api/crm/leads', ['lastName' => 'New', 'leadStatus' => 'New', 'assignedTo' => $this->user['id'], 'campaign_id' => $campaign], $this->user['h']);
        $import = $this->postJson('/api/crm/leads/import', ['rows' => [['lastName' => 'Row', 'assignedTo' => $this->user['id'], 'campaign_id' => $campaign], ['lastName' => 'Plain', 'assignedTo' => $this->user['id']]]], $this->user['h'])->assertOk();

        $store->assertStatus(422)->assertJsonPath('code', 'campaign_link_unavailable');
        $this->assertSame([false, true], array_column($import->json('data.results'), 'ok'));
        $this->assertStringContainsString('not available yet', $import->json('data.results.0.reason'));
        $this->assertSame($before + 1, DB::table('crm_leads')->count(), 'only the plain import row was made');

        $this->postJson('/api/crm/leads', ['lastName' => 'Empty', 'leadStatus' => 'New', 'assignedTo' => $this->user['id'], 'campaign_id' => null], $this->user['h'])->assertCreated();
    }

    public function test_the_public_form_ignores_a_campaign_it_cannot_store_and_still_takes_the_lead(): void
    {
        DB::table('crm_lead_capture_settings')->insert(['sub_institute_id' => self::T, 'public_key' => 'pk_old', 'is_enabled' => 1, 'default_lead_source' => 'Website', 'default_assigned_to' => $this->user['id'], 'created_at' => now(), 'updated_at' => now()]);
        $campaign = $this->makeCampaign(self::T);

        $this->postJson('/api/public/lead-capture/pk_old', ['lastName' => 'Visitor', 'email' => 'v@school.example', 'campaign_id' => $campaign])->assertCreated();

        $this->assertSame(1, DB::table('crm_leads')->where('last_name', 'Visitor')->count());
    }

    public function test_the_factory_drops_a_campaign_it_has_no_column_for_instead_of_failing_the_insert(): void
    {
        $campaign = $this->makeCampaign(self::T);

        $id = LeadFactory::create(['last_name' => 'Factory', 'assigned_to' => $this->user['id'], 'campaign_id' => $campaign], self::T, $this->user['id']);

        $this->assertSame('Factory', DB::table('crm_leads')->where('id', $id)->value('last_name'));
    }

    public function test_a_lead_merge_works_without_the_column(): void
    {
        $a = $this->makeLead(self::T, ['assigned_to' => $this->user['id'], 'email' => 'm@school.example']);
        $b = $this->makeLead(self::T, ['assigned_to' => $this->user['id'], 'email' => 'm@school.example']);

        $this->postJson('/api/crm/leads/merge', ['survivorId' => $a, 'duplicateIds' => [$b]], $this->user['h'])->assertOk();
    }
}
