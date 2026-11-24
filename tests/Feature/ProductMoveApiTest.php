<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsProductRemovalWorld;
use Tests\TestCase;

/**
 * Moving one or several Sub-products under another Product (S15 follow-up, 24 Nov 2026; contract section 4.2, decisions D-6 and D-8):
 *
 *   POST /api/product-master/move   {subProductIds: [..], toProductId: n}
 *
 * The move is written by the one path the writer uses when a form changes the Product (ProductMasterSync::mirror), so the master row's `parent_id`, the About's `parent_product`
 * and the Sell item's product line always agree; the checker's I5 is asserted after each move that has an About or a Sell side. The world has no shared row
 * (BuildsProductRemovalWorld).
 */
class ProductMoveApiTest extends TestCase
{
    use BuildsProductRemovalWorld;

    private const T = 1;

    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildProductRemovalWorld();
    }

    private function move(array $ids, mixed $to, int $profile = self::ADMIN): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/product-master/move', ['subProductIds' => $ids, 'toProductId' => $to], $this->who($profile));
    }

    public function test_a_sub_product_moves_and_its_master_row_its_about_and_its_sell_item_all_say_the_same_product(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $sold = $this->makeSold($payroll, 'PC1', 'Payroll one');
        $this->assertChecker('before the move');

        $r = $this->move([$sold['master']], $hr)->assertOk();

        $r->assertJsonPath('status', 1)->assertJsonPath('data', ['moved' => [$sold['master']], 'unchanged' => [], 'toProductId' => $hr]);
        $this->assertSame($hr, (int) $this->masterRow($sold['master'])->parent_id);
        $this->assertSame('HR Suite', DB::table('g2g_product_offers')->where('id', $sold['offer'])->value('parent_product'), 'the About\'s group follows (the checker\'s I5)');
        $this->assertSame($hr, (int) DB::table('crm_products')->where('id', $sold['crm'])->value('product_master_id'), 'and so does the Sell item\'s product line');
        $this->assertSame('hr_suite/pc1', $this->masterRow($sold['master'])->product_key, 'the key follows the Product, as the Portfolio always did');
        $this->assertChecker('after the move');
    }

    public function test_the_pointers_of_a_sub_product_do_not_change_when_it_moves(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $sold = $this->makeSold($payroll, 'PC1', 'Payroll one');
        $lead = $this->leadAbout($sold['master']);
        $this->ticketAbout($sold['crm']);
        $this->quoteLineHolds($sold['crm']);

        $this->move([$sold['master']], $hr)->assertOk();

        $this->assertSame($sold['master'], (int) DB::table('crm_leads')->where('id', $lead)->value('product_master_id'), 'a lead keeps pointing at its Sub-product');
        $this->assertSame(1, DB::table('crm_tickets')->where('product_id', $sold['crm'])->count());
        $this->assertSame(1, DB::table('crm_quote_line_items')->where('product_id', $sold['crm'])->count());
    }

    public function test_several_move_at_once_and_one_that_is_already_there_is_reported_unchanged(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $a = $this->makeSub($payroll, 'PC1', 'A payroll');
        $item = $this->makeItem($payroll, 'B kit');
        $there = $this->makeSub($hr, 'HR1', 'C already there');

        $r = $this->move([$a['master'], $there['master'], $item['master']], $hr)->assertOk();

        $this->assertSame([$a['master'], $item['master']], $r->json('data.moved'));
        $this->assertSame([$there['master']], $r->json('data.unchanged'));
        $this->assertSame($hr, $r->json('data.toProductId'));
        foreach ([$a['master'], $item['master'], $there['master']] as $id) {
            $this->assertSame($hr, (int) $this->masterRow($id)->parent_id);
        }
        $this->assertSame($hr, (int) DB::table('crm_products')->where('id', $item['crm'])->value('product_master_id'), 'an item with only a Sell side moves through its catalogue row');
        $this->assertCount(2, $this->events->of('product.moved'), 'one line per Sub-product that moved, none for the one that did not');
        $this->assertChecker('after a move of several');
    }

    public function test_moving_to_the_product_it_is_under_is_a_no_op_that_writes_and_records_nothing(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $a = $this->makeSold($payroll, 'PC1', 'A payroll');
        $stamp = $this->masterRow($a['master'])->updated_at;
        $before = $this->snapshot();

        $r = $this->move([$a['master']], $payroll)->assertOk();

        $r->assertJsonPath('data', ['moved' => [], 'unchanged' => [$a['master']], 'toProductId' => $payroll]);
        $this->assertEquals($before, $this->snapshot());
        $this->assertSame($stamp, $this->masterRow($a['master'])->updated_at);
        $this->assertSame([], $this->events->lines);
    }

    public function test_one_moved_line_is_written_per_sub_product_with_the_names_from_and_to(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $a = $this->makeSub($payroll, 'PC1', 'A payroll');

        $this->move([$a['master']], $hr)->assertOk();

        $moved = $this->events->of('product.moved');
        $this->assertCount(1, $moved);
        $this->assertSame(['g2g_product_master', $a['master'], self::T], [$moved[0]['entityType'], $moved[0]['entityId'], $moved[0]['tenant']]);
        $this->assertEquals(['from' => 'Payroll Cloud', 'to' => 'HR Suite', 'fromId' => $payroll, 'toId' => $hr, 'name' => 'A payroll', 'kind' => 'sub_product'], array_intersect_key($moved[0]['payload'], array_flip(['from', 'to', 'fromId', 'toId', 'name', 'kind'])));
        $this->assertSame([['field' => 'product', 'oldValue' => 'Payroll Cloud', 'newValue' => 'HR Suite']], $moved[0]['payload']['changes']);
        $this->assertGreaterThan(0, $moved[0]['actor'], 'the person who moved it is named');
        $this->assertSame([0], array_values(array_unique($this->events->levels)), 'written after the commit, outside any transaction');
    }

    public function test_the_target_must_be_an_active_product_of_your_own_and_a_bad_one_moves_nothing(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $archived = $this->makeProduct('Old line', 'old_line');
        DB::table('g2g_product_master')->where('id', $archived)->update(['status' => 'archived']);
        $theirs = $this->makeProduct('Their Product', 'their_line', self::T2);
        $a = $this->makeSub($payroll, 'PC1', 'A payroll');
        $b = $this->makeSub($payroll, 'PC2', 'B payroll');
        $item = $this->makeItem($payroll, 'Kit');
        $before = $this->snapshot();

        // an ITEM (no About) moved under a Sub-product: the mirror would write that id as its Product without a word, so only this check stops it
        $this->move([$item['master']], $a['master'])->assertStatus(422)->assertJsonPath('code', 'invalid_target');
        $this->move([$item['master']], $item['master'])->assertStatus(422)->assertJsonPath('code', 'invalid_target');
        foreach ([null, 0, -3, $archived, $a['master'], 'abc', [1]] as $to) {
            $this->move([$a['master']], $to)->assertStatus(422)->assertJsonPath('code', 'invalid_target')->assertJsonPath('status', 0);
        }
        // a number that is not one of YOUR rows (another organisation's, or nobody's) is a 404, like every other id of these routes, and does not say which
        foreach ([$theirs, 987654] as $to) {
            $this->move([$a['master']], $to)->assertStatus(404)->assertJsonPath('status', 0);
        }
        $this->postJson('/api/product-master/move', ['subProductIds' => [$a['master']]], $this->who(self::ADMIN))->assertStatus(422)->assertJsonPath('code', 'invalid_target');

        $this->assertEquals($before, $this->snapshot());
        $this->assertSame([], $this->events->lines);
        $this->assertSame($payroll, (int) $this->masterRow($b['master'])->parent_id);
    }

    public function test_an_empty_or_missing_list_is_nothing_to_move_and_a_list_that_is_too_long_is_refused(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');

        $this->move([], $hr)->assertStatus(422)->assertJsonPath('code', 'nothing_to_move');
        $this->postJson('/api/product-master/move', ['toProductId' => $hr], $this->who(self::ADMIN))->assertStatus(422)->assertJsonPath('code', 'nothing_to_move');
        $this->postJson('/api/product-master/move', ['subProductIds' => 'all', 'toProductId' => $hr], $this->who(self::ADMIN))->assertStatus(422)->assertJsonPath('code', 'nothing_to_move');
        $this->move(range(1, 501), $hr)->assertStatus(422)->assertJsonPath('code', 'too_many');
        $this->assertNotNull($this->masterRow($payroll));
    }

    public function test_an_id_that_is_not_yours_or_not_there_is_a_404_for_the_whole_call_and_nothing_moves(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $mine = $this->makeSub($payroll, 'PC1', 'Mine');
        $theirs = $this->makeProduct('Their Product', 'their_line', self::T2);
        $theirSub = $this->makeSub($theirs, 'Q1', 'Theirs', self::T2);
        $before = $this->snapshot();

        $this->move([$mine['master'], $theirSub['master']], $hr)->assertStatus(404)->assertJsonPath('status', 0);
        $this->move([$mine['master'], 987654], $hr)->assertStatus(404);
        $this->move([$mine['master'], 'abc'], $hr)->assertStatus(404);
        $this->move([$theirSub['master']], $hr)->assertStatus(404);

        $this->assertEquals($before, $this->snapshot());
        $this->assertSame([], $this->events->lines);
    }

    public function test_a_product_cannot_be_moved_under_another_product(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');

        $this->move([$payroll], $hr)->assertStatus(422)->assertJsonPath('code', 'not_a_sub_product');

        $this->assertNull($this->masterRow($payroll)->parent_id);
    }

    public function test_the_right_is_judged_for_every_record_before_the_first_write_and_one_refusal_moves_none(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $about = $this->makeSub($payroll, 'PC1', 'About only');
        $item = $this->makeItem($payroll, 'Sell only');
        $before = $this->snapshot();

        // the Sell right alone does not move an About (the Portfolio's); the Portfolio's right alone does not move a Sell-only item; one refusal in the list moves none of it
        $this->move([$about['master']], $hr, self::SELLER)->assertStatus(403)->assertJsonPath('status', 0);
        $this->move([$item['master']], $hr, self::HR)->assertStatus(403);
        $this->move([$item['master'], $about['master']], $hr, self::SELLER)->assertStatus(403);
        $this->move([$about['master'], $item['master']], $hr, self::HR)->assertStatus(403);
        $this->move([$about['master']], $hr, self::NOBODY)->assertStatus(403);
        $this->assertEquals($before, $this->snapshot(), 'a refusal writes nothing');
        $this->assertSame([], $this->events->lines);

        // and each person who holds the right moves what it covers
        $this->move([$about['master']], $hr, self::HR)->assertOk();
        $this->move([$item['master']], $hr, self::SELLER)->assertOk();
    }

    public function test_a_sub_product_moves_by_the_edit_right_so_a_person_who_may_only_delete_cannot_move_it(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $item = $this->makeItem($payroll, 'Sell only');
        DB::table('tbluserprofilemaster')->insert(['id' => 6, 'name' => 'Deleter', 'role_key' => 'employee', 'sub_institute_id' => self::T]);
        DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => self::T, 'menu_id' => 468, 'profile_id' => 6, 'can_view' => 1, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 1]);
        \Illuminate\Support\Facades\Cache::flush();

        $this->move([$item['master']], $hr, 6)->assertStatus(403);

        $this->assertSame($payroll, (int) $this->masterRow($item['master'])->parent_id);
    }

    public function test_a_product_with_no_portfolio_name_cannot_take_a_sub_product_that_has_an_about_and_nothing_is_written(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $bare = (int) DB::table('g2g_product_master')->insertGetId(['sub_institute_id' => self::T, 'product_key' => 'bare', 'kind' => 'product', 'name' => 'Bare', 'status' => 'active', 'origin' => 'manual', 'portfolio_name' => null, 'created_at' => now(), 'updated_at' => now()]);
        $a = $this->makeSub($payroll, 'PC1', 'A payroll');
        $before = $this->snapshot();

        $this->move([$a['master']], $bare)->assertStatus(422)->assertJsonPath('code', 'invalid_target');

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_a_failure_half_way_takes_back_the_sub_products_already_moved(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $first = $this->makeSub($payroll, 'PC1', 'A first');
        $second = $this->makeSub($payroll, 'PC2', 'B second');
        $before = $this->snapshot();
        DB::unprepared("CREATE TRIGGER fail_offer_pc2 BEFORE UPDATE ON g2g_product_offers WHEN OLD.offer_id = 'PC2' BEGIN SELECT RAISE(ABORT, 'the offer write failed'); END");
        $this->withoutExceptionHandling();

        try {
            $this->move([$first['master'], $second['master']], $hr);
            $this->fail('the second move was made to fail');
        } catch (QueryException $e) {
            $this->assertStringContainsString('the offer write failed', $e->getMessage());
        }

        $this->assertEquals($before, $this->snapshot(), 'the first Sub-product is under its old Product again, in all three stores');
        $this->assertSame([], $this->events->lines, 'and no line says it moved');
    }

    /** @return iterable<string, array{0: string}> */
    public static function whatStopsTheSecondOfThree(): iterable
    {
        yield 'the caller may not move it (a Sell-only item, and the caller is HR)' => ['right'];
        yield 'it is another organisation\'s' => ['other_organisation'];
        yield 'it is a Product, not a Sub-product' => ['a_product'];
        yield 'the database fails while it is written' => ['database'];
    }

    #[DataProvider('whatStopsTheSecondOfThree')]
    public function test_when_the_second_of_three_cannot_move_the_first_and_the_third_stay_where_they_were_in_every_store_and_no_line_is_written(string $why): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $first = $this->makeSold($payroll, 'PC1', 'A first');
        $third = $this->makeSold($payroll, 'PC3', 'C third');
        $theirs = $this->makeProduct('Their Product', 'their_line', self::T2);
        $second = match ($why) {
            'right' => $this->makeItem($payroll, 'B second kit'),
            'other_organisation' => $this->makeSub($theirs, 'Q2', 'B second theirs', self::T2),
            'a_product' => ['master' => $this->makeProduct('Third line', 'third_line')],
            'database' => $this->makeSub($payroll, 'PC2', 'B second'),
        };
        // HR holds the Portfolio's right but no Sell right: the two Sub-products with an About are its to move, the Sell-only item in the middle is not
        $caller = $why === 'right' ? self::HR : self::ADMIN;
        if ($why === 'database') {
            DB::unprepared("CREATE TRIGGER fail_offer_pc2 BEFORE UPDATE ON g2g_product_offers WHEN OLD.offer_id = 'PC2' BEGIN SELECT RAISE(ABORT, 'the offer write failed'); END");
            $this->withoutExceptionHandling();
        }
        $before = $this->snapshot();

        $failed = null;
        $response = null;
        try {
            $response = $this->move([$first['master'], $second['master'], $third['master']], $hr, $caller);
        } catch (QueryException $e) {
            $failed = $e;
        }

        if ($why === 'database') {
            $this->assertNotNull($failed, 'the write was made to fail');
        } else {
            $this->assertNull($failed);
            $response->assertStatus(['right' => 403, 'other_organisation' => 404, 'a_product' => 422][$why])->assertJsonPath('status', 0);
        }
        $this->assertEquals($before, $this->snapshot(), 'rows, About, Sell rows: nothing was written for any of the three');
        foreach ([$first, $third] as $kept) {
            $this->assertSame($payroll, (int) $this->masterRow($kept['master'])->parent_id);
            $this->assertSame('Payroll Cloud', DB::table('g2g_product_offers')->where('id', $kept['offer'])->value('parent_product'));
            $this->assertSame($payroll, (int) DB::table('crm_products')->where('id', $kept['crm'])->value('product_master_id'));
        }
        $this->assertSame([], $this->events->lines, 'and no history line says they moved');
    }

    public function test_a_history_line_that_cannot_be_written_never_undoes_the_move(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $a = $this->makeSub($payroll, 'PC1', 'A payroll');
        $this->events->failWith = 'the event store is down';

        $this->move([$a['master']], $hr)->assertOk();

        $this->assertSame($hr, (int) $this->masterRow($a['master'])->parent_id);
    }

    public function test_a_moved_sub_product_shows_under_its_new_product_in_the_list_and_the_archive_still_works(): void
    {
        $payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $a = $this->makeSub($payroll, 'PC1', 'A payroll');
        $this->move([$a['master']], $hr)->assertOk();

        $rows = collect($this->getJson('/api/product-master/list?per=100', $this->who(self::ADMIN))->assertOk()->json('data'));
        $row = $rows->firstWhere('id', $a['master']);

        $this->assertSame([$hr, 'HR Suite'], [$row['productId'], $row['productName']]);
        $this->postJson("/api/product-master/{$a['master']}/archive", [], $this->who(self::ADMIN))->assertOk();
    }
}
