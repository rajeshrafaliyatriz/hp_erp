<?php

namespace Tests\Feature;

use App\Domain\Portfolio\OfferIcpSync;
use App\Domain\Portfolio\ProductOffer;
use App\Domain\Signals\Products\ProductCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsProductRemovalWorld;
use Tests\TestCase;

/**
 * Deleting a Product, a Sub-product or an item (S15 follow-up, 24 Nov 2026; contract section 4.1 and 4.3, decisions D-4, D-5, D-8):
 *
 *   GET  /api/product-master/{id}/removal   what blocks it, the Sub-products under it, the Products they may go to
 *   POST /api/product-master/{id}/remove    all or nothing, one transaction; a Product says what becomes of its Sub-products
 *
 * The world has NO shared row: every Product is the organisation's own (BuildsProductRemovalWorld). A mutation that breaks a safeguard is killed by the test named after it
 * in the mutation table of the hand-over.
 */
class ProductRemovalApiTest extends TestCase
{
    use BuildsProductRemovalWorld;

    private const T = 1;

    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildProductRemovalWorld();
    }

    private function preview(int $id, int $profile = self::ADMIN): \Illuminate\Testing\TestResponse
    {
        return $this->getJson("/api/product-master/{$id}/removal", $this->who($profile));
    }

    private function remove(int $id, array $body = [], int $profile = self::ADMIN): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/product-master/{$id}/remove", $body, $this->who($profile));
    }

    /** @return list<string> the codes of a blockedBy list */
    private function codes(array $blockedBy): array
    {
        return array_column($blockedBy, 'code');
    }

    // ================================================================== the preview (4.1)

    public function test_a_sub_product_nothing_uses_can_be_deleted_and_the_preview_offers_the_other_products(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $this->makeProduct('Legacy', 'legacy_line');
        DB::table('g2g_product_master')->where('product_key', 'legacy_line')->update(['status' => 'archived']);
        $sub = $this->makeSub($payroll, 'PC1', 'Payroll one');

        $data = $this->preview($sub['master'])->assertOk()->assertJsonPath('status', 1)->json('data');

        $this->assertSame(['id' => $sub['master'], 'kind' => 'sub_product', 'name' => 'Payroll one', 'canDelete' => true, 'blockedBy' => [], 'subProducts' => [], 'targets' => [['id' => $hr, 'name' => 'HR Suite']]], $data);
        $this->assertNotContains($payroll, array_column($data['targets'], 'id'), 'the Product it is under is not a place to move it to');
        $this->assertSame([], $this->events->lines, 'a preview writes nothing, history included');
    }

    public function test_a_product_preview_lists_its_sub_products_and_the_other_active_products_but_never_itself(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $a = $this->makeSub($payroll, 'PC1', 'A payroll');
        $b = $this->makeSold($payroll, 'PC2', 'B payroll');

        $data = $this->preview($payroll)->assertOk()->json('data');

        $this->assertSame(['id', 'kind', 'name', 'canDelete', 'blockedBy', 'subProducts', 'targets'], array_keys($data));
        $this->assertSame('product', $data['kind']);
        $this->assertTrue($data['canDelete']);
        $this->assertSame([$a['master'], $b['master']], array_column($data['subProducts'], 'id'));
        $this->assertSame(['id', 'name', 'kind', 'status', 'canDelete', 'blockedBy'], array_keys($data['subProducts'][0]));
        $this->assertSame([['id' => $hr, 'name' => 'HR Suite']], $data['targets'], 'the other active Products; never the record itself');
    }

    public function test_every_blocker_has_its_code_its_count_and_a_sentence_a_person_can_read(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $sold = $this->makeSold($payroll, 'PC1', 'Payroll one');
        $this->quoteLineHolds($sold['crm'], 3);
        $this->priceBookHolds($sold['crm']);
        $this->ticketAbout($sold['crm']);
        $this->leadAbout($sold['master']);
        $this->icp('payroll_cloud_b2b', ['PC1'], 'payroll_cloud');

        $data = $this->preview($sold['master'])->assertOk()->json('data');

        $this->assertFalse($data['canDelete']);
        $this->assertSame(['sold_on_documents', 'records_point_at_it', 'offer_in_use'], $this->codes($data['blockedBy']));
        $by = array_column($data['blockedBy'], null, 'code');
        $this->assertSame(4, $by['sold_on_documents']['count'], '3 quote lines and 1 price book item hold the Sell row');
        $this->assertStringContainsString('quote lines 3', $by['sold_on_documents']['message']);
        $this->assertStringContainsString('price book items 1', $by['sold_on_documents']['message']);
        $this->assertSame(2, $by['records_point_at_it']['count'], 'a ticket by its catalogue id and a lead by its master id');
        $this->assertStringContainsString('tickets 1', $by['records_point_at_it']['message']);
        $this->assertStringContainsString('leads 1', $by['records_point_at_it']['message']);
        $this->assertSame(1, $by['offer_in_use']['count']);
        $this->assertStringContainsString('ICP payroll_cloud_b2b', $by['offer_in_use']['message']);
        foreach ($data['blockedBy'] as $b) {
            $this->assertSame(['code', 'count', 'message'], array_keys($b));
            $this->assertDoesNotMatchRegularExpression('/crm_|g2g_|product_id|_id\b/', $b['message'], 'no table or column name in a sentence a person reads');
        }
    }

    public function test_the_readiness_history_of_the_about_counts_as_records_that_point_at_it(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $sub = $this->makeSub($payroll, 'PC1', 'Payroll one');
        DB::table('g2g_offer_readiness_log')->insert([['sub_institute_id' => self::T, 'offer_row_id' => $sub['offer']], ['sub_institute_id' => self::T, 'offer_row_id' => $sub['offer']]]);

        $data = $this->preview($sub['master'])->assertOk()->json('data');

        $this->assertSame(['records_point_at_it'], $this->codes($data['blockedBy']));
        $this->assertSame(2, $data['blockedBy'][0]['count']);
        $this->assertStringContainsString('readiness history lines 2', $data['blockedBy'][0]['message']);
    }

    public function test_the_about_s_own_icp_rows_are_not_use_but_an_icp_of_somebody_else_that_lists_it_is(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $sub = $this->makeSub($payroll, 'PC1', 'Payroll one');
        app(OfferIcpSync::class)->sync(self::T, 1, ProductOffer::query()->findOrFail($sub['offer']), ['b2b' => ['label' => 'Own'], 'b2c' => ['label' => 'Own consumer']]);
        $this->assertSame(2, DB::table('g2g_icp_profiles')->count(), 'the writer made the About its own ICP rows, and each lists the offer id');

        $this->assertTrue($this->preview($sub['master'])->assertOk()->json('data.canDelete'), 'its own ICP rows go with it, so they do not block it');

        $this->icp('payroll_cloud_b2b', ['PC1', 'PC9'], 'payroll_cloud');
        $this->assertSame(['offer_in_use'], $this->codes($this->preview($sub['master'])->json('data.blockedBy')));
    }

    public function test_a_product_whose_sub_product_is_blocked_says_so_and_the_sub_product_rows_say_which(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $free = $this->makeSub($payroll, 'PC1', 'A free one');
        $held = $this->makeSold($payroll, 'PC2', 'B held one');
        $this->quoteLineHolds($held['crm']);

        $data = $this->preview($payroll)->assertOk()->json('data');

        $this->assertFalse($data['canDelete']);
        $this->assertSame(['has_sub_products_blocked'], $this->codes($data['blockedBy']));
        $this->assertSame(1, $data['blockedBy'][0]['count']);
        $rows = array_column($data['subProducts'], null, 'id');
        $this->assertTrue($rows[$free['master']]['canDelete']);
        $this->assertFalse($rows[$held['master']]['canDelete']);
        $this->assertSame(['sold_on_documents'], $this->codes($rows[$held['master']]['blockedBy']));
    }

    public function test_a_product_that_records_name_is_blocked_by_them_and_the_line_of_its_sell_rows_is_not_one_of_them(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $this->makeSold($payroll, 'PC1', 'Payroll one');   // its Sell row's product_master_id points at the Product: that is the Sub-product, not a record
        $this->assertTrue($this->preview($payroll)->json('data.canDelete'));

        $this->leadAbout($payroll);

        $data = $this->preview($payroll)->json('data');
        $this->assertSame(['records_point_at_it'], $this->codes($data['blockedBy']));
        $this->assertStringContainsString('leads 1', $data['blockedBy'][0]['message']);
    }

    public function test_the_preview_of_an_id_that_is_not_yours_or_not_there_is_a_404_and_one_you_may_not_delete_is_a_403(): void
    {
        $theirs = $this->makeProduct('Their Product', 'their_line', self::T2);
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $sub = $this->makeSub($payroll, 'PC1', 'Payroll one');

        $this->preview($theirs)->assertStatus(404);
        $this->preview(987654)->assertStatus(404);
        $this->preview($payroll, self::SELLER)->assertStatus(403);
        $this->preview($sub['master'], self::SELLER)->assertStatus(403);
        $this->preview($sub['master'], self::NOBODY)->assertStatus(403);
        $this->getJson("/api/product-master/{$payroll}/removal")->assertStatus(401);
    }

    // ================================================================== deleting a Sub-product (4.3)

    public function test_deleting_a_sub_product_removes_its_master_row_its_about_and_its_own_icp_rows_and_writes_one_history_line(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $sub = $this->makeSub($payroll, 'PC1', 'Payroll one');
        $other = $this->makeSub($payroll, 'PC2', 'Payroll two');
        app(OfferIcpSync::class)->sync(self::T, 1, ProductOffer::query()->findOrFail($sub['offer']), ['b2b' => ['label' => 'Own']]);
        DB::table('g2g_icp_profile_versions')->insert(['sub_institute_id' => self::T, 'icp_profile_id' => (int) DB::table('g2g_icp_profiles')->value('id'), 'version' => 1, 'config' => '{}']);
        $theirs = $this->makeProduct('Their Product', 'their_line', self::T2);
        $theirSub = $this->makeSub($theirs, 'PC1', 'Their payroll one', self::T2);   // the SAME offer id, in another organisation

        $r = $this->remove($sub['master'])->assertOk();

        $r->assertJsonPath('status', 1)->assertJsonPath('data.removed', [$sub['master']])->assertJsonPath('data.moved', [])->assertJsonPath('data.toProductId', null);
        $this->assertNull($this->masterRow($sub['master']));
        $this->assertNull(DB::table('g2g_product_offers')->where('id', $sub['offer'])->first());
        $this->assertSame(0, DB::table('g2g_icp_profiles')->count(), 'its own ICP rows went with it');
        $this->assertSame(0, DB::table('g2g_icp_profile_versions')->count());
        $this->assertNotNull($this->masterRow($other['master']), 'its sibling is untouched');
        $this->assertNotNull($this->masterRow($payroll));
        $this->assertNotNull($this->masterRow($theirSub['master']), 'another organisation\'s Sub-product with the same offer id is untouched');
        $this->assertNotNull(DB::table('g2g_product_offers')->where('id', $theirSub['offer'])->first());

        $removed = $this->events->of('product.removed');
        $this->assertCount(1, $removed);
        $this->assertSame(['g2g_product_master', $sub['master'], self::T], [$removed[0]['entityType'], $removed[0]['entityId'], $removed[0]['tenant']]);
        $this->assertSame('Payroll one', $removed[0]['payload']['name']);
        $this->assertSame('PC1', $removed[0]['payload']['offerId']);
        $this->assertSame('Payroll Cloud', $removed[0]['payload']['parentName']);
        $this->assertSame([], $this->events->of('crm.product.deleted'), 'it had no Sell row');
        $this->assertSame([0], array_values(array_unique($this->events->levels)), 'every line was written after the commit, outside any transaction');
    }

    public function test_the_sell_row_goes_to_the_recycle_bin_and_is_not_deleted_for_good(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $sold = $this->makeSold($payroll, 'PC1', 'Payroll one');

        $this->remove($sold['master'])->assertOk();

        $crm = DB::table('crm_products')->where('id', $sold['crm'])->first();
        $this->assertNotNull($crm, 'the catalogue row is still there: a soft delete');
        $this->assertNotNull($crm->deleted_at, 'in the Recycle Bin');
        $this->assertNotNull($crm->deleted_by, 'and who deleted it');
        $this->assertNull($this->masterRow($sold['master']));
        $binned = $this->events->of('crm.product.deleted');
        $this->assertCount(1, $binned);
        $this->assertSame(['crm_products', $sold['crm']], [$binned[0]['entityType'], $binned[0]['entityId']]);
        $this->assertSame('deleted_at', $binned[0]['payload']['changes'][0]['field']);
        $this->assertSame((int) $crm->deleted_by, $binned[0]['actor'], 'the line names the person who did it');
        $this->assertCount(1, $this->events->of('product.removed'));
    }

    public function test_an_item_that_is_sold_only_is_deleted_the_same_way(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $item = $this->makeItem($payroll, 'Starter kit');

        $this->remove($item['master'], [], self::SELLER)->assertOk()->assertJsonPath('data.removed', [$item['master']]);

        $this->assertNull($this->masterRow($item['master']));
        $this->assertNotNull(DB::table('crm_products')->where('id', $item['crm'])->value('deleted_at'));
    }

    public function test_anything_in_use_blocks_it_with_a_409_naming_what_and_nothing_at_all_is_written(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $sold = $this->makeSold($payroll, 'PC1', 'Payroll one');
        $this->quoteLineHolds($sold['crm'], 2);
        $this->leadAbout($sold['master']);
        $before = $this->snapshot();

        $r = $this->remove($sold['master'])->assertStatus(409);

        $r->assertJsonPath('status', 0)->assertJsonPath('code', 'blocked');
        $this->assertSame(['sold_on_documents', 'records_point_at_it'], $this->codes($r->json('data.blockedBy')));
        $this->assertSame([], $r->json('data.subProducts'));
        $this->assertStringContainsString('archive it instead', $r->json('message'));
        $this->assertEquals($before, $this->snapshot(), 'nothing changed');
        $this->assertNull(DB::table('crm_products')->where('id', $sold['crm'])->value('deleted_at'), 'a Sell row a document holds is never sent to the Recycle Bin');
        $this->assertSame([], $this->events->lines, 'and no history line is written for a delete that did not happen');
    }

    public function test_a_sell_row_in_the_recycle_bin_that_a_document_holds_still_blocks_the_delete(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $sold = $this->makeSold($payroll, 'PC1', 'Payroll one');
        $this->quoteLineHolds($sold['crm']);
        DB::table('crm_products')->where('id', $sold['crm'])->update(['deleted_at' => now()]);

        $this->remove($sold['master'])->assertStatus(409)->assertJsonPath('data.blockedBy.0.code', 'sold_on_documents');
        $this->assertNotNull($this->masterRow($sold['master']), 'the master row is the only link from the document line back to its Product page');
    }

    public function test_each_blocker_alone_blocks(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $cases = [
            'sold_on_documents' => fn (array $s) => $this->priceBookHolds($s['crm']),
            'records_point_at_it' => fn (array $s) => $this->ticketAbout($s['crm']),
            'offer_in_use' => fn (array $s) => $this->icp('payroll_cloud_b2b', ['PC' . $s['n']], 'payroll_cloud'),
        ];
        $n = 0;
        foreach ($cases as $code => $hold) {
            $n++;
            $s = $this->makeSold($payroll, "PC{$n}", "Payroll {$n}") + ['n' => $n];
            $hold($s);
            $this->remove($s['master'])->assertStatus(409)->assertJsonPath('data.blockedBy.0.code', $code);
            $this->assertNotNull($this->masterRow($s['master']), "{$code} blocks");
        }
    }

    public function test_an_id_of_another_organisation_or_one_that_is_not_there_is_a_404_and_changes_nothing(): void
    {
        $theirs = $this->makeProduct('Their Product', 'their_line', self::T2);
        $theirSub = $this->makeSub($theirs, 'Q1', 'Their one', self::T2);
        $before = $this->snapshot();

        $this->remove($theirSub['master'])->assertStatus(404);
        $this->remove($theirs, ['subProducts' => 'delete'])->assertStatus(404);
        $this->remove(987654)->assertStatus(404);

        $this->assertEquals($before, $this->snapshot());
        $this->assertSame([], $this->events->lines);
    }

    // ================================================================== rights, per record

    public function test_the_right_to_delete_is_judged_per_record_by_its_section_and_a_refusal_changes_nothing(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $about = $this->makeSub($payroll, 'PC1', 'About only');
        $both = $this->makeSold($payroll, 'PC2', 'About and Sell');
        $item = $this->makeItem($payroll, 'Sell only');
        $before = $this->snapshot();

        // the Portfolio's right alone (HR) does not reach a Sell row; the Sell right alone (an employee) does not reach an About; nobody with no right reaches anything
        $this->remove($both['master'], [], self::HR)->assertStatus(403)->assertJsonPath('status', 0);
        $this->remove($both['master'], [], self::SELLER)->assertStatus(403);
        $this->remove($about['master'], [], self::SELLER)->assertStatus(403);
        $this->remove($item['master'], [], self::HR)->assertStatus(403);
        foreach ([$payroll, $about['master'], $both['master'], $item['master']] as $id) {
            $this->remove($id, ['subProducts' => 'delete'], self::NOBODY)->assertStatus(403);
        }
        $this->remove($payroll, ['subProducts' => 'delete'], self::SELLER)->assertStatus(403);
        $this->remove($payroll, ['subProducts' => 'delete'], self::HR)->assertStatus(403);
        $this->assertEquals($before, $this->snapshot(), 'a refusal writes nothing');

        // and each one who holds the right does
        $this->remove($about['master'], [], self::HR)->assertOk();
        $this->remove($item['master'], [], self::SELLER)->assertOk();
        $this->remove($both['master'], [], self::ADMIN)->assertOk();
    }

    public function test_a_product_is_an_administrators_to_delete_even_when_everything_under_it_is_the_callers_own_to_delete(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $empty = $this->makeProduct('Empty line', 'empty_line');
        $item = $this->makeItem($payroll, 'Sell only kit');
        $before = $this->snapshot();

        // a seller holds every right on Products and Services: the Sell-only item is the seller's to delete or to move, the Product it sits under is not
        $this->remove($item['master'], [], self::SELLER)->assertOk();
        $item2 = $this->makeItem($payroll, 'Second kit');
        $before = $this->snapshot();
        $this->remove($payroll, ['subProducts' => 'delete'], self::SELLER)->assertStatus(403);
        $this->remove($payroll, ['subProducts' => 'move', 'toProductId' => $hr], self::SELLER)->assertStatus(403);
        $this->remove($empty, [], self::SELLER)->assertStatus(403);
        $this->remove($empty, [], self::HR)->assertStatus(403);
        $this->preview($empty, self::SELLER)->assertStatus(403);

        $this->assertEquals($before, $this->snapshot(), 'a refusal writes nothing');
        $this->assertNotNull($this->masterRow($item2['master']));
        $this->remove($empty, [], self::ADMIN)->assertOk();
    }
    public function test_a_delete_asks_the_delete_action_not_the_edit_action_of_the_right(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $item = $this->makeItem($payroll, 'Sell only');
        DB::table('tbluserprofilemaster')->insert(['id' => 6, 'name' => 'Editor', 'role_key' => 'employee', 'sub_institute_id' => self::T]);
        DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => self::T, 'menu_id' => 468, 'profile_id' => 6, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 0]);
        \Illuminate\Support\Facades\Cache::flush();

        $this->remove($item['master'], [], 6)->assertStatus(403);
        $this->assertNotNull($this->masterRow($item['master']));
    }

    // ================================================================== a Product with Sub-products (D-4)

    public function test_a_product_with_sub_products_is_never_deleted_without_the_answer_to_what_becomes_of_them(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $this->makeSub($payroll, 'PC1', 'Payroll one');
        $before = $this->snapshot();

        $this->remove($payroll)->assertStatus(422)->assertJsonPath('code', 'choose_sub_products')->assertJsonPath('status', 0);
        $this->remove($payroll, ['subProducts' => 'keep'])->assertStatus(422)->assertJsonPath('code', 'choose_sub_products');
        $this->remove($payroll, ['subProducts' => ['delete']])->assertStatus(422)->assertJsonPath('code', 'choose_sub_products');

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_a_product_with_no_sub_product_needs_no_answer(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $this->makeProduct('HR Suite', 'hr_suite');

        $this->remove($payroll)->assertOk()->assertJsonPath('data.removed', [$payroll])->assertJsonPath('data.moved', [])->assertJsonPath('data.toProductId', null);

        $this->assertNull($this->masterRow($payroll));
        $this->assertSame(0, DB::table('g2g_signal_products')->where('key', 'payroll_cloud')->count(), 'its own custom row is gone');
        $this->assertSame(1, DB::table('g2g_signal_products')->where('key', 'hr_suite')->count());
        $removed = $this->events->of('product.removed');
        $this->assertSame('none', $removed[0]['payload']['subProducts']);
    }

    public function test_the_move_choice_moves_every_sub_product_and_then_deletes_the_product(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $a = $this->makeSub($payroll, 'PC1', 'A payroll');
        $b = $this->makeSold($payroll, 'PC2', 'B payroll');
        $item = $this->makeItem($payroll, 'C kit');
        $this->leadAbout($b['master']);   // a record that points at a Sub-product that is MOVED: the pointer stays on its id and does not block the Product's delete
        $this->quoteLineHolds($b['crm']);

        $r = $this->remove($payroll, ['subProducts' => 'move', 'toProductId' => $hr])->assertOk();

        $r->assertJsonPath('data.removed', [$payroll])->assertJsonPath('data.toProductId', $hr);
        $this->assertSame([$a['master'], $b['master'], $item['master']], $r->json('data.moved'));
        $this->assertNull($this->masterRow($payroll));
        foreach ([$a, $b, $item] as $moved) {
            $this->assertSame($hr, (int) $this->masterRow($moved['master'])->parent_id);
        }
        $this->assertSame('HR Suite', DB::table('g2g_product_offers')->where('id', $a['offer'])->value('parent_product'), 'the About follows its Product, so the stores agree');
        $this->assertSame($hr, (int) DB::table('crm_products')->where('id', $b['crm'])->value('product_master_id'));
        $this->assertSame($hr, (int) DB::table('crm_products')->where('id', $item['crm'])->value('product_master_id'));
        $this->assertSame(1, DB::table('crm_leads')->where('product_master_id', $b['master'])->count(), 'a lead keeps pointing at its Sub-product');
        $this->assertSame(1, DB::table('crm_quote_line_items')->where('product_id', $b['crm'])->count(), 'and the quote line at its catalogue row');
        $this->assertNull(DB::table('crm_products')->where('id', $b['crm'])->value('deleted_at'), 'nothing went to the Recycle Bin');
        $this->assertSame(0, DB::table('g2g_signal_products')->where('key', 'payroll_cloud')->count());

        $this->assertCount(3, $this->events->of('product.moved'));
        $removed = $this->events->of('product.removed');
        $this->assertCount(1, $removed);
        $this->assertSame('moved', $removed[0]['payload']['subProducts']);
        $this->assertSame($hr, $removed[0]['payload']['toProductId']);
    }

    public function test_the_move_choice_needs_a_target_that_is_one_of_your_own_active_products_and_not_the_product_itself(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $archived = $this->makeProduct('Old line', 'old_line');
        DB::table('g2g_product_master')->where('id', $archived)->update(['status' => 'archived']);
        $theirs = $this->makeProduct('Their Product', 'their_line', self::T2);
        $sub = $this->makeSub($payroll, 'PC1', 'Payroll one');
        $item = $this->makeItem($payroll, 'Kit');
        $before = $this->snapshot();

        foreach ([null, 0, $payroll, $archived, $sub['master'], $item['master'], 'abc'] as $to) {
            $this->remove($payroll, ['subProducts' => 'move', 'toProductId' => $to])->assertStatus(422)->assertJsonPath('code', 'invalid_target');
        }
        // a number that is not one of YOUR rows (another organisation's, or nobody's) is a 404, like every other id of these routes, and does not say which
        foreach ([$theirs, 987654] as $to) {
            $this->remove($payroll, ['subProducts' => 'move', 'toProductId' => $to])->assertStatus(404)->assertJsonPath('status', 0);
        }
        $this->remove($payroll, ['subProducts' => 'move'])->assertStatus(422)->assertJsonPath('code', 'invalid_target');

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_the_delete_choice_removes_the_sub_products_with_the_product_and_bins_their_sell_rows(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $hrSub = $this->makeSub($hr, 'HR1', 'HR one');
        $a = $this->makeSub($payroll, 'PC1', 'A payroll');
        $b = $this->makeSold($payroll, 'PC2', 'B payroll');
        $item = $this->makeItem($payroll, 'C kit');

        $r = $this->remove($payroll, ['subProducts' => 'delete'])->assertOk();

        $this->assertSame([$payroll, $a['master'], $b['master'], $item['master']], $r->json('data.removed'));
        $this->assertSame([], $r->json('data.moved'));
        $this->assertNull($r->json('data.toProductId'));
        foreach ([$payroll, $a['master'], $b['master'], $item['master']] as $id) {
            $this->assertNull($this->masterRow($id), "master row {$id}");
        }
        $this->assertSame(0, DB::table('g2g_product_offers')->whereIn('id', [$a['offer'], $b['offer']])->count());
        $this->assertNotNull(DB::table('crm_products')->where('id', $b['crm'])->value('deleted_at'));
        $this->assertNotNull(DB::table('crm_products')->where('id', $item['crm'])->value('deleted_at'));
        $this->assertNull(DB::table('crm_products')->where('id', $b['crm'])->value('product_master_id'), 'a Sell row in the Recycle Bin is not left pointing at a Product that is gone');
        $this->assertNotNull($this->masterRow($hrSub['master']), 'a Sub-product of another Product is untouched');
        $this->assertCount(4, $this->events->of('product.removed'));
        $this->assertCount(2, $this->events->of('crm.product.deleted'));
        $this->assertSame([], $this->events->of('product.moved'));
    }

    public function test_the_delete_choice_is_refused_as_a_whole_when_one_sub_product_is_in_use_and_the_move_choice_is_not(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $free = $this->makeSold($payroll, 'PC1', 'A free one');
        $held = $this->makeSold($payroll, 'PC2', 'B held one');
        $this->quoteLineHolds($held['crm']);
        $before = $this->snapshot();

        $r = $this->remove($payroll, ['subProducts' => 'delete'])->assertStatus(409);

        $r->assertJsonPath('code', 'blocked');
        $this->assertSame(['has_sub_products_blocked'], $this->codes($r->json('data.blockedBy')));
        $rows = array_column($r->json('data.subProducts'), null, 'id');
        $this->assertTrue($rows[$free['master']]['canDelete']);
        $this->assertFalse($rows[$held['master']]['canDelete']);
        $this->assertEquals($before, $this->snapshot(), 'the free Sub-product was not deleted either: all or nothing');
        $this->assertNull(DB::table('crm_products')->where('id', $free['crm'])->value('deleted_at'));
        $this->assertSame([], $this->events->lines);

        // the same Product, the same Sub-products: moving them is allowed, because moving loses nothing
        $this->remove($payroll, ['subProducts' => 'move', 'toProductId' => $hr])->assertOk()->assertJsonPath('data.removed', [$payroll]);
    }

    public function test_a_product_that_records_name_cannot_be_deleted_either_way(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $this->makeSub($payroll, 'PC1', 'Payroll one');
        $this->leadAbout($payroll);
        $before = $this->snapshot();

        foreach ([['subProducts' => 'delete'], ['subProducts' => 'move', 'toProductId' => $hr]] as $body) {
            $r = $this->remove($payroll, $body)->assertStatus(409);
            $this->assertSame(['records_point_at_it'], $this->codes($r->json('data.blockedBy')));
        }
        $this->assertEquals($before, $this->snapshot(), 'the move did not happen either');
    }

    public function test_the_icp_rows_of_the_product_line_go_with_it_and_do_not_block_the_sub_products_that_they_list(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $sub = $this->makeSub($payroll, 'PC1', 'Payroll one');
        $line = $this->icp('payroll_cloud_b2b', ['PC1'], 'payroll_cloud');
        $other = $this->icp('hr_suite_b2b', ['HR9'], 'hr_suite');
        $this->assertSame(['offer_in_use'], $this->codes($this->preview($sub['master'])->json('data.blockedBy')), 'on its own, the Sub-product is in use by that ICP');

        $this->assertTrue($this->preview($payroll)->json('data.canDelete'), 'but the ICP goes with the Product, so deleting both together is allowed');
        $this->remove($payroll, ['subProducts' => 'delete'])->assertOk();

        $this->assertNull(DB::table('g2g_icp_profiles')->where('id', $line)->first(), 'the Product\'s own line ICP is gone');
        $this->assertNotNull(DB::table('g2g_icp_profiles')->where('id', $other)->first(), 'another Product\'s ICP is not touched');
        $this->assertNull($this->masterRow($sub['master']));
        $this->assertNotNull($this->masterRow($hr));
    }

    public function test_a_built_in_product_is_deleted_by_the_organisations_deleted_override_and_the_other_organisation_keeps_its_own(): void
    {
        $builtIn = (int) DB::table('g2g_product_master')->insertGetId([
            'sub_institute_id' => self::T, 'product_key' => 'scholarclone', 'kind' => 'product', 'name' => 'ScholarClone', 'status' => 'active', 'origin' => 'builtin',
            'signal_key' => 'scholar', 'portfolio_name' => 'Scholar K-12', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sub = $this->makeSub($builtIn, 'S1', 'Scholar one');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $listedBefore = array_keys(ProductCatalog::definitionsFor(self::T2));
        $this->assertArrayHasKey('scholarclone', ProductCatalog::definitionsFor(self::T), 'before: the organisation lists it');

        $this->remove($builtIn, ['subProducts' => 'move', 'toProductId' => $hr])->assertOk()->assertJsonPath('data.removed', [$builtIn]);

        ProductCatalog::forgetCustom();
        $this->assertNull($this->masterRow($builtIn));
        $override = DB::table('g2g_signal_products')->where('sub_institute_id', self::T)->where('key', 'scholarclone')->first();
        $this->assertNotNull($override, 'the organisation\'s own override row is the only way to delete a Product that code defines');
        $this->assertSame('deleted', $override->status);
        $this->assertSame('scholar', $override->business_fit);
        $this->assertArrayNotHasKey('scholarclone', ProductCatalog::definitionsFor(self::T), 'definitionsFor stops listing it');
        $this->assertSame($listedBefore, array_keys(ProductCatalog::definitionsFor(self::T2)), 'another organisation is not touched');
        $this->assertSame(0, DB::table('g2g_signal_products')->where('sub_institute_id', self::T2)->count());
        $this->assertSame($hr, (int) $this->masterRow($sub['master'])->parent_id, 'its Sub-product lives on under the Product it was moved to');
    }

    public function test_a_built_in_product_the_organisation_already_renamed_keeps_its_override_row_and_marks_it_deleted(): void
    {
        $builtIn = (int) DB::table('g2g_product_master')->insertGetId([
            'sub_institute_id' => self::T, 'product_key' => 'g2g', 'kind' => 'product', 'name' => 'G2G renamed', 'status' => 'active', 'origin' => 'builtin',
            'signal_key' => 'g2g', 'portfolio_name' => 'G2G', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('g2g_signal_products')->insert(['sub_institute_id' => self::T, 'key' => 'g2g', 'label' => 'G2G renamed', 'business_fit' => 'g2g', 'icp_product_line' => 'g2g', 'status' => 'active', 'portfolio_names' => '["G2G renamed"]']);

        $this->remove($builtIn)->assertOk();

        $this->assertSame(1, DB::table('g2g_signal_products')->where('sub_institute_id', self::T)->where('key', 'g2g')->count(), 'one row, not two');
        $this->assertSame('deleted', DB::table('g2g_signal_products')->where('sub_institute_id', self::T)->where('key', 'g2g')->value('status'));
    }

    // ================================================================== all or nothing, in one transaction

    public function test_a_failure_half_way_through_takes_back_everything_that_was_deleted_before_it(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $first = $this->makeSold($payroll, 'PC1', 'A first');
        $second = $this->makeSub($payroll, 'PC2', 'B second');
        $before = $this->snapshot();
        DB::unprepared("CREATE TRIGGER fail_offer_pc2 BEFORE DELETE ON g2g_product_offers WHEN OLD.offer_id = 'PC2' BEGIN SELECT RAISE(ABORT, 'the offer write failed'); END");
        $this->withoutExceptionHandling();

        try {
            $this->remove($payroll, ['subProducts' => 'delete']);
            $this->fail('the second delete was made to fail');
        } catch (QueryException $e) {
            $this->assertStringContainsString('the offer write failed', $e->getMessage());
        }

        $this->assertEquals($before, $this->snapshot(), 'the first Sub-product, its Sell row and the Product are exactly as they were');
        $this->assertNull(DB::table('crm_products')->where('id', $first['crm'])->value('deleted_at'));
        $this->assertNotNull($this->masterRow($first['master']));
        $this->assertNotNull($this->masterRow($second['master']));
        $this->assertSame([], $this->events->lines, 'a delete that was taken back leaves no history line');
    }

    // ================================================================== the ICP rows an About owns hang off nothing when they go

    public function test_the_target_accounts_made_for_an_icp_that_goes_stay_and_lose_the_pointer(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $sub = $this->makeSub($payroll, 'PC1', 'Payroll one');
        app(OfferIcpSync::class)->sync(self::T, 1, ProductOffer::query()->findOrFail($sub['offer']), ['b2b' => ['label' => 'Own']]);
        $icp = (int) DB::table('g2g_icp_profiles')->value('id');
        // the world builds the accounts table before the ICP migration that adds this column to it, so the column is added here, as that migration does on a host
        \Illuminate\Support\Facades\Schema::table('g2g_target_accounts', fn ($t) => $t->unsignedBigInteger('icp_profile_id')->nullable());
        $account = (int) DB::table('g2g_target_accounts')->insertGetId(['sub_institute_id' => self::T, 'pack' => 'sub_pc1', 'account_key' => 'a|b', 'name' => 'A school', 'icp_profile_id' => $icp]);

        $this->remove($sub['master'])->assertOk();

        $this->assertNull(DB::table('g2g_icp_profiles')->where('id', $icp)->first());
        $this->assertNotNull(DB::table('g2g_target_accounts')->where('id', $account)->first(), 'the account is history, not use: it stays');
        $this->assertNull(DB::table('g2g_target_accounts')->where('id', $account)->value('icp_profile_id'));
    }

    // ================================================================== all or nothing: the second of three is blocked

    /** @return iterable<string, array{0: string}> */
    public static function whatBlocksTheSecondOfThree(): iterable
    {
        yield 'a document holds its Sell row' => ['sold_on_documents'];
        yield 'a record points at it' => ['records_point_at_it'];
        yield 'an ICP lists it' => ['offer_in_use'];
    }

    #[DataProvider('whatBlocksTheSecondOfThree')]
    public function test_when_the_second_of_three_sub_products_is_blocked_the_first_and_the_third_are_untouched_and_the_409_names_the_blocked_one(string $code): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $first = $this->makeSold($payroll, 'PC1', 'A first');
        $second = $this->makeSold($payroll, 'PC2', 'B second held');
        $third = $this->makeSold($payroll, 'PC3', 'C third');
        app(OfferIcpSync::class)->sync(self::T, 1, ProductOffer::query()->findOrFail($first['offer']), ['b2b' => ['label' => 'First own']]);
        app(OfferIcpSync::class)->sync(self::T, 1, ProductOffer::query()->findOrFail($third['offer']), ['b2b' => ['label' => 'Third own']]);
        match ($code) {
            'sold_on_documents' => $this->quoteLineHolds($second['crm']),
            'records_point_at_it' => $this->ticketAbout($second['crm']),
            'offer_in_use' => $this->icp('payroll_cloud_b2b', ['PC2'], 'other_line'),
        };
        $before = $this->snapshot();

        $r = $this->remove($payroll, ['subProducts' => 'delete'])->assertStatus(409);

        $r->assertJsonPath('status', 0)->assertJsonPath('code', 'blocked');
        $rows = $r->json('data.subProducts');
        $this->assertSame([$first['master'], $second['master'], $third['master']], array_column($rows, 'id'), 'all three are listed, in order');
        $this->assertSame([true, false, true], array_column($rows, 'canDelete'), 'and the one that is blocked is the second');
        $this->assertSame([$code], array_column($rows[1]['blockedBy'], 'code'), 'with what blocks it');
        $this->assertSame('B second held', $rows[1]['name']);
        $this->assertSame(['has_sub_products_blocked'], $this->codes($r->json('data.blockedBy')));
        $this->assertStringContainsString('B second held', $r->json('data.blockedBy.0.message'), 'the sentence names the blocked Sub-product');
        $this->assertEquals($before, $this->snapshot(), 'rows, About, ICP rows, Sell rows: exactly as they were');
        foreach ([$first, $third] as $free) {
            $this->assertNotNull($this->masterRow($free['master']));
            $this->assertNotNull(DB::table('g2g_product_offers')->where('id', $free['offer'])->first());
            $this->assertNull(DB::table('crm_products')->where('id', $free['crm'])->value('deleted_at'), 'its Sell row is not in the Recycle Bin');
        }
        $this->assertSame(2, DB::table('g2g_icp_profiles')->whereIn('key', ['sub_pc1', 'sub_pc3'])->count(), 'the ICP rows of the free ones are still there');
        $this->assertSame([], $this->events->lines, 'and no history line');

        // the same three, one at a time: the first and the third can go on their own, the second cannot
        $this->remove($second['master'])->assertStatus(409)->assertJsonPath('data.blockedBy.0.code', $code);
        $this->remove($first['master'])->assertOk();
        $this->remove($third['master'])->assertOk();
        $this->assertNotNull($this->masterRow($second['master']));
    }

    // ================================================================== the screen shows only `message`: it alone must say what blocks it

    public function test_the_409_message_alone_names_every_blocker_of_a_sub_product(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $sold = $this->makeSold($payroll, 'PC1', 'Payroll one');
        $this->quoteLineHolds($sold['crm'], 2);
        $this->priceBookHolds($sold['crm']);
        $this->ticketAbout($sold['crm']);
        $this->leadAbout($sold['master']);
        $this->icp('payroll_cloud_b2b', ['PC1'], 'payroll_cloud');

        $message = (string) $this->remove($sold['master'])->assertStatus(409)->json('message');

        foreach (['quote lines 2', 'price book items 1', 'tickets 1', 'leads 1', 'ICP payroll_cloud_b2b', 'archive it instead'] as $part) {
            $this->assertStringContainsString($part, $message, "the message alone says: {$part}");
        }
        $this->assertDoesNotMatchRegularExpression('/crm_|g2g_|product_id/', $message, 'and in words a person reads');
    }

    public function test_the_409_message_alone_names_what_blocks_a_product_and_each_blocked_sub_product_and_not_the_free_ones(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $this->makeSold($payroll, 'PC1', 'A free one');
        $held = $this->makeSold($payroll, 'PC2', 'B held by a quote');
        $watched = $this->makeSold($payroll, 'PC3', 'C held by a ticket');
        $this->quoteLineHolds($held['crm']);
        $this->ticketAbout($watched['crm']);
        $this->leadAbout($payroll);

        $message = (string) $this->remove($payroll, ['subProducts' => 'delete'])->assertStatus(409)->json('message');

        $this->assertStringContainsString('This Product is in use', $message);
        $this->assertStringContainsString('leads 1', $message, 'what blocks the Product itself');
        $this->assertStringContainsString('B held by a quote', $message, 'each blocked Sub-product by name');
        $this->assertStringContainsString('C held by a ticket', $message);
        $this->assertStringNotContainsString('A free one', $message, 'and not the one that is free');
        $this->assertStringContainsString('2 of its Sub-products are in use', $message);
        $this->assertStringContainsString('archive it instead', $message);

        // with the move choice only what blocks the Product itself is in the way, and the message says only that
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $move = (string) $this->remove($payroll, ['subProducts' => 'move', 'toProductId' => $hr])->assertStatus(409)->json('message');
        $this->assertStringContainsString('leads 1', $move);
        $this->assertStringNotContainsString('B held by a quote', $move);
    }

    public function test_the_same_three_with_the_move_choice_all_move_because_what_blocks_a_delete_does_not_block_a_move(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $first = $this->makeSold($payroll, 'PC1', 'A first');
        $second = $this->makeSold($payroll, 'PC2', 'B second held');
        $third = $this->makeSold($payroll, 'PC3', 'C third');
        $this->quoteLineHolds($second['crm']);

        $this->remove($payroll, ['subProducts' => 'move', 'toProductId' => $hr])->assertOk()->assertJsonPath('data.moved', [$first['master'], $second['master'], $third['master']]);

        foreach ([$first, $second, $third] as $moved) {
            $this->assertSame($hr, (int) $this->masterRow($moved['master'])->parent_id);
        }
    }

    // ================================================================== history lines go through the recorder, after the commit, and never fail the delete

    public function test_a_history_line_that_cannot_be_written_never_undoes_the_delete(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $sub = $this->makeSub($payroll, 'PC1', 'Payroll one');
        $this->events->failWith = 'the event store is down';

        $this->remove($sub['master'])->assertOk();

        $this->assertNull($this->masterRow($sub['master']), 'the delete is done; the line is a claim about it, made afterwards');
    }

    public function test_the_move_choice_writes_one_moved_line_per_sub_product_with_the_names_from_and_to(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $a = $this->makeSub($payroll, 'PC1', 'A payroll');

        $this->remove($payroll, ['subProducts' => 'move', 'toProductId' => $hr])->assertOk();

        $moved = $this->events->of('product.moved');
        $this->assertCount(1, $moved);
        $this->assertSame(['g2g_product_master', $a['master']], [$moved[0]['entityType'], $moved[0]['entityId']]);
        $this->assertSame(['from' => 'Payroll Cloud', 'to' => 'HR Suite', 'fromId' => $payroll, 'toId' => $hr], array_intersect_key($moved[0]['payload'], array_flip(['from', 'to', 'fromId', 'toId'])));
        $this->assertSame([['field' => 'product', 'oldValue' => 'Payroll Cloud', 'newValue' => 'HR Suite']], $moved[0]['payload']['changes']);
        $this->assertSame([0], array_values(array_unique($this->events->levels)), 'the moved line and the removed line were written after the commit');
    }
}
