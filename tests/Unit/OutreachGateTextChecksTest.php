<?php

namespace Tests\Unit;

use App\Domain\OutreachGate\CheckResult;
use App\Domain\OutreachGate\ClaimBook;
use App\Domain\OutreachGate\Checks\BannedPhraseCheck;
use App\Domain\OutreachGate\Checks\LengthCheck;
use App\Domain\OutreachGate\Checks\LinkCountCheck;
use App\Domain\OutreachGate\Checks\PlaceholderCheck;
use App\Domain\OutreachGate\Checks\UnapprovedClaimCheck;
use App\Domain\OutreachGate\Draft;
use App\Domain\OutreachGate\GateResult;
use App\Domain\OutreachGate\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The pure checks of the outreach gate (slice 10): text in, answer out, with the configuration handed in. They extend PHPUnit's own TestCase on purpose: if a check ever
 * needed the framework, the database or a model, this file would stop running. Each check has failing and passing cases, the edges of its rule, and the traps the rule must
 * not fall into (a word inside a longer word, an e-mail address that looks like a domain, a number that is not a claim).
 */
class OutreachGateTextChecksTest extends TestCase
{
    /** @return array<string, mixed> */
    private function config(): array
    {
        return require __DIR__ . '/../../config/outreach_gate.php';
    }

    private function draft(string $body, string $subject = 'A short question'): Draft
    {
        return new Draft(Draft::GTM, 1, 1, $subject, $body);
    }

    // ------------------------------------------------------------------ banned phrases

    #[DataProvider('bannedFailing')]
    public function test_a_banned_phrase_fails_and_is_named(string $body, string $phrase): void
    {
        $r = (new BannedPhraseCheck($this->config()['banned_phrases']))->run($this->draft($body));

        $this->assertFalse($r->passed);
        $this->assertSame('banned_phrase', $r->key);
        $this->assertStringContainsString($phrase, $r->detail);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function bannedFailing(): array
    {
        return [
            'plain' => ['Please act now to book a slot.', 'act now'],
            'upper case' => ['ACT NOW and we will call.', 'act now'],
            'odd spacing' => ["Please act\n   now to book.", 'act now'],
            'with a symbol' => ['This is 100% free for you.', '100% free'],
            'hyphenated' => ['A risk-free pilot.', 'risk-free'],
            'at the very end' => ['Do it today, click here', 'click here'],
        ];
    }

    #[DataProvider('bannedPassing')]
    public function test_clean_text_and_words_that_only_contain_a_banned_phrase_pass(string $body): void
    {
        $r = (new BannedPhraseCheck($this->config()['banned_phrases']))->run($this->draft($body));

        $this->assertTrue($r->passed, $r->detail);
    }

    /** @return array<string, array{0: string}> */
    public static function bannedPassing(): array
    {
        return [
            'clean' => ['Hello, we help schools run admissions. Would a short call suit you?'],
            'inside a longer word' => ['We react nowadays to every request quickly.'],
            // each side of the phrase is a word boundary ON ITS OWN (found by mutation: dropping either one left every test green)
            'a longer word in front of the phrase' => ['We react now to every request quickly.'],
            'a longer word after the phrase' => ['We act nowhere near as slowly as that.'],
            'empty' => [''],
        ];
    }

    public function test_a_blank_phrase_in_the_configuration_is_ignored_and_the_others_still_work(): void
    {
        $check = new BannedPhraseCheck(['', '   ', 'buy now']);

        $this->assertTrue($check->run($this->draft('Hello, would a short call suit you?'))->passed, 'a blank phrase must not match everything');
        $this->assertFalse($check->run($this->draft('Please buy now.'))->passed);
    }

    public function test_the_subject_is_read_too(): void
    {
        $r = (new BannedPhraseCheck(['buy now']))->run($this->draft('Hello there, a calm body.', 'Buy NOW'));
        $this->assertFalse($r->passed);
    }

    // ------------------------------------------------------------------ length

    public function test_the_length_check_fails_over_the_cap_and_passes_at_it(): void
    {
        $check = new LengthCheck(150, 1500);

        $this->assertTrue($check->run($this->draft(str_repeat('a', 1500), str_repeat('s', 150)))->passed, 'exactly at both caps');
        $over = $check->run($this->draft(str_repeat('a', 1501)));
        $this->assertFalse($over->passed);
        $this->assertStringContainsString('1501', $over->detail);
        $this->assertFalse($check->run($this->draft('ok body', str_repeat('s', 151)))->passed);
    }

    public function test_the_length_is_counted_in_characters_not_bytes(): void
    {
        $this->assertTrue((new LengthCheck(150, 1500))->run($this->draft(str_repeat('é', 1500)))->passed, '1500 two-byte characters are 1500 characters');
        $this->assertTrue((new LengthCheck(150, 1500))->run($this->draft('ok body', str_repeat('é', 150)))->passed, '150 two-byte characters in the subject are 150 characters');
        $this->assertFalse((new LengthCheck(150, 1500))->run($this->draft('ok body', str_repeat('é', 151)))->passed);
    }

    public function test_an_empty_body_or_subject_fails(): void
    {
        $this->assertFalse((new LengthCheck(150, 1500))->run($this->draft("  \n "))->passed);
        $this->assertFalse((new LengthCheck(150, 1500))->run($this->draft('a body', ''))->passed);
    }

    // ------------------------------------------------------------------ links

    private function links(): LinkCountCheck
    {
        $c = $this->config();

        return new LinkCountCheck($c['max_links'], $c['unsubscribe_markers'], $c['link_tlds']);
    }

    #[DataProvider('linkCases')]
    public function test_links_are_counted_as_distinct_places(string $body, int $expected, bool $passes): void
    {
        $check = $this->links();
        $this->assertCount($expected, $check->links($body), 'links found: ' . json_encode($check->links($body)));
        $this->assertSame($passes, $check->run($this->draft($body))->passed);
    }

    /** @return array<string, array{0: string, 1: int, 2: bool}> */
    public static function linkCases(): array
    {
        return [
            'no link' => ['Hello, no links here.', 0, true],
            'one link' => ['See https://example.org/demo for details.', 1, true],
            'two different links' => ['See https://example.org/demo and https://other.example.org/page.', 2, false],
            'the same link twice' => ['See https://example.org/demo. Again: https://example.org/demo', 1, true],
            'http and https are one place' => ['http://example.org/demo and https://example.org/demo', 1, true],
            'a trailing slash and a full stop do not make another link' => ['https://example.org/demo/ and https://example.org/demo.', 1, true],
            'one link plus the unsubscribe link' => ['See https://example.org/demo. Unsubscribe: https://crm.example.org/api/gtm/unsubscribe/5?signature=abc', 1, true],
            'only an unsubscribe link' => ['Unsubscribe here: https://crm.example.org/api/crm/email/unsubscribe/LEAD/9?signature=abc', 0, true],
            'www counts' => ['Visit www.example.org today.', 1, true],
            'two www links' => ['Visit www.example.org or www.other.example.org.', 2, false],
            'a bare domain counts' => ['Our site is example.com/demo.', 1, true],
            'two bare domains' => ['example.com and other-site.in', 2, false],
            'an e-mail address is not a link' => ['Write to asha.rao@school.example.com anytime.', 0, true],
            'an e-mail address and a link' => ['Write to asha@school.example.com or see https://example.org/x.', 1, true],
            'a scheme link is not counted again as a bare domain' => ['https://example.org/demo', 1, true],
            'a link in brackets' => ['(see https://example.org/demo)', 1, true],
            'abbreviations are not links' => ['e.g. this, i.e. that, etc. and so on', 0, true],
            // found by mutation: each of these was invisible to every test
            'www with an ending the bare-domain list does not know' => ['Visit www.example.xyz and www.other.xyz.', 2, false],
            'www and the bare host are one place' => ['Visit www.example.org or example.org today.', 1, true],
            'a tracking link that carries another address in its query' => ['See https://example.org/go?u=school.example.com for details.', 1, true],
        ];
    }

    public function test_the_limit_comes_from_the_configuration(): void
    {
        $c = $this->config();
        $body = 'See https://example.org/a and https://example.org/b.';

        $this->assertFalse((new LinkCountCheck(1, $c['unsubscribe_markers'], $c['link_tlds']))->run($this->draft($body))->passed);
        $this->assertTrue((new LinkCountCheck(2, $c['unsubscribe_markers'], $c['link_tlds']))->run($this->draft($body))->passed);
    }

    // ------------------------------------------------------------------ placeholders

    private function placeholders(): PlaceholderCheck
    {
        return new PlaceholderCheck($this->config()['placeholder_patterns']);
    }

    #[DataProvider('placeholderForms')]
    public function test_every_form_of_an_unfilled_merge_field_fails(string $body): void
    {
        $r = $this->placeholders()->run($this->draft($body));

        $this->assertFalse($r->passed, "not caught: {$body}");
        $this->assertSame('placeholder', $r->key);
    }

    /** @return array<string, array{0: string}> */
    public static function placeholderForms(): array
    {
        return [
            'double braces' => ['Hello {{first_name}}, a quick question.'],
            'double braces with spaces' => ['Hello {{ first_name | default:"there" }}, a quick question.'],
            'single braces' => ['Hello {first_name}, a quick question.'],
            'single braces with a space' => ['Hello { First Name }, a quick question.'],
            'square brackets, a name' => ['Hello [First Name], a quick question.'],
            'square brackets, a company' => ['We saw what [Company] is doing.'],
            'square brackets, all capitals' => ['We saw what [SCHOOL NAME] is doing.'],
            'angle brackets' => ['Hello <first_name>, a quick question.'],
            'angle brackets with a space' => ['Hello <First Name>, a quick question.'],
            'percent signs' => ['Hello %FIRSTNAME%, a quick question.'],
            'dollar and braces' => ['Hello ${name}, a quick question.'],
            'template tags' => ['Hello {% name %}, a quick question.'],
            // found by mutation: a shape only ONE pattern of the list can see
            'dollar and braces around a number' => ['Hello ${0}, a quick question.'],
            'dollar and braces around quoted text' => ['Hello ${"name"}, a quick question.'],
            'square brackets, capitals and no keyword' => ['Hello [FNAME], a quick question.'],
        ];
    }

    public function test_a_placeholder_in_the_subject_fails_too(): void
    {
        $this->assertFalse($this->placeholders()->run($this->draft('A clean body here.', 'Question for {{company}}'))->passed);
    }

    #[DataProvider('placeholderFree')]
    public function test_ordinary_text_is_not_mistaken_for_a_merge_field(string $body): void
    {
        $r = $this->placeholders()->run($this->draft($body));

        $this->assertTrue($r->passed, $r->detail);
    }

    /** @return array<string, array{0: string}> */
    public static function placeholderFree(): array
    {
        return [
            'a name' => ['Dear Asha Rao, a quick question about admissions.'],
            'a citation' => ['Schools like yours report fewer errors [1] after the first term.'],
            'a lower case aside' => ['We can start next week [see attached] if it suits you.'],
            'a html break' => ["Hello,<br>a quick question about admissions."],
            'a percent figure' => ['It cut errors by 5% in a pilot.'],
            'a price' => ['The fee is $50 a month.'],
        ];
    }

    public function test_the_hits_are_named_once_each(): void
    {
        $found = $this->placeholders()->found('Hi {{name}}, {{name}} again, and [COMPANY].');

        $this->assertSame(['{{name}}', '[COMPANY]'], $found);
    }

    // ------------------------------------------------------------------ claims: which sentences, and what covers them

    private function claims(): UnapprovedClaimCheck
    {
        return new UnapprovedClaimCheck(new ClaimBook(), $this->config()['claim_markers']);
    }

    #[DataProvider('claimSentences')]
    public function test_a_numeric_percentage_guarantee_or_superlative_sentence_is_a_claim(string $sentence): void
    {
        $this->assertSame([$sentence], $this->claims()->claimSentences($this->draft($sentence)), "not seen as a claim: {$sentence}");
    }

    /** @return array<string, array{0: string}> */
    public static function claimSentences(): array
    {
        return [
            'a percentage' => ['ScholarClone cuts paperwork by 60%.'],
            'a spaced percentage' => ['It cuts paperwork by 60 %.'],
            'the word percent' => ['It cuts paperwork by sixty percent.'],
            'a multiple' => ['Admissions run 10x faster.'],
            'a guarantee' => ['We guarantee a reply in a day.'],
            'guaranteed' => ['Results are guaranteed.'],
            'number one' => ['We are number one in India.'],
            'hash one' => ['#1 in school admissions.'],
            'best' => ['It is the best admissions tool.'],
            'fastest' => ['The fastest way to enrol.'],
            'the only' => ['We are the only platform that does this.'],
            'only one' => ['Only one product does all of this.'],
            'leading' => ['The leading provider for schools.'],
            'most trusted' => ['The most trusted name in admissions.'],
            'zero downtime' => ['Zero downtime during admissions season.'],
            'one and only' => ['She is the one and only choice for schools.'],
            // "No." followed by a number is one claim; the sentence splitter used to cut it in two so this marker could never fire (found by mutation)
            'no. 1' => ['We are No. 1 in India.'],
            'no. 1, lower case and two spaces' => ['We are no.  1 in India.'],
        ];
    }

    #[DataProvider('notClaims')]
    public function test_ordinary_numbers_and_a_bare_only_are_not_claims(string $sentence): void
    {
        $this->assertSame([], $this->claims()->claimSentences($this->draft($sentence)), "wrongly seen as a claim: {$sentence}");
    }

    /** @return array<string, array{0: string}> */
    public static function notClaims(): array
    {
        return [
            'a plain number' => ['Our team has 12 years of experience.'],
            'a bare only' => ['We only need 15 minutes of your time.'],
            'a date' => ['Could we talk on 12 November at 4 pm?'],
            'no number' => ['Would a short call next week suit you?'],
        ];
    }

    public function test_the_subject_is_searched_for_claims_too(): void
    {
        $this->assertSame(['Cut paperwork by 60%'], $this->claims()->claimSentences($this->draft('A calm body.', 'Cut paperwork by 60%')));
    }

    public function test_a_sentence_is_covered_by_an_approved_claim_regardless_of_case_spacing_and_punctuation(): void
    {
        $approved = [Text::normalise('ScholarClone cuts paperwork by 60% for schools.')];

        $this->assertTrue(UnapprovedClaimCheck::covered(Text::normalise('SCHOLARCLONE   cuts paperwork by 60 % for schools'), $approved), 'case, spacing, a space before the %, no full stop');
        $this->assertTrue(UnapprovedClaimCheck::covered(Text::normalise('Cuts paperwork by 60%!'), $approved), 'a run of its words');
    }

    public function test_a_sentence_that_changes_the_claim_is_not_covered(): void
    {
        $approved = [Text::normalise('ScholarClone cuts paperwork by 60% for schools.')];

        $this->assertFalse(UnapprovedClaimCheck::covered(Text::normalise('ScholarClone cuts paperwork by 70% for schools.'), $approved), 'another number');
        $this->assertFalse(UnapprovedClaimCheck::covered(Text::normalise('ScholarClone cuts paperwork by 60% for schools, guaranteed.'), $approved), 'an added word');
        $this->assertFalse(UnapprovedClaimCheck::covered(Text::normalise('by 60%'), $approved), 'a fragment of fewer than three words');
        $this->assertFalse(UnapprovedClaimCheck::covered(Text::normalise('Cuts paperwork by 60%'), []), 'nothing is approved');
        // found by mutation: a run of words must be a run of WHOLE words, and the % and # signs are part of the claim
        $this->assertFalse(UnapprovedClaimCheck::covered(Text::normalise('ScholarClone cuts paperwork by 60% for school'), $approved), 'a run that stops in the middle of a word');
        $this->assertFalse(UnapprovedClaimCheck::covered(Text::normalise('Cuts errors by 60% for schools'), [Text::normalise('Cuts errors by 60 for schools')]), '60% is not 60');
        $this->assertFalse(UnapprovedClaimCheck::covered(Text::normalise('#1 in school admissions'), [Text::normalise('1 in school admissions')]), '#1 is not 1');
    }

    public function test_a_short_approved_claim_and_an_empty_sentence_are_covered_exactly(): void
    {
        $this->assertTrue(UnapprovedClaimCheck::covered('best ever', ['best ever']), 'an approved claim of two words (only a hand-written row can be that short) still covers itself');
        $this->assertFalse(UnapprovedClaimCheck::covered('best ever', ['best of all']));
        $this->assertTrue(UnapprovedClaimCheck::covered('', []), 'a sentence with nothing left after normalising claims nothing');
    }

    public function test_normalising_keeps_what_changes_a_claim_and_folds_what_does_not(): void
    {
        $this->assertSame('60%', Text::normalise("60\u{00A0}%"), 'a no-break space before the % closes up like a space');
        $this->assertSame('60%', Text::normalise('60 %'));
        $this->assertSame('cuts by 6% for schools', Text::normalise('  Cuts   BY 6% for “schools”!!  '));
        $this->assertSame('1 in school admissions', Text::normalise('1 in school admissions'));
        $this->assertSame('#1 in school admissions', Text::normalise('#1 in school admissions.'));
        $this->assertNotSame(Text::normalise('by 6%'), Text::normalise('by 60%'));
    }

    // ------------------------------------------------------------------ the small things

    public function test_sentences_split_after_a_full_stop_and_at_line_breaks_but_not_inside_a_link_or_a_number(): void
    {
        $this->assertSame(
            ['See example.org/page now.', 'It cut errors by 60.5%!', 'Really?', 'Yes'],
            Text::sentences("See example.org/page now. It cut errors by 60.5%! Really?\nYes")
        );
    }

    public function test_no_followed_by_a_number_does_not_end_a_sentence_but_other_full_stops_after_no_still_do(): void
    {
        $this->assertSame(['We are No. 1 in India.', 'Next one.'], Text::sentences('We are No. 1 in India. Next one.'));
        $this->assertSame(['We are no.  1 in India.'], Text::sentences('We are no.  1 in India.'), 'two spaces make no difference');
        $this->assertSame(['The answer is no.', 'Next sentence.'], Text::sentences('The answer is no. Next sentence.'));
        $this->assertSame(['A casino.', 'Next.'], Text::sentences('A casino. Next.'));
        $this->assertSame(['No.', '1 thing'], Text::sentences("No.\n1 thing"), 'a line break always ends it');
    }

    public function test_a_detail_is_cut_at_the_width_of_the_stored_column(): void
    {
        $r = CheckResult::fail('k', 'L', str_repeat('word ', 200));

        $this->assertLessThanOrEqual(CheckResult::DETAIL_MAX, mb_strlen($r->detail));
        $this->assertStringEndsWith('…', $r->detail);
        $this->assertSame('short', CheckResult::pass('k', 'L', "  short \n")->detail);
    }

    public function test_a_result_passes_only_when_every_check_passed_and_at_least_one_ran(): void
    {
        $at = new \Carbon\Carbon('2026-11-04 10:00:00');
        $make = fn (array $checks) => new GateResult('run', Draft::GTM, 1, 1, $checks, $at);

        $this->assertTrue($make([CheckResult::pass('a', 'A'), CheckResult::pass('b', 'B')])->passed());
        $this->assertFalse($make([CheckResult::pass('a', 'A'), CheckResult::fail('b', 'B', 'no')])->passed());
        $this->assertFalse($make([])->passed(), 'a gate that checked nothing vouches for nothing');
        $this->assertSame(['b'], array_map(fn ($c) => $c->key, $make([CheckResult::pass('a', 'A'), CheckResult::fail('b', 'B', 'no')])->failed()));
    }

    public function test_a_pattern_that_does_not_compile_is_an_error_not_a_silent_pass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Text::regex('(unclosed');
    }
}
