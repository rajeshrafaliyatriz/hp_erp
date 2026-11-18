<?php

namespace Tests\Feature;

use App\Domain\Portfolio\ProductOffer;
use App\Domain\Signals\Opportunities\Company;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Opportunities\ResearchRun;
use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression test suite for All Signals Daily Inbox:
 * 1. Aggregates multiple runs on the same day with different sub-products, customer models, and regions.
 * 2. Manual entries appear in the same inbox even when scope fields are null.
 * 3. Specific-scope filters still narrow results outside All Signals mode.
 * 4. The selected date is applied consistently to research and manual records.
 * 5. Historical records are not falsely assigned missing metadata.
 * 6. The selected main-product boundary is preserved unless All Products is explicitly selected.
 * 7. Tenant isolation is preserved.
 * 8. Stored run attribution is never mutated by queries.
 * 9. Inbox querying is read-only and never triggers research generation.
 */
class AllSignalsDailyInboxTest extends TestCase
{
    private const T1 = 1;
    private const T2 = 2;

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
            '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables',
            '2026_09_30_180000_add_signal_fields_to_ingestion_findings',
            '2026_09_30_200000_add_research_sources_and_signal_kind',
            '2026_09_30_220000_create_g2g_portfolio_and_partners_tables',
            '2026_09_30_230000_add_claude_feed_and_linking_fields',
            '2026_10_01_130000_add_stage_to_g2g_research_runs',
            '2026_10_08_100000_extend_g2g_signals_for_market_import',
            '2026_11_29_090000_add_assessment_to_g2g_company_opportunities',
            '2026_12_01_090000_add_product_key_to_signals_runs_and_sources',
            '2026_10_14_100000_create_g2g_icp_profiles_tables',
            '2026_10_31_090000_add_research_run_configuration_scope',
            '2026_12_07_090000_add_scope_to_g2g_company_opportunities',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }

        RoleKey::flushCache();
        Cache::flush();
        Carbon::setTestNow('2026-10-20 10:00:00');

        DB::table('tbluserprofilemaster')->insert([
            ['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator'],
        ]);

        ProductProfile::create([
            'sub_institute_id' => self::T1,
            'product_name' => 'ScholarClone',
            'description' => 'School ERP',
            'problems_solved' => 'School administration',
            'features' => 'ERP, LMS',
            'target_industries' => ['Education'],
            'target_markets' => ['Gujarat'],
            'keywords' => ['school erp'],
            'excluded' => [],
            'research_enabled' => true,
            'research_frequency' => 'daily',
            'recency_days' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function headers(int $tenant = self::T1): array
    {
        $id = DB::table('tbluser')->insertGetId([
            'user_name' => 'user_' . uniqid(),
            'sub_institute_id' => $tenant,
            'user_profile_id' => 1,
        ]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('test')->plainTextToken];
    }

    private function createRun(int $tenant, string $product, ?string $offerId, string $model, ?array $regions, string $date = '2026-10-20'): ResearchRun
    {
        $selections = $regions !== null ? \App\Domain\Signals\Support\RegionScope::fromInput($regions)?->selections() : null;
        $regionJson = \App\Domain\Signals\Opportunities\ResearchRunScope::regionJson($selections);
        $scopeKey = \App\Domain\Signals\Opportunities\ResearchRunScope::key($tenant, $product, $offerId, $model, $selections);

        $run = new ResearchRun([
            'sub_institute_id' => $tenant,
            'trigger' => 'manual',
            'product_key' => $product,
            'offer_id' => $offerId,
            'customer_model' => $model,
            'region_scope' => $regionJson,
            'scope_key' => $scopeKey,
            'report_date' => $date,
            'status' => 'success',
            'started_at' => Carbon::parse($date . ' 09:00:00'),
            'completed_at' => Carbon::parse($date . ' 09:05:00'),
            'opportunities_qualified' => 1,
            'queries_run' => 1,
            'queries_failed' => 0,
            'sources_found' => 1,
            'companies_researched' => 1,
            'opportunities_rejected' => 0,
            'stage' => 'completed',
        ]);
        $run->timestamps = false;
        $run->created_at = Carbon::parse($date . ' 09:00:00');
        $run->updated_at = Carbon::parse($date . ' 09:00:00');
        $run->save();

        return $run;
    }

    private function createOpportunity(int $tenant, string $product, ?int $runId, ?string $offerId, ?string $model, ?array $regions, string $title, ?string $importSource = null, string $date = '2026-10-20'): CompanyOpportunity
    {
        $company = Company::firstOrCreate(
            ['sub_institute_id' => $tenant, 'normalized_name' => strtolower($title)],
            ['name' => $title, 'created_at' => Carbon::parse($date . ' 09:00:00')]
        );

        $regionJson = $regions !== null ? \App\Domain\Signals\Opportunities\ResearchRunScope::regionJson(\App\Domain\Signals\Support\RegionScope::fromInput($regions)?->selections()) : null;

        $opp = new CompanyOpportunity([
            'sub_institute_id' => $tenant,
            'company_id' => $company->id,
            'product_key' => $product,
            'business_fit' => $product,
            'research_run_id' => $runId,
            'offer_id' => $offerId,
            'customer_model' => $model,
            'region_scope' => $regionJson,
            'title' => $title,
            'category' => 'expansion',
            'observed_event' => $title . ' expanding operations',
            'why_indicates_need' => 'Needs school management system',
            'product_fit' => 'ERP covers needs',
            'recommended_action' => 'Contact principal',
            'priority' => 'High',
            'qualification' => 'qualified',
            'confidence' => 'High',
            'sources' => [],
            'fingerprint' => hash('sha256', $title . uniqid()),
            'import_source' => $importSource,
        ]);
        $opp->timestamps = false;
        $opp->created_at = Carbon::parse($date . ' 09:00:00');
        $opp->updated_at = Carbon::parse($date . ' 09:00:00');
        $opp->first_discovered_at = Carbon::parse($date . ' 09:00:00');
        $opp->save();

        return $opp;
    }

    /**
     * Requirement 1: All Signals aggregates multiple runs on the same day with different sub-products, customer models and regions.
     */
    public function test_all_signals_aggregates_multiple_runs_on_same_day(): void
    {
        $h = $this->headers(self::T1);

        $run1 = $this->createRun(self::T1, 'scholarclone', 'SCH-01', 'b2b', [['country' => 'India', 'state' => 'Gujarat']]);
        $opp1 = $this->createOpportunity(self::T1, 'scholarclone', $run1->id, 'SCH-01', 'b2b', [['country' => 'India', 'state' => 'Gujarat']], 'Opp B2B Gujarat');

        $run2 = $this->createRun(self::T1, 'scholarclone', 'SCH-02', 'b2c', [['country' => 'India', 'state' => 'Maharashtra']]);
        $opp2 = $this->createOpportunity(self::T1, 'scholarclone', $run2->id, 'SCH-02', 'b2c', [['country' => 'India', 'state' => 'Maharashtra']], 'Opp B2C Maharashtra');

        $run3 = $this->createRun(self::T1, 'scholarclone', null, 'b2g', [['country' => 'India', 'state' => 'Delhi']]);
        $opp3 = $this->createOpportunity(self::T1, 'scholarclone', $run3->id, null, 'b2g', [['country' => 'India', 'state' => 'Delhi']], 'Opp B2G Delhi');

        $res = $this->withHeaders($h)->getJson('/api/signals/opportunities?product=scholarclone&run_date=2026-10-20&all_signals=1');
        $res->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($opp1->id, $ids);
        $this->assertContains($opp2->id, $ids);
        $this->assertContains($opp3->id, $ids);
    }

    /**
     * Requirement 2: Manual entries appear in the same inbox even when scope fields are null.
     */
    public function test_manual_entries_appear_in_all_signals_with_null_scope(): void
    {
        $h = $this->headers(self::T1);

        $run1 = $this->createRun(self::T1, 'scholarclone', 'SCH-01', 'b2b', null);
        $oppGen = $this->createOpportunity(self::T1, 'scholarclone', $run1->id, 'SCH-01', 'b2b', null, 'Generated Lead');

        $oppMan = $this->createOpportunity(self::T1, 'scholarclone', null, null, null, null, 'Manual Lead Without Scope', 'manual');

        $res = $this->withHeaders($h)->getJson('/api/signals/opportunities?product=scholarclone&run_date=2026-10-20&all_signals=1');
        $res->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($oppGen->id, $ids);
        $this->assertContains($oppMan->id, $ids);
    }

    /**
     * Requirement 3: Specific-scope filters still narrow results outside All Signals mode.
     */
    public function test_specific_scope_filters_narrow_results_outside_all_signals(): void
    {
        $h = $this->headers(self::T1);

        $run1 = $this->createRun(self::T1, 'scholarclone', 'SCH-01', 'b2b', [['country' => 'India', 'state' => 'Gujarat']]);
        $opp1 = $this->createOpportunity(self::T1, 'scholarclone', $run1->id, 'SCH-01', 'b2b', [['country' => 'India', 'state' => 'Gujarat']], 'Target Scope');

        $run2 = $this->createRun(self::T1, 'scholarclone', 'SCH-02', 'b2c', [['country' => 'India', 'state' => 'Maharashtra']]);
        $opp2 = $this->createOpportunity(self::T1, 'scholarclone', $run2->id, 'SCH-02', 'b2c', [['country' => 'India', 'state' => 'Maharashtra']], 'Other Scope');

        // Request specific scope: b2b, SCH-01, Gujarat, all_signals not set
        $res = $this->withHeaders($h)->getJson('/api/signals/opportunities?product=scholarclone&run_date=2026-10-20&customer_model=b2b&offer_id=SCH-01&regions[0][country]=India&regions[0][state]=Gujarat');
        $res->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($opp1->id, $ids);
        $this->assertNotContains($opp2->id, $ids);
    }

    /**
     * Requirement 4: The selected date is applied consistently to research and manual records.
     */
    public function test_selected_date_applied_consistently_to_research_and_manual(): void
    {
        $h = $this->headers(self::T1);

        $runOct20 = $this->createRun(self::T1, 'scholarclone', null, 'b2b', null, '2026-10-20');
        $oppOct20Gen = $this->createOpportunity(self::T1, 'scholarclone', $runOct20->id, null, 'b2b', null, 'Oct 20 Gen', null, '2026-10-20');
        $oppOct20Man = $this->createOpportunity(self::T1, 'scholarclone', null, null, 'b2b', null, 'Oct 20 Man', 'manual', '2026-10-20');

        $runOct19 = $this->createRun(self::T1, 'scholarclone', null, 'b2b', null, '2026-10-19');
        $oppOct19Gen = $this->createOpportunity(self::T1, 'scholarclone', $runOct19->id, null, 'b2b', null, 'Oct 19 Gen', null, '2026-10-19');

        $res20 = $this->withHeaders($h)->getJson('/api/signals/opportunities?product=scholarclone&run_date=2026-10-20&all_signals=1');
        $res20->assertOk();
        $ids20 = collect($res20->json('data'))->pluck('id')->all();
        $this->assertContains($oppOct20Gen->id, $ids20);
        $this->assertContains($oppOct20Man->id, $ids20);
        $this->assertNotContains($oppOct19Gen->id, $ids20);

        $res19 = $this->withHeaders($h)->getJson('/api/signals/opportunities?product=scholarclone&run_date=2026-10-19&all_signals=1');
        $res19->assertOk();
        $ids19 = collect($res19->json('data'))->pluck('id')->all();
        $this->assertContains($oppOct19Gen->id, $ids19);
        $this->assertNotContains($oppOct20Gen->id, $ids19);
        $this->assertNotContains($oppOct20Man->id, $ids19);
    }

    /**
     * Requirement 5: Historical records are not falsely assigned missing metadata.
     */
    public function test_historical_records_return_honest_null_metadata(): void
    {
        $h = $this->headers(self::T1);

        $run = $this->createRun(self::T1, 'scholarclone', null, 'b2b', null, '2026-10-20');
        $opp = $this->createOpportunity(self::T1, 'scholarclone', $run->id, null, null, null, 'Historical Record', null, '2026-10-20');

        $res = $this->withHeaders($h)->getJson('/api/signals/opportunities?product=scholarclone&run_date=2026-10-20&all_signals=1');
        $res->assertOk();

        $item = collect($res->json('data'))->firstWhere('id', $opp->id);
        $this->assertNotNull($item);
        $this->assertNull($item['offer_id']);
        $this->assertNull($item['customer_model']);
        $this->assertNull($item['region_scope']);
    }

    /**
     * Requirement 6: Selected main-product boundary is preserved unless All Products is explicitly selected.
     */
    public function test_main_product_boundary_preserved(): void
    {
        $h = $this->headers(self::T1);

        $runSC = $this->createRun(self::T1, 'scholarclone', null, 'b2b', null, '2026-10-20');
        $oppSC = $this->createOpportunity(self::T1, 'scholarclone', $runSC->id, null, 'b2b', null, 'SC Lead', null, '2026-10-20');

        $runEB = $this->createRun(self::T1, 'enterprise_brain', null, 'b2b', null, '2026-10-20');
        $oppEB = $this->createOpportunity(self::T1, 'enterprise_brain', $runEB->id, null, 'b2b', null, 'EB Lead', null, '2026-10-20');

        // Product = scholarclone
        $resSC = $this->withHeaders($h)->getJson('/api/signals/opportunities?product=scholarclone&run_date=2026-10-20&all_signals=1');
        $resSC->assertOk();
        $idsSC = collect($resSC->json('data'))->pluck('id')->all();
        $this->assertContains($oppSC->id, $idsSC);
        $this->assertNotContains($oppEB->id, $idsSC);

        // Product = all
        $resAll = $this->withHeaders($h)->getJson('/api/signals/opportunities?product=all&run_date=2026-10-20&all_signals=1');
        $resAll->assertOk();
        $idsAll = collect($resAll->json('data'))->pluck('id')->all();
        $this->assertContains($oppSC->id, $idsAll);
        $this->assertContains($oppEB->id, $idsAll);
    }

    /**
     * Requirement 7: Tenant isolation is preserved.
     */
    public function test_tenant_isolation_preserved(): void
    {
        $h1 = $this->headers(self::T1);
        $h2 = $this->headers(self::T2);

        $runT2 = $this->createRun(self::T2, 'scholarclone', null, 'b2b', null, '2026-10-20');
        $oppT2 = $this->createOpportunity(self::T2, 'scholarclone', $runT2->id, null, 'b2b', null, 'Tenant 2 Lead', null, '2026-10-20');

        $res = $this->withHeaders($h1)->getJson('/api/signals/opportunities?product=scholarclone&run_date=2026-10-20&all_signals=1');
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertNotContains($oppT2->id, $ids);
    }

    /**
     * Requirement 8: Changing dropdowns or querying does not mutate stored run attribution.
     */
    public function test_querying_never_mutates_stored_attribution(): void
    {
        $h = $this->headers(self::T1);

        $run = $this->createRun(self::T1, 'scholarclone', 'SCH-01', 'b2b', [['country' => 'India', 'state' => 'Gujarat']]);
        $opp = $this->createOpportunity(self::T1, 'scholarclone', $run->id, 'SCH-01', 'b2b', [['country' => 'India', 'state' => 'Gujarat']], 'Original Lead');

        // Query with all_signals=1
        $this->withHeaders($h)->getJson('/api/signals/opportunities?product=scholarclone&all_signals=1')->assertOk();

        // Query with different customer_model and offer_id
        $this->withHeaders($h)->getJson('/api/signals/opportunities?product=scholarclone&customer_model=b2c&offer_id=SCH-02')->assertOk();

        // Verify stored DB record is untouched
        $oppReloaded = CompanyOpportunity::find($opp->id);
        $this->assertSame('SCH-01', $oppReloaded->offer_id);
        $this->assertSame('b2b', $oppReloaded->customer_model);
        $this->assertSame($run->id, $oppReloaded->research_run_id);
    }

    /**
     * Requirement 9: Loading or refreshing the inbox never triggers research generation.
     */
    public function test_loading_inbox_never_triggers_research(): void
    {
        $h = $this->headers(self::T1);

        $initialRunCount = ResearchRun::count();

        $this->withHeaders($h)->getJson('/api/signals/opportunities?product=scholarclone&all_signals=1')->assertOk();
        $this->withHeaders($h)->getJson('/api/signals/research/dates?product=scholarclone&all_signals=1')->assertOk();

        $this->assertSame($initialRunCount, ResearchRun::count());
    }
}
