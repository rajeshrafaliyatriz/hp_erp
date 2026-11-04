<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\Support\BuildsPipelineWorld;
use Tests\TestCase;

/**
 * Slice 11, acceptance 3, 4 and 6: an opportunity carries its product and a coded loss reason, both checked against the organisation; the two configurable
 * rules (a coded reason required for Closed Lost; fields required to enter a stage) are OFF by default and change nothing until switched on; the old response
 * keeps every key it had; another organisation's opportunity is a 404 on every route.
 */
class PipelineLinksApiTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;
    use BuildsPipelineWorld;

    /** Every key the opportunity response had before slice 11, in order. A new key is added AFTER these; none of these changes. */
    private const OLD_KEYS = [
        'id', 'opportunityNo', 'name', 'organizationId', 'organizationName', 'campaignId', 'campaignName', 'amount', 'currency', 'closingDate', 'salesStage', 'probability',
        'weightedRevenue', 'leadSource', 'potentialType', 'nextStep', 'forecastCategory', 'description', 'closeReason', 'convertedFromGtmDealId', 'assignedTo', 'createdBy',
        'createdAt', 'updatedAt',
    ];

    /** @var array{id: int, h: array<string, string>} */
    private array $caller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPipelineWorld();
        $this->caller = $this->opportunitiesUser();
    }

    private function create(array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/crm/opportunities', $over + ['name' => 'Deal ' . uniqid(), 'assignedTo' => $this->caller['id']], $this->caller['h']);
    }

    // ---- the response keeps its shape ----

    public function test_the_old_keys_are_all_still_there_in_the_same_order_and_the_new_ones_follow(): void
    {
        $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Prospecting']);

        $show = $this->getJson("/api/crm/opportunities/{$id}", $this->caller['h'])->assertOk()->json('data');
        $list = $this->getJson('/api/crm/opportunities', $this->caller['h'])->assertOk()->json('data.items.0');
        $created = $this->create()->assertStatus(201)->json('data');

        foreach ([$show, $list, $created] as $shape) {
            $this->assertSame(self::OLD_KEYS, array_slice(array_keys($shape), 0, count(self::OLD_KEYS)), 'the old keys, untouched and in order');
            $this->assertSame(['product', 'loss_reason'], array_slice(array_keys($shape), count(self::OLD_KEYS)), 'and exactly two new ones after them');
            $this->assertNull($shape['product']);
            $this->assertNull($shape['loss_reason']);
        }
    }

    // ---- the product ----

    public function test_an_opportunity_is_created_with_its_own_product_and_reads_it_back(): void
    {
        $own = $this->makeProduct(self::T, 'my_product', 'My Product');

        $res = $this->create(['productMasterId' => $own])->assertStatus(201);

        $this->assertSame(['id' => $own, 'key' => 'my_product', 'name' => 'My Product'], $res->json('data.product'));
        $this->assertSame($own, (int) DB::table('crm_opportunities')->find((int) $res->json('data.id'))->product_master_id);
        $this->getJson('/api/crm/opportunities/' . $res->json('data.id'), $this->caller['h'])->assertJsonPath('data.product.name', 'My Product');
    }

    public function test_the_snake_case_spelling_of_the_contract_works_too(): void
    {
        $own = $this->makeProduct(self::T, 'my_product', 'My Product');

        $res = $this->create(['product_master_id' => $own])->assertStatus(201);
        $this->assertSame($own, $res->json('data.product.id'));

        $this->putJson('/api/crm/opportunities/' . $res->json('data.id'), ['product_master_id' => null], $this->caller['h'])->assertOk()->assertJsonPath('data.product', null);
    }

    public function test_a_shared_product_is_allowed(): void
    {
        $shared = $this->makeProduct(0, 'shared_product', 'Shared Product');

        $this->create(['productMasterId' => $shared])->assertStatus(201)->assertJsonPath('data.product.name', 'Shared Product');
    }

    public function test_another_organisations_product_is_a_422_and_nothing_is_stored(): void
    {
        $theirs = $this->makeProduct(self::T2, 'their_product', 'Their Product');
        $mine = $this->makeOpportunity(self::T, ['name' => 'Mine', 'assigned_to' => $this->caller['id']]);

        $this->create(['name' => 'Wants theirs', 'productMasterId' => $theirs])->assertStatus(422)->assertJsonPath('status', 0);
        $this->putJson("/api/crm/opportunities/{$mine}", ['productMasterId' => $theirs], $this->caller['h'])->assertStatus(422);
        $this->putJson("/api/crm/opportunities/{$mine}", ['productMasterId' => 987654], $this->caller['h'])->assertStatus(422);   // an id that does not exist

        $this->assertSame(0, DB::table('crm_opportunities')->where('name', 'Wants theirs')->count());
        $this->assertNull(DB::table('crm_opportunities')->find($mine)->product_master_id);
    }

    public function test_a_product_pointer_that_is_not_this_organisations_to_see_shows_as_nothing(): void
    {
        // Planted by hand (the API refuses it): the response must not leak the other organisation's product name.
        $theirs = $this->makeProduct(self::T2, 'their_product', 'Their Secret Product');
        $id = $this->makeOpportunity(self::T, ['product_master_id' => $theirs]);

        $res = $this->getJson("/api/crm/opportunities/{$id}", $this->caller['h'])->assertOk();

        $this->assertNull($res->json('data.product'));
        $this->assertStringNotContainsString('Secret', $res->getContent());
    }

    public function test_a_list_reads_its_products_in_one_query(): void
    {
        foreach (range(1, 5) as $i) {
            $this->makeOpportunity(self::T, ['product_master_id' => $this->makeProduct(self::T, "p{$i}", "Product {$i}"), 'name' => "Deal {$i}"]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();

        $res = $this->getJson('/api/crm/opportunities', $this->caller['h'])->assertOk();

        $productQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "g2g_product_master"'))->count();
        $this->assertSame(1, $productQueries, 'one query for the five products, not five');
        $this->assertCount(5, array_filter($res->json('data.items'), fn ($i) => $i['product'] !== null));
    }

    // ---- the loss reason ----

    public function test_a_coded_loss_reason_is_stored_and_read_back_with_its_label(): void
    {
        $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Negotiation/Review', 'assigned_to' => $this->caller['id']]);

        $res = $this->putJson("/api/crm/opportunities/{$id}/stage", ['salesStage' => 'Closed Lost', 'lossReasonCode' => 'price', 'closeReason' => 'They found it 20% cheaper'], $this->caller['h'])->assertOk();

        $this->assertSame(['code' => 'price', 'label' => 'Price too high'], $res->json('data.loss_reason'));
        $this->assertSame('price', DB::table('crm_opportunities')->find($id)->loss_reason_code);
        $this->assertSame('They found it 20% cheaper', DB::table('crm_opportunities')->find($id)->close_reason, 'the free text is kept as written');
    }

    public function test_a_coded_reason_alone_counts_as_saying_why_and_fills_the_free_text(): void
    {
        $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Prospecting', 'assigned_to' => $this->caller['id']]);

        $this->putJson("/api/crm/opportunities/{$id}/stage", ['salesStage' => 'Closed Lost', 'lossReasonCode' => 'competitor'], $this->caller['h'])->assertOk();

        $row = DB::table('crm_opportunities')->find($id);
        $this->assertSame(['competitor', 'Chose a competitor'], [$row->loss_reason_code, $row->close_reason], 'the older screens and the GTM deal see a reason too');
    }

    public function test_the_old_rule_still_holds_a_lost_deal_needs_some_reason(): void
    {
        $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Prospecting', 'assigned_to' => $this->caller['id']]);

        $stage = $this->putJson("/api/crm/opportunities/{$id}/stage", ['salesStage' => 'Closed Lost'], $this->caller['h']);
        $edit = $this->putJson("/api/crm/opportunities/{$id}", ['salesStage' => 'Closed Lost'], $this->caller['h']);
        $create = $this->create(['salesStage' => 'Closed Lost']);

        foreach ([$stage, $edit, $create] as $res) {
            $res->assertStatus(422)->assertJsonPath('code', 'reason_required')->assertJsonPath('message', 'Say why this opportunity was lost - it is the only way the pipeline learns from it.');
        }
        $this->assertSame('Prospecting', DB::table('crm_opportunities')->find($id)->sales_stage);
    }

    public function test_a_free_text_reason_alone_is_still_enough_by_default(): void
    {
        $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Prospecting', 'assigned_to' => $this->caller['id']]);

        $this->putJson("/api/crm/opportunities/{$id}/stage", ['salesStage' => 'Closed Lost', 'closeReason' => 'No money this year'], $this->caller['h'])->assertOk()->assertJsonPath('data.loss_reason', null);
    }

    public function test_a_code_that_is_not_in_the_list_is_a_422_and_so_is_a_code_on_a_deal_that_is_not_lost(): void
    {
        $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Prospecting', 'assigned_to' => $this->caller['id']]);

        $this->putJson("/api/crm/opportunities/{$id}/stage", ['salesStage' => 'Closed Lost', 'lossReasonCode' => 'because_i_said', 'closeReason' => 'x'], $this->caller['h'])->assertStatus(422);
        $this->putJson("/api/crm/opportunities/{$id}", ['lossReasonCode' => 'price'], $this->caller['h'])->assertStatus(422)->assertJsonPath('code', 'loss_reason_not_lost');
        $this->create(['salesStage' => 'Prospecting', 'lossReasonCode' => 'price'])->assertStatus(422)->assertJsonPath('code', 'loss_reason_not_lost');

        $this->assertSame('Prospecting', DB::table('crm_opportunities')->find($id)->sales_stage);
        $this->assertNull(DB::table('crm_opportunities')->find($id)->loss_reason_code);
    }

    public function test_reopening_a_lost_deal_clears_its_code(): void
    {
        $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Closed Lost', 'loss_reason_code' => 'timing', 'close_reason' => 'Next year', 'assigned_to' => $this->caller['id']]);

        $res = $this->putJson("/api/crm/opportunities/{$id}/stage", ['salesStage' => 'Qualification'], $this->caller['h'])->assertOk();

        $this->assertNull($res->json('data.loss_reason'));
        $this->assertNull(DB::table('crm_opportunities')->find($id)->loss_reason_code);
    }

    public function test_the_loss_reason_switch_off_by_default_and_on(): void
    {
        $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Prospecting', 'assigned_to' => $this->caller['id']]);

        // OFF (the default): free text is enough.
        $this->assertFalse(config('crm_sales.loss_reason_required'));
        $this->putJson("/api/crm/opportunities/{$id}/stage", ['salesStage' => 'Closed Lost', 'closeReason' => 'Gone quiet'], $this->caller['h'])->assertOk();

        // ON: a move into Closed Lost needs a code, answered field by field.
        config(['crm_sales.loss_reason_required' => true]);
        $other = $this->makeOpportunity(self::T, ['sales_stage' => 'Prospecting', 'assigned_to' => $this->caller['id']]);
        $this->putJson("/api/crm/opportunities/{$other}/stage", ['salesStage' => 'Closed Lost', 'closeReason' => 'Gone quiet'], $this->caller['h'])
            ->assertStatus(422)->assertJsonPath('code', 'loss_reason_required')->assertJsonPath('errors.lossReasonCode.0', 'Required for Closed Lost.');
        $this->assertSame('Prospecting', DB::table('crm_opportunities')->find($other)->sales_stage);
        $this->putJson("/api/crm/opportunities/{$other}/stage", ['salesStage' => 'Closed Lost', 'lossReasonCode' => 'no_response'], $this->caller['h'])->assertOk();

        // Editing a deal that is ALREADY lost is not a move into it, so the switch does not block the edit.
        $this->putJson("/api/crm/opportunities/{$id}", ['name' => 'Renamed after loss'], $this->caller['h'])->assertOk();
    }

    // ---- fields required to enter a stage ----

    public function test_nothing_is_required_by_default(): void
    {
        $this->assertSame([], config('crm_sales.required_to_enter'));
        $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Qualification', 'assigned_to' => $this->caller['id']]);

        $this->putJson("/api/crm/opportunities/{$id}/stage", ['salesStage' => 'Proposal/Quotation'], $this->caller['h'])->assertOk()->assertJsonPath('data.salesStage', 'Proposal/Quotation');
    }

    public function test_a_move_into_a_listed_stage_without_its_fields_is_a_422_keyed_by_field(): void
    {
        config(['crm_sales.required_to_enter' => ['Proposal/Quotation' => ['amount', 'closing_date']]]);
        $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Qualification', 'assigned_to' => $this->caller['id']]);

        $res = $this->putJson("/api/crm/opportunities/{$id}/stage", ['salesStage' => 'Proposal/Quotation'], $this->caller['h']);

        $res->assertStatus(422)->assertJsonPath('code', 'fields_required')->assertJsonPath('message', 'Add an amount and a closing date before moving this to Proposal/Quotation.');
        $this->assertSame(['amount', 'closingDate'], array_keys($res->json('errors')));
        $this->assertSame('Qualification', DB::table('crm_opportunities')->find($id)->sales_stage, 'it did not move');
        $this->assertSame(0, DB::table('crm_opportunity_stage_history')->where('opportunity_id', $id)->count(), 'and left no history');

        // One missing field names only that field.
        $this->putJson("/api/crm/opportunities/{$id}/stage", ['salesStage' => 'Proposal/Quotation', 'amount' => 900], $this->caller['h'])
            ->assertStatus(422)->assertJsonPath('message', 'Add a closing date before moving this to Proposal/Quotation.');

        // Both given with the move: it goes through.
        $this->putJson("/api/crm/opportunities/{$id}/stage", ['salesStage' => 'Proposal/Quotation', 'amount' => 900, 'closingDate' => '2026-12-31'], $this->caller['h'])
            ->assertOk()->assertJsonPath('data.salesStage', 'Proposal/Quotation');
    }

    public function test_the_fields_already_on_the_deal_count_and_the_stage_name_is_matched_without_case(): void
    {
        config(['crm_sales.required_to_enter' => ['PROPOSAL/Quotation' => ['amount', 'closing_date']]]);
        $empty = $this->makeOpportunity(self::T, ['sales_stage' => 'Qualification', 'assigned_to' => $this->caller['id']]);
        $full = $this->makeOpportunity(self::T, ['sales_stage' => 'Qualification', 'amount' => '500.00', 'closing_date' => '2026-12-20', 'assigned_to' => $this->caller['id']]);

        // The config spells the stage in lower case and the picklist in title case: the rule still finds the stage.
        $this->putJson("/api/crm/opportunities/{$empty}/stage", ['salesStage' => 'Proposal/Quotation'], $this->caller['h'])->assertStatus(422)->assertJsonPath('code', 'fields_required');
        // And a deal that already has both fields needs nothing more.
        $this->putJson("/api/crm/opportunities/{$full}/stage", ['salesStage' => 'Proposal/Quotation'], $this->caller['h'])->assertOk();
    }

    public function test_the_rule_applies_to_an_edit_and_to_a_create_that_enters_the_stage_but_not_to_other_stages_or_to_an_edit_inside_it(): void
    {
        config(['crm_sales.required_to_enter' => ['Proposal/Quotation' => ['amount']]]);
        $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Qualification', 'assigned_to' => $this->caller['id']]);

        $this->putJson("/api/crm/opportunities/{$id}", ['salesStage' => 'Proposal/Quotation'], $this->caller['h'])->assertStatus(422)->assertJsonPath('errors.amount.0', 'Required before this can move to Proposal/Quotation.');
        $this->create(['salesStage' => 'Proposal/Quotation'])->assertStatus(422)->assertJsonPath('code', 'fields_required');
        $this->putJson("/api/crm/opportunities/{$id}", ['salesStage' => 'Negotiation/Review'], $this->caller['h'])->assertOk();   // another stage: no rule

        // A deal that is ALREADY in the stage (it got there before the rule) can still be edited.
        $inside = $this->makeOpportunity(self::T, ['sales_stage' => 'Proposal/Quotation', 'assigned_to' => $this->caller['id']]);
        $this->putJson("/api/crm/opportunities/{$inside}", ['name' => 'Renamed'], $this->caller['h'])->assertOk();
        $this->putJson("/api/crm/opportunities/{$inside}", ['name' => 'Renamed again', 'salesStage' => 'Proposal/Quotation'], $this->caller['h'])->assertOk();
    }

    public function test_clearing_the_amount_in_the_same_move_counts_as_missing(): void
    {
        config(['crm_sales.required_to_enter' => ['Proposal/Quotation' => ['amount']]]);
        $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Qualification', 'amount' => '500.00', 'assigned_to' => $this->caller['id']]);

        $this->putJson("/api/crm/opportunities/{$id}/stage", ['salesStage' => 'Proposal/Quotation', 'amount' => null], $this->caller['h'])->assertStatus(422);
        $this->putJson("/api/crm/opportunities/{$id}/stage", ['salesStage' => 'Proposal/Quotation', 'amount' => 0], $this->caller['h'])->assertOk();   // zero is a value
    }

    public function test_a_product_can_be_asked_for_too(): void
    {
        config(['crm_sales.required_to_enter' => ['Proposal/Quotation' => ['product_master_id']]]);
        $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Qualification', 'assigned_to' => $this->caller['id']]);

        $this->putJson("/api/crm/opportunities/{$id}/stage", ['salesStage' => 'Proposal/Quotation'], $this->caller['h'])->assertStatus(422)->assertJsonPath('errors.productMasterId.0', 'Required before this can move to Proposal/Quotation.');
        $this->putJson("/api/crm/opportunities/{$id}", ['salesStage' => 'Proposal/Quotation', 'productMasterId' => $this->makeProduct(self::T, 'p', 'P')], $this->caller['h'])->assertOk();
    }

    // ---- a host that is behind ----

    public function test_a_host_without_the_columns_still_saves_and_answers_without_the_new_fields(): void
    {
        $this->tearDownWorldForBehindHost();
        $caller = $this->opportunitiesUser();
        $product = $this->makeProduct(self::T, 'p', 'P');

        $res = $this->postJson('/api/crm/opportunities', ['name' => 'On an old host', 'assignedTo' => $caller['id'], 'salesStage' => 'Prospecting', 'productMasterId' => $product, 'lossReasonCode' => 'price'], $caller['h']);

        $res->assertStatus(201)->assertJsonPath('data.product', null)->assertJsonPath('data.loss_reason', null);
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('crm_opportunities', 'product_master_id'));
        $id = (int) $res->json('data.id');
        $this->putJson("/api/crm/opportunities/{$id}", ['name' => 'Renamed', 'product_master_id' => $product], $caller['h'])->assertOk();
        $this->putJson("/api/crm/opportunities/{$id}/stage", ['salesStage' => 'Closed Lost', 'closeReason' => 'x', 'lossReasonCode' => 'price'], $caller['h'])->assertOk();
    }

    private function tearDownWorldForBehindHost(): void
    {
        (require base_path('database/migrations/2026_12_11_130000_add_product_and_loss_reason_to_crm_opportunities.php'))->down();
    }

    // ---- another organisation ----

    public function test_every_id_route_answers_another_organisations_opportunity_with_the_404_of_an_id_that_never_existed(): void
    {
        $secret = $this->makeProduct(self::T2, 'secret', 'Secret Product');
        $victim = $this->makeOpportunity(self::T2, ['name' => 'Victim secret deal', 'sales_stage' => 'Prospecting', 'product_master_id' => $secret, 'amount' => '777.00', 'currency' => 'INR']);
        DB::table('crm_opportunity_stage_history')->insert(['opportunity_id' => $victim, 'from_stage' => null, 'to_stage' => 'Prospecting', 'created_at' => now()]);
        $before = $this->footprint();
        $body = ['name' => 'Hijacked', 'salesStage' => 'Closed Won', 'amount' => 1, 'productMasterId' => $secret, 'lossReasonCode' => 'price', 'assignedTo' => $this->caller['id'], 'contactId' => 1, 'productId' => 1, 'quantity' => 1];

        $swept = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (! str_starts_with($route->uri(), 'api/crm/opportunities/') || ! str_contains($route->uri(), '{id}')) {
                continue;
            }
            foreach (array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS'])) as $method) {
                $url = fn (int $id) => '/' . str_replace(['{id}', '{rowId}'], [(string) $id, '1'], $route->uri());
                $none = $this->json($method, $url(999999), $body, $this->caller['h']);
                $theirs = $this->json($method, $url($victim), $body, $this->caller['h']);
                $this->assertSame(404, $none->getStatusCode(), "{$method} {$route->uri()} with an id that never existed");
                $this->assertSame(404, $theirs->getStatusCode(), "{$method} {$route->uri()} with the other organisation's id");
                $this->assertSame($none->json(), $theirs->json(), "{$method} {$route->uri()}: the same answer, nothing revealed");
                $this->assertStringNotContainsString('Secret', $theirs->getContent());
                $swept[] = "{$method} {$route->uri()}";
            }
        }

        $this->assertSame($before, $this->footprint(), "nothing of the other organisation's changed");
        foreach (['GET api/crm/opportunities/{id}', 'PUT api/crm/opportunities/{id}', 'PUT api/crm/opportunities/{id}/stage', 'GET api/crm/opportunities/{id}/stage-history', 'DELETE api/crm/opportunities/{id}'] as $needed) {
            $this->assertContains($needed, $swept, "{$needed} was swept");
        }
    }

    public function test_the_list_and_the_stage_history_show_only_the_callers_organisation(): void
    {
        $theirs = $this->makeOpportunity(self::T2, ['name' => 'Victim secret deal', 'product_master_id' => $this->makeProduct(self::T2, 'secret', 'Secret Product')]);
        $mine = $this->makeOpportunity(self::T, ['name' => 'Mine']);

        $list = $this->getJson('/api/crm/opportunities', $this->caller['h'])->assertOk();

        $this->assertSame([$mine], array_map('intval', array_column($list->json('data.items'), 'id')));
        $this->assertStringNotContainsString('Secret', $list->getContent());
        $this->assertStringNotContainsString((string) $theirs, (string) json_encode(array_column($list->json('data.items'), 'id')));
    }

    /** @return array<string, mixed> everything of organisation 2's a request could change */
    private function footprint(): array
    {
        $rows = fn (string $table) => DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return ['opportunities' => DB::table('crm_opportunities')->where('sub_institute_id', self::T2)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'history' => $rows('crm_opportunity_stage_history'), 'contacts' => $rows('crm_opportunity_contacts'), 'products' => $rows('crm_opportunity_products')];
    }
}
