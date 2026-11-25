<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\ProductPriceTotals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsProductReadWorld;
use Tests\TestCase;

/**
 * S16 (25 Nov 2026), contract section 4: what a Product costs (`priceTotals`, `subProductsCounted` on a Product row of the list and on the Product record), `taxPercent` on every Sell
 * row, and what `GET /api/product-master/meta` says about currencies.
 *
 * The world: the Product ScholarClone with ten things under it (see items()): four that count in INR, one in USD, and the ones that must NOT count (no price yet, paused, in the
 * Recycle Bin, an archived Sub-product, an About with no Sell). The rules proved, each with the mutation that breaks it (evidence file of S16):
 *   - the tax of each row is the rule of TaxCalculator on that row's own rate, summed per currency; an empty currency counts under the default (INR)
 *   - a row that is archived, paused, binned, unpriced or an About only is not counted; the unsold active ones are `uncounted`
 *   - a caller who may view no Sell sees no totals; one who may view products only does not see a service counted (nor missed)
 *   - ONE grouped query: the same number of statements for 3 and for 12 Sub-products
 */
class ProductPriceTotalsTest extends TestCase
{
    use BuildsProductReadWorld;

    private int $gst18;

    private int $gst5;

    private int $product;

    /** @var array<string, array{master: int, crm: int}> */
    private array $it = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildReadWorld();
        Schema::table('crm_tax_rates', fn ($t) => $t->string('currency', 3)->nullable());
        $this->gst18 = (int) DB::table('crm_tax_rates')->insertGetId(['sub_institute_id' => self::T, 'name' => 'GST 18%', 'percentage' => 18, 'currency' => 'INR', 'is_active' => 1]);
        $this->gst5 = (int) DB::table('crm_tax_rates')->insertGetId(['sub_institute_id' => self::T, 'name' => 'GST 5%', 'percentage' => 5, 'currency' => null, 'is_active' => 1]);
        $this->product = $this->sharedId('scholarclone', self::T);
        $this->items();
    }

    /** Ten things under the Product, each in the state its name says. */
    private function items(): void
    {
        $p = fn (array $over): array => $over + ['product_master_id' => $this->product];
        $this->it['a'] = $this->item('A rupee product at 18', $p(['unit_price' => 1000, 'currency' => 'INR', 'tax_rate_id' => $this->gst18]));
        $this->it['b'] = $this->item('B rupee product at 5', $p(['unit_price' => 999.99, 'currency' => 'inr', 'tax_rate_id' => $this->gst5]));
        $this->it['usd'] = $this->item('C dollar product no tax', $p(['unit_price' => 100, 'currency' => 'USD']));
        $this->it['service'] = $this->item('G rupee service no rate', $p(['unit_price' => 300, 'currency' => 'INR', 'item_type' => 'service']));
        $this->it['blank'] = $this->item('H no currency at 18', $p(['unit_price' => 200, 'currency' => '', 'tax_rate_id' => $this->gst18]));
        $this->it['noprice'] = $this->item('D no price yet', $p(['unit_price' => null, 'currency' => 'INR']));
        $this->it['paused'] = $this->item('E paused', $p(['unit_price' => 500, 'currency' => 'INR', 'is_active' => 0]));
        // F: a Sub-product whose Sell row has been put in the Recycle Bin (the record stays, with its About): the row is there but binned, so it is not counted
        $this->it['binned'] = $this->item('F binned', $p(['unit_price' => 500, 'currency' => 'INR']));
        DB::table('crm_products')->where('id', $this->it['binned']['crm'])->update(['deleted_at' => now()]);
        $this->it['archived'] = $this->item('I archived', $p(['unit_price' => 9999, 'currency' => 'INR']));
        DB::table('g2g_product_master')->where('id', $this->it['archived']['master'])->update(['status' => 'archived']);
        $about = $this->subProduct('SCH-ABOUT', 'J About only', ['parent_product' => 'Scholar K-12']);
        DB::table('g2g_product_master')->where('id', $about['master'])->update(['parent_id' => $this->product]);
    }

    /** @return array<string, mixed> the ScholarClone row of the list, as a profile sees it */
    private function row(int $profile = self::ADMIN): array
    {
        $rows = $this->getJson('/api/product-master/list?per=100', $this->as($profile))->assertOk()->json('data');
        $mine = array_values(array_filter($rows, fn ($r) => (int) $r['id'] === $this->product));
        $this->assertCount(1, $mine);

        return $mine[0];
    }

    public function test_a_product_totals_its_sub_products_per_currency_with_the_tax_of_each_rows_own_rate(): void
    {
        $row = $this->row();

        // A 1000 + 180, B 999.99 + 50.00 (5% of 999.99 is 49.9995, half up), G 300 + 0, H 200 + 36 (empty currency: the default INR) -> four rows in rupees; C 100 dollars
        $this->assertEquals([
            ['currency' => 'INR', 'subtotal' => 2499.99, 'tax' => 266.0, 'total' => 2765.99, 'counted' => 4, 'uncounted' => 4],
            ['currency' => 'USD', 'subtotal' => 100.0, 'tax' => 0.0, 'total' => 100.0, 'counted' => 1, 'uncounted' => 4],
        ], $row['priceTotals']);
        $this->assertSame(5, $row['subProductsCounted']);
    }

    public function test_what_is_not_sold_at_a_price_is_not_counted_and_the_unsold_active_ones_are_the_uncounted(): void
    {
        // uncounted = D (no price), E (paused), F (its Sell row is in the Recycle Bin), J (an About with no Sell): 4. I is archived: not active, so neither counted nor missing.
        $this->assertSame(4, $this->row()['priceTotals'][0]['uncounted']);

        DB::table('crm_products')->where('id', $this->it['paused']['crm'])->update(['is_active' => 1]);
        $row = $this->row();
        $this->assertSame(3, $row['priceTotals'][0]['uncounted'], 'a paused item that is switched on counts');
        $this->assertSame(2999.99, $row['priceTotals'][0]['subtotal']);
        $this->assertSame(5, $row['priceTotals'][0]['counted']);
    }

    public function test_a_product_with_nothing_priced_has_no_total_and_a_row_that_is_not_a_product_has_no_such_key(): void
    {
        $other = $this->sharedId('enterprise_brain', self::T);
        $rows = $this->getJson('/api/product-master/list?per=100', $this->as(self::ADMIN))->assertOk()->json('data');
        $row = array_values(array_filter($rows, fn ($r) => (int) $r['id'] === $other))[0];
        $this->assertSame([], $row['priceTotals']);
        $this->assertSame(0, $row['subProductsCounted']);

        $sub = array_values(array_filter($rows, fn ($r) => $r['kind'] !== 'product'))[0];
        $this->assertArrayNotHasKey('priceTotals', $sub);
    }

    public function test_a_caller_who_may_view_no_price_gets_no_totals_and_one_who_may_view_products_only_does_not_see_services(): void
    {
        $hr = $this->row(self::HR);
        $this->assertSame([], $hr['priceTotals']);
        $this->assertSame(0, $hr['subProductsCounted']);

        $products = $this->row(self::PRODUCTS_VIEWER);
        $this->assertSame(['INR' => [3, 4], 'USD' => [1, 4]], collect($products['priceTotals'])->mapWithKeys(fn ($t) => [$t['currency'] => [$t['counted'], $t['uncounted']]])->all(), 'the service is neither counted nor missing');
        $this->assertEquals(2199.99, $products['priceTotals'][0]['subtotal']);

        $services = $this->row(self::SERVICES_VIEWER);
        // a services viewer sees one service counted; of the product-type items nothing is counted or missed, only the About with no Sell (J) is "no price yet"
        $this->assertEquals([['currency' => 'INR', 'subtotal' => 300.0, 'tax' => 0.0, 'total' => 300.0, 'counted' => 1, 'uncounted' => 1]], $services['priceTotals']);
    }

    public function test_the_product_record_carries_the_same_totals_and_a_sell_record_its_tax_percent(): void
    {
        $record = $this->getJson("/api/product-master/{$this->product}", $this->as(self::ADMIN))->assertOk()->json('data');
        $this->assertSame($this->row()['priceTotals'], $record['priceTotals']);
        $this->assertSame(5, $record['subProductsCounted']);

        $a = $this->getJson("/api/product-master/{$this->it['a']['master']}", $this->as(self::ADMIN))->assertOk()->json('data');
        $this->assertEquals(18.0, $a['sell']['taxPercent']);
        $this->assertSame('GST 18%', $a['sell']['taxRateName']);
        $usd = $this->getJson("/api/product-master/{$this->it['usd']['master']}", $this->as(self::ADMIN))->assertOk()->json('data');
        $this->assertNull($usd['sell']['taxPercent']);
        $this->assertArrayNotHasKey('priceTotals', $a, 'only a Product has a total');

        $hr = $this->getJson("/api/product-master/{$this->product}", $this->as(self::HR))->assertOk()->json('data');
        $this->assertSame([], $hr['priceTotals']);
    }

    public function test_every_sell_row_of_the_list_carries_its_tax_percent_for_a_caller_who_may_see_prices_only(): void
    {
        $rows = collect($this->getJson('/api/product-master/list?per=100', $this->as(self::ADMIN))->assertOk()->json('data'))->keyBy('id');
        $this->assertEquals(18.0, $rows[$this->it['a']['master']]['taxPercent']);
        $this->assertEquals(5.0, $rows[$this->it['b']['master']]['taxPercent']);
        $this->assertNull($rows[$this->it['usd']['master']]['taxPercent']);
        $this->assertSame('INR', $rows[$this->it['a']['master']]['currency']);

        $hr = collect($this->getJson('/api/product-master/list?per=100', $this->as(self::HR))->assertOk()->json('data'))->keyBy('id');
        $this->assertArrayNotHasKey('taxPercent', $hr[$this->it['a']['master']]);
        $this->assertArrayNotHasKey('unitPrice', $hr[$this->it['a']['master']]);
    }

    public function test_the_total_is_one_grouped_query_whatever_the_number_of_sub_products(): void
    {
        $log = [];
        DB::listen(function ($q) use (&$log) {
            $log[] = $q->sql;
        });
        $run = function () use (&$log): array {
            $log = [];
            $this->getJson('/api/product-master/list?per=100', $this->as(self::ADMIN))->assertOk();

            return $log;
        };
        $grouped = fn (array $statements): int => count(array_filter($statements, fn ($s) => str_contains($s, 'group by 1, 2, 3, 4, 5, 6')));
        $run();   // the first request warms what the application caches for a person (menu ids, roles, the custom Products): not what is measured
        $before = $run();
        $this->assertSame(1, $grouped($before), 'the totals of every Product on the page are ONE grouped statement');

        // nine more under the same Product and twelve under another: in four currencies, three rates, some unpriced
        $other = $this->sharedId('enterprise_brain', self::T);
        foreach (range(1, 9) as $i) {
            $this->item("More {$i}", ['product_master_id' => $this->product, 'unit_price' => $i * 10, 'currency' => ['INR', 'USD', 'EUR', 'GBP'][$i % 4], 'tax_rate_id' => [$this->gst18, $this->gst5, null][$i % 3]]);
        }
        foreach (range(1, 12) as $i) {
            $this->item("Other {$i}", ['product_master_id' => $other, 'unit_price' => $i % 5 === 0 ? null : $i * 7, 'currency' => 'INR', 'tax_rate_id' => $this->gst18]);
        }
        $after = $run();

        $this->assertSame(1, $grouped($after), 'and still one with twenty-one more Sub-products under two Products');
        // The same total statements for 3 and for 12 Sub-products under a Product: what the totals add to the list is its tax-rate join and the one grouped statement, whatever the number.
        $mine = fn (array $statements): int => count(array_filter($statements, fn ($s) => str_contains($s, 'crm_tax_rates')));
        $this->assertSame($mine($before), $mine($after), 'the statements that read the tax rates do not depend on the number of Sub-products');
        $kinds = fn (array $statements): array => array_count_values(array_map(fn ($q) => substr(preg_replace('/\\(\\?(, \\?)*\\)/', '(?)', $q), 0, 90), $statements));
        $a = $kinds($before);
        $b = $kinds($after);
        $differ = [];
        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $k) {
            if (($a[$k] ?? 0) !== ($b[$k] ?? 0)) {
                $differ[$k] = [$a[$k] ?? 0, $b[$k] ?? 0];
            }
        }
        $this->assertCount(count($before), $after, 'the whole list asks the database the same number of questions; the statements that differ (before, after): ' . json_encode($differ));
        $this->assertSame(14, $this->row()['subProductsCounted'], 'and the nine new ones are in the total');
    }

    public function test_the_calculator_class_alone_is_one_statement_for_any_number_of_products(): void
    {
        $other = $this->sharedId('enterprise_brain', self::T);
        $this->item('Brain one', ['product_master_id' => $other, 'unit_price' => 10, 'currency' => 'INR']);
        $rights = new \App\Domain\ProductMaster\ProductReadRights($this->identityOf(self::ADMIN));
        $statements = [];
        DB::listen(function ($q) use (&$statements) {
            $statements[] = $q->sql;
        });

        $totals = (new ProductPriceTotals(self::T, $rights))->forProducts([$this->product, $other, 999999]);

        $grouped = array_filter($statements, fn ($s) => str_contains($s, 'group by'));
        $this->assertCount(1, $grouped, 'one GROUP BY statement for all three ids');
        $this->assertSame([], $totals[999999]['priceTotals'], 'an id that is not a Product has nothing');
        $this->assertSame(1, $totals[$other]['subProductsCounted']);
    }

    /** @return array{user: object, user_id: int, sub_institute_id: int} */
    private function identityOf(int $profile): array
    {
        $id = (int) DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => self::T, 'user_profile_id' => $profile]);

        return ['user' => DB::table('tbluser')->where('id', $id)->first(), 'user_id' => $id, 'sub_institute_id' => self::T];
    }

    public function test_meta_lists_the_currencies_the_default_and_the_currency_of_every_rate(): void
    {
        $meta = $this->getJson('/api/product-master/meta', $this->as(self::ADMIN))->assertOk()->json('data');

        $this->assertSame(['INR', 'USD', 'EUR', 'GBP', 'AED', 'SGD', 'AUD', 'CAD', 'JPY', 'CHF', 'SAR', 'ZAR'], array_column($meta['currencies'], 'code'));
        $this->assertSame(['code', 'name', 'symbol', 'decimals', 'country'], array_keys($meta['currencies'][0]));
        $this->assertSame('INR', $meta['defaultCurrency']);
        $byName = collect($meta['taxRates'])->keyBy('name');
        $this->assertSame('INR', $byName['GST 18%']['currency']);
        $this->assertNull($byName['GST 5%']['currency']);

        DB::table('tenant_setting')->insert(['sub_institute_id' => self::T, 'setting_key' => 'org.currency', 'setting_value' => 'usd']);
        $this->assertSame('USD', $this->getJson('/api/product-master/meta', $this->as(self::ADMIN))->assertOk()->json('data.defaultCurrency'));
        DB::table('tenant_setting')->where('sub_institute_id', self::T)->update(['setting_value' => 'Rupees']);
        $this->assertSame('INR', $this->getJson('/api/product-master/meta', $this->as(self::ADMIN))->assertOk()->json('data.defaultCurrency'), 'a value outside the list is never the default');
    }

    public function test_a_host_without_the_currency_column_still_answers_with_null_currencies(): void
    {
        Schema::table('crm_tax_rates', fn ($t) => $t->dropColumn('currency'));

        $meta = $this->getJson('/api/product-master/meta', $this->as(self::ADMIN))->assertOk()->json('data');

        $this->assertNull(collect($meta['taxRates'])->firstWhere('name', 'GST 18%')['currency']);
        $this->assertCount(2, $this->row()['priceTotals'], 'and the totals do not need the column');
    }
}
