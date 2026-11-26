<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/**
 * S16 (25 Nov 2026), contract section 4 (a): the three `saveLineItems` (quote, sales order, invoice) refuse a line whose catalogue item is priced in one currency
 * when the document is in another: 422 `currency_mismatch`, "<item> is priced in <CUR>, this document is in <CUR>.", and NOTHING is saved (the lines and the totals the document already
 * had stay). The rule is the same class (LineCurrencyGuard) behind all three, so each controller is proved here by the same story; nothing else of those methods changes.
 *
 * Not a mismatch: a line with no `productId`, an item with no currency, a document with no currency, the same currency in another case, an item of another organisation.
 */
class CrmLineCurrencyMismatchTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    private int $inr;

    private int $usd;

    private int $none;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLeadsWorld();
        foreach ([
            '2026_11_24_080100_create_crm_tax_rates_table', '2026_11_24_080200_create_crm_products_table', '2026_11_24_080700_create_crm_quotes_table',
            '2026_11_24_080800_create_crm_quote_line_items_table', '2026_11_24_082400_create_crm_sales_orders_table', '2026_11_24_082500_create_crm_sales_order_line_items_table',
            '2026_11_24_082800_create_crm_invoices_table', '2026_11_24_082900_create_crm_invoice_line_items_table', '2026_11_30_080100_add_payment_tracking_to_crm_invoices_table',
            '2026_12_26_090000_add_currency_to_crm_tax_rates',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }
        DB::table('tblmenumaster_g2g')->insert([
            ['id' => 770, 'menu_name' => 'Quotes', 'access_link' => '/module/crm/sales/quotes'],
            ['id' => 771, 'menu_name' => 'Sales Orders', 'access_link' => '/module/crm/sales/sales-orders'],
            ['id' => 773, 'menu_name' => 'Invoices', 'access_link' => '/module/crm/sales/invoices'],
        ]);
        $item = fn (string $name, ?string $currency, int $tenant = self::T): int => (int) DB::table('crm_products')->insertGetId([
            'name' => $name, 'product_no' => 'PRD-' . uniqid(), 'item_type' => 'product', 'sub_institute_id' => $tenant, 'unit_price' => 100, 'currency' => $currency,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->inr = $item('Rupee Plan', 'INR');
        $this->usd = $item('Dollar Plan', 'USD');
        $this->none = $item('Open Plan', null);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}> */
    public static function documents(): array
    {
        return [
            'quote' => ['quotes', 'crm_quotes', 'crm_quote_line_items', 'quote_id', '/module/crm/sales/quotes', 'quote_no', 'quote_stage'],
            'sales order' => ['sales-orders', 'crm_sales_orders', 'crm_sales_order_line_items', 'sales_order_id', '/module/crm/sales/sales-orders', 'so_no', 'so_stage'],
            'invoice' => ['invoices', 'crm_invoices', 'crm_invoice_line_items', 'invoice_id', '/module/crm/sales/invoices', 'invoice_no', 'invoice_stage'],
        ];
    }

    private function document(string $table, string $numberColumn, ?string $currency, int $tenant = self::T): int
    {
        return (int) DB::table($table)->insertGetId([
            $numberColumn => 'DOC-' . uniqid(), 'subject' => 'Gujarat order', 'currency' => $currency, 'sub_institute_id' => $tenant, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function lines(int ...$products): array
    {
        return array_map(fn (int $p) => ['productId' => $p, 'description' => 'line', 'quantity' => 2, 'unitPrice' => 100], $products);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('documents')]
    public function test_a_line_priced_in_another_currency_is_refused_and_nothing_is_saved(string $route, string $table, string $lineTable, string $fk, string $link, string $number): void
    {
        $user = $this->user([$link => ['view', 'edit']]);
        $doc = $this->document($table, $number, 'INR');
        $this->putJson("/api/crm/{$route}/{$doc}/line-items", ['lineItems' => $this->lines($this->inr)], $user['h'])->assertOk();
        $before = [DB::table($lineTable)->where($fk, $doc)->get()->all(), (float) DB::table($table)->where('id', $doc)->value('subtotal')];
        $this->assertCount(1, $before[0]);

        $res = $this->putJson("/api/crm/{$route}/{$doc}/line-items", ['lineItems' => $this->lines($this->inr, $this->usd)], $user['h']);

        $res->assertStatus(422)->assertJson(['status' => 0, 'code' => 'currency_mismatch', 'message' => 'Dollar Plan is priced in USD, this document is in INR.']);
        $this->assertEquals($before[0], DB::table($lineTable)->where($fk, $doc)->get()->all(), 'the lines the document had are untouched');
        $this->assertSame($before[1], (float) DB::table($table)->where('id', $doc)->value('subtotal'), 'so are its totals');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('documents')]
    public function test_what_is_not_a_mismatch_is_saved_as_before(string $route, string $table, string $lineTable, string $fk, string $link, string $number): void
    {
        $user = $this->user([$link => ['view', 'edit']]);
        $foreign = (int) DB::table('crm_products')->insertGetId(['name' => 'Elsewhere', 'product_no' => 'PRD-X' . uniqid(), 'item_type' => 'product', 'sub_institute_id' => self::T2, 'unit_price' => 5, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now()]);

        // the same currency in another case, an item with no currency, a line with no item, an item of another organisation (it is not looked up: it is not ours)
        $inr = $this->document($table, $number, 'inr');
        $lines = [...$this->lines($this->inr, $this->none), ['description' => 'Freight', 'quantity' => 1, 'unitPrice' => 50], ...$this->lines($foreign)];
        $this->putJson("/api/crm/{$route}/{$inr}/line-items", ['lineItems' => $lines], $user['h'])->assertOk();
        $this->assertSame(4, DB::table($lineTable)->where($fk, $inr)->count());

        // a document with no currency takes an item of any currency
        $open = $this->document($table, $number, null);
        $this->putJson("/api/crm/{$route}/{$open}/line-items", ['lineItems' => $this->lines($this->usd, $this->inr)], $user['h'])->assertOk();
        $this->assertSame(2, DB::table($lineTable)->where($fk, $open)->count());

        // an empty save still clears the lines
        $this->putJson("/api/crm/{$route}/{$open}/line-items", ['lineItems' => []], $user['h'])->assertOk();
        $this->assertSame(0, DB::table($lineTable)->where($fk, $open)->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('documents')]
    public function test_the_mismatch_is_found_on_any_line_and_names_the_first_one(string $route, string $table, string $lineTable, string $fk, string $link, string $number): void
    {
        $user = $this->user([$link => ['view', 'edit']]);
        $doc = $this->document($table, $number, 'USD');
        $eur = (int) DB::table('crm_products')->insertGetId(['name' => 'Euro Plan', 'product_no' => 'PRD-E' . uniqid(), 'item_type' => 'product', 'sub_institute_id' => self::T, 'unit_price' => 5, 'currency' => 'eur', 'created_at' => now(), 'updated_at' => now()]);

        $this->putJson("/api/crm/{$route}/{$doc}/line-items", ['lineItems' => $this->lines($this->usd, $this->none, $eur, $this->inr)], $user['h'])
            ->assertStatus(422)->assertJson(['code' => 'currency_mismatch', 'message' => 'Euro Plan is priced in EUR, this document is in USD.']);
        $this->assertSame(0, DB::table($lineTable)->where($fk, $doc)->count());
    }

    public function test_the_guard_asks_the_database_once_however_many_lines_there_are(): void
    {
        $user = $this->user(['/module/crm/sales/quotes' => ['view', 'edit']]);
        $doc = $this->document('crm_quotes', 'quote_no', 'INR');
        $queries = 0;
        DB::listen(function ($q) use (&$queries) {
            if (str_contains($q->sql, 'from "crm_products"') || str_contains($q->sql, 'from `crm_products`')) {
                $queries++;
            }
        });

        $this->putJson("/api/crm/quotes/{$doc}/line-items", ['lineItems' => $this->lines(...array_fill(0, 12, $this->inr))], $user['h'])->assertOk();

        $this->assertSame(1, $queries, 'one statement reads the currencies of all twelve lines');
    }
}
