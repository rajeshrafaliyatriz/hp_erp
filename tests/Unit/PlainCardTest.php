<?php

namespace Tests\Unit;

use App\Domain\Signals\Market\CardPresenter;
use App\Domain\Signals\Market\PlainLanguageRules;
use App\Domain\Signals\Market\QualificationGate;
use Tests\TestCase;

/**
 * Defect 5: the face of the card is plain English, written that way at generation time and enforced
 * by the gate, with every internal value translated in one place (CardPresenter).
 */
class PlainCardTest extends TestCase
{
    private function codes(array $face, bool $needsSoWhat = true): array
    {
        return array_values(array_unique(array_column((new PlainLanguageRules())->violations($face, $needsSoWhat), 'code')));
    }

    private function good(): array
    {
        return [
            'what_happened' => 'They have picked 11 edtech products to roll out in government schools. The programme is backed by ₹170 crore over three years, running to 2029.',
            'likely_problem' => 'Those 11 products now have to work inside state government systems. That is integration work, and Scholar Clone fits it.',
            'soft_marketing_angle' => 'Approach the chosen vendors, and the foundation that runs the programme. Not the programme itself, because the selection is already closed.',
        ];
    }

    public function test_the_target_register_passes_every_rule(): void
    {
        $this->assertSame([], $this->codes($this->good()));
    }

    /** @dataProvider jargon */
    public function test_internal_vocabulary_is_rejected_on_the_face(string $field, string $text): void
    {
        $this->assertContains('jargon_on_card', $this->codes([$field => $text] + $this->good()), $text);
    }

    public static function jargon(): array
    {
        return [
            ['what_happened', 'This is a strong buying signal from the college.'],
            ['likely_problem', 'Our inference is that they need analytics. Scholar Clone fits it.'],
            ['soft_marketing_angle', 'Use the pilot entry point and call the principal.'],
            ['likely_problem', 'Their pipeline needs this. Scholar Clone fits the gap well.'],
            ['likely_problem', 'This is a hypothesis about a need. Scholar Clone might fit it.'],
            ['likely_problem', 'Offer EB-03 to them because they need ingestion. It fits well.'],
            ['what_happened', 'The fund is worth 1700000000 over three years in total.'],
        ];
    }

    public function test_a_normal_use_of_confirmed_in_prose_is_allowed(): void
    {
        $this->assertSame([], $this->codes(['what_happened' => 'The college confirmed 400 new admissions this year. It is hiring staff.'] + $this->good()));
    }

    public function test_a_sentence_over_twenty_words_is_rejected(): void
    {
        $long = 'The programme which is backed by several large donors and run by a well known foundation has now chosen eleven different products for use in many classrooms.';
        $this->assertContains('sentence_too_long', $this->codes(['what_happened' => $long] + $this->good()));
    }

    public function test_running_to_too_many_sentences_is_rejected(): void
    {
        $this->assertContains('sentence_too_long', $this->codes(['soft_marketing_angle' => 'Call them. Call again. Visit them. Email them.'] + $this->good()));
    }

    public function test_a_missing_or_thin_so_what_is_rejected(): void
    {
        $this->assertContains('missing_so_what', $this->codes(['likely_problem' => ''] + $this->good()));
        $this->assertContains('missing_so_what', $this->codes(['likely_problem' => 'They need our product.'] + $this->good()));
        $this->assertContains('missing_so_what', $this->codes(['likely_problem' => 'They are growing fast and will need better software soon.'] + $this->good())); // one sentence
        $this->assertNotContains('missing_so_what', $this->codes(['likely_problem' => ''] + $this->good(), needsSoWhat: false)); // context items name no buyer
    }

    public function test_the_gate_rejects_with_these_codes_and_names_the_field(): void
    {
        $gate = new QualificationGate();
        $base = [
            'buyer' => ['name' => 'ShikshaNext', 'website' => 'https://shikshanext.org'], 'trigger_summary' => 'Selection closed, rollout starts.',
            'event_date' => '2026-09-02', 'source_url' => 'https://shikshanext.org/news', 'fetch_level' => 'page_text', 'evidence' => ['quote_verified' => true],
            'what_we_could_sell' => 'integration pilot for the 11 selected products in 3 states', 'entry_point' => 'pilot',
            'scores' => ['buying_signal' => 4, 'problem_fit' => 4, 'product_fit' => 4, 'accessibility' => 3, 'urgency' => 3, 'potential_value' => 4, 'evidence_quality' => 4],
        ] + $this->good();

        $this->assertTrue($gate->evaluate($base)['ok']);

        $r = $gate->evaluate(['likely_problem' => 'A strong buying signal. Our platform fits it nicely.'] + $base);
        $this->assertSame('jargon_on_card', $r['code']);
        $this->assertStringContainsString('likely_problem', $r['reason']);

        $this->assertSame('missing_so_what', $gate->evaluate(['likely_problem' => 'They need us.'] + $base)['code']);
        $this->assertSame('sentence_too_long', $gate->evaluate(['what_happened' => str_repeat('word ', 25) . 'end.'] + $base)['code']);
    }

    public function test_the_import_path_is_not_held_to_the_face_rules(): void
    {
        // A record without an evidence block (the daily-scan import) keeps its long-standing behaviour.
        $r = (new QualificationGate())->evaluate([
            'buyer' => ['name' => 'Dept of School Education'], 'trigger_summary' => 'Tender for LMS.', 'event_date' => '2026-09-02',
            'source_url' => 'https://x.gov.in/t', 'claim_level' => 'confirmed', 'fetch_level' => 'page_text',
            'what_happened' => 'A tender signal in the pipeline with a 1700000000 value.',
        ]);
        $this->assertTrue($r['ok']);
    }

    public function test_urgency_is_stored_as_a_band_from_the_deadline_the_urgency_score_and_the_section(): void
    {
        $p = new CardPresenter();
        $this->assertSame('act_this_week', $p->band(5, 2, 'watchlist'));
        $this->assertSame('act_this_week', $p->band(null, 5, 'watchlist'));
        $this->assertSame('act_this_month', $p->band(20, 1, 'watchlist'));
        $this->assertSame('act_this_month', $p->band(null, 3, 'watchlist'));
        $this->assertSame('act_this_month', $p->band(null, 1, 'immediate_action'));
        $this->assertSame('nurture', $p->band(null, 2, 'watchlist'));
        $this->assertSame('nurture', $p->band(-3, 1, 'watchlist')); // expired: no longer worth acting on
        $this->assertSame('act_this_week', $p->normaliseBand('Immediate', null, null, null)); // an older stored value
    }

    public function test_the_card_face_reads_like_the_worked_example(): void
    {
        $face = (new CardPresenter())->face([
            'company_name' => 'ShikshaNext', 'company_type' => 'other', 'company_state' => null, 'location' => 'India', 'website' => null,
            'observed_event' => $this->good()['what_happened'], 'likely_problem' => $this->good()['likely_problem'], 'why_indicates_need' => 'x',
            'soft_marketing_angle' => $this->good()['soft_marketing_angle'], 'recommended_action' => 'x', 'entry_point' => 'si_partnership',
            'urgency' => 'act_this_month', 'feed_section' => 'watchlist', 'days_left' => null, 'claim_level' => 'inference',
            'import_source' => 'web-research', 'source_url' => 'https://www.thehindu.com/news/x', 'source_published_at' => '2026-09-02',
        ]);

        $this->assertSame('Worth acting on this month', $face['urgency_line']);
        $this->assertSame('ShikshaNext — organisation, India', $face['organisation_line']);
        $this->assertSame('They have picked 11 edtech products to roll out in government schools.', $face['headline']);
        $this->assertSame('The programme is backed by ₹170 crore over three years, running to 2029.', $face['context']);
        $this->assertSame($this->good()['likely_problem'], $face['so_what']);
        $this->assertSame($this->good()['soft_marketing_angle'], $face['what_to_do']);
        $this->assertSame('The facts are solid. That they need us is our read, not their statement.', $face['how_sure']);
        $this->assertSame('From thehindu.com, 2 September 2026', $face['source']['text']);
        $this->assertTrue($face['written_plainly']);
    }

    public function test_every_stored_value_has_plain_words_and_no_internal_word_reaches_the_face(): void
    {
        $this->assertSame('Propose a small paid pilot', CardPresenter::ENTRY_PHRASES['pilot']);
        $this->assertSame('Go in through a partner already doing the work', CardPresenter::ENTRY_PHRASES['si_partnership']);
        $this->assertSame('Their own announcement says this.', CardPresenter::SURENESS['confirmed']);
        $this->assertSame('government department', CardPresenter::ORG_KINDS['government']);

        $banned = (array) config('signals.card.plain.banned_terms');
        $words = array_merge(array_values(CardPresenter::URGENCY_LINES), array_values(CardPresenter::ENTRY_PHRASES), array_values(CardPresenter::SURENESS), array_values(CardPresenter::ORG_KINDS));
        foreach ($words as $text) {
            foreach ($banned as $term) {
                $this->assertDoesNotMatchRegularExpression('/\b' . preg_quote($term, '/') . '\b/i', $text, "'{$text}' contains banned '{$term}'");
            }
        }
    }

    public function test_urgency_line_counts_down_when_a_deadline_exists(): void
    {
        $f = fn (?int $d) => (new CardPresenter())->face(['company_name' => 'X', 'urgency' => 'act_this_week', 'days_left' => $d, 'observed_event' => 'A thing happened.']);
        $this->assertSame('Needs attention this week — closes in 3 days', $f(3)['urgency_line']);
        $this->assertSame('Needs attention this week — closes in 1 day', $f(1)['urgency_line']);
        $this->assertSame('Needs attention this week — closes today', $f(0)['urgency_line']);
        $this->assertSame('Needs attention this week', $f(null)['urgency_line']);
    }

    public function test_an_older_lead_still_gets_a_sensible_face_and_is_marked_as_not_written_plainly(): void
    {
        $face = (new CardPresenter())->face([
            'company_name' => 'Triz', 'observed_event' => 'Offer G2G-01 is configured as SaaS.', 'why_indicates_need' => 'Selling SaaS-only models can lose deals.',
            'recommended_action' => 'Confirm hybrid options.', 'claim_level' => null, 'urgency' => 'Within 30 days',
        ]);
        $this->assertFalse($face['written_plainly']);
        $this->assertSame('Worth acting on this month', $face['urgency_line']);
        $this->assertSame('Selling SaaS-only models can lose deals.', $face['so_what']);
        $this->assertSame('Confirm hybrid options.', $face['what_to_do']);
        $this->assertSame(CardPresenter::SURENESS['hypothesis'], $face['how_sure']); // unknown evidence is never presented as solid
    }
}
