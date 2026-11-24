<?php

namespace Tests\Feature;

use App\Domain\Portfolio\OfferIcpSync;
use App\Domain\Portfolio\ProductOffer;
use App\Domain\ProductMaster\ProductPointers;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsProductRemovalWorld;
use Tests\TestCase;

/**
 * The cost of a preview and of a delete does not grow with the number of Sub-products under a Product (S15 follow-up, 24 Nov 2026; found on the dev database, where one query takes
 * 150 to 250 ms and the PHP limit is 60 seconds: the preview of a Product with Sub-products answered 500 after 74 seconds).
 *
 * Why it grew: every record asked the pointer registry (about 40 tables) its own questions, so a Product with N Sub-products cost N times about 40 queries. Now the Product and its
 * Sub-products are asked together: ONE query per pointer table over all their ids, grouped in PHP; the organisation's ICPs are read once; the rights are asked once per kind of
 * right; the Sell rows, About rows and item types are read once; the deletes are one statement per table. These tests count the queries (DB query log, sqlite) and compare a Product
 * with 3 Sub-products with a Product with 12, built the same way: the counts must be EQUAL.
 *
 * What is NOT constant, and is said here so nobody is surprised: the `move` choice writes each Sub-product through ProductMasterSync::mirror() (the writer's own path, which the
 * contract tells us to reuse), which costs a handful of queries per Sub-product; and the event store gets one insert per history line after the commit (the recorder here is a fake,
 * so those are not in the count). The READ side of a move is constant, and that is asserted.
 */
class ProductRemovalQueryCountTest extends TestCase
{
    use BuildsProductRemovalWorld;

    private const T = 1;

    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildProductRemovalWorld();
    }

    /** A Product with `$n` Sub-products built the same way each: an About, a Sell row, an ICP of its own and a ticket on a different pointer table. @return array{product: int, children: list<array{offer: int, master: int, crm: int}>} */
    private function family(string $label, string $key, int $n): array
    {
        $product = $this->makeProduct($label, $key);
        $children = [];
        for ($i = 1; $i <= $n; $i++) {
            $child = $this->makeSold($product, strtoupper($key[0]) . $i, "Child {$i} of {$label}");
            app(OfferIcpSync::class)->sync(self::T, 1, ProductOffer::query()->findOrFail($child['offer']), ['b2b' => ['label' => "Own {$i}"]]);
            $children[] = $child;
        }

        return ['product' => $product, 'children' => $children];
    }

    /** @return list<string> every statement the request ran */
    private function statements(\Closure $do): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $do();
        $log = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $log;
    }

    /** The statements that READ a pointer table or the ICP table: the part of a preview that used to grow with every Sub-product. @param list<string> $log @return list<string> */
    private function pointerReads(array $log): array
    {
        $tables = array_values(array_diff(array_unique([...ProductPointers::tables(), 'g2g_offer_readiness_log', 'g2g_icp_profiles']), ['crm_products']));

        return array_values(array_filter($log, function (string $sql) use ($tables): bool {
            if (! str_starts_with(strtolower(ltrim($sql)), 'select')) {
                return false;
            }
            foreach ($tables as $table) {
                if (preg_match('/["`]' . preg_quote($table, '/') . '["`]/', $sql) === 1) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function preview(int $id): void
    {
        $this->getJson("/api/product-master/{$id}/removal", $this->who(self::ADMIN))->assertOk();
    }

    // ================================================================== the preview

    public function test_the_preview_of_a_product_costs_the_same_with_3_sub_products_as_with_12(): void
    {
        $small = $this->family('Payroll Cloud', 'payroll_cloud', 3);
        $large = $this->family('HR Suite', 'hr_suite', 12);
        $this->preview($small['product']);   // one warm-up: what the framework itself caches on a first request is not the code's cost

        $few = $this->statements(fn () => $this->preview($small['product']));
        $many = $this->statements(fn () => $this->preview($large['product']));

        $this->assertSame(count($few), count($many), 'a Product with 3 Sub-products and one with 12 ask the database the same number of questions (' . count($few) . ' and ' . count($many) . ')');
        $this->assertSame(count($this->pointerReads($few)), count($this->pointerReads($many)), 'and so do the reads of the pointer tables');
        $this->assertLessThanOrEqual(80, count($many), 'one batch per pointer table, not 40 queries per record: ' . count($many) . ' statements');
    }

    public function test_the_preview_of_one_sub_product_is_under_60_queries(): void
    {
        $family = $this->family('Payroll Cloud', 'payroll_cloud', 2);
        $this->preview($family['children'][0]['master']);

        $log = $this->statements(fn () => $this->preview($family['children'][1]['master']));
        $grouped = count(array_filter($log, fn (string $sql) => str_contains($sql, 'pointer_rows')));

        // The world has only some of the pointer tables; the dev database has all 22 pointer columns, so its count is this one plus the grouped reads this world does not have.
        $this->assertLessThanOrEqual(60 - (22 - $grouped), count($log), 'a Sub-product preview is one batch per pointer table: ' . count($log) . ' statements, of which ' . $grouped . ' read a pointer column (the dev database has 22)');
        $this->assertLessThanOrEqual(22, $grouped);
    }

    public function test_the_icp_list_is_read_once_however_many_sub_products_there_are(): void
    {
        $small = $this->family('Payroll Cloud', 'payroll_cloud', 3);
        $large = $this->family('HR Suite', 'hr_suite', 12);
        $this->preview($small['product']);

        $few = $this->statements(fn () => $this->preview($small['product']));
        $many = $this->statements(fn () => $this->preview($large['product']));
        $icp = fn (array $log) => count(array_filter($log, fn (string $sql) => str_contains($sql, '"g2g_icp_profiles"') && str_starts_with(strtolower(ltrim($sql)), 'select')));

        $this->assertSame($icp($few), $icp($many), 'the ICPs of the organisation are read the same number of times for 3 and for 12');
        $this->assertLessThanOrEqual(2, $icp($many), 'once for the ICP scan and at most once more');
    }

    // ================================================================== the delete

    public function test_the_delete_together_costs_the_same_with_3_sub_products_as_with_12(): void
    {
        $warm = $this->family('Warm line', 'warm_line', 1);
        $small = $this->family('Payroll Cloud', 'payroll_cloud', 3);
        $large = $this->family('HR Suite', 'hr_suite', 12);
        $this->postJson('/api/product-master/' . $warm['product'] . '/remove', ['subProducts' => 'delete'], $this->who(self::ADMIN))->assertOk();   // one warm-up: what the framework caches on a first delete is not the code's cost

        $few = $this->statements(fn () => $this->postJson('/api/product-master/' . $small['product'] . '/remove', ['subProducts' => 'delete'], $this->who(self::ADMIN))->assertOk());
        $many = $this->statements(fn () => $this->postJson('/api/product-master/' . $large['product'] . '/remove', ['subProducts' => 'delete'], $this->who(self::ADMIN))->assertOk());

        $this->assertSame(count($few), count($many), 'deleting a Product with 3 Sub-products and one with 12 ask the database the same number of questions (' . count($few) . ' and ' . count($many) . ')');
        $this->assertSame(0, DB::table('g2g_product_master')->count(), 'and both Products went with all their Sub-products');
        $this->assertSame(0, DB::table('g2g_icp_profiles')->count());
    }

    public function test_the_move_choice_reads_the_same_with_3_sub_products_as_with_12_and_each_move_costs_a_bounded_number_of_writes(): void
    {
        $small = $this->family('Payroll Cloud', 'payroll_cloud', 3);
        $large = $this->family('HR Suite', 'hr_suite', 12);
        $target = $this->makeProduct('Target line', 'target_line');
        $this->preview($small['product']);

        $few = $this->statements(fn () => $this->postJson('/api/product-master/' . $small['product'] . '/remove', ['subProducts' => 'move', 'toProductId' => $target], $this->who(self::ADMIN))->assertOk());
        $many = $this->statements(fn () => $this->postJson('/api/product-master/' . $large['product'] . '/remove', ['subProducts' => 'move', 'toProductId' => $target], $this->who(self::ADMIN))->assertOk());

        $this->assertSame(count($this->pointerReads($few)), count($this->pointerReads($many)), 'the reads of the pointer tables and of the ICPs do not depend on how many Sub-products move');
        $perChild = (count($many) - count($few)) / 9;
        $this->assertLessThanOrEqual(25, $perChild, "each moved Sub-product costs the writer's own path (ProductMasterSync::mirror), a bounded number of statements: {$perChild}");
    }

    public function test_a_blocked_product_costs_the_same_as_a_free_one(): void
    {
        $free = $this->family('Payroll Cloud', 'payroll_cloud', 6);
        $held = $this->family('HR Suite', 'hr_suite', 6);
        foreach ($held['children'] as $child) {
            $this->quoteLineHolds($child['crm']);
            $this->ticketAbout($child['crm']);
            $this->leadAbout($child['master']);
        }
        $this->preview($free['product']);

        $a = $this->statements(fn () => $this->preview($free['product']));
        $b = $this->statements(fn () => $this->preview($held['product']));

        $this->assertSame(count($a), count($b), 'what blocks a Sub-product does not change how many questions are asked');
    }
}
