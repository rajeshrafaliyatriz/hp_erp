<?php

namespace Tests\Feature;

use App\Domain\Signals\Icp\IcpProfile;
use App\Domain\Signals\Products\ProductCatalog;
use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * An organisation's own top-level products, each with its own ICP, and the per-opportunity ICP comparison.
 *
 * WRITTEN, NOT RUN in the change that added it: it builds the tables from the migration files on the in-memory sqlite database, and no migration
 * was to be executed. Run it (and the existing ProductIcpEditorTest / ProductPortfolioIcpTest, which must still pass unchanged) against an
 * isolated verification database before relying on the persistence.
 */
class CustomProductApiTest extends TestCase
{
    private const T = 1;
    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
        // These tests rely on an ICP stored against the product itself (the legacy path; a product's ICP now comes from its sub-products).
        config(['signals.legacy_product_icp' => true]);
        Schema::create('personal_access_tokens', function ($t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluserprofilemaster', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('role_key')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('tenant_setting', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('setting_key');
            $t->text('setting_value')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluser', function ($t) {
            $t->id();
            $t->string('user_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->integer('status')->default(1);
        });
        foreach ([
            '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables', '2026_09_30_180000_add_signal_fields_to_ingestion_findings',
            '2026_09_30_200000_add_research_sources_and_signal_kind', '2026_09_30_220000_create_g2g_portfolio_and_partners_tables',
            '2026_09_30_230000_add_claude_feed_and_linking_fields', '2026_10_01_130000_add_stage_to_g2g_research_runs',
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_11_29_090000_add_assessment_to_g2g_company_opportunities',
            '2026_12_01_090000_add_product_key_to_signals_runs_and_sources', '2026_10_14_100000_create_g2g_icp_profiles_tables',
            '2026_11_29_100000_add_date_provenance_to_g2g_research_sources', '2026_11_29_110000_add_modified_date_to_g2g_research_sources',
            '2026_10_13_100000_create_g2g_target_accounts_table', '2026_12_05_090000_create_g2g_signal_products_table',
            '2026_10_31_090000_add_research_run_configuration_scope', '2026_12_07_090000_add_scope_to_g2g_company_opportunities',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        RoleKey::flushCache();
        Cache::flush();
        ProductCatalog::forgetCustom();
        DB::table('tbluserprofilemaster')->insert([['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator'], ['id' => 2, 'name' => 'Employee', 'role_key' => 'employee']]);
    }

    private function h(int $tenant = self::T, int $profile = 1): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => $profile]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    /** Stores an ICP against a product line the way older data was stored: a product has no editor for one any more (its ICPs belong to its sub-products). */
    private function storeProductIcp(string $productKey, array $config, bool $active = true, int $tenant = self::T): void
    {
        ProductCatalog::forgetCustom();
        $line = ProductCatalog::definitionsFor($tenant)[ProductCatalog::normalise($productKey, $tenant)]['icp_product_line'];
        IcpProfile::create(['sub_institute_id' => $tenant, 'key' => $line . '_icp', 'name' => (string) ($config['label'] ?? $productKey), 'product_line' => $line,
            'status' => $active ? 'active' : 'draft', 'is_default' => true, 'version' => 1, 'created_by' => 1, 'config' => $config]);
    }

    private function icpConfig(array $over = []): array
    {
        return $over + [
            'label' => 'Payroll for SMEs', 'industry_label' => 'small manufacturers', 'buyer_types' => ['manufacturer'], 'regions' => ['Gujarat'],
            'parts' => ['payroll' => 'monthly payroll and compliance'],
            'must_have' => [['attribute' => 'staff', 'op' => '>=', 'value' => 50, 'label' => 'under 50 staff']],
            'disqualifiers' => [['label' => 'trader', 'phrases' => ['trading company']]],
            'triggers' => ['new_plant' => ['source' => 'news', 'part' => 'payroll', 'decay' => 90, 'pain' => 'a new plant needs payroll', 'phrases' => ['new plant']]],
            'queries' => [['id' => 'pay-news', 'source' => 'news', 'topic' => 'news', 'days' => 45, 'q' => 'manufacturer announces new plant Gujarat']],
            'per_run' => ['catalogue' => 1, 'accounts' => 0], 'account_query' => '"{name}" {city} new plant', 'account_days' => 30, 'account_recheck_days' => 7,
        ];
    }

    public function test_an_administrator_creates_a_product_with_its_own_icp_line_and_it_reloads_from_storage(): void
    {
        $created = $this->postJson('/api/signals/icp/products', ['key' => 'payroll_cloud', 'label' => 'Payroll Cloud'], $this->h())->assertCreated()->json('data.product');
        $this->assertTrue($created['custom']);
        $this->assertSame('payroll_cloud', $created['business_fit']);
        $this->assertSame('needs_setup', $created['state']);
        $this->assertNull($created['icp'], 'a new product starts with no ICP: nothing is borrowed from a group');

        ProductCatalog::forgetCustom();
        $keys = collect($this->getJson('/api/portfolio/products', $this->h())->assertOk()->json('data.products'))->pluck('key')->all();
        $this->assertSame(['scholarclone', 'enterprise_brain', 'g2g', 'payroll_cloud'], $keys);

        $this->storeProductIcp('payroll_cloud', $this->icpConfig());
        $profile = IcpProfile::where('sub_institute_id', self::T)->sole();
        $this->assertSame('payroll_cloud', $profile->product_line, 'its ICP is saved under its own product line');
        $this->assertSame('ready', $this->getJson('/api/portfolio/products/payroll_cloud', $this->h())->json('data.product.state'));
        // The built-in products' ICPs are untouched.
        $this->assertSame('needs_setup', $this->getJson('/api/portfolio/products/g2g', $this->h())->json('data.product.state'));
    }

    public function test_invalid_duplicate_and_built_in_keys_are_refused_with_reasons(): void
    {
        // A built-in product's key and a reserved word are refused. A key that is merely too short or spelled loosely is repaired (see keyFrom), below.
        foreach (['g2g', 'All'] as $key) {
            $this->postJson('/api/signals/icp/products', ['key' => $key, 'label' => 'Some product'], $this->h())->assertStatus(422)->assertJsonPath('code', 'product_invalid');
        }
        $this->postJson('/api/signals/icp/products', ['key' => 'fresh_one', 'label' => 'ScholarClone'], $this->h())->assertStatus(422)->assertJsonStructure(['errors' => ['label']]);
        $this->postJson('/api/signals/icp/products', ['key' => 'dupe_one', 'label' => 'Dupe One'], $this->h())->assertCreated();
        $this->postJson('/api/signals/icp/products', ['key' => 'dupe_one', 'label' => 'Dupe Two'], $this->h())->assertStatus(422)->assertJsonStructure(['errors' => ['key']]);
        $this->assertSame(1, DB::table('g2g_signal_products')->count());

        // A short or loosely spelled key (a Product ID such as P3) is repaired into a valid one, and either spelling then finds the product.
        $this->postJson('/api/signals/icp/products', ['key' => 'p3', 'label' => 'Scholar Learn'], $this->h())->assertCreated()->assertJsonPath('data.product.key', 'prod_p3');
        $this->postJson('/api/signals/icp/products', ['key' => 'has space', 'label' => 'Has Space'], $this->h())->assertCreated()->assertJsonPath('data.product.key', 'has_space');
        $this->getJson('/api/portfolio/products/p3', $this->h())->assertOk();
        $this->getJson('/api/portfolio/products/prod_p3', $this->h())->assertOk();
        $this->assertSame(3, DB::table('g2g_signal_products')->count());
    }

    public function test_a_short_product_id_and_a_long_description_save_the_product_and_its_icp(): void
    {
        // What the Add product form does for Product ID "P3": check the ICP first, create the product, then save the ICP under the key it was given.
        $long = str_repeat('Scholar Learn combines an LMS with personalised adaptive learning. ', 6);   // ~390 characters
        $config = $this->icpConfig([
            'label' => 'Scholar Learn — Private K–12 Schools — Gujarat & Maharashtra', 'industry_label' => str_repeat('Private school groups that want digital learning. ', 20),
            'buyer_types' => ['Private K–12 schools', 'Education trusts and societies operating K–12 schools'], 'regions' => ['Gujarat', 'Maharashtra'],
            'guidance' => str_repeat('Decision-makers are trustees and principals. ', 20), 'parts' => ['prod_p3' => $long], 'customer_model' => 'b2b',
            'triggers' => ['prod_p3_t1' => ['source' => 'news', 'part' => 'prod_p3', 'decay' => 90, 'pain' => 'School announces procurement of a new LMS', 'phrases' => ['School announces procurement of a new LMS']]],
        ]);
        unset($config['must_have'], $config['account_query'], $config['account_days'], $config['account_recheck_days']);

        $this->postJson('/api/signals/icp/validate', ['config' => $config], $this->h())->assertOk()->assertJsonPath('data.valid', true);
        $made = $this->postJson('/api/signals/icp/products', ['key' => 'p3', 'label' => 'Scholar Learn'], $this->h())->assertCreated();
        $key = $made->json('data.product.key');
        $this->assertSame('prod_p3', $key);
        $this->storeProductIcp($key, $config, false);
        $this->assertSame('prod_p3', IcpProfile::where('sub_institute_id', self::T)->sole()->product_line);
    }

    public function test_a_product_of_your_own_can_be_removed_and_added_again_and_a_built_in_one_cannot(): void
    {
        $this->postJson('/api/signals/icp/products', ['key' => 'gone_soon', 'label' => 'Gone Soon'], $this->h())->assertCreated();
        $this->storeProductIcp('gone_soon', $this->icpConfig());
        $this->assertSame(1, IcpProfile::where('sub_institute_id', self::T)->where('product_line', 'gone_soon')->where('status', 'active')->count());

        $this->deleteJson('/api/signals/icp/products/gone_soon', [], $this->h(self::T, 2))->assertStatus(403);   // not an administrator
        $this->deleteJson('/api/signals/icp/products/scholarclone', [], $this->h())->assertStatus(422)->assertJsonPath('code', 'built_in_product');
        $this->deleteJson('/api/signals/icp/products/nothing_here', [], $this->h())->assertStatus(404);

        $this->deleteJson('/api/signals/icp/products/gone_soon', [], $this->h())->assertOk()->assertJsonPath('data.removed', 'gone_soon')->assertJsonPath('data.icp_deleted', 1);
        $this->assertSame(0, DB::table('g2g_signal_products')->count());
        $this->assertSame(0, IcpProfile::where('product_line', 'gone_soon')->count(), 'the ICP is deleted from the database too');
        $this->assertSame(0, DB::table('g2g_icp_profile_versions')->count(), 'and its saved versions');
        $keys = collect($this->getJson('/api/portfolio/products', $this->h())->json('data.products'))->pluck('key')->all();
        $this->assertNotContains('gone_soon', $keys);

        // the same name and key can be used again
        $this->postJson('/api/signals/icp/products', ['key' => 'gone_soon', 'label' => 'Gone Soon'], $this->h())->assertCreated();
        $this->storeProductIcp('gone_soon', $this->icpConfig(), false);
        $this->assertSame(1, IcpProfile::where('product_line', 'gone_soon')->count(), 'a fresh draft only');
    }

    public function test_only_administrators_can_create_a_product_and_the_organisation_comes_from_the_token(): void
    {
        $this->postJson('/api/signals/icp/products', ['key' => 'blocked', 'label' => 'Blocked'])->assertStatus(401);
        $this->postJson('/api/signals/icp/products', ['key' => 'blocked', 'label' => 'Blocked'], $this->h(self::T, 2))->assertStatus(403);
        $this->assertSame(0, DB::table('g2g_signal_products')->count());

        // A tenant id in the request is ignored: the row is the token's organisation's.
        $this->postJson('/api/signals/icp/products', ['key' => 'mine_only', 'label' => 'Mine Only', 'sub_institute_id' => self::T2], $this->h(self::T))->assertCreated();
        $this->assertSame(self::T, (int) DB::table('g2g_signal_products')->value('sub_institute_id'));
    }

    public function test_custom_products_are_invisible_to_other_organisations_and_the_same_key_is_free_there(): void
    {
        $this->postJson('/api/signals/icp/products', ['key' => 'payroll_cloud', 'label' => 'Payroll Cloud'], $this->h(self::T))->assertCreated();
        ProductCatalog::forgetCustom();

        $theirs = collect($this->getJson('/api/portfolio/products', $this->h(self::T2))->assertOk()->json('data.products'))->pluck('key')->all();
        $this->assertSame(['scholarclone', 'enterprise_brain', 'g2g'], $theirs);
        $this->getJson('/api/portfolio/products/payroll_cloud', $this->h(self::T2))->assertStatus(404);   // another organisation's product is not found

        $this->postJson('/api/signals/icp/products', ['key' => 'payroll_cloud', 'label' => 'Payroll Cloud'], $this->h(self::T2))->assertCreated();
        $this->assertSame(2, DB::table('g2g_signal_products')->count());
    }

    public function test_the_sub_product_categories_are_exactly_the_catalog_products_and_follow_new_ones(): void
    {
        $cats = fn (int $t) => $this->getJson('/api/portfolio/taxonomy', $this->h($t))->assertOk()->json('data.parent_products');
        $products = fn (int $t) => collect($this->getJson('/api/portfolio/products', $this->h($t))->assertOk()->json('data.products'));

        $this->assertEqualsCanonicalizing(['G2G' => 'G2G', 'EB' => 'Enterprise Brain', 'Scholar K-12' => 'ScholarClone'], $cats(self::T), 'the same three products as the portfolio cards, not the old five-entry list');
        $this->assertCount(count($cats(self::T)), $products(self::T));

        $this->postJson('/api/signals/icp/products', ['key' => 'payroll_cloud', 'label' => 'Payroll Cloud'], $this->h())->assertCreated();
        ProductCatalog::forgetCustom();
        $this->assertSame('Payroll Cloud', $cats(self::T)['Payroll Cloud'] ?? null, 'a new product appears with no code change');
        $this->assertCount(count($cats(self::T)), $products(self::T));
        $this->assertArrayNotHasKey('Payroll Cloud', $cats(self::T2), 'another organisation does not see it');
    }

    public function test_a_sub_product_cannot_be_saved_under_a_category_that_is_not_a_product(): void
    {
        $r = $this->postJson('/api/portfolio/offers', ['offer_id' => 'HE-01', 'name' => 'Campus', 'parent_product' => 'HE', 'readiness_status' => 'Live'], $this->h());
        $r->assertStatus(422);
        $this->assertArrayHasKey('parent_product', $r->json('errors'));
    }

    public function test_the_opportunity_list_follows_the_scope_stored_on_each_record_and_survives_an_icp_edit(): void
    {
        $run = fn (array $o) => \App\Domain\Signals\Opportunities\ResearchRun::create($o + [
            'sub_institute_id' => self::T, 'report_date' => now()->toDateString(), 'trigger' => 'manual', 'status' => 'success', 'stage' => 'completed',
            'product_key' => 'enterprise_brain', 'customer_model' => 'b2g', 'offer_id' => null, 'region_scope' => null, 'started_at' => now(),
            'scope_key' => str_repeat('a', 64),
        ]);
        $record = fn (string $name, string $url) => [
            'buyer' => ['name' => $name, 'type' => 'enterprise', 'state' => 'Gujarat'], 'trigger_type' => 'expansion', 'trigger_summary' => 'Opening a new plant.',
            'what_happened' => $name . ' is opening a new plant.', 'event_date' => now()->subDays(3)->toDateString(), 'source_url' => $url, 'source_title' => 'News',
            'claim_level' => 'hypothesis', 'fetch_level' => 'search_snippet', 'business_fit' => 'eb',
        ];
        $import = fn (array $r, $run) => app(\App\Domain\Signals\Market\MarketImporter::class)->import(self::T, [$r], ['source_label' => 'test', 'research_run_id' => $run->id]);

        // Two selections of the same product; the first one's run was made under a configuration hash that no longer exists (the ICP was edited).
        $import($record('Alpha Mills', 'https://n.example.com/alpha'), $run(['scope_key' => str_repeat('1', 64)]));
        $import($record('Beta Mills', 'https://n.example.com/beta'), $run(['customer_model' => 'b2b', 'scope_key' => str_repeat('2', 64)]));
        // An older record from before scope was stored: no scope on it, found only through its run's key, which no longer matches either.
        $old = $run(['customer_model' => null, 'scope_key' => str_repeat('3', 64)]);
        $import($record('Old Mills', 'https://n.example.com/old'), $old);

        $names = fn (string $query) => collect($this->getJson('/api/signals/opportunities?expired=include&' . $query, $this->h())->assertOk()->json('data'))->pluck('company_name')->sort()->values()->all();

        $this->assertSame(['Alpha Mills'], $names('product=enterprise_brain&customer_model=b2g'), 'the B2G selection shows only what was found for B2G');
        $this->assertSame(['Beta Mills'], $names('product=enterprise_brain&customer_model=b2b'), 'B2B is not shown B2G records');
        $this->assertSame([], $names('product=enterprise_brain&customer_model=b2g&offer_id=EB-01'), 'another sub-product is its own selection');
    }

    public function test_the_list_can_be_sorted_by_priority_or_qualification_and_keeps_its_usual_order_otherwise(): void
    {
        $run = \App\Domain\Signals\Opportunities\ResearchRun::create([
            'sub_institute_id' => self::T, 'report_date' => now()->toDateString(), 'trigger' => 'manual', 'status' => 'success', 'stage' => 'completed',
            'product_key' => 'enterprise_brain', 'customer_model' => 'b2b', 'started_at' => now(), 'scope_key' => str_repeat('a', 64),
        ]);
        foreach ([['Low Mills', 'Low', 'Monitoring'], ['High Mills', 'High', 'Needs Verification'], ['Mid Mills', 'Medium', 'New Opportunity']] as $i => [$name, $priority, $qualification]) {
            app(\App\Domain\Signals\Market\MarketImporter::class)->import(self::T, [[
                'buyer' => ['name' => $name, 'type' => 'enterprise', 'state' => 'Gujarat'], 'trigger_type' => 'expansion', 'trigger_summary' => 'Opening a new plant.',
                'what_happened' => $name . ' is opening a new plant.', 'event_date' => now()->subDays(3)->toDateString(), 'source_url' => "https://n.example.com/{$i}",
                'source_title' => 'News', 'claim_level' => 'hypothesis', 'fetch_level' => 'search_snippet', 'business_fit' => 'eb',
            ]], ['source_label' => 'test', 'research_run_id' => $run->id]);
            DB::table('g2g_company_opportunities')->where('source_url', "https://n.example.com/{$i}")->update(['priority' => $priority, 'qualification' => $qualification, 'score_total' => 10]);
        }
        $names = fn (string $q) => collect($this->getJson('/api/signals/opportunities?expired=include&product=enterprise_brain&customer_model=b2b' . $q, $this->h())->assertOk()->json('data'))->pluck('company_name')->values()->all();

        $this->assertSame(['High Mills', 'Mid Mills', 'Low Mills'], $names('&sort=priority'));
        $this->assertSame(['Mid Mills', 'High Mills', 'Low Mills'], $names('&sort=qualification'));
        $this->assertSame($names(''), $names('&sort=default'), 'default is the usual order');
        $this->getJson('/api/signals/opportunities?sort=nonsense', $this->h())->assertStatus(422);
    }

    // ── opportunities added by hand ──────────────────────────────────────────

    /** @param array<string, mixed> $o */
    private function manualBody(array $o = []): array
    {
        return $o + ['product' => 'enterprise_brain', 'customer_model' => 'b2b', 'company_name' => 'Acme Plastics Pvt Ltd',
            'summary' => 'Acme announced on LinkedIn that it is opening a second plant and is hiring a plant IT head.',
            'source_url' => 'https://www.linkedin.com/posts/acme-plastics_expansion-123'];
    }

    private function listNames(string $query, int $tenant = self::T): array
    {
        return collect($this->getJson('/api/signals/opportunities?expired=include&product=enterprise_brain&customer_model=b2b' . $query, $this->h($tenant))->assertOk()->json('data'))->pluck('company_name')->sort()->values()->all();
    }

    public function test_a_manual_opportunity_is_stamped_with_the_server_time_and_listed_under_the_day_it_was_saved(): void
    {
        // 20:30 UTC on 2 Nov is 02:00 on 3 Nov in the signals timezone: the day it is listed under is the signals day it was saved.
        \Illuminate\Support\Carbon::setTestNow('2026-11-02 20:30:00');
        try {
            $r = $this->postJson('/api/signals/opportunities/manual', $this->manualBody(['source_published_at' => '2026-09-01']), $this->h())->assertCreated()->json('data');

            $this->assertSame('2026-11-03', $r['captured_on']);
            $row = DB::table('g2g_company_opportunities')->find($r['id']);
            $this->assertSame('2026-11-02 20:30:00', $row->created_at, 'the server clock at the moment of saving');
            $this->assertSame('2026-11-02 20:30:00', $row->first_discovered_at);
            $this->assertSame('2026-09-01', substr((string) $row->source_published_at, 0, 10), 'the post date is kept, as the source date');
            $this->assertNull($row->research_run_id);
            $this->assertSame('manual-entry', $row->import_source);
            $this->assertSame(['enterprise_brain', 'b2b'], [$row->product_key, $row->customer_model], 'saved for the selected product and customer model');

            $this->assertSame(['Acme Plastics Pvt Ltd'], $this->listNames('&run_date=2026-11-03'), 'listed under the day it was saved, with no research run');
            $this->assertSame([], $this->listNames('&run_date=2026-11-02'), 'not under the UTC day, nor the previous one');
            $this->assertSame([], $this->listNames('&run_date=2026-09-01'), 'never under the date of the post it came from');
            $this->assertSame(['Acme Plastics Pvt Ltd'], $this->listNames(''), 'and in the list without a day filter');

            $item = $this->getJson('/api/signals/opportunities?expired=include&product=enterprise_brain&customer_model=b2b&run_date=2026-11-03', $this->h())->assertOk()->json('data.0');
            $this->assertTrue($item['entered_manually']);
            $this->assertSame('2026-11-03', $item['captured_on'], 'the day it was saved, in the signals timezone');
            $this->assertNull($item['report_date'], 'no research day: it is not presented as one');
            $this->assertNull($item['research_run_id']);
            $this->assertSame('2026-09-01', substr((string) $item['source_published_at'], 0, 10), 'the post date stays the post date');
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_nothing_the_client_sends_can_backdate_a_manual_opportunity(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-11-02 10:00:00');
        try {
            $body = $this->manualBody(['source_published_at' => '2026-01-05', 'created_at' => '2020-01-01 00:00:00', 'first_discovered_at' => '2020-01-01 00:00:00', 'observed_at' => '2020-01-01', 'event_date' => '2020-01-01']);
            $id = $this->postJson('/api/signals/opportunities/manual', $body, $this->h())->assertCreated()->json('data.id');
            $row = DB::table('g2g_company_opportunities')->find($id);

            $this->assertSame('2026-11-02 10:00:00', $row->created_at);
            $this->assertSame('2026-11-02 10:00:00', $row->first_discovered_at);
            $this->assertNull($row->event_date, 'no event date is invented, and none is taken from the client');
            $this->assertSame('2026-01-05', substr((string) $row->source_published_at, 0, 10));
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_the_dates_list_includes_a_day_that_only_has_manual_opportunities_and_adds_to_a_research_day(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-11-02 10:00:00');
        try {
            $run = \App\Domain\Signals\Opportunities\ResearchRun::create([
                'sub_institute_id' => self::T, 'report_date' => '2026-11-02', 'trigger' => 'manual', 'status' => 'success', 'stage' => 'completed', 'opportunities_qualified' => 4,
                'product_key' => 'enterprise_brain', 'customer_model' => 'b2b', 'started_at' => now(), 'scope_key' => str_repeat('a', 64),
            ]);
            $this->postJson('/api/signals/opportunities/manual', $this->manualBody(), $this->h())->assertCreated();   // 2 Nov: a day with a run too
            \Illuminate\Support\Carbon::setTestNow('2026-11-05 10:00:00');
            $this->postJson('/api/signals/opportunities/manual', $this->manualBody(['company_name' => 'Beta Forge Ltd', 'source_url' => 'https://www.linkedin.com/posts/beta-1']), $this->h())->assertCreated();   // 5 Nov: no run

            $dates = collect($this->getJson('/api/signals/research/dates?product=enterprise_brain&customer_model=b2b', $this->h())->assertOk()->json('data.dates'))->keyBy('date');

            $this->assertSame(5, $dates['2026-11-02']['qualified'], "the run's 4 plus the 1 saved that day");
            $this->assertSame(1, $dates['2026-11-02']['runs']);
            $this->assertEquals(['runs' => 0, 'qualified' => 1, 'status' => 'manual'], collect($dates['2026-11-05'])->only(['runs', 'qualified', 'status'])->all(), 'a day with only a manual record is a day');
            $this->assertSame(['2026-11-05', '2026-11-02'], $dates->keys()->all(), 'newest first');
            $this->assertNotNull($run->id);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_researched_opportunities_keep_their_run_day_and_run_id_filtering_and_manual_ones_do_not_match_a_run_id(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-11-02 10:00:00');
        try {
            $run = \App\Domain\Signals\Opportunities\ResearchRun::create([
                'sub_institute_id' => self::T, 'report_date' => '2026-10-20', 'trigger' => 'manual', 'status' => 'success', 'stage' => 'completed',
                'product_key' => 'enterprise_brain', 'customer_model' => 'b2b', 'started_at' => now()->subDays(13), 'scope_key' => str_repeat('a', 64),
            ]);
            app(\App\Domain\Signals\Market\MarketImporter::class)->import(self::T, [[
                'buyer' => ['name' => 'Researched Mills', 'type' => 'enterprise', 'state' => 'Gujarat'], 'trigger_type' => 'expansion', 'trigger_summary' => 'Opening a new plant.',
                'what_happened' => 'Researched Mills is opening a new plant.', 'event_date' => '2026-10-18', 'source_url' => 'https://n.example.com/r',
                'source_title' => 'News', 'claim_level' => 'hypothesis', 'fetch_level' => 'search_snippet', 'business_fit' => 'eb',
            ]], ['source_label' => 'web-research', 'research_run_id' => $run->id]);
            $this->postJson('/api/signals/opportunities/manual', $this->manualBody(), $this->h())->assertCreated();

            $this->assertSame(['Researched Mills'], $this->listNames('&run_date=2026-10-20'), 'a research day shows its own run only, as before');
            $this->assertSame(['Researched Mills'], $this->listNames('&run_id=' . $run->id), 'run-id filtering is unchanged');
            $this->assertSame(['Acme Plastics Pvt Ltd', 'Researched Mills'], $this->listNames(''));
            $this->assertSame('2026-10-20', collect($this->getJson('/api/signals/opportunities?expired=include&product=enterprise_brain&customer_model=b2b&run_id=' . $run->id, $this->h())->json('data'))->first()['report_date']);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_a_manual_opportunity_is_checked_and_is_not_saved_twice_or_for_another_organisation(): void
    {
        $post = fn (array $o) => $this->postJson('/api/signals/opportunities/manual', $this->manualBody($o), $this->h());

        $post(['company_name' => ''])->assertStatus(422);
        $post(['source_url' => 'not a link'])->assertStatus(422);
        $post(['summary' => 'too short'])->assertStatus(422);
        $post(['product' => 'no_such_product'])->assertStatus(422)->assertJsonPath('code', 'unknown_product');
        $post(['offer_id' => 'NOT-AN-OFFER'])->assertStatus(422)->assertJsonPath('code', 'unknown_sub_product');
        $post(['customer_model' => 'b2x'])->assertStatus(422);
        $future = now()->addDays(3)->toDateString();
        $post(['source_published_at' => $future])->assertStatus(422)->assertJsonPath('errors.source_published_at.0', 'The post cannot have been published in the future.');
        $this->assertSame(0, DB::table('g2g_company_opportunities')->count(), 'nothing was saved by any refused request');

        $post([])->assertCreated();
        $post([])->assertStatus(409)->assertJsonPath('code', 'already_saved');
        $this->assertSame(1, DB::table('g2g_company_opportunities')->count());
        $this->assertSame([], $this->listNames('', self::T2), 'another organisation does not see it');
    }

    /** @return array<string, array{0: string}> */
    public static function platformLinks(): array
    {
        return [
            'LinkedIn post' => ['https://www.linkedin.com/posts/acme-plastics_expansion-activity-7123456789012345678-AbCd?utm_source=share'],
            'LinkedIn profile' => ['https://in.linkedin.com/in/jane-doe-1a2b3c'],
            'Facebook post' => ['https://www.facebook.com/AcmePlastics/posts/pfbid02abcDEF123'],
            'Facebook page' => ['https://www.facebook.com/AcmePlastics'],
            'Instagram post' => ['https://www.instagram.com/p/CxYz123AbC/?igsh=MWx4'],
            'Instagram profile' => ['https://www.instagram.com/acme.plastics/'],
            'X post' => ['https://x.com/acmeplastics/status/1712345678901234567?s=20'],
            'Twitter post' => ['https://twitter.com/acmeplastics/status/1712345678901234567'],
            'YouTube video' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            'YouTube channel' => ['https://www.youtube.com/@AcmePlastics'],
            'company website' => ['https://www.acmeplastics.co.in/about-us/news'],
            'news article' => ['https://economictimes.indiatimes.com/industry/acme-opens-plant/articleshow/12345.cms'],
            'job portal' => ['https://www.naukri.com/job-listings-erp-manager-acme-plastics-surat-021126001234'],
            'non-latin path' => ['https://example.com/समाचार/acme-plant'],
        ];
    }

    /** @dataProvider platformLinks */
    public function test_an_opportunity_can_be_added_from_a_link_on_any_platform_without_the_link_being_opened(string $url): void
    {
        \Illuminate\Support\Facades\Http::fake();
        $id = $this->postJson('/api/signals/opportunities/manual', $this->manualBody(['source_url' => $url]), $this->h())->assertCreated()->json('data.id');

        \Illuminate\Support\Facades\Http::assertNothingSent();   // the link is evidence the person supplied: never fetched, scraped or opened
        $row = DB::table('g2g_company_opportunities')->find($id);
        $this->assertSame('hypothesis', $row->claim_level, 'the person\'s own account: not marked as checked');
        $this->assertSame('Monitoring', $row->qualification);
        $this->assertSame('manual-entry', $row->import_source);
        $this->assertNull($row->research_run_id);
        $this->assertMatchesRegularExpression('#^https?://[\x21-\x7E]+$#', (string) $row->source_url, 'a valid, plain-ASCII address is what is stored');
        $this->assertSame($row->source_url, json_decode($row->sources, true)[0]['url'], 'the source list and the record agree');
        $this->assertSame($row->source_url, collect($this->getJson('/api/signals/opportunities?expired=include&product=enterprise_brain&customer_model=b2b', $this->h())->assertOk()->json('data'))->first()['source_url']);
    }

    /** @return array<string, array{0: string}> */
    public static function unsafeLinks(): array
    {
        return [
            'javascript' => ['javascript:alert(document.cookie)'],
            'javascript with a linkedin look' => ['javascript://www.linkedin.com/%0Aalert(1)'],
            'data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'file' => ['file:///etc/passwd'],
            'ftp' => ['ftp://files.example.com/a.pdf'],
            'no scheme' => ['linkedin.com/posts/x'],
            'protocol-relative' => ['//www.linkedin.com/posts/x'],
            'only a scheme' => ['https://'],
            'localhost' => ['http://localhost/admin'],
            'ip address' => ['http://10.0.0.5/x'],
            'with a password' => ['https://user:secret@example.com/a'],
            'with a space' => ['https://example.com/a b'],
            'not a link' => ['not a url'],
        ];
    }

    /** @dataProvider unsafeLinks */
    public function test_a_malformed_or_unsafe_link_is_refused_and_nothing_is_saved(string $url): void
    {
        \Illuminate\Support\Facades\Http::fake();
        $r = $this->postJson('/api/signals/opportunities/manual', $this->manualBody(['source_url' => $url]), $this->h());

        $r->assertStatus(422);
        $this->assertNotEmpty($r->json('errors.source_url'), 'the message is about the link');
        $this->assertSame(0, DB::table('g2g_company_opportunities')->count());
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_the_link_is_still_required_and_the_company_and_summary_are_still_required(): void
    {
        $post = fn (array $o) => $this->postJson('/api/signals/opportunities/manual', $this->manualBody($o), $this->h());

        $post(['source_url' => ''])->assertStatus(422)->assertJsonValidationErrors('source_url');
        $post(['company_name' => ''])->assertStatus(422)->assertJsonValidationErrors('company_name');
        $post(['summary' => ''])->assertStatus(422)->assertJsonValidationErrors('summary');
        $this->assertSame(0, DB::table('g2g_company_opportunities')->count());
    }

    public function test_the_same_post_shared_with_other_tracking_is_a_duplicate_and_a_different_post_is_not(): void
    {
        $post = fn (array $o) => $this->postJson('/api/signals/opportunities/manual', $this->manualBody($o), $this->h());

        $post(['source_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA', 'summary' => 'Acme shows its new plant floor in this video and says it is hiring a plant IT head.'])->assertCreated();
        // the same video: a short link with a share id, then a mobile link with a timestamp
        $post(['source_url' => 'https://youtu.be/AAAAAAAAAAA?si=abc123', 'summary' => 'Acme shows its new plant floor in this video and says it is hiring a plant IT head.'])->assertStatus(409)->assertJsonPath('code', 'already_saved');
        $post(['source_url' => 'https://m.youtube.com/watch?v=AAAAAAAAAAA&t=30s', 'summary' => 'Acme shows its new plant floor in this video and says it is hiring a plant IT head.'])->assertStatus(409);
        $this->assertSame(1, DB::table('g2g_company_opportunities')->count());

        // a DIFFERENT video of the same company is its own opportunity (the video id is in the query string)
        $post(['source_url' => 'https://www.youtube.com/watch?v=BBBBBBBBBBB', 'summary' => 'In a second video the managing director announces a tender for warehouse automation software.'])->assertCreated();
        $this->assertSame(2, DB::table('g2g_company_opportunities')->count());

        // LinkedIn: the same post with share tracking is a duplicate
        $post(['source_url' => 'https://www.linkedin.com/posts/acme_a-123?utm_source=share&utm_medium=member_ios', 'summary' => 'Acme announces on LinkedIn that it signed a distributor in Gujarat for the new range.'])->assertCreated();
        $post(['source_url' => 'https://linkedin.com/posts/acme_a-123/', 'summary' => 'Acme announces on LinkedIn that it signed a distributor in Gujarat for the new range.'])->assertStatus(409);
        $this->assertSame(3, DB::table('g2g_company_opportunities')->count());
    }

    public function test_two_different_posts_of_one_company_added_days_apart_are_two_opportunities(): void
    {
        $post = fn (array $o) => $this->postJson('/api/signals/opportunities/manual', $this->manualBody($o), $this->h());

        $post(['source_url' => 'https://www.facebook.com/AcmePlastics/posts/111', 'source_published_at' => '2026-10-20', 'summary' => 'Acme posts on Facebook that it is opening a second plant near Surat next quarter.'])->assertCreated();
        $post(['source_url' => 'https://www.facebook.com/AcmePlastics/posts/222', 'source_published_at' => '2026-10-22', 'summary' => 'A later Facebook post says the managing director is looking for a warehouse management vendor.'])->assertCreated();
        $post(['source_url' => 'https://www.instagram.com/p/CxYz123AbC/', 'summary' => 'Acme shares an Instagram reel of the new line and mentions hiring a plant head.'])->assertCreated();
        $this->assertSame(3, DB::table('g2g_company_opportunities')->count(), 'each is its own opportunity, on any platform, dated or not');

        // the same account of the same thing, from a second platform, is still recognised as one opportunity with an added source
        $again = $post(['source_url' => 'https://x.com/acmeplastics/status/999', 'summary' => 'Acme posts on Facebook that it is opening a second plant near Surat next quarter.'])->assertOk();
        $this->assertSame('updated', $again->json('data.outcome'));
        $this->assertSame(3, DB::table('g2g_company_opportunities')->count());
    }

    public function test_the_same_link_is_saved_independently_per_organisation_and_never_shown_across(): void
    {
        $url = 'https://www.facebook.com/AcmePlastics/posts/pfbid02abcDEF123';
        $this->postJson('/api/signals/opportunities/manual', $this->manualBody(['source_url' => $url]), $this->h(self::T))->assertCreated();
        $this->postJson('/api/signals/opportunities/manual', $this->manualBody(['source_url' => $url]), $this->h(self::T2))->assertCreated();   // not a duplicate of the other organisation's

        $this->assertSame([self::T => 1, self::T2 => 1], DB::table('g2g_company_opportunities')->selectRaw('sub_institute_id, COUNT(*) c')->groupBy('sub_institute_id')->pluck('c', 'sub_institute_id')->all());
        $this->assertSame(1, count($this->getJson('/api/signals/opportunities?expired=include&product=enterprise_brain&customer_model=b2b', $this->h(self::T2))->assertOk()->json('data')));
        $this->postJson('/api/signals/opportunities/manual', $this->manualBody(['source_url' => $url]), $this->h(self::T))->assertStatus(409);
    }

    public function test_a_link_from_another_platform_keeps_the_server_capture_time_and_the_separate_post_date(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-11-02 20:30:00');
        try {
            $id = $this->postJson('/api/signals/opportunities/manual', $this->manualBody(['source_url' => 'https://www.instagram.com/p/CxYz123AbC/', 'source_published_at' => '2026-08-15', 'created_at' => '2020-01-01 00:00:00']), $this->h())->assertCreated()->json('data.id');
            $row = DB::table('g2g_company_opportunities')->find($id);

            $this->assertSame('2026-11-02 20:30:00', $row->created_at);
            $this->assertSame('2026-08-15', substr((string) $row->source_published_at, 0, 10));
            $this->assertSame(['Acme Plastics Pvt Ltd'], $this->listNames('&run_date=2026-11-03'));
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_a_product_label_or_key_is_saved_as_that_products_own_group(): void
    {
        $this->postJson('/api/portfolio/offers', ['offer_id' => 'Z-01', 'name' => 'Z', 'parent_product' => 'Enterprise Brain', 'readiness_status' => 'Live'], $this->h())->assertSuccessful();
        $this->postJson('/api/portfolio/offers', ['offer_id' => 'Z-02', 'name' => 'Z2', 'parent_product' => 'scholarclone', 'readiness_status' => 'Live'], $this->h())->assertSuccessful();

        $this->assertSame('EB', DB::table('g2g_product_offers')->where('offer_id', 'Z-01')->value('parent_product'));
        $this->assertSame('Scholar K-12', DB::table('g2g_product_offers')->where('offer_id', 'Z-02')->value('parent_product'));
    }

    public function test_adding_an_offer_is_unchanged_and_creates_no_product(): void
    {
        $before = DB::table('g2g_signal_products')->count();
        $this->postJson('/api/portfolio/offers', ['offer_id' => 'X-01', 'name' => 'An offer', 'parent_product' => 'G2G', 'readiness_status' => 'Live'], $this->h())->assertSuccessful();
        $this->assertSame($before, DB::table('g2g_signal_products')->count());
    }

    public function test_the_comparison_is_per_opportunity_scoped_to_the_organisation_and_changes_nothing(): void
    {
        $this->postJson('/api/signals/icp/products', ['key' => 'payroll_cloud', 'label' => 'Payroll Cloud'], $this->h())->assertCreated();
        $this->storeProductIcp('payroll_cloud', $this->icpConfig());
        ProductCatalog::forgetCustom();

        $company = DB::table('g2g_companies')->insertGetId(['sub_institute_id' => self::T, 'name' => 'Acme Plastics', 'normalized_name' => 'acme plastics', 'created_at' => now(), 'updated_at' => now()]);
        $opp = DB::table('g2g_company_opportunities')->insertGetId([
            'sub_institute_id' => self::T, 'company_id' => $company, 'title' => 'New plant', 'category' => 'expansion', 'observed_event' => 'announced a new plant',
            'why_indicates_need' => 'x', 'product_fit' => 'x', 'recommended_action' => 'x', 'priority' => 'Medium', 'qualification' => 'Qualified', 'confidence' => 'Medium',
            'review_status' => 'New', 'business_fit' => 'payroll_cloud', 'sources' => '[]', 'fingerprint' => 'cmp-fixture-1', 'first_discovered_at' => now(), 'last_verified_at' => now(),
        ]);

        $r = $this->getJson("/api/signals/opportunities/{$opp}/icp-comparison", $this->h())->assertOk()->json('data');
        $this->assertTrue($r['available']);
        $this->assertSame('payroll_cloud', $r['product']['key']);
        $statuses = collect($r['criteria'])->pluck('status', 'criterion');
        $this->assertSame('Needs review', $statuses['staff at least 50'], 'no account is linked: a missing fact is never a match');
        $this->assertSame('Not found', $statuses['trader']);
        $this->assertFalse($r['evidence']['available'], 'no verified quote is stored, and none is invented');
        $this->assertSame('Qualified', $r['stored_result']['qualification'], 'the stored qualification is shown, never recomputed');
        $this->assertSame('Qualified', DB::table('g2g_company_opportunities')->where('id', $opp)->value('qualification'));

        // Another organisation cannot read it; an unassigned signal has nothing to compare.
        $this->getJson("/api/signals/opportunities/{$opp}/icp-comparison", $this->h(self::T2))->assertStatus(404);
        $this->getJson("/api/signals/opportunities/{$opp}/icp-comparison")->assertStatus(401);
        DB::table('g2g_company_opportunities')->where('id', $opp)->update(['business_fit' => null]);
        $this->assertFalse($this->getJson("/api/signals/opportunities/{$opp}/icp-comparison", $this->h())->json('data.available'));
    }
}
