<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\ProductIntegrity;
use App\Domain\ProductMaster\ProductPointers;
use App\Domain\ProductMaster\ProductRepointer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCrmProductApiWorld;
use Tests\Support\BuildsProductPointerWorld;
use Tests\TestCase;

/**
 * Merging two catalogue items moves EVERYTHING that points at the duplicate (slice 15, P2-B2: defect D6; Docs/agentic-crm/slices/S15-product-portfolio-merge.md).
 *
 * The merge route used to repoint two of the twelve columns that hold a crm_products id (opportunity products and quote lines); the other ten kept pointing at
 * the item that was now in the Recycle Bin. ProductRepointer walks the registry (ProductPointers::toCrmProduct()) instead of a list in the controller, so a
 * pointer added to the registry is moved without anybody remembering it. These tests run the real route on in-memory sqlite, with one table per registered pointer
 * (built from the registry, the same way tests/Feature/ProductIntegrityTest.php does) and the two REAL unique keys on top of them.
 *
 * What is proved: every registered pointer moves (once per pointer, from the registry); the rows of another organisation never do; only the pointer column changes;
 * the survivor's row wins where a unique key would collide; a bundle never ends up containing itself; counts and sums per table do not move except by the rows a
 * unique key forces out; the checker is clean afterwards; and the repoint is part of the merge's own transaction.
 */
class ProductMergeRepointTest extends TestCase
{
    use BuildsCrmProductApiWorld;
    use BuildsProductPointerWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrmProductApiWorld();
        foreach (ProductPointers::all() as $entry) {
            $this->ensurePointerTable($entry);
        }
        // The registry builds plain tables; these two have the unique keys the real migrations give them.
        Schema::table('crm_price_book_items', fn ($t) => $t->unique(['price_book_id', 'product_id'], 'crm_price_book_item_pair_unique'));
        Schema::table('crm_product_bundle_items', fn ($t) => $t->unique(['parent_product_id', 'component_product_id'], 'crm_bundle_pair_unique'));
    }

    // ---- helpers ----

    /** The real merge route, called by organisation 1's administrator. @param list<int> $duplicates */
    private function mergeInto(int $survivor, array $duplicates): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/crm/products/merge', ['survivorId' => $survivor, 'duplicateIds' => $duplicates], $this->headers($this->h(self::T, 1)));
    }

    /** @return array<string, mixed>|null the whole row, as an array */
    private function row(string $table, int $id): ?array
    {
        $row = DB::table($table)->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    /** @return array<string, mixed> */
    private function report(?int $tenant = self::T): array
    {
        return (new ProductIntegrity(null, $tenant))->check();
    }

    private function assertCheckerClean(string $when): void
    {
        $report = $this->report();
        $this->assertSame(0, $report['errors'], "the checker finds no error {$when}: " . json_encode($report['findings']));
        $this->assertSame(0, $report['warnings'], "and no warning {$when}: " . json_encode($report['findings']));
    }

    /** @return iterable<string, array{0: string, 1: string}> one case per registered pointer at a catalogue item, read from the registry */
    public static function everyPointerAtACatalogueItem(): iterable
    {
        foreach (ProductPointers::toCrmProduct() as $e) {
            yield "{$e['table']}.{$e['column']}" => [$e['table'], $e['column']];
        }
    }

    /** Extra column values a row of this pointer needs so that it is a row a real table could hold (a bundle's other end is never empty). @return array<string, mixed> */
    private function companion(string $table, string $column, int $other): array
    {
        return match ("{$table}.{$column}") {
            'crm_product_bundle_items.parent_product_id' => ['component_product_id' => $other],
            default => [],
        };
    }

    /** Four plain catalogue items that are nobody in the test: the "other end" of a bundle row. @return list<int> */
    private function parts(): array
    {
        return array_map(fn (int $n) => $this->crmCreate("Part {$n}"), [1, 2, 3, 4]);
    }

    /**
     * One row of a registered pointer at $product, with its own quantity and total where that table sums them. A bundle row also needs a part ($part): the pair
     * (parent, part) is unique, so two rows of the bundle table may not share it.
     *
     * @param array<string, mixed> $e a registry entry
     */
    private function lineAt(array $e, int $product, int $part, float $quantity = 1, float $total = 0): int
    {
        $over = $this->companion($e['table'], $e['column'], $part);
        foreach ($e['measures'] as $measure) {
            $over[$measure] = $measure === 'quantity' ? $quantity : $total;
        }

        return $this->pointRow($e, self::T, $product, $over);
    }

    // ================================================================== every registered pointer

    #[DataProvider('everyPointerAtACatalogueItem')]
    public function test_the_merge_moves_this_pointer_from_the_duplicate_to_the_survivor_and_touches_nothing_else(string $table, string $column): void
    {
        $entry = $this->entry($table, $column);
        $dup = $this->crmCreate('Duplicate');
        $survivor = $this->crmCreate('Survivor');
        $third = $this->crmCreate('Third product');
        $someOther = $this->crmCreate('Component');

        $atDup = $this->pointRow($entry, self::T, $dup, $this->companion($table, $column, $someOther));
        $atSurvivor = $this->pointRow($entry, self::T, $survivor, $this->companion($table, $column, $third));
        $atThird = $this->pointRow($entry, self::T, $third, $this->companion($table, $column, $survivor));
        // A row of ANOTHER organisation that carries the duplicate's id (damage the checker calls I1T): a merge of organisation 1 never touches it. A bundle's parent
        // column has no organisation of its own (it is the organisation of the product it names), so that pointer has no such row.
        $isBundleParent = is_array($entry['scope']) && $entry['scope']['via'] === $column;
        $otherOrganisation = $isBundleParent ? null : $this->pointRow($entry, self::T2, $dup, $this->companion($table, $column, $someOther));

        $before = [$atDup => $this->row($table, $atDup), $atSurvivor => $this->row($table, $atSurvivor), $atThird => $this->row($table, $atThird)];
        $otherBefore = $otherOrganisation === null ? null : $this->row($table, $otherOrganisation);
        $rowsBefore = DB::table($table)->count();
        $expectedDupRow = $before[$atDup];
        $expectedDupRow[$column] = $survivor;

        $this->mergeInto($survivor, [$dup])->assertOk()->assertJsonPath('data.merged', 1);

        $this->assertSame($survivor, (int) DB::table($table)->where('id', $atDup)->value($column), "{$table}.{$column}: the row that pointed at the duplicate points at the survivor");
        $this->assertSame($expectedDupRow, $this->row($table, $atDup), 'and only the pointer column changed on that row (nothing else, not even updated_at)');
        $this->assertSame($before[$atSurvivor], $this->row($table, $atSurvivor), 'the survivor\'s own row is as it was');
        $this->assertSame($before[$atThird], $this->row($table, $atThird), 'another product\'s row is as it was');
        if ($otherOrganisation !== null) {
            $this->assertSame($otherBefore, $this->row($table, $otherOrganisation), "a row of organisation 2 is never touched by organisation 1's merge");
        }
        $this->assertSame($rowsBefore, DB::table($table)->count(), 'no row was added or lost');
    }

    public function test_several_duplicates_merge_into_one_survivor_in_one_request(): void
    {
        $d1 = $this->crmCreate('Duplicate one');
        $d2 = $this->crmCreate('Duplicate two');
        $survivor = $this->crmCreate('Survivor');
        $rows = [];
        foreach (ProductPointers::toCrmProduct() as $e) {
            // A bundle's two rows need two different parts, or the pair itself would be the same row twice.
            $partOne = $this->crmCreate('Part one of ' . $e['table'] . '.' . $e['column']);
            $partTwo = $this->crmCreate('Part two of ' . $e['table'] . '.' . $e['column']);
            $rows[] = [
                $e['table'], $e['column'],
                $this->pointRow($e, self::T, $d1, $this->companion($e['table'], $e['column'], $partOne)),
                $this->pointRow($e, self::T, $d2, $this->companion($e['table'], $e['column'], $partTwo)),
            ];
        }

        $this->mergeInto($survivor, [$d1, $d2])->assertOk()->assertJsonPath('data.merged', 2);

        foreach ($rows as [$table, $column, $rowOne, $rowTwo]) {
            $this->assertSame([$survivor, $survivor], [(int) DB::table($table)->where('id', $rowOne)->value($column), (int) DB::table($table)->where('id', $rowTwo)->value($column)], "{$table}.{$column}: both duplicates' rows moved");
        }
    }

    public function test_a_soft_deleted_document_of_the_duplicate_follows_too(): void
    {
        $dup = $this->crmCreate('Duplicate');
        $survivor = $this->crmCreate('Survivor');
        Schema::table('crm_quotes', fn ($t) => $t->timestamp('deleted_at')->nullable());
        $quote = (int) DB::table('crm_quotes')->insertGetId(['sub_institute_id' => self::T, 'updated_at' => self::STAMP, 'deleted_at' => self::STAMP]);
        $line = (int) DB::table('crm_quote_line_items')->insertGetId(['quote_id' => $quote, 'product_id' => $dup, 'quantity' => 2, 'line_total' => 50]);

        $this->mergeInto($survivor, [$dup])->assertOk();

        $this->assertSame($survivor, (int) DB::table('crm_quote_line_items')->where('id', $line)->value('product_id'), 'a quote that was archived long ago still shows the product that is now called the survivor');
    }

    // ================================================================== the totals do not move

    public function test_every_line_count_and_sum_is_unchanged_and_the_survivor_holds_exactly_what_both_held(): void
    {
        $dup = $this->crmCreate('Duplicate');
        $survivor = $this->crmCreate('Survivor');
        $third = $this->crmCreate('Third');
        $parts = $this->parts();
        $counted = [];
        foreach (ProductPointers::toCrmProduct() as $e) {
            // Two rows at the duplicate, one at the survivor, one at a third product, each with its own quantity and total.
            $this->lineAt($e, $dup, $parts[0], 3, 300);
            $this->lineAt($e, $dup, $parts[1], 4, 410);
            $this->lineAt($e, $survivor, $parts[2], 5, 520);
            $this->lineAt($e, $third, $parts[3], 6, 630);
            if ($e['lines']) {
                $counted[] = [$e['table'], $e['column']];
            }
        }
        $before = (new ProductIntegrity(null, self::T))->census();

        $this->mergeInto($survivor, [$dup])->assertOk();

        $after = (new ProductIntegrity(null, self::T))->census();
        $unexpected = array_values(array_filter(ProductIntegrity::differences($before, $after), fn (string $d) => ! str_contains($d, ': rows for product ')));
        $this->assertSame([], $unexpected, 'no table lost or gained a row and no sum moved: ' . implode(' | ', $unexpected));
        $this->assertNotEmpty($counted, 'the registry has pointers that are counted per product');
        foreach ($counted as [$table, $column]) {
            $beforeBy = $before['tables'][$table]['pointers'][$column]['by_product'];
            $afterBy = $after['tables'][$table]['pointers'][$column]['by_product'];
            $this->assertSame(2, $beforeBy[$dup] ?? 0, "{$table}.{$column}: the duplicate had its two rows before");
            $this->assertArrayNotHasKey($dup, $afterBy, "{$table}.{$column}: nothing points at the duplicate any more");
            $this->assertSame(3, $afterBy[$survivor] ?? 0, "{$table}.{$column}: the survivor holds the duplicate's two rows and its own one");
            $this->assertSame($beforeBy[$third], $afterBy[$third] ?? 0, "{$table}.{$column}: a third product's rows did not move");
        }
    }

    // ================================================================== the master side and the checker

    public function test_after_a_merge_of_catalogue_only_items_the_checker_finds_nothing_at_all(): void
    {
        $dup = $this->crmCreate('Duplicate');
        $survivor = $this->crmCreate('Survivor');
        $parts = $this->parts();
        foreach (ProductPointers::toCrmProduct() as $e) {
            $this->lineAt($e, $dup, $parts[0], 1, 10);
            $this->lineAt($e, $survivor, $parts[1], 1, 10);
        }
        $this->assertCheckerClean('before the merge (so the check after it means something)');

        $this->mergeInto($survivor, [$dup])->assertOk();

        $this->assertCheckerClean('after the merge');
        $this->assertSame('archived', $this->masterOfCrm($dup)->status, 'the master side is what it always was: the duplicate\'s row leaves');
        $this->assertSame('active', $this->masterOfCrm($survivor)->status, 'and the survivor\'s stays');
        $this->assertNotNull(DB::table('crm_products')->where('id', $dup)->value('deleted_at'), 'the duplicate is in the Recycle Bin, not deleted');
    }

    public function test_a_merged_away_item_that_a_sub_product_is_sold_through_leaves_a_warning_and_no_error(): void
    {
        // PINNED ON PURPOSE: the existing master-side rule keeps the Sub-product's pointer at an item that went to the Recycle Bin (taking the item out again restores
        // the link). A catalogue merge therefore leaves the checker's W1 warning for such a Sub-product, and never an error. Joining the survivor's item with the
        // Sub-product is the pair review's work (P2-D), not this merge's.
        $sold = $this->soldSubProduct();
        $survivor = $this->crmCreate('Survivor');
        $line = $this->pointRow($this->entry('crm_quote_line_items', 'product_id'), self::T, $sold['crm']);

        $this->mergeInto($survivor, [$sold['crm']])->assertOk();

        $report = $this->report();
        $this->assertSame(0, $report['errors'], json_encode($report['findings']));
        $this->assertSame(1, $report['counts']['W1'], 'the Sub-product still points at the binned item');
        $this->assertSame($survivor, (int) DB::table('crm_quote_line_items')->where('id', $line)->value('product_id'), 'the quote line is on the survivor');
        $this->assertSame('active', $this->masterOfOffer($sold['offer'])->status, 'the Sub-product itself was not archived');
    }

    // ================================================================== unique keys

    public function test_a_price_book_that_already_holds_the_survivor_keeps_the_survivors_row_and_drops_the_duplicates(): void
    {
        $dup = $this->crmCreate('Duplicate');
        $survivor = $this->crmCreate('Survivor');
        $books = [];
        foreach (['both', 'duplicate only', 'survivor only'] as $name) {
            $books[$name] = (int) DB::table('crm_price_books')->insertGetId(['sub_institute_id' => self::T, 'updated_at' => self::STAMP]);
        }
        $item = fn (int $book, int $product, float $price) => (int) DB::table('crm_price_book_items')->insertGetId(['price_book_id' => $book, 'product_id' => $product, 'list_price' => $price, 'updated_at' => self::STAMP]);
        $dupInBoth = $item($books['both'], $dup, 10);
        $survivorInBoth = $item($books['both'], $survivor, 12);
        $dupAlone = $item($books['duplicate only'], $dup, 8);
        $survivorAlone = $item($books['survivor only'], $survivor, 9);
        // Another organisation's book that holds the duplicate AND the survivor (damage; the checker calls it I1T): the pair would collide, and it is not ours to touch.
        $foreignBook = (int) DB::table('crm_price_books')->insertGetId(['sub_institute_id' => self::T2, 'updated_at' => self::STAMP]);
        $foreign = $item($foreignBook, $dup, 77);
        $foreignSurvivor = $item($foreignBook, $survivor, 78);
        $before = (new ProductIntegrity(null, self::T))->census();

        $this->mergeInto($survivor, [$dup])->assertOk();

        $this->assertNull($this->row('crm_price_book_items', $dupInBoth), 'the duplicate\'s row in a book that already has the survivor is dropped');
        $this->assertSame(12.0, (float) DB::table('crm_price_book_items')->where('id', $survivorInBoth)->value('list_price'), 'the survivor\'s price in that book is the one that stays');
        $this->assertSame($survivor, (int) DB::table('crm_price_book_items')->where('id', $dupAlone)->value('product_id'), 'a book that held only the duplicate now holds the survivor, at the duplicate\'s price');
        $this->assertSame(8.0, (float) DB::table('crm_price_book_items')->where('id', $dupAlone)->value('list_price'));
        $this->assertSame($survivor, (int) DB::table('crm_price_book_items')->where('id', $survivorAlone)->value('product_id'));
        $this->assertSame($dup, (int) DB::table('crm_price_book_items')->where('id', $foreign)->value('product_id'), 'another organisation\'s book is not touched, not even where the pair would have collided');
        $this->assertNotNull($this->row('crm_price_book_items', $foreignSurvivor));
        $this->assertSame(3, DB::table('crm_price_book_items')->where('price_book_id', '!=', $foreignBook)->where('product_id', $survivor)->count(), 'the survivor is in each of the three books of organisation 1 exactly once');
        $differences = ProductIntegrity::differences($before, (new ProductIntegrity(null, self::T))->census());
        $this->assertContains('crm_price_book_items: rows 4 -> 3', $differences, 'exactly one row left: the duplicate\'s in the book that had both');
        $this->assertContains('crm_price_book_items: sum of list_price 39.00 -> 29.00', $differences, 'by its price, 10');
    }

    public function test_two_duplicates_in_one_price_book_leave_one_row_for_the_survivor(): void
    {
        $d1 = $this->crmCreate('Duplicate one');
        $d2 = $this->crmCreate('Duplicate two');
        $survivor = $this->crmCreate('Survivor');
        $book = (int) DB::table('crm_price_books')->insertGetId(['sub_institute_id' => self::T, 'updated_at' => self::STAMP]);
        foreach ([[$d1, 5], [$d2, 6]] as [$product, $price]) {
            DB::table('crm_price_book_items')->insert(['price_book_id' => $book, 'product_id' => $product, 'list_price' => $price, 'updated_at' => self::STAMP]);
        }

        $this->mergeInto($survivor, [$d1, $d2])->assertOk();

        $this->assertSame([[$survivor, 5.0]], DB::table('crm_price_book_items')->where('price_book_id', $book)->get()->map(fn ($r) => [(int) $r->product_id, (float) $r->list_price])->all(), 'the first duplicate\'s row moved, the second was the survivor\'s twin by then and went');
    }

    public function test_bundles_keep_one_row_per_pair_and_never_contain_themselves(): void
    {
        $dup = $this->crmCreate('Duplicate');
        $survivor = $this->crmCreate('Survivor');
        [$x1, $x2, $bundleA, $bundleB] = array_map(fn (string $n) => $this->crmCreate($n), ['Part 1', 'Part 2', 'Bundle A', 'Bundle B']);
        $pair = fn (int $parent, int $component, float $quantity = 1) => (int) DB::table('crm_product_bundle_items')->insertGetId(['parent_product_id' => $parent, 'component_product_id' => $component, 'quantity' => $quantity]);
        // The duplicate and the survivor as components of the same bundle (one pair would appear twice), and of different ones.
        $aHoldsBoth = [$pair($bundleA, $dup, 2), $pair($bundleA, $survivor, 3)];
        $bHoldsDup = $pair($bundleB, $dup, 4);
        // The duplicate and the survivor as bundles of the same part (one pair twice) and of different parts.
        $dupHasPart1 = $pair($dup, $x1, 5);
        $dupHasPart2 = $pair($dup, $x2, 6);
        $survivorHasPart1 = $pair($survivor, $x1, 7);
        // The two ends of one merge: the survivor holds the duplicate, and the duplicate holds the survivor (each would become a bundle of itself).
        $survivorHoldsDup = $pair($survivor, $dup, 8);
        $dupHoldsSurvivor = $pair($dup, $survivor, 9);
        // A damaged row: the duplicate is a part of itself. Repointed it would be the survivor as a part of itself, so it goes too.
        $dupHoldsItself = $pair($dup, $dup, 11);
        // Another organisation's bundle that names the duplicate AND the survivor (damage; the pair would collide, and it is not ours to touch).
        $foreignParent = $this->crmCreate('Their bundle', 'product', self::T2, 3);
        $foreign = $pair($foreignParent, $dup, 10);
        $foreignSurvivor = $pair($foreignParent, $survivor, 12);
        $rowsBefore = DB::table('crm_product_bundle_items')->count();

        $this->mergeInto($survivor, [$dup])->assertOk();

        $this->assertNull($this->row('crm_product_bundle_items', $aHoldsBoth[0]), 'a bundle that held both keeps the survivor\'s row');
        $this->assertSame(3.0, (float) DB::table('crm_product_bundle_items')->where('id', $aHoldsBoth[1])->value('quantity'), 'at the survivor\'s quantity');
        $this->assertSame($survivor, (int) DB::table('crm_product_bundle_items')->where('id', $bHoldsDup)->value('component_product_id'), 'a bundle that held only the duplicate now holds the survivor');
        $this->assertNull($this->row('crm_product_bundle_items', $dupHasPart1), 'the duplicate\'s part 1 is the survivor\'s part 1 already: its row goes');
        $this->assertSame(7.0, (float) DB::table('crm_product_bundle_items')->where('id', $survivorHasPart1)->value('quantity'));
        $this->assertSame($survivor, (int) DB::table('crm_product_bundle_items')->where('id', $dupHasPart2)->value('parent_product_id'), 'part 2 moves to the survivor');
        $this->assertNull($this->row('crm_product_bundle_items', $survivorHoldsDup), 'a bundle that would now contain itself is deleted (survivor holding the duplicate)');
        $this->assertNull($this->row('crm_product_bundle_items', $dupHoldsSurvivor), 'and the other direction (duplicate holding the survivor)');
        $this->assertNull($this->row('crm_product_bundle_items', $dupHoldsItself), 'and a duplicate that was a part of itself');
        $this->assertSame(0, DB::table('crm_product_bundle_items')->whereColumn('parent_product_id', 'component_product_id')->count(), 'no bundle contains itself');
        $this->assertSame($dup, (int) DB::table('crm_product_bundle_items')->where('id', $foreign)->value('component_product_id'), 'another organisation\'s bundle is not touched, not even where the pair would have collided');
        $this->assertNotNull($this->row('crm_product_bundle_items', $foreignSurvivor));
        $this->assertSame(0, DB::table('crm_product_bundle_items')->where('parent_product_id', $dup)->orWhere(fn ($q) => $q->where('component_product_id', $dup)->where('parent_product_id', '!=', $foreignParent))->count(), 'nothing of organisation 1 names the duplicate');
        $this->assertSame($rowsBefore - 5, DB::table('crm_product_bundle_items')->count(), 'five rows left: the two collisions and the three that would have been self-containing');
    }

    public function test_a_row_with_an_empty_part_never_collides_because_a_null_is_not_equal_to_a_null_in_a_unique_key(): void
    {
        // The real table cannot hold a NULL here (both columns are NOT NULL), but this replica can, and a unique key never counts two NULLs as the same value, so the
        // merge must not either: it would delete a row that no key forced out.
        $dup = $this->crmCreate('Duplicate');
        $survivor = $this->crmCreate('Survivor');
        $duplicatesRow = (int) DB::table('crm_product_bundle_items')->insertGetId(['parent_product_id' => $dup, 'component_product_id' => null, 'quantity' => 1]);
        $survivorsRow = (int) DB::table('crm_product_bundle_items')->insertGetId(['parent_product_id' => $survivor, 'component_product_id' => null, 'quantity' => 1]);

        $this->mergeInto($survivor, [$dup])->assertOk();

        $this->assertSame($survivor, (int) DB::table('crm_product_bundle_items')->where('id', $duplicatesRow)->value('parent_product_id'), 'moved, not dropped');
        $this->assertNotNull($this->row('crm_product_bundle_items', $survivorsRow));
    }

    public function test_a_row_that_already_contains_itself_is_left_alone_and_the_merge_still_works(): void
    {
        // Damage that exists before the merge (a product that is a part of itself) is not this merge's to repair, and must not stop it.
        $dup = $this->crmCreate('Duplicate');
        $survivor = $this->crmCreate('Survivor');
        $other = $this->crmCreate('Other');
        $damaged = (int) DB::table('crm_product_bundle_items')->insertGetId(['parent_product_id' => $other, 'component_product_id' => $other, 'quantity' => 1]);
        $moves = (int) DB::table('crm_product_bundle_items')->insertGetId(['parent_product_id' => $other, 'component_product_id' => $dup, 'quantity' => 1]);

        $this->mergeInto($survivor, [$dup])->assertOk();

        $this->assertNotNull($this->row('crm_product_bundle_items', $damaged));
        $this->assertSame($survivor, (int) DB::table('crm_product_bundle_items')->where('id', $moves)->value('component_product_id'));
    }

    public function test_an_opportunity_that_lists_both_keeps_both_rows_as_there_is_no_unique_key_to_protect(): void
    {
        // crm_opportunity_products has no unique key (its migration and its add route allow the same product twice), so nothing may be dropped: the quantity of
        // each row is the opportunity's own interest, and the count per table must not move.
        $dup = $this->crmCreate('Duplicate');
        $survivor = $this->crmCreate('Survivor');
        $opportunity = (int) DB::table('crm_opportunities')->insertGetId(['sub_institute_id' => self::T, 'updated_at' => self::STAMP]);
        $rows = [
            (int) DB::table('crm_opportunity_products')->insertGetId(['opportunity_id' => $opportunity, 'product_id' => $dup, 'quantity' => 2]),
            (int) DB::table('crm_opportunity_products')->insertGetId(['opportunity_id' => $opportunity, 'product_id' => $survivor, 'quantity' => 3]),
        ];

        $this->mergeInto($survivor, [$dup])->assertOk();

        $this->assertSame([[$survivor, 2.0], [$survivor, 3.0]], DB::table('crm_opportunity_products')->whereIn('id', $rows)->orderBy('id')->get()->map(fn ($r) => [(int) $r->product_id, (float) $r->quantity])->all());
    }

    // ================================================================== one transaction

    public function test_a_failure_half_way_through_the_repoint_leaves_the_merge_undone(): void
    {
        $dup = $this->crmCreate('Duplicate');
        $survivor = $this->crmCreate('Survivor');
        $neutral = $this->crmCreate('Neutral');
        $rows = [];
        foreach (ProductPointers::toCrmProduct() as $e) {
            $rows[] = [$e['table'], $e['column'], $this->lineAt($e, $dup, $neutral, 1, 10)];
        }
        // One table, late in the registry, whose update fails: everything moved before it has to come back.
        DB::unprepared("CREATE TRIGGER fail_faqs BEFORE UPDATE ON crm_faqs BEGIN SELECT RAISE(ABORT, 'the faq write failed'); END");

        $this->mergeInto($survivor, [$dup])->assertStatus(500);

        foreach ($rows as [$table, $column, $id]) {
            $this->assertSame($dup, (int) DB::table($table)->where('id', $id)->value($column), "{$table}.{$column}: nothing was moved, because one write failed and the merge is one transaction");
        }
        $this->assertNull(DB::table('crm_products')->where('id', $dup)->value('deleted_at'), 'and the duplicate is not in the Recycle Bin');
    }

    // ================================================================== the class on its own

    public function test_merging_a_product_into_itself_or_with_no_rows_does_nothing_and_says_so(): void
    {
        $a = $this->crmCreate('A');
        $b = $this->crmCreate('B');
        $line = $this->pointRow($this->entry('crm_quote_line_items', 'product_id'), self::T, $a);
        $before = $this->row('crm_quote_line_items', $line);

        $this->assertSame(['moved' => [], 'dropped' => []], ProductRepointer::repoint(self::T, $a, $a), 'a product merged into itself');
        $this->assertSame(['moved' => [], 'dropped' => []], ProductRepointer::repoint(self::T, $b, $a), 'a product nothing points at');
        $this->assertSame($before, $this->row('crm_quote_line_items', $line));

        $result = ProductRepointer::repoint(self::T, $a, $b);
        $this->assertSame(['crm_quote_line_items.product_id' => 1], $result['moved'], 'the report names each pointer that moved and how many rows');
        $this->assertSame([], $result['dropped']);
        $this->assertSame(['moved' => [], 'dropped' => []], ProductRepointer::repoint(self::T, $a, $b), 'repeating it moves nothing more');
    }

    public function test_a_product_id_of_zero_is_not_a_product_and_is_never_the_one_that_moves_or_the_one_moved_to(): void
    {
        $a = $this->crmCreate('A');
        $noProduct = $this->pointRow($this->entry('crm_tickets', 'product_id'), self::T, 0);   // "no product" written as 0 by a careless import
        $atA = $this->pointRow($this->entry('crm_tickets', 'product_id'), self::T, $a);

        $this->assertSame(['moved' => [], 'dropped' => []], ProductRepointer::repoint(self::T, 0, $a), 'nothing is "merged" out of the no-product rows');
        $this->assertSame(['moved' => [], 'dropped' => []], ProductRepointer::repoint(self::T, $a, 0), 'and nothing is moved onto no product');
        $this->assertSame(0, (int) DB::table('crm_tickets')->where('id', $noProduct)->value('product_id'));
        $this->assertSame($a, (int) DB::table('crm_tickets')->where('id', $atA)->value('product_id'));
    }

    public function test_the_report_counts_what_moved_and_what_a_unique_key_dropped(): void
    {
        $dup = $this->crmCreate('Duplicate');
        $survivor = $this->crmCreate('Survivor');
        $book = (int) DB::table('crm_price_books')->insertGetId(['sub_institute_id' => self::T, 'updated_at' => self::STAMP]);
        DB::table('crm_price_book_items')->insert([['price_book_id' => $book, 'product_id' => $dup, 'list_price' => 1, 'updated_at' => self::STAMP], ['price_book_id' => $book, 'product_id' => $survivor, 'list_price' => 2, 'updated_at' => self::STAMP]]);
        $this->pointRow($this->entry('crm_tickets', 'product_id'), self::T, $dup);
        $this->pointRow($this->entry('crm_tickets', 'product_id'), self::T, $dup);

        $result = ProductRepointer::repoint(self::T, $dup, $survivor);

        $this->assertSame(['crm_tickets.product_id' => 2], $result['moved']);
        $this->assertSame(['crm_price_book_items.product_id' => 1], $result['dropped']);
    }

    public function test_a_host_without_one_of_the_tables_skips_it_and_moves_the_rest(): void
    {
        $dup = $this->crmCreate('Duplicate');
        $survivor = $this->crmCreate('Survivor');
        $this->pointRow($this->entry('crm_faqs', 'product_id'), self::T, $dup);
        $ticket = $this->pointRow($this->entry('crm_tickets', 'product_id'), self::T, $dup);
        $invoiceLine = $this->pointRow($this->entry('crm_invoice_line_items', 'product_id'), self::T, $dup);
        Schema::drop('crm_faqs');
        Schema::drop('crm_product_bundle_items');   // the table of a pair: the pass that deletes rows containing themselves must skip it too
        Schema::drop('crm_invoices');               // the document table a line's organisation is read from: without it nobody can say whose the line is

        $this->mergeInto($survivor, [$dup])->assertOk();

        $this->assertSame($survivor, (int) DB::table('crm_tickets')->where('id', $ticket)->value('product_id'), 'the older host\'s missing table is not an error');
        $this->assertSame($dup, (int) DB::table('crm_invoice_line_items')->where('id', $invoiceLine)->value('product_id'), 'a line whose document table is missing is not guessed at: left as it is');
    }
}
