<?php

namespace Tests\Unit;

use App\Domain\Signals\Market\EvidenceGrader;
use App\Domain\Signals\Market\QualificationGate;
use App\Domain\Signals\Market\SectionAssigner;
use Tests\TestCase;

/**
 * The lead card's rules: evidence is derived, sections come from config, vague sell lines and
 * dump-bucket entry points are refused. All pure; thresholds are read from config('signals.card').
 */
class SignalsCardRulesTest extends TestCase
{
    private function grade(array $evidence, string $fetch, string $url, array $buyer = ['name' => 'Acme Industries', 'website' => null]): array
    {
        return (new EvidenceGrader())->grade($evidence, $fetch, $url, $buyer);
    }

    public function test_a_primary_source_read_in_full_with_a_verified_quote_is_confirmed(): void
    {
        $g = $this->grade(['quote_verified' => true], 'page_text', 'https://education.punjab.gov.in/tender/12');
        $this->assertSame('confirmed', $g['claim_level']);
        $this->assertTrue($g['primary']);
    }

    public function test_a_news_headline_is_never_confirmed(): void
    {
        $g = $this->grade(['quote_verified' => true], 'full_document', 'https://news.example.com/acme-pune');
        $this->assertSame('hypothesis', $g['claim_level']);
        $this->assertStringContainsString('secondary', $g['note']);
    }

    public function test_the_buyers_own_domain_is_primary_for_that_buyer_only(): void
    {
        $buyer = ['name' => 'Acme Industries', 'website' => 'https://www.acme-industries.com'];
        $this->assertSame('confirmed', $this->grade(['quote_verified' => true], 'page_text', 'https://careers.acme-industries.com/pune', $buyer)['claim_level']);
        $this->assertSame('hypothesis', $this->grade(['quote_verified' => true], 'page_text', 'https://rival-news.example.org/acme', $buyer)['claim_level']);
    }

    public function test_a_primary_source_seen_only_as_a_snippet_needs_recorded_reasoning_to_be_inference(): void
    {
        $url = 'https://education.punjab.gov.in/notice';
        $this->assertSame('hypothesis', $this->grade(['quote_verified' => true], 'search_snippet', $url)['claim_level']);

        $g = $this->grade(['quote_verified' => true, 'inference_reasoning' => 'A cohort of 400 new teachers implies onboarding and training demand.'], 'search_snippet', $url);
        $this->assertSame('inference', $g['claim_level']);
        $this->assertStringContainsString('onboarding and training demand', $g['note']); // the reasoning is recorded
    }

    public function test_two_independent_domains_can_support_an_inference_but_one_cannot(): void
    {
        $why = ['inference_reasoning' => 'Two outlets report a hiring surge, which implies payroll strain.'];
        $this->assertSame('hypothesis', $this->grade(['quote_verified' => true, 'corroborating_domains' => 1] + $why, 'page_text', 'https://a.example.com/x')['claim_level']);
        $this->assertSame('inference', $this->grade(['quote_verified' => true, 'corroborating_domains' => 2] + $why, 'page_text', 'https://a.example.com/x')['claim_level']);
    }

    public function test_an_unverified_quote_is_always_a_hypothesis(): void
    {
        $this->assertSame('hypothesis', $this->grade(['quote_verified' => false, 'inference_reasoning' => 'Reasoned from something fairly long.'], 'full_document', 'https://x.gov.in/a')['claim_level']);
    }

    /** @dataProvider sections */
    public function test_section_assignment_comes_from_config_thresholds(array $s, ?string $expected): void
    {
        $this->assertSame($expected, (new SectionAssigner())->assign($s));
    }

    public static function sections(): array
    {
        return [
            'confirmed, 22' => [['claim_level' => 'confirmed', 'score_total' => 22], 'immediate_action'],
            'inference, 30' => [['claim_level' => 'inference', 'score_total' => 30], 'immediate_action'],
            'confirmed, 21 -> watching' => [['claim_level' => 'confirmed', 'score_total' => 21], 'watchlist'],
            'hypothesis, 35 never acts now' => [['claim_level' => 'hypothesis', 'score_total' => 35], 'watchlist'],
            'hypothesis, 5 still watching' => [['claim_level' => 'hypothesis', 'score_total' => 5], 'watchlist'],
            'inference, 14 is below the bar' => [['claim_level' => 'inference', 'score_total' => 14], null],
            'competitor move is context' => [['claim_level' => 'confirmed', 'score_total' => 35, 'context' => 'competitor'], 'competitor_intelligence'],
            'market event is context' => [['claim_level' => 'hypothesis', 'score_total' => null, 'context' => 'market'], 'market_intelligence'],
            'scoreless confirmed page text keeps the old rule' => [['claim_level' => 'confirmed', 'fetch_level' => 'page_text', 'score_total' => null], 'immediate_action'],
            'scoreless inference is watching' => [['claim_level' => 'inference', 'fetch_level' => 'page_text', 'score_total' => null], 'watchlist'],
        ];
    }

    public function test_changing_the_config_changes_the_sections(): void
    {
        config(['signals.card.sections.act_now_min_score' => 30]);
        $this->assertSame('watchlist', (new SectionAssigner())->assign(['claim_level' => 'confirmed', 'score_total' => 25]));
    }

    /** @dataProvider vagueLines */
    public function test_vague_sell_lines_are_refused(string $line, bool $vague): void
    {
        $this->assertSame($vague, (new QualificationGate())->isVagueSellLine($line), $line);
    }

    public static function vagueLines(): array
    {
        return [
            ['AI solution', true], ['digital transformation', true], ['our platform', true], ['Our AI platform', true],
            ['AI-powered digital transformation solutions', true], ['We can offer our end-to-end solutions', true],
            ['capability mapping pilot for 3 departments', false],
            ['payroll automation pilot for the new Pune plant HR team', false],
            ['teacher onboarding analytics workshop for 12 district offices', false],
        ];
    }

    private function record(array $over = []): array
    {
        return $over + [
            'buyer' => ['name' => 'Acme Industries', 'website' => 'https://acme-industries.com'], 'trigger_summary' => 'Hiring 200 people in Pune.', 'event_date' => '2026-10-01',
            'source_url' => 'https://acme-industries.com/news/pune', 'claim_level' => 'confirmed', 'fetch_level' => 'page_text',
            'what_we_could_sell' => 'payroll automation pilot for the new Pune plant HR team',
        ];
    }

    public function test_the_gate_rejects_a_vague_sell_line_and_an_unexplained_other(): void
    {
        $gate = new QualificationGate();
        $this->assertSame('vague_sell_line', $gate->evaluate($this->record(['what_we_could_sell' => 'AI solution']))['code']);
        $this->assertSame('entry_point_other_needs_reason', $gate->evaluate($this->record(['entry_point' => 'other']))['code']);
        $this->assertTrue($gate->evaluate($this->record(['entry_point' => 'other', 'entry_point_reason' => 'A grant-funded programme with no standard route']))['ok']);
        $this->assertTrue($gate->evaluate($this->record(['entry_point' => 'pilot']))['ok']);
    }

    public function test_a_researched_lead_must_carry_the_full_card_and_scores(): void
    {
        $gate = new QualificationGate();
        $base = $this->record(['claim_level' => null, 'evidence' => ['quote_verified' => true], 'entry_point' => 'pilot']);

        $incomplete = $gate->evaluate($base);
        $this->assertSame('incomplete_card', $incomplete['code']);
        $this->assertStringContainsString('soft_marketing_angle', $incomplete['reason']);

        $full = $base + ['soft_marketing_angle' => 'Open with payroll errors during a hiring surge.', 'likely_problem' => 'Manual payroll is straining under the hiring surge. Our payroll automation fits that need.'];
        $this->assertSame('missing_scores', $gate->evaluate($full)['code']);

        $scores = ['buying_signal' => 4, 'problem_fit' => 4, 'product_fit' => 4, 'accessibility' => 3, 'urgency' => 3, 'potential_value' => 3, 'evidence_quality' => 3];
        $ok = $gate->evaluate($full + ['scores' => $scores]);
        $this->assertTrue($ok['ok']);
        $this->assertSame('confirmed', $ok['record']['claim_level']); // derived: buyer's own domain, page_text, verified quote
    }

    public function test_a_non_hypothesis_lead_below_the_score_bar_is_rejected(): void
    {
        $gate = new QualificationGate();
        $low = ['buying_signal' => 1, 'problem_fit' => 2, 'product_fit' => 2, 'accessibility' => 2, 'urgency' => 1, 'potential_value' => 2, 'evidence_quality' => 2];
        $r = $gate->evaluate($this->record([
            'claim_level' => null, 'evidence' => ['quote_verified' => true], 'entry_point' => 'pilot', 'scores' => $low,
            'soft_marketing_angle' => 'Open with payroll errors during a hiring surge.', 'likely_problem' => 'Manual payroll is straining under the hiring surge. Our payroll automation fits that need.',
        ]));
        $this->assertSame('below_score_bar', $r['code']);
    }
}
