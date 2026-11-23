<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\ProductListQuery;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsProductReadWorld;
use Tests\TestCase;

/**
 * `GET /api/product-master/list`, the paged flat list of the unified Product Portfolio (slice 15, P2-E1, brief section 1 and the coordinator's additions: `shared`, `category`
 * and `qtyInStock` for a Sell viewer, `status=all`, `sort`/`dir`).
 *
 * The world is the shape of the dev data (tests/Support/BuildsProductUnifyWorld::scholarWorld): five shared Products, 8 Sub-products with an About (SCH-01 to SCH-07 and EB-01) and
 * 9 catalogue items (two of them services), 22 rows in all. The people are those of BuildsProductReadWorld: an administrator, a sales-like profile (Sell only), HR (Portfolio
 * only), a Products viewer and a Services viewer. The rules proved here, each with the gate it protects:
 *   - HR sees every name, id, state and count, and never a SKU, category, stock, price or currency; and cannot find a SKU by searching or order by price
 *   - a caller sees the Sell fields of the rows of the types they may view, not of the others
 *   - another organisation's rows are never listed; the shared Products are, flagged `shared`
 *   - paging is exact (the pages put together are the whole list, once) and `per` is held to 5..100
 *   - every filter, `status=all`, and every sort, in both directions, with the rows kept in their groups
 */
class ProductListApiTest extends TestCase
{
    use BuildsProductReadWorld;

    /** @var array{product: int, other: int, subs: array<string, array{master: int, offer: int}>, items: array<string, array{master: int, crm: int}>} */
    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildReadWorld();
        $this->w = $this->scholarWorld();
        // Stock for one product and (wrongly, as an old import could have left it) for a service: a service never shows stock.
        DB::table('crm_products')->where('id', $this->w['items']['gujarat']['crm'])->update(['qty_in_stock' => 12.5]);
        DB::table('crm_products')->where('id', $this->w['items']['implementation']['crm'])->update(['qty_in_stock' => 7]);
    }

    /** @return \Illuminate\Testing\TestResponse */
    private function list(int $profile = self::ADMIN, string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/product-master/list' . ($query !== '' ? '?' . $query : ''), $this->as($profile));
    }

    /**
     * How many built-in (shared) Products the catalogue holds. It was five when this file was written; origin/main added a sixth (Fiber Valley), so the counts below follow
     * the catalogue instead of a literal, and the Product names are read from it too.
     */
    private function builtIn(): int
    {
        return count(\App\Domain\Signals\Products\ProductCatalog::definitions()) + 2;   // + the two Product Portfolio groups the world builder makes: Higher Education and Commercial Bundle
    }

    /** @return list<string> the built-in Products' names in alphabetical order, as the list answers them */
    private function builtInNames(): array
    {
        $names = array_map(fn (array $d): string => (string) $d['label'], array_values(\App\Domain\Signals\Products\ProductCatalog::definitions()));
        $names = array_merge($names, ['Higher Education', 'Commercial Bundle']);
        sort($names, SORT_STRING | SORT_FLAG_CASE);

        return $names;
    }

    /** @return list<array<string, mixed>> */
    private function rows(int $profile = self::ADMIN, string $query = ''): array
    {
        return $this->list($profile, $query)->assertOk()->json('data');
    }

    /** @return list<string> */
    private function names(int $profile = self::ADMIN, string $query = ''): array
    {
        return array_column($this->rows($profile, $query), 'name');
    }

    /** @return array<string, array<string, mixed>> the rows by name */
    private function byName(int $profile = self::ADMIN, string $query = 'per=100'): array
    {
        $out = [];
        foreach ($this->rows($profile, $query) as $row) {
            $out[$row['name']] = $row;
        }

        return $out;
    }

    private const SELL_KEYS = ['sku', 'category', 'qtyInStock', 'unitPrice', 'currency'];

    // ================================================================== the contract

    public function test_the_list_is_paged_flat_and_has_the_shape_of_the_contract(): void
    {
        $res = $this->list(self::ADMIN)->assertOk();

        $this->assertSame(1, $res->json('status'));
        $meta = $res->json('meta');
        $this->assertSame(17 + $this->builtIn(), $meta['total'], 'the built-in Products + 8 Sub-products with an About + 9 catalogue items');
        $this->assertSame([1, 20, 2], [$meta['page'], $meta['perPage'], $meta['lastPage']]);
        $this->assertTrue($meta['available']);
        $this->assertCount(20, $res->json('data'));
        $this->assertSame(['createProduct', 'createSubProduct', 'editSubProduct', 'viewSell', 'confirmMatches'], array_keys($meta['can']));

        $rows = $this->byName(self::ADMIN);
        $sub = $rows['Scholar School ERP'];   // a Sub-product with an About and nothing to sell
        // CHANGED ON PURPOSE (slice 15, WPA1, item 6): a caller who may view Sell also gets `crmProductId` on every row, null where the record has no catalogue row (the contract with the
        // list screen), so the key set of this row gains it. It still has none of the Sell VALUES (sku, category, qtyInStock, unitPrice, currency).
        $this->assertEqualsCanonicalizing(
            ['id', 'kind', 'name', 'key', 'status', 'productId', 'productName', 'type', 'state', 'readiness', 'signalsFound', 'icp', 'hasAbout', 'hasSell', 'needsProduct', 'shared', 'subProductCount', 'crmProductId'],
            array_keys($sub), 'a row with no catalogue row has no Sell values, even for an administrator, and a null catalogue id'
        );
        $this->assertNull($sub['crmProductId']);
        $this->assertEquals(['kind' => 'sub_product', 'productName' => 'ScholarClone', 'type' => null, 'state' => 'not_sold', 'readiness' => 'Prototype', 'hasAbout' => true, 'hasSell' => false, 'needsProduct' => false, 'shared' => false, 'status' => 'active', 'subProductCount' => null],
            array_intersect_key($sub, array_flip(['kind', 'productName', 'type', 'state', 'readiness', 'hasAbout', 'hasSell', 'needsProduct', 'shared', 'status', 'subProductCount'])));
        $this->assertSame($this->w['product'], $sub['productId']);
        $this->assertSame($this->w['subs']['SCH-01']['master'], $sub['id']);
        $this->assertSame(0, $sub['signalsFound']);
        $this->assertSame('none', $sub['icp']);

        $item = $rows['Scholar One ERP - Gujarat Edition'];   // a catalogue-only item, sold
        $this->assertEquals(['kind' => 'item', 'type' => 'product', 'state' => 'sold', 'hasAbout' => false, 'hasSell' => true, 'readiness' => null, 'signalsFound' => null, 'icp' => null,
            'sku' => 'SCH-01-GUJ', 'category' => 'School ERP', 'qtyInStock' => 12.5, 'unitPrice' => 120000.0],
            array_intersect_key($item, array_flip(['kind', 'type', 'state', 'hasAbout', 'hasSell', 'readiness', 'signalsFound', 'icp', 'sku', 'category', 'qtyInStock', 'unitPrice'])));

        $product = $rows['ScholarClone'];   // a level-1 Product
        $this->assertEquals(['kind' => 'product', 'shared' => true, 'productId' => null, 'productName' => null, 'type' => null, 'state' => null, 'readiness' => null, 'hasAbout' => false, 'hasSell' => false, 'needsProduct' => false, 'subProductCount' => 7 + 9],
            array_intersect_key($product, array_flip(['kind', 'shared', 'productId', 'productName', 'type', 'state', 'readiness', 'hasAbout', 'hasSell', 'needsProduct', 'subProductCount'])));
        $this->assertArrayNotHasKey('sku', $product);
    }

    public function test_the_requests_needs_a_token(): void
    {
        $this->getJson('/api/product-master/list')->assertStatus(401);
        $this->getJson('/api/product-master/list', ['Authorization' => 'Bearer nope'])->assertStatus(401);
    }

    // ================================================================== who sees the Sell fields (and the gate mutations G2, G3, G4)

    public function test_hr_sees_every_name_and_state_and_never_a_sku_category_stock_price_or_currency(): void
    {
        $hr = $this->list(self::HR, 'per=100')->assertOk();
        $admin = $this->list(self::ADMIN, 'per=100')->assertOk();

        $this->assertSame(17 + $this->builtIn(), $hr->json('meta.total'));
        $this->assertSame(array_column($admin->json('data'), 'name'), array_column($hr->json('data'), 'name'), 'HR sees the same records in the same order');
        foreach ($hr->json('data') as $row) {
            foreach (self::SELL_KEYS as $key) {
                $this->assertArrayNotHasKey($key, $row, "HR must not receive {$key} ({$row['name']})");
            }
        }
        // Not under any key, not even by accident: the words that only the Sell side holds (a category is not part of any name here).
        foreach (['SCH-01-GUJ', 'SCH-CCB-GUJ', 'Digital Learning', 'Student Safety', 'Transport Management', '120000', '12.5', '25000'] as $secret) {
            $this->assertFalse(str_contains((string) $hr->getContent(), $secret), "{$secret} must not be anywhere in what HR receives");
        }
        // ... but the Sell STATE, the type and the About side are there, as the contract says.
        $rows = $this->byName(self::HR);
        $this->assertSame('sold', $rows['Scholar One ERP - Gujarat Edition']['state']);
        $this->assertSame('service', $rows['ScholarClone On-Premise & Cloud Implementation']['type']);
        $this->assertSame('Prototype', $rows['Scholar School ERP']['readiness']);
        $this->assertSame(['createProduct' => false, 'createSubProduct' => true, 'editSubProduct' => true, 'viewSell' => false, 'confirmMatches' => false], $hr->json('meta.can'));
        // and the same for a person with no right at all
        foreach ($this->rows(self::NOBODY, 'per=100') as $row) {
            $this->assertArrayNotHasKey('unitPrice', $row);
            $this->assertArrayNotHasKey('sku', $row);
        }
    }

    public function test_a_sales_like_profile_sees_the_sell_fields_and_the_flags_of_a_seller(): void
    {
        $rows = $this->byName(self::SALES);
        $gujarat = $rows['Scholar One ERP - Gujarat Edition'];
        $this->assertEquals(['sku' => 'SCH-01-GUJ', 'category' => 'School ERP', 'qtyInStock' => 12.5, 'unitPrice' => 120000.0, 'currency' => null], array_intersect_key($gujarat, array_flip(self::SELL_KEYS)));
        $this->assertEquals(['unitPrice' => 25000.0, 'qtyInStock' => null], array_intersect_key($rows['ScholarClone On-Premise & Cloud Implementation'], ['unitPrice' => 1, 'qtyInStock' => 1]), 'a service never shows stock, whatever an old import left');
        $this->assertSame(['createProduct' => false, 'createSubProduct' => true, 'editSubProduct' => true, 'viewSell' => true, 'confirmMatches' => false], $this->list(self::SALES)->json('meta.can'));
        $this->assertSame(['createProduct' => true, 'createSubProduct' => true, 'editSubProduct' => true, 'viewSell' => true, 'confirmMatches' => true], $this->list(self::ADMIN)->json('meta.can'));
    }

    public function test_each_row_shows_its_sell_fields_only_to_a_caller_who_may_view_its_own_type(): void
    {
        $asProducts = $this->byName(self::PRODUCTS_VIEWER);
        $asServices = $this->byName(self::SERVICES_VIEWER);

        $this->assertArrayHasKey('unitPrice', $asProducts['Scholar One ERP - Gujarat Edition']);
        $this->assertArrayNotHasKey('unitPrice', $asProducts['ScholarClone On-Premise & Cloud Implementation'], 'a Products viewer does not see a service\'s price');
        $this->assertArrayNotHasKey('sku', $asProducts['ScholarClone On-Premise & Cloud Implementation']);
        $this->assertArrayNotHasKey('unitPrice', $asServices['Scholar One ERP - Gujarat Edition'], 'a Services viewer does not see a product\'s price');
        $this->assertArrayNotHasKey('category', $asServices['Scholar One ERP - Gujarat Edition']);
        $this->assertEquals(25000.0, $asServices['ScholarClone On-Premise & Cloud Implementation']['unitPrice']);
        $this->assertSame(['viewSell' => true], array_intersect_key($this->list(self::SERVICES_VIEWER)->json('meta.can'), ['viewSell' => 1]));
    }

    // ================================================================== tenants

    public function test_another_organisations_rows_are_never_listed_and_the_shared_products_are_flagged(): void
    {
        $own = DB::table('g2g_product_master')->insertGetId(['sub_institute_id' => self::T, 'product_key' => 'payroll', 'kind' => 'product', 'name' => 'Payroll', 'portfolio_name' => 'Payroll', 'status' => 'active', 'origin' => 'custom', 'created_at' => now(), 'updated_at' => now()]);
        $this->item('Their item', ['sku' => 'THEIRS-1', 'unit_price' => 99], self::T2);

        $mine = $this->byName(self::ADMIN);
        $theirs = $this->byName(self::ADMIN2);

        $this->assertArrayHasKey('Payroll', $mine);
        $this->assertArrayNotHasKey('Their item', $mine);
        $this->assertArrayHasKey('Their item', $theirs);
        $this->assertArrayNotHasKey('Payroll', $theirs, 'organisation 1\'s own Product');
        $this->assertArrayNotHasKey('Scholar School ERP', $theirs);
        $this->assertArrayNotHasKey('Scholar One ERP - Gujarat Edition', $theirs);
        $this->assertSame(['ScholarClone' => true, 'Payroll' => false, 'Scholar School ERP' => false], array_map(fn ($n) => $mine[$n]['shared'], ['ScholarClone' => 'ScholarClone', 'Payroll' => 'Payroll', 'Scholar School ERP' => 'Scholar School ERP']),
            'the shared Product is flagged `shared`: readable, not changeable');
        $this->assertTrue($theirs['ScholarClone']['shared']);
        // the filters cannot reach across: organisation 2 asking for organisation 1's Product or SKU finds nothing
        $this->assertSame([], $this->rows(self::ADMIN2, 'productId=' . $own));
        $this->assertSame([], $this->rows(self::ADMIN2, 'q=SCH-01-GUJ'));
        $this->assertSame([], $this->rows(self::ADMIN, 'q=THEIRS-1'));
        // a Sell pointer that points into another organisation is not followed (no price of theirs under my row)
        DB::table('g2g_product_master')->where('id', $this->w['subs']['SCH-02']['master'])->update(['crm_product_id' => DB::table('crm_products')->where('sub_institute_id', self::T2)->value('id')]);
        $row = $this->byName(self::ADMIN)['Fees Intelligence & Decision Support'];
        $this->assertFalse($row['hasSell']);
        $this->assertArrayNotHasKey('unitPrice', $row);
    }

    // ================================================================== paging

    public function test_the_pages_put_together_are_the_whole_list_once_in_one_order(): void
    {
        $all = array_column($this->rows(self::ADMIN, 'per=100'), 'id');
        $this->assertCount(17 + $this->builtIn(), $all);
        $this->assertCount(17 + $this->builtIn(), array_unique($all));

        $pages = [];
        foreach ([1, 2, 3, 4, 5] as $page) {
            $res = $this->list(self::ADMIN, "per=5&page={$page}")->assertOk();
            $this->assertSame([17 + $this->builtIn(), $page, 5, (int) ceil((17 + $this->builtIn()) / 5)], [$res->json('meta.total'), $res->json('meta.page'), $res->json('meta.perPage'), $res->json('meta.lastPage')]);
            $pages = array_merge($pages, array_column($res->json('data'), 'id'));
        }
        $this->assertSame($all, $pages, 'one order across pages: nothing twice, nothing missing');
        $this->assertSame([], $this->rows(self::ADMIN, 'per=5&page=6'), 'past the end is an empty page, not an error');
        $this->assertSame(17 + $this->builtIn(), $this->list(self::ADMIN, 'per=5&page=6')->json('meta.total'));
    }

    public function test_per_is_held_to_5_to_100_and_a_bad_page_is_page_one(): void
    {
        $perPage = fn (string $q) => $this->list(self::ADMIN, $q)->assertOk()->json('meta.perPage');

        $this->assertSame(100, $perPage('per=500'), 'a request for more than 100 is clamped');
        $this->assertSame(100, $perPage('per=100'));
        $this->assertSame(5, $perPage('per=1'));
        $this->assertSame(5, $perPage('per=-3'));
        $this->assertSame(20, $perPage('per=abc'));
        $this->assertSame(20, $perPage(''));
        $this->assertCount(17 + $this->builtIn(), $this->rows(self::ADMIN, 'per=500'), 'the whole list fits in the clamped page');
        foreach (['page=0', 'page=-4', 'page=abc'] as $bad) {
            $this->assertSame(1, $this->list(self::ADMIN, $bad)->json('meta.page'), $bad);
        }
    }

    // ================================================================== the order: groups

    public function test_the_rows_come_in_groups_a_product_then_its_sub_products_and_the_strays_last(): void
    {
        $stray = DB::table('g2g_product_master')->insertGetId(['sub_institute_id' => self::T, 'product_key' => 'crm/stray', 'kind' => 'item', 'name' => 'Aaa stray item', 'parent_id' => null, 'status' => 'active', 'origin' => 'crm', 'created_at' => now(), 'updated_at' => now()]);
        $rows = $this->rows(self::ADMIN, 'per=100');

        $this->assertSame($stray, end($rows)['id'], 'a Sub-product with no Product is last, however early its name sorts');
        $this->assertTrue(end($rows)['needsProduct']);
        $seen = [];
        $current = null;
        foreach ($rows as $row) {
            if ($row['kind'] === 'product') {
                $this->assertNotContains($row['id'], $seen, 'a Product row appears once');
                $seen[] = $row['id'];
                $current = $row['id'];
            } elseif (! $row['needsProduct']) {
                $this->assertSame($current, $row['productId'], "{$row['name']} comes right after its own Product, in one run");
            }
        }
        // the Product rows are alphabetical and each holds its Sub-products by name
        $products = array_values(array_filter($rows, fn ($r) => $r['kind'] === 'product'));
        $this->assertSame($this->builtInNames(), array_column($products, 'name'));
        $scholar = array_values(array_filter($rows, fn ($r) => ($r['productId'] ?? null) === $this->w['product']));
        $names = array_column($scholar, 'name');
        $this->assertSame($this->sortedCopy($names), $names, 'Sub-products by name inside a Product');
    }

    // ================================================================== filters

    public function test_q_finds_a_name_or_an_offer_id_in_any_letter_case_and_a_percent_or_underscore_is_a_letter_not_a_wildcard(): void
    {
        $this->item('Cotton 100% pure', ['sku' => 'COT-1']);
        $this->item('Under_score kit', ['sku' => 'US-1']);

        $this->assertSame(['Scholar Fleet GPS Bus Tracking System'], $this->names(self::ADMIN, 'q=FLEET'));
        $this->assertSame(['LMS-PAL (AI question generation)'], $this->names(self::HR, 'q=sch-03'), 'an offer id, for HR too');
        $this->assertSame(['Cotton 100% pure'], $this->names(self::ADMIN, 'q=' . urlencode('100%')));
        $this->assertSame(['Cotton 100% pure'], $this->names(self::ADMIN, 'q=' . urlencode('%')), 'a % is a letter: it finds the one name that has one, not everything');
        $this->assertSame(['Under_score kit'], $this->names(self::ADMIN, 'q=' . urlencode('_')), 'so is an underscore');
        $this->assertSame([], $this->names(self::ADMIN, 'q=' . urlencode('Cot%ure')), 'a % in the middle is no wildcard either');
        $this->assertSame([], $this->names(self::ADMIN, 'q=nothing-has-this'));
        $this->assertCount(19 + $this->builtIn(), $this->rows(self::ADMIN, 'per=100&q='), 'an empty search is no search');
    }

    public function test_the_sku_is_searchable_only_for_a_caller_who_may_view_the_type_of_that_item(): void
    {
        $find = fn (int $profile, string $sku) => $this->names($profile, 'q=' . $sku);

        $this->assertSame(['Scholar One ERP - Gujarat Edition'], $find(self::ADMIN, 'SCH-01-GUJ'));
        $this->assertSame(['Scholar One ERP - Gujarat Edition'], $find(self::SALES, 'SCH-01-GUJ'));
        $this->assertSame([], $find(self::HR, 'SCH-01-GUJ'), 'HR cannot find an item by its SKU: a search must not be a way to read it');
        $this->assertSame([], $find(self::HR, 'SCH-SRV-IMP'));
        $this->assertSame([], $find(self::NOBODY, 'SCH-01-GUJ'));
        $this->assertSame(['Scholar One ERP - Gujarat Edition'], $find(self::PRODUCTS_VIEWER, 'SCH-01-GUJ'));
        $this->assertSame([], $find(self::PRODUCTS_VIEWER, 'SCH-SRV-IMP'), 'a Products viewer cannot find a SERVICE by its SKU');
        $this->assertSame(['ScholarClone On-Premise & Cloud Implementation'], $find(self::SERVICES_VIEWER, 'SCH-SRV-IMP'));
        $this->assertSame([], $find(self::SERVICES_VIEWER, 'SCH-01-GUJ'));
    }

    // ================================================================== WPA1 item 6: the catalogue id on the row, and the product number in the search

    public function test_a_row_carries_the_catalogue_id_for_a_caller_who_may_view_the_sell_of_its_type_and_null_for_a_record_with_no_sell_side(): void
    {
        $gujarat = $this->w['items']['gujarat']['crm'];
        $implementation = $this->w['items']['implementation']['crm'];

        foreach ([self::ADMIN, self::SALES] as $profile) {
            $rows = $this->byName($profile);
            $this->assertSame($gujarat, $rows['Scholar One ERP - Gujarat Edition']['crmProductId'], "profile {$profile}: the id of the catalogue row");
            $this->assertSame($implementation, $rows['ScholarClone On-Premise & Cloud Implementation']['crmProductId'], 'a service too');
            $this->assertArrayHasKey('crmProductId', $rows['Scholar School ERP'], 'a Sub-product with no Sell side: the key is there');
            $this->assertNull($rows['Scholar School ERP']['crmProductId']);
            $this->assertNull($rows['ScholarClone']['crmProductId'] ?? null, 'a level-1 Product has no Sell side');
            $this->assertArrayHasKey('crmProductId', $rows['ScholarClone']);
        }

        // Each type is judged on its own: a Products viewer is not given the id of a service, nor a Services viewer the id of a product.
        $asProducts = $this->byName(self::PRODUCTS_VIEWER);
        $this->assertSame($gujarat, $asProducts['Scholar One ERP - Gujarat Edition']['crmProductId']);
        $this->assertArrayHasKey('crmProductId', $asProducts['ScholarClone On-Premise & Cloud Implementation']);
        $this->assertNull($asProducts['ScholarClone On-Premise & Cloud Implementation']['crmProductId'], 'the id of a service is not given to a caller who may not view services');
        $asServices = $this->byName(self::SERVICES_VIEWER);
        $this->assertSame($implementation, $asServices['ScholarClone On-Premise & Cloud Implementation']['crmProductId']);
        $this->assertNull($asServices['Scholar One ERP - Gujarat Edition']['crmProductId']);
    }

    public function test_hr_and_a_person_with_no_right_never_receive_the_catalogue_id_not_even_as_null(): void
    {
        foreach ([self::HR, self::NOBODY] as $profile) {
            foreach ($this->rows($profile, 'per=100') as $row) {
                $this->assertArrayNotHasKey('crmProductId', $row, "profile {$profile} must not learn whether '{$row['name']}' is sold, nor its catalogue id");
            }
        }
        $this->assertStringNotContainsString('crmProductId', (string) $this->list(self::HR, 'per=100')->getContent());
    }

    public function test_the_product_number_is_searchable_only_for_a_caller_who_may_view_the_type_of_that_item(): void
    {
        $numberOf = fn (string $key): string => (string) DB::table('crm_products')->where('id', $this->w['items'][$key]['crm'])->value('product_no');
        $product = $numberOf('gujarat');
        $service = $numberOf('implementation');
        $this->assertNotSame('', $product);
        $this->assertNotSame($product, $service, 'two items, two numbers');
        $find = fn (int $profile, string $q) => $this->names($profile, 'q=' . urlencode($q));

        $this->assertSame(['Scholar One ERP - Gujarat Edition'], $find(self::ADMIN, $product), 'the whole number');
        $this->assertSame(['Scholar One ERP - Gujarat Edition'], $find(self::ADMIN, strtolower($product)), 'in any letter case');
        $this->assertContains('Scholar One ERP - Gujarat Edition', $find(self::ADMIN, substr($product, 0, 6)), 'and a part of it');
        $this->assertSame(['Scholar One ERP - Gujarat Edition'], $find(self::SALES, $product));
        $this->assertSame([], $find(self::HR, $product), 'HR cannot find an item by its number: a search must not be a way to read it');
        $this->assertSame([], $find(self::HR, $service));
        $this->assertSame([], $find(self::NOBODY, $product));
        $this->assertSame(['Scholar One ERP - Gujarat Edition'], $find(self::PRODUCTS_VIEWER, $product));
        $this->assertSame([], $find(self::PRODUCTS_VIEWER, $service), 'a Products viewer cannot find a SERVICE by its number');
        $this->assertSame(['ScholarClone On-Premise & Cloud Implementation'], $find(self::SERVICES_VIEWER, $service));
        $this->assertSame([], $find(self::SERVICES_VIEWER, $product));
    }

    public function test_the_product_and_type_filters_leave_only_the_sub_products_asked_for(): void
    {
        $scholar = $this->rows(self::ADMIN, 'per=100&productId=' . $this->w['product']);
        $this->assertCount(16, $scholar, '7 Sub-products with an About and 9 catalogue items; not the Product row, not Enterprise Brain\'s');
        $this->assertNotContains('product', array_column($scholar, 'kind'));
        $this->assertSame(['Enterprise Brain Intelligence Layer'], $this->names(self::ADMIN, 'productId=' . $this->w['other']));
        $this->assertSame([], $this->rows(self::ADMIN, 'productId=999999'));

        $services = $this->rows(self::ADMIN, 'type=service');
        $this->assertEqualsCanonicalizing(['ScholarClone On-Premise & Cloud Implementation', 'ScholarClone Faculty & Staff Certification Training'], array_column($services, 'name'));
        $this->assertSame(['service', 'service'], array_column($services, 'type'));
        $subs = $this->rows(self::ADMIN, 'per=100&type=subproduct');
        $this->assertCount(15, $subs, '8 with an About (they are Sub-products, never services) and 7 product items; no Product row, no service');
        $this->assertNotContains('service', array_column($subs, 'type'));
        $this->assertNotContains('product', array_column($subs, 'kind'));
        $this->assertCount(17 + $this->builtIn(), $this->rows(self::ADMIN, 'per=100&type=whatever'), 'an unknown type is no filter');
    }

    public function test_the_sold_filter_and_the_four_states(): void
    {
        DB::table('crm_products')->where('id', $this->w['items']['training']['crm'])->update(['is_active' => 0]);
        DB::table('crm_products')->where('id', $this->w['items']['fleet']['crm'])->update(['deleted_at' => now()]);
        $state = fn (string $name) => $this->byName(self::HR)[$name]['state'];

        $this->assertSame('sold', $state('Scholar One ERP - Gujarat Edition'));
        $this->assertSame('paused', $state('ScholarClone Faculty & Staff Certification Training'), 'switched off, still there');
        $this->assertSame('binned', $state('Scholar Fleet GPS Bus Tracking System'), 'in the Recycle Bin');
        $this->assertSame('not_sold', $state('Scholar School ERP'), 'an About with no catalogue row');
        $this->assertNull($state('ScholarClone'), 'a Product has no Sell state');

        $this->assertCount(7, $this->rows(self::ADMIN, 'sold=yes'), '7 items are sold');
        $this->assertSame(['ScholarClone Faculty & Staff Certification Training'], $this->names(self::ADMIN, 'sold=paused'));
        $no = $this->rows(self::ADMIN, 'per=100&sold=no');
        $this->assertCount(9, $no, 'the 8 Sub-products with no catalogue row and the one in the Bin; no Product row');
        $this->assertContains('Scholar Fleet GPS Bus Tracking System', array_column($no, 'name'));
        $this->assertNotContains('product', array_column($no, 'kind'));
        $this->assertCount(17 + $this->builtIn(), $this->rows(self::ADMIN, 'per=100&sold=maybe'), 'an unknown value is no filter');
        $this->assertSame(['sold' => 7, 'no' => 9, 'paused' => 1], ['sold' => count($this->rows(self::HR, 'sold=yes')), 'no' => count($this->rows(self::HR, 'per=100&sold=no')), 'paused' => count($this->rows(self::HR, 'sold=paused'))], 'HR may filter by the STATE, as the contract says');
    }

    public function test_the_readiness_filter_matches_the_offers_readiness_and_leaves_out_items_and_products(): void
    {
        DB::table('g2g_product_offers')->where('id', $this->w['subs']['SCH-01']['offer'])->update(['readiness_status' => 'Live – with client']);

        $this->assertSame(['Scholar School ERP'], $this->names(self::HR, 'readiness=' . urlencode('Live – with client')));
        $this->assertCount(7, $this->rows(self::HR, 'readiness=Prototype'), 'the other 7 Sub-products with an About; no item, no Product');
        $this->assertSame([], $this->rows(self::HR, 'readiness=Nothing'));
    }

    public function test_status_lists_active_by_default_archived_on_request_and_all_so_an_archived_record_can_be_found(): void
    {
        DB::table('g2g_product_master')->where('id', $this->w['subs']['SCH-07']['master'])->update(['status' => 'archived']);
        DB::table('g2g_product_master')->where('product_key', 'g2g')->update(['status' => 'archived']);

        $active = $this->rows(self::ADMIN, 'per=100');
        $this->assertCount(15 + $this->builtIn(), $active);
        $this->assertNotContains('School FTS / Digital Office', array_column($active, 'name'));
        $this->assertSame(['active'], array_values(array_unique(array_column($active, 'status'))));
        $this->assertSame(15, $this->byName(self::ADMIN)['ScholarClone']['subProductCount'], 'the count follows the status: one of the 16 is archived');

        $archived = $this->rows(self::ADMIN, 'status=archived');
        $this->assertEqualsCanonicalizing(['G2G', 'School FTS / Digital Office'], array_column($archived, 'name'));
        $this->assertSame(['archived'], array_values(array_unique(array_column($archived, 'status'))));

        $all = $this->rows(self::ADMIN, 'per=100&status=all');
        $this->assertCount(17 + $this->builtIn(), $all, 'active and archived together');
        $this->assertEqualsCanonicalizing(['active', 'archived'], array_values(array_unique(array_column($all, 'status'))));
        $this->assertSame(16, $this->byName(self::ADMIN, 'per=100&status=all')['ScholarClone']['subProductCount']);
        $this->assertCount(15 + $this->builtIn(), $this->rows(self::ADMIN, 'per=100&status=bogus'), 'an unknown status is the default');
        $this->assertSame(17 + $this->builtIn(), $this->list(self::ADMIN, 'status=all')->json('meta.total'));
        $this->assertSame(2, $this->list(self::ADMIN, 'status=archived')->json('meta.total'));
    }

    // ================================================================== sorts

    /** @return list<string> the Sub-products of ScholarClone, in the order they come */
    private function scholarOrder(int $profile, string $query): array
    {
        $rows = $this->rows($profile, 'per=100&' . $query);
        $out = [];
        foreach ($rows as $row) {
            if (($row['productId'] ?? null) === $this->w['product']) {
                $out[] = $row['name'];
            }
        }

        return $out;
    }

    public function test_the_default_sort_is_name_and_an_unknown_sort_or_direction_is_the_default(): void
    {
        $default = $this->names(self::ADMIN, 'per=100');

        $this->assertSame($default, $this->names(self::ADMIN, 'per=100&sort=name&dir=asc'));
        $this->assertSame($default, $this->names(self::ADMIN, 'per=100&sort=bogus'), 'an unknown sort is name');
        $this->assertSame($default, $this->names(self::ADMIN, 'per=100&sort=' . urlencode('name; DROP TABLE x')));
        $this->assertSame($default, $this->names(self::ADMIN, 'per=100&dir=sideways'), 'an unknown direction is asc');
        $this->assertSame($default, $this->names(self::ADMIN, 'per=100&sort=name&dir=ASC'));
    }

    public function test_sorting_by_name_descending_turns_the_groups_and_the_names_round_but_keeps_each_product_row_first(): void
    {
        $rows = $this->rows(self::ADMIN, 'per=100&sort=name&dir=desc');
        $products = array_values(array_filter($rows, fn ($r) => $r['kind'] === 'product'));

        $this->assertSame(array_reverse($this->builtInNames()), array_column($products, 'name'));
        $this->assertSame('ScholarClone', $rows[0]['name'], 'the Product row comes first in its group');
        $names = $this->scholarOrder(self::ADMIN, 'sort=name&dir=desc');
        $asc = $this->scholarOrder(self::ADMIN, 'sort=name&dir=asc');
        $this->assertSame(array_reverse($asc), $names);
        $this->assertSame('School FTS / Digital Office', $names[0], 'Z to A');
    }

    public function test_sorting_by_price_orders_a_sell_viewer_by_price_and_leaves_the_rows_with_none_last_in_either_direction(): void
    {
        $order = fn (string $dir) => array_slice($this->scholarOrder(self::SALES, "sort=price&dir={$dir}"), 0, 9);
        $byPrice = ['ScholarClone Faculty & Staff Certification Training', 'ScholarClone On-Premise & Cloud Implementation', 'Scholar Smart Attendance & Biometric Access',
            'Scholar CCB Fee Collection System', 'Scholar Examination & CCE Report Engine', 'Scholar LMS & Digital Classroom', 'Scholar Fleet GPS Bus Tracking System',
            'Scholar Core School Management', 'Scholar One ERP - Gujarat Edition'];   // 15000, 25000, 30000, 35000, 40000, 45000, 50000, 75000, 120000

        $this->assertSame($byPrice, $order('asc'));
        $this->assertSame(array_reverse($byPrice), $order('desc'));
        $this->assertCount(7, array_slice($this->scholarOrder(self::SALES, 'sort=price&dir=desc'), 9), 'the 7 Sub-products with no price come after the priced ones, in either direction');
        $this->assertSame($byPrice, array_slice($this->scholarOrder(self::ADMIN, 'sort=price'), 0, 9));
    }

    public function test_a_caller_who_may_view_no_sell_cannot_sort_by_price_it_is_the_name_order(): void
    {
        $byName = $this->names(self::HR, 'per=100');

        $this->assertSame($byName, $this->names(self::HR, 'per=100&sort=price&dir=asc'), 'HR asking for a price order gets the name order: the order would leak the prices');
        $this->assertSame($this->names(self::HR, 'per=100&dir=desc'), $this->names(self::HR, 'per=100&sort=price&dir=desc'));
        $this->assertSame($this->names(self::NOBODY, 'per=100'), $this->names(self::NOBODY, 'per=100&sort=price'));
        $this->assertNotSame($byName, $this->names(self::ADMIN, 'per=100&sort=price&dir=asc'), 'the control: for a Sell viewer the same request does re-order');
    }

    public function test_the_price_order_of_a_caller_does_not_leak_the_prices_of_a_type_they_may_not_view(): void
    {
        $order = $this->scholarOrder(self::PRODUCTS_VIEWER, 'sort=price&dir=asc');

        $this->assertSame(['Scholar Smart Attendance & Biometric Access', 'Scholar CCB Fee Collection System', 'Scholar Examination & CCE Report Engine', 'Scholar LMS & Digital Classroom',
            'Scholar Fleet GPS Bus Tracking System', 'Scholar Core School Management', 'Scholar One ERP - Gujarat Edition'], array_slice($order, 0, 7), 'the 7 products in price order');
        $this->assertEqualsCanonicalizing(['ScholarClone Faculty & Staff Certification Training', 'ScholarClone On-Premise & Cloud Implementation'], array_slice(array_filter($order, fn ($n) => str_starts_with($n, 'ScholarClone ')), 0), 'the services are among the rows with no visible price, not at their price (15000 and 25000 would put them first)');
        $this->assertGreaterThanOrEqual(7, array_search('ScholarClone Faculty & Staff Certification Training', $order));
        $this->assertGreaterThanOrEqual(7, array_search('ScholarClone On-Premise & Cloud Implementation', $order));
    }

    public function test_sorting_by_readiness_orders_the_sub_products_inside_their_product_and_leaves_the_rows_without_one_last(): void
    {
        DB::table('g2g_product_offers')->where('id', $this->w['subs']['SCH-01']['offer'])->update(['readiness_status' => 'Live']);
        DB::table('g2g_product_offers')->where('id', $this->w['subs']['SCH-02']['offer'])->update(['readiness_status' => 'Alpha']);

        $prototypes = ['LMS-PAL (AI question generation)', 'Career Intelligence & Counselling', 'Prompt-to-Video Lessons', 'Learning ESO (mastery execution)', 'School FTS / Digital Office'];
        $items = array_keys(array_filter($this->byName(self::HR), fn ($r) => $r['kind'] === 'item'));   // no readiness at all

        $asc = $this->scholarOrder(self::HR, 'sort=readiness&dir=asc');
        $this->assertCount(14 + 2, $asc);
        $this->assertSame(['Fees Intelligence & Decision Support', 'Scholar School ERP'], array_slice($asc, 0, 2), 'Alpha, then Live');
        $this->assertEqualsCanonicalizing($prototypes, array_slice($asc, 2, 5), 'then Prototype');
        $this->assertEqualsCanonicalizing($items, array_slice($asc, 7), 'the rows with no readiness are last');

        $desc = $this->scholarOrder(self::HR, 'sort=readiness&dir=desc');
        $this->assertEqualsCanonicalizing($prototypes, array_slice($desc, 0, 5), 'descending: Prototype first');
        $this->assertSame(['Scholar School ERP', 'Fees Intelligence & Decision Support'], array_slice($desc, 5, 2), 'then Live, then Alpha');
        $this->assertEqualsCanonicalizing($items, array_slice($desc, 7), 'the rows with no readiness are last in either direction');
    }

    public function test_sorting_by_signals_counts_only_this_organisations_real_signals_and_leaves_rows_with_no_offer_last(): void
    {
        $signal = fn (string $offer, int $tenant = self::T, int $sample = 0) => DB::table('g2g_company_opportunities')->insert(['sub_institute_id' => $tenant, 'relevant_offer_id' => $offer, 'is_sample' => $sample, 'product_master_id' => null]);
        foreach (['SCH-03', 'SCH-03', 'SCH-03', 'SCH-01'] as $offer) {
            $signal($offer);
        }
        $signal('SCH-01', self::T, 1);       // a sample row is not a signal
        $signal('SCH-02', self::T2);         // another organisation's is not mine
        $by = $this->byName(self::HR);
        $this->assertSame([3, 1, 0], [$by['LMS-PAL (AI question generation)']['signalsFound'], $by['Scholar School ERP']['signalsFound'], $by['Fees Intelligence & Decision Support']['signalsFound']]);

        $desc = $this->scholarOrder(self::HR, 'sort=signals&dir=desc');
        $this->assertSame(['LMS-PAL (AI question generation)', 'Scholar School ERP'], array_slice($desc, 0, 2), '3 signals, then 1');
        $this->assertCount(5, array_slice($desc, 2, 5));
        $items = array_keys(array_filter($by, fn ($r) => $r['kind'] === 'item'));
        $this->assertEqualsCanonicalizing($items, array_slice($desc, 7), 'items have no offer, so no signal count: last');

        $asc = $this->scholarOrder(self::HR, 'sort=signals&dir=asc');
        $this->assertSame(['Scholar School ERP', 'LMS-PAL (AI question generation)'], array_slice($asc, 5, 2), 'ascending: the five with none, then 1, then 3');
        $this->assertEqualsCanonicalizing($items, array_slice($asc, 7), 'still last');
    }

    /** @param list<string> $names @return list<string> */
    private function sortedCopy(array $names): array
    {
        $copy = $names;
        usort($copy, 'strcmp');

        return $copy;
    }
}
