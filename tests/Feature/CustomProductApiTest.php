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

        $this->putJson('/api/signals/icp/products/payroll_cloud', ['config' => $this->icpConfig(), 'activate' => true], $this->h())->assertOk();
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
        $this->getJson('/api/signals/icp/products/p3', $this->h())->assertOk();
        $this->getJson('/api/signals/icp/products/prod_p3', $this->h())->assertOk();
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
        $this->putJson("/api/signals/icp/products/{$key}", ['config' => $config, 'name' => 'Scholar Learn ICP', 'activate' => false], $this->h())->assertOk();
        $this->assertSame('prod_p3', IcpProfile::where('sub_institute_id', self::T)->sole()->product_line);
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
        $this->getJson('/api/signals/icp/products/payroll_cloud', $this->h(self::T2))->assertStatus(404);
        $this->putJson('/api/signals/icp/products/payroll_cloud', ['config' => $this->icpConfig()], $this->h(self::T2))->assertStatus(404);

        $this->postJson('/api/signals/icp/products', ['key' => 'payroll_cloud', 'label' => 'Payroll Cloud'], $this->h(self::T2))->assertCreated();
        $this->assertSame(2, DB::table('g2g_signal_products')->count());
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
        $this->putJson('/api/signals/icp/products/payroll_cloud', ['config' => $this->icpConfig(), 'activate' => true], $this->h())->assertOk();
        ProductCatalog::forgetCustom();

        $company = DB::table('g2g_companies')->insertGetId(['sub_institute_id' => self::T, 'name' => 'Acme Plastics', 'created_at' => now(), 'updated_at' => now()]);
        $opp = DB::table('g2g_company_opportunities')->insertGetId([
            'sub_institute_id' => self::T, 'company_id' => $company, 'title' => 'New plant', 'category' => 'expansion', 'observed_event' => 'announced a new plant',
            'why_indicates_need' => 'x', 'product_fit' => 'x', 'recommended_action' => 'x', 'priority' => 'Medium', 'qualification' => 'Qualified', 'confidence' => 'Medium',
            'review_status' => 'New', 'business_fit' => 'payroll_cloud', 'first_discovered_at' => now(), 'last_verified_at' => now(),
        ]);

        $r = $this->getJson("/api/signals/opportunities/{$opp}/icp-comparison", $this->h())->assertOk()->json('data');
        $this->assertTrue($r['available']);
        $this->assertSame('payroll_cloud', $r['product']['key']);
        $statuses = collect($r['criteria'])->pluck('status', 'criterion');
        $this->assertSame('Needs review', $statuses['staff >= 50'], 'no account is linked: a missing fact is never a match');
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
