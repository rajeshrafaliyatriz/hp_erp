<?php

namespace Tests\Feature;

use App\Domain\Signals\Opportunities\Company;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `scope_filter` on the opportunity list: the four selectors of Company Opportunities (main product, sub-product, ICP type, region) filter the list
 * independently and inclusively, instead of matching one exact run scope. The summary counts and the pages (the export reads them) follow the same
 * filter. Runs on the in-memory sqlite database.
 */
class OpportunityScopeFilterTest extends TestCase
{
    private const T1 = 1;
    private const T2 = 2;
    private const URL = '/api/signals/opportunities?scope_filter=1&expired=include&per_page=50';

    protected function setUp(): void
    {
        parent::setUp();
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
            '2026_10_31_090000_add_research_run_configuration_scope', '2026_12_07_090000_add_scope_to_g2g_company_opportunities',
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

    private function headers(int $tenant = self::T1): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'user_' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => 1]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('test')->plainTextToken];
    }

    /** @param list<array<string, string>>|null $places the region_scope exactly as a run stores it (country, state, city, in that order) */
    private function opp(string $title, string $product, ?string $offer, ?string $model, ?array $places, int $tenant = self::T1): CompanyOpportunity
    {
        $company = Company::firstOrCreate(['sub_institute_id' => $tenant, 'normalized_name' => strtolower($title)], ['name' => $title]);
        $region = $places === null ? null : json_encode(array_map(fn ($p) => ['country' => $p[0], 'state' => $p[1] ?? null, 'city' => $p[2] ?? null], $places), JSON_UNESCAPED_UNICODE);

        $opp = new CompanyOpportunity([
            'sub_institute_id' => $tenant, 'company_id' => $company->id, 'product_key' => $product, 'business_fit' => $product,
            'offer_id' => $offer, 'customer_model' => $model, 'region_scope' => $region, 'title' => $title, 'category' => 'expansion',
            'observed_event' => $title . ' expanding', 'why_indicates_need' => 'Needs it', 'product_fit' => 'Fits', 'recommended_action' => 'Call',
            'priority' => 'High', 'qualification' => 'qualified', 'confidence' => 'High', 'sources' => [], 'fingerprint' => hash('sha256', $title . uniqid()),
        ]);
        $opp->timestamps = false;
        $opp->created_at = $opp->updated_at = $opp->first_discovered_at = Carbon::parse('2026-10-20 09:00:00');
        $opp->save();

        return $opp;
    }

    /** @return list<int> */
    private function ids(string $query, int $tenant = self::T1): array
    {
        $res = $this->withHeaders($this->headers($tenant))->getJson(self::URL . $query);
        $res->assertOk();

        return collect($res->json('data'))->pluck('id')->sort()->values()->all();
    }

    /** @param list<CompanyOpportunity> $rows @return list<int> */
    private function idsOf(array $rows): array
    {
        return collect($rows)->pluck('id')->sort()->values()->all();
    }

    public function test_the_sub_product_filters_the_list(): void
    {
        $a = $this->opp('A', 'scholarclone', 'SCH-01', 'b2b', null);
        $this->opp('B', 'scholarclone', 'SCH-02', 'b2b', null);
        $this->opp('C', 'scholarclone', null, 'b2b', null);

        $this->assertSame($this->idsOf([$a]), $this->ids('&product=scholarclone&offer_id=SCH-01'));
    }

    public function test_the_icp_type_filters_the_list(): void
    {
        $b2b = $this->opp('A', 'scholarclone', 'SCH-01', 'b2b', null);
        $b2g = $this->opp('B', 'scholarclone', 'SCH-01', 'b2g', null);
        $this->opp('C', 'scholarclone', 'SCH-01', null, null);   // no stored model: not in a model filter

        $this->assertSame($this->idsOf([$b2b]), $this->ids('&product=scholarclone&customer_model=b2b'));
        $this->assertSame($this->idsOf([$b2g]), $this->ids('&product=scholarclone&customer_model=b2g'));
    }

    public function test_a_region_matches_by_country(): void
    {
        $gujarat = $this->opp('A', 'scholarclone', null, 'b2b', [['India', 'Gujarat']]);
        $goa = $this->opp('B', 'scholarclone', null, 'b2b', [['India', 'Goa']]);
        $india = $this->opp('C', 'scholarclone', null, 'b2b', [['India']]);
        $this->opp('D', 'scholarclone', null, 'b2b', [['Germany']]);
        $this->opp('E', 'scholarclone', null, 'b2b', null);   // All Regions: not in a region filter

        $this->assertSame($this->idsOf([$gujarat, $goa, $india]), $this->ids('&product=scholarclone&regions[0][country]=India'));
    }

    public function test_a_region_matches_by_state_and_overlaps_when_a_record_has_several_places(): void
    {
        $gujarat = $this->opp('A', 'scholarclone', null, 'b2b', [['India', 'Gujarat']]);
        $surat = $this->opp('B', 'scholarclone', null, 'b2b', [['India', 'Gujarat', 'Surat']]);
        $both = $this->opp('C', 'scholarclone', null, 'b2b', [['India', 'Goa'], ['India', 'Gujarat']]);
        $this->opp('D', 'scholarclone', null, 'b2b', [['India', 'Goa']]);
        $this->opp('E', 'scholarclone', null, 'b2b', [['Germany', 'Gujarat']]);   // the same state name in another country

        $this->assertSame(
            $this->idsOf([$gujarat, $surat, $both]),
            $this->ids('&product=scholarclone&regions[0][country]=India&regions[0][state]=Gujarat'),
        );
    }

    public function test_two_chosen_places_are_alternatives_not_a_requirement_of_both(): void
    {
        $gujarat = $this->opp('A', 'scholarclone', null, 'b2b', [['India', 'Gujarat']]);
        $germany = $this->opp('B', 'scholarclone', null, 'b2b', [['Germany']]);
        $this->opp('C', 'scholarclone', null, 'b2b', [['India', 'Goa']]);

        $this->assertSame(
            $this->idsOf([$gujarat, $germany]),
            $this->ids('&product=scholarclone&regions[0][country]=India&regions[0][state]=Gujarat&regions[1][country]=Germany'),
        );
    }

    public function test_no_regions_means_no_region_filter(): void
    {
        $a = $this->opp('A', 'scholarclone', null, 'b2b', [['India', 'Gujarat']]);
        $b = $this->opp('B', 'scholarclone', null, 'b2b', null);

        $this->assertSame($this->idsOf([$a, $b]), $this->ids('&product=scholarclone'));
    }

    public function test_all_products_with_an_icp_type_filters_across_products(): void
    {
        $sc = $this->opp('A', 'scholarclone', 'SCH-01', 'b2b', null);
        $eb = $this->opp('B', 'enterprise_brain', 'EB-01', 'b2b', null);
        $this->opp('C', 'enterprise_brain', 'EB-01', 'b2g', null);

        $this->assertSame($this->idsOf([$sc, $eb]), $this->ids('&product=all&customer_model=b2b'));
    }

    public function test_the_summary_counts_and_every_page_follow_the_same_filter(): void
    {
        $this->opp('A', 'scholarclone', 'SCH-01', 'b2b', [['India', 'Gujarat']]);
        $this->opp('B', 'scholarclone', 'SCH-01', 'b2b', [['India', 'Goa']]);
        $this->opp('C', 'scholarclone', 'SCH-01', 'b2b', [['India', 'Gujarat', 'Surat']]);
        $this->opp('D', 'scholarclone', 'SCH-01', 'b2g', [['India', 'Gujarat']]);
        $this->opp('E', 'scholarclone', 'SCH-02', 'b2b', [['India', 'Gujarat']]);

        $filter = '&product=scholarclone&offer_id=SCH-01&customer_model=b2b&regions[0][country]=India&regions[0][state]=Gujarat';
        $h = $this->headers();
        $first = $this->withHeaders($h)->getJson(self::URL . $filter . '&per_page=1&page=1')->assertOk();
        $second = $this->withHeaders($h)->getJson(self::URL . $filter . '&per_page=1&page=2')->assertOk();
        $third = $this->withHeaders($h)->getJson(self::URL . $filter . '&per_page=1&page=3')->assertOk();

        $this->assertSame(2, $first->json('summary.total'), 'the tiles count the filtered list');
        $this->assertSame(2, $first->json('meta.total'));
        $this->assertSame(2, $first->json('meta.last_page'), 'the export reads these pages: two pages of one, then nothing');
        $this->assertCount(1, $first->json('data'));
        $this->assertCount(1, $second->json('data'));
        $this->assertCount(0, $third->json('data'));
        $this->assertNotSame($first->json('data.0.id'), $second->json('data.0.id'));
    }

    public function test_another_organisations_rows_are_never_in_the_list(): void
    {
        $mine = $this->opp('A', 'scholarclone', 'SCH-01', 'b2b', [['India', 'Gujarat']]);
        $this->opp('B', 'scholarclone', 'SCH-01', 'b2b', [['India', 'Gujarat']], self::T2);

        $this->assertSame($this->idsOf([$mine]), $this->ids('&product=all&customer_model=b2b&regions[0][country]=India'));
    }

    public function test_an_icp_type_that_is_not_one_of_the_three_is_refused_in_words(): void
    {
        $res = $this->withHeaders($this->headers())->getJson(self::URL . '&product=scholarclone&customer_model=b2x');

        $res->assertStatus(422)->assertJson(['status' => 0, 'code' => 'invalid_customer_model', 'message' => 'Choose B2B, B2C, or B2G.']);
    }

    public function test_without_scope_filter_the_list_is_unchanged(): void
    {
        $b2b = $this->opp('A', 'scholarclone', 'SCH-01', 'b2b', [['India', 'Gujarat']]);
        $b2g = $this->opp('B', 'scholarclone', 'SCH-02', 'b2g', [['Germany']]);
        $h = $this->headers();
        $base = '/api/signals/opportunities?expired=include&per_page=50';

        // All products ignored the scope before and still does; all_signals=1 ignores it for one product.
        $all = $this->withHeaders($h)->getJson($base . '&product=all&customer_model=b2b&offer_id=SCH-01')->assertOk();
        $this->assertSame($this->idsOf([$b2b, $b2g]), collect($all->json('data'))->pluck('id')->sort()->values()->all());
        $every = $this->withHeaders($h)->getJson($base . '&product=scholarclone&all_signals=1&customer_model=b2b')->assertOk();
        $this->assertSame($this->idsOf([$b2b, $b2g]), collect($every->json('data'))->pluck('id')->sort()->values()->all());
        // One product without all_signals still matches the exact run scope (here: b2b, SCH-01, India/Gujarat), not an overlap.
        $exact = $this->withHeaders($h)->getJson($base . '&product=scholarclone&customer_model=b2b&offer_id=SCH-01&regions[0][country]=India')->assertOk();
        $this->assertSame([], $exact->json('data'), 'a country alone is not the exact run scope India/Gujarat');
    }
}
