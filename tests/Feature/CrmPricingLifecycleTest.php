<?php

namespace Tests\Feature;

use App\Domain\Money\MoneyFormat;
use App\Domain\Reports\Crm\CrmReportModules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/**
 * S16 (25 Nov 2026), contract section 4: the Price Book is GONE from the backend, and the price of a Sub-product is connected through the lifecycle.
 *
 *   gone       the 16 routes of /api/crm/price-books, `priceBookId` / `priceBookName` on a quote (the column stays, an old quote keeps its id), the saved-view and Recycle Bin modules
 *   (b)        the opportunity's products list answers unitPrice, currency and taxPercent (read only), for a caller who may see prices
 *   (c)        the quote and invoice PDFs write each amount with the symbol of the document's currency and each line's tax as "<name> <pct>%"
 *   (d)        the report modules: quotes, sales orders, invoices and purchase orders gain currency, subtotal and tax_total; products gain currency and tax_rate
 *   tax rates  the tax-rate API answers and accepts `currency` (a catalogue code or null)
 */
class CrmPricingLifecycleTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    private const QUOTES = '/module/crm/sales/quotes';

    private const OPPS = '/module/crm/sales/opportunities';

    private const PRODUCTS = '/module/crm/sales/products';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLeadsWorld();
        foreach ([
            '2026_11_24_080100_create_crm_tax_rates_table', '2026_11_24_080200_create_crm_products_table', '2026_11_24_080300_create_crm_opportunities_table',
            '2026_11_24_080500_create_crm_opportunity_products_table', '2026_11_24_080700_create_crm_quotes_table', '2026_11_24_080800_create_crm_quote_line_items_table',
            '2026_11_24_081800_create_crm_price_books_table', '2026_11_24_081900_create_crm_price_book_items_table', '2026_11_24_082000_add_price_book_id_to_crm_quotes_table',
            '2026_11_22_080000_create_crm_saved_views_table', '2026_12_26_090000_add_currency_to_crm_tax_rates',
        ] as $migration) {
            // the facts and leads world already holds some of these tables (the opportunities, for one): a migration runs only for a table that is not there yet
            if (preg_match('/create_(crm_[a-z_]+?)(?:_table)?$/', $migration, $m) === 1 && Schema::hasTable($m[1])) {
                continue;
            }
            (require base_path("database/migrations/{$migration}.php"))->up();
        }
        DB::table('tblmenumaster_g2g')->insert([
            ['id' => 770, 'menu_name' => 'Quotes', 'access_link' => self::QUOTES],
            ['id' => 771, 'menu_name' => 'Opportunities', 'access_link' => self::OPPS],
            ['id' => 772, 'menu_name' => 'Products', 'access_link' => self::PRODUCTS],
            ['id' => 773, 'menu_name' => 'Price Books', 'access_link' => '/module/crm/sales/price-books'],
            ['id' => 774, 'menu_name' => 'Services', 'access_link' => '/module/crm/sales/services'],
        ]);
    }

    // ================================================================== gone

    public function test_the_price_book_routes_are_gone(): void
    {
        $user = $this->user(['/module/crm/sales/price-books' => ['view', 'add', 'edit', 'delete']]);

        foreach ([['GET', 'price-books'], ['GET', 'price-books/active'], ['GET', 'price-books/export'], ['GET', 'price-books/1'], ['POST', 'price-books'], ['PUT', 'price-books/1'],
            ['DELETE', 'price-books/1'], ['POST', 'price-books/bulk/delete'], ['POST', 'price-books/bulk/assign'], ['GET', 'price-books/1/items'], ['POST', 'price-books/1/items'],
            ['PUT', 'price-books/1/items/2'], ['DELETE', 'price-books/1/items/2'], ['GET', 'price-books/1/history']] as [$method, $path]) {
            $this->json($method, "/api/crm/{$path}", [], $user['h'])->assertStatus(404);
        }
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Api/Crm/CrmPriceBookController.php'));
        $this->assertEmpty(array_filter(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes(), fn ($r) => str_contains($r->uri(), 'price-books')), 'no route of the application mentions price-books');
    }

    public function test_a_quote_no_longer_reads_or_returns_a_price_book_and_an_old_quote_keeps_its_column(): void
    {
        $user = $this->user([self::QUOTES => ['view', 'add', 'edit']]);
        $book = (int) DB::table('crm_price_books')->insertGetId(['price_book_no' => 'PB-1', 'name' => 'Retail', 'sub_institute_id' => self::T, 'created_at' => now(), 'updated_at' => now()]);
        $old = (int) DB::table('crm_quotes')->insertGetId(['quote_no' => 'QT-OLD', 'subject' => 'Old quote', 'price_book_id' => $book, 'currency' => 'INR', 'sub_institute_id' => self::T, 'created_at' => now(), 'updated_at' => now()]);

        $shown = $this->getJson("/api/crm/quotes/{$old}", $user['h'])->assertOk()->json('data');
        $this->assertArrayNotHasKey('priceBookId', $shown);
        $this->assertArrayNotHasKey('priceBookName', $shown);

        $res = $this->postJson('/api/crm/quotes', ['subject' => 'New quote', 'assignedTo' => $user['id'], 'priceBookId' => 987654, 'currency' => 'INR'], $user['h'])->assertStatus(201);
        $this->assertArrayNotHasKey('priceBookId', $res->json('data'));
        $this->assertNull(DB::table('crm_quotes')->where('id', $res->json('data.id'))->value('price_book_id'), 'an id sent by an old client is not stored (and not judged)');

        $this->putJson("/api/crm/quotes/{$old}", ['subject' => 'Renamed', 'priceBookId' => null], $user['h'])->assertOk();
        $this->assertSame($book, (int) DB::table('crm_quotes')->where('id', $old)->value('price_book_id'), 'the column of an old quote is left as it was');
        $this->assertSame(1, DB::table('crm_price_books')->count(), 'and the tables are not dropped');
    }

    public function test_the_saved_view_and_recycle_bin_modules_are_gone(): void
    {
        $user = $this->user([self::QUOTES => ['view', 'delete'], '/module/crm/sales/price-books' => ['view', 'delete']]);

        $conditions = [['field' => 'subject', 'operator' => 'contains', 'value' => 'x']];
        $this->postJson('/api/crm/saved-views', ['module' => 'price_books', 'name' => 'Mine', 'conditions' => $conditions], $user['h'])->assertStatus(422);
        $this->postJson('/api/crm/saved-views', ['module' => 'quotes', 'name' => 'Mine', 'conditions' => $conditions], $user['h'])->assertStatus(201);
        $this->getJson('/api/crm/saved-views?module=price_books', $user['h'])->assertStatus(422);

        $book = (int) DB::table('crm_price_books')->insertGetId(['price_book_no' => 'PB-1', 'name' => 'Retail', 'sub_institute_id' => self::T, 'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson("/api/crm/recycle-bin/price_books/{$book}/restore", [], $user['h'])->assertStatus(404);
        $this->deleteJson("/api/crm/recycle-bin/price_books/{$book}", [], $user['h'])->assertStatus(404);
        $listed = $this->getJson('/api/crm/recycle-bin', $user['h'])->assertOk()->json('data.items') ?? $this->getJson('/api/crm/recycle-bin', $user['h'])->json('data');
        $this->assertNotContains('price_books', array_column((array) $listed, 'type'));
    }

    // ================================================================== (b) the opportunity's products

    public function test_the_opportunity_products_answer_the_price_the_currency_and_the_tax_percent(): void
    {
        $rate = (int) DB::table('crm_tax_rates')->insertGetId(['sub_institute_id' => self::T, 'name' => 'GST 18%', 'percentage' => 18, 'currency' => 'INR', 'is_active' => 1]);
        $product = (int) DB::table('crm_products')->insertGetId(['name' => 'Scholar ERP', 'product_no' => 'PRD-1', 'item_type' => 'product', 'unit_price' => 1500, 'currency' => 'INR', 'tax_rate_id' => $rate, 'sub_institute_id' => self::T, 'created_at' => now(), 'updated_at' => now()]);
        $service = (int) DB::table('crm_products')->insertGetId(['name' => 'Training', 'product_no' => 'SVC-1', 'item_type' => 'service', 'unit_price' => 20, 'currency' => 'USD', 'sub_institute_id' => self::T, 'created_at' => now(), 'updated_at' => now()]);
        $opp = (int) DB::table('crm_opportunities')->insertGetId(['opportunity_no' => 'OPP-1', 'name' => 'Deal', 'sales_stage' => 'Prospecting', 'sub_institute_id' => self::T, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$product, $service] as $p) {
            DB::table('crm_opportunity_products')->insert(['opportunity_id' => $opp, 'product_id' => $p, 'quantity' => 2, 'created_at' => now()]);
        }

        // may view the opportunity and the products: the three keys are there
        $full = $this->user([self::OPPS => ['view'], self::PRODUCTS => ['view'], '/module/crm/sales/services' => ['view']]);
        $rows = collect($this->getJson("/api/crm/opportunities/{$opp}/products", $full['h'])->assertOk()->json('data'))->keyBy('name');
        $this->assertEquals([1500.0, 'INR', 18.0], [$rows['Scholar ERP']['unitPrice'], $rows['Scholar ERP']['currency'], $rows['Scholar ERP']['taxPercent']]);
        $this->assertEquals([20.0, 'USD', null], [$rows['Training']['unitPrice'], $rows['Training']['currency'], $rows['Training']['taxPercent']]);
        $this->assertEquals(2.0, $rows['Scholar ERP']['quantity'], 'what the list always had is still there');

        // may view the opportunity only: no price, no currency, no tax, as the Portfolio does not show them either
        $plain = $this->user([self::OPPS => ['view']]);
        $rows = collect($this->getJson("/api/crm/opportunities/{$opp}/products", $plain['h'])->assertOk()->json('data'))->keyBy('name');
        foreach (['unitPrice', 'currency', 'taxPercent'] as $key) {
            $this->assertArrayNotHasKey($key, $rows['Scholar ERP']);
        }
        $this->assertSame(['id', 'productId', 'name', 'itemType', 'quantity'], array_keys($rows['Scholar ERP']));
    }

    // ================================================================== tax rates

    public function test_the_tax_rate_api_answers_and_accepts_a_currency(): void
    {
        $admin = $this->user([self::QUOTES => ['view', 'edit']]);
        $made = $this->postJson('/api/crm/tax-rates', ['name' => 'GST 18%', 'percentage' => 18, 'currency' => 'inr'], $admin['h'])->assertStatus(201);
        $this->assertSame('INR', $made->json('data.currency'));
        $any = $this->postJson('/api/crm/tax-rates', ['name' => 'Service charge', 'percentage' => 10], $admin['h'])->assertStatus(201);
        $this->assertNull($any->json('data.currency'));
        $this->postJson('/api/crm/tax-rates', ['name' => 'Bad', 'percentage' => 1, 'currency' => 'Rupees'], $admin['h'])->assertStatus(422)->assertJson(['message' => 'Choose a currency from the list.']);
        $this->assertSame(2, DB::table('crm_tax_rates')->count());

        $id = (int) $made->json('data.id');
        $this->putJson("/api/crm/tax-rates/{$id}", ['currency' => 'usd'], $admin['h'])->assertOk()->assertJsonPath('data.currency', 'USD');
        $this->putJson("/api/crm/tax-rates/{$id}", ['name' => 'Renamed'], $admin['h'])->assertOk()->assertJsonPath('data.currency', 'USD');
        $this->putJson("/api/crm/tax-rates/{$id}", ['currency' => 'Dollars'], $admin['h'])->assertStatus(422);
        $this->putJson("/api/crm/tax-rates/{$id}", ['currency' => null], $admin['h'])->assertOk()->assertJsonPath('data.currency', null);
        $this->assertEquals(18.0, DB::table('crm_tax_rates')->where('id', $id)->value('percentage'), 'the percentage is immutable, as it was');

        $list = collect($this->getJson('/api/crm/tax-rates', $admin['h'])->assertOk()->json('data'));
        $this->assertSame(['Renamed' => null, 'Service charge' => null], $list->pluck('currency', 'name')->all());
        $this->assertArrayHasKey('currency', $this->getJson('/api/crm/tax-rates/admin', $admin['h'])->assertOk()->json('data.0'));
    }

    public function test_a_host_without_the_currency_column_answers_null_and_refuses_to_store_one(): void
    {
        Schema::table('crm_tax_rates', fn ($t) => $t->dropColumn('currency'));
        $admin = $this->user([self::QUOTES => ['view', 'edit']]);

        $this->postJson('/api/crm/tax-rates', ['name' => 'GST 5%', 'percentage' => 5], $admin['h'])->assertStatus(201)->assertJsonPath('data.currency', null);
        $this->postJson('/api/crm/tax-rates', ['name' => 'GST 18%', 'percentage' => 18, 'currency' => 'INR'], $admin['h'])->assertStatus(422);
        $this->assertSame(1, DB::table('crm_tax_rates')->count());
        $this->assertNull($this->getJson('/api/crm/tax-rates', $admin['h'])->assertOk()->json('data.0.currency'));
    }

    // ================================================================== (c) the PDFs

    public function test_the_money_format_writes_the_symbol_of_the_currency_and_the_tax_label_without_repeating_itself(): void
    {
        $this->assertSame('₹1,234.50', MoneyFormat::amount(1234.5, 'INR'));
        $this->assertSame('$1,234.50', MoneyFormat::amount('1234.5', 'usd'));
        $this->assertSame('¥1,235', MoneyFormat::amount(1234.5, 'JPY'), 'the yen keeps no decimals');
        $this->assertSame('CHF 1,200.00', MoneyFormat::amount(1200, 'CHF'), 'a symbol made of letters is followed by a space');
        $this->assertSame('RUPEES 1,234.50', MoneyFormat::amount(1234.5, 'rupees'), 'a currency outside the list is its code as text');
        $this->assertSame('1,234.50', MoneyFormat::amount(1234.5, null), 'no currency: the bare number, as before');
        $this->assertSame('-₹10.00', MoneyFormat::amount(-10, 'INR'));
        $this->assertSame('GST 18%', MoneyFormat::taxLabel('GST 18%', 18), 'a name that says its percentage is not repeated');
        $this->assertSame('VAT 20%', MoneyFormat::taxLabel('VAT', '20.000'));
        $this->assertSame('Service charge 7.5%', MoneyFormat::taxLabel('Service charge', 7.5));
        $this->assertSame('18%', MoneyFormat::taxLabel(null, 18));
        $this->assertSame('-', MoneyFormat::taxLabel('GST', null));
    }

    public function test_the_quote_and_invoice_pdfs_show_the_symbol_and_the_tax_name_and_percent(): void
    {
        $doc = (object) [
            'quote_no' => 'QT-1', 'invoice_no' => 'INV-1', 'subject' => 'S', 'quote_stage' => 'Sent', 'invoice_stage' => 'Sent', 'currency' => 'USD', 'organization_name' => 'School', 'contact_name' => null,
            'billing_street' => null, 'billing_city' => null, 'billing_state' => null, 'billing_code' => null, 'billing_country' => null, 'shipping_street' => null, 'shipping_city' => null,
            'shipping_state' => null, 'shipping_code' => null, 'shipping_country' => null, 'terms_conditions' => null, 'description' => null, 'valid_till' => null, 'invoice_date' => '2026-11-25', 'due_date' => null,
            'subtotal' => 1000, 'discount_amount' => 0, 'tax_total' => 75, 'shipping_handling_amount' => 0, 'adjustment' => 0, 'total' => 1075, 'amount_paid' => 75, 'created_at' => '2026-11-25', 'sales_order_no' => null,
        ];
        $line = (object) ['description' => 'Plan', 'quantity' => 1, 'unit_price' => 1000, 'discount_percent' => null, 'discount_amount' => 0, 'tax_name_snapshot' => 'US sales tax', 'tax_percent_snapshot' => 7.5, 'line_total' => 1075];

        $quote = view('crm.quotes.pdf', ['quote' => $doc, 'lineItems' => [$line], 'schoolName' => 'School', 'assignedToName' => 'A'])->render();
        $this->assertStringContainsString('$1,000.00', $quote);
        $this->assertStringContainsString('US sales tax 7.5%', $quote);
        $this->assertStringContainsString('$1,075.00', $quote);
        $this->assertStringNotContainsString('USD 1,075.00', $quote, 'the code is no longer printed before the total');

        $invoice = view('crm.invoices.pdf', ['invoice' => $doc, 'lineItems' => [$line], 'schoolName' => 'School', 'assignedToName' => 'A', 'balanceDue' => 1000.0])->render();
        $this->assertStringContainsString('$1,000.00', $invoice);
        $this->assertStringContainsString('US sales tax 7.5%', $invoice);
        $this->assertStringContainsString('-$75.00', $invoice, 'the amount paid');
    }

    // ================================================================== (d) the report modules

    public function test_the_report_modules_carry_the_currency_and_the_tax_fields(): void
    {
        $modules = collect(CrmReportModules::definitions())->keyBy('key');
        $fields = fn (string $key): array => array_column($modules[$key]->fields, 'column', 'key');

        foreach (['crm.quotes' => 'qt', 'crm.sales_orders' => 'so', 'crm.invoices' => 'inv', 'crm.purchase_orders' => 'po'] as $key => $alias) {
            $f = $fields($key);
            $this->assertSame("{$alias}.currency", $f['currency'], $key);
            $this->assertSame("{$alias}.subtotal", $f['subtotal'], $key);
            $this->assertSame("{$alias}.tax_total", $f['tax_total'], $key);
            $this->assertSame("{$alias}.total", $f['total'], $key);
        }
        $products = $fields('crm.products');
        $this->assertSame('pd.currency', $products['currency']);
        $this->assertStringContainsString('crm_tax_rates', $products['tax_rate']);
        $this->assertSame('pd.unit_price', $products['unit_price']);
    }
}
