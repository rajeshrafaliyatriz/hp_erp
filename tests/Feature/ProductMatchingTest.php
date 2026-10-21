<?php

namespace Tests\Feature;

use App\Domain\Portfolio\MatchingEngine;
use App\Domain\Portfolio\OpportunityMatch;
use App\Domain\Portfolio\ProductOffer;
use App\Domain\Portfolio\ProductProfileMatcher;
use App\Domain\Portfolio\TaxonomyService;
use App\Domain\Signals\Icp\IcpProfile;
use App\Domain\Signals\Opportunities\OpportunityResearcher;
use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Opportunities\ResearchRun;
use App\Domain\Signals\Products\ProductCatalog;
use App\Domain\Signals\Support\SafeUrlFetcherInterface;
use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A product of ANY kind is read in full and matched to what a signal needs: its description, features, the problems it solves, who it is for and
 * the buying events it responds to (codes, the ten signal families, or a person's own words). A signal is matched to the child products of its own
 * product line. Everything is deterministic, adds to the existing scoring and never lowers it. No provider is called.
 */
class ProductMatchingTest extends TestCase
{
    private const T = 1;

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
            '2026_11_29_100000_add_date_provenance_to_g2g_research_sources', '2026_11_29_110000_add_modified_date_to_g2g_research_sources',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }

        RoleKey::flushCache();
        Cache::flush();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-20 10:00:00');
        DB::table('tbluserprofilemaster')->insert([['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator']]);
        config([
            'ai.provider.gemini.api_key' => 'test-key', 'signals.ai_attempts' => 1, 'signals.web_search.driver' => 'tavily', 'signals.web_search.tavily_key' => 'tvly-test-key',
            'signals.web_search.min_interval_ms' => 0, 'signals.icp.scholar_k12_tenants' => [self::T],
        ]);
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

    private function product(array $o = []): ProductOffer
    {
        return ProductOffer::create($o + [
            'sub_institute_id' => self::T, 'offer_id' => 'P-' . uniqid(), 'name' => 'Product', 'parent_product' => 'Scholar K-12', 'readiness_status' => 'In development',
            'what_it_does' => null, 'modules_components' => null, 'needs_solved' => [], 'primary_segments' => [], 'trigger_signals' => [],
        ]);
    }

    /** A signal as the matcher reads it. */
    private function signal(array $o = []): object
    {
        return (object) ($o + [
            'title' => 'Podar group opens a new campus', 'observed_event' => 'The group opens a new campus in Vadodara and is hiring an ERP administrator.',
            'why_indicates_need' => 'A new campus needs admissions, fee collection and attendance running from day one.', 'likely_problem' => '',
            'what_we_could_sell' => 'fee collection and admissions for the new campus', 'trigger_summary' => 'New campus with admissions open.', 'trigger_type' => 'expansion',
            'buyer_segment' => null, 'business_fit' => 'scholar',
        ]);
    }

    // ── the whole profile, for any kind of product ───────────────────────────

    public function test_a_products_own_words_are_matched_to_what_the_signal_says_and_the_words_are_shown(): void
    {
        $p = $this->product(['name' => 'Fee Collection Suite', 'what_it_does' => 'Online fee collection, receipts and parent reminders for schools.',
            'needs_solved' => ['Fee collection is slow and receipts are lost during admissions season']]);

        $r = (new ProductProfileMatcher())->evaluate($p, $this->signal());

        $this->assertGreaterThan(0, $r['points']);
        $this->assertContains('collection', $r['needs']);
        $this->assertContains('admission', $r['needs']);
        $this->assertStringContainsString('both speak about', $r['reasons'][0]);
        $this->assertStringContainsString('customer problems', $r['reasons'][0], 'it says which part of the product record the words came from');
    }

    public function test_it_works_for_a_product_that_has_nothing_to_do_with_schools(): void
    {
        $p = $this->product(['parent_product' => 'EB', 'name' => 'Plant Downtime Monitor', 'what_it_does' => 'Real time machine downtime dashboards and maintenance alerts for factories.',
            'needs_solved' => ['Unplanned machine downtime stops production lines'], 'primary_segments' => ['Mid-size manufacturers'], 'trigger_signals' => ['expansion']]);
        $signal = $this->signal(['title' => 'Kiran Alloys opens a new plant', 'observed_event' => 'Kiran Alloys is opening a new plant in Sanand and worries about machine downtime on its production lines.',
            'why_indicates_need' => 'Maintenance alerts and downtime dashboards matter from the first shift.', 'buyer_segment' => 'manufacturers', 'business_fit' => 'eb']);

        $r = (new ProductProfileMatcher())->evaluate($p, $signal);

        $this->assertContains('downtime', $r['needs']);
        $this->assertContains('manufacturer', $r['segments'], 'a person\'s own free-text customer type is matched too');
        $this->assertContains('Expansion', $r['events'], 'the product responds to the Expansion family and the signal is an expansion');
        $this->assertLessThanOrEqual(ProductProfileMatcher::MAX_POINTS, $r['points']);
        $this->assertGreaterThanOrEqual(60, $r['points'], 'needs, customer and event all line up');
    }

    public function test_a_free_text_buying_event_a_person_typed_is_matched_by_its_words(): void
    {
        $p = $this->product(['name' => 'Admissions CRM', 'what_it_does' => 'Tracks enquiries.', 'trigger_signals' => ['Admissions open for the new academic session']]);

        $r = (new ProductProfileMatcher())->evaluate($p, $this->signal(['trigger_type' => 'admissions', 'trigger_summary' => 'Admissions open for the new academic session at the new campus.']));

        $this->assertSame(['Admissions open for the new academic session'], $r['events']);
    }

    public function test_a_product_with_no_details_adds_nothing_and_nothing_is_invented(): void
    {
        $r = (new ProductProfileMatcher())->evaluate($this->product(['name' => 'X']), $this->signal());

        $this->assertSame(0, $r['points']);
        $this->assertSame([], $r['reasons']);
    }

    public function test_one_stray_shared_word_is_not_a_match(): void
    {
        $p = $this->product(['name' => 'Transport Tracker', 'what_it_does' => 'GPS tracking for school buses and routes.']);

        $r = (new ProductProfileMatcher())->evaluate($p, $this->signal(['observed_event' => 'The group opens a new campus in Vadodara and a route to it is announced.']));

        $this->assertSame([], $r['needs'], 'a single shared word is too weak to count');
    }

    // ── the engine: additive, and limited to the signal's own product line ───

    public function test_the_whole_profile_only_adds_to_the_existing_score(): void
    {
        $this->product(['offer_id' => 'FEE-1', 'name' => 'Fee Collection Suite', 'what_it_does' => 'Online fee collection, receipts and admissions for schools.']);
        $signal = $this->signal();

        config(['signals.matching.profile_overlap' => false]);
        $before = (new MatchingEngine())->matchForSignal($signal, 'opportunity', self::T)['offer_matches'];
        config(['signals.matching.profile_overlap' => true]);
        $after = (new MatchingEngine())->matchForSignal($signal, 'opportunity', self::T)['offer_matches'];

        $this->assertSame([], $before, 'the old keyword rules found nothing in this product\'s words');
        $this->assertSame('FEE-1', $after[0]['offer']->offer_id);
        $this->assertGreaterThanOrEqual(20, $after[0]['match_score']);
        $this->assertNotEmpty($after[0]['match_reasons']);
        $this->assertNotEmpty($after[0]['profile_match']['needs']);
    }

    public function test_a_signal_is_matched_only_to_the_child_products_of_its_own_product_line(): void
    {
        $this->product(['offer_id' => 'SCH-FEE', 'name' => 'Fee Collection Suite', 'parent_product' => 'Scholar K-12', 'what_it_does' => 'Fee collection, receipts and admissions.']);
        $this->product(['offer_id' => 'EB-FEE', 'name' => 'Finance Brain', 'parent_product' => 'EB', 'what_it_does' => 'Fee collection analytics and admissions reporting.']);

        $ids = fn ($signal) => array_map(fn ($m) => $m['offer']->offer_id, (new MatchingEngine())->matchForSignal($signal, 'opportunity', self::T)['offer_matches']);

        $this->assertSame(['SCH-FEE'], $ids($this->signal(['business_fit' => 'scholar'])), 'ScholarClone\'s signal is matched to ScholarClone\'s products');
        $this->assertSame(['EB-FEE'], $ids($this->signal(['business_fit' => 'eb'])));
        $this->assertEqualsCanonicalizing(['SCH-FEE', 'EB-FEE'], $ids($this->signal(['business_fit' => null])), 'a signal with no product line is matched to all, as before');

        config(['signals.matching.limit_to_product' => false]);
        $this->assertEqualsCanonicalizing(['SCH-FEE', 'EB-FEE'], $ids($this->signal(['business_fit' => 'scholar'])), 'the limit can be switched off');
    }

    public function test_a_product_from_another_category_that_the_icp_lists_is_a_child_too(): void
    {
        $this->product(['offer_id' => 'SCH-1', 'name' => 'School ERP', 'parent_product' => 'Scholar K-12']);
        $this->product(['offer_id' => 'HE-1', 'name' => 'Campus Analytics', 'parent_product' => 'HE']);
        IcpProfile::create(['sub_institute_id' => self::T, 'key' => 'scholar_k12', 'name' => 'Scholar', 'product_line' => 'scholar', 'status' => 'active', 'is_default' => true, 'version' => 2, 'updated_by' => 5,
            'config' => ['label' => 'ScholarClone', 'industry_label' => 'schools', 'buyer_types' => ['school'], 'regions' => ['Gujarat'], 'parts' => ['school_erp' => 'school management system'],
                'triggers' => ['new_campus' => ['source' => 'news', 'part' => 'school_erp', 'decay' => 90, 'pain' => 'a new campus needs a system', 'phrases' => ['new campus']]],
                'queries' => [['id' => 'q1', 'source' => 'news', 'topic' => 'news', 'days' => 45, 'q' => 'school new campus Gujarat']], 'per_run' => ['catalogue' => 1, 'accounts' => 0], 'offer_ids' => ['HE-1']]]);

        $children = (new ProductCatalog())->childOffers(self::T, 'scholarclone')->pluck('offer_id')->all();

        $this->assertEqualsCanonicalizing(['SCH-1', 'HE-1'], $children);
        $this->assertSame([], (new ProductCatalog())->childOffers(2, 'scholarclone')->all(), 'another organisation has none of them');
    }

    // ── what the person sees ─────────────────────────────────────────────────

    public function test_a_stored_match_comes_back_with_the_product_its_category_and_why(): void
    {
        $fixture = json_decode((string) file_get_contents(base_path('database/fixtures/demo_signals_2026-10-13_14.json')), true);
        $row = $fixture['data']['opportunities'][0];
        unset($row['id']);
        $id = DB::table('g2g_company_opportunities')->insertGetId(array_merge($row, ['sub_institute_id' => self::T, 'company_id' => 1, 'research_run_id' => null, 'import_source' => null]));
        $this->product(['offer_id' => 'FEE-1', 'name' => 'Fee Collection Suite']);
        OpportunityMatch::create(['sub_institute_id' => self::T, 'signal_type' => 'opportunity', 'signal_id' => $id, 'offer_id' => 'FEE-1', 'match_status' => 'Matched', 'match_score' => 62,
            'matched_need_codes' => [], 'rationale' => 'The signal and this product both speak about: fee, admission (in the product\'s description).']);

        $match = $this->getJson('/api/signals/opportunities?expired=include&per_page=50', $this->h())->assertOk()->json('data.0.matched_offers.0');

        $this->assertSame('Fee Collection Suite', $match['offer_name']);
        $this->assertSame('Scholar K-12', $match['offer_category']);
        $this->assertStringContainsString('both speak about', $match['rationale']);
        $this->assertSame(62, $match['match_score']);
    }

    // ── what the research reads ──────────────────────────────────────────────

    public function test_the_research_prompt_is_shown_every_child_product_in_full(): void
    {
        ProductProfile::create(['sub_institute_id' => self::T, 'product_name' => 'ScholarClone K-12 Bundle', 'description' => 'School ERP.', 'problems_solved' => 'x', 'features' => 'y',
            'target_industries' => ['Education'], 'target_markets' => ['Gujarat'], 'keywords' => ['school erp'], 'excluded' => [], 'research_enabled' => true, 'research_frequency' => 'daily', 'recency_days' => 30]);
        $this->product(['offer_id' => 'FEE-1', 'name' => 'Fee Collection Suite', 'what_it_does' => 'Online fee collection with parent reminders.', 'modules_components' => 'Receipts; reminders',
            'needs_solved' => ['Slow fee collection during admissions'], 'primary_segments' => ['Private K-12 schools'], 'trigger_signals' => ['expansion']]);

        $ctx = (new ProductCatalog())->resolve(self::T, 'scholarclone');

        $this->assertCount(1, $ctx->children);
        $child = $ctx->children[0];
        $this->assertSame('FEE-1', $child['id']);
        $this->assertSame('Online fee collection with parent reminders.', $child['what_it_does']);
        $this->assertSame('Receipts; reminders', $child['features']);
        $this->assertSame(['Slow fee collection during admissions'], $child['problems_it_solves'], 'the person\'s own words are passed on unchanged');
        $this->assertSame(['Private K-12 schools'], $child['sold_to']);
        $this->assertStringContainsString('Expansion', $child['responds_to_events'][0]);
    }

    // ── the signal families ──────────────────────────────────────────────────

    public function test_the_ten_signal_families_are_offered_with_their_examples_and_older_codes_still_resolve(): void
    {
        $families = $this->getJson('/api/portfolio/taxonomy', $this->h())->assertOk()->json('data.signal_families');

        $this->assertSame(['expansion', 'leadership', 'technology', 'academic', 'teacher_capability', 'career_readiness', 'digital_transformation', 'funding_procurement', 'competitive_change', 'direct_intent'], array_keys($families));
        $this->assertSame('New campus, new programme, acquisition', $families['expansion']['examples']);
        $this->assertSame('Expansion: New campus, new programme, acquisition', TaxonomyService::getSignalLabel('expansion'));
        $this->assertSame('Empanelment RFP / EOI / tender', TaxonomyService::getSignalLabel('S-GOV-RFP'), 'an older code keeps its label');
        $this->assertSame('Admissions open', TaxonomyService::getSignalLabel('Admissions open'), 'a person\'s own entry is its own label');
        $this->assertSame(['expansion', 'S-COMP'], TaxonomyService::validateSignalCodes(['expansion', 'S-COMP', 'nonsense']));
    }

    public function test_a_product_keeps_a_persons_own_free_text_in_its_lists_and_the_lists_are_bounded(): void
    {
        $h = $this->h();
        $body = ['offer_id' => 'MY-1', 'name' => 'Fee Suite', 'parent_product' => 'Scholar K-12', 'readiness_status' => 'In development',
            'needs_solved' => ['Fee collection is slow', 'N13'], 'primary_segments' => ['Boarding schools', 'K12-PVT'], 'trigger_signals' => ['expansion', 'New transport tender']];

        $this->postJson('/api/portfolio/offers', $body, $h)->assertStatus(201);

        $row = DB::table('g2g_product_offers')->where('offer_id', 'MY-1')->first();
        $this->assertSame(['Fee collection is slow', 'N13'], json_decode($row->needs_solved, true));
        $this->assertSame(['expansion', 'New transport tender'], json_decode($row->trigger_signals, true));
        $this->postJson('/api/portfolio/offers', array_merge($body, ['offer_id' => 'MY-2', 'needs_solved' => [str_repeat('x', 401)]]), $h)->assertStatus(422);
        $this->postJson('/api/portfolio/offers', array_merge($body, ['offer_id' => 'MY-3', 'needs_solved' => [['not' => 'text']]]), $h)->assertStatus(422);
    }

    public function test_a_signals_trigger_type_maps_to_a_family_only_when_it_clearly_belongs_to_one(): void
    {
        $this->assertSame('funding_procurement', TaxonomyService::familyForTrigger('tender'));
        $this->assertSame('funding_procurement', TaxonomyService::familyForTrigger('RFP'));
        $this->assertSame('expansion', TaxonomyService::familyForTrigger('acquisition'));
        $this->assertSame('leadership', TaxonomyService::familyForTrigger('leadership'));
        $this->assertSame('direct_intent', TaxonomyService::familyForTrigger('vendor_search'));
        foreach (['hiring', 'programme', 'regulation', 'partnership', 'pain_point', 'other', '', null] as $unclear) {
            $this->assertNull(TaxonomyService::familyForTrigger($unclear), 'no guessed family for ' . var_export($unclear, true));
        }
    }

    public function test_a_tender_signal_matches_a_product_that_responds_to_funding_and_procurement(): void
    {
        $p = $this->product(['name' => 'Tender Desk', 'what_it_does' => 'Prepares tender responses.', 'trigger_signals' => ['funding_procurement']]);

        $r = (new ProductProfileMatcher())->evaluate($p, $this->signal(['trigger_type' => 'tender', 'title' => 'Board floats a tender', 'observed_event' => 'The board floated a tender.']));

        $this->assertSame(['Funding and procurement'], $r['events']);
    }

    public function test_the_signal_card_data_carries_its_family(): void
    {
        $fixture = json_decode((string) file_get_contents(base_path('database/fixtures/demo_signals_2026-10-13_14.json')), true);
        $row = $fixture['data']['opportunities'][0];
        unset($row['id']);
        DB::table('g2g_company_opportunities')->insert(array_merge($row, ['sub_institute_id' => self::T, 'company_id' => 1, 'research_run_id' => null, 'import_source' => null, 'trigger_type' => 'expansion']));

        $card = $this->getJson('/api/signals/opportunities?expired=include', $this->h())->assertOk()->json('data.0');

        $this->assertSame('expansion', $card['signal_family']['code']);
        $this->assertSame('Expansion', $card['signal_family']['label']);
    }
}
