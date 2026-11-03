<?php

namespace Tests\Feature;

use App\Domain\Portfolio\ProductOffer;
use App\Domain\Signals\Icp\IcpProfile;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Products\ProductCatalog;
use App\Domain\Signals\Products\ProductNotConfigured;
use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A main product has NO ICP of its own. Its ICPs belong to its sub-products (per customer model), and everything that used to read a product-level
 * ICP (readiness, a whole-product run, "all sub-products") is derived from them: each sub-product's own ICP is used, one without a usable ICP is
 * reported by name and never skipped silently or replaced by another's, and nothing stored is deleted. Runs on in-memory sqlite; nothing is called.
 */
class SubProductIcpOwnershipTest extends TestCase
{
    private const T = 1;
    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
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
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_10_08_120000_add_readiness_confirmation_to_g2g_product_offers',
            '2026_11_29_090000_add_assessment_to_g2g_company_opportunities', '2026_12_01_090000_add_product_key_to_signals_runs_and_sources',
            '2026_10_31_090000_add_research_run_configuration_scope', '2026_10_14_100000_create_g2g_icp_profiles_tables',
            '2026_10_13_100000_create_g2g_target_accounts_table', '2026_10_27_200000_add_icp_config_to_g2g_product_offers_table',
            '2026_12_05_090000_create_g2g_signal_products_table',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        RoleKey::flushCache();
        Cache::flush();
        ProductCatalog::forgetCustom();
        Carbon::setTestNow('2026-11-03 10:00:00');
        DB::table('tbluserprofilemaster')->insert([['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function h(int $tenant = self::T): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => 1]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    /** One ICP for a sub-product: who buys it, its triggers and its searches. */
    private function icp(string $who, string $query, array $over = []): array
    {
        return $over + [
            'label' => $who, 'industry_label' => $who, 'buyer_types' => ['enterprise'], 'regions' => ['Gujarat'],
            'triggers' => ['t_' . md5($query) => ['source' => 'news', 'part' => 'x', 'decay' => 90, 'pain' => "{$who} need it", 'phrases' => [$query]]],
            'queries' => [['id' => 'q-' . md5($query), 'source' => 'news', 'topic' => 'news', 'days' => 45, 'q' => $query]],
        ];
    }

    /** @param array<string, array<string, mixed>|null> $icps customer model => ICP */
    private function sub(string $id, string $name, array $icps = [], string $product = 'EB', int $tenant = self::T): ProductOffer
    {
        return ProductOffer::create([
            'sub_institute_id' => $tenant, 'offer_id' => $id, 'name' => $name, 'parent_product' => $product, 'readiness_status' => 'Live', 'what_it_does' => "{$name} does its job.",
            'is_user_edited' => true, 'icp_config' => $icps === [] ? null : $icps,
        ]);
    }

    // â”€â”€ a product has no ICP editor or storage of its own â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function test_a_main_product_icp_can_no_longer_be_read_created_edited_activated_validated_or_compared(): void
    {
        $h = $this->h();
        $config = ['config' => $this->icp('Mills', 'new plant'), 'activate' => true];

        foreach (['enterprise_brain', 'scholarclone', 'g2g'] as $product) {
            foreach ([['getJson', ''], ['putJson', ''], ['patchJson', '/status'], ['postJson', '/validate'], ['getJson', '/compare']] as [$method, $suffix]) {
                $uri = "/api/signals/icp/products/{$product}{$suffix}";
                $status = ($method === 'getJson' ? $this->getJson($uri, $h) : $this->{$method}($uri, $config, $h))->getStatusCode();
                $this->assertContains($status, [404, 405], "{$method} /api/signals/icp/products/{$product}{$suffix} must be gone, got {$status}");
            }
        }
        $this->assertSame(0, IcpProfile::count(), 'nothing was created by any of those attempts');
    }

    public function test_a_product_itself_can_still_be_created_and_deleted(): void
    {
        $h = $this->h();
        $this->postJson('/api/signals/icp/products', ['key' => 'payroll_cloud', 'label' => 'Payroll Cloud'], $h)->assertCreated();
        $this->assertSame(0, IcpProfile::count(), 'creating a product creates no ICP');
        ProductCatalog::forgetCustom();
        $this->deleteJson('/api/signals/icp/products/payroll_cloud', [], $h)->assertOk()->assertJsonPath('data.removed', 'payroll_cloud');
        $this->assertSame(0, DB::table('g2g_signal_products')->count());
    }

    // â”€â”€ ICPs belong to the right sub-product and customer scope â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function test_each_sub_product_keeps_its_own_icp_per_customer_model_and_is_reported_on_its_own(): void
    {
        $this->sub('EB-01', 'Knowledge Layer', ['b2b' => $this->icp('Manufacturers', 'new plant Gujarat'), 'b2g' => $this->icp('State departments', 'tender knowledge management')]);
        $this->sub('EB-02', 'Plant Dashboard', ['b2b' => $this->icp('Plant owners', 'plant downtime')]);
        $this->sub('EB-03', 'Not set up yet');

        $offers = collect($this->getJson('/api/portfolio/products/enterprise_brain', $this->h())->assertOk()->json('data.product.offers'))->keyBy('offer_id');

        $this->assertTrue($offers['EB-01']['icp_models']['b2b']['ready']);
        $this->assertTrue($offers['EB-01']['icp_models']['b2g']['ready']);
        $this->assertTrue($offers['EB-02']['icp_models']['b2b']['ready']);
        $this->assertFalse($offers['EB-02']['icp_models']['b2g']['ready'], 'EB-02 has no B2G ICP: nothing is borrowed from EB-01');
        $this->assertFalse($offers['EB-03']['icp_models']['b2b']['ready']);
        $this->assertStringContainsString('no Individual ICP is saved', implode(' ', $offers['EB-03']['icp_models']['b2b']['missing']));
    }

    // â”€â”€ "all sub-products" resolves every applicable ICP â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function test_all_sub_products_use_each_ready_sub_products_own_icp_and_report_the_one_without(): void
    {
        $this->sub('EB-01', 'Knowledge Layer', ['b2b' => $this->icp('Manufacturers', 'new plant Gujarat')]);
        $this->sub('EB-02', 'Plant Dashboard', ['b2b' => $this->icp('Plant owners', 'plant downtime alerts')]);
        $this->sub('EB-03', 'Not set up yet');

        $product = $this->getJson('/api/portfolio/products/enterprise_brain', $this->h())->assertOk()->json('data.product');
        $this->assertTrue($product['ready'], 'at least one sub-product can run');
        $this->assertSame('sub_products', $product['icp']['source'], 'the ICP of the product is the combination of its sub-products\' ICPs');
        $this->assertSame(['EB-01', 'EB-02'], $product['icp']['offer_ids'], 'exactly the sub-products that have an ICP');
        $this->assertSame([], $product['missing'], 'nothing blocks the run: the ready sub-products can be searched');
        $without = $product['sub_products_without_icp'];
        $this->assertSame(['EB-03'], array_column($without, 'offer_id'), 'the one without an ICP is named, not skipped silently');
        $this->assertSame('Not set up yet', $without[0]['name']);
        $this->assertStringContainsString('no Individual ICP is saved', implode(' ', $without[0]['problems']));

        $ctx = (new ProductCatalog())->resolve(self::T, 'enterprise_brain');
        $queries = array_column($ctx->pack->config()['queries'], 'q');
        $this->assertEqualsCanonicalizing(['new plant Gujarat', 'plant downtime alerts'], $queries, 'the searches of BOTH sub-products, none invented');
        $this->assertSame(['eb-01', 'eb-02'], array_keys($ctx->parts), 'a signal can be about either sub-product, and not about EB-03');
        $this->assertNull($ctx->subProduct, 'a whole-product run, not one sub-product');
        $this->assertSame(['EB-03'], array_column($ctx->withoutIcp, 'offer_id'), 'the run knows which sub-product it will not search, and says so');
        $this->assertNull($ctx->pack->profileId, 'there is no product-level ICP profile behind it');
    }

    public function test_the_customer_model_decides_which_sub_products_apply(): void
    {
        $this->sub('EB-01', 'Knowledge Layer', ['b2b' => $this->icp('Manufacturers', 'new plant'), 'b2g' => $this->icp('Departments', 'department tender')]);
        $this->sub('EB-02', 'Plant Dashboard', ['b2b' => $this->icp('Plant owners', 'plant downtime')]);

        $b2b = (new ProductCatalog())->resolve(self::T, 'enterprise_brain', 'b2b');
        $b2g = (new ProductCatalog())->resolve(self::T, 'enterprise_brain', 'b2g');

        $this->assertSame(['eb-01', 'eb-02'], array_keys($b2b->parts));
        $this->assertSame(['eb-01'], array_keys($b2g->parts), 'only the sub-product with a B2G ICP applies to B2G');
        $this->assertSame(['department tender'], array_column($b2g->pack->config()['queries'], 'q'));
        $d = (new ProductCatalog())->describe(self::T, 'enterprise_brain');
        $this->assertTrue($d['icp_models']['b2g']['ready']);
        $this->assertSame(['EB-02'], array_column($d['icp_models']['b2g']['without_icp'], 'offer_id'), 'and the one that does not is named for that model');
        $this->assertSame([], $d['icp_models']['b2b']['without_icp'], 'while for B2B both have one');
    }

    public function test_one_sub_product_runs_from_its_own_icp_and_does_not_inherit_a_product_level_one(): void
    {
        // An ICP stored against the product (older data) must not leak into a sub-product's run any more.
        IcpProfile::create(['sub_institute_id' => self::T, 'key' => 'eb_old', 'name' => 'Old EB', 'product_line' => 'eb', 'status' => 'active', 'is_default' => true, 'version' => 3, 'created_by' => 1,
            'config' => $this->icp('Old product-level customers', 'old product level search', ['disqualifiers' => [['label' => 'old exclusion', 'phrases' => ['old exclusion phrase']]]])]);
        $this->sub('EB-01', 'Knowledge Layer', ['b2b' => $this->icp('Manufacturers', 'new plant Gujarat')]);

        $ctx = (new ProductCatalog())->resolveSub(self::T, 'enterprise_brain', 'EB-01');

        $this->assertSame(['new plant Gujarat'], array_column($ctx->pack->config()['queries'], 'q'));
        $this->assertSame([], (array) ($ctx->pack->config()['disqualifiers'] ?? []), 'the product-level exclusions are not applied: they were never this sub-product\'s');
        $this->assertSame('EB-01', $ctx->subProduct['offer_id']);
    }

    // â”€â”€ missing ICPs are reported explicitly â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function test_with_no_sub_product_icp_the_product_is_not_ready_and_every_sub_product_is_named(): void
    {
        $this->sub('EB-01', 'Knowledge Layer');
        $this->sub('EB-02', 'Plant Dashboard');

        $product = $this->getJson('/api/portfolio/products/enterprise_brain', $this->h())->assertOk()->json('data.product');
        $this->assertFalse($product['ready']);
        $this->assertNull($product['icp']);
        $this->assertSame('needs_setup', $product['state']);
        $text = implode(' | ', $product['missing']);
        $this->assertStringContainsString('Knowledge Layer (EB-01): ICP not configured', $text);
        $this->assertStringContainsString('Plant Dashboard (EB-02): ICP not configured', $text);
        $this->assertStringContainsString('Individual ICP', (string) $product['next_step']);

        try {
            (new ProductCatalog())->resolve(self::T, 'enterprise_brain');
            $this->fail('a run must be refused, not started from another product\'s or a made-up ICP');
        } catch (ProductNotConfigured $e) {
            $this->assertStringContainsString('Knowledge Layer (EB-01): ICP not configured', implode(' ', $e->missing));
        }
    }

    public function test_a_product_with_no_sub_products_says_so(): void
    {
        $d = (new ProductCatalog())->describe(self::T, 'g2g');

        $this->assertFalse($d['ready']);
        $this->assertStringContainsString('has no sub-products yet', implode(' ', $d['missing']));
    }

    public function test_the_stored_product_level_icp_does_not_make_a_product_ready(): void
    {
        IcpProfile::create(['sub_institute_id' => self::T, 'key' => 'eb_old', 'name' => 'Old EB', 'product_line' => 'eb', 'status' => 'active', 'is_default' => true, 'version' => 1, 'created_by' => 1,
            'config' => $this->icp('Old', 'old product level search')]);
        $this->sub('EB-01', 'Knowledge Layer');

        $this->assertFalse((new ProductCatalog())->describe(self::T, 'enterprise_brain')['ready'], 'a sub-product without its own ICP is not ready, whatever is stored for the product');
    }

    // â”€â”€ tenant isolation and history â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function test_another_organisations_sub_product_icps_are_never_used(): void
    {
        $this->sub('EB-01', 'Theirs', ['b2b' => $this->icp('Their customers', 'their search')], 'EB', self::T2);
        $this->sub('EB-09', 'Mine', ['b2b' => $this->icp('My customers', 'my search')]);

        $ctx = (new ProductCatalog())->resolve(self::T, 'enterprise_brain');
        $this->assertSame(['my search'], array_column($ctx->pack->config()['queries'], 'q'));
        $this->assertSame(['eb-09'], array_keys($ctx->parts));
        $this->assertSame(['EB-01'], collect($this->getJson('/api/portfolio/products/enterprise_brain', $this->h(self::T2))->assertOk()->json('data.product.offers'))->pluck('offer_id')->all());
    }

    public function test_nothing_stored_is_changed_or_deleted_by_reading_products_and_their_icps(): void
    {
        $profile = IcpProfile::create(['sub_institute_id' => self::T, 'key' => 'eb_old', 'name' => 'Old EB', 'product_line' => 'eb', 'status' => 'active', 'is_default' => true, 'version' => 3, 'created_by' => 7,
            'config' => $this->icp('Old', 'old product level search')]);
        $this->sub('EB-01', 'Knowledge Layer', ['b2b' => $this->icp('Manufacturers', 'new plant')]);
        $company = DB::table('g2g_companies')->insertGetId(['sub_institute_id' => self::T, 'name' => 'Acme', 'normalized_name' => 'acme', 'created_at' => now(), 'updated_at' => now()]);
        $opportunity = DB::table('g2g_company_opportunities')->insertGetId(['sub_institute_id' => self::T, 'company_id' => $company, 'title' => 't', 'category' => 'project', 'sources' => '[]', 'fingerprint' => 'keep',
            'observed_event' => 'x', 'why_indicates_need' => 'x', 'product_fit' => 'x', 'recommended_action' => 'x', 'priority' => 'Low', 'qualification' => 'Qualified', 'confidence' => 'Low',
            'review_status' => 'New', 'business_fit' => 'eb', 'first_discovered_at' => now(), 'last_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $before = [IcpProfile::count(), ProductOffer::count(), (int) $profile->fresh()->version, CompanyOpportunity::count()];

        $catalog = new ProductCatalog();
        $catalog->describe(self::T, 'enterprise_brain');
        $catalog->resolve(self::T, 'enterprise_brain');
        $catalog->resolveSub(self::T, 'enterprise_brain', 'EB-01');
        $this->getJson('/api/portfolio/products', $this->h())->assertOk();

        $this->assertSame($before, [IcpProfile::count(), ProductOffer::count(), (int) $profile->fresh()->version, CompanyOpportunity::count()]);
        $this->assertSame('keep', CompanyOpportunity::findOrFail($opportunity)->fingerprint, 'a historical opportunity is untouched');
        $this->assertSame(7, (int) $profile->fresh()->created_by, 'and so is the stored ICP row, with its author and version');
    }

    // â”€â”€ the old behaviour is only reachable by an explicit switch â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function test_the_product_level_icp_is_read_only_while_the_legacy_switch_is_on(): void
    {
        IcpProfile::create(['sub_institute_id' => self::T, 'key' => 'eb_old', 'name' => 'Old EB', 'product_line' => 'eb', 'status' => 'active', 'is_default' => true, 'version' => 1, 'created_by' => 1,
            'config' => $this->icp('Old', 'old product level search')]);
        $this->sub('EB-01', 'Knowledge Layer', ['b2b' => $this->icp('Manufacturers', 'new plant')]);

        $this->assertSame(['new plant'], array_column((new ProductCatalog())->resolve(self::T, 'enterprise_brain')->pack->config()['queries'], 'q'), 'the default: the sub-product\'s');
        config(['signals.legacy_product_icp' => true]);
        $this->assertSame(['old product level search'], array_column((new ProductCatalog())->resolve(self::T, 'enterprise_brain')->pack->config()['queries'], 'q'), 'only with the switch on');
    }

    // â”€â”€ the generic ICP profile endpoints cannot make a product-level ICP â”€â”€â”€â”€

    /** A complete ICP in the shape the validator accepts. */
    private function validIcp(array $over = []): array
    {
        return $over + [
            'label' => 'Test ICP', 'industry_label' => 'mid-size manufacturers', 'buyer_types' => ['manufacturer'], 'regions' => ['Gujarat'],
            'parts' => ['knowledge_layer' => 'one searchable memory across plants'],
            'triggers' => ['new_plant' => ['source' => 'news', 'part' => 'knowledge_layer', 'decay' => 90, 'pain' => 'a new plant needs shared knowledge', 'phrases' => ['new plant']]],
            'queries' => [['id' => 'eb-news-plant', 'source' => 'news', 'topic' => 'news', 'days' => 45, 'q' => 'manufacturer announces new plant Gujarat']],
            'per_run' => ['catalogue' => 1, 'accounts' => 0],
        ];
    }

    private function legacyProfile(int $tenant = self::T): IcpProfile
    {
        return IcpProfile::create(['sub_institute_id' => $tenant, 'key' => 'eb_old', 'name' => 'Old EB', 'product_line' => 'eb', 'status' => 'active', 'is_default' => true, 'version' => 3, 'created_by' => 7,
            'config' => $this->validIcp(['product_line' => 'eb'])]);
    }

    public function test_the_generic_profile_endpoints_refuse_to_create_a_product_level_icp(): void
    {
        $h = $this->h();
        $this->postJson('/api/signals/icp/products', ['key' => 'payroll_cloud', 'label' => 'Payroll Cloud'], $h)->assertCreated();
        ProductCatalog::forgetCustom();

        foreach ([['eb', 'eb_new'], ['scholar', 'scholar_new'], ['g2g', 'g2g_new'], ['payroll_cloud', 'payroll_new'], ['scholarclone', 'sc_new'], ['enterprise_brain', 'eb_full_new']] as [$line, $key]) {
            $r = $this->postJson('/api/signals/icp/profiles', ['key' => $key, 'name' => 'New', 'config' => $this->validIcp(['product_line' => $line])], $h);
            $r->assertStatus(422)->assertJsonPath('code', 'product_level_icp_not_supported');
            $this->assertStringContainsString('sub-product', (string) $r->json('message'));
        }
        // Setting a product key as the profile's own key is also refused
        foreach (['scholar', 'scholarclone', 'eb', 'enterprise_brain', 'g2g', 'payroll_cloud'] as $productKey) {
            $r = $this->postJson('/api/signals/icp/profiles', ['key' => $productKey, 'name' => 'By Product Key', 'config' => $this->validIcp()], $h);
            $r->assertStatus(422)->assertJsonPath('code', 'product_level_icp_not_supported');
        }
        // copying a built-in product template is the same thing
        $this->postJson('/api/signals/icp/profiles', ['key' => 'scholar_copy', 'name' => 'Copy', 'template' => 'scholar_k12'], $h)->assertStatus(422)->assertJsonPath('code', 'product_level_icp_not_supported');
        $this->assertSame(0, IcpProfile::count(), 'nothing was created');
    }

    public function test_the_generic_profile_endpoints_still_take_a_sub_products_icp_and_it_is_what_that_sub_product_runs_from(): void
    {
        $h = $this->h();
        $this->sub('EB-01', 'Knowledge Layer');
        $config = $this->validIcp(['product_line' => 'offer_eb_01_b2g', 'customer_model' => 'b2g', 'offer_ids' => ['EB-01'], 'queries' => [['id' => 'q-b2g', 'source' => 'news', 'topic' => 'news', 'days' => 45, 'q' => 'department tender knowledge management']]]);

        $created = $this->postJson('/api/signals/icp/profiles', ['key' => 'sub_eb-01_b2g', 'name' => 'Knowledge Layer B2G ICP', 'config' => $config], $h)->assertCreated();
        $this->patchJson('/api/signals/icp/profiles/' . $created->json('data.id') . '/status', ['status' => 'active'], $h)->assertOk();

        $catalog = new ProductCatalog();
        $this->assertSame([], $catalog->subProduct(self::T, 'enterprise_brain', 'EB-01', 'b2g')['problems'], 'the sub-product now has a usable B2G ICP');
        $this->assertNotSame([], $catalog->subProduct(self::T, 'enterprise_brain', 'EB-01', 'b2b')['problems'], 'and still none for B2B: the model is its own scope');
        $this->assertSame(['department tender knowledge management'], array_column($catalog->resolveSub(self::T, 'enterprise_brain', 'EB-01', 'b2g')->pack->config()['queries'], 'q'));

        // activating another ICP for the same sub-product line archives the first one
        $second = $this->postJson('/api/signals/icp/profiles', ['key' => 'sub_eb-01_b2g_v2', 'name' => 'Knowledge Layer B2G ICP v2', 'config' => $config], $h)->assertCreated();
        $this->patchJson('/api/signals/icp/profiles/' . $second->json('data.id') . '/status', ['status' => 'active'], $h)->assertOk();
        $this->assertSame('archived', IcpProfile::find($created->json('data.id'))->status);
        $this->assertSame('active', IcpProfile::find($second->json('data.id'))->status);
    }

    public function test_a_stored_product_level_profile_stays_readable_and_is_not_edited_or_reactivated_through_the_generic_endpoints(): void
    {
        $h = $this->h();
        $old = $this->legacyProfile();
        $other = IcpProfile::create(['sub_institute_id' => self::T, 'key' => 'eb_second', 'name' => 'Second', 'product_line' => 'eb', 'status' => 'active', 'is_default' => false, 'version' => 1, 'created_by' => 7, 'config' => $this->validIcp(['product_line' => 'eb'])]);

        // reads and checks
        $this->getJson('/api/signals/icp/profiles', $h)->assertOk()->assertJsonFragment(['key' => 'eb_old']);
        $this->getJson("/api/signals/icp/profiles/{$old->id}", $h)->assertOk()->assertJsonPath('data.key', 'eb_old');
        $this->getJson('/api/signals/icp/templates', $h)->assertOk();
        $this->postJson('/api/signals/icp/validate', ['config' => $this->validIcp()], $h)->assertOk();

        // no edit (not of the settings, not of the name), no re-pointing, no activation
        $this->putJson("/api/signals/icp/profiles/{$old->id}", ['config' => $this->validIcp(['product_line' => 'eb', 'label' => 'Changed'])], $h)->assertStatus(422)->assertJsonPath('code', 'product_level_icp_not_supported');
        $this->putJson("/api/signals/icp/profiles/{$old->id}", ['name' => 'Renamed'], $h)->assertStatus(422);
        $sub = IcpProfile::create(['sub_institute_id' => self::T, 'key' => 'sub_eb-01', 'name' => 'Sub', 'product_line' => 'offer_eb_01_b2b', 'status' => 'draft', 'is_default' => false, 'version' => 1, 'created_by' => 7,
            'config' => $this->validIcp(['product_line' => 'offer_eb_01_b2b'])]);
        $this->putJson("/api/signals/icp/profiles/{$sub->id}", ['config' => $this->validIcp(['product_line' => 'eb'])], $h)->assertStatus(422)->assertJsonPath('code', 'product_level_icp_not_supported');
        $other->update(['status' => 'draft']);
        $this->patchJson("/api/signals/icp/profiles/{$other->id}/status", ['status' => 'active'], $h)->assertStatus(422)->assertJsonPath('code', 'product_level_icp_not_supported');
        $this->assertSame('active', $old->fresh()->status, 'activating one did not archive another');

        // putting one away is still possible, and changes nothing else
        $this->patchJson("/api/signals/icp/profiles/{$other->id}/status", ['status' => 'archived'], $h)->assertOk();

        $fresh = $old->fresh();
        $this->assertSame([3, 7, 'Old EB', 'active'], [(int) $fresh->version, (int) $fresh->created_by, $fresh->name, $fresh->status], 'the historical row is exactly as it was');
        $this->assertSame('eb', $fresh->config['product_line']);
        $this->assertSame(3, IcpProfile::count());
    }

    public function test_another_organisations_profiles_are_not_reachable_through_the_generic_endpoints(): void
    {
        $theirs = $this->legacyProfile(self::T2);

        $this->getJson("/api/signals/icp/profiles/{$theirs->id}", $this->h(self::T))->assertStatus(404);
        $this->putJson("/api/signals/icp/profiles/{$theirs->id}", ['name' => 'x'], $this->h(self::T))->assertStatus(404);
        $this->patchJson("/api/signals/icp/profiles/{$theirs->id}/status", ['status' => 'archived'], $this->h(self::T))->assertStatus(404);
        $this->assertSame('active', $theirs->fresh()->status);
    }

    public function test_scheduled_runs_follow_the_same_sub_product_icp_ownership_rules(): void
    {
        // Old stored main-product ICP exists
        IcpProfile::create(['sub_institute_id' => self::T, 'key' => 'eb_old', 'name' => 'Old EB', 'product_line' => 'eb', 'status' => 'active', 'is_default' => true, 'version' => 1, 'created_by' => 1,
            'config' => $this->icp('Old', 'old search')]);
        // Sub-product exists but has no ICP
        $this->sub('EB-01', 'Knowledge Layer');

        // Scheduled run resolution fails because sub-products lack ICPs, ignoring the stored product ICP
        try {
            (new ProductCatalog())->resolve(self::T, 'enterprise_brain');
            $this->fail('scheduled run must fail when sub-products lack ICPs');
        } catch (ProductNotConfigured $e) {
            $this->assertStringContainsString('Knowledge Layer (EB-01): ICP not configured', implode(' ', $e->missing));
        }

        // Once sub-product has its own ICP, resolution succeeds and scheduled run uses it
        $this->sub('EB-02', 'Plant Dashboard', ['b2b' => $this->icp('Plant managers', 'plant equipment downtime')]);
        $ctx = (new ProductCatalog())->resolve(self::T, 'enterprise_brain');
        $this->assertSame(['plant equipment downtime'], array_column($ctx->pack->config()['queries'], 'q'));
        $this->assertSame(['EB-01'], array_column($ctx->withoutIcp, 'offer_id'), 'the unconfigured sub-product is reported explicitly');
    }
}
