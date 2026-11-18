<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\ProductMaster;
use App\Domain\ProductMaster\ProductMasterBackfill;
use App\Domain\ProductMaster\ProductResolver;
use App\Domain\Signals\Products\ProductCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Slice 1 of the agentic CRM: the Product Master table, its resolver and its backfill (Docs/agentic-crm/slices/S01-product-master.md).
 * The table comes from the real migration files run on the in-memory sqlite database; the Product Portfolio offers and the CRM catalogue are
 * minimal fixtures with only the columns the backfill reads.
 */
class ProductMasterTest extends TestCase
{
    private const T = 1;

    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
        ProductCatalog::forgetCustom();
        foreach (['2026_12_07_090000_create_g2g_product_master_table', '2026_12_07_090100_ensure_g2g_signal_products_table'] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        Schema::create('g2g_product_offers', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('offer_id', 50);
            $t->string('name', 191);
            $t->string('parent_product', 100)->nullable();
        });
        Schema::create('crm_products', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('product_no', 100)->nullable();
            $t->string('name', 191);
            $t->timestamp('deleted_at')->nullable();
        });
    }

    /** The data the live Product Portfolio has in outline: three ID schemes, a product-level offer named EB, a duplicate id and an orphan. */
    private function seedPortfolio(): void
    {
        $offer = fn (?int $t, string $id, string $name, ?string $parent) => DB::table('g2g_product_offers')->insert(['sub_institute_id' => $t, 'offer_id' => $id, 'name' => $name, 'parent_product' => $parent]);
        $offer(self::T, 'P1', 'Scholar One', 'Scholar K-12');
        $offer(self::T, 'P2', 'Scholar Core', 'Scholar K-12');
        $offer(self::T, 'P2', 'Scholar Core duplicate', 'Scholar K-12');
        $offer(self::T, 'EB', 'AI-powered Enterprise Intelligence Platform', 'EB');
        $offer(self::T, 'BUN-01', 'SME Capability + Intelligence Bundle', 'Bundle');
        $offer(self::T, 'HE-01', 'Higher Education ERP', 'HE');
        $offer(self::T, 'G2G-01', 'G2G Core', 'G2G');
        $offer(self::T, 'X-1', 'Orphan', 'No Such Product');
        $offer(self::T2, 'P1', 'Another organisation\'s Scholar One', 'Scholar K-12');
        $offer(null, 'SHARED-1', 'A shared offer', 'G2G');
    }

    private function ownProduct(): void
    {
        DB::table('g2g_signal_products')->insert([
            'sub_institute_id' => self::T, 'key' => 'payroll', 'label' => 'Payroll', 'business_fit' => 'payroll', 'icp_product_line' => 'payroll',
            'portfolio_names' => json_encode(['Payroll']), 'status' => 'active',
        ]);
        DB::table('g2g_product_offers')->insert(['sub_institute_id' => self::T, 'offer_id' => 'PAY-1', 'name' => 'Payroll Core', 'parent_product' => 'Payroll']);
    }

    private function apply(int $tenant = self::T): array
    {
        return (new ProductMasterBackfill)->run($tenant, true);
    }

    // ---- the table ----

    public function test_the_migrations_create_the_tables_and_are_safe_to_run_twice(): void
    {
        foreach (['2026_12_07_090000_create_g2g_product_master_table', '2026_12_07_090100_ensure_g2g_signal_products_table'] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        $this->assertTrue(Schema::hasTable('g2g_product_master'));
        foreach (['sub_institute_id', 'product_key', 'kind', 'parent_id', 'name', 'status', 'origin', 'signal_key', 'portfolio_name', 'offer_row_id', 'crm_product_id', 'created_by', 'updated_by', 'created_at', 'updated_at'] as $c) {
            $this->assertTrue(Schema::hasColumn('g2g_product_master', $c), "missing column {$c}");
        }
        $this->assertTrue(Schema::hasTable('g2g_signal_products'));
    }

    public function test_the_unique_keys_are_per_organisation_and_allow_many_empty_pointers(): void
    {
        $row = fn (array $o = []) => $o + ['sub_institute_id' => self::T, 'product_key' => 'k' . uniqid(), 'kind' => 'sub_product', 'name' => 'n', 'status' => 'active', 'origin' => 'manual'];
        DB::table('g2g_product_master')->insert($row(['product_key' => 'a', 'signal_key' => 'x', 'offer_row_id' => 5, 'crm_product_id' => 7]));

        // The same key, signal key, offer or catalogue pointer in ANOTHER organisation is fine.
        DB::table('g2g_product_master')->insert($row(['sub_institute_id' => self::T2, 'product_key' => 'a', 'signal_key' => 'x', 'offer_row_id' => 5, 'crm_product_id' => 7]));
        // Many rows with NULL signal key / offer / catalogue pointers in one organisation are fine.
        DB::table('g2g_product_master')->insert($row());
        DB::table('g2g_product_master')->insert($row());

        foreach ([['product_key' => 'a'], ['signal_key' => 'x'], ['offer_row_id' => 5], ['crm_product_id' => 7]] as $clash) {
            try {
                DB::table('g2g_product_master')->insert($row($clash));
                $this->fail('A duplicate within one organisation must be refused: ' . json_encode($clash));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- the backfill ----

    public function test_a_dry_run_reports_what_it_would_create_and_writes_nothing(): void
    {
        $this->seedPortfolio();
        $r = (new ProductMasterBackfill)->run(self::T, false);

        $this->assertFalse($r['apply']);
        $this->assertSame(0, DB::table('g2g_product_master')->count(), 'a dry run must not write');
        $this->assertSame(5, $r['created']['shared product'], 'Scholar K-12, Enterprise Brain, G2G, HE, Bundle');
        $this->assertSame(7, $r['created']['sub-product'], 'P1, P2, EB, BUN-01, HE-01, G2G-01 and the shared SHARED-1 (the duplicate P2 and the orphan are skipped)');
    }

    public function test_a_dry_run_works_before_the_table_exists_and_apply_refuses(): void
    {
        $this->seedPortfolio();
        Schema::drop('g2g_product_master');

        $r = (new ProductMasterBackfill)->run(self::T, false);
        $this->assertSame(5, $r['created']['shared product']);
        $this->assertSame(7, $r['created']['sub-product']);
        $this->assertStringContainsString('does not exist on this connection yet', implode("\n", $r['issues']));

        $this->expectException(\RuntimeException::class);
        (new ProductMasterBackfill)->run(self::T, true);
    }

    public function test_apply_creates_the_products_and_sub_products_under_the_right_parents(): void
    {
        $this->seedPortfolio();
        $this->ownProduct();
        $r = $this->apply();

        $this->assertTrue($r['apply']);
        $this->assertSame(5, $r['created']['shared product']);
        $this->assertSame(1, $r['created']['own product']);
        // P1, P2, EB, BUN-01, HE-01, G2G-01, PAY-1 for the organisation, plus SHARED-1 which belongs to nobody.
        $this->assertSame(8, $r['created']['sub-product']);

        $by = fn (int $t, string $key) => ProductMaster::where('sub_institute_id', $t)->where('product_key', $key)->first();
        $scholar = $by(0, 'scholarclone');
        $this->assertSame('product', $scholar->kind);
        $this->assertSame('scholar', $scholar->signal_key);
        $this->assertSame('Scholar K-12', $scholar->portfolio_name);

        foreach (['he', 'bundle'] as $k) {
            $p = $by(0, $k);
            $this->assertSame('product', $p->kind, "{$k} is a product of the Product Master even though Signals has no value for it");
            $this->assertNull($p->signal_key);
        }
        $this->assertSame($scholar->id, $by(self::T, 'scholarclone/p2')->parent_id);
        $this->assertSame($by(0, 'bundle')->id, $by(self::T, 'bundle/bun-01')->parent_id);
        $this->assertSame($by(0, 'he')->id, $by(self::T, 'he/he-01')->parent_id);
        $this->assertSame($by(self::T, 'payroll')->id, $by(self::T, 'payroll/pay-1')->parent_id, 'an own product is the parent of its own offers');
        $this->assertSame(0, $by(0, 'g2g/shared-1')->sub_institute_id, 'an offer that belongs to no organisation is shared');
        $this->assertSame('sub_product', $by(self::T, 'scholarclone/p1')->kind);
        $this->assertNotNull($by(self::T, 'scholarclone/p1')->offer_row_id);
    }

    public function test_problems_are_listed_not_guessed_away(): void
    {
        $this->seedPortfolio();
        $r = $this->apply();
        $issues = implode("\n", $r['issues']);

        $this->assertStringContainsString("no product named 'No Such Product'", $issues);
        $this->assertStringContainsString('offer id P2 appears more than once', $issues);
        $this->assertStringContainsString('offer EB has the same id as its product', $issues);
        $this->assertNull(ProductMaster::where('product_key', 'like', '%/x-1')->first(), 'an orphan offer is not given an invented parent');
        $this->assertSame(1, ProductMaster::where('sub_institute_id', self::T)->where('product_key', 'scholarclone/p2')->count(), 'the duplicate P2 was not added');
    }

    public function test_the_backfill_is_repeatable_and_the_second_run_changes_nothing(): void
    {
        $this->seedPortfolio();
        $this->ownProduct();
        $this->apply();
        $count = DB::table('g2g_product_master')->count();
        $snapshot = DB::table('g2g_product_master')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $second = $this->apply();

        $this->assertSame([], $second['created'], 'a second run creates nothing');
        $this->assertSame($count, DB::table('g2g_product_master')->count());
        $this->assertSame($snapshot, DB::table('g2g_product_master')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'and changes no existing row');
    }

    public function test_one_organisation_never_gets_another_organisations_offers(): void
    {
        $this->seedPortfolio();
        $this->apply(self::T);
        $this->apply(self::T2);

        $mine = ProductMaster::where('sub_institute_id', self::T2)->pluck('name')->all();
        $this->assertSame(["Another organisation's Scholar One"], $mine);
        $this->assertNotContains('Scholar Core', $mine);
    }

    public function test_catalogue_items_become_their_own_items_and_deleted_ones_are_skipped(): void
    {
        DB::table('crm_products')->insert([
            ['sub_institute_id' => self::T, 'product_no' => 'PRD-1', 'name' => 'Training day', 'deleted_at' => null],
            ['sub_institute_id' => self::T, 'product_no' => 'PRD-2', 'name' => 'Removed', 'deleted_at' => now()],
            ['sub_institute_id' => self::T2, 'product_no' => 'PRD-3', 'name' => 'Other organisation', 'deleted_at' => null],
        ]);
        $r = $this->apply();

        $this->assertSame(1, $r['created']['catalogue item']);
        $item = ProductMaster::where('kind', 'item')->first();
        $this->assertSame('Training day', $item->name);
        $this->assertSame('crm/prd-1', $item->product_key);
        $this->assertNull($item->parent_id, 'no name matching: a catalogue row is its own item until a person links it');
        $this->assertNotNull($item->crm_product_id);
    }

    // ---- the resolver ----

    public function test_every_spelling_of_a_product_reaches_the_same_row_and_unknown_values_reach_nothing(): void
    {
        $this->seedPortfolio();
        $this->apply();
        $res = new ProductResolver;
        $scholar = ProductMaster::where('sub_institute_id', 0)->where('product_key', 'scholarclone')->first();

        foreach (['scholarclone', 'scholar', 'Scholar K-12', 'scholar_k12', 'SCHOLAR-K12', 'ScholarClone', ' scholar '] as $spelling) {
            $this->assertSame($scholar->id, $res->product(self::T, $spelling)?->id, "'{$spelling}' should reach Scholar K-12");
        }
        $this->assertSame('he', $res->product(self::T, 'HE')?->product_key);
        $this->assertSame('bundle', $res->product(self::T, 'bundle')?->product_key);
        foreach (['multiple', '', null, 'no such product', 'Scholar K', 'scholars'] as $none) {
            $this->assertNull($res->product(self::T, $none), 'must not guess: ' . var_export($none, true));
        }
    }

    public function test_a_sub_product_is_found_by_its_offer_id_and_an_ambiguous_id_finds_nothing(): void
    {
        $this->seedPortfolio();
        $this->apply();
        $res = new ProductResolver;

        $this->assertSame('bundle/bun-01', $res->subProduct(self::T, 'BUN-01')?->product_key);
        $this->assertSame('scholarclone/p2', $res->subProduct(self::T, 'P2')?->product_key, 'the duplicate offer row has no master row, so P2 is not ambiguous');
        $this->assertNull($res->subProduct(self::T, 'NOPE'));
        $this->assertNull($res->subProduct(self::T, ''));

        // Two master rows pointing at two offers with the same id make the id ambiguous.
        $dupOffer = DB::table('g2g_product_offers')->where('offer_id', 'P2')->where('name', 'Scholar Core duplicate')->value('id');
        $parent = ProductMaster::where('product_key', 'scholarclone')->value('id');
        DB::table('g2g_product_master')->insert(['sub_institute_id' => self::T, 'product_key' => 'scholarclone/p2b', 'kind' => 'sub_product', 'parent_id' => $parent, 'name' => 'dup', 'status' => 'active', 'origin' => 'manual', 'offer_row_id' => $dupOffer]);
        $this->assertNull($res->subProduct(self::T, 'P2'));
    }

    public function test_the_most_specific_row_wins_unless_the_signal_disagrees_with_its_own_offer(): void
    {
        $this->seedPortfolio();
        $this->apply();
        $res = new ProductResolver;

        $this->assertSame('scholarclone/p1', $res->forSignal(self::T, 'scholar', 'P1')?->product_key, 'the offer belongs to the same product: the sub-product');
        $this->assertSame('bundle/bun-01', $res->forSignal(self::T, null, 'BUN-01')?->product_key, 'no product value: the offer decides (the 11 live Bundle signals)');
        $this->assertSame('scholarclone', $res->forSignal(self::T, 'scholar', 'BUN-01')?->product_key, 'a conflict: the stored product value wins and the offer is ignored');
        $this->assertSame('scholarclone', $res->forSignal(self::T, 'scholar', null)?->product_key);
        $this->assertSame('scholarclone', $res->forSignal(self::T, 'scholar', 'NOPE')?->product_key);
        $this->assertNull($res->forSignal(self::T, null, null));
        $this->assertNull($res->forSignal(self::T, 'multiple', null));
    }

    public function test_an_organisation_never_sees_another_organisations_own_products(): void
    {
        $this->ownProduct();
        $this->apply(self::T);
        $res = new ProductResolver;

        $this->assertSame('payroll', $res->product(self::T, 'payroll')?->product_key);
        $this->assertNull($res->product(self::T2, 'payroll'), "another organisation's own product is invisible");
        $this->assertSame('scholarclone', $res->product(self::T2, 'scholar')?->product_key, 'but the shared built-in products are visible to everyone');
    }

    // ---- the command ----

    public function test_the_command_defaults_to_a_dry_run_and_apply_writes(): void
    {
        $this->seedPortfolio();

        $this->artisan('product-master:backfill', ['--tenant' => self::T])
            ->expectsOutputToContain('DRY RUN')->expectsOutputToContain('would create')->assertExitCode(0);
        $this->assertSame(0, DB::table('g2g_product_master')->count());

        $this->artisan('product-master:backfill', ['--tenant' => self::T, '--apply' => true])
            ->expectsOutputToContain('APPLY')->assertExitCode(0);
        $this->assertGreaterThan(0, DB::table('g2g_product_master')->count());

        $this->artisan('product-master:backfill')->assertExitCode(1);
        $this->artisan('product-master:backfill', ['--all' => true])->assertExitCode(0);
    }
}
