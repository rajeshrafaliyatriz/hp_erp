<?php

namespace Tests\Unit;

use App\Domain\Signals\Icp\IcpPack;
use App\Domain\Signals\Icp\IcpPhrases;
use App\Domain\Signals\Icp\IcpProfileValidator;
use App\Domain\Signals\Icp\IcpScorer;
use Tests\TestCase;

/**
 * The ICP engine is not about schools. These tests run a HOSPITAL organisation's ICP through the same code, check
 * that bad ICPs are refused before they can be saved, and that a phrase can never behave like a regular expression.
 */
class IcpGenericTest extends TestCase
{
    /** A completely different organisation: staff scheduling software sold to hospitals. */
    private function hospital(array $over = []): array
    {
        return $over + [
            'label' => 'Staff scheduling for hospitals', 'product_line' => 'rostering', 'industry_label' => 'hospitals',
            'buyer_types' => ['hospital'], 'regions' => ['Gujarat'],
            'parts' => ['rostering' => 'staff rostering and attendance'],
            'must_have' => [
                ['attribute' => 'beds', 'op' => '>=', 'value' => 100, 'label' => 'under 100 beds', 'prompt' => 'fewer than 100 beds'],
                ['field' => 'state', 'op' => 'in_regions', 'label' => 'outside Gujarat', 'prompt' => 'a location outside Gujarat'],
            ],
            'disqualifiers' => [['label' => 'veterinary or dental clinic', 'phrases' => ['veterinary', 'dental clinic', 'pet hospital']]],
            'criteria' => [
                ['key' => 'beds', 'label' => 'Beds', 'max' => 20, 'rules' => [['range' => [300, 9999], 'points' => 20], ['range' => [100, 299], 'points' => 10]]],
                ['key' => 'nabh', 'label' => 'NABH accredited', 'max' => 10, 'values' => ['yes' => 10]],
            ],
            'evidence_points' => ['active_triggers' => 20],
            'triggers' => ['new_wing' => ['source' => 'news', 'part' => 'rostering', 'decay' => 90, 'pain' => 'a new wing needs rosters from day one', 'phrases' => ['new wing', 'new block']]],
            'queries' => [['id' => 'h-job-nurse', 'source' => 'job', 'topic' => 'general', 'days' => 60, 'q' => 'hospital recruitment "staff nurse" Gujarat']],
            'per_run' => ['catalogue' => 1, 'accounts' => 1],
            'account_query' => '"{name}" {city} (hiring OR "new wing")', 'account_days' => 30, 'account_recheck_days' => 7,
            'buying_centre' => ['nursing superintendent' => 'owns rosters and staffing'],
        ];
    }

    public function test_a_hospital_icp_is_valid_and_scores_with_the_same_engine(): void
    {
        $this->assertSame([], (new IcpProfileValidator())->validate($this->hospital()));
        $s = new IcpScorer();

        $big = $s->score(['name' => 'City General Hospital', 'state' => 'Gujarat', 'attributes' => ['beds' => 450, 'nabh' => 'yes']], $this->hospital());
        $this->assertSame('candidate', $big['status']);
        $this->assertSame(30, $big['static_points']);
        $this->assertSame(100, $big['fit_pct']);

        $small = $s->score(['name' => 'Little Care Hospital', 'state' => 'Gujarat', 'attributes' => ['beds' => 60]], $this->hospital());
        $this->assertSame('disqualified', $small['status']);
        $this->assertSame('under 100 beds', $small['notes']['disqualified_by']);

        $this->assertSame('veterinary or dental clinic', $s->score(['name' => 'Rover Pet Hospital', 'state' => 'Gujarat'], $this->hospital())['notes']['disqualified_by']);
        $this->assertSame('outside Gujarat', $s->score(['name' => 'Some Hospital', 'state' => 'Kerala', 'attributes' => ['beds' => 500]], $this->hospital())['notes']['disqualified_by']);

        // nothing known: unscored, never rejected
        $this->assertSame('unscored', $s->score(['name' => 'Unknown Hospital'], $this->hospital())['status']);
    }

    public function test_the_prompt_for_a_hospital_icp_says_hospital_things_and_nothing_about_schools(): void
    {
        $text = (new IcpPack('hospitals', $this->hospital()))->promptBlock();

        $this->assertStringContainsString('We sell Staff scheduling for hospitals to hospitals', $text);
        $this->assertStringContainsString('staff rostering and attendance', $text);
        $this->assertStringContainsString('nursing superintendent', $text);
        $this->assertStringContainsString('fewer than 100 beds', $text);
        $this->assertStringContainsString('veterinary or dental clinic', $text);
        $this->assertDoesNotMatchRegularExpression('/school|student|grade|trustee|campus/i', $text);
    }

    public function test_a_hospital_pack_plans_its_own_searches_and_decay(): void
    {
        $pack = new IcpPack('hospitals', $this->hospital());
        $this->assertSame('hospital recruitment "staff nurse" Gujarat', $pack->catalogueForRun(now())[0]['q']);
        $this->assertStringContainsString('"City General" Surat', $pack->accountQuery('City General', 'Surat')['q']);
        $this->assertNull($pack->productLine() === 'rostering' ? null : 'x');
        $this->assertSame(90, $pack->decayDays('expansion'), 'an ICP with no decay table falls back to 90 days');
    }

    public function test_the_units_in_a_message_are_written_the_indian_way(): void
    {
        $this->assertSame('1,00,000', IcpScorer::inr(100000));
        $this->assertSame('25,000', IcpScorer::inr(25000));
        $this->assertSame('1,23,45,678', IcpScorer::inr(12345678));
    }

    // ── a phrase is never a pattern ──────────────────────────────────────────

    public function test_phrases_match_whole_words_case_insensitively_and_flexible_spaces(): void
    {
        $this->assertTrue(IcpPhrases::matches(['play school'], 'Little Stars PLAY   School'));
        $this->assertTrue(IcpPhrases::matches(['iit-jee'], 'Apex IIT-JEE classes'));
        $this->assertFalse(IcpPhrases::matches(['nit'], 'Community Unit School'), 'nit inside a word is not a match');
        $this->assertFalse(IcpPhrases::matches([], 'anything'));
    }

    public function test_pattern_characters_in_a_phrase_are_taken_literally_so_nothing_can_freeze_the_server(): void
    {
        // As a regular expression this is catastrophic backtracking; as a phrase it is just odd text that matches nothing.
        $evil = '(a+)+$';
        $start = microtime(true);
        $this->assertFalse(IcpPhrases::matches([$evil], str_repeat('a', 5000) . '!'));
        $this->assertLessThan(0.5, microtime(true) - $start);
        $this->assertTrue(IcpPhrases::matches(['a.b'], 'x a.b y'));
        $this->assertFalse(IcpPhrases::matches(['a.b'], 'x aXb y'), 'a dot is a dot');
    }

    // ── validation ───────────────────────────────────────────────────────────

    /** @dataProvider badIcps */
    public function test_a_bad_icp_is_refused_with_a_reason(array $change, string $mentions): void
    {
        // The validator's search ceiling is configurable (default 14). These cases are written against a ceiling of 6, so they pin it.
        config(['signals.opportunities.max_queries' => 6]);
        $problems = (new IcpProfileValidator())->validate($change + $this->hospital());

        $this->assertNotSame([], $problems, 'expected a problem for: ' . $mentions);
        $this->assertStringContainsString($mentions, implode(' | ', $problems));
    }

    public static function badIcps(): array
    {
        $q = fn (array $o) => [$o + ['id' => 'q1', 'source' => 'news', 'topic' => 'news', 'days' => 30, 'q' => 'hospital new wing Gujarat']];

        return [
            'unknown setting' => [['webhook' => 'http://evil'], 'unknown setting'],
            'no label' => [['label' => ''], 'label'],
            'a pattern in a disqualifier' => [['disqualifiers' => [['label' => 'x', 'phrases' => ['(a+)+$']]]], 'pattern characters'],
            'a disqualifier with no phrases' => [['disqualifiers' => [['label' => 'x', 'phrases' => []]]], 'at least one phrase'],
            'bad operator' => [['must_have' => [['attribute' => 'beds', 'op' => 'LIKE', 'value' => 1, 'label' => 'x']]], 'one of'],
            'between needs two numbers' => [['must_have' => [['attribute' => 'fee', 'op' => 'between', 'value' => [5], 'label' => 'x']]], 'between needs'],
            'attribute and field together' => [['must_have' => [['attribute' => 'a', 'field' => 'b', 'op' => '=', 'value' => 1, 'label' => 'x']]], 'exactly one'],
            'in_regions without regions' => [['regions' => [], 'must_have' => [['field' => 'state', 'op' => 'in_regions', 'label' => 'x']]], 'regions'],
            'points above the criterion max' => [['criteria' => [['key' => 'beds', 'label' => 'Beds', 'max' => 10, 'rules' => [['range' => [1, 5], 'points' => 20]]]]], 'points'],
            'criterion with neither rules nor values' => [['criteria' => [['key' => 'beds', 'label' => 'Beds', 'max' => 10]]], 'exactly one'],
            'total above 100' => [['evidence_points' => ['a' => 60, 'b' => 60]], 'must not exceed 100'],
            'bad topic' => [['queries' => $q(['topic' => 'images'])], 'news or general'],
            'days out of range' => [['queries' => $q(['days' => 9999])], 'days 1 to 365'],
            'a query with a line break' => [['queries' => $q(['q' => "one\ntwo"])], 'single line'],
            'a domain that is a url' => [['queries' => $q(['domains' => ['https://evil.com/x']])], 'plain site name'],
            'too many queries' => [['queries' => array_map(fn ($i) => ['id' => "q{$i}", 'source' => 'news', 'topic' => 'news', 'days' => 30, 'q' => "query number {$i}"], range(1, 41))], 'at most 40'],
            'searches per run above the limit' => [['per_run' => ['catalogue' => 6, 'accounts' => 6]], 'must not exceed'],
            'account query without {name}' => [['account_query' => 'hospital hiring'], '{name}'],
            'a trigger on a part that does not exist' => [['triggers' => ['t' => ['source' => 'news', 'part' => 'ghost', 'decay' => 30, 'phrases' => ['new wing']]]], "ICP's parts"],
            'priority 1 needing less than priority 2' => [['priority' => [1 => 40, 2 => 75]], 'highest score'],
            'a month that does not exist' => [['buying_window_months' => [13]], 'months 1 to 12'],
        ];
    }

    public function test_an_enormous_icp_is_refused_before_anything_else(): void
    {
        $problems = (new IcpProfileValidator())->validate($this->hospital(['guidance' => str_repeat('x', 70000)]));

        $this->assertStringContainsString('too large', $problems[0]);
    }
}
