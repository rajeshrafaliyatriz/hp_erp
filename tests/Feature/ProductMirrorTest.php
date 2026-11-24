<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\ProductIntegrity;
use App\Domain\ProductMaster\ProductMaster;
use App\Domain\ProductMaster\ProductMasterInvalid;
use App\Domain\ProductMaster\ProductMasterSync;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCrmProductApiWorld;
use Tests\TestCase;

/**
 * ONE name and ONE product for a Sub-product, whichever door changes it (slice 15, P2-B2: defects D7 and D8; Docs/agentic-crm/slices/S15-product-portfolio-merge.md).
 *
 * A Sub-product that is sold through the CRM lives in three places: its master row, its Portfolio offer and its catalogue item. ProductMasterSync::mirror() is the
 * one method that writes a name and a product to every copy that exists, in one transaction; catalogueItem() (the CRM door) and offer() (the Portfolio door) call it.
 * These tests prove it on the real routes and on the method itself, on in-memory sqlite:
 *   - every copy changes, only the copies that exist change, and a different record with the same name never does;
 *   - the three writes are ONE transaction (the sequence of statements is read, and a failure at each copy leaves all three as they were);
 *   - nothing is swallowed on this path, while the legacy entry points still answer as before;
 *   - D8: a catalogue item with no product line never takes the product away from the Sub-product it is sold through.
 */
class ProductMirrorTest extends TestCase
{
    use BuildsCrmProductApiWorld;

    private const T = 1;

    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrmProductApiWorld();
    }

    // ---- helpers ----

    /** @return array<string, mixed> */
    private function check(): array
    {
        return (new ProductIntegrity(null, self::T))->check();
    }

    private function assertChecker(string $why): void
    {
        $report = $this->check();
        $this->assertSame([], array_filter($report['counts']), "the checker is clean {$why}: " . json_encode($report['findings']));
    }

    /** What each of the three copies holds now. @param array{offer: int, crm: int, master: int, product: int} $f @return array<string, array<int, mixed>> */
    private function copies(array $f): array
    {
        $m = DB::table('g2g_product_master')->where('id', $f['master'])->first();
        $o = DB::table('g2g_product_offers')->where('id', $f['offer'])->first();
        $c = DB::table('crm_products')->where('id', $f['crm'])->first();

        return [
            'master' => [$m->name, (int) $m->parent_id, $m->product_key],
            'offer' => [$o->name, $o->parent_product],
            'crm' => [$c->name, (int) $c->product_master_id],
        ];
    }

    /** Every statement that writes, in order, with the transaction boundaries around them. @return list<string> */
    private function recordWrites(\Closure $work): array
    {
        $log = [];
        DB::listen(function ($q) use (&$log) {
            if (preg_match('/^\s*(update|insert\s+into|delete\s+from)\s+["`]?(\w+)/i', $q->sql, $m) === 1) {
                $log[] = strtoupper(explode(' ', trim($m[1]))[0]) . ' ' . $m[2];
            }
        });
        Event::listen(TransactionBeginning::class, function () use (&$log) {
            $log[] = 'BEGIN';
        });
        Event::listen(TransactionCommitted::class, function () use (&$log) {
            $log[] = 'COMMIT';
        });
        Event::listen(TransactionRolledBack::class, function () use (&$log) {
            $log[] = 'ROLLBACK';
        });
        $work();

        return $log;
    }

    /** Makes every UPDATE of one table fail, as a database that is down half way would. */
    private function failUpdatesOf(string $table): void
    {
        DB::unprepared("CREATE TRIGGER fail_{$table} BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, 'the {$table} write failed'); END");
    }

    // ================================================================== D8: a restored item stays in its sub-product

    /**
     * The cause of D8, in one test. ProductMasterSync::catalogueItem() copied the catalogue item's `product_master_id` onto the master row of the Sub-product that
     * holds the item, also when the item has none (its Products form leaves the line empty, and every row made before the line column existed has none). A NULL
     * parent takes the Sub-product out of the list, which groups Sub-products by their parent: it "disappeared" when the item came back from the Recycle Bin, and
     * equally on any ordinary save of the item (a price change).
     *
     * @return iterable<string, array{0: string}>
     */
    public static function waysTheItemIsSavedAgain(): iterable
    {
        yield 'restored from the Recycle Bin' => ['restore'];
        yield 'an ordinary edit (a new price)' => ['edit'];
    }

    #[DataProvider('waysTheItemIsSavedAgain')]
    public function test_a_catalogue_item_with_no_product_line_never_clears_the_product_of_the_sub_product_it_is_sold_through(string $how): void
    {
        $f = $this->soldSubProduct();
        DB::table('crm_products')->where('id', $f['crm'])->update(['product_master_id' => null]);   // an item that was never given a line
        $h = $this->headers($this->h());

        if ($how === 'restore') {
            $this->deleteJson("/api/crm/products/{$f['crm']}", [], $h)->assertOk();
            $this->postJson("/api/crm/recycle-bin/products/{$f['crm']}/restore", [], $h)->assertOk();
        } else {
            $this->putJson("/api/crm/products/{$f['crm']}", ['unitPrice' => 99], $h)->assertOk();
        }

        $this->assertSame($f['product'], (int) $this->masterOfOffer($f['offer'])->parent_id, 'the sub-product is still under its Product: a missing line on the item never removes it');
        $scholar = collect($this->getJson('/api/product-master', $h)->assertOk()->json('data.products'))->firstWhere('key', 'scholarclone');
        $sub = collect($scholar['subProducts'])->firstWhere('offerId', 'P9');
        $this->assertNotNull($sub, 'and the Product Master list still shows it under that Product');
        $this->assertTrue($sub['soldInCrm'], 'sold in the CRM, as before');
        $this->assertSame($f['product'], (int) DB::table('crm_products')->where('id', $f['crm'])->value('product_master_id'), 'the item learns the Product of its Sub-product, so the two stores agree');
        $this->assertSame('scholarclone/p9', $this->masterOfOffer($f['offer'])->product_key, 'and the key is the one it always had');
        $this->assertChecker('after the item is saved again');
    }

    public function test_an_item_that_names_another_product_still_moves_the_sub_product_to_it(): void
    {
        // The other side of D8: only a MISSING line is ignored. A line that is set is the item's word, and the Sub-product follows it (the one-record rule).
        $f = $this->soldSubProduct();
        $eb = $this->sharedId('enterprise_brain');

        $this->putJson("/api/crm/products/{$f['crm']}", ['productMasterId' => $eb], $this->headers($this->h()))->assertOk();

        $this->assertSame($eb, (int) $this->masterOfOffer($f['offer'])->parent_id);
        $this->assertSame('EB', DB::table('g2g_product_offers')->where('id', $f['offer'])->value('parent_product'), 'the Portfolio shows it under the new Product too');
        $this->assertSame('enterprise_brain/p9', $this->masterOfOffer($f['offer'])->product_key, 'and the key follows the Product, as the Portfolio door always did');
        $this->assertChecker('after the item moved to another Product');
    }

    // ================================================================== D7: the mirror, called directly

    public function test_one_call_writes_the_name_and_the_product_to_the_master_row_the_offer_and_the_item(): void
    {
        $f = $this->soldSubProduct();
        $eb = $this->sharedId('enterprise_brain');

        $written = (new ProductMasterSync)->mirror(self::T, $f['master'], ['name' => 'Scholar Nine Pro', 'parent_id' => $eb]);

        $this->assertEqualsCanonicalizing(['g2g_product_master', 'g2g_product_offers', 'crm_products'], $written, 'all three copies were written');
        $this->assertSame([
            'master' => ['Scholar Nine Pro', $eb, 'enterprise_brain/p9'],
            'offer' => ['Scholar Nine Pro', 'EB'],
            'crm' => ['Scholar Nine Pro', $eb],
        ], $this->copies($f));
        $this->assertChecker('after a mirrored rename and move');
    }

    public function test_a_name_alone_or_a_product_alone_changes_only_that(): void
    {
        $f = $this->soldSubProduct();
        $eb = $this->sharedId('enterprise_brain');
        $sync = new ProductMasterSync;

        $sync->mirror(self::T, $f['master'], ['name' => 'Renamed only']);
        $this->assertSame(['Renamed only', $f['product'], 'scholarclone/p9'], $this->copies($f)['master'], 'the product was not named, so it did not move');
        $this->assertSame(['Renamed only', $f['product']], $this->copies($f)['crm']);

        $sync->mirror(self::T, $f['master'], ['parent_id' => $eb]);
        $this->assertSame(['Renamed only', $eb, 'enterprise_brain/p9'], $this->copies($f)['master'], 'the name was not named, so it stayed');
        $this->assertSame(['Renamed only', 'EB'], $this->copies($f)['offer']);
    }

    public function test_a_row_that_holds_only_one_pointer_changes_only_what_exists(): void
    {
        // A catalogue-only item: its master row and its item. A Portfolio offer's row with no item: its master row and its offer.
        $item = $this->crmCreate('Training day', 'service');
        $itemMaster = (int) $this->masterOfCrm($item)->id;
        $offer = $this->offerCreate('P7', 'Scholar Seven');
        $offerMaster = (int) $this->masterOfOffer($offer)->id;
        $sync = new ProductMasterSync;

        $this->assertEqualsCanonicalizing(['g2g_product_master', 'crm_products'], $sync->mirror(self::T, $itemMaster, ['name' => 'Training week']), 'an item has no offer to write');
        $this->assertSame('Training week', DB::table('crm_products')->where('id', $item)->value('name'));
        $this->assertSame('Scholar Seven', DB::table('g2g_product_offers')->where('id', $offer)->value('name'), 'the offer next door is a different record');

        $this->assertEqualsCanonicalizing(['g2g_product_master', 'g2g_product_offers'], $sync->mirror(self::T, $offerMaster, ['name' => 'Scholar Seven Plus']), 'an offer with no item has no item to write');
        $this->assertSame('Scholar Seven Plus', DB::table('g2g_product_offers')->where('id', $offer)->value('name'));
        $this->assertSame('Training week', DB::table('crm_products')->where('id', $item)->value('name'), 'and the item next door is a different record');
        $this->assertChecker('after a mirror on rows that hold one pointer');
    }

    public function test_a_different_record_with_the_same_name_is_never_renamed(): void
    {
        // Three more records with exactly the name of the one that is renamed: an offer, a catalogue-only item and another sold Sub-product.
        $f = $this->soldSubProduct('P9', 'Scholar Nine');
        $twinOffer = $this->offerCreate('P8', 'Scholar Nine');
        $twinItem = $this->crmCreate('Scholar Nine');
        $twinSold = $this->soldSubProduct('P7', 'Scholar Nine');
        $before = [
            'offer' => DB::table('g2g_product_offers')->where('id', $twinOffer)->first(),
            'item' => DB::table('crm_products')->where('id', $twinItem)->first(),
            'master' => DB::table('g2g_product_master')->whereIn('id', [(int) $this->masterOfOffer($twinOffer)->id, (int) $this->masterOfCrm($twinItem)->id, $twinSold['master']])->orderBy('id')->get(),
            'soldOffer' => DB::table('g2g_product_offers')->where('id', $twinSold['offer'])->first(),
            'soldItem' => DB::table('crm_products')->where('id', $twinSold['crm'])->first(),
        ];

        (new ProductMasterSync)->mirror(self::T, $f['master'], ['name' => 'Scholar Nine Pro']);

        $this->assertEquals($before['offer'], DB::table('g2g_product_offers')->where('id', $twinOffer)->first(), 'an offer of the same name is another record');
        $this->assertEquals($before['item'], DB::table('crm_products')->where('id', $twinItem)->first(), 'an item of the same name is another record');
        $this->assertEquals($before['master'], DB::table('g2g_product_master')->whereIn('id', [(int) $this->masterOfOffer($twinOffer)->id, (int) $this->masterOfCrm($twinItem)->id, $twinSold['master']])->orderBy('id')->get());
        $this->assertEquals($before['soldOffer'], DB::table('g2g_product_offers')->where('id', $twinSold['offer'])->first());
        $this->assertEquals($before['soldItem'], DB::table('crm_products')->where('id', $twinSold['crm'])->first());
        $this->assertSame('Scholar Nine Pro', $this->copies($f)['offer'][0], 'while its own three copies did change');
    }

    public function test_when_every_copy_already_agrees_nothing_is_written_at_all(): void
    {
        $f = $this->soldSubProduct();
        $before = $this->copies($f);
        $stamps = [
            DB::table('g2g_product_master')->where('id', $f['master'])->value('updated_at'),
            DB::table('g2g_product_offers')->where('id', $f['offer'])->value('updated_at'),
            DB::table('crm_products')->where('id', $f['crm'])->value('updated_at'),
        ];

        $written = null;
        $log = $this->recordWrites(function () use ($f, &$written) {
            $written = (new ProductMasterSync)->mirror(self::T, $f['master'], ['name' => 'Scholar Nine', 'parent_id' => $f['product']]);
        });

        $this->assertSame([], $written, 'nothing differed, so nothing was written');
        $this->assertSame([], array_values(array_filter($log, fn (string $s) => str_starts_with($s, 'UPDATE') || str_starts_with($s, 'INSERT') || str_starts_with($s, 'DELETE'))), 'not one writing statement was sent');
        $this->assertSame($before, $this->copies($f));
        $this->assertSame($stamps, [
            DB::table('g2g_product_master')->where('id', $f['master'])->value('updated_at'),
            DB::table('g2g_product_offers')->where('id', $f['offer'])->value('updated_at'),
            DB::table('crm_products')->where('id', $f['crm'])->value('updated_at'),
        ], 'not even an updated_at moved');
    }

    public function test_a_dry_run_says_which_copies_it_would_write_and_writes_none(): void
    {
        $f = $this->soldSubProduct();
        $before = $this->copies($f);

        $log = $this->recordWrites(function () use ($f, &$would) {
            $would = (new ProductMasterSync(null, false))->mirror(self::T, $f['master'], ['name' => 'Scholar Nine Pro', 'parent_id' => $this->sharedId('enterprise_brain')]);
        });

        $this->assertEqualsCanonicalizing(['g2g_product_master', 'g2g_product_offers', 'crm_products'], $would);
        $this->assertSame([], $log, 'a dry run sends no writing statement and opens no transaction');
        $this->assertSame($before, $this->copies($f));
    }

    public function test_the_three_writes_are_one_transaction_and_a_single_commit(): void
    {
        $f = $this->soldSubProduct();

        $log = $this->recordWrites(fn () => (new ProductMasterSync)->mirror(self::T, $f['master'], ['name' => 'Scholar Nine Pro', 'parent_id' => $this->sharedId('enterprise_brain')]));

        $this->assertSame(['BEGIN', 'UPDATE g2g_product_master', 'UPDATE g2g_product_offers', 'UPDATE crm_products', 'COMMIT'], $log, 'one begin, the three writes, one commit: no reader can see them differ');
    }

    /** @return iterable<string, array{0: string}> the copy whose write fails */
    public static function theCopyThatFails(): iterable
    {
        yield 'the master row' => ['g2g_product_master'];
        yield 'the Portfolio offer' => ['g2g_product_offers'];
        yield 'the catalogue item' => ['crm_products'];
    }

    #[DataProvider('theCopyThatFails')]
    public function test_a_rename_that_fails_half_way_leaves_all_three_copies_as_they_were(string $failing): void
    {
        $f = $this->soldSubProduct();
        $before = $this->copies($f);
        $beforeStatus = DB::table('g2g_product_master')->where('id', $f['master'])->value('status');
        $this->failUpdatesOf($failing);

        try {
            // The master-only column rides in the same statement as the name: it must not survive a failure either.
            (new ProductMasterSync)->mirror(self::T, $f['master'], ['name' => 'Scholar Nine Pro', 'parent_id' => $this->sharedId('enterprise_brain')], ['status' => ProductMaster::STATUS_ARCHIVED]);
            $this->fail('the failure of one copy must reach the caller: nothing is swallowed on this path');
        } catch (\Throwable $e) {
            $this->assertStringContainsString("the {$failing} write failed", $e->getMessage());
        }

        $this->assertSame($before, $this->copies($f), 'the copies written before the failure were taken back');
        $this->assertSame($beforeStatus, DB::table('g2g_product_master')->where('id', $f['master'])->value('status'));
    }

    public function test_a_failure_leaves_a_rolled_back_transaction_in_the_log_not_a_commit(): void
    {
        $f = $this->soldSubProduct();
        $this->failUpdatesOf('crm_products');
        $events = [];
        Event::listen(TransactionCommitted::class, function () use (&$events) {
            $events[] = 'COMMIT';
        });
        Event::listen(TransactionRolledBack::class, function () use (&$events) {
            $events[] = 'ROLLBACK';
        });

        try {
            (new ProductMasterSync)->mirror(self::T, $f['master'], ['name' => 'Scholar Nine Pro']);
            $this->fail('expected the failure to reach the caller');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(['ROLLBACK'], $events);
    }

    // ---- the two doors do not swallow, the legacy entry points still answer as before ----

    /**
     * Puts the three copies back to one agreed state and takes the failure triggers away, so each half of the next test starts from a world in which the
     * only difference is the one it makes.
     *
     * @param array{offer: int, crm: int, master: int, product: int} $f
     */
    private function agreeAgain(array $f): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS fail_g2g_product_offers');
        DB::unprepared('DROP TRIGGER IF EXISTS fail_crm_products');
        DB::unprepared('DROP TRIGGER IF EXISTS fail_g2g_product_master');
        DB::table('g2g_product_master')->where('id', $f['master'])->update(['name' => 'Scholar Nine']);
        DB::table('g2g_product_offers')->where('id', $f['offer'])->update(['name' => 'Scholar Nine']);
        DB::table('crm_products')->where('id', $f['crm'])->update(['name' => 'Scholar Nine']);
    }

    public function test_the_crm_door_and_the_portfolio_door_let_a_failure_through(): void
    {
        $f = $this->soldSubProduct();

        // The CRM door wrote its own copy (a new name) and the mirror is to carry it to the other two; the offer's write fails.
        DB::table('crm_products')->where('id', $f['crm'])->update(['name' => 'Renamed in the CRM']);
        $this->failUpdatesOf('g2g_product_offers');
        try {
            (new ProductMasterSync)->catalogueItem(self::T, $f['crm']);
            $this->fail('catalogueItem() must not swallow a failure of the mirror');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('g2g_product_offers write failed', $e->getMessage());
        }
        $this->assertSame('Scholar Nine', DB::table('g2g_product_master')->where('id', $f['master'])->value('name'), 'the master row was not half written');

        // And the Portfolio door, with the item's write failing.
        $this->agreeAgain($f);
        DB::table('g2g_product_offers')->where('id', $f['offer'])->update(['name' => 'Renamed in the Portfolio']);
        $this->failUpdatesOf('crm_products');
        try {
            (new ProductMasterSync)->offer(self::T, $f['offer']);
            $this->fail('offer() must not swallow a failure of the mirror');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('crm_products write failed', $e->getMessage());
        }
        $this->assertSame('Scholar Nine', DB::table('g2g_product_master')->where('id', $f['master'])->value('name'));
    }

    public function test_the_legacy_entry_points_still_answer_as_before_and_only_log_the_failure(): void
    {
        $f = $this->soldSubProduct();

        // Through the guarded entry points (what every legacy endpoint calls) the same two failures are logged and never raised.
        DB::table('crm_products')->where('id', $f['crm'])->update(['name' => 'Renamed in the CRM']);
        $this->failUpdatesOf('g2g_product_offers');
        Log::spy();
        ProductMasterSync::throughCatalogueItems(self::T, [$f['crm']]);
        Log::shouldHaveReceived('warning')->once();
        $this->assertSame('Scholar Nine', DB::table('g2g_product_master')->where('id', $f['master'])->value('name'), 'and the mirror took its own writes back');

        $this->agreeAgain($f);
        DB::table('g2g_product_offers')->where('id', $f['offer'])->update(['name' => 'Renamed in the Portfolio']);
        $this->failUpdatesOf('crm_products');
        ProductMasterSync::throughOffer(self::T, $f['offer']);
        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_a_host_without_the_master_table_or_the_other_stores_is_skipped_not_failed(): void
    {
        $f = $this->soldSubProduct();
        \Illuminate\Support\Facades\Schema::drop('g2g_product_offers');

        // The offer table is not on this host: its copy cannot exist, and the other two are still mirrored.
        $written = (new ProductMasterSync)->mirror(self::T, $f['master'], ['name' => 'Scholar Nine Pro']);
        $this->assertEqualsCanonicalizing(['g2g_product_master', 'crm_products'], $written);

        \Illuminate\Support\Facades\Schema::drop('g2g_product_master');
        $this->assertSame([], (new ProductMasterSync)->mirror(self::T, $f['master'], ['name' => 'Again']), 'no master table: nothing to mirror, and no error');
    }

    // ---- what the mirror refuses, and what it leaves alone ----

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusedIdentities(): iterable
    {
        yield 'a blank name' => [['name' => '   '], 'name'];
        yield 'no product for a Sub-product' => [['parent_id' => null], 'parent_id'];
        yield 'a product that does not exist' => [['parent_id' => 999999], 'parent_id'];
    }

    /** @param array<string, mixed> $identity */
    #[DataProvider('refusedIdentities')]
    public function test_a_change_that_cannot_be_made_to_every_copy_is_refused_and_writes_to_none(array $identity, string $field): void
    {
        $f = $this->soldSubProduct();
        $before = $this->copies($f);

        try {
            (new ProductMasterSync)->mirror(self::T, $f['master'], $identity + ['name' => 'Would be a rename']);
            $this->fail('expected a refusal');
        } catch (ProductMasterInvalid $e) {
            $this->assertArrayHasKey($field, $e->errors);
        }

        $this->assertSame($before, $this->copies($f), 'not one copy took the name that the others could not');
    }

    public function test_a_sub_product_is_never_put_under_a_sub_product_or_under_another_organisations_product(): void
    {
        $f = $this->soldSubProduct();
        $other = $this->soldSubProduct('Q1', 'Their one', 'Scholar K-12', 'scholarclone', self::T2);
        $theirsOwn = (int) DB::table('g2g_product_master')->insertGetId(['sub_institute_id' => self::T2, 'product_key' => 'theirs', 'kind' => 'product', 'name' => 'Theirs', 'status' => 'active', 'origin' => 'custom', 'portfolio_name' => 'Theirs']);
        $before = $this->copies($f);

        foreach (['a Sub-product' => $other['master'], "another organisation's Product" => $theirsOwn, 'an item' => (int) $this->masterOfCrm($this->crmCreate('Loose item'))->id] as $what => $badParent) {
            try {
                (new ProductMasterSync)->mirror(self::T, $f['master'], ['parent_id' => $badParent]);
                $this->fail("{$what} cannot be the Product of a Sub-product");
            } catch (ProductMasterInvalid $e) {
                $this->assertArrayHasKey('parent_id', $e->errors);
            }
        }
        $this->assertSame($before, $this->copies($f));
    }

    public function test_a_product_without_a_portfolio_name_cannot_hold_a_sub_product_that_has_an_offer(): void
    {
        $f = $this->soldSubProduct();
        $nameless = (int) DB::table('g2g_product_master')->insertGetId(['sub_institute_id' => self::T, 'product_key' => 'nameless', 'kind' => 'product', 'name' => 'Nameless', 'status' => 'active', 'origin' => 'custom', 'portfolio_name' => null]);
        $before = $this->copies($f);

        try {
            (new ProductMasterSync)->mirror(self::T, $f['master'], ['parent_id' => $nameless]);
            $this->fail('the offer needs a group name to sit under, and this product has none');
        } catch (ProductMasterInvalid $e) {
            $this->assertArrayHasKey('parent_id', $e->errors);
        }
        $this->assertSame($before, $this->copies($f), 'the offer could not follow, so nothing moved');
    }

    public function test_an_unknown_row_and_an_unknown_identity_key_are_refused(): void
    {
        $f = $this->soldSubProduct();

        try {
            (new ProductMasterSync)->mirror(self::T, 424242, ['name' => 'Nobody']);
            $this->fail('a row that is not there');
        } catch (ProductMasterInvalid $e) {
            $this->assertSame(404, $e->status);
        }
        try {
            (new ProductMasterSync)->mirror(self::T2, $f['master'], ['name' => 'Not yours']);
            $this->fail("another organisation's row");
        } catch (ProductMasterInvalid $e) {
            $this->assertSame(404, $e->status);
        }
        $this->assertSame('Scholar Nine', DB::table('g2g_product_master')->where('id', $f['master'])->value('name'));

        $this->expectException(\InvalidArgumentException::class);
        (new ProductMasterSync)->mirror(self::T, $f['master'], ['name' => 'X', 'price' => 5]);
    }

    public function test_another_organisations_offer_or_item_is_never_written_even_if_a_master_row_points_at_it(): void
    {
        $f = $this->soldSubProduct();
        $theirs = $this->soldSubProduct('Q1', 'Their one', 'Scholar K-12', 'scholarclone', self::T2);
        // A damaged row of organisation 1 that points at organisation 2's offer and item (the checker calls this I2).
        DB::table('g2g_product_master')->where('id', $f['master'])->update(['offer_row_id' => $theirs['offer'], 'crm_product_id' => $theirs['crm']]);
        $theirsBefore = [DB::table('g2g_product_offers')->where('id', $theirs['offer'])->first(), DB::table('crm_products')->where('id', $theirs['crm'])->first()];

        $written = (new ProductMasterSync)->mirror(self::T, $f['master'], ['name' => 'Renamed by organisation 1', 'parent_id' => $this->sharedId('enterprise_brain')]);

        $this->assertSame(['g2g_product_master'], $written, 'only the row of its own organisation');
        $this->assertEquals($theirsBefore, [DB::table('g2g_product_offers')->where('id', $theirs['offer'])->first(), DB::table('crm_products')->where('id', $theirs['crm'])->first()]);
    }

    public function test_an_item_in_the_recycle_bin_is_renamed_with_the_rest_so_a_restore_does_not_bring_back_an_old_name(): void
    {
        $f = $this->soldSubProduct();
        $h = $this->headers($this->h());
        $this->deleteJson("/api/crm/products/{$f['crm']}", [], $h)->assertOk();

        (new ProductMasterSync)->mirror(self::T, $f['master'], ['name' => 'Scholar Nine Pro']);

        $this->assertSame('Scholar Nine Pro', DB::table('crm_products')->where('id', $f['crm'])->value('name'), 'the binned copy is a copy that exists');
        $this->postJson("/api/crm/recycle-bin/products/{$f['crm']}/restore", [], $h)->assertOk();
        $this->assertSame('Scholar Nine Pro', DB::table('g2g_product_offers')->where('id', $f['offer'])->value('name'), 'the restore did not turn the other copies back');
        $this->assertChecker('after a restore');
    }

    public function test_the_key_follows_the_product_when_it_is_free_and_stays_when_another_row_holds_it(): void
    {
        $f = $this->soldSubProduct();
        $eb = $this->sharedId('enterprise_brain');
        // Another Sub-product of this organisation already uses the key the move would give.
        DB::table('g2g_product_master')->insert(['sub_institute_id' => self::T, 'product_key' => 'enterprise_brain/p9', 'kind' => 'sub_product', 'name' => 'Squatter', 'status' => 'active', 'origin' => 'manual']);

        (new ProductMasterSync)->mirror(self::T, $f['master'], ['parent_id' => $eb]);

        $row = DB::table('g2g_product_master')->where('id', $f['master'])->first();
        $this->assertSame($eb, (int) $row->parent_id, 'the move happened');
        $this->assertSame('scholarclone/p9', $row->product_key, 'the key it had is kept when the new one is taken');
    }

    public function test_the_actor_is_recorded_on_every_copy_that_was_written(): void
    {
        $f = $this->soldSubProduct();

        (new ProductMasterSync(null, true, 77))->mirror(self::T, $f['master'], ['name' => 'Scholar Nine Pro']);

        $this->assertSame(77, (int) DB::table('g2g_product_master')->where('id', $f['master'])->value('updated_by'));
        $this->assertSame(77, (int) DB::table('g2g_product_offers')->where('id', $f['offer'])->value('updated_by'));
        $this->assertSame(77, (int) DB::table('crm_products')->where('id', $f['crm'])->value('updated_by'));
    }

    public function test_the_master_row_is_read_with_a_lock_inside_the_transaction_so_two_renames_of_one_record_take_turns(): void
    {
        // sqlite has no row locks and drops the clause, so the grammar is told to say when one was asked for: a mutation that removes lockForUpdate() is then visible.
        $f = $this->soldSubProduct();
        $connection = DB::connection();
        $grammar = new class($connection) extends \Illuminate\Database\Query\Grammars\SQLiteGrammar {
            /** @var list<string> */
            public array $log = [];

            protected function compileLock(\Illuminate\Database\Query\Builder $query, $value)
            {
                $this->log[] = 'LOCK ' . $query->from;

                return '';
            }
        };
        $connection->setQueryGrammar($grammar);
        Event::listen(TransactionBeginning::class, function () use ($grammar) {
            $grammar->log[] = 'BEGIN';
        });
        Event::listen(TransactionCommitted::class, function () use ($grammar) {
            $grammar->log[] = 'COMMIT';
        });

        (new ProductMasterSync)->mirror(self::T, $f['master'], ['name' => 'Scholar Nine Pro']);
        $this->assertSame(['BEGIN', 'LOCK g2g_product_master', 'COMMIT'], $grammar->log, 'the master row is read FOR UPDATE, once, after the transaction began and before it ended');

        $grammar->log = [];
        (new ProductMasterSync(null, false))->mirror(self::T, $f['master'], ['name' => 'A dry run']);
        $this->assertSame([], $grammar->log, 'a dry run takes no lock and opens no transaction');
    }

    public function test_a_product_of_level_one_is_not_renamed_here_because_its_name_is_the_group_its_sub_products_sit_under(): void
    {
        $own = (int) DB::table('g2g_product_master')->insertGetId(['sub_institute_id' => self::T, 'product_key' => 'mine', 'kind' => 'product', 'name' => 'Mine', 'status' => 'active', 'origin' => 'custom', 'portfolio_name' => 'Mine']);

        try {
            (new ProductMasterSync)->mirror(self::T, $own, ['name' => 'Renamed']);
            $this->fail('a Product has copies in stores this method does not write, so it is refused rather than half done');
        } catch (ProductMasterInvalid $e) {
            $this->assertArrayHasKey('kind', $e->errors);
        }
        $this->assertSame('Mine', DB::table('g2g_product_master')->where('id', $own)->value('name'));
    }

    public function test_a_new_offer_that_takes_over_an_archived_row_carries_its_name_and_product_to_the_item_the_row_holds(): void
    {
        // An earlier delete archived the row of offer P9 (its offer pointer cleared). A catalogue item is still attached to that row (made by hand: the API would
        // have turned the row into an item). The offer is made again: the row is taken over, and the item follows it, in one transaction.
        $offer = $this->offerCreate('P9', 'Scholar Nine');
        $masterId = (int) $this->masterOfOffer($offer)->id;
        $this->deleteJson("/api/portfolio/offers/{$offer}", [], $this->headers($this->h()))->assertOk();
        $item = (int) DB::table('crm_products')->insertGetId(['sub_institute_id' => self::T, 'product_no' => 'PRD-OLD1', 'item_type' => 'product', 'name' => 'Old catalogue name', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('g2g_product_master')->where('id', $masterId)->update(['crm_product_id' => $item]);
        $again = (int) DB::table('g2g_product_offers')->insertGetId(['sub_institute_id' => self::T, 'offer_id' => 'P9', 'name' => 'Scholar Nine again', 'parent_product' => 'Scholar K-12', 'readiness_status' => 'Prototype']);
        $this->assertSame('archived', DB::table('g2g_product_master')->where('id', $masterId)->value('status'), 'the row is archived before the take-over');

        // A failure while taking it over reaches the caller, and the row is not taken over.
        $this->failUpdatesOf('crm_products');
        try {
            (new ProductMasterSync)->offer(self::T, $again);
            $this->fail('the take-over must not swallow a failure of the mirror');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('crm_products write failed', $e->getMessage());
        }
        $row = DB::table('g2g_product_master')->where('id', $masterId)->first();
        $this->assertSame('archived', $row->status, 'the failure took the take-over back');
        $this->assertNull($row->offer_row_id);

        DB::unprepared('DROP TRIGGER fail_crm_products');
        $this->assertSame(ProductMasterSync::ACT_REVIVED, (new ProductMasterSync)->offer(self::T, $again));
        $row = DB::table('g2g_product_master')->where('id', $masterId)->first();
        $this->assertSame(['active', $again, 'Scholar Nine again', $this->sharedId('scholarclone')], [$row->status, (int) $row->offer_row_id, $row->name, (int) $row->parent_id]);
        $this->assertSame(['Scholar Nine again', $this->sharedId('scholarclone')], [DB::table('crm_products')->where('id', $item)->value('name'), (int) DB::table('crm_products')->where('id', $item)->value('product_master_id')], 'and the item it holds took the offer\'s name and Product');
    }

    public function test_a_door_says_it_updated_a_record_when_only_the_other_copies_needed_writing(): void
    {
        $f = $this->soldSubProduct();
        $sync = new ProductMasterSync;

        // The CRM door: the item and the master row already agree on a new name; only the offer differs.
        DB::table('crm_products')->where('id', $f['crm'])->update(['name' => 'Renamed once']);
        DB::table('g2g_product_master')->where('id', $f['master'])->update(['name' => 'Renamed once']);
        $this->assertSame(ProductMasterSync::ACT_UPDATED, $sync->catalogueItem(self::T, $f['crm']));
        $this->assertSame('Renamed once', DB::table('g2g_product_offers')->where('id', $f['offer'])->value('name'));
        $this->assertSame(ProductMasterSync::ACT_UNCHANGED, $sync->catalogueItem(self::T, $f['crm']), 'the second time there is nothing left to do');

        // The Portfolio door: the offer and the master row agree on a new name; only the item differs.
        DB::table('g2g_product_offers')->where('id', $f['offer'])->update(['name' => 'Renamed twice']);
        DB::table('g2g_product_master')->where('id', $f['master'])->update(['name' => 'Renamed twice']);
        $this->assertSame(ProductMasterSync::ACT_UPDATED, $sync->offer(self::T, $f['offer']));
        $this->assertSame('Renamed twice', DB::table('crm_products')->where('id', $f['crm'])->value('name'));
        $this->assertSame(ProductMasterSync::ACT_UNCHANGED, $sync->offer(self::T, $f['offer']));
    }

    // ---- the small guards, each with a test that can fail ----

    public function test_organisation_zero_is_the_shared_rows_and_is_never_a_place_to_write(): void
    {
        $f = $this->soldSubProduct();
        $before = $this->copies($f);

        $this->assertSame([], (new ProductMasterSync)->mirror(0, $f['master'], ['name' => 'Renamed as nobody']), 'no organisation, no write, and no error');
        $this->assertSame($before, $this->copies($f));
    }

    public function test_a_group_that_differs_only_in_letter_case_is_the_same_group_and_is_not_rewritten(): void
    {
        $f = $this->soldSubProduct('P9', 'Scholar Nine', 'EB', 'enterprise_brain');
        DB::table('g2g_product_offers')->where('id', $f['offer'])->update(['parent_product' => 'eb']);

        $written = (new ProductMasterSync)->mirror(self::T, $f['master'], ['parent_id' => $f['product']]);

        $this->assertSame([], $written, 'the checker compares groups without regard to case, so the mirror does too');
        $this->assertSame('eb', DB::table('g2g_product_offers')->where('id', $f['offer'])->value('parent_product'));
    }

    public function test_a_master_only_column_is_written_only_when_it_differs(): void
    {
        $f = $this->soldSubProduct();
        $sync = new ProductMasterSync;

        $this->assertSame([], $sync->mirror(self::T, $f['master'], [], ['status' => ProductMaster::STATUS_ACTIVE]), 'it is active already');
        $this->assertSame(['g2g_product_master'], $sync->mirror(self::T, $f['master'], [], ['status' => ProductMaster::STATUS_ARCHIVED]));
        $this->assertSame('archived', DB::table('g2g_product_master')->where('id', $f['master'])->value('status'));
        $this->assertSame(['g2g_product_master'], $sync->mirror(self::T, $f['master'], ['name' => 'Scholar Nine'], ['status' => ProductMaster::STATUS_ACTIVE]), 'a master-only change alone is still a write of the master row, and nothing else');
    }

    public function test_a_table_with_no_updated_by_column_is_stamped_without_it_and_nothing_fails(): void
    {
        $f = $this->soldSubProduct();
        \Illuminate\Support\Facades\Schema::table('crm_products', fn ($t) => $t->dropColumn('updated_by'));
        \Illuminate\Support\Facades\Schema::table('g2g_product_offers', fn ($t) => $t->dropColumn('updated_by'));

        $written = (new ProductMasterSync(null, true, 77))->mirror(self::T, $f['master'], ['name' => 'Scholar Nine Pro']);

        $this->assertEqualsCanonicalizing(['g2g_product_master', 'g2g_product_offers', 'crm_products'], $written);
        $this->assertSame(77, (int) DB::table('g2g_product_master')->where('id', $f['master'])->value('updated_by'), 'the table that has the column still says who');
    }

    public function test_a_catalogue_table_with_no_product_line_column_takes_the_name_and_skips_the_product(): void
    {
        $f = $this->soldSubProduct();
        $eb = $this->sharedId('enterprise_brain');
        \Illuminate\Support\Facades\Schema::table('crm_products', fn ($t) => $t->dropColumn('product_master_id'));

        $written = (new ProductMasterSync)->mirror(self::T, $f['master'], ['name' => 'Scholar Nine Pro', 'parent_id' => $eb]);

        $this->assertEqualsCanonicalizing(['g2g_product_master', 'g2g_product_offers', 'crm_products'], $written, 'the item still takes the name');
        $this->assertSame($eb, (int) DB::table('g2g_product_master')->where('id', $f['master'])->value('parent_id'));
        $this->assertSame('EB', DB::table('g2g_product_offers')->where('id', $f['offer'])->value('parent_product'));
    }

    public function test_the_repair_pass_reports_a_row_it_cannot_make_agree_and_goes_on_with_the_others(): void
    {
        // One Sub-product whose catalogue item names a "product" that is really a Sub-product (the CRM door cannot accept that), and one offer renamed behind our back.
        $bad = $this->soldSubProduct('P9', 'Scholar Nine');
        $junk = (int) $this->masterOfOffer($this->offerCreate('P5', 'Scholar Five'))->id;
        DB::table('crm_products')->where('id', $bad['crm'])->update(['product_master_id' => $junk]);
        $good = $this->offerCreate('P8', 'Scholar Eight');
        DB::table('g2g_product_offers')->where('id', $good)->update(['name' => 'Scholar Eight (renamed behind our back)']);

        $this->artisan('product-master:repair', ['--tenant' => self::T, '--apply' => true])
            ->expectsOutputToContain("catalogue item {$bad['crm']}: Choose one of your products., so it was skipped")
            ->assertExitCode(0);

        $this->assertSame('Scholar Eight (renamed behind our back)', $this->masterOfOffer($good)->name, 'the pass went on and repaired the next row');
        $this->assertSame($bad['product'], (int) DB::table('crm_products')->where('id', $bad['crm'])->value('product_master_id'), 'and the Portfolio door put the right Product on the item that the CRM door could not accept');
    }

    // ================================================================== D7: through the two real doors

    public function test_the_crm_door_carries_a_rename_to_the_offer_and_the_master_row(): void
    {
        $f = $this->soldSubProduct();

        $this->putJson("/api/crm/products/{$f['crm']}", ['name' => 'Scholar Nine (CRM)'], $this->headers($this->h()))->assertOk();

        $this->assertSame(['Scholar Nine (CRM)', 'Scholar Nine (CRM)', 'Scholar Nine (CRM)'], [
            DB::table('g2g_product_master')->where('id', $f['master'])->value('name'),
            DB::table('g2g_product_offers')->where('id', $f['offer'])->value('name'),
            DB::table('crm_products')->where('id', $f['crm'])->value('name'),
        ]);
        $this->assertChecker('after a rename from the CRM door');
    }

    public function test_the_portfolio_door_carries_a_rename_and_a_move_to_the_item_and_the_master_row(): void
    {
        $f = $this->soldSubProduct();
        $eb = $this->sharedId('enterprise_brain');
        $h = $this->headers($this->h());

        $this->putJson("/api/portfolio/offers/{$f['offer']}", ['name' => 'Scholar Nine (Portfolio)'], $h)->assertOk();
        $this->assertSame(['Scholar Nine (Portfolio)', 'Scholar Nine (Portfolio)', 'Scholar Nine (Portfolio)'], [
            DB::table('g2g_product_master')->where('id', $f['master'])->value('name'),
            DB::table('g2g_product_offers')->where('id', $f['offer'])->value('name'),
            DB::table('crm_products')->where('id', $f['crm'])->value('name'),
        ]);
        $this->assertChecker('after a rename from the Portfolio door');

        $this->putJson("/api/portfolio/offers/{$f['offer']}", ['parent_product' => 'EB'], $h)->assertOk();
        $this->assertSame($eb, (int) DB::table('g2g_product_master')->where('id', $f['master'])->value('parent_id'));
        $this->assertSame($eb, (int) DB::table('crm_products')->where('id', $f['crm'])->value('product_master_id'), 'the item sits under the new Product too');
        $this->assertChecker('after a move from the Portfolio door');
    }

    public function test_a_catalogue_only_item_is_renamed_alone_by_the_crm_door(): void
    {
        $f = $this->soldSubProduct('P9', 'Starter kit');
        $loose = $this->crmCreate('Starter kit');
        $looseMaster = (int) $this->masterOfCrm($loose)->id;
        $offerBefore = DB::table('g2g_product_offers')->where('id', $f['offer'])->first();

        $this->putJson("/api/crm/products/{$loose}", ['name' => 'Starter kit 2'], $this->headers($this->h()))->assertOk();

        $this->assertSame('Starter kit 2', DB::table('g2g_product_master')->where('id', $looseMaster)->value('name'));
        $this->assertEquals($offerBefore, DB::table('g2g_product_offers')->where('id', $f['offer'])->first(), 'the Sub-product with the same name is another record');
        $this->assertSame('Starter kit', DB::table('crm_products')->where('id', $f['crm'])->value('name'));
        $this->assertChecker('after a rename of a catalogue-only item');
    }
}
