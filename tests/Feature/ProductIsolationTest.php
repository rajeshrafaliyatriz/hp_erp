<?php

namespace Tests\Feature;

use App\Domain\Portfolio\ProductOffer;
use App\Domain\ProductMaster\ProductFacet;
use App\Domain\ProductMaster\ProductIsolation;
use App\Domain\ProductMaster\ProductLines;
use App\Domain\ProductMaster\ProductMaster;
use App\Domain\ProductMaster\ProductMasterBackfill;
use App\Domain\ProductMaster\ProductMasterInvalid;
use App\Domain\ProductMaster\ProductMasterSync;
use App\Domain\ProductMaster\ProductMatchSuggester;
use App\Domain\ProductMaster\ProductResolver;
use App\Domain\ProductMaster\ProductWriter;
use App\Domain\Signals\Products\ProductCatalog;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsProductReadWorld;
use Tests\TestCase;

/**
 * Products belong to ONE organisation and are visible to that organisation only (S15 follow-up, 24 Nov 2026, contract D-1 and D-2): the two scopes, the built-in Products
 * and, for EVERY reader of the Product Master, a two-organisation test that proves organisation B never sees organisation A's Product, Sub-product, About, item or custom
 * Product, that the owner of each still sees its own, and that the LEFTOVERS of the old sharing (a Product of organisation 0 with a Sub-product under it, an About with no
 * organisation) are visible to nobody.
 *
 * The world is the read world (tests/Support/BuildsProductReadWorld.php): organisations 1 and 2, each with an administrator, and each with ITS OWN copy of the built-in Products
 * (the fixture makes both owners of the built-ins; this class then makes only organisation 1 the owner, as the config list says in production). On top of it, for each
 * organisation: a Product of its own made through the real door (Alpha Suite, Beta Suite), a Sub-product with an About, and a catalogue item; and the leftovers.
 *
 * Every hand-written copy of the old rule has a reader here that a mutation of it breaks: the list (rows, Product join, About join, Sub-product counts), the Sub-product key
 * check, the sync (the Product a record moves to, the Product of a Portfolio group), the match suggester, the resolver, the facet, the options and lines, the writer's
 * lookup, the Portfolio offers, the Ask tool, the organisation presenter, the opportunity backfill, the backfill of the built-ins.
 */
class ProductIsolationTest extends TestCase
{
    use BuildsProductReadWorld;

    /** @var array{product: int, offer: int, sub: int, crm: int, item: int} */
    private array $a;

    /** @var array{product: int, offer: int, sub: int, crm: int, item: int} */
    private array $b;

    /** @var array{product: int, sub: int, offer: int} the leftovers of the old sharing */
    private array $ghost;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildReadWorld();
        $this->a = $this->orgWorld(self::T, 'Alpha', self::ADMIN);
        $this->b = $this->orgWorld(self::T2, 'Beta', self::ADMIN2);

        // The leftovers of the old sharing: a Product of organisation 0 with a Sub-product under it (and its About, which no organisation owns), and an About of its own.
        $this->ghost = [];
        $this->ghost['product'] = $this->master(['sub_institute_id' => 0, 'product_key' => 'ghost', 'kind' => 'product', 'name' => 'Ghost Suite', 'origin' => 'builtin', 'signal_key' => 'ghost', 'portfolio_name' => 'Ghost Group']);
        $this->ghost['offer'] = $this->offerRow(null, 'GH1', 'Ghost One', 'Ghost Group');
        $this->ghost['sub'] = $this->master(['sub_institute_id' => 0, 'product_key' => 'ghost/gh1', 'kind' => 'sub_product', 'name' => 'Ghost One', 'parent_id' => $this->ghost['product'], 'offer_row_id' => $this->ghost['offer'], 'origin' => 'portfolio']);

        // From here on organisation 1 owns the built-in Products and organisation 2 does not, as in production (organisation 6 owns them).
        config(['product_isolation.builtin_owner_tenants' => [self::T]]);
        ProductCatalog::forgetCustom();
    }

    /** An About row of the real table; a tenant of null is an About with no organisation. */
    private function offerRow(?int $tenant, string $offerId, string $name, string $group): int
    {
        return (int) DB::table('g2g_product_offers')->insertGetId([
            'sub_institute_id' => $tenant, 'offer_id' => $offerId, 'name' => $name, 'parent_product' => $group, 'readiness_status' => 'Prototype', 'deployment_model' => 'SaaS (multi-tenant)',
            'typical_deal_band' => 'To fill', 'created_at' => self::STAMP, 'updated_at' => self::STAMP,
        ]);
    }

    /**
     * One organisation's own Product, a Sub-product with an About under it and a catalogue item, made through the real doors.
     *
     * @return array{product: int, offer: int, sub: int, crm: int, item: int}
     */
    private function orgWorld(int $tenant, string $name, int $profile): array
    {
        $h = $this->headers($this->h($tenant, $profile));
        $product = (int) $this->postJson('/api/product-master', ['kind' => 'product', 'name' => "{$name} Suite", 'key' => strtolower($name)], $h)->assertStatus(201)->json('data.id');
        $offer = $this->offerCreate(substr($name, 0, 1) . '1', "{$name} One", "{$name} Suite", $tenant);
        $crm = $this->crmCreate("{$name} Kit", 'product', $tenant, $profile);

        return ['product' => $product, 'offer' => $offer, 'sub' => (int) $this->masterOfOffer($offer, $tenant)->id, 'crm' => $crm, 'item' => (int) $this->masterOfCrm($crm, $tenant)->id];
    }

    /** @return list<string> */
    private function listNames(int $profile, string $query = 'per=100&status=all'): array
    {
        return array_column($this->getJson('/api/product-master/list?' . $query, $this->as($profile))->assertOk()->json('data'), 'name');
    }

    // ------------------------------------------------------------------ the two scopes

    public function test_the_master_scope_returns_the_organisations_own_rows_and_nothing_else(): void
    {
        $mine = ProductMaster::visibleTo(self::T)->pluck('id')->map(fn ($v) => (int) $v)->all();

        foreach ([$this->a['product'], $this->a['sub'], $this->a['item']] as $id) {
            $this->assertContains($id, $mine, 'organisation 1 sees its own rows');
        }
        foreach ([$this->b['product'], $this->b['sub'], $this->b['item'], $this->ghost['product'], $this->ghost['sub']] as $id) {
            $this->assertNotContains($id, $mine, "row {$id} is another organisation's or nobody's");
        }
        $this->assertSame([], ProductMaster::visibleTo(0)->pluck('id')->all(), 'a caller with no organisation reads no row: organisation 0 is not an organisation');
        $this->assertSame([], ProductMaster::visibleTo(-1)->pluck('id')->all());
        $this->assertSame(0, ProductMaster::visibleTo(self::T)->where('sub_institute_id', '!=', self::T)->count());
        $this->assertSame(0, ProductMaster::SHARED, 'the constant stays: the checker and the command find the leftovers with it');
    }

    public function test_the_offer_scope_returns_the_organisations_own_offers_and_a_null_tenant_keeps_meaning_no_organisation(): void
    {
        $mine = ProductOffer::query()->forTenant(self::T)->pluck('offer_id')->all();
        $theirs = ProductOffer::query()->forTenant(self::T2)->pluck('offer_id')->all();

        $this->assertContains('A1', $mine);
        $this->assertNotContains('B1', $mine);
        $this->assertNotContains('GH1', $mine, 'an About with no organisation is nobody\'s: not even a fallback for everybody');
        $this->assertContains('B1', $theirs);
        $this->assertNotContains('A1', $theirs);
        $this->assertNotContains('GH1', $theirs);
        $this->assertSame(['GH1'], ProductOffer::query()->forTenant(null)->pluck('offer_id')->all(), 'null still means "no organisation": it reads the rows that have none');
    }

    // ------------------------------------------------------------------ the built-in Products

    public function test_the_built_in_products_belong_to_the_owner_organisations_only(): void
    {
        $this->assertArrayHasKey('scholarclone', ProductCatalog::definitionsFor(self::T));
        $this->assertArrayNotHasKey('scholarclone', ProductCatalog::definitionsFor(self::T2), 'an organisation that is not an owner has none of them');
        $this->assertSame([], array_intersect(array_keys(ProductCatalog::definitions()), array_keys(ProductCatalog::definitionsFor(self::T2))));
        // its own custom Product is still its own, and organisation 1's custom Product is not its
        $this->assertArrayHasKey('beta', ProductCatalog::definitionsFor(self::T2));
        $this->assertArrayNotHasKey('alpha', ProductCatalog::definitionsFor(self::T2));
        $this->assertArrayHasKey('alpha', ProductCatalog::definitionsFor(self::T));
        $this->assertArrayNotHasKey('beta', ProductCatalog::definitionsFor(self::T));
        // no organisation keeps the definitions of the code, and the definitions themselves are unchanged
        $this->assertSame(array_keys(ProductCatalog::definitions()), array_keys(ProductCatalog::definitionsFor(null)));
        $this->assertSame(array_keys(ProductCatalog::definitions()), array_keys(ProductCatalog::definitionsFor(0)));
        $this->assertTrue(ProductCatalog::isBuiltIn('scholarclone'));
        $this->assertNull(ProductCatalog::normalise('scholarclone', self::T2), 'the key is not a product of an organisation that does not own it');
        $this->assertSame('scholarclone', ProductCatalog::normalise('ScholarClone', self::T));
        // the owner list is configuration, read in one place
        config(['product_isolation.builtin_owner_tenants' => [self::T, self::T2]]);
        $this->assertArrayHasKey('scholarclone', ProductCatalog::definitionsFor(self::T2));
        config(['product_isolation.builtin_owner_tenants' => []]);
        $this->assertSame([], array_intersect(array_keys(ProductCatalog::definitions()), array_keys(ProductCatalog::definitionsFor(self::T))), 'an empty list: nobody owns them');
    }

    public function test_the_owner_list_is_read_from_the_config_in_one_place_and_is_tolerant(): void
    {
        config(['product_isolation.builtin_owner_tenants' => [6, '7', ' 8 ', 'x', 0, -2, 6]]);
        $this->assertSame([6, 7, 8], ProductIsolation::builtinOwners());
        $this->assertTrue(ProductIsolation::isBuiltinOwner(7));
        $this->assertFalse(ProductIsolation::isBuiltinOwner(9));
        $this->assertFalse(ProductIsolation::isBuiltinOwner(0));
        $this->assertFalse(ProductIsolation::isBuiltinOwner(null));
        $this->assertSame(6, ProductIsolation::defaultOwner());

        config(['product_isolation.builtin_owner_tenants' => '3, 4']);
        $this->assertSame([3, 4], ProductIsolation::builtinOwners());

        // the config file itself: a comma list from the environment, 6 when nothing is set, nobody when it is empty
        $read = function (?string $value): array {
            $was = getenv('G2G_BUILTIN_PRODUCTS_TENANTS');
            $value === null ? putenv('G2G_BUILTIN_PRODUCTS_TENANTS') : putenv('G2G_BUILTIN_PRODUCTS_TENANTS=' . $value);
            unset($_ENV['G2G_BUILTIN_PRODUCTS_TENANTS'], $_SERVER['G2G_BUILTIN_PRODUCTS_TENANTS']);
            try {
                return (require base_path('config/product_isolation.php'))['builtin_owner_tenants'];
            } finally {
                $was === false ? putenv('G2G_BUILTIN_PRODUCTS_TENANTS') : putenv('G2G_BUILTIN_PRODUCTS_TENANTS=' . $was);
            }
        };
        $this->assertSame([6], $read(null), 'the default is organisation 6');
        $this->assertSame([6, 7], $read('6, 7'));
        $this->assertSame([], $read(''));
    }

    // ------------------------------------------------------------------ every reader, two organisations

    public function test_the_list_shows_an_organisation_its_own_rows_only(): void
    {
        $a = $this->listNames(self::ADMIN);
        $b = $this->listNames(self::ADMIN2);

        foreach (['Alpha Suite', 'Alpha One', 'Alpha Kit'] as $name) {
            $this->assertContains($name, $a);
            $this->assertNotContains($name, $b, "{$name} is organisation 1's");
        }
        foreach (['Beta Suite', 'Beta One', 'Beta Kit'] as $name) {
            $this->assertContains($name, $b);
            $this->assertNotContains($name, $a);
        }
        foreach ([$a, $b] as $names) {
            $this->assertNotContains('Ghost Suite', $names, 'a Product of organisation 0 is listed to nobody');
            $this->assertNotContains('Ghost One', $names);
        }
        // every row says it is not shared
        foreach ($this->getJson('/api/product-master/list?per=100&status=all', $this->as(self::ADMIN))->json('data') as $row) {
            $this->assertFalse($row['shared']);
        }
    }

    public function test_a_row_is_joined_to_its_own_product_and_about_only_and_the_sub_product_count_counts_its_own_children_only(): void
    {
        // a Sub-product of organisation 1 whose parent is the Product of organisation 0, and a Sub-product of organisation 0 under organisation 1's Product
        DB::table('g2g_product_master')->where('id', $this->a['sub'])->update(['parent_id' => $this->ghost['product']]);
        $this->master(['sub_institute_id' => 0, 'product_key' => 'ghost/under-alpha', 'kind' => 'sub_product', 'name' => 'Stray', 'parent_id' => $this->a['product'], 'origin' => 'portfolio']);

        $rows = collect($this->getJson('/api/product-master/list?per=100&status=all', $this->as(self::ADMIN))->json('data'))->keyBy('name');

        $this->assertNull($rows['Alpha One']['productName'], 'its Product is not one of its own, so it is not joined');
        $this->assertSame(0, $rows['Alpha Suite']['subProductCount'], 'a child of organisation 0 is not counted under the organisation\'s Product');
        $this->assertNotContains('Stray', array_keys($rows->all()));
        // and the About of another organisation (or of nobody) behind a master row is not joined
        DB::table('g2g_product_master')->where('id', $this->a['sub'])->update(['parent_id' => $this->a['product'], 'offer_row_id' => $this->ghost['offer']]);
        $row = collect($this->getJson('/api/product-master/list?per=100&status=all', $this->as(self::ADMIN))->json('data'))->firstWhere('name', 'Alpha One');
        $this->assertFalse($row['hasAbout'], 'the About of nobody is not the organisation\'s About');
    }

    public function test_the_options_and_the_lines_offer_an_organisation_its_own_rows_only(): void
    {
        $options = fn (int $profile): array => array_column($this->getJson('/api/product-master/options', $this->as($profile))->assertOk()->json('data'), 'name');
        $lines = fn (int $profile): array => array_column($this->getJson('/api/product-master/lines', $this->as($profile))->assertOk()->json('data'), 'name');

        $this->assertContains('Alpha Suite', $options(self::ADMIN));
        $this->assertContains('Alpha One', $options(self::ADMIN));
        $this->assertNotContains('Alpha One', $options(self::ADMIN2));
        $this->assertNotContains('Beta One', $options(self::ADMIN));
        $this->assertContains('Beta Suite', $lines(self::ADMIN2));
        $this->assertNotContains('Alpha Suite', $lines(self::ADMIN2));
        foreach ([$options(self::ADMIN), $options(self::ADMIN2), $lines(self::ADMIN), $lines(self::ADMIN2)] as $names) {
            $this->assertNotContains('Ghost Suite', $names);
            $this->assertNotContains('Ghost One', $names);
        }
    }

    public function test_a_record_of_another_organisation_or_of_nobody_is_not_found(): void
    {
        foreach ([$this->b['product'], $this->b['sub'], $this->ghost['product'], $this->ghost['sub']] as $id) {
            $this->getJson("/api/product-master/{$id}", $this->as(self::ADMIN))->assertStatus(404);
            $this->putJson("/api/product-master/{$id}", ['name' => 'Taken'], $this->as(self::ADMIN))->assertStatus(404);
            $this->postJson("/api/product-master/{$id}/archive", [], $this->as(self::ADMIN))->assertStatus(404);
        }
        $this->getJson("/api/product-master/{$this->a['product']}", $this->as(self::ADMIN))->assertOk()->assertJsonPath('data.shared', false);
        $this->getJson("/api/product-master/{$this->a['product']}", $this->as(self::ADMIN2))->assertStatus(404);
        $this->getJson("/api/product-master/by-crm/{$this->a['crm']}", $this->as(self::ADMIN2))->assertStatus(404);
        $this->assertSame('Ghost Suite', DB::table('g2g_product_master')->where('id', $this->ghost['product'])->value('name'), 'nothing was written to the leftover');
    }

    public function test_the_writer_refuses_a_row_of_nobody_as_not_found(): void
    {
        $row = ProductMaster::find($this->ghost['product']);

        try {
            (new ProductWriter)->update($this->adminIdentity(self::T), $row, ['name' => 'Taken']);
            $this->fail('a row of organisation 0 is not the organisation\'s to write');
        } catch (ProductMasterInvalid $e) {
            $this->assertSame(404, $e->status, 'it is "not found", the same as another organisation\'s row: there is nothing shared to explain any more');
        }
        $this->assertSame('Ghost Suite', DB::table('g2g_product_master')->where('id', $this->ghost['product'])->value('name'));
    }

    public function test_a_sub_product_cannot_be_moved_under_a_product_of_nobody_or_of_another_organisation(): void
    {
        foreach ([$this->ghost['product'], $this->b['product']] as $product) {
            $this->putJson("/api/product-master/{$this->a['sub']}", ['productId' => $product], $this->as(self::ADMIN))->assertStatus(422);
        }
        $this->assertSame($this->a['product'], (int) DB::table('g2g_product_master')->where('id', $this->a['sub'])->value('parent_id'));
        $this->putJson("/api/product-master/{$this->a['sub']}", ['productId' => $this->a['product']], $this->as(self::ADMIN))->assertOk();
    }

    public function test_the_sync_files_an_offer_only_under_a_product_of_its_own_organisation(): void
    {
        // an offer of organisation 1 filed under the Portfolio group that only the leftover Product of organisation 0 carries
        $offer = $this->offerRow(self::T, 'GH2', 'Stray About', 'Ghost Group');
        $sync = new ProductMasterSync;

        $result = $sync->offer(self::T, $offer, ProductMasterSync::CREATED);

        $this->assertNotSame(ProductMasterSync::ACT_CREATED, $result, 'no row was made: there is no Product of the organisation\'s own for that group');
        $this->assertNull(DB::table('g2g_product_master')->where('sub_institute_id', self::T)->where('offer_row_id', $offer)->first());
    }

    public function test_the_key_of_a_new_product_is_free_when_only_a_row_of_nobody_holds_it(): void
    {
        $this->assertSame('ghost', ProductLines::freeKey(self::T, 'ghost'), 'a leftover of organisation 0 holds no key: the unique keys are per organisation');
        $this->assertSame('alpha_2', ProductLines::freeKey(self::T, 'alpha'), 'its own Product does');
        $this->assertSame('alpha', ProductLines::freeKey(self::T2, 'alpha'), 'and another organisation\'s Product does not');
    }

    public function test_a_line_is_visible_to_its_organisation_only(): void
    {
        $this->assertTrue(ProductLines::isVisibleLine(self::T, $this->a['product']));
        $this->assertFalse(ProductLines::isVisibleLine(self::T, $this->b['product']));
        $this->assertFalse(ProductLines::isVisibleLine(self::T, $this->ghost['product']));
        $this->assertNull(ProductLines::findByName(self::T, 'Ghost Suite'));
        $this->assertNull(ProductLines::findByName(self::T2, 'Alpha Suite'));
        $this->assertSame($this->a['product'], (int) ProductLines::findByName(self::T, 'alpha suite')?->id);
    }

    public function test_the_resolver_finds_an_organisations_own_rows_only(): void
    {
        $resolver = new ProductResolver;

        $this->assertSame($this->a['product'], (int) $resolver->product(self::T, 'alpha')?->id);
        $this->assertNull($resolver->product(self::T2, 'alpha'));
        $this->assertNull($resolver->product(self::T, 'ghost'), 'a leftover of organisation 0 is not a product of anybody');
        $this->assertNull($resolver->product(self::T2, 'Ghost Group'));
        $this->assertSame($this->a['sub'], (int) $resolver->subProduct(self::T, 'A1')?->id);
        $this->assertNull($resolver->subProduct(self::T2, 'A1'));
        $this->assertNull($resolver->subProduct(self::T, 'GH1'), 'an About with no organisation resolves to nothing');
        $this->assertNull($resolver->subProduct(self::T2, 'GH1'));
        $this->assertNull($resolver->forSignal(self::T2, 'alpha', 'A1'));
    }

    public function test_the_facet_matches_the_children_of_the_organisation_only(): void
    {
        $stray = $this->master(['sub_institute_id' => 0, 'product_key' => 'ghost/under-alpha', 'kind' => 'sub_product', 'name' => 'Stray', 'parent_id' => $this->a['product'], 'origin' => 'portfolio']);
        $own = $this->master(['sub_institute_id' => self::T2, 'product_key' => 'beta/other', 'kind' => 'sub_product', 'name' => 'Not yours', 'parent_id' => $this->a['product'], 'origin' => 'portfolio']);

        $ids = ProductFacet::ids(self::T, $this->a['product']);

        $this->assertSame([$this->a['product'], $this->a['sub']], $ids);
        $this->assertNotContains($stray, $ids);
        $this->assertNotContains($own, $ids);
    }

    public function test_the_match_suggester_compares_an_organisations_own_rows_only(): void
    {
        $suggest = fn (int $tenant): string => (string) json_encode((new ProductMatchSuggester)->suggest($tenant));

        $a = $suggest(self::T);
        $b = $suggest(self::T2);

        $this->assertStringNotContainsString('Beta', $a);
        $this->assertStringNotContainsString('Alpha', $b);
        $this->assertStringNotContainsString('Ghost', $a . $b);
        // the Product an item sits under is the organisation's own: an item filed under a Product of nobody has no Product to compare within
        DB::table('g2g_product_master')->where('id', $this->a['item'])->update(['parent_id' => $this->ghost['product']]);
        $json = $suggest(self::T);
        $this->assertStringNotContainsString('Ghost', $json);
    }

    public function test_the_portfolio_offers_and_the_catalogue_of_products_show_an_organisation_its_own_only(): void
    {
        $offers = fn (int $profile): array => array_column($this->getJson('/api/portfolio/offers?per_page=100', $this->as($profile))->assertOk()->json('data.data') ?? $this->getJson('/api/portfolio/offers?per_page=100', $this->as($profile))->json('data'), 'offer_id');

        $a = $offers(self::ADMIN);
        $b = $offers(self::ADMIN2);

        $this->assertContains('A1', $a);
        $this->assertNotContains('B1', $a);
        $this->assertContains('B1', $b);
        $this->assertNotContains('A1', $b);
        $this->assertNotContains('GH1', $a, 'the About with no organisation is shown to nobody');
        $this->assertNotContains('GH1', $b);
        $this->assertSame(0, (int) $this->getJson('/api/portfolio/stats', $this->as(self::ADMIN2))->json('data.by_parent_product.Alpha Suite'));
        $this->getJson("/api/portfolio/offers/{$this->ghost['offer']}", $this->as(self::ADMIN))->assertStatus(404);

        // the products of Signals: the owner has the built-in ones and its own, another organisation its own only
        $keys = fn (int $profile): array => array_column($this->getJson('/api/portfolio/products', $this->as($profile))->assertOk()->json('data.products'), 'key');
        $this->assertContains('scholarclone', $keys(self::ADMIN));
        $this->assertContains('alpha', $keys(self::ADMIN));
        $this->assertSame(['beta'], $keys(self::ADMIN2), 'organisation 2 owns no built-in Product, and organisation 1\'s custom Product is not its');
    }

    public function test_the_checker_reads_one_organisation_without_the_leftovers_and_the_whole_database_with_them(): void
    {
        $all = (new \App\Domain\ProductMaster\ProductIntegrity)->check();
        $own = (new \App\Domain\ProductMaster\ProductIntegrity(null, self::T2))->check();
        $zero = (new \App\Domain\ProductMaster\ProductIntegrity(null, 0))->check();

        $this->assertSame(3, $all['counts']['not_isolated'], 'the leftover Product and Sub-product of organisation 0, and the About with no organisation');
        $this->assertSame(3, $zero['counts']['not_isolated']);
        $this->assertSame(0, $own['counts']['not_isolated'], 'a run limited to one organisation does not report the rows of nobody');
    }

    // ------------------------------------------------------------------ the writers of rows

    public function test_the_backfill_makes_the_built_in_products_for_an_owner_organisation_only_and_never_for_organisation_0(): void
    {
        DB::table('g2g_product_master')->where('kind', '!=', 'zzz')->delete();
        DB::table('g2g_product_offers')->delete();
        config(['product_isolation.builtin_owner_tenants' => [self::T]]);

        $owner = (new ProductMasterBackfill)->run(self::T, true);
        $other = (new ProductMasterBackfill)->run(self::T2, true);
        $none = (new ProductMasterBackfill)->run(0, true);

        $this->assertGreaterThanOrEqual(4, $owner['created']['built-in product'] ?? 0, 'the owner gets the built-in Products, under its own id');
        $this->assertArrayNotHasKey('built-in product', $other['created'], 'an organisation that is not an owner gets none of the built-in Products (its own Product and its catalogue item are its own and are made)');
        $this->assertSame([], $none['created'], 'and organisation 0 is never filled');
        $this->assertSame(0, DB::table('g2g_product_master')->where('sub_institute_id', 0)->count());
        $this->assertSame(0, DB::table('g2g_product_master')->where('sub_institute_id', self::T2)->where('origin', 'builtin')->count(), 'organisation 2 holds no built-in Product');
        $this->assertGreaterThanOrEqual(4, DB::table('g2g_product_master')->where('sub_institute_id', self::T)->where('kind', 'product')->count());
        $this->assertSame(1, DB::table('g2g_product_master')->where('sub_institute_id', self::T)->where('product_key', 'scholarclone')->count());

        // a second run changes nothing
        $again = (new ProductMasterBackfill)->run(self::T, true);
        $this->assertSame([], $again['created']);
    }

    public function test_the_backfill_does_not_read_an_about_with_no_organisation(): void
    {
        DB::table('g2g_product_master')->delete();
        config(['product_isolation.builtin_owner_tenants' => [self::T]]);

        (new ProductMasterBackfill)->run(self::T, true);

        $this->assertNull(DB::table('g2g_product_master')->where('offer_row_id', $this->ghost['offer'])->first(), 'an About of nobody is nobody\'s Sub-product');
    }

    // ------------------------------------------------------------------ the readers outside the Product Master folder

    public function test_the_organisation_page_names_a_product_of_its_own_organisation_only(): void
    {
        $presenter = new \App\Domain\OrgAccounts\OrgAccountPresenter(self::T);
        $names = new \ReflectionMethod($presenter, 'productNames');

        $found = $names->invoke($presenter, [$this->a['product'], $this->b['product'], $this->ghost['product']]);

        $this->assertSame([$this->a['product'] => 'Alpha Suite'], $found, 'its own Product is named; another organisation\'s and a leftover row of organisation 0 are not');
    }

    public function test_the_usage_of_a_record_reads_the_offer_code_of_its_own_organisation_only(): void
    {
        // organisation 1's Sub-product points (by damage) at the About that belongs to no organisation, and a signal of organisation 1 names that About's code
        DB::table('g2g_product_master')->where('id', $this->a['sub'])->update(['offer_row_id' => $this->ghost['offer']]);
        DB::table('g2g_company_opportunities')->insert(['sub_institute_id' => self::T, 'business_fit' => 'alpha', 'relevant_offer_id' => 'GH1', 'is_sample' => 0]);

        $usage = $this->getJson("/api/product-master/{$this->a['sub']}/usage", $this->as(self::ADMIN))->assertOk()->json('data');

        $this->assertNull($usage['signals'], 'an About of nobody has no code for this organisation, so no signal is counted through it');
    }
}
