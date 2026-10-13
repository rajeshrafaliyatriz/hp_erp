<?php

namespace Tests\Feature;

use App\Domain\Signals\Market\QualificationGate;
use App\Domain\Signals\Market\SectionAssigner;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Support\SafeUrlFetcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WHO IS A BUYER. A seller of what we sell (AI, analytics, software, edtech, ERP, LMS, IT services, consultancies) is a
 * competitor or at most a partner, never a lead. The prompt says so, and the gate enforces it in code so that a model
 * which forgets cannot put one into "act now" or the watchlist. The four vendor stories are real examples from the
 * seed data. All web and AI traffic is mocked.
 */
class BuyerRuleTest extends TestCase
{
    private const T = 1;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables', '2026_09_30_180000_add_signal_fields_to_ingestion_findings',
            '2026_09_30_200000_add_research_sources_and_signal_kind', '2026_09_30_220000_create_g2g_portfolio_and_partners_tables',
            '2026_09_30_230000_add_claude_feed_and_linking_fields', '2026_10_01_130000_add_stage_to_g2g_research_runs',
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_10_13_100000_create_g2g_target_accounts_table',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        Cache::flush();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-20 10:00:00');
        config([
            'ai.provider.gemini.api_key' => 'test-key', 'signals.ai_attempts' => 1,
            'signals.web_search.driver' => 'tavily', 'signals.web_search.tavily_key' => 'tvly-test-key',
            'signals.web_search.min_interval_ms' => 0, 'signals.icp.scholar_k12_tenants' => [self::T],
        ]);
        $this->app->instance(SafeUrlFetcher::class, new SafeUrlFetcher(fn () => ['93.184.216.34']));
        ProductProfile::create([
            'sub_institute_id' => self::T, 'product_name' => 'ScholarClone K-12 Bundle', 'description' => 'School ERP, LMS, teacher and career intelligence.',
            'problems_solved' => 'Disconnected school systems', 'features' => 'ERP, LMS', 'target_industries' => ['Education'], 'target_markets' => ['Gujarat'],
            'keywords' => ['school erp'], 'excluded' => [], 'research_enabled' => true, 'research_frequency' => 'daily', 'recency_days' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, array{url: string, title: string, content: string, quote: string, company: string, fact: string}> */
    private function stories(): array
    {
        return [
            'brahma' => ['company' => 'Brahma AI', 'url' => 'https://news.example.com/brahma-ai-raises', 'title' => 'Brahma AI raises USD 150 million',
                'content' => 'Brahma AI raised funding at a USD 2 billion valuation to accelerate its enterprise AI platform.',
                'quote' => 'Brahma AI raised funding at a USD 2 billion valuation', 'fact' => 'Brahma AI raised funding at a USD 2 billion valuation. It will grow its enterprise AI platform.'],
            'vaia' => ['company' => 'Vaia', 'url' => 'https://news.example.com/vaia-us', 'title' => 'Vaia enters the US',
                'content' => 'Vaia expands its AI intelligence platform into the US market for financial institutions.',
                'quote' => 'Vaia expands its AI intelligence platform into the US market', 'fact' => 'Vaia is taking its AI intelligence platform into the US market. It serves financial institutions.'],
            'nec' => ['company' => 'NEC India', 'url' => 'https://news.example.com/nec-wingarc', 'title' => 'NEC India and WingArc1st partner',
                'content' => 'NEC India and WingArc1st expand partnership to bring data empowerment and analytics solutions to India.',
                'quote' => 'NEC India and WingArc1st expand partnership to bring data empowerment', 'fact' => 'NEC India and WingArc1st widened a partnership to bring analytics solutions to India.'],
            'rsk' => ['company' => 'RSK Business Solutions', 'url' => 'https://news.example.com/rsk-dubai', 'title' => 'RSK at the Dubai AI Festival',
                'content' => 'RSK Business Solutions to showcase practical AI applications at Dubai AI Festival 2026.',
                'quote' => 'RSK Business Solutions to showcase practical AI applications', 'fact' => 'RSK Business Solutions will show AI applications at an event in Dubai.'],
            'podar' => ['company' => 'Podar World School', 'url' => 'https://news.example.com/podar-campus', 'title' => 'Podar opens a campus',
                'content' => 'Podar World School announced a new campus in Vadodara and is hiring an ERP administrator ahead of admissions 2027.',
                'quote' => 'Podar World School announced a new campus in Vadodara and is hiring an ERP administrator', 'fact' => 'Podar World School is opening a new campus in Vadodara. It is also hiring an ERP administrator.'],
        ];
    }

    /** What a model that IGNORED the buyer rule would return: a confident lead for every story, no context set. */
    private function lead(array $s): array
    {
        return [
            'company_name' => $s['company'], 'website' => null, 'industry' => null, 'location' => null, 'buyer_type' => 'enterprise', 'context' => null,
            'trigger_type' => 'expansion',
            'needs_statement' => "{$s['company']} is growing and needs a school system running from day one.",
            'closing_date' => null, 'what_happened' => $s['fact'], 'trigger_summary' => 'Growth points to systems being set up now.',
            'likely_problem' => 'Growth needs admissions, fees and attendance working from the first day. Our school management system covers all three.',
            'what_we_could_sell' => 'school management system for the new campus covering admissions, fees and attendance', 'offer_id' => null,
            'entry_point' => 'discovery_meeting', 'entry_point_reason' => null,
            'soft_marketing_angle' => 'Ask for the principal or the trustee. Open with running every campus from one system.',
            'likely_stakeholder' => null,
            'scores' => ['buying_signal' => 5, 'problem_fit' => 5, 'product_fit' => 5, 'accessibility' => 4, 'urgency' => 5, 'potential_value' => 5, 'evidence_quality' => 4, 'reasoning' => 'Strong on paper.'],
            'inference_reasoning' => null, 'event_date' => null, 'source_ids' => [], 'evidence_quote' => $s['quote'],
        ];
    }

    /** Run a research pass whose search returns $stories and whose model returns a lead for each. */
    private function research(array $stories): void
    {
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstance(\Illuminate\Http\Client\Factory::class);
        $results = [];
        $leads = [];
        foreach (array_values($stories) as $i => $s) {
            $results[] = ['url' => $s['url'], 'title' => $s['title'], 'content' => $s['content'], 'published_date' => '2026-10-15'];
            $lead = $this->lead($s);
            $lead['source_ids'] = ['S' . ($i + 1)];
            $leads[] = $lead;
        }
        Http::fake([
            'api.tavily.com/*' => Http::response(['results' => $results], 200),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['opportunities' => $leads])]]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ], 200),
            '*/robots.txt' => Http::response('', 404),
            '*' => Http::response('<html><body><p>nothing</p></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
        $this->artisan('signals:research', ['--tenant' => self::T, '--trigger' => 'manual'])->assertExitCode(0);
    }

    // ── the prompt ───────────────────────────────────────────────────────────

    public function test_the_research_prompt_states_who_is_a_buyer_before_the_strict_rules(): void
    {
        $this->research([$this->stories()['podar']]);

        $body = collect(Http::recorded())->map(fn ($p) => $p[0])->first(fn (Request $r) => str_contains($r->url(), 'generativelanguage'));
        // Every string in the request, decoded (the system prompt may be nested JSON), joined: quotes compare as quotes.
        $strings = [];
        $data = $body->data();
        array_walk_recursive($data, function ($v) use (&$strings) {
            if (is_string($v)) {
                $strings[] = $v;
            }
        });
        $prompt = implode("
", $strings);

        $this->assertNotFalse($at = strpos($prompt, 'WHO IS A BUYER'));
        $this->assertLessThan(strpos($prompt, 'STRICT RULES'), $at, 'the buyer rule comes before the strict rules');
        foreach ([
            'would USE our offer on its own students, staff or data',
            'are NOT buyers',
            '"competitor"', '"market"',
            'own product launch, funding, award, event appearance or expansion abroad',
            'a problem we solve', 'a reason to act now', 'a person who decides', 'trust', 'a route to buy',
            'scores.reasoning', 'buying_signal is 4 or 5 only when BOTH',
            'Brahma AI raises USD 150 million', 'Vaia expands its AI platform into the US', 'NEC India signs a distribution deal', 'RSK Business Solutions demos AI',
        ] as $needle) {
            $this->assertStringContainsString($needle, $prompt, $needle);
        }
    }

    // ── the four real vendor stories ─────────────────────────────────────────

    public function test_the_four_vendor_stories_produce_no_lead_in_immediate_action_or_the_watchlist(): void
    {
        $s = $this->stories();
        $this->research([$s['brahma'], $s['vaia'], $s['nec'], $s['rsk']]);

        $this->assertSame(0, CompanyOpportunity::whereIn('feed_section', ['immediate_action', 'watchlist'])->count());
        foreach (CompanyOpportunity::all() as $o) {
            $this->assertContains($o->feed_section, [SectionAssigner::MARKET, SectionAssigner::COMPETITOR], "stored only as context: {$o->title}");
        }
    }

    public function test_a_real_school_in_the_same_run_is_still_a_lead(): void
    {
        $s = $this->stories();
        $this->research([$s['brahma'], $s['podar'], $s['vaia'], $s['nec'], $s['rsk']]);

        $leads = CompanyOpportunity::whereIn('feed_section', ['immediate_action', 'watchlist'])->get();
        $this->assertCount(1, $leads);
        $this->assertStringContainsString('Podar World School', $leads->first()->title);
        $this->assertSame(4, CompanyOpportunity::whereIn('feed_section', [SectionAssigner::MARKET, SectionAssigner::COMPETITOR])->count());
    }

    // ── the gate ─────────────────────────────────────────────────────────────

    private function importRecord(array $o = []): array
    {
        return $o + [
            'buyer' => ['name' => 'Some Vendor'], 'trigger_summary' => 'Raised funding.', 'event_date' => '2026-10-10',
            'source_url' => 'https://news.example.com/x', 'claim_level' => 'confirmed', 'fetch_level' => 'page_text',
        ];
    }

    public function test_the_gate_turns_a_vendor_the_model_forgot_into_a_context_item_and_keeps_a_competitor_a_competitor(): void
    {
        $gate = new QualificationGate();

        $forgot = $gate->evaluate($this->importRecord(['icp_vendor' => true]));
        $this->assertTrue($forgot['ok']);
        $this->assertSame('market', $forgot['record']['context']);

        $said = $gate->evaluate($this->importRecord(['icp_vendor' => true, 'context' => 'competitor']));
        $this->assertSame('competitor', $said['record']['context']);

        $buyer = $gate->evaluate($this->importRecord(['icp_vendor' => false]));
        $this->assertNull($buyer['record']['context']);
    }

    public function test_a_context_item_can_never_be_placed_in_immediate_action_or_the_watchlist_whatever_its_score(): void
    {
        $assigner = new SectionAssigner();
        foreach (['market' => SectionAssigner::MARKET, 'competitor' => SectionAssigner::COMPETITOR] as $context => $section) {
            foreach (['confirmed', 'inference', 'hypothesis'] as $claim) {
                foreach ([null, 5, 22, 35] as $score) {
                    $this->assertSame($section, $assigner->assign(['claim_level' => $claim, 'fetch_level' => 'full_document', 'score_total' => $score, 'context' => $context]), "{$context} / {$claim} / {$score}");
                }
            }
        }
    }
}
