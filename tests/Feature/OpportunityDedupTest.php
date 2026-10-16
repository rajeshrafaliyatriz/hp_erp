<?php

namespace Tests\Feature;

use App\Domain\Signals\Market\MarketImporter;
use App\Domain\Signals\Market\OpportunityEvent;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * One event is one opportunity. The fingerprint includes the source URL, so the same event on two news sites used to
 * become two opportunities. The importer now recognises the same event (same buyer and trigger, dates within 7 days, or
 * the same tender reference) and adds the new source instead. Runs on the in-memory sqlite database.
 */
class OpportunityDedupTest extends TestCase
{
    private const T = 1;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables', '2026_09_30_180000_add_signal_fields_to_ingestion_findings',
            '2026_09_30_200000_add_research_sources_and_signal_kind', '2026_09_30_220000_create_g2g_portfolio_and_partners_tables',
            '2026_09_30_230000_add_claude_feed_and_linking_fields', '2026_10_01_130000_add_stage_to_g2g_research_runs',
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_11_29_090000_add_assessment_to_g2g_company_opportunities', '2026_12_01_090000_add_product_key_to_signals_runs_and_sources', '2026_10_14_100000_create_g2g_icp_profiles_tables',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        Cache::flush();
        Carbon::setTestNow('2026-10-20 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $o */
    private function record(array $o = []): array
    {
        return $o + [
            'buyer' => ['name' => 'Podar World School', 'type' => 'school', 'state' => 'Gujarat'],
            'trigger_type' => 'expansion', 'trigger_summary' => 'Opening a new campus in Vadodara.',
            'what_happened' => 'Podar World School is opening a new campus in Vadodara.', 'event_date' => '2026-10-10',
            'source_url' => 'https://news-a.example.com/podar-campus', 'source_title' => 'Podar opens campus',
            'claim_level' => 'hypothesis', 'fetch_level' => 'search_snippet',
        ];
    }

    /** @param list<array<string, mixed>> $records @return array<string, mixed> */
    private function import(array $records): array
    {
        return app(MarketImporter::class)->import(self::T, $records, ['source_label' => 'test']);
    }

    /** A researched-style record that passes the full card rules. */
    private function researched(array $o = []): array
    {
        return $o + [
            'buyer' => ['name' => 'Podar World School', 'type' => 'school', 'state' => null, 'website' => null, 'aliases' => []],
            'context' => null, 'trigger_type' => 'expansion',
            'trigger_summary' => 'A new campus and an ERP hire point to systems being set up now.',
            'headline' => 'Podar World School is opening a new campus and needs a school system running from day one.',
            'what_happened' => 'Podar World School is opening a new campus in Vadodara. It is also hiring an ERP administrator.',
            'likely_problem' => 'A new campus needs admissions, fees and attendance working from the first day. Our school management system covers all three.',
            'what_we_could_sell' => 'school management system for the new Vadodara campus covering admissions, fees and attendance',
            'entry_point' => 'discovery_meeting', 'soft_marketing_angle' => 'Ask for the principal or the trustee. Open with running the new campus from one system.',
            'source_url' => 'https://news-a.example.com/podar-campus', 'source_title' => 'Podar opens campus', 'source_published_at' => '2026-10-10',
            'event_date' => '2026-10-10', 'observed_at' => '2026-10-20 10:00:00', 'fetch_level' => 'page_text',
            'evidence' => ['quote_verified' => true, 'corroborating_domains' => 1, 'inference_reasoning' => null],
            'source_excerpt' => 'Podar World School announced a new campus in Vadodara', 'content_hash' => 'aaa',
            'scores' => ['buying_signal' => 4, 'problem_fit' => 4, 'product_fit' => 4, 'accessibility' => 3, 'urgency' => 4, 'potential_value' => 3, 'evidence_quality' => 3, 'reasoning' => 'A new campus and an ERP hire.'],
        ];
    }

    // ── the same event ───────────────────────────────────────────────────────

    public function test_one_event_on_two_sites_is_one_opportunity_with_two_sources(): void
    {
        $first = $this->import([$this->record()]);
        $second = $this->import([$this->record(['source_url' => 'https://news-b.example.org/vadodara-campus', 'source_title' => 'Campus coming up in Vadodara'])]);

        $this->assertSame(1, $first['accepted']);
        $this->assertSame(0, $second['accepted'], 'not a new opportunity');
        $this->assertSame(1, $second['updated']);
        $this->assertSame('corroborated', $second['results'][0]['reason']);

        $opp = CompanyOpportunity::sole();
        $this->assertCount(2, $opp->sources);
        $this->assertSame(['https://news-a.example.com/podar-campus', 'https://news-b.example.org/vadodara-campus'], array_column($opp->sources, 'url'));
    }

    public function test_the_corroboration_is_recorded_as_an_event_with_the_new_domain_count(): void
    {
        $this->import([$this->record()]);
        $this->import([$this->record(['source_url' => 'https://news-b.example.org/vadodara-campus'])]);

        $event = OpportunityEvent::where('event_type', 'corroborated')->sole();
        $this->assertSame([1, 2], $event->changes['corroborating_domains']);
        $this->assertSame([1, 2], $event->changes['sources']);
        $this->assertSame('https://news-b.example.org/vadodara-campus', $event->changes['source_url']);
    }

    public function test_corroboration_never_touches_what_a_person_decided_or_when_it_was_first_found(): void
    {
        $this->import([$this->record()]);
        $opp = CompanyOpportunity::sole();
        $firstFound = $opp->first_discovered_at->toDateTimeString();
        $opp->forceFill(['review_status' => 'Follow-up', 'pipeline_status' => 'ENGAGED', 'reviewed_by' => 7])->save();

        Carbon::setTestNow('2026-10-25 09:00:00');
        $this->import([$this->record(['source_url' => 'https://news-b.example.org/vadodara-campus'])]);

        $opp->refresh();
        $this->assertSame('Follow-up', $opp->review_status);
        $this->assertSame('ENGAGED', $opp->pipeline_status);
        $this->assertSame(7, (int) $opp->reviewed_by);
        $this->assertSame($firstFound, $opp->first_discovered_at->toDateTimeString());
    }

    public function test_the_same_source_again_is_a_duplicate_and_a_tracking_parameter_is_not_a_second_source(): void
    {
        $this->import([$this->record()]);

        $same = $this->import([$this->record()]);
        $tracked = $this->import([$this->record(['source_url' => 'https://news-a.example.com/podar-campus?utm_source=whatsapp&utm_medium=share'])]);

        $this->assertSame(1, $same['duplicate']);
        $this->assertSame(1, $tracked['duplicate']);
        $this->assertCount(1, CompanyOpportunity::sole()->sources);
        $this->assertSame(0, OpportunityEvent::where('event_type', 'corroborated')->count());
    }

    // ── the window ───────────────────────────────────────────────────────────

    /** @dataProvider daysApart */
    public function test_events_within_seven_days_are_one_and_further_apart_are_two(int $days, int $expected): void
    {
        $this->import([$this->record(['event_date' => '2026-10-01'])]);
        $this->import([$this->record(['event_date' => Carbon::parse('2026-10-01')->addDays($days)->toDateString(), 'source_url' => 'https://news-b.example.org/other'])]);

        $this->assertSame($expected, CompanyOpportunity::count(), "{$days} days apart");
    }

    public static function daysApart(): array
    {
        return [[0, 1], [3, 1], [7, 1], [8, 2], [10, 2], [30, 2]];
    }

    public function test_a_source_date_stands_in_when_the_event_has_no_date(): void
    {
        $this->import([$this->record(['event_date' => null, 'source_published_at' => '2026-10-10', 'observed_at' => '2026-10-10'])]);
        $second = $this->import([$this->record(['event_date' => null, 'source_published_at' => '2026-10-12', 'source_url' => 'https://news-b.example.org/x', 'observed_at' => '2026-10-12'])]);

        $this->assertSame(1, CompanyOpportunity::count());
        $this->assertSame(1, $second['updated'], json_encode($second['results']));
    }

    public function test_a_different_buyer_or_a_different_kind_of_trigger_is_a_different_opportunity(): void
    {
        $this->import([$this->record()]);
        $this->import([$this->record(['buyer' => ['name' => 'Ryan International School', 'type' => 'school', 'state' => 'Gujarat'], 'source_url' => 'https://news-b.example.org/ryan'])]);
        $this->import([$this->record(['trigger_type' => 'hiring', 'source_url' => 'https://careers.example.com/erp'])]);

        $this->assertSame(3, CompanyOpportunity::count());
    }

    // ── tenders ──────────────────────────────────────────────────────────────

    public function test_a_tender_with_the_same_reference_from_two_portals_is_one_opportunity(): void
    {
        $tender = fn (array $o) => $this->record($o + [
            'buyer' => ['name' => 'Education Department Gujarat', 'type' => 'government', 'state' => 'Gujarat'], 'trigger_type' => 'tender',
            'reference_no' => 'GEM/2026/B/4471', 'trigger_summary' => 'Tender for a school management system.',
            'what_happened' => 'The department invited bids for a school management system.', 'claim_level' => 'confirmed', 'fetch_level' => 'page_text',
        ]);

        $this->import([$tender(['event_date' => '2026-09-01', 'source_url' => 'https://gem.gov.in/bid/4471'])]);
        $second = $this->import([$tender(['event_date' => '2026-10-15', 'source_url' => 'https://nprocure.com/tender/4471'])]);

        $this->assertSame(1, CompanyOpportunity::count(), 'the reference decides, not the dates');
        $this->assertSame('corroborated', $second['results'][0]['reason']);
        $this->assertCount(2, CompanyOpportunity::sole()->sources);

        $this->import([$tender(['reference_no' => 'GEM/2026/B/9999', 'source_url' => 'https://gem.gov.in/bid/9999'])]);
        $this->assertSame(2, CompanyOpportunity::count(), 'a different reference is a different tender');
    }

    // ── evidence ─────────────────────────────────────────────────────────────

    public function test_a_second_independent_source_can_raise_the_evidence_level_and_never_lowers_it(): void
    {
        $first = $this->import([$this->researched()]);
        $this->assertSame(1, $first['accepted'], json_encode($first['results']));
        $opp = CompanyOpportunity::sole();
        $this->assertSame('hypothesis', $opp->claim_level);
        $this->assertSame('watchlist', $opp->feed_section);

        // A second independent site, and this time the writer recorded WHY the need follows from the fact.
        $second = $this->import([$this->researched([
            'source_url' => 'https://news-b.example.org/vadodara-campus', 'source_title' => 'Campus coming up',
            'evidence' => ['quote_verified' => true, 'corroborating_domains' => 1, 'inference_reasoning' => 'A new campus and an ERP administrator hire show systems are being set up now.'],
        ])]);

        $this->assertSame(1, $second['updated'], json_encode($second['results']));
        $opp->refresh();
        $this->assertSame('inference', $opp->claim_level, 'two domains plus recorded reasoning');
        $this->assertSame('immediate_action', $opp->feed_section, 'inference with a stored score of 25 is placed again');
        $this->assertStringContainsString('2 independent domains', $opp->evidence_note);
        $this->assertSame(['hypothesis', 'inference'], OpportunityEvent::where('event_type', 'corroborated')->sole()->changes['claim_level']);

        // A third, weaker source must not pull it back down.
        $this->import([$this->researched(['source_url' => 'https://news-c.example.net/x', 'fetch_level' => 'search_snippet'])]);
        $this->assertSame('inference', $opp->refresh()->claim_level);
        $this->assertCount(3, $opp->sources);
    }
}
