<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsProductReadWorld;
use Tests\TestCase;

/**
 * The unified READ API of the Product Master (slice 15, P2-E1): `GET /{id}` (show), `/{id}/sell`, `/by-crm/{crmId}`, `/picker` and the re-gated old reads (`/`, `/lines`, `/options`,
 * `/meta`). The list has its own file (ProductListApiTest), the history and the usage theirs (ProductHistoryApiTest, ProductUsageApiTest), the route map hers
 * (ProductMasterRouteMapTest).
 *
 * The rule the owner asked for: names, ids and lines are open to every signed-in user of the organisation, so HR can open the Portfolio; everything priced or Sell-related needs the
 * view right of the item's own type. The people are those of BuildsProductReadWorld: 1 administrator, 2 sales-like (Sell only), 5 HR (Portfolio only), 6 may view Products only,
 * 7 may view Services only, 8 nobody; 3 is the administrator of the other organisation.
 *
 * One record holds both sides (a Sub-product "Scholar Nine" with an About and a Sell); the scholarWorld adds the rest.
 */
class ProductReadApiTest extends TestCase
{
    use BuildsProductReadWorld;

    /** @var array{master: int, offer: int, crm: int} the record that holds an About and a Sell */
    private array $both;

    /** @var array<string, array{master: int, crm: int}> */
    private array $items;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildReadWorld();
        $w = $this->scholarWorld();
        $this->items = $w['items'];
        $sub = $this->subProduct('SCH-09', 'Scholar Nine', ['what_it_does' => 'Nine things', 'readiness_status' => 'Live']);
        $crm = (int) DB::table('crm_products')->insertGetId([
            'sub_institute_id' => self::T, 'product_no' => 'PRD-NINE', 'item_type' => 'product', 'name' => 'Scholar Nine', 'sku' => 'SCH-09-SKU', 'category' => 'Nines', 'unit_price' => 999,
            'cost_price' => 600, 'commission_rate' => 7.5, 'currency' => 'INR', 'is_active' => 1, 'product_master_id' => $w['product'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('g2g_product_master')->where('id', $sub['master'])->update(['crm_product_id' => $crm]);
        $this->both = ['master' => $sub['master'], 'offer' => $sub['offer'], 'crm' => $crm];
    }

    private function read(string $url, ?int $profile = self::ADMIN): \Illuminate\Testing\TestResponse
    {
        return $this->getJson($url, $profile === null ? [] : $this->as($profile));
    }

    private function body(\Illuminate\Testing\TestResponse $r): string
    {
        return (string) $r->getContent();
    }

    // ================================================================== show: GET /{id}

    public function test_show_carries_the_about_the_icp_state_and_the_sell_for_a_caller_who_may_view_it(): void
    {
        $data = $this->read('/api/product-master/' . $this->both['master'])->assertOk()->json('data');

        $this->assertSame('Scholar Nine', $data['name']);
        $this->assertSame('Nine things', $data['about']['whatItDoes']);
        $this->assertSame($data['offer']['about'], $data['about'], 'the About is also at the top, as the contract says');
        $this->assertSame('none', $data['icp']);
        $this->assertSame(['state' => 'sold', 'crmProductId' => $this->both['crm'], 'type' => 'product', 'sku' => 'SCH-09-SKU', 'category' => 'Nines', 'currency' => 'INR'],
            array_intersect_key($data['sell'], array_flip(['state', 'crmProductId', 'type', 'sku', 'category', 'currency'])));
        $this->assertEquals([999.0, 600.0, 7.5], [$data['sell']['unitPrice'], $data['sell']['costPrice'], $data['sell']['commissionRate']]);
        $this->assertSame($this->both['crm'], $data['crmProduct']['id'], 'the old drawer\'s key is kept for a caller who may view Sell');
        $this->assertEquals(999.0, $data['crmProduct']['unitPrice']);
    }

    public function test_show_to_hr_has_the_names_the_about_and_the_icp_and_no_sell_and_no_crm_product(): void
    {
        $res = $this->read('/api/product-master/' . $this->both['master'], self::HR)->assertOk();
        $data = $res->json('data');

        $this->assertSame('Scholar Nine', $data['name']);
        $this->assertSame('Nine things', $data['about']['whatItDoes']);
        $this->assertSame('none', $data['icp']);
        $this->assertArrayHasKey('sell', $data, 'the key is always there');
        $this->assertNull($data['sell']);
        $this->assertArrayHasKey('crmProduct', $data);
        $this->assertNull($data['crmProduct'], 'the ungated crmProduct of the old read is gone');
        $this->assertSame('ScholarClone', $data['parent']['name']);
        foreach (['SCH-09-SKU', '999', '600', '7.5', 'Nines', 'PRD-NINE'] as $secret) {
            $this->assertFalse(str_contains($this->body($res), $secret), "{$secret} must not be in what HR receives");
        }
    }

    public function test_show_follows_the_type_of_the_record_for_a_caller_with_one_of_the_two_rights(): void
    {
        $service = $this->items['implementation']['master'];

        $this->assertNotNull($this->read('/api/product-master/' . $this->both['master'], self::PRODUCTS_VIEWER)->json('data.sell'));
        $this->assertNull($this->read('/api/product-master/' . $service, self::PRODUCTS_VIEWER)->json('data.sell'), 'a Products viewer does not see a service\'s Sell');
        $this->assertNull($this->read('/api/product-master/' . $service, self::PRODUCTS_VIEWER)->json('data.crmProduct'));
        $this->assertNotNull($this->read('/api/product-master/' . $service, self::SERVICES_VIEWER)->json('data.sell'));
        $this->assertNull($this->read('/api/product-master/' . $this->both['master'], self::SERVICES_VIEWER)->json('data.sell'));
        $this->assertNotNull($this->read('/api/product-master/' . $service, self::SALES)->json('data.sell'));
        $this->assertNull($this->read('/api/product-master/' . $this->both['master'], self::NOBODY)->json('data.sell'));
    }

    public function test_show_of_a_product_and_of_a_record_with_no_about_or_no_sell_and_the_tenant_rules(): void
    {
        $scholar = $this->sharedId('scholarclone');
        $product = $this->read('/api/product-master/' . $scholar, self::HR)->assertOk()->json('data');
        $this->assertSame(['product', true, null, null, 'template'], [$product['kind'], $product['shared'], $product['about'], $product['sell'], $product['icp']]);
        $this->assertCount(17, $product['subProducts'], '7 + 1 + 9 Sub-products under ScholarClone');

        $item = $this->read('/api/product-master/' . $this->items['gujarat']['master'], self::ADMIN)->assertOk()->json('data');
        $this->assertNull($item['about'], 'an item has no About');
        $this->assertNull($item['icp']);
        $this->assertSame('sold', $item['sell']['state']);

        $aboutOnly = $this->read('/api/product-master/' . $this->sharedSubMaster(), self::SALES)->assertOk()->json('data');
        $this->assertNull($aboutOnly['sell']);
        $this->assertNotNull($aboutOnly['about']);

        $other = $this->item('Their item', ['sku' => 'T2-1'], self::T2);
        $this->read('/api/product-master/' . $other['master'], self::ADMIN)->assertStatus(404);
        $this->read('/api/product-master/' . $this->items['gujarat']['master'], self::ADMIN2)->assertStatus(404);
        $this->read('/api/product-master/' . $scholar, self::ADMIN2)->assertOk();
        $this->read('/api/product-master/999999')->assertStatus(404);
        $this->read('/api/product-master/' . $scholar, null)->assertStatus(401);
    }

    /** The master id of an About-only Sub-product (SCH-01). */
    private function sharedSubMaster(): int
    {
        return (int) DB::table('g2g_product_master')->where('product_key', 'scholarclone/sch-01')->value('id');
    }

    // ================================================================== sell: GET /{id}/sell

    public function test_sell_gives_the_whole_section_to_a_caller_who_may_view_it_and_403_to_anybody_else(): void
    {
        $res = $this->read('/api/product-master/' . $this->both['master'] . '/sell')->assertOk();
        $data = $res->json('data');

        $this->assertSame(1, $res->json('status'));
        $this->assertSame(['state' => 'sold', 'crmProductId' => $this->both['crm'], 'productNo' => 'PRD-NINE', 'type' => 'product', 'sku' => 'SCH-09-SKU', 'isActive' => true], array_intersect_key($data, array_flip(['state', 'crmProductId', 'productNo', 'type', 'sku', 'isActive'])));
        $this->assertEquals([999.0, 600.0, 7.5], [$data['unitPrice'], $data['costPrice'], $data['commissionRate']]);
        $this->assertArrayHasKey('qtyInStock', $data);
        $this->read('/api/product-master/' . $this->both['master'] . '/sell', self::SALES)->assertOk()->assertJsonPath('data.sku', 'SCH-09-SKU');

        foreach ([self::HR, self::NOBODY] as $profile) {
            $res = $this->read('/api/product-master/' . $this->both['master'] . '/sell', $profile)->assertStatus(403);
            $this->assertFalse(str_contains($this->body($res), '999'));
            $this->assertSame(0, $res->json('status'));
        }
        $this->read('/api/product-master/' . $this->both['master'] . '/sell', null)->assertStatus(401);
    }

    public function test_sell_asks_for_the_right_of_the_records_own_type(): void
    {
        $service = $this->items['training']['master'];

        $this->read('/api/product-master/' . $this->both['master'] . '/sell', self::PRODUCTS_VIEWER)->assertOk();
        $this->read('/api/product-master/' . $service . '/sell', self::PRODUCTS_VIEWER)->assertStatus(403);
        $this->read('/api/product-master/' . $service . '/sell', self::SERVICES_VIEWER)->assertOk()->assertJsonPath('data.type', 'service');
        $this->read('/api/product-master/' . $this->both['master'] . '/sell', self::SERVICES_VIEWER)->assertStatus(403);
    }

    public function test_sell_of_a_record_in_the_recycle_bin_says_so_and_of_a_record_with_no_sell_is_null_for_a_seller_and_403_for_hr(): void
    {
        DB::table('crm_products')->where('id', $this->items['fleet']['crm'])->update(['deleted_at' => now()]);

        $this->read('/api/product-master/' . $this->items['fleet']['master'] . '/sell')->assertOk()->assertJsonPath('data.state', 'binned');
        $about = $this->sharedSubMaster();
        $this->assertNull($this->read('/api/product-master/' . $about . '/sell', self::SALES)->assertOk()->json('data'), 'an About-only record has no Sell section');
        $this->read('/api/product-master/' . $about . '/sell', self::HR)->assertStatus(403);   // HR is not told whether there is one
        $this->assertNull($this->read('/api/product-master/' . $this->sharedId('scholarclone') . '/sell', self::SALES)->assertOk()->json('data'));
        $this->read('/api/product-master/' . $this->sharedId('scholarclone') . '/sell', self::HR)->assertStatus(403);
    }

    public function test_sell_of_another_organisations_record_is_a_404_whoever_asks(): void
    {
        $other = $this->item('Their item', ['sku' => 'T2-1', 'unit_price' => 5], self::T2);

        $this->read('/api/product-master/' . $other['master'] . '/sell', self::ADMIN)->assertStatus(404);
        $this->read('/api/product-master/' . $other['master'] . '/sell', self::ADMIN2)->assertOk()->assertJsonPath('data.sku', 'T2-1');
        $this->read('/api/product-master/999999/sell')->assertStatus(404);
    }

    public function test_a_pointer_into_another_organisation_is_not_followed_not_for_the_type_and_not_for_the_data(): void
    {
        $theirs = $this->item('Their service', ['item_type' => 'service', 'sku' => 'T2-SVC', 'unit_price' => 5], self::T2);
        $about = $this->sharedSubMaster();
        DB::table('g2g_product_master')->where('id', $about)->update(['crm_product_id' => $theirs['crm']]);   // a corrupt pointer (the integrity checker calls it I1T)

        // The type of the record is looked up in the caller's own organisation only: here there is none, so the record counts as having no catalogue row and a Products viewer
        // is answered (with nothing). Followed across organisations it would be a SERVICE and a Products viewer would get a 403.
        $res = $this->read('/api/product-master/' . $about . '/sell', self::PRODUCTS_VIEWER)->assertOk();
        $this->assertNull($res->json('data'));
        $this->assertFalse(str_contains($this->body($res), 'T2-SVC'));
        $this->assertNull($this->read('/api/product-master/' . $about, self::SALES)->json('data.sell'), 'their price is not under my record');
    }

    // ================================================================== by-crm

    public function test_by_crm_names_the_record_that_holds_a_catalogue_id_live_or_in_the_bin(): void
    {
        $this->read('/api/product-master/by-crm/' . $this->both['crm'])->assertOk()->assertExactJson(['status' => 1, 'data' => ['id' => $this->both['master'], 'kind' => 'sub_product', 'mergedInto' => null]]);   // CHANGED ON PURPOSE (WPB, G-E4): the answer gained `mergedInto`, null unless the record is a merged-away husk
        $this->read('/api/product-master/by-crm/' . $this->items['gujarat']['crm'], self::HR)->assertOk()->assertJsonPath('data.id', $this->items['gujarat']['master'])->assertJsonPath('data.kind', 'item');

        DB::table('crm_products')->where('id', $this->items['fleet']['crm'])->update(['deleted_at' => now()]);
        $this->read('/api/product-master/by-crm/' . $this->items['fleet']['crm'])->assertOk()->assertJsonPath('data.id', $this->items['fleet']['master']);
    }

    public function test_by_crm_is_this_organisations_only_and_a_404_otherwise(): void
    {
        $other = $this->item('Their item', [], self::T2);

        $this->read('/api/product-master/by-crm/' . $other['crm'], self::ADMIN)->assertStatus(404);
        $this->read('/api/product-master/by-crm/' . $this->both['crm'], self::ADMIN2)->assertStatus(404);
        $this->read('/api/product-master/by-crm/' . $other['crm'], self::ADMIN2)->assertOk()->assertJsonPath('data.id', $other['master']);
        $this->read('/api/product-master/by-crm/999999')->assertStatus(404);
        $this->read('/api/product-master/by-crm/abc')->assertStatus(404);
        $this->read('/api/product-master/by-crm/' . $this->both['crm'], null)->assertStatus(401);
    }

    public function test_by_crm_also_finds_an_archived_record_so_an_old_link_lands_where_it_can_be_restored(): void
    {
        DB::table('g2g_product_master')->where('id', $this->both['master'])->update(['status' => 'archived']);

        $this->read('/api/product-master/by-crm/' . $this->both['crm'])->assertOk()->assertJsonPath('data.id', $this->both['master']);
        // a merged-away record gives its pointer up to the survivor (the table's unique key allows one holder), so the old catalogue id leads to the survivor
        DB::table('g2g_product_master')->where('id', $this->both['master'])->update(['crm_product_id' => null]);
        DB::table('g2g_product_master')->where('id', $this->items['gujarat']['master'])->update(['crm_product_id' => $this->both['crm']]);
        $this->read('/api/product-master/by-crm/' . $this->both['crm'])->assertOk()->assertJsonPath('data.id', $this->items['gujarat']['master']);
    }

    // ================================================================== picker

    private function picker(string $query = 'type=product', ?int $profile = self::ADMIN): \Illuminate\Testing\TestResponse
    {
        return $this->read('/api/product-master/picker' . ($query !== '' ? '?' . $query : ''), $profile);
    }

    /** @return list<string> */
    private function picked(string $query = 'type=product', int $profile = self::ADMIN): array
    {
        return array_column($this->picker($query, $profile)->assertOk()->json('data'), 'name');
    }

    public function test_the_picker_offers_the_sold_active_catalogue_backed_sub_products_of_the_type_asked_and_nothing_else(): void
    {
        // paused, in the Bin, archived, never sold (About only: SCH-01..07 and EB-01 have no catalogue row), another organisation's: none of them is offered
        DB::table('crm_products')->where('id', $this->items['training']['crm'])->update(['is_active' => 0]);
        DB::table('crm_products')->where('id', $this->items['fleet']['crm'])->update(['deleted_at' => now()]);
        DB::table('g2g_product_master')->where('id', $this->items['exam']['master'])->update(['status' => 'archived']);
        $this->item('Their item', ['sku' => 'T2-1'], self::T2);

        $products = $this->picked('type=product');
        $this->assertEqualsCanonicalizing(['Scholar One ERP - Gujarat Edition', 'Scholar Core School Management', 'Scholar CCB Fee Collection System', 'Scholar LMS & Digital Classroom',
            'Scholar Smart Attendance & Biometric Access', 'Scholar Nine'], $products, 'sold + active + not binned + catalogue-backed, products only');
        $this->assertSame(['ScholarClone On-Premise & Cloud Implementation'], $this->picked('type=service'), 'the paused training service is not offered');
        $this->assertEqualsCanonicalizing($products, $this->picked('type=subproduct'), 'subproduct is the product type of the new words');
        $this->assertCount(7, $this->picked(''), 'no type: every type the caller may view');
        foreach (['Scholar School ERP', 'ScholarClone', 'Their item', 'Scholar Fleet GPS Bus Tracking System', 'Scholar Examination & CCE Report Engine'] as $never) {
            $this->assertNotContains($never, $this->picked(''));
        }
    }

    public function test_a_shared_master_row_that_holds_a_catalogue_id_is_not_offered_because_sell_is_never_shared(): void
    {
        // A shared row (organisation 0) holding a catalogue pointer is a state the checker warns about (W2); the picker must not offer the organisation's item through it.
        DB::table('g2g_product_master')->where('id', $this->items['core']['master'])->update(['crm_product_id' => null]);
        DB::table('g2g_product_master')->insert(['sub_institute_id' => 0, 'product_key' => 'shared/odd', 'kind' => 'sub_product', 'name' => 'Odd shared', 'parent_id' => $this->sharedId('scholarclone'), 'crm_product_id' => $this->items['core']['crm'], 'status' => 'active', 'origin' => 'builtin', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertNotContains('Scholar Core School Management', $this->picked('type=product'));
        $this->assertNotContains('Odd shared', $this->picked('type=product'));
    }

    public function test_a_level_1_product_is_never_offered_even_if_a_row_of_it_holds_a_catalogue_id(): void
    {
        // The item row gives its catalogue id away to a Product row: the integrity checker would call this corrupt, and the picker must not be fooled by it.
        DB::table('g2g_product_master')->where('id', $this->items['core']['master'])->update(['crm_product_id' => null]);
        $own = DB::table('g2g_product_master')->insertGetId(['sub_institute_id' => self::T, 'product_key' => 'payroll', 'kind' => 'product', 'name' => 'Payroll', 'portfolio_name' => 'Payroll', 'status' => 'active', 'origin' => 'custom', 'crm_product_id' => $this->items['core']['crm'], 'created_at' => now(), 'updated_at' => now()]);

        $names = $this->picked('type=product');

        $this->assertNotContains('Payroll', $names);
        $this->assertNotContains('Scholar Core School Management', $names, 'the item row gave its catalogue id away, and a Product is not a Sub-product');
        $this->assertGreaterThan(0, $own);
    }

    public function test_a_picker_row_has_what_a_document_line_needs(): void
    {
        DB::table('crm_products')->where('id', $this->both['crm'])->update(['tax_rate_id' => 7]);

        $row = collect($this->picker('type=product&q=' . urlencode('Scholar Nine'))->json('data'))->first();

        $this->assertSame(['crmProductId', 'masterId', 'name', 'productName', 'sku', 'unitPrice', 'currency', 'taxRateId', 'type'], array_keys($row));
        $this->assertSame([$this->both['crm'], $this->both['master'], 'Scholar Nine', 'ScholarClone', 'SCH-09-SKU', 'INR', 7, 'product'],
            [$row['crmProductId'], $row['masterId'], $row['name'], $row['productName'], $row['sku'], $row['currency'], $row['taxRateId'], $row['type']]);
        $this->assertEquals(999.0, $row['unitPrice']);
        $this->assertSame(1, $this->picker('type=product')->json('status'));
    }

    public function test_the_picker_needs_the_view_right_of_the_type_asked(): void
    {
        $this->picker('type=product', self::HR)->assertStatus(403);
        $this->picker('type=service', self::HR)->assertStatus(403);
        $this->picker('', self::HR)->assertStatus(403);
        $this->picker('', self::NOBODY)->assertStatus(403);
        $this->picker('type=product', null)->assertStatus(401);

        $this->assertNotEmpty($this->picked('type=product', self::PRODUCTS_VIEWER));
        $this->picker('type=service', self::PRODUCTS_VIEWER)->assertStatus(403);
        $this->assertSame(['ScholarClone Faculty & Staff Certification Training', 'ScholarClone On-Premise & Cloud Implementation'], $this->sorted($this->picked('type=service', self::SERVICES_VIEWER)));
        $this->picker('type=product', self::SERVICES_VIEWER)->assertStatus(403);
        $this->assertSame([], array_diff($this->picked('', self::PRODUCTS_VIEWER), $this->picked('type=product')), 'no type: only the types the caller may view');
        $this->assertCount(2, $this->picked('', self::SERVICES_VIEWER));
        $this->assertCount(10, $this->picked('', self::SALES), 'a seller with both rights gets both types: 8 products and 2 services');
        $this->picker('type=hardware')->assertStatus(422);
    }

    /** @param list<string> $names @return list<string> */
    private function sorted(array $names): array
    {
        sort($names);

        return $names;
    }

    public function test_the_picker_searches_as_you_type_on_the_name_and_the_sku_and_a_wildcard_is_a_letter(): void
    {
        $this->item('Cotton 100% pure', ['sku' => 'COT-1']);

        $this->assertSame(['Scholar Fleet GPS Bus Tracking System'], $this->picked('type=product&q=fleet'));
        $this->assertSame(['Scholar Fleet GPS Bus Tracking System'], $this->picked('type=product&q=SCH-BUS'), 'by SKU');
        $this->assertSame(['Scholar Fleet GPS Bus Tracking System'], $this->picked('type=product&q=' . urlencode(' bus ')), 'trimmed');
        $this->assertSame(['Cotton 100% pure'], $this->picked('type=product&q=' . urlencode('%')));
        $this->assertSame([], $this->picked('type=product&q=' . urlencode('_')));
        $this->assertSame([], $this->picked('type=product&q=nothing-has-this'));
        $this->assertSame([], $this->picked('type=service&q=fleet'), 'a product is not found among services');
    }

    public function test_the_picker_limit_defaults_to_20_and_is_never_more_than_50(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->item(sprintf('Bulk item %02d', $i), ['sku' => sprintf('BULK-%02d', $i)]);
        }

        $this->assertCount(20, $this->picker('type=product')->json('data'));
        $this->assertCount(50, $this->picker('type=product&limit=500')->json('data'));
        $this->assertCount(50, $this->picker('type=product&limit=50')->json('data'));
        $this->assertCount(3, $this->picker('type=product&limit=3')->json('data'));
        $this->assertCount(1, $this->picker('type=product&limit=0')->json('data'));
        $this->assertCount(20, $this->picker('type=product&limit=abc')->json('data'));
        $found = $this->picked('type=product&limit=50&q=bulk');
        $this->assertCount(50, $found, 'a search narrows the list to the 60 bulk items, 50 of them');
        $this->assertSame([], array_filter($found, fn ($n) => ! str_starts_with($n, 'Bulk item')));
    }

    public function test_the_picker_is_grouped_by_product_then_name(): void
    {
        $other = DB::table('g2g_product_master')->insertGetId(['sub_institute_id' => self::T, 'product_key' => 'payroll', 'kind' => 'product', 'name' => 'Payroll', 'portfolio_name' => 'Payroll', 'status' => 'active', 'origin' => 'custom', 'created_at' => now(), 'updated_at' => now()]);
        $this->item('Zeta module', ['product_master_id' => $other]);   // its NAME sorts after every Scholar item, its Product before ScholarClone
        $rows = $this->picker('type=product')->json('data');

        $this->assertSame(['Payroll', 'Zeta module'], [$rows[0]['productName'], $rows[0]['name']], 'Payroll sorts before ScholarClone, whatever the item\'s own name');
        $products = array_column($rows, 'productName');
        $this->assertSame($products, $this->sorted($products), 'one group after another');
        $names = array_column(array_filter($rows, fn ($r) => $r['productName'] === 'ScholarClone'), 'name');
        $this->assertSame($names, $this->sorted(array_values($names)), 'by name inside a Product');
    }

    // ================================================================== the old reads, re-gated

    public function test_the_old_reads_are_open_to_a_signed_in_user_but_hide_the_sell_identifiers_from_a_caller_who_may_not_view_them(): void
    {
        foreach (['/api/product-master', '/api/product-master/lines', '/api/product-master/options', '/api/product-master/meta'] as $url) {
            $this->read($url, self::HR)->assertOk();
            $this->read($url, self::NOBODY)->assertOk();
            $this->read($url, null)->assertStatus(401);
        }

        $tree = fn (int $profile) => collect($this->read('/api/product-master', $profile)->json('data.products'))->firstWhere('key', 'scholarclone');
        $nine = fn (int $profile) => collect($tree($profile)['subProducts'])->firstWhere('name', 'Scholar Nine');
        $this->assertSame([true, $this->both['crm'], 'product'], [$nine(self::ADMIN)['soldInCrm'], $nine(self::ADMIN)['crmProductId'], $nine(self::ADMIN)['crmItemType']]);
        $this->assertSame([true, null, null], [$nine(self::HR)['soldInCrm'], $nine(self::HR)['crmProductId'], $nine(self::HR)['crmItemType']], 'the Sell identifiers go only to a caller who may view them; the sold state is open');
        $this->assertSame($this->both['crm'], $nine(self::PRODUCTS_VIEWER)['crmProductId']);
        $this->assertNull($nine(self::SERVICES_VIEWER)['crmProductId']);

        $items = fn (int $profile) => collect($this->read('/api/product-master', $profile)->json('data.items'))->pluck('crmProductId', 'name')->all();
        $this->assertSame($this->items['gujarat']['crm'], $items(self::ADMIN)['Scholar One ERP - Gujarat Edition']);
        $this->assertSame($this->items['implementation']['crm'], $items(self::ADMIN)['ScholarClone On-Premise & Cloud Implementation']);
        $this->assertSame([null], array_values(array_unique($items(self::HR))), 'HR gets the names of the items and no catalogue id');
        $this->assertSame($this->items['gujarat']['crm'], $items(self::PRODUCTS_VIEWER)['Scholar One ERP - Gujarat Edition']);
        $this->assertNull($items(self::PRODUCTS_VIEWER)['ScholarClone On-Premise & Cloud Implementation']);
    }

    public function test_the_old_reads_stay_in_the_callers_organisation(): void
    {
        $this->item('Their item', [], self::T2);
        $options = fn (int $profile) => array_column($this->read('/api/product-master/options', $profile)->json('data'), 'name');

        $this->assertContains('Scholar Nine', $options(self::HR));
        $this->assertNotContains('Their item', $options(self::HR));
        $this->assertContains('Their item', $options(self::ADMIN2));
        $this->assertNotContains('Scholar Nine', $options(self::ADMIN2));
        $this->assertContains('ScholarClone', array_column($this->read('/api/product-master/lines', self::HR)->json('data'), 'name'));
    }

    public function test_meta_flags_follow_the_writers_rules_for_hr_a_seller_and_a_viewer(): void
    {
        $can = fn (int $profile) => $this->read('/api/product-master/meta', $profile)->assertOk()->json('data.can');

        $this->assertTrue($can(self::HR)['createSubProduct'], 'HR writes the Portfolio by role: it may add a Sub-product although it has no row on Products');
        $this->assertTrue($can(self::HR)['editSubProduct']);
        $this->assertFalse($can(self::HR)['createProduct'], 'only an administrator makes a Product');
        $this->assertTrue($can(self::ADMIN)['createProduct']);
        $this->assertTrue($can(self::SALES)['createSubProduct'], 'a seller may add the Sell side');
        $this->assertFalse($can(self::PRODUCTS_VIEWER)['createSubProduct'], 'view only');
        $this->assertFalse($can(self::PRODUCTS_VIEWER)['editSubProduct']);
        $this->assertFalse($can(self::NOBODY)['createSubProduct']);
        DB::table('crm_tax_rates')->insert(['sub_institute_id' => self::T, 'name' => 'GST 18', 'percentage' => 18, 'is_active' => 1]);
        $this->assertSame([], $this->read('/api/product-master/meta', self::HR)->json('data.taxRates'), 'tax rates only for a catalogue writer');
        $this->assertCount(1, $this->read('/api/product-master/meta', self::SALES)->json('data.taxRates'));
    }
}
