<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\Support\BuildsPipelineWorld;
use Tests\TestCase;

/**
 * The opportunity form stores the MASTER id of what the person chose, and since the S15 follow-up (24 Nov 2026, D-7; contract section 4.4) that may be a Sub-product or an item
 * as well as a Product. CrmOpportunityController asks LeadLinks::productVisible, which accepts any master row of the ORGANISATION (it was never restricted to `kind = product`);
 * this file pins that for the opportunity form, as ProductIdsAcceptSubProductsTest does for the lead and campaign forms, and pins that another organisation's Sub-product is
 * "not found" the same way a missing one is.
 */
class ProductIdsAcceptSubProductsOpportunityTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;
    use BuildsPipelineWorld;

    /** @var array{id: int, h: array<string, string>} */
    private array $caller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPipelineWorld();
        $this->caller = $this->opportunitiesUser();
    }

    private function row(int $tenant, string $kind, string $name, ?int $parent = null, string $status = 'active'): int
    {
        return (int) DB::table('g2g_product_master')->insertGetId([
            'sub_institute_id' => $tenant, 'kind' => $kind, 'product_key' => strtolower(str_replace(' ', '_', $name)) . '_' . uniqid(), 'name' => $name, 'status' => $status, 'parent_id' => $parent,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function create(array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/crm/opportunities', $over + ['name' => 'Deal ' . uniqid(), 'assignedTo' => $this->caller['id']], $this->caller['h']);
    }

    public function test_an_opportunity_is_made_and_changed_with_a_sub_product_or_an_item_and_reads_it_back_by_name(): void
    {
        $product = $this->row(self::T, 'product', 'Payroll Cloud');
        $sub = $this->row(self::T, 'sub_product', 'Payroll one', $product);
        $item = $this->row(self::T, 'item', 'Starter kit', $product);

        $made = $this->create(['productMasterId' => $sub])->assertStatus(201);

        $this->assertSame($sub, (int) DB::table('crm_opportunities')->find((int) $made->json('data.id'))->product_master_id);
        $made->assertJsonPath('data.product.id', $sub)->assertJsonPath('data.product.name', 'Payroll one');
        $this->putJson('/api/crm/opportunities/' . $made->json('data.id'), ['productMasterId' => $item], $this->caller['h'])->assertOk()->assertJsonPath('data.product.id', $item);
        $this->create(['product_master_id' => $item])->assertStatus(201);
    }

    public function test_another_organisations_sub_product_is_not_found_on_create_and_on_update_and_nothing_is_stored(): void
    {
        $theirProduct = $this->row(self::T2, 'product', 'Their Product');
        $theirSub = $this->row(self::T2, 'sub_product', 'Their sub', $theirProduct);
        $mineProduct = $this->row(self::T, 'product', 'Payroll Cloud');
        $mineSub = $this->row(self::T, 'sub_product', 'Payroll one', $mineProduct);
        $made = $this->create(['productMasterId' => $mineSub])->assertStatus(201);
        $count = DB::table('crm_opportunities')->count();

        $this->create(['name' => 'Wants theirs', 'productMasterId' => $theirSub])->assertStatus(422)->assertJsonPath('status', 0);
        $this->putJson('/api/crm/opportunities/' . $made->json('data.id'), ['productMasterId' => $theirSub], $this->caller['h'])->assertStatus(422);

        $this->assertSame($count, DB::table('crm_opportunities')->count());
        $this->assertSame($mineSub, (int) DB::table('crm_opportunities')->find((int) $made->json('data.id'))->product_master_id);
    }
}
