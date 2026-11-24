<?php

namespace Tests\Feature;

use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * D-E4 of the products parity audit (slice 15, track WPB), the signal feed's half: `GET /api/signals/opportunities?product_master_id=` also finds the signals stamped with
 * one of the Product's ACTIVE Sub-products (Signals stamps a Sub-product on purpose when a research run was for one offer: ProductResolver::forSignal). A Sub-product's own
 * facet is an exact match, an archived Sub-product is not matched by its Product, and the Sub-products are the organisation's own and the shared ones. The lead list's half
 * is in LeadProductFacetChildrenTest. The world is the one of SignalFeedFiltersTest (the real signal migrations on in-memory sqlite).
 */
class SignalProductFacetChildrenTest extends TestCase
{
    private const T1 = 1;
    private const T2 = 2;

    private int $seq = 0;

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
            '2026_12_01_090000_add_product_key_to_signals_runs_and_sources', '2026_12_07_090000_create_g2g_product_master_table',
            '2026_12_08_090100_add_review_and_product_columns_to_g2g_company_opportunities',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }

        RoleKey::flushCache();
        Cache::flush();
        Carbon::setTestNow('2026-10-20 10:00:00');
        DB::table('tbluserprofilemaster')->insert([['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function h(int $tenant = self::T1): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => 1]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    private function master(int $tenant, string $kind, string $name, ?int $parent = null, string $status = 'active'): int
    {
        return (int) DB::table('g2g_product_master')->insertGetId([
            'sub_institute_id' => $tenant, 'product_key' => strtolower(str_replace(' ', '_', $name)) . '_' . uniqid(), 'kind' => $kind, 'name' => $name, 'status' => $status, 'parent_id' => $parent,
        ]);
    }

    private function sig(string $title, ?int $product, int $tenant = self::T1): int
    {
        $this->seq++;

        return (int) DB::table('g2g_company_opportunities')->insertGetId([
            'sub_institute_id' => $tenant, 'company_id' => 1, 'research_run_id' => null, 'title' => $title, 'category' => 'hiring', 'observed_event' => 'e', 'why_indicates_need' => 'w',
            'product_fit' => 'f', 'recommended_action' => 'a', 'priority' => 'Medium', 'qualification' => 'Monitoring', 'confidence' => 'Medium', 'review_status' => 'New', 'sources' => '[]',
            'fingerprint' => md5('fp' . $this->seq), 'first_discovered_at' => '2026-10-01 09:00:00', 'last_verified_at' => '2026-10-01 09:00:00', 'created_at' => '2026-10-01 09:00:00',
            'updated_at' => '2026-10-01 09:00:00', 'product_master_id' => $product,
        ]);
    }

    /** @return list<string> the titles a request returned, sorted */
    private function titles(string $query, int $tenant = self::T1): array
    {
        $titles = collect($this->getJson('/api/signals/opportunities?per_page=50&' . $query, $this->h($tenant))->assertOk()->json('data'))->pluck('title')->all();
        sort($titles);

        return $titles;
    }

    private function world(): array
    {
        $p = $this->master(self::T1, 'product', 'Alpha Product');
        $active = $this->master(self::T1, 'sub_product', 'Alpha Active Sub', $p);
        $archived = $this->master(self::T1, 'sub_product', 'Alpha Archived Sub', $p, 'archived');
        $q = $this->master(self::T1, 'product', 'Beta Product');
        $qSub = $this->master(self::T1, 'sub_product', 'Beta Sub', $q);
        $this->sig('AlphaDirect', $p);
        $this->sig('AlphaUnderActive', $active);
        $this->sig('AlphaUnderArchived', $archived);
        $this->sig('BetaDirect', $q);
        $this->sig('BetaUnderSub', $qSub);
        $this->sig('NothingAtAll', null);

        return compact('p', 'active', 'archived', 'q', 'qSub');
    }

    public function test_a_product_facet_finds_the_signals_of_the_product_and_of_its_active_sub_products(): void
    {
        $w = $this->world();

        $this->assertSame(['AlphaDirect', 'AlphaUnderActive'], $this->titles("product_master_id={$w['p']}"));
        $this->assertSame(['BetaDirect', 'BetaUnderSub'], $this->titles("product_master_id={$w['q']}"));
    }

    public function test_a_sub_product_facet_is_an_exact_match_also_for_an_archived_one(): void
    {
        $w = $this->world();

        $this->assertSame(['AlphaUnderActive'], $this->titles("product_master_id={$w['active']}"));
        $this->assertSame(['AlphaUnderArchived'], $this->titles("product_master_id={$w['archived']}"), 'an archived Sub-product keeps its own facet');
        $this->assertSame(['BetaUnderSub'], $this->titles("product_master_id={$w['qSub']}"));
    }

    public function test_an_archived_sub_product_is_not_matched_by_its_product_until_it_is_restored(): void
    {
        $w = $this->world();

        $this->assertNotContains('AlphaUnderArchived', $this->titles("product_master_id={$w['p']}"));
        DB::table('g2g_product_master')->where('id', $w['archived'])->update(['status' => 'active']);
        $this->assertSame(['AlphaDirect', 'AlphaUnderActive', 'AlphaUnderArchived'], $this->titles("product_master_id={$w['p']}"));
        // The feed without the facet, and a facet nobody holds, answer as before.
        $this->assertCount(6, $this->titles(''));
        $this->assertSame([], $this->titles('product_master_id=999999'));
    }

    public function test_the_sub_products_are_the_organisations_own_never_another_organisations_and_never_a_row_of_organisation_0(): void
    {
        // CHANGED ON PURPOSE (S15 follow-up, 24 Nov 2026): the Product and its Sub-product are the organisation's OWN (they were shared rows of organisation 0).
        $shared = $this->master(self::T1, 'product', 'Own Product');
        $sharedSub = $this->master(self::T1, 'sub_product', 'Own Sub', $shared);
        $leftover = $this->master(0, 'sub_product', 'Leftover Sub', $shared);
        $theirs = $this->master(self::T2, 'sub_product', 'Their Sub', $shared);
        $this->sig('OnShared', $shared);
        $this->sig('OnSharedSub', $sharedSub);
        $this->sig('OnLeftover', $leftover);
        $this->sig('PointsAtTheirSub', $theirs);                       // a forged pointer: this organisation may not see that Sub-product
        $this->sig('TheirSignal', $theirs, self::T2);

        $this->assertSame(['OnShared', 'OnSharedSub'], $this->titles("product_master_id={$shared}"));
        $this->assertSame(['PointsAtTheirSub'], $this->titles("product_master_id={$theirs}"), 'the exact match on the row itself is what it always was, this organisation\'s own signals');
        // The other organisation reaches its own Sub-product through the shared Product.
        $this->assertSame(['TheirSignal'], $this->titles("product_master_id={$shared}", self::T2));
    }

    public function test_another_organisations_signals_are_never_listed_under_a_facet(): void
    {
        $w = $this->world();
        $this->sig('TheirOnAlphaSub', $w['active'], self::T2);
        $this->sig('TheirOnAlpha', $w['p'], self::T2);

        $this->assertSame(['AlphaDirect', 'AlphaUnderActive'], $this->titles("product_master_id={$w['p']}"));
    }
}
