<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\ProductPointers;
use App\Domain\ProductMaster\ProductUseCount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCrmProductApiWorld;
use Tests\TestCase;

/**
 * The Recycle Bin's "Delete permanently" no longer orphans a catalogue item's pointers (slice 15, P2-E2a step 1; audit finding G-1,
 * Docs/agentic-crm/_evidence/s15/products-parity-audit-1-fields-endpoints-2026-11-13.md).
 *
 * `DELETE /api/crm/recycle-bin/{products|services}/{id}` used to hard-delete a `crm_products` row that 10 tables point at (11 columns: the lines of quotes, sales orders
 * and invoices, price-book items, opportunity products, both ends of a bundle, tickets, assets, FAQs and campaigns); the foreign keys then cascaded or
 * nulled, and the loose `crm_campaigns.product_id` was simply left behind. Now the delete is refused with a 409 `product_in_use` and the exact count per pointer while any
 * registered pointer (ProductPointers::toCrmProduct) holds the id; an item nothing points at is deleted as before and its Product Master row follows.
 *
 * The pointer tables are built FROM THE REGISTRY, so a pointer added to the registry is tested here without an edit. The master row's own `crm_product_id` is not a use
 * that blocks the delete: ProductMasterSync archives a catalogue-only row or lets a Sub-product go of the item when it is deleted for good (it is the thing that follows).
 */
class CrmRecycleBinProductGuardTest extends TestCase
{
    use BuildsCrmProductApiWorld;

    private const T = 1;

    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrmProductApiWorld();
        foreach (ProductPointers::toCrmProduct() as $e) {
            if (! Schema::hasTable($e['table'])) {
                Schema::create($e['table'], fn ($t) => $t->id());
            }
            if (! Schema::hasColumn($e['table'], $e['column'])) {
                Schema::table($e['table'], fn ($t) => $t->unsignedBigInteger($e['column'])->nullable());
            }
        }
    }

    // ---- helpers ----

    /** A catalogue item made through the real door and put in the Recycle Bin through the real door. */
    private function binned(string $type = 'product', string $name = 'Starter kit', int $tenant = self::T): int
    {
        $id = $this->crmCreate($name, $type, $tenant, $tenant === self::T ? 1 : 3);
        $this->deleteJson("/api/crm/products/{$id}", [], $this->headers($this->h($tenant, $tenant === self::T ? 1 : 3)))->assertOk();
        $this->assertNotNull(DB::table('crm_products')->where('id', $id)->value('deleted_at'), 'the item is in the Recycle Bin');

        return $id;
    }

    private function point(string $table, string $column, int $crmId, int $rows = 1): void
    {
        for ($i = 0; $i < $rows; $i++) {
            DB::table($table)->insert([$column => $crmId]);
        }
    }

    private function forceDelete(string $type, int $id, int $tenant = self::T)
    {
        return $this->deleteJson("/api/crm/recycle-bin/{$type}/{$id}", [], $this->headers($this->h($tenant, $tenant === self::T ? 1 : 3)));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function everyPointer(): array
    {
        $cases = [];
        foreach (ProductPointers::toCrmProductInUse() as $e) {
            $cases["{$e['table']}.{$e['column']}"] = [$e['table'], $e['column']];
        }

        return $cases;
    }

    public function test_a_dormant_price_book_row_is_no_use_and_never_blocks_the_delete(): void
    {
        // S16: the Price Book is gone; its rows are kept only so a merge repoints them and the checker finds orphans (ProductPointers::DORMANT). They are not a use.
        $id = $this->binned();
        $this->point('crm_price_book_items', 'product_id', $id, 3);

        $this->assertSame([], ProductUseCount::atCatalogueItem($id));
        $this->forceDelete('products', $id)->assertOk();
        $this->assertNull(DB::table('crm_products')->where('id', $id)->first(), 'the item is gone for good');
    }

    // ---- the guard ----

    public function test_a_binned_item_with_a_quote_line_is_refused_and_names_the_table_and_nothing_is_deleted(): void
    {
        $id = $this->binned();
        $master = $this->masterOfCrm($id);
        $this->point('crm_quote_line_items', 'product_id', $id);

        $r = $this->forceDelete('products', $id)->assertStatus(409);

        $r->assertJsonPath('status', 0)->assertJsonPath('code', 'product_in_use');
        $this->assertSame([['table' => 'crm_quote_line_items', 'column' => 'product_id', 'label' => 'quote lines', 'count' => 1]], $r->json('used_by'));
        $this->assertSame('This product is used by 1 record (quote lines). Restore it instead, or ask for it to be merged.', $r->json('message'));
        $this->assertNotNull(DB::table('crm_products')->where('id', $id)->first(), 'the item is still in the Recycle Bin');
        $this->assertSame(1, DB::table('crm_quote_line_items')->where('product_id', $id)->count(), 'the line is untouched');
        $this->assertEquals($master, $this->masterOfCrm($id), 'the master row did not move');
    }

    public function test_a_binned_item_nothing_points_at_is_deleted_as_before_and_its_master_row_follows(): void
    {
        $id = $this->binned();
        $this->assertSame('archived', $this->masterOfCrm($id)->status);

        $this->forceDelete('products', $id)->assertOk()->assertJsonPath('message', 'Permanently deleted.');

        $this->assertNull(DB::table('crm_products')->where('id', $id)->first(), 'the item is gone for good');
        $this->assertSame('archived', $this->masterOfCrm($id)->status, 'the catalogue-only master row stays archived (ProductMasterSync, as before)');
    }

    public function test_the_master_rows_own_pointer_alone_does_not_block_the_delete_and_the_sub_product_lets_go_of_the_item(): void
    {
        $f = $this->soldSubProduct();
        $this->deleteJson("/api/crm/products/{$f['crm']}", [], $this->headers($this->h()))->assertOk();
        $this->assertSame($f['crm'], (int) $this->masterOfOffer($f['offer'])->crm_product_id, 'the pointer is kept while the item is in the Recycle Bin');

        $this->forceDelete('products', $f['crm'])->assertOk();

        $this->assertNull($this->masterOfOffer($f['offer'])->crm_product_id, 'the Sub-product no longer points at a row that is gone');
        $this->assertSame('active', $this->masterOfOffer($f['offer'])->status);
    }

    #[DataProvider('everyPointer')]
    public function test_each_registered_pointer_alone_refuses_the_delete_and_is_named(string $table, string $column): void
    {
        $id = $this->binned();
        $this->point($table, $column, $id, 2);

        $r = $this->forceDelete('products', $id)->assertStatus(409);

        $this->assertSame([['table' => $table, 'column' => $column, 'label' => ProductUseCount::LABELS["{$table}.{$column}"], 'count' => 2]], $r->json('used_by'), 'the count is exact for this one pointer');
        $this->assertNotNull(DB::table('crm_products')->where('id', $id)->first(), 'nothing was deleted');
    }

    public function test_the_count_is_exact_per_table_when_many_tables_point_at_the_item(): void
    {
        $id = $this->binned();
        $other = $this->binned('product', 'Another kit');
        $expected = [];
        $n = 0;
        foreach (ProductPointers::toCrmProductInUse() as $e) {
            $n++;
            $this->point($e['table'], $e['column'], $id, $n);
            // Rows of ANOTHER item in the same columns are no use of this one.
            $this->point($e['table'], $e['column'], $other, 3);
            $expected[] = ['table' => $e['table'], 'column' => $e['column'], 'label' => ProductUseCount::LABELS["{$e['table']}.{$e['column']}"], 'count' => $n];
        }

        $r = $this->forceDelete('products', $id)->assertStatus(409);

        $this->assertSame($expected, $r->json('used_by'), 'one entry per registered pointer, in registry order, with its own exact count');
        $total = array_sum(array_column($expected, 'count'));
        $this->assertStringContainsString("used by {$total} records (", $r->json('message'));
        $this->assertGreaterThanOrEqual(5, count($expected), 'the exactness is proved on at least five tables');
    }

    public function test_a_row_in_the_bin_of_a_document_still_counts_because_the_delete_would_reach_it(): void
    {
        // The documents the quote lines belong to may be in the Recycle Bin themselves: the foreign key cascade would still delete the line, and a person who restores the
        // quote would find it short. The guard therefore counts the rows that point, not the documents that are alive.
        $id = $this->binned();
        $this->point('crm_invoice_line_items', 'product_id', $id);

        $this->forceDelete('products', $id)->assertStatus(409)->assertJsonPath('used_by.0.table', 'crm_invoice_line_items');
    }

    public function test_a_service_is_guarded_the_same_way_and_says_service(): void
    {
        $id = $this->binned('service', 'Training');
        $this->point('crm_tickets', 'product_id', $id);

        $r = $this->forceDelete('services', $id)->assertStatus(409);

        $r->assertJsonPath('code', 'product_in_use');
        $this->assertStringStartsWith('This service is used by 1 record (tickets).', $r->json('message'));
        $this->assertNotNull(DB::table('crm_products')->where('id', $id)->first());
    }

    public function test_another_organisations_item_is_not_found_as_before_and_is_not_counted(): void
    {
        $theirs = $this->binned('product', 'Their kit', self::T2);
        $this->point('crm_quote_line_items', 'product_id', $theirs);

        $this->forceDelete('products', $theirs, self::T)->assertStatus(404)->assertJsonPath('message', 'Record not found in Recycle Bin.');
        $this->assertNotNull(DB::table('crm_products')->where('id', $theirs)->first());
    }

    public function test_an_item_that_is_not_in_the_bin_is_not_found_as_before(): void
    {
        $id = $this->crmCreate('Live kit');
        $this->point('crm_quote_line_items', 'product_id', $id);

        $this->forceDelete('products', $id)->assertStatus(404);
    }

    public function test_the_other_record_types_of_the_recycle_bin_are_untouched(): void
    {
        Schema::create('crm_service_contracts', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('subject')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->unsignedBigInteger('deleted_by')->nullable();
        });
        DB::table('tblmenumaster_g2g')->insert(['id' => 470, 'menu_name' => 'Service Contracts', 'parent_id' => 199, 'level' => 2, 'access_link' => '/module/crm/support/service-contracts', 'sort_order' => 470]);
        DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => self::T, 'menu_id' => 470, 'profile_id' => 1, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1]);
        $contract = (int) DB::table('crm_service_contracts')->insertGetId(['sub_institute_id' => self::T, 'subject' => 'Acme', 'deleted_at' => now(), 'deleted_by' => 1]);
        // A quote line that holds the SAME number in product_id: only a catalogue item's delete looks at the product pointers.
        $this->point('crm_quote_line_items', 'product_id', $contract);

        $this->forceDelete('service_contracts', $contract)->assertOk()->assertJsonPath('message', 'Permanently deleted.');

        $this->assertNull(DB::table('crm_service_contracts')->where('id', $contract)->first(), 'a service contract is deleted exactly as before');
    }

    public function test_an_unknown_type_is_still_a_404(): void
    {
        $this->forceDelete('widgets', 5)->assertStatus(404)->assertJsonPath('message', 'Unknown record type.');
    }

    public function test_the_use_count_class_answers_nothing_for_an_item_nothing_points_at_and_skips_a_table_the_host_lacks(): void
    {
        $this->assertSame([], ProductUseCount::atCatalogueItem(987654));
        Schema::drop('crm_campaigns');

        $this->assertSame([], ProductUseCount::atCatalogueItem(987654), 'a table this host does not have is skipped, never an error');
    }

    public function test_every_registered_pointer_has_a_label_a_person_can_read(): void
    {
        // S16: the dormant price-book pointer stays in the registry (a merge repoints it, the checker finds orphans) but is no USE and so has no label (ProductPointers::DORMANT).
        $keys = array_map(fn (array $e): string => $e['table'] . '.' . $e['column'], [...ProductPointers::toCrmProductInUse(), ...ProductPointers::toOffer()]);
        sort($keys);
        $labelled = array_keys(ProductUseCount::LABELS);
        sort($labelled);

        $this->assertSame($keys, $labelled, 'a pointer added to the registry needs a label here, and a label for no pointer is stale');
        foreach (ProductUseCount::LABELS as $key => $label) {
            $this->assertGreaterThanOrEqual(4, strlen($label), "{$key} needs a label");
        }
    }
}
