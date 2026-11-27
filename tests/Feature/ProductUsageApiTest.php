<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\ProductPointers;
use App\Domain\ProductMaster\ProductUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsProductReadWorld;
use Tests\TestCase;

/**
 * `GET /api/product-master/{id}/usage`: what a record is used by (slice 15, P2-E1, brief section 5; audit finding G-3).
 *
 * The answer keeps `signals`, `icp`, `priceBooks`, `quotes`, `basePrice` and `currency`, and adds for a record with a catalogue row `counts` (a number for EVERY table of
 * ProductPointers::toCrmProduct(), under the names of ProductUsage::COUNT_NAMES), `pointers` (the same numbers under the registry's own keys) and `latest` (the newest five
 * quotes, sales orders and invoices). The counts are proved against rows built by hand, one kind of row per rule: a document counts once however many lines hold the item, a
 * document in the Recycle Bin and another organisation's document are not a use. The gate: only a caller who may view the Sell side of the record's type; an About-only record
 * answers its signals and ICP only.
 */
class ProductUsageApiTest extends TestCase
{
    use BuildsProductReadWorld;

    /** @var array{master: int, offer: int, crm: int} a Sub-product with an About and a Sell */
    private array $rec;

    /** @var array<string, array{master: int, crm: int}> */
    private array $items;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildReadWorld();
        $w = $this->scholarWorld();
        $this->items = $w['items'];
        $sub = $this->subProduct('SCH-09', 'Scholar Nine');
        $crm = (int) DB::table('crm_products')->insertGetId([
            'sub_institute_id' => self::T, 'product_no' => 'PRD-NINE', 'item_type' => 'product', 'name' => 'Scholar Nine', 'unit_price' => 999, 'currency' => 'INR', 'is_active' => 1,
            'product_master_id' => $w['product'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('g2g_product_master')->where('id', $sub['master'])->update(['crm_product_id' => $crm]);
        $this->rec = ['master' => $sub['master'], 'offer' => $sub['offer'], 'crm' => $crm];
    }

    private function usage(int $profile = self::ADMIN, ?int $master = null): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/product-master/' . ($master ?? $this->rec['master']) . '/usage', $this->as($profile));
    }

    private function row(string $table, array $values): int
    {
        return (int) DB::table($table)->insertGetId($values);
    }

    private function other(): int
    {
        return $this->items['gujarat']['crm'];
    }

    // ================================================================== a count for every table of the registry

    /**
     * Builds, for ONE registry entry, the rows the rules are about, and returns the number the usage must report: live rows of this organisation that hold the item.
     * An own-scope table: 2 live rows, 1 in the Recycle Bin, 1 of another organisation, 1 holding another item. A line table: 2 documents (one with TWO lines of the item), 1
     * document in the Bin, 1 of another organisation, 1 line of another item. A bundle table: rows by parent or by component, with a binned bundle and another organisation's.
     *
     * @param array<string, mixed> $e a registry entry
     */
    private function build(array $e, int $crm): int
    {
        $table = $e['table'];
        $column = $e['column'];
        $now = now();
        if (is_string($e['scope'])) {
            $scope = $e['scope'];
            $this->row($table, [$column => $crm, $scope => self::T]);
            $this->row($table, [$column => $crm, $scope => self::T]);
            $this->row($table, [$column => $crm, $scope => self::T, 'deleted_at' => $now]);
            $this->row($table, [$column => $crm, $scope => self::T2]);
            $this->row($table, [$column => $this->other(), $scope => self::T]);

            return 2;
        }

        $parent = $e['scope']['parent'];
        $via = $e['scope']['via'];
        $tenantColumn = $e['scope']['parent_tenant'];
        if ($parent === ProductPointers::CRM_PRODUCTS) {
            // a bundle: the owner is the parent product
            $mine = fn (string $name, array $over = []) => $this->row('crm_products', $over + ['sub_institute_id' => self::T, 'product_no' => $name, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            if ($column === $via) {   // the item as a bundle: its parts
                foreach ([1, 2, 3] as $i) {
                    $this->row($table, ['parent_product_id' => $crm, 'component_product_id' => $mine("Part {$i}"), 'quantity' => 1]);
                }

                return 3;
            }
            $this->row($table, ['parent_product_id' => $mine('Bundle A'), 'component_product_id' => $crm, 'quantity' => 1]);
            $this->row($table, ['parent_product_id' => $mine('Bundle B'), 'component_product_id' => $crm, 'quantity' => 1]);
            $this->row($table, ['parent_product_id' => $mine('Binned bundle', ['deleted_at' => $now]), 'component_product_id' => $crm, 'quantity' => 1]);
            $this->row($table, ['parent_product_id' => $mine('Their bundle', ['sub_institute_id' => self::T2]), 'component_product_id' => $crm, 'quantity' => 1]);

            return 2;
        }

        $doc = fn (int $tenant, ?string $deleted = null) => $this->row($parent, [$tenantColumn => $tenant, 'deleted_at' => $deleted]);
        $a = $doc(self::T);
        $b = $doc(self::T);
        $this->row($table, [$via => $a, $column => $crm]);
        $this->row($table, [$via => $a, $column => $crm]);   // two lines of the item on one document: one document
        $this->row($table, [$via => $b, $column => $crm]);
        $this->row($table, [$via => $doc(self::T, (string) $now), $column => $crm]);
        $this->row($table, [$via => $doc(self::T2), $column => $crm]);
        $this->row($table, [$via => $a, $column => $this->other()]);

        return 2;
    }

    public function test_every_table_of_the_registry_is_counted_against_hand_built_rows(): void
    {
        $expected = [];
        foreach (ProductPointers::toCrmProductInUse() as $entry) {
            $expected[$entry['table'] . '.' . $entry['column']] = $this->build($entry, $this->rec['crm']);
        }
        $this->assertCount(10, $expected, 'the 11 pointers of the registry, less the dormant price-book one (S16)');

        $data = $this->usage()->assertOk()->json('data');

        $this->assertSame($expected, $data['pointers'], 'one number per pointer, under its registry key');
        $names = ProductUsage::COUNT_NAMES;
        $counts = [];
        foreach ($expected as $key => $n) {
            $counts[$names[$key]] = $n;
        }
        $this->assertSame($counts, $data['counts'], 'and under the names a screen uses');
        $this->assertSame(2, $data['quotes'], 'the old key still says how many quotes');
        $this->assertSame(['quotes' => 2, 'salesOrders' => 2, 'invoices' => 2, 'opportunities' => 2, 'bundleParts' => 3, 'usedInBundles' => 2,
            'tickets' => 2, 'assets' => 2, 'faqs' => 2, 'campaigns' => 2], $data['counts']);
    }

    public function test_the_names_cover_the_registry_exactly_so_a_new_pointer_cannot_be_forgotten(): void
    {
        // S16: the dormant price-book pointer is in the registry for the merge and the checker only; it is no use and has no name here (ProductPointers::DORMANT).
        $keys = array_map(fn ($e) => $e['table'] . '.' . $e['column'], ProductPointers::toCrmProductInUse());

        $this->assertEqualsCanonicalizing($keys, array_keys(ProductUsage::COUNT_NAMES), 'a pointer added to the registry needs a name in ProductUsage::COUNT_NAMES (and the other way round)');
        $this->assertSame(['crm_price_book_items.product_id'], ProductPointers::DORMANT);
        $this->assertSame(array_values(array_unique(ProductUsage::COUNT_NAMES)), array_values(ProductUsage::COUNT_NAMES), 'no two pointers share a name');
    }

    public function test_the_counts_follow_the_item_into_the_recycle_bin_and_never_cross_to_another_item(): void
    {
        $quote = $this->row('crm_quotes', ['sub_institute_id' => self::T, 'quote_no' => 'Q-1', 'created_at' => now()]);
        $this->row('crm_quote_line_items', ['quote_id' => $quote, 'product_id' => $this->rec['crm']]);
        $this->row('crm_quote_line_items', ['quote_id' => $quote, 'product_id' => $this->other()]);
        DB::table('crm_products')->where('id', $this->rec['crm'])->update(['deleted_at' => now()]);

        $data = $this->usage()->assertOk()->json('data');
        $this->assertSame(1, $data['counts']['quotes'], 'documents still point at an item in the Recycle Bin: that is what a person needs to see before deleting it for good');
        $this->assertSame(0, $data['counts']['invoices']);
        $this->assertSame(1, $this->usage(self::ADMIN, $this->items['gujarat']['master'])->json('data.counts.quotes'), 'the other item has its own line');
    }

    // ================================================================== the newest five

    /** @return list<array{id: int, number: ?string, date: ?string, status: ?string}> */
    private function latest(string $list, int $profile = self::ADMIN): array
    {
        return $this->usage($profile)->assertOk()->json('data.latest.' . $list);
    }

    public function test_the_latest_five_quotes_are_the_newest_with_number_date_status_and_the_link_id(): void
    {
        $ids = [];
        foreach (range(1, 7) as $i) {
            $q = $this->row('crm_quotes', ['sub_institute_id' => self::T, 'quote_no' => "Q-{$i}", 'quote_stage' => $i % 2 ? 'Sent' : 'Accepted', 'created_at' => sprintf('2026-10-%02d 12:00:00', $i)]);
            $this->row('crm_quote_line_items', ['quote_id' => $q, 'product_id' => $this->rec['crm']]);
            $ids[$i] = $q;
        }
        $this->row('crm_quote_line_items', ['quote_id' => $ids[7], 'product_id' => $this->rec['crm']]);   // two lines: one entry
        $binned = $this->row('crm_quotes', ['sub_institute_id' => self::T, 'quote_no' => 'Q-BIN', 'created_at' => '2026-10-20 12:00:00', 'deleted_at' => now()]);
        $theirs = $this->row('crm_quotes', ['sub_institute_id' => self::T2, 'quote_no' => 'Q-THEIRS', 'created_at' => '2026-10-21 12:00:00']);
        $another = $this->row('crm_quotes', ['sub_institute_id' => self::T, 'quote_no' => 'Q-OTHER', 'created_at' => '2026-10-22 12:00:00']);
        foreach ([$binned, $theirs] as $q) {
            $this->row('crm_quote_line_items', ['quote_id' => $q, 'product_id' => $this->rec['crm']]);
        }
        $this->row('crm_quote_line_items', ['quote_id' => $another, 'product_id' => $this->other()]);

        $latest = $this->latest('quotes');

        $this->assertCount(5, $latest, 'the newest five');
        $this->assertSame(['Q-7', 'Q-6', 'Q-5', 'Q-4', 'Q-3'], array_column($latest, 'number'));
        $this->assertSame([$ids[7], $ids[6], $ids[5], $ids[4], $ids[3]], array_column($latest, 'id'), 'the link id is the document\'s own');
        $this->assertSame(['2026-10-07', '2026-10-06', '2026-10-05', '2026-10-04', '2026-10-03'], array_column($latest, 'date'));
        $this->assertSame(['Sent', 'Accepted', 'Sent', 'Accepted', 'Sent'], array_column($latest, 'status'));
        $this->assertSame(['id', 'number', 'date', 'status'], array_keys($latest[0]));
        $this->assertSame(7, $this->usage()->json('data.counts.quotes'), 'the count is every quote, the list the newest five');
    }

    public function test_the_latest_sales_orders_and_invoices_are_read_from_their_own_tables_and_a_short_list_is_short(): void
    {
        $so = $this->row('crm_sales_orders', ['sub_institute_id' => self::T, 'so_no' => 'SO-1', 'so_stage' => 'Created', 'created_at' => '2026-10-01 10:00:00']);
        $this->row('crm_sales_order_line_items', ['sales_order_id' => $so, 'product_id' => $this->rec['crm']]);
        $older = $this->row('crm_invoices', ['sub_institute_id' => self::T, 'invoice_no' => 'INV-1', 'invoice_stage' => 'Paid', 'invoice_date' => '2026-09-01', 'created_at' => '2026-10-01 10:00:00']);
        $newer = $this->row('crm_invoices', ['sub_institute_id' => self::T, 'invoice_no' => 'INV-2', 'invoice_stage' => 'Sent', 'invoice_date' => '2026-09-15', 'created_at' => '2026-10-02 10:00:00']);
        foreach ([$older, $newer] as $inv) {
            $this->row('crm_invoice_line_items', ['invoice_id' => $inv, 'product_id' => $this->rec['crm']]);
        }

        $this->assertSame([['id' => $so, 'number' => 'SO-1', 'date' => '2026-10-01', 'status' => 'Created']], $this->latest('salesOrders'));
        $invoices = $this->latest('invoices');
        $this->assertSame(['INV-2', 'INV-1'], array_column($invoices, 'number'));
        $this->assertSame(['2026-09-15', '2026-09-01'], array_column($invoices, 'date'), 'an invoice shows its invoice date');
        $this->assertSame([], $this->latest('quotes'), 'no quote holds it');
    }

    public function test_an_older_host_with_thin_tables_or_missing_tables_is_answered_not_failed(): void
    {
        Schema::drop('crm_tickets');
        Schema::drop('crm_quotes');
        Schema::create('crm_quotes', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
        });
        $q = $this->row('crm_quotes', ['sub_institute_id' => self::T]);
        $this->row('crm_quote_line_items', ['quote_id' => $q, 'product_id' => $this->rec['crm']]);

        $data = $this->usage()->assertOk()->json('data');

        $this->assertNull($data['pointers']['crm_tickets.product_id'], 'a table this host does not have is null, not an error');
        $this->assertNull($data['counts']['tickets']);
        $this->assertSame(1, $data['counts']['quotes']);
        $this->assertSame([['id' => $q, 'number' => null, 'date' => null, 'status' => null]], $data['latest']['quotes'], 'a column the host lacks is null');
        $this->assertSame(0, $data['counts']['assets']);
    }

    // ================================================================== the answer for a record with no Sell side

    public function test_a_record_with_an_about_and_no_catalogue_row_answers_its_signals_and_icp_only(): void
    {
        $about = (int) DB::table('g2g_product_master')->where('product_key', 'scholarclone/sch-03')->value('id');
        DB::table('g2g_company_opportunities')->insert([
            ['sub_institute_id' => self::T, 'relevant_offer_id' => 'SCH-03', 'is_sample' => 0], ['sub_institute_id' => self::T, 'relevant_offer_id' => 'SCH-03', 'is_sample' => 0],
            ['sub_institute_id' => self::T, 'relevant_offer_id' => 'SCH-03', 'is_sample' => 1], ['sub_institute_id' => self::T2, 'relevant_offer_id' => 'SCH-03', 'is_sample' => 0],
        ]);

        $data = $this->usage(self::SALES, $about)->assertOk()->json('data');

        $this->assertSame(2, $data['signals'], 'this organisation\'s real signals');
        $this->assertSame('none', $data['icp']);
        $this->assertSame([0, null, null], [$data['quotes'], $data['basePrice'], $data['currency']]);
        $this->assertArrayNotHasKey('priceBooks', $data, 'the Usage answer has no price books since S16');
        $this->assertSame([null, null, null], [$data['counts'], $data['pointers'], $data['latest']], 'no catalogue row: nothing to count');
        // a Product answers its signals and ICP too
        $scholar = $this->usage(self::ADMIN, $this->sharedId('scholarclone'))->assertOk()->json('data');
        $this->assertSame([0, 'template', null], [$scholar['signals'], $scholar['icp'], $scholar['counts']]);
        // and an item answers no signals or ICP (it has no About)
        $item = $this->usage(self::ADMIN, $this->items['gujarat']['master'])->assertOk()->json('data');
        $this->assertSame([null, null], [$item['signals'], $item['icp']]);
        $this->assertIsArray($item['counts']);
    }

    public function test_a_dormant_price_book_row_is_neither_listed_nor_counted_and_the_base_price_is_kept(): void
    {
        $book = $this->row('crm_price_books', ['sub_institute_id' => self::T, 'name' => 'Retail 2027', 'price_book_no' => 'PB-1', 'currency' => 'INR', 'is_active' => 1]);
        $this->row('crm_price_book_items', ['price_book_id' => $book, 'product_id' => $this->rec['crm'], 'list_price' => 1200]);

        $data = $this->usage()->assertOk()->json('data');

        $this->assertArrayNotHasKey('priceBooks', $data);
        $this->assertArrayNotHasKey('priceBooks', $data['counts']);
        $this->assertArrayNotHasKey('crm_price_book_items.product_id', $data['pointers']);
        $this->assertEquals(999.0, $data['basePrice']);
        $this->assertSame('INR', $data['currency']);
    }

    // ================================================================== the gate (G7, G8) and the organisation

    public function test_only_a_caller_who_may_view_the_sell_side_of_the_records_type_gets_the_usage(): void
    {
        $q = $this->row('crm_quotes', ['sub_institute_id' => self::T, 'quote_no' => 'Q-SECRET', 'created_at' => now()]);
        $this->row('crm_quote_line_items', ['quote_id' => $q, 'product_id' => $this->rec['crm']]);

        foreach ([self::HR, self::NOBODY] as $profile) {
            $res = $this->usage($profile)->assertStatus(403);
            $this->assertFalse(str_contains((string) $res->getContent(), 'Q-SECRET'));
            $this->assertSame(0, $res->json('status'));
        }
        $this->usage(self::ADMIN)->assertOk();
        $this->usage(self::SALES)->assertOk()->assertJsonPath('data.counts.quotes', 1);
        $this->usage(self::PRODUCTS_VIEWER)->assertOk()->assertJsonPath('data.latest.quotes.0.number', 'Q-SECRET');
        $this->usage(self::SERVICES_VIEWER)->assertStatus(403);   // this record is a product
        $this->getJson('/api/product-master/' . $this->rec['master'] . '/usage')->assertStatus(401);
    }

    public function test_the_gate_asks_for_the_right_of_the_type_of_a_service_and_of_any_type_for_a_record_with_no_catalogue_row(): void
    {
        $service = $this->items['implementation']['master'];
        $about = (int) DB::table('g2g_product_master')->where('product_key', 'scholarclone/sch-01')->value('id');

        $this->usage(self::SERVICES_VIEWER, $service)->assertOk();
        $this->usage(self::PRODUCTS_VIEWER, $service)->assertStatus(403);
        $this->usage(self::HR, $service)->assertStatus(403);
        $this->usage(self::PRODUCTS_VIEWER, $about)->assertOk();
        $this->usage(self::SERVICES_VIEWER, $about)->assertOk();
        $this->usage(self::HR, $about)->assertStatus(403);   // HR is not told whether there is a Sell side, and the signals of an About are in the list
        $this->usage(self::NOBODY, $this->sharedId('scholarclone'))->assertStatus(403);
    }

    public function test_a_pointer_into_another_organisation_is_not_followed_no_price_and_no_counts_of_theirs(): void
    {
        $theirs = $this->item('Their item', ['unit_price' => 5, 'currency' => 'USD'], self::T2);
        $about = (int) DB::table('g2g_product_master')->where('product_key', 'scholarclone/sch-01')->value('id');
        DB::table('g2g_product_master')->where('id', $about)->update(['crm_product_id' => $theirs['crm']]);   // a corrupt pointer (the integrity checker calls it I1T)
        $q = $this->row('crm_quotes', ['sub_institute_id' => self::T2, 'quote_no' => 'Q-T2', 'created_at' => now()]);
        $this->row('crm_quote_line_items', ['quote_id' => $q, 'product_id' => $theirs['crm']]);

        $data = $this->usage(self::ADMIN, $about)->assertOk()->json('data');

        $this->assertNull($data['basePrice'], 'their price is not shown under my record');
        $this->assertNull($data['currency']);
        $this->assertSame([null, null, null], [$data['counts'], $data['pointers'], $data['latest']], 'as for a record with no catalogue row of its own');
    }

    public function test_the_usage_is_this_organisations_only(): void
    {
        $other = $this->item('Their item', ['unit_price' => 5], self::T2);
        $q = $this->row('crm_quotes', ['sub_institute_id' => self::T2, 'quote_no' => 'Q-T2', 'created_at' => now()]);
        $this->row('crm_quote_line_items', ['quote_id' => $q, 'product_id' => $other['crm']]);
        $mine = $this->row('crm_quotes', ['sub_institute_id' => self::T, 'quote_no' => 'Q-T1', 'created_at' => now()]);
        $this->row('crm_quote_line_items', ['quote_id' => $mine, 'product_id' => $other['crm']]);   // a stray line of mine holding THEIR item

        $this->usage(self::ADMIN, $other['master'])->assertStatus(404);
        $this->usage(self::ADMIN2)->assertStatus(404);
        $theirs = $this->usage(self::ADMIN2, $other['master'])->assertOk()->json('data');
        $this->assertSame(1, $theirs['counts']['quotes'], 'their count holds their own document only');
        $this->assertSame(['Q-T2'], array_column($theirs['latest']['quotes'], 'number'));
        $this->usage(self::ADMIN, 999999)->assertStatus(404);
    }
}
