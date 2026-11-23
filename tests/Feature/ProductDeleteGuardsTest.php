<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\ProductMaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsCrmProductApiWorld;
use Tests\TestCase;

/**
 * Two old delete doors that could break a merged record now say no (slice 15, P2-E2a, steps 2 and 3; audit decisions D-8 and D-9).
 *
 *   D-8  DELETE /api/portfolio/offers/{id}  (the About of a Sub-product): 409 `offer_has_crm_item` while the master row of that offer holds a `crm_product_id` (the
 *        Sub-product is sold in CRM: pause or archive it instead), and 409 `offer_has_records` while a row of the registry's third family (ProductPointers::toOffer(), the
 *        readiness history) points at the offer. The ICP guard keeps its place and its words (it is asked first).
 *   D-9  DELETE /api/signals/icp/products/{key}  (a custom Product): 409 `product_has_sub_products` with the count while the Product has children in the master (not
 *        archived) or any offer names it as its group. The built-in refusal (422) and a Product with no Sub-product are as before.
 *
 * Nothing is written when a guard refuses: the rows are read back afterwards.
 */
class ProductDeleteGuardsTest extends TestCase
{
    use BuildsCrmProductApiWorld;

    private const T = 1;

    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrmProductApiWorld();
    }

    private function admin(): array
    {
        return $this->headers($this->h());
    }

    private function createProduct(string $key = 'payroll_cloud', string $label = 'Payroll Cloud'): array
    {
        return $this->postJson('/api/signals/icp/products', ['key' => $key, 'label' => $label], $this->admin())->assertCreated()->json('data.product');
    }

    /** The offer row id of a Sub-product made through the real Portfolio door under the group of a custom Product. */
    private function subProductUnder(string $label, string $offerId = 'PC1'): int
    {
        return (int) $this->postJson('/api/portfolio/offers', ['offer_id' => $offerId, 'name' => "Sub {$offerId}", 'parent_product' => $label, 'readiness_status' => 'Prototype'], $this->admin())
            ->assertStatus(201)->json('data.id');
    }

    // ================================================================ D-8: an About that is sold in CRM

    public function test_d8_deleting_an_offer_whose_sub_product_is_sold_in_crm_is_refused_with_the_words_and_nothing_is_written(): void
    {
        $f = $this->soldSubProduct();
        $before = [DB::table('g2g_product_offers')->get()->all(), DB::table('g2g_product_master')->get()->all(), DB::table('crm_products')->get()->all()];

        $r = $this->deleteJson("/api/portfolio/offers/{$f['offer']}", [], $this->admin())->assertStatus(409);

        $r->assertJsonPath('status', 0)->assertJsonPath('code', 'offer_has_crm_item')->assertJsonPath('message', 'This sub-product is sold in CRM. Pause or archive it instead.');
        $this->assertEquals($before, [DB::table('g2g_product_offers')->get()->all(), DB::table('g2g_product_master')->get()->all(), DB::table('crm_products')->get()->all()], 'no store changed');
    }

    public function test_d8_the_offer_id_works_in_the_address_as_well_as_the_row_number(): void
    {
        $this->soldSubProduct('P9');

        $this->deleteJson('/api/portfolio/offers/P9', [], $this->admin())->assertStatus(409)->assertJsonPath('code', 'offer_has_crm_item');
    }

    public function test_d8_an_offer_that_is_not_sold_in_crm_is_deleted_as_before(): void
    {
        $offer = $this->offerCreate('P9', 'Scholar Nine');

        $this->deleteJson("/api/portfolio/offers/{$offer}", [], $this->admin())->assertOk()->assertJsonPath('message', 'Offer deleted successfully');

        $this->assertNull(DB::table('g2g_product_offers')->where('id', $offer)->first());
    }

    public function test_d8_after_the_sale_is_given_up_the_offer_can_be_deleted(): void
    {
        $f = $this->soldSubProduct();
        $this->deleteJson("/api/portfolio/offers/{$f['offer']}", [], $this->admin())->assertStatus(409);
        DB::table('g2g_product_master')->where('id', $f['master'])->update(['crm_product_id' => null]);

        $this->deleteJson("/api/portfolio/offers/{$f['offer']}", [], $this->admin())->assertOk();
    }

    public function test_d8_the_icp_guard_keeps_its_place_and_its_words_when_both_apply(): void
    {
        $f = $this->soldSubProduct('P9');
        DB::table('g2g_icp_profiles')->insert([
            'sub_institute_id' => self::T, 'key' => 'scholarclone_b2b', 'name' => 'My ICP', 'product_line' => 'scholarclone', 'config' => json_encode(['offer_ids' => ['P9']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = $this->deleteJson("/api/portfolio/offers/{$f['offer']}", [], $this->admin())->assertStatus(409);

        $r->assertJsonPath('code', 'offer_in_use')->assertJsonPath('message', 'This product is selected in an ICP. Remove it from the ICP first, then delete it.');
    }

    public function test_d8_another_organisations_offer_is_not_found_and_a_master_row_of_another_organisation_never_blocks(): void
    {
        $theirs = $this->offerCreate('Q1', 'Their offer', 'Scholar K-12', self::T2);
        DB::table('g2g_product_master')->where('offer_row_id', $theirs)->update(['crm_product_id' => 4242]);

        $this->deleteJson("/api/portfolio/offers/{$theirs}", [], $this->admin())->assertStatus(404);

        // A row of this organisation's own offer: nothing of the other organisation's master row is read.
        $mine = $this->offerCreate('P9', 'Mine');
        $this->deleteJson("/api/portfolio/offers/{$mine}", [], $this->admin())->assertOk();
    }

    public function test_d8_an_offer_with_a_readiness_history_is_refused_because_the_delete_would_orphan_it(): void
    {
        $offer = $this->offerCreate('P9', 'Scholar Nine');
        Schema::create('g2g_offer_readiness_log', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('offer_row_id');
            $t->string('offer_id', 50)->nullable();
        });
        DB::table('g2g_offer_readiness_log')->insert([['sub_institute_id' => self::T, 'offer_row_id' => $offer, 'offer_id' => 'P9'], ['sub_institute_id' => self::T, 'offer_row_id' => $offer, 'offer_id' => 'P9']]);

        $r = $this->deleteJson("/api/portfolio/offers/{$offer}", [], $this->admin())->assertStatus(409);

        $r->assertJsonPath('code', 'offer_has_records')->assertJsonPath('used_by.0.table', 'g2g_offer_readiness_log')->assertJsonPath('used_by.0.count', 2);
        $this->assertNotNull(DB::table('g2g_product_offers')->where('id', $offer)->first(), 'the offer is still there');
    }

    public function test_d8_a_host_with_no_master_table_still_deletes_an_offer_as_before(): void
    {
        $offer = $this->offerCreate('P9', 'Scholar Nine');
        Schema::drop('g2g_product_master');

        $this->deleteJson("/api/portfolio/offers/{$offer}", [], $this->admin())->assertOk();
    }

    // ================================================================ D-9: a custom Product that still has Sub-products

}
