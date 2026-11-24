<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsProductRemovalWorld;
use Tests\TestCase;

/**
 * The pickers: every active Product and Sub-product can be chosen (S15 follow-up, 24 Nov 2026; contract section 4.4, decision D-7).
 *
 *   GET /api/product-master/picker?scope=all          every active Sub-product and item of the organisation, sold or not, with `sold` and `state`, a null `crmProductId` where there
 *                                                      is no catalogue row; the default scope (`sold`) is what it always was and does not change because the parameter exists
 *   GET /api/product-master/lines?withSubProducts=1   every active Product and, right after it, its active Sub-products and items
 *
 * The organisation's own rows only (D-1), and what a caller may SEE of the Sell side is the rule of every read (ProductReadRights). The world has no shared row.
 */
class ProductPickerScopeTest extends TestCase
{
    use BuildsProductRemovalWorld;

    private const T = 1;

    private const T2 = 2;

    /** the profile that may view Products only (no Services) */
    private const PRODUCTS_ONLY = 6;

    /** @var array<string, array<string, int>> */
    private array $rows = [];

    private int $payroll;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildProductRemovalWorld();
        DB::table('tbluserprofilemaster')->insert(['id' => self::PRODUCTS_ONLY, 'name' => 'Products viewer', 'role_key' => 'employee', 'sub_institute_id' => self::T]);
        DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => self::T, 'menu_id' => 468, 'profile_id' => self::PRODUCTS_ONLY, 'can_view' => 1]);
        Cache::flush();

        $this->payroll = $this->makeProduct('Payroll Cloud', 'payroll_cloud');
        $this->rows['sold'] = $this->makeSold($this->payroll, 'PC1', 'Payroll sold');
        DB::table('crm_products')->where('id', $this->rows['sold']['crm'])->update(['sku' => 'PC1-SKU', 'unit_price' => 100, 'currency' => 'INR', 'tax_rate_id' => 3]);
        $this->rows['paused'] = $this->makeSold($this->payroll, 'PC2', 'Payroll paused');
        DB::table('crm_products')->where('id', $this->rows['paused']['crm'])->update(['is_active' => 0, 'sku' => 'PC2-SKU', 'unit_price' => 200]);
        $this->rows['about'] = $this->makeSub($this->payroll, 'PC3', 'Payroll about only');
        $this->rows['binned'] = $this->makeSold($this->payroll, 'PC4', 'Payroll binned');
        DB::table('crm_products')->where('id', $this->rows['binned']['crm'])->update(['deleted_at' => now(), 'sku' => 'PC4-SKU']);
        $this->rows['archived'] = $this->makeSold($this->payroll, 'PC5', 'Payroll archived');
        DB::table('g2g_product_master')->where('id', $this->rows['archived']['master'])->update(['status' => 'archived']);
        $this->rows['kit'] = $this->makeItem($this->payroll, 'Kit');
        DB::table('crm_products')->where('id', $this->rows['kit']['crm'])->update(['sku' => 'KIT-1', 'unit_price' => 50]);
        $this->rows['dead'] = $this->makeItem($this->payroll, 'Dead kit');
        DB::table('crm_products')->where('id', $this->rows['dead']['crm'])->update(['deleted_at' => now()]);
        $this->rows['service'] = $this->makeItem($this->payroll, 'Consulting', 'service');
        DB::table('crm_products')->where('id', $this->rows['service']['crm'])->update(['sku' => 'SVC-1', 'unit_price' => 900]);

        // another organisation: never offered here
        $theirs = $this->makeProduct('Their Product', 'their_line', self::T2);
        $this->makeSold($theirs, 'Q1', 'Their sold', self::T2);
    }

    /** @return list<array<string, mixed>> */
    private function pick(string $query, int $profile = self::ADMIN): array
    {
        return $this->getJson('/api/product-master/picker' . ($query === '' ? '' : '?' . $query), $this->who($profile))->assertOk()->json('data');
    }

    /** @return list<string> */
    private function names(string $query, int $profile = self::ADMIN): array
    {
        return array_column($this->pick($query, $profile), 'name');
    }

    // ================================================================== the default scope does not change

    public function test_the_default_scope_offers_exactly_the_sold_rows_with_the_old_shape_and_scope_all_does_not_leak_into_it(): void
    {
        $default = $this->pick('');

        $this->assertEqualsCanonicalizing(['Payroll sold', 'Kit', 'Consulting'], array_column($default, 'name'), 'sold, active, not binned, catalogue-backed: nothing else');
        $this->assertSame(['crmProductId', 'masterId', 'name', 'productName', 'sku', 'unitPrice', 'currency', 'taxRateId', 'type'], array_keys($default[0]), 'no sold or state key in the default answer');
        $this->assertSame($default, $this->pick('scope=sold'), 'scope=sold is the default');
        $this->assertSame($default, $this->pick('scope='), 'an empty scope is the default too');
        $this->assertEqualsCanonicalizing(['Payroll sold', 'Kit'], $this->names('type=product'));
        $this->assertSame(['Consulting'], $this->names('type=service'));
        // what scope=all would add is not there
        foreach (['Payroll paused', 'Payroll about only', 'Payroll binned', 'Payroll archived', 'Dead kit'] as $never) {
            $this->assertNotContains($never, $this->names(''));
        }
    }

    public function test_the_default_scope_still_refuses_a_caller_who_may_view_no_prices(): void
    {
        $this->getJson('/api/product-master/picker', $this->who(self::HR))->assertStatus(403);
        $this->getJson('/api/product-master/picker?scope=sold', $this->who(self::NOBODY))->assertStatus(403);
        $this->getJson('/api/product-master/picker?type=hardware', $this->who(self::ADMIN))->assertStatus(422);
    }

    public function test_an_unknown_scope_is_a_422_and_not_a_silent_default(): void
    {
        $this->getJson('/api/product-master/picker?scope=everything', $this->who(self::ADMIN))->assertStatus(422)->assertJsonPath('status', 0)->assertJsonPath('errors.scope.0', 'scope must be sold or all.');
        $this->getJson('/api/product-master/picker?scope=ALL', $this->who(self::ADMIN))->assertOk();
    }

    // ================================================================== scope=all

    public function test_scope_all_offers_every_active_sub_product_and_item_sold_or_not_with_its_state(): void
    {
        $rows = $this->pick('scope=all&limit=50');

        $byName = array_column($rows, null, 'name');
        $this->assertEqualsCanonicalizing(['Payroll sold', 'Payroll paused', 'Payroll about only', 'Payroll binned', 'Kit', 'Consulting'], array_keys($byName));
        $this->assertSame(['crmProductId', 'masterId', 'name', 'productName', 'sku', 'unitPrice', 'currency', 'taxRateId', 'type', 'sold', 'state'], array_keys($rows[0]));

        $sold = $byName['Payroll sold'];
        $this->assertEquals([true, 'sold', $this->rows['sold']['crm'], $this->rows['sold']['master'], 'Payroll Cloud', 'PC1-SKU', 100.0, 'INR', 3, 'product'],
            [$sold['sold'], $sold['state'], $sold['crmProductId'], $sold['masterId'], $sold['productName'], $sold['sku'], $sold['unitPrice'], $sold['currency'], $sold['taxRateId'], $sold['type']]);

        $paused = $byName['Payroll paused'];
        $this->assertSame([false, 'paused', $this->rows['paused']['crm']], [$paused['sold'], $paused['state'], $paused['crmProductId']], 'a paused row has a catalogue row but is not sold');

        $about = $byName['Payroll about only'];
        $this->assertSame([false, 'not_sold', null, null, null, $this->rows['about']['master'], 'Payroll Cloud'], [$about['sold'], $about['state'], $about['crmProductId'], $about['unitPrice'], $about['type'], $about['masterId'], $about['productName']],
            'no catalogue row: a null crmProductId and nothing priced, but it is a Sub-product that can be chosen by its master id');

        $binned = $byName['Payroll binned'];
        $this->assertSame([false, 'not_sold', null, null, null], [$binned['sold'], $binned['state'], $binned['crmProductId'], $binned['sku'], $binned['unitPrice']], 'a catalogue row in the Recycle Bin is never handed out as a number to hold');

        $this->assertSame([true, 'sold', 'product'], [$byName['Kit']['sold'], $byName['Kit']['state'], $byName['Kit']['type']]);
        $this->assertSame([true, 'sold', 'service'], [$byName['Consulting']['sold'], $byName['Consulting']['state'], $byName['Consulting']['type']]);
    }

    public function test_scope_all_never_offers_an_archived_row_an_item_in_the_recycle_bin_a_product_or_another_organisations_row(): void
    {
        $names = $this->names('scope=all&limit=50');

        $this->assertNotContains('Payroll archived', $names);
        $this->assertNotContains('Dead kit', $names, 'a catalogue-only item in the Recycle Bin is not offered');
        $this->assertNotContains('Payroll Cloud', $names, 'a Product (level 1) is not a row of the picker; the Product box reads /lines');
        $this->assertNotContains('Their sold', $names);
        $this->assertNotContains('Their Product', $names);
        $masterIds = array_column($this->pick('scope=all&limit=50'), 'masterId');
        $this->assertSame([], array_values(array_intersect($masterIds, DB::table('g2g_product_master')->where('sub_institute_id', self::T2)->pluck('id')->all())));
    }

    public function test_scope_all_filters_by_type_and_searches_by_name_and_by_sku_for_a_caller_who_may_view_it(): void
    {
        $this->assertSame(['Consulting'], $this->names('scope=all&type=service'));
        $this->assertEqualsCanonicalizing(['Payroll sold', 'Payroll paused', 'Payroll about only', 'Payroll binned', 'Kit'], $this->names('scope=all&type=product&limit=50'), 'a Sub-product with no catalogue row is not a service');
        $this->assertEqualsCanonicalizing(['Payroll sold', 'Payroll paused', 'Payroll about only', 'Payroll binned', 'Kit'], $this->names('scope=all&type=subproduct&limit=50'));
        $this->assertSame(['Payroll about only'], $this->names('scope=all&q=about'));
        $this->assertSame(['Payroll paused'], $this->names('scope=all&q=PC2-SKU'), 'by SKU');
        $this->assertSame([], $this->names('scope=all&q=nothing-has-this'));
        $this->assertCount(2, $this->pick('scope=all&limit=2'));
        $this->assertCount(1, $this->pick('scope=all&limit=0'));
        $this->assertSame([], $this->names('scope=all&q=' . urlencode('%')), 'a wildcard is a letter');
    }

    public function test_scope_all_answers_a_caller_who_may_view_no_prices_with_the_same_rows_and_nothing_priced(): void
    {
        $admin = $this->pick('scope=all&limit=50');

        foreach ([self::HR, self::NOBODY] as $profile) {
            $rows = $this->pick('scope=all&limit=50', $profile);
            $this->assertSame(array_column($admin, 'masterId'), array_column($rows, 'masterId'), 'the same rows');
            $this->assertSame(array_column($admin, 'state'), array_column($rows, 'state'));
            foreach ($rows as $row) {
                $this->assertSame([null, null, null, null], [$row['crmProductId'], $row['sku'], $row['unitPrice'], $row['currency']], "profile {$profile}: no number, SKU or price");
                $this->assertNull($row['taxRateId']);
            }
        }
        $this->assertSame([], $this->names('scope=all&q=PC2-SKU', self::HR), 'the SKU cannot be searched by a caller who may not view it');
        $this->assertSame(['Payroll paused'], $this->names('scope=all&q=PC2-SKU', self::ADMIN));
    }

    public function test_scope_all_holds_back_the_sell_fields_of_a_type_the_caller_may_not_view_but_not_of_the_other(): void
    {
        $rows = array_column($this->pick('scope=all&limit=50', self::PRODUCTS_ONLY), null, 'name');

        $this->assertSame($this->rows['kit']['crm'], $rows['Kit']['crmProductId'], 'a product row, and the caller may view products');
        $this->assertEquals(50.0, $rows['Kit']['unitPrice']);
        $this->assertSame([null, null, null], [$rows['Consulting']['crmProductId'], $rows['Consulting']['sku'], $rows['Consulting']['unitPrice']], 'a service row, and the caller may not view services');
        $this->assertSame([true, 'sold'], [$rows['Consulting']['sold'], $rows['Consulting']['state']], 'the name and the state are open to everybody who is signed in');
        $this->assertSame([], $this->names('scope=all&q=SVC-1', self::PRODUCTS_ONLY));
    }

    public function test_scope_all_needs_a_signed_in_user_and_a_host_without_the_tables_answers_an_empty_list(): void
    {
        $this->getJson('/api/product-master/picker?scope=all')->assertStatus(401);
        \Illuminate\Support\Facades\Schema::drop('crm_products');

        $this->getJson('/api/product-master/picker?scope=all', $this->who(self::ADMIN))->assertOk()->assertJsonPath('data', []);
    }

    // ================================================================== lines

    public function test_lines_by_default_are_the_active_products_as_id_key_and_name_and_nothing_else_changes(): void
    {
        $archived = $this->makeProduct('Old line', 'old_line');
        DB::table('g2g_product_master')->where('id', $archived)->update(['status' => 'archived']);
        $hr = $this->makeProduct('HR Suite', 'hr_suite');

        $data = $this->getJson('/api/product-master/lines', $this->who(self::ADMIN))->assertOk()->json('data');

        $this->assertSame([['id' => $hr, 'key' => 'hr_suite', 'name' => 'HR Suite'], ['id' => $this->payroll, 'key' => 'payroll_cloud', 'name' => 'Payroll Cloud']], $data, 'by name, active only, no other key');
        $this->assertSame(json_encode(['status' => 1, 'data' => [['id' => $hr, 'key' => 'hr_suite', 'name' => 'HR Suite'], ['id' => $this->payroll, 'key' => 'payroll_cloud', 'name' => 'Payroll Cloud']]]),
            $this->getJson('/api/product-master/lines', $this->who(self::ADMIN))->getContent(), 'the default answer is byte for byte what it always was: no sold key');
        $this->assertSame($data, $this->getJson('/api/product-master/lines?withSubProducts=0', $this->who(self::ADMIN))->json('data'));
        $this->assertSame($data, $this->getJson('/api/product-master/lines?withSubProducts=false', $this->who(self::ADMIN))->json('data'));
        $this->getJson('/api/product-master/lines')->assertStatus(401);
        $this->getJson('/api/product-master/lines', $this->who(self::HR))->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_lines_with_sub_products_put_each_active_sub_product_and_item_under_its_product(): void
    {
        $hr = $this->makeProduct('HR Suite', 'hr_suite');
        $hrOne = $this->makeSub($hr, 'HR1', 'Hiring');
        $archived = $this->makeProduct('Old line', 'old_line');
        DB::table('g2g_product_master')->where('id', $archived)->update(['status' => 'archived']);

        $data = $this->getJson('/api/product-master/lines?withSubProducts=1', $this->who(self::HR))->assertOk()->json('data');

        $this->assertSame(['id', 'key', 'name', 'kind', 'parentName', 'sold'], array_keys($data[0]));
        $shape = array_map(fn (array $r) => [$r['kind'], $r['name'], $r['parentName'], $r['sold']], $data);
        $this->assertSame([
            ['product', 'HR Suite', null, null],
            ['sub_product', 'Hiring', 'HR Suite', false],                  // an About only: not sold in CRM
            ['product', 'Payroll Cloud', null, null],                      // a Product is never sold itself: null, not false
            ['item', 'Consulting', 'Payroll Cloud', true],
            ['item', 'Dead kit', 'Payroll Cloud', false],      // an item in the Recycle Bin keeps its master row until the sync archives it; here nothing did: its Sell row is in the bin, so not sold
            ['item', 'Kit', 'Payroll Cloud', true],
            ['sub_product', 'Payroll about only', 'Payroll Cloud', false],
            ['sub_product', 'Payroll binned', 'Payroll Cloud', false],      // its Sell row is in the Recycle Bin
            ['sub_product', 'Payroll paused', 'Payroll Cloud', false],     // its Sell row is switched off
            ['sub_product', 'Payroll sold', 'Payroll Cloud', true],
        ], $shape, 'each active Product, then its active Sub-products and items by name; an archived row and another organisation\'s rows are not there');
        $this->assertSame($hrOne['master'], $data[1]['id']);
        $this->assertSame('hr_suite/hr1', $data[1]['key']);
    }

    public function test_sold_in_lines_means_the_same_as_sold_in_scope_all_for_every_row(): void
    {
        $lines = collect($this->getJson('/api/product-master/lines?withSubProducts=1', $this->who(self::ADMIN))->assertOk()->json('data'))->where('kind', '!=', 'product')->pluck('sold', 'id')->all();
        $picker = collect($this->pick('scope=all&limit=50'))->pluck('sold', 'masterId')->all();

        $this->assertNotEmpty($picker);
        foreach ($picker as $masterId => $sold) {
            $this->assertSame($sold, $lines[$masterId], "master row {$masterId}: the picker and the lines agree on what is sold");
        }
        $this->assertSame([], array_keys(array_diff_key($picker, $lines)), 'every row the picker offers is in the lines');
    }

    public function test_a_sub_product_that_points_at_another_organisations_catalogue_row_is_not_sold(): void
    {
        $theirCrm = (int) DB::table('crm_products')->where('sub_institute_id', self::T2)->value('id');
        DB::table('g2g_product_master')->where('id', $this->rows['about']['master'])->update(['crm_product_id' => $theirCrm]);

        $rows = collect($this->getJson('/api/product-master/lines?withSubProducts=1', $this->who(self::ADMIN))->assertOk()->json('data'))->keyBy('id');

        $this->assertNotNull($theirCrm);
        $this->assertFalse($rows[$this->rows['about']['master']]['sold'], 'Sell is never shared: a catalogue row of another organisation is no Sell side of mine');
    }

    public function test_a_sub_product_with_no_active_product_still_can_be_chosen_and_follows_the_products(): void
    {
        $old = $this->makeProduct('Old line', 'old_line');
        $strayUnderOld = $this->makeSub($old, 'OL1', 'Under old');
        DB::table('g2g_product_master')->where('id', $old)->update(['status' => 'archived']);
        $orphan = (int) DB::table('g2g_product_master')->insertGetId(['sub_institute_id' => self::T, 'product_key' => 'orphan/1', 'kind' => 'sub_product', 'name' => 'Orphan', 'status' => 'active', 'origin' => 'manual', 'parent_id' => null, 'created_at' => now(), 'updated_at' => now()]);

        $data = $this->getJson('/api/product-master/lines?withSubProducts=1', $this->who(self::ADMIN))->assertOk()->json('data');

        $tail = array_slice($data, -2);
        $this->assertEqualsCanonicalizing([[$orphan, null], [$strayUnderOld['master'], 'Old line']], array_map(fn (array $r) => [$r['id'], $r['parentName']], $tail), 'after every active Product and its children');
        $this->assertSame(['product'], array_values(array_unique(array_column(array_slice($data, 0, 1), 'kind'))));
    }

    public function test_the_lines_of_another_organisation_are_never_listed(): void
    {
        $theirs = (int) $this->masterByKey('their_line', self::T2)->id;   // made in setUp
        $this->makeSub($theirs, 'Q9', 'Their sub', self::T2);

        $names = array_column($this->getJson('/api/product-master/lines?withSubProducts=1', $this->who(self::ADMIN))->json('data'), 'name');
        $theirNames = array_column($this->getJson('/api/product-master/lines?withSubProducts=1', $this->who(self::ADMIN2))->json('data'), 'name');

        $this->assertNotContains('Their Product', $names);
        $this->assertNotContains('Their sub', $names);
        $this->assertSame(['Their Product', 'Their sold', 'Their sub'], $theirNames, 'and they see only their own');
    }
}
