<?php

namespace Tests\Feature;

use App\Domain\Signals\Icp\IcpResearchPlanner;
use App\Domain\Signals\Market\OpportunityFit;
use App\Domain\Signals\Market\QualificationGate;
use App\Domain\Signals\Opportunities\TavilySearchProvider;
use App\Domain\Signals\Support\EvidenceAttribution;
use App\Domain\Signals\Support\RegionScope;
use App\Domain\Signals\Support\SearchCache;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The deterministic fit score, who a quote is really about, the search country, and per-scope rotation. Runs on the in-memory sqlite database;
 * no provider is called (Http is faked).
 */
class OpportunityQualityTest extends TestCase
{
    private const T = 1;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables', '2026_09_30_180000_add_signal_fields_to_ingestion_findings',
            '2026_09_30_200000_add_research_sources_and_signal_kind', '2026_09_30_220000_create_g2g_portfolio_and_partners_tables',
            '2026_09_30_230000_add_claude_feed_and_linking_fields', '2026_10_01_130000_add_stage_to_g2g_research_runs',
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_12_01_090000_add_product_key_to_signals_runs_and_sources',
            '2026_10_14_100000_create_g2g_icp_profiles_tables', '2026_10_13_100000_create_g2g_target_accounts_table',
            '2026_10_31_090000_add_research_run_configuration_scope', '2026_12_07_090000_add_scope_to_g2g_company_opportunities',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        Carbon::setTestNow('2026-10-16 10:00:00');
        config(['signals.web_search.driver' => 'tavily', 'signals.web_search.tavily_key' => 'tvly-test-key']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $o */
    private function fitInput(array $o = []): array
    {
        return $o + [
            'part_known' => true, 'child_supported' => null, 'sub_product_run' => false,
            'buyer_type' => 'school', 'icp_buyer_types' => ['school'], 'trigger_type' => 'tender', 'icp_triggers' => ['tender'],
            'region_requested' => false, 'region_status' => null, 'location_in_icp_regions' => true,
            'page_read' => true, 'quote_verified' => true, 'source_domains' => 2,
            'intent_level' => 'strong', 'age_days' => 3, 'tender_days_left' => null,
        ];
    }

    // ── fit ──────────────────────────────────────────────────────────────────

    public function test_the_fit_weights_add_up_to_one_hundred_and_a_strong_candidate_scores_high(): void
    {
        $this->assertSame(100, array_sum(OpportunityFit::WEIGHTS));
        $fit = OpportunityFit::score($this->fitInput());

        $this->assertGreaterThanOrEqual(65, $fit['total']);
        $this->assertSame('High', OpportunityFit::band($fit['total']));
        foreach ($fit['components'] as $name => $c) {
            $this->assertLessThanOrEqual($c['max'], $c['score'], "{$name} never exceeds its weight");
        }
    }

    public function test_weak_evidence_no_intent_and_old_news_score_low_whatever_the_model_claimed(): void
    {
        $fit = OpportunityFit::score($this->fitInput([
            'page_read' => false, 'quote_verified' => false, 'source_domains' => 1, 'intent_level' => 'unconfirmed', 'age_days' => 200,
            'buyer_type' => null, 'trigger_type' => 'other', 'location_in_icp_regions' => false,
        ]));

        $this->assertLessThan(40, $fit['total']);
        $this->assertSame('Low', OpportunityFit::band($fit['total']));
        $this->assertSame(0, $fit['components']['freshness']['score']);
        $this->assertSame(0, $fit['components']['intent']['score']);
    }

    public function test_a_requested_place_the_source_does_not_name_costs_geography_and_an_outside_one_scores_zero(): void
    {
        $named = OpportunityFit::score($this->fitInput(['region_requested' => true, 'region_status' => 'confirmed']))['components']['geography']['score'];
        $unnamed = OpportunityFit::score($this->fitInput(['region_requested' => true, 'region_status' => 'unconfirmed']))['components']['geography']['score'];
        $outside = OpportunityFit::score($this->fitInput(['region_requested' => true, 'region_status' => 'outside']))['components']['geography']['score'];

        $this->assertGreaterThan($unnamed, $named);
        $this->assertGreaterThan($outside, $unnamed);
        $this->assertSame(0, $outside);
    }

    public function test_an_open_tender_is_fresh_for_as_long_as_it_is_open_and_not_at_all_once_closed(): void
    {
        $open = OpportunityFit::score($this->fitInput(['age_days' => 100, 'tender_days_left' => 30]))['components']['freshness']['score'];
        $closing = OpportunityFit::score($this->fitInput(['age_days' => 100, 'tender_days_left' => 3]))['components']['freshness']['score'];
        $closed = OpportunityFit::score($this->fitInput(['age_days' => 100, 'tender_days_left' => -1]))['components']['freshness']['score'];

        $this->assertSame([10, 7, 0], [$open, $closing, $closed]);
    }

    private function researchedRecord(array $assessment, string $intent = 'direct_need', string $buying = 'strong'): array
    {
        return [
            'buyer' => ['name' => 'Podar World School', 'type' => 'school', 'state' => null, 'website' => null, 'aliases' => []],
            'context' => null, 'trigger_type' => 'expansion',
            'trigger_summary' => 'A new campus and an ERP hire point to systems being set up now.',
            'headline' => 'Podar World School is opening a new campus and needs a school system running from day one.',
            'what_happened' => 'Podar World School is opening a new campus in Vadodara. It is also hiring an ERP administrator.',
            'likely_problem' => 'A new campus needs admissions, fees and attendance working from the first day. Our school management system covers all three.',
            'what_we_could_sell' => 'school management system for the new Vadodara campus covering admissions, fees and attendance',
            'entry_point' => 'discovery_meeting', 'soft_marketing_angle' => 'Ask for the principal or the trustee. Open with running the new campus from one system.',
            'source_url' => 'https://news-a.example.com/podar-campus', 'source_title' => 'Podar opens campus', 'source_published_at' => '2026-10-14',
            'event_date' => '2026-10-14', 'observed_at' => '2026-10-16 10:00:00', 'fetch_level' => 'page_text', 'claim_level' => 'confirmed',
            'evidence' => ['quote_verified' => true, 'corroborating_domains' => 1, 'inference_reasoning' => null],
            'source_excerpt' => 'Podar World School announced a new campus in Vadodara', 'content_hash' => 'aaa',
            'intent' => $intent, 'buying_intent' => $buying, 'assessment' => ['fit' => $assessment],
        ];
    }

    public function test_the_gate_refuses_a_candidate_whose_fit_is_below_the_floor(): void
    {
        $out = (new QualificationGate())->evaluate($this->researchedRecord(['total' => 10]));

        $this->assertFalse($out['ok']);
        $this->assertSame('below_fit_floor', $out['code']);
    }

    public function test_a_weak_fit_is_never_rated_hot_and_a_strong_fit_is_left_alone(): void
    {
        $gate = new QualificationGate();
        $strong = $gate->evaluate($this->researchedRecord(['total' => 90]));
        $weak = $gate->evaluate($this->researchedRecord(['total' => 40]));

        $this->assertTrue($strong['ok'], $strong['reason'] ?? '');
        $this->assertTrue($weak['ok'], $weak['reason'] ?? '');
        $this->assertSame('hot', $strong['record']['strength'], 'the rules alone call this hot');
        $this->assertSame('warm', $weak['record']['strength'], 'a fit of 40 caps it at warm');
        $this->assertStringContainsString('fit with the product is 40', $weak['record']['strength_reason']);
    }

    // ── attribution ──────────────────────────────────────────────────────────

    public function test_a_name_inside_another_word_is_not_the_organisation(): void
    {
        $this->assertTrue(EvidenceAttribution::containsWord('the dps school opened', 'dps'));
        $this->assertFalse(EvidenceAttribution::containsWord('the dpsx school opened', 'dps'));
        $this->assertFalse(EvidenceAttribution::containsWord('podarsh academy', 'podar'));
        $this->assertTrue(EvidenceAttribution::containsWord('(podar) group', 'podar'));
    }

    public function test_the_name_must_be_tied_to_the_quote_by_title_address_or_nearness(): void
    {
        $quote = 'is hiring an erp administrator';
        $far = 'podar world school is mentioned here. ' . str_repeat('unrelated filler text about other things. ', 120) . 'another school ' . $quote . ' for its campus.';

        $this->assertFalse(EvidenceAttribution::names($far, 'podar world school', $quote)['ok'], 'the name is far from the quote: the quote is about someone else');
        $this->assertSame('title', EvidenceAttribution::names($far, 'podar world school', $quote, 'podar world school opens campus')['basis']);
        $this->assertSame('address', EvidenceAttribution::names($far, 'podar world school', $quote, '', 'https://www.podarworldschool.com/news/1')['basis']);

        $near = 'podar world school ' . $quote . ' for its new campus';
        $this->assertSame('near_quote', EvidenceAttribution::names($near, 'podar world school', $quote)['basis']);
        $this->assertFalse(EvidenceAttribution::names('nothing about it', 'podar world school', $quote)['ok']);
    }

    // ── search country ───────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function payloads(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'api.tavily.com'))->map(fn (Request $r) => $r->data())->values()->all();
    }

    public function test_a_general_search_is_biased_to_the_requested_country_to_none_for_several_and_to_india_by_default(): void
    {
        Http::fake(['api.tavily.com/*' => Http::response(['results' => []], 200)]);
        $p = new TavilySearchProvider();

        $p->search('tender', 90, 5, ['topic' => 'general']);
        $p->search('tender', 90, 5, ['topic' => 'general', 'country' => 'united arab emirates']);
        $p->search('tender', 90, 5, ['topic' => 'general', 'country' => null]);

        [$default, $uae, $none] = $this->payloads();
        $this->assertSame('india', $default['country'], 'no region asked for: the default market, as before');
        $this->assertSame('united arab emirates', $uae['country']);
        $this->assertArrayNotHasKey('country', $none, 'places in several countries: no country is forced');
    }

    public function test_the_region_scope_names_its_one_country_or_none(): void
    {
        $guj = RegionScope::fromInput([['country' => 'India', 'state' => 'Gujarat']]);
        $this->assertSame('india', $guj->singleCountry());
        $mixed = RegionScope::fromInput([['country' => 'India', 'state' => 'Gujarat'], ['country' => 'United Arab Emirates']]);
        $this->assertNull($mixed->singleCountry());
    }

    public function test_the_country_is_part_of_a_searchs_identity_only_when_one_was_asked_for(): void
    {
        $cache = new SearchCache(new Repository(new ArrayStore()), 4);
        $plain = $cache->key(1, 'tender', ['topic' => 'general', 'days' => 30]);

        $this->assertSame($plain, $cache->key(1, 'tender', ['topic' => 'general', 'days' => 30]), 'unchanged for searches made without a country');
        $this->assertNotSame($plain, $cache->key(1, 'tender', ['topic' => 'general', 'days' => 30, 'country' => 'uae']));
        $this->assertNotSame($cache->key(1, 'tender', ['country' => 'uae']), $cache->key(1, 'tender', ['country' => 'india']));
    }

    // ── registry_products (optional ICP setting) ─────────────────────────────

    public function test_registry_products_is_optional_and_must_be_known_capabilities(): void
    {
        $validator = new \App\Domain\Signals\Icp\IcpProfileValidator();
        $base = (array) config('signals_icp.scholar_k12');
        $problems = fn (array $c) => array_values(array_filter($validator->validate($c), fn ($p) => str_starts_with($p, 'registry_products')));

        $this->assertSame([], $problems($base), 'absent: no preference, nothing changes');
        $this->assertSame([], $problems($base + ['registry_products' => ['learn', 'ccb']]));
        $this->assertNotSame([], $problems($base + ['registry_products' => ['learn', 'made_up']]));
        $this->assertNotSame([], $problems($base + ['registry_products' => 'learn']));
        $this->assertNotSame([], $problems($base + ['registry_products' => [5]]));
    }

    public function test_an_icp_that_names_registry_products_gets_only_those_sources_and_one_that_does_not_is_unchanged(): void
    {
        $registry = new \App\Domain\Signals\Sources\SourceRegistry();
        $byId = collect($registry->all())->keyBy('id');
        $selector = new \App\Domain\Signals\Sources\SourceSelector($registry);
        $day = Carbon::parse('2026-10-16');
        $options = ['can_restrict_domains' => true, 'days' => 45, 'shift' => 0];

        $named = $selector->select($day, 50, $options + ['products' => ['learn'], 'restrict_to_products' => true]);
        $this->assertNotSame([], $named['selected'], 'there are sources tagged for this capability');
        foreach ($named['selected'] as $id) {
            $this->assertContains('learn', $byId[$id]['products'], "{$id} is tagged for the capability the ICP asked for");
        }
        $this->assertContains('not_for_this_icp', $named['skipped']);

        $unrestricted = $selector->select($day, 50, $options + ['products' => ['learn']]);
        $none = $selector->select($day, 50, $options);
        $this->assertLessThan($unrestricted['eligible'], $named['eligible'], 'naming the capability narrows what is eligible');
        $this->assertSame($none['eligible'], $unrestricted['eligible'], 'without the restriction every source stays eligible (ranking only): unchanged for existing ICPs');
    }

    public function test_only_scholarclone_uses_the_registry_unnamed_and_any_product_may_opt_in(): void
    {
        $src = file_get_contents(base_path('app/Domain/Signals/Opportunities/OpportunityResearcher.php'));
        $this->assertStringContainsString("=== [] && ! \$this->ctx->isScholar()", $src);
        $this->assertStringContainsString("'restrict_to_products' => ((array) (\$this->ctx->pack->config()['registry_products'] ?? [])) !== []", $src);
    }

    // ── per-scope rotation ───────────────────────────────────────────────────

    private function addRun(string $status, array $o = []): void
    {
        DB::table('g2g_research_runs')->insert($o + ['sub_institute_id' => self::T, 'report_date' => '2026-10-16', 'trigger' => 'manual', 'status' => $status, 'product_key' => 'enterprise_brain',
            'customer_model' => 'b2b', 'offer_id' => null, 'started_at' => '2026-10-16 09:00:00', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_rotation_counts_only_this_scope_and_only_runs_that_finished_well(): void
    {
        $this->addRun('success');                                              // this scope
        $this->addRun('failed');                                               // learned nothing: not counted
        $this->addRun('success', ['customer_model' => 'b2g']);                 // another customer model
        $this->addRun('success', ['offer_id' => 'EB-01']);                     // another sub-product
        $this->addRun('success', ['customer_model' => null]);                  // saved before the model was recorded: counts as B2B

        $this->assertSame(2, IcpResearchPlanner::attemptsToday(self::T, 'enterprise_brain', null, null, 'b2b'));
        $this->assertSame(1, IcpResearchPlanner::attemptsToday(self::T, 'enterprise_brain', null, null, 'b2g'));
        $this->assertSame(1, IcpResearchPlanner::attemptsToday(self::T, 'enterprise_brain', null, 'EB-01', 'b2b'));
        $this->assertSame(4, IcpResearchPlanner::attemptsToday(self::T, 'enterprise_brain'), 'without a scope it is the product total, as before');
    }
}
