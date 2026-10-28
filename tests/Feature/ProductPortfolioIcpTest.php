<?php

namespace Tests\Feature;

use App\Domain\Portfolio\ProductOffer;
use App\Domain\Signals\Icp\IcpProfile;
use App\Domain\Signals\Icp\IcpProfileValidator;
use App\Domain\Signals\Products\IcpOfferComparator;
use App\Domain\Signals\Products\ProductCatalog;
use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The user-managed Product Portfolio and its link to an ICP: products are created, edited and removed by people through the API (tenant-scoped,
 * admin / HR for writes); an ICP lists the products it is for (none are added for the person); and the comparison of an ICP with a product is
 * deterministic and says "insufficient data" instead of guessing. Nothing here calls a provider.
 */
class ProductPortfolioIcpTest extends TestCase
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
            '2026_10_14_100000_create_g2g_icp_profiles_tables', '2026_10_13_100000_create_g2g_target_accounts_table',
            '2026_10_27_200000_add_icp_config_to_g2g_product_offers_table',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }

        RoleKey::flushCache();
        Cache::flush();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-20 10:00:00');
        DB::table('tbluserprofilemaster')->insert([
            ['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator'],
            ['id' => 2, 'name' => 'Employee', 'role_key' => 'employee'],
            ['id' => 3, 'name' => 'HR Manager', 'role_key' => 'hr_manager'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function h(int $tenant = self::T, int $profile = 1): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => $profile]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    /** The product fields a person fills in. */
    private function body(array $o = []): array
    {
        return $o + [
            'offer_id' => 'MY-01', 'name' => 'Fee Collection Suite', 'parent_product' => 'Scholar K-12', 'readiness_status' => 'In development',
            'what_it_does' => 'Online fee collection, receipts and parent reminders for schools.', 'modules_components' => 'Fee engine; receipts; reminders',
            'needs_solved' => ['N13'], 'primary_segments' => ['K12-PVT'], 'trigger_signals' => [], 'pricing_model' => 'Per student per year',
        ];
    }

    private function make(array $o = [], int $tenant = self::T): ProductOffer
    {
        return ProductOffer::create($this->body($o) + ['sub_institute_id' => $tenant, 'is_user_edited' => true]);
    }

    private function icpConfig(array $o = []): array
    {
        return $o + [
            'label' => 'Enterprise Brain for manufacturers', 'industry_label' => 'mid-size manufacturers', 'buyer_types' => ['manufacturer'], 'regions' => ['Gujarat'],
            'parts' => ['knowledge_layer' => 'one searchable memory across plants, documents and people'],
            'triggers' => ['new_plant' => ['source' => 'news', 'part' => 'knowledge_layer', 'decay' => 90, 'pain' => 'a new plant needs shared knowledge from day one', 'phrases' => ['new plant']]],
            'queries' => [['id' => 'eb-news-plant', 'source' => 'news', 'topic' => 'news', 'days' => 45, 'q' => 'manufacturer announces new plant Gujarat']],
            'per_run' => ['catalogue' => 1, 'accounts' => 0],
        ];
    }

    private function saveIcp(array $config, int $tenant = self::T, string $product = 'enterprise_brain', bool $activate = false)
    {
        return $this->putJson("/api/signals/icp/products/{$product}", ['config' => $config, 'activate' => $activate], $this->h($tenant));
    }

    // ── product CRUD ─────────────────────────────────────────────────────────

    public function test_a_product_is_created_viewed_edited_searched_and_deleted_through_the_api_and_persists(): void
    {
        $h = $this->h();

        $created = $this->postJson('/api/portfolio/offers', $this->body(), $h)->assertStatus(201);
        $this->assertSame(self::T, (int) DB::table('g2g_product_offers')->where('offer_id', 'MY-01')->value('sub_institute_id'), 'the organisation comes from the token');
        $this->assertSame('Fee Collection Suite', $this->getJson('/api/portfolio/offers/' . $created->json('data.id'), $h)->assertOk()->json('data.name'));

        $this->putJson('/api/portfolio/offers/MY-01', ['name' => 'Fee Suite Plus', 'pricing_model' => 'Per campus'], $h)->assertOk();
        $this->assertSame('Fee Suite Plus', DB::table('g2g_product_offers')->where('offer_id', 'MY-01')->value('name'));

        $this->assertCount(1, $this->getJson('/api/portfolio/offers?search=plus', $h)->assertOk()->json('data.data'));
        $this->assertCount(0, $this->getJson('/api/portfolio/offers?search=nothing-like-this', $h)->assertOk()->json('data.data'));
        $this->assertCount(1, $this->getJson('/api/portfolio/offers?parent_product=Scholar%20K-12', $h)->assertOk()->json('data.data'));

        $this->deleteJson('/api/portfolio/offers/MY-01', [], $h)->assertOk();
        $this->assertSame(0, DB::table('g2g_product_offers')->count());
    }

    public function test_adding_a_sub_product_with_its_own_icp_creates_an_offer_and_no_main_product_and_survives_edit_and_reload(): void
    {
        $h = $this->h();
        $mainBefore = collect($this->getJson('/api/portfolio/products', $h)->assertOk()->json('data.products'))->pluck('key')->all();
        $icp = ['label' => 'Scholar Learn ICP', 'industry_label' => 'private K-12 schools', 'customer_model' => 'b2b', 'buyer_types' => ['Private K-12 schools'], 'regions' => ['Gujarat'],
            'pitch' => 'Learning resources are scattered.'];

        $created = $this->postJson('/api/portfolio/offers', $this->body(['offer_id' => 'P3', 'name' => 'Scholar Learn', 'icp_config' => $icp]), $h)->assertCreated();
        $id = $created->json('data.id');

        // a SUB-product: it is an offer under the category, and no new main product appears
        $this->assertSame('Scholar K-12', $created->json('data.parent_product'));
        $this->assertSame($mainBefore, collect($this->getJson('/api/portfolio/products', $h)->json('data.products'))->pluck('key')->all());
        $names = collect($this->getJson('/api/portfolio/offers?per_page=100', $h)->json('data.data'))->pluck('name')->all();
        $this->assertContains('Scholar Learn', $names);

        // its ICP belongs to THIS sub-product (its Offer ID), reloads from storage, and is not another product's
        $shown = $this->getJson("/api/portfolio/offers/{$id}", $h)->assertOk();
        $this->assertSame('Scholar Learn ICP', $shown->json('data.individual_icp.label'));
        $this->assertSame(['P3'], $shown->json('data.individual_icp.offer_ids'));
        $this->assertSame(1, \App\Domain\Signals\Icp\IcpProfile::where('sub_institute_id', self::T)->where('key', 'sub_p3')->count());
        $this->assertSame(0, \App\Domain\Signals\Icp\IcpProfile::where('sub_institute_id', self::T)->where('product_line', 'scholar')->count(), 'the main product ICP is untouched');

        // editing keeps the same sub-product and the same single ICP (no duplicate)
        $this->putJson("/api/portfolio/offers/{$id}", ['name' => 'Scholar Learn', 'icp_config' => $icp + ['guidance' => 'Trustees decide.']], $h)->assertOk();
        $this->assertSame(1, ProductOffer::where('offer_id', 'P3')->count());
        $this->assertSame(1, \App\Domain\Signals\Icp\IcpProfile::where('sub_institute_id', self::T)->where('key', 'sub_p3')->count());
        $this->assertSame('Trustees decide.', $this->getJson("/api/portfolio/offers/{$id}", $h)->json('data.individual_icp.guidance'));

        // another sub-product whose ID differs only in its capital letter keeps ITS OWN ICP (upper-case IDs used to collapse into one stored ICP)
        $other = $this->postJson('/api/portfolio/offers', $this->body(['offer_id' => 'Q3', 'name' => 'Scholar Other', 'icp_config' => ['label' => 'Other ICP', 'industry_label' => 'trusts']]), $h)->assertCreated();
        $this->assertSame(2, \App\Domain\Signals\Icp\IcpProfile::where('sub_institute_id', self::T)->where('key', 'like', 'sub_%')->count());
        $this->assertSame('Scholar Learn ICP', $this->getJson("/api/portfolio/offers/{$id}", $h)->json('data.individual_icp.label'));
        $this->assertSame('Other ICP', $this->getJson('/api/portfolio/offers/' . $other->json('data.id'), $h)->json('data.individual_icp.label'));
    }

    public function test_an_icp_saved_under_the_older_key_is_still_shown_for_its_own_offer_only(): void
    {
        $h = $this->h();
        $p3 = $this->make(['offer_id' => 'P3', 'name' => 'Scholar Learn']);
        $q3 = $this->make(['offer_id' => 'Q3', 'name' => 'Scholar Other']);
        // saved by the older code: every capital letter of the ID had become an underscore, so P3 and Q3 shared the key "sub__3"
        \App\Domain\Signals\Icp\IcpProfile::create(['sub_institute_id' => self::T, 'key' => 'sub__3', 'name' => 'Legacy ICP', 'product_line' => 'offer__3', 'status' => 'active',
            'is_default' => false, 'version' => 1, 'config' => ['label' => 'Legacy ICP', 'offer_ids' => ['P3']]]);

        $this->assertSame('Legacy ICP', $this->getJson("/api/portfolio/offers/{$p3->id}", $h)->assertOk()->json('data.individual_icp.label'));
        $this->assertNull($this->getJson("/api/portfolio/offers/{$q3->id}", $h)->assertOk()->json('data.individual_icp'), 'another offer never gets it');
    }

    private function subIcp(array $o = []): array
    {
        return $o + ['label' => 'Fee suite ICP', 'industry_label' => 'private K-12 schools', 'customer_model' => 'b2b', 'buyer_types' => ['Private K-12 schools'], 'regions' => ['Gujarat'],
            'parts' => ['my_01' => 'online fee collection'],
            'triggers' => ['my_01_t1' => ['source' => 'news', 'part' => 'my_01', 'decay' => 90, 'pain' => 'School announces an online fee system', 'phrases' => ['online fee system']]],
            'queries' => [['id' => 'my_01-q1', 'source' => 'news', 'topic' => 'news', 'days' => 45, 'q' => 'school online fee collection Gujarat']],
            'disqualifiers' => [['label' => 'preschool', 'phrases' => ['preschool']]]];
    }

    public function test_a_run_for_one_sub_product_is_refused_with_the_reason_unless_it_is_this_products_and_has_a_usable_icp(): void
    {
        $h = $this->h();
        $this->postJson('/api/portfolio/offers', $this->body(['offer_id' => 'MY-01', 'name' => 'Fee Suite']), $h)->assertCreated();                       // no ICP
        $this->postJson('/api/portfolio/offers', $this->body(['offer_id' => 'MY-02', 'name' => 'Half Done', 'icp_config' => ['label' => 'x', 'industry_label' => 'schools']]), $h)->assertCreated();
        $this->postJson('/api/portfolio/offers', $this->body(['offer_id' => 'EB-09', 'name' => 'Brain Thing', 'parent_product' => 'EB', 'icp_config' => $this->subIcp()]), $h)->assertCreated();

        $run = fn (string $offer) => $this->postJson('/api/signals/research/run', ['product' => 'scholarclone', 'offer_id' => $offer], $h);
        $run('MY-01')->assertStatus(422)->assertJsonPath('code', 'sub_product_not_ready')->assertJsonFragment(['offer_id' => 'MY-01']);
        $this->assertStringContainsString('no Individual ICP', $run('MY-01')->json('message'));
        $this->assertContains('its ICP lists no buying triggers', $run('MY-02')->assertStatus(422)->json('missing'));
        $run('EB-09')->assertStatus(422)->assertJsonPath('code', 'unknown_sub_product');   // another product's offer is never accepted
        $run('NOPE-1')->assertStatus(422)->assertJsonPath('code', 'unknown_sub_product');

        // a usable one passes the sub-product checks (it then stops at the providers, which this test does not configure)
        $this->postJson('/api/portfolio/offers', $this->body(['offer_id' => 'MY-03', 'name' => 'Ready One', 'icp_config' => $this->subIcp()]), $h)->assertCreated();
        $this->assertNotContains($run('MY-03')->json('code'), ['sub_product_not_ready', 'unknown_sub_product']);
    }

    public function test_the_product_list_says_which_sub_products_have_a_usable_icp(): void
    {
        $h = $this->h();
        $this->postJson('/api/portfolio/offers', $this->body(['offer_id' => 'MY-01', 'name' => 'Fee Suite']), $h)->assertCreated();
        $this->postJson('/api/portfolio/offers', $this->body(['offer_id' => 'MY-03', 'name' => 'Ready One', 'icp_config' => $this->subIcp()]), $h)->assertCreated();

        $offers = collect($this->getJson('/api/portfolio/products', $h)->assertOk()->json('data.products'))->firstWhere('key', 'scholarclone')['offers'];
        $by = collect($offers)->keyBy('offer_id');
        $this->assertTrue($by['MY-03']['icp_ready']);
        $this->assertFalse($by['MY-01']['icp_ready']);
        $this->assertNotEmpty($by['MY-01']['icp_missing']);
    }

    public function test_a_sub_product_context_uses_its_own_icp_and_keeps_the_products_qualification_rules(): void
    {
        $h = $this->h();
        $this->postJson('/api/portfolio/offers', $this->body(['offer_id' => 'MY-03', 'name' => 'Ready One', 'icp_config' => $this->subIcp()]), $h)->assertCreated();
        $catalog = new \App\Domain\Signals\Products\ProductCatalog();

        $whole = $catalog->resolve(self::T, 'scholarclone');
        $sub = $catalog->resolveSub(self::T, 'scholarclone', 'MY-03');

        $this->assertSame(['offer_id' => 'MY-03', 'name' => 'Ready One'], $sub->subProduct);
        $this->assertNull($whole->subProduct);
        $c = $sub->pack->config();
        $this->assertSame(['my_01_t1'], array_keys($c['triggers']), 'only the sub-product\'s own triggers');
        $this->assertSame('school online fee collection Gujarat', $c['queries'][0]['q']);
        $this->assertSame('private K-12 schools', $c['industry_label']);
        // what decides a fit is the product's own, not replaced
        foreach (['evidence_rules', 'must_have', 'tiers', 'min_known_share'] as $k) {
            $this->assertEquals($whole->pack->config()[$k] ?? null, $c[$k] ?? null, $k);
        }
        $this->assertGreaterThanOrEqual(count($whole->pack->config()['disqualifiers'] ?? []), count($c['disqualifiers']), 'exclusions are never fewer');
        $this->assertStringContainsString('SUB-PRODUCT FOCUS', $sub->pack->promptBlock());
        $this->assertStringNotContainsString('SUB-PRODUCT FOCUS', $whole->pack->promptBlock());
        $this->assertSame(['my_01', 'my-03'], array_keys($sub->parts), 'only this offer can be what a signal is about');
    }

    public function test_only_administrators_and_hr_may_change_the_portfolio_but_anyone_signed_in_may_read_it(): void
    {
        $this->make();
        $employee = $this->h(self::T, 2);

        $this->postJson('/api/portfolio/offers', $this->body(['offer_id' => 'X-1']), $employee)->assertStatus(403);
        $this->putJson('/api/portfolio/offers/MY-01', ['name' => 'Changed'], $employee)->assertStatus(403);
        $this->deleteJson('/api/portfolio/offers/MY-01', [], $employee)->assertStatus(403);
        $this->getJson('/api/portfolio/offers', $employee)->assertOk();
        $this->postJson('/api/portfolio/offers', $this->body(['offer_id' => 'HR-1']), $this->h(self::T, 3))->assertStatus(201);
        $this->postJson('/api/portfolio/offers', $this->body())->assertStatus(401);
        $this->assertSame('Fee Collection Suite', DB::table('g2g_product_offers')->where('offer_id', 'MY-01')->value('name'));
    }

    public function test_an_organisation_cannot_see_change_or_delete_another_organisations_products(): void
    {
        $this->make(['offer_id' => 'T1-ONLY']);
        $other = $this->h(self::T2);

        $this->assertSame([], $this->getJson('/api/portfolio/offers', $other)->assertOk()->json('data.data'));
        $this->getJson('/api/portfolio/offers/T1-ONLY', $other)->assertStatus(404);
        $this->putJson('/api/portfolio/offers/T1-ONLY', ['name' => 'Hijacked'], $other)->assertStatus(404);
        $this->deleteJson('/api/portfolio/offers/T1-ONLY', [], $other)->assertStatus(404);
        $this->assertSame('Fee Collection Suite', DB::table('g2g_product_offers')->where('offer_id', 'T1-ONLY')->value('name'));
    }

    public function test_the_shared_foundation_rows_can_be_read_but_not_changed_or_deleted_from_an_organisation(): void
    {
        ProductOffer::create($this->body(['offer_id' => 'GLOBAL-1']) + ['sub_institute_id' => null]);
        $h = $this->h();

        $this->getJson('/api/portfolio/offers/GLOBAL-1', $h)->assertOk();
        $this->putJson('/api/portfolio/offers/GLOBAL-1', ['name' => 'Changed'], $h)->assertStatus(404);
        $this->deleteJson('/api/portfolio/offers/GLOBAL-1', [], $h)->assertStatus(404);
        $this->assertSame(1, DB::table('g2g_product_offers')->whereNull('sub_institute_id')->count());
    }

    public function test_an_offer_id_cannot_be_used_twice_and_required_fields_are_enforced(): void
    {
        $h = $this->h();
        $this->postJson('/api/portfolio/offers', $this->body(), $h)->assertStatus(201);

        $this->postJson('/api/portfolio/offers', $this->body(), $h)->assertStatus(409);
        $this->postJson('/api/portfolio/offers', ['offer_id' => 'NO-NAME'], $h)->assertStatus(422);
    }

    // ── deleting a product that an ICP uses ──────────────────────────────────

    public function test_a_product_an_icp_lists_is_not_deleted_behind_its_back_and_the_icp_survives(): void
    {
        $this->make();
        $this->saveIcp($this->icpConfig(['offer_ids' => ['MY-01']]))->assertOk();

        $blocked = $this->deleteJson('/api/portfolio/offers/MY-01', [], $this->h())->assertStatus(409);
        $this->assertSame('offer_in_use', $blocked->json('code'));
        $this->assertNotEmpty($blocked->json('used_by'));
        $this->assertSame(1, DB::table('g2g_product_offers')->count());

        $this->saveIcp($this->icpConfig(['offer_ids' => []]))->assertOk();   // the person takes it out of the ICP
        $this->deleteJson('/api/portfolio/offers/MY-01', [], $this->h())->assertOk();

        $this->assertSame(1, IcpProfile::count(), 'the ICP was never deleted');
    }

    // ── the ICP's own list of products ───────────────────────────────────────

    public function test_the_products_chosen_for_an_icp_are_saved_and_still_there_after_a_reload(): void
    {
        $this->make(['offer_id' => 'A-1', 'name' => 'Alpha']);
        $this->make(['offer_id' => 'B-1', 'name' => 'Beta']);
        $this->make(['offer_id' => 'C-1', 'name' => 'Gamma']);

        $this->saveIcp($this->icpConfig(['offer_ids' => ['A-1', 'C-1']]), activate: true)->assertOk();

        $this->assertSame(['A-1', 'C-1'], $this->getJson('/api/signals/icp/products/enterprise_brain', $this->h())->assertOk()->json('data.config.offer_ids'));
        $this->assertSame(['A-1', 'C-1'], $this->getJson('/api/portfolio/products/enterprise_brain', $this->h())->assertOk()->json('data.product.icp.offer_ids'));
    }

    public function test_choosing_no_products_is_saved_as_no_products_and_nothing_is_added_for_the_person(): void
    {
        $this->make(['offer_id' => 'EB-1', 'name' => 'Brain', 'parent_product' => 'EB']);
        $config = $this->icpConfig();
        unset($config['parts']);   // an ICP that names no parts of its own

        $this->saveIcp($config + ['offer_ids' => []])->assertOk();
        $this->assertSame([], $this->getJson('/api/signals/icp/products/enterprise_brain', $this->h())->assertOk()->json('data.config.offer_ids'), 'an empty choice is kept');
        $this->saveIcp($config + ['offer_ids' => []], activate: true)->assertStatus(422);   // nothing chosen and no parts: it cannot be activated, and says why

        IcpProfile::query()->update(['status' => 'active', 'is_default' => true]);   // as if it had been activated some other way
        $ctx = (new ProductCatalog())->describe(self::T, 'enterprise_brain');
        $this->assertSame([], $ctx['icp']['offer_ids']);
        $this->assertNotEmpty($ctx['missing'], 'with nothing chosen and no parts, the ICP says what is missing instead of silently using every product');
    }

    public function test_an_older_icp_without_a_list_keeps_using_the_products_of_its_portfolio_name(): void
    {
        $this->make(['offer_id' => 'EB-1', 'name' => 'Brain', 'parent_product' => 'EB']);
        $config = $this->icpConfig();
        unset($config['parts']);
        IcpProfile::create(['sub_institute_id' => self::T, 'key' => 'eb_manufacturing', 'name' => 'EB', 'product_line' => 'eb', 'status' => 'active', 'is_default' => true, 'version' => 1, 'config' => $config]);

        $d = (new ProductCatalog())->describe(self::T, 'enterprise_brain');

        $this->assertNull($d['icp']['offer_ids'], 'no list: the earlier behaviour');
        $this->assertTrue($d['ready'], 'its product still counts as its part');
    }

    public function test_only_the_chosen_products_become_the_icps_parts(): void
    {
        $this->make(['offer_id' => 'EB-1', 'name' => 'Knowledge Layer', 'parent_product' => 'EB', 'what_it_does' => 'One memory across plants.']);
        $this->make(['offer_id' => 'EB-2', 'name' => 'Plant Dashboard', 'parent_product' => 'EB', 'what_it_does' => 'Plant dashboards.']);
        $config = $this->icpConfig();
        unset($config['parts']);
        IcpProfile::create(['sub_institute_id' => self::T, 'key' => 'eb_manufacturing', 'name' => 'EB', 'product_line' => 'eb', 'status' => 'active', 'is_default' => true, 'version' => 1,
            'config' => $config + ['offer_ids' => ['EB-2']]]);

        $ctx = (new ProductCatalog())->resolve(self::T, 'enterprise_brain');

        $this->assertSame(['eb-2'], array_keys($ctx->parts), 'EB-1 was not chosen, so it is not used');
    }

    public function test_a_product_that_is_not_in_this_organisations_portfolio_cannot_be_attached(): void
    {
        $this->make(['offer_id' => 'OTHER-ORG'], self::T2);

        $r = $this->saveIcp($this->icpConfig(['offer_ids' => ['OTHER-ORG']]))->assertStatus(422);
        $this->assertStringContainsString('not a product in your Product Portfolio', json_encode($r->json('problems')));
        $this->saveIcp($this->icpConfig(['offer_ids' => ['A', 'A']]))->assertStatus(422);
        $this->saveIcp($this->icpConfig(['offer_ids' => ['bad id!']]))->assertStatus(422);
        $this->assertSame(0, IcpProfile::count());
        $this->assertSame([], (new IcpProfileValidator())->validate($this->icpConfig() + ['offer_ids' => []] + ['product_line' => 'eb']));
    }

    // ── comparing an ICP with a product ──────────────────────────────────────

    public function test_the_comparison_endpoint_scores_the_chosen_products_and_is_tenant_scoped(): void
    {
        $this->make(['offer_id' => 'EB-1', 'name' => 'Knowledge Layer', 'parent_product' => 'EB', 'what_it_does' => 'One searchable memory across plants, documents and people, shared from day one.',
            'primary_segments' => ['SME', 'ENTERPRISE'], 'needs_solved' => ['N10']]);
        $this->make(['offer_id' => 'OTHER', 'name' => 'Not mine'], self::T2);
        $this->saveIcp($this->icpConfig(['offer_ids' => ['EB-1']]))->assertOk();

        $saved = $this->getJson('/api/signals/icp/products/enterprise_brain/compare', $this->h())->assertOk();
        $this->assertSame('EB-1', $saved->json('data.results.0.offer.offer_id'));
        $this->assertFalse($saved->json('data.results.0.ai_used'));
        $this->assertNotNull($saved->json('data.method'));

        $other = $this->getJson('/api/signals/icp/products/enterprise_brain/compare?offer_ids=EB-1,OTHER', $this->h())->assertOk();
        $this->assertSame(['OTHER'], $other->json('data.unknown_offer_ids'), 'another organisation\'s product is not found, not compared');
        $this->assertCount(1, $other->json('data.results'));
        $this->assertSame([], $this->getJson('/api/signals/icp/products/enterprise_brain/compare?offer_ids=EB-1', $this->h(self::T2))->assertOk()->json('data.results'));
        $this->getJson('/api/signals/icp/products/enterprise_brain/compare', $this->h(self::T, 2))->assertStatus(403);
        $this->getJson('/api/signals/icp/products/enterprise_brain/compare?offer_ids=' . implode(',', range(1, 31)), $this->h())->assertStatus(422);
    }

    // ── the scoring itself ───────────────────────────────────────────────────

    private function offerRow(array $o = []): array
    {
        return $o + ['id' => 1, 'offer_id' => 'P-1', 'name' => 'Knowledge Layer', 'parent_product' => 'EB', 'readiness_status' => 'Live – with client', 'readiness_confirmed' => false,
            'what_it_does' => 'One searchable memory across plants, documents and people.', 'modules_components' => 'Search; document ingestion', 'proof_points' => null, 'notes' => null,
            'needs_solved' => ['N10'], 'primary_segments' => ['SME'], 'trigger_signals' => ['S-SME-FUND'], 'deployment_model' => 'SaaS (multi-tenant)', 'pricing_model' => null, 'typical_deal_band' => null];
    }

    private function icp(array $o = []): array
    {
        return $o + ['industry_label' => 'mid-size manufacturers', 'buyer_types' => ['SME manufacturer'], 'regions' => ['Gujarat'],
            'parts' => ['knowledge_layer' => 'one searchable memory across plants, documents and people', 'dashboards' => 'real time machine downtime dashboards'],
            'triggers' => ['funding_round' => ['pain' => 'expansion funding means new plants need shared knowledge', 'phrases' => ['funding']]]];
    }

    public function test_the_score_is_the_documented_weighted_mean_of_the_dimensions_with_data(): void
    {
        $r = (new IcpOfferComparator())->compare($this->icp(), $this->offerRow());
        $dim = fn (string $k) => collect($r['dimensions'])->firstWhere('key', $k);

        $this->assertSame('scored', $r['status']);
        $this->assertSame(100, $dim('segment_fit')['score'], 'SME manufacturer shares the word "SME" with the segment');
        $this->assertSame(33, $dim('need_coverage')['score'], 'of three requirements only the knowledge-layer part is covered');
        $this->assertNotNull($dim('trigger_alignment')['score']);
        $weights = array_column($r['dimensions'], 'weight', 'key');
        $have = array_filter($r['dimensions'], fn ($d) => $d['score'] !== null);
        $expected = (int) round(array_sum(array_map(fn ($d) => $d['score'] * $d['weight'], $have)) / array_sum(array_column($have, 'weight')));
        $this->assertSame($expected, $r['score']);
        $this->assertSame(['segment_fit' => 30, 'need_coverage' => 40, 'trigger_alignment' => 20], $weights);
        $this->assertFalse($r['ai_used']);
    }

    public function test_matches_and_gaps_come_with_the_words_that_support_them(): void
    {
        $r = (new IcpOfferComparator())->compare($this->icp(), $this->offerRow());
        $need = collect($r['dimensions'])->firstWhere('key', 'need_coverage');

        $this->assertNotEmpty($need['evidence']);
        $this->assertStringContainsString('shared:', $need['evidence'][0]);
        $this->assertCount(2, $need['missing']);
        $this->assertStringContainsString('downtime dashboards', implode(' ', $need['missing']));
        $this->assertNotEmpty(array_filter($r['recommendations'], fn ($t) => str_contains($t, 'downtime dashboards')));
        $this->assertSame('insufficient_data', $r['market']['geography_status'], 'a product record has no region, so it is never scored');
    }

    public function test_missing_data_gives_insufficient_data_and_never_a_made_up_score(): void
    {
        $bare = $this->offerRow(['what_it_does' => null, 'modules_components' => null, 'needs_solved' => [], 'primary_segments' => [], 'trigger_signals' => []]);

        $r = (new IcpOfferComparator())->compare($this->icp(), $bare);

        $this->assertSame('insufficient_data', $r['status']);
        $this->assertNull($r['score']);
        $this->assertStringContainsString('Insufficient data', $r['summary']);
        $this->assertTrue(collect($r['dimensions'])->every(fn ($d) => $d['score'] === null));
        $this->assertNotEmpty($r['recommendations']);

        $emptyIcp = (new IcpOfferComparator())->compare(['industry_label' => 'x'], $this->offerRow());
        $this->assertSame('insufficient_data', $emptyIcp['status'], 'an ICP with nothing to compare cannot be scored either');
    }

    public function test_a_score_needs_need_coverage_plus_one_other_dimension(): void
    {
        $onlyNeeds = $this->offerRow(['primary_segments' => [], 'trigger_signals' => []]);

        $r = (new IcpOfferComparator())->compare($this->icp(), $onlyNeeds);

        $this->assertSame('insufficient_data', $r['status'], 'one dimension alone is not enough to call it a match');
        $this->assertNull($r['score']);
    }

    public function test_the_same_input_always_gives_the_same_result(): void
    {
        $a = (new IcpOfferComparator())->compare($this->icp(), $this->offerRow());
        $b = (new IcpOfferComparator())->compare($this->icp(), $this->offerRow());

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_readiness_and_price_are_shown_as_facts_and_do_not_move_the_score(): void
    {
        $live = (new IcpOfferComparator())->compare($this->icp(), $this->offerRow(['readiness_status' => 'Live – with client', 'pricing_model' => 'X']));
        $idea = (new IcpOfferComparator())->compare($this->icp(), $this->offerRow(['readiness_status' => 'Proposal / concept', 'pricing_model' => null]));

        $this->assertSame($live['score'], $idea['score']);
        $this->assertSame('X', $live['facts']['pricing_model']);
        $this->assertNull($idea['facts']['pricing_model'], 'no price is invented');
        $this->assertNotEmpty(array_filter($idea['recommendations'], fn ($t) => str_contains($t, 'has not been confirmed')));
    }
}
