<?php

namespace Tests\Feature;

use App\Domain\Facts\EvidenceScorer;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The golden tests of the evidence scorer (slice 4), ported from Comp AI's evidence.spec.ts (MIT licence): each case is written from the RULE it pins, not
 * translated line by line, and the weights table itself is pinned so that a re-calibration is a visible, reviewed change.
 */
class FactsEvidenceScorerTest extends TestCase
{
    /** @return list<array{kind: string}> */
    private function of(string ...$kinds): array
    {
        return array_map(fn ($kind) => ['kind' => $kind, 'quote' => "saw {$kind}"], $kinds);
    }

    public function test_a_primary_source_that_names_them_makes_a_verified_fact(): void
    {
        $scored = EvidenceScorer::score($this->of('profile.email-match'));

        $this->assertSame('verified', $scored['band']);
        $this->assertTrue($scored['has_primary']);
        $this->assertSame(0.95, $scored['score']);
    }

    public function test_our_own_mailbox_is_primary_evidence_and_sits_exactly_on_the_verified_floor(): void
    {
        $scored = EvidenceScorer::score($this->of('crm.thread-reply'));

        $this->assertSame(0.85, $scored['score'], 'weight 0.85 gives a score of exactly the verified floor');
        $this->assertSame('verified', $scored['band'], 'the floor itself is verified (>=), so the rounding of the score cannot lose it');
    }

    public function test_a_signature_alone_is_probable_and_on_a_thread_they_replied_to_it_is_verified(): void
    {
        $this->assertSame('probable', EvidenceScorer::score($this->of('crm.signature-block'))['band']);
        $both = EvidenceScorer::score($this->of('crm.thread-reply', 'crm.signature-block'));

        $this->assertSame('verified', $both['band']);
        $this->assertSame(0.97, $both['score'], '1 - (1 - 0.85)(1 - 0.80)');
    }

    public function test_without_a_primary_source_nothing_is_verified_however_much_other_evidence_there_is(): void
    {
        $scored = EvidenceScorer::score($this->of('handle.name-form', 'search.cites-profile', 'web.cited-claim', 'employer-only'));

        $this->assertGreaterThan((float) config('facts.band_floor.probable'), $scored['score']);
        $this->assertFalse($scored['has_primary']);
        $this->assertSame('probable', $scored['band']);
        $this->assertStringContainsString('nothing that identifies them directly', $scored['rationale']);
    }

    public function test_the_primary_source_rule_holds_even_if_the_weights_are_recalibrated_upwards(): void
    {
        $this->assertSame('probable', EvidenceScorer::bandFor(0.99, false));
        $this->assertSame('verified', EvidenceScorer::bandFor(0.85, true));
    }

    public function test_every_band_edge_belongs_to_the_higher_band_and_just_below_it_to_the_lower(): void
    {
        $edges = [
            [0.85, true, 'verified'], [0.849, true, 'probable'], [0.55, false, 'probable'], [0.549, false, 'possible'],
            [0.30, false, 'possible'], [0.299, false, null], [0.0, false, null], [0.99, true, 'verified'],
        ];
        foreach ($edges as [$score, $primary, $band]) {
            $this->assertSame($band, EvidenceScorer::bandFor($score, $primary), "score {$score}, primary " . var_export($primary, true));
        }
    }

    public function test_an_unreadable_x_handle_is_a_suggestion_not_a_value(): void
    {
        $scored = EvidenceScorer::score($this->of('handle.name-form', 'search.cites-profile'));

        $this->assertSame('probable', $scored['band']);
        $this->assertSame(0.578, $scored['score'], '1 - 0.65 * 0.65 = 0.5775, stored to three decimals');
    }

    public function test_sources_that_disagree_hold_the_fact_rather_than_average_it(): void
    {
        $scored = EvidenceScorer::score([...$this->of('linkedin.employer-and-name'), ['kind' => 'contradiction', 'quote' => 'their signature says a different employer']]);

        $this->assertSame('possible', $scored['band']);
        $this->assertSame(0.45, $scored['score'], 'capped at the contradiction ceiling, below the probable floor');
        $this->assertStringStartsWith('Held:', $scored['rationale']);
        $this->assertStringContainsString('their signature says a different employer', $scored['rationale']);
    }

    public function test_a_contradiction_with_no_text_still_says_the_fact_is_held(): void
    {
        $this->assertSame('Held: sources disagree.', EvidenceScorer::score([...$this->of('profile.email-match'), ['kind' => 'contradiction']])['rationale']);
    }

    public function test_evidence_too_weak_to_keep_has_no_band(): void
    {
        $this->assertNull(EvidenceScorer::score($this->of('employer-only'))['band']);
        $this->assertNull(EvidenceScorer::score([])['band']);
        $this->assertSame('No evidence.', EvidenceScorer::score([])['rationale']);
    }

    public function test_certainty_is_never_claimed(): void
    {
        $everything = EvidenceScorer::score($this->of('profile.email-match', 'linkedin.employer-and-name', 'crm.thread-reply', 'github.account-identity', 'site.own-page'));

        $this->assertSame(0.99, $everything['score'], 'capped at the ceiling');
        $this->assertLessThan(1.0, $everything['score']);
    }

    public function test_the_rationale_is_a_sentence_a_rep_can_read(): void
    {
        $scored = EvidenceScorer::score($this->of('github.account-identity', 'handle.name-form'));

        $this->assertStringContainsString('GitHub account', $scored['rationale']);
        $this->assertStringContainsString('handle', $scored['rationale']);
        $this->assertSame('The GitHub account names them or their employer and the handle is a form of their name', $scored['rationale']);
    }

    public function test_the_band_always_agrees_with_the_stored_score(): void
    {
        // 1 - (1 - 0.85) in floating point is 0.8499999999999999...: the score is rounded before the band is read, so a stored 0.850 can never be 'probable'.
        $scored = EvidenceScorer::score($this->of('crm.thread-reply'));
        $this->assertSame(round($scored['score'], 3), $scored['score']);
        $this->assertSame(EvidenceScorer::bandFor($scored['score'], $scored['has_primary']), $scored['band']);
    }

    public function test_the_same_kind_from_the_same_address_counts_once_but_two_addresses_count_twice(): void
    {
        $page = fn (string $url) => ['kind' => 'web.cited-claim', 'source_url' => $url];

        $once = EvidenceScorer::score([$page('https://school.example/contact'), $page('https://www.school.example/contact/'), $page('http://SCHOOL.example/contact?utm=1')]);
        $twice = EvidenceScorer::score([$page('https://school.example/contact'), $page('https://other.example/about')]);

        $this->assertSame(0.4, $once['score'], 'one page cited three ways is one witness');
        $this->assertSame(0.64, $twice['score'], 'two different pages are two witnesses: 1 - 0.6 * 0.6');
    }

    public function test_evidence_without_an_address_is_never_merged(): void
    {
        $this->assertSame(0.64, EvidenceScorer::score($this->of('web.cited-claim', 'web.cited-claim'))['score']);
    }

    public function test_an_unknown_kind_is_refused_not_scored_as_nothing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown evidence kind 'psychic.hunch'");

        EvidenceScorer::score([['kind' => 'psychic.hunch']]);
    }

    public function test_the_weights_table_is_pinned_to_comp_ais_values_and_the_g2g_additions(): void
    {
        $original = [
            'profile.email-match' => [0.95, true], 'linkedin.employer-and-name' => [0.85, true], 'crm.thread-reply' => [0.85, true], 'crm.signature-block' => [0.80, true],
            'github.account-identity' => [0.80, true], 'crm.meeting-attendance' => [0.70, true], 'web.cited-claim' => [0.40, false], 'handle.name-form' => [0.35, false],
            'search.cites-profile' => [0.35, false], 'employer-only' => [0.20, false], 'contradiction' => [0.0, false],
        ];
        foreach ($original as $kind => [$weight, $primary]) {
            $row = EvidenceScorer::weights()[$kind];
            $this->assertSame($weight, (float) $row['weight'], "{$kind} weight");
            $this->assertSame($primary, $row['primary'], "{$kind} primary");
            $this->assertNotSame('', trim($row['label']), "{$kind} has a plain-English label");
        }
        $this->assertSame(array_merge(array_keys($original), ['site.own-page', 'directory.listing']), array_keys(EvidenceScorer::weights()), 'exactly the eleven originals and the two G2G additions');
        $this->assertSame(0.99, (float) config('facts.ceiling'));
        $this->assertSame(0.45, (float) config('facts.contradicted_cap'));
        $this->assertSame(['verified' => 0.85, 'probable' => 0.55, 'possible' => 0.30], config('facts.band_floor'));
    }
}
