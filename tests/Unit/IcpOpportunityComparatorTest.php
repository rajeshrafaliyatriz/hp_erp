<?php

namespace Tests\Unit;

use App\Domain\Signals\Icp\IcpOpportunityComparator as C;
use App\Domain\Signals\Icp\IcpScorer;
use PHPUnit\Framework\TestCase;

/**
 * An opportunity laid next to its product's ICP. Pure: no database, no provider. Evidence and sources are only stored values, a fact that is
 * not recorded is never a match, and the comparison agrees with IcpScorer, which is left as it was.
 */
class IcpOpportunityComparatorTest extends TestCase
{
    private function pack(array $over = []): array
    {
        return $over + [
            'regions' => ['Gujarat'],
            'must_have' => [
                ['attribute' => 'beds', 'op' => '>=', 'value' => 100, 'label' => 'under 100 beds'],
                ['field' => 'state', 'op' => 'in_regions', 'label' => 'outside Gujarat'],
            ],
            'disqualifiers' => [['label' => 'veterinary clinic', 'phrases' => ['veterinary', 'pet hospital']]],
            'manual_flags' => ['group_owned' => 'part of a hospital group'],
            'criteria' => [['key' => 'beds', 'label' => 'Beds', 'max' => 20, 'rules' => [['range' => [100, 9999], 'points' => 20]]]],
        ];
    }

    private function opp(array $o = []): array
    {
        return $o + ['company_name' => 'City Care Hospital', 'observed_event' => 'opened a new wing', 'source' => ['label' => 'City news', 'url' => 'https://news.example.com/wing']];
    }

    private function statusOf(array $result, string $criterion): string
    {
        foreach ($result['criteria'] as $r) {
            if (str_starts_with($r['criterion'], $criterion) || $r['criterion'] === $criterion) {
                return $r['status'];
            }
        }
        $this->fail("no row for {$criterion}");
    }

    public function test_a_recorded_fact_that_meets_the_criterion_is_matched_with_the_stored_fact_as_evidence_and_the_account_as_source(): void
    {
        $r = (new C())->compare($this->pack(), ['name' => 'City Care', 'state' => 'Gujarat', 'crm_ref' => 'L-9', 'attributes' => ['beds' => 250]], $this->opp());
        $row = $r['criteria'][0];
        $this->assertSame(C::MATCHED, $row['status']);
        $this->assertSame('beds: 250', $row['evidence']['text']);
        $this->assertSame('account_fact', $row['evidence']['kind']);
        $this->assertSame('L-9', $row['source']['ref']);
        $this->assertTrue($row['evidence_available']);
    }

    public function test_a_fact_that_is_not_recorded_needs_review_and_has_no_evidence_or_source(): void
    {
        $r = (new C())->compare($this->pack(), ['name' => 'City Care', 'state' => 'Gujarat', 'attributes' => []], $this->opp());
        $row = $r['criteria'][0];
        $this->assertSame(C::NEEDS_REVIEW, $row['status']);
        $this->assertFalse($row['evidence_available']);
        $this->assertNull($row['evidence']);
        $this->assertNull($row['source']);
        $this->assertNotSame(C::MATCHED, $row['status'], 'missing data is never a match');
    }

    public function test_no_linked_account_means_every_must_have_needs_review_never_matched(): void
    {
        $r = (new C())->compare($this->pack(), null, $this->opp());
        $must = array_values(array_filter($r['criteria'], fn ($x) => $x['kind'] === 'must_have'));
        $this->assertCount(2, $must);
        foreach ($must as $m) {
            $this->assertSame(C::NEEDS_REVIEW, $m['status']);
            $this->assertNull($m['evidence']);
            $this->assertNull($m['source']);
        }
    }

    public function test_a_recorded_fact_that_fails_a_must_have_is_excluded(): void
    {
        $r = (new C())->compare($this->pack(), ['name' => 'Small', 'state' => 'Gujarat', 'attributes' => ['beds' => 20]], $this->opp());
        $this->assertSame(C::EXCLUDED, $r['criteria'][0]['status']);
        $this->assertStringContainsString('under 100 beds', $r['criteria'][0]['reason']);
    }

    public function test_an_exclusion_whose_words_appear_in_the_name_or_event_is_excluded_and_one_that_does_not_is_not_found(): void
    {
        $hit = (new C())->compare($this->pack(), ['state' => 'Gujarat', 'attributes' => ['beds' => 200]], $this->opp(['company_name' => 'Happy Paws Pet Hospital']));
        $this->assertSame(C::EXCLUDED, $this->statusOf($hit, 'veterinary clinic'));
        $this->assertSame('recorded_text', collect($hit['criteria'])->firstWhere('criterion', 'veterinary clinic')['evidence']['kind']);

        $miss = (new C())->compare($this->pack(), ['state' => 'Gujarat', 'attributes' => ['beds' => 200]], $this->opp());
        $row = collect($miss['criteria'])->firstWhere('criterion', 'veterinary clinic');
        $this->assertSame(C::NOT_FOUND, $row['status']);
        $this->assertNull($row['evidence']);
        $this->assertStringContainsString('Only those two texts were checked', $row['reason']);
    }

    public function test_a_manual_flag_set_on_the_account_is_excluded_and_an_unset_one_is_not_a_finding(): void
    {
        $with = (new C())->compare($this->pack(), ['state' => 'Gujarat', 'attributes' => ['beds' => 200, 'flags' => ['group_owned']]], $this->opp());
        $this->assertSame(C::EXCLUDED, $this->statusOf($with, 'part of a hospital group'));

        $without = (new C())->compare($this->pack(), ['state' => 'Gujarat', 'attributes' => ['beds' => 200]], $this->opp());
        $this->assertNull(collect($without['criteria'])->firstWhere('criterion', 'part of a hospital group'));
    }

    public function test_the_exclusion_source_is_the_stored_source_and_is_omitted_when_none_is_stored(): void
    {
        $r = (new C())->compare($this->pack(), null, $this->opp(['company_name' => 'Pet Hospital', 'source' => null]));
        $row = collect($r['criteria'])->firstWhere('criterion', 'veterinary clinic');
        $this->assertSame(C::EXCLUDED, $row['status']);
        $this->assertNull($row['source']);

        $r2 = (new C())->compare($this->pack(), null, $this->opp(['company_name' => 'Pet Hospital']));
        $this->assertSame('https://news.example.com/wing', collect($r2['criteria'])->firstWhere('criterion', 'veterinary clinic')['source']['url']);
    }

    public function test_the_summary_counts_every_row_once(): void
    {
        $r = (new C())->compare($this->pack(), ['state' => 'Gujarat', 'attributes' => ['beds' => 250]], $this->opp());
        $this->assertSame(count($r['criteria']), array_sum($r['summary']));
    }

    public function test_an_icp_with_nothing_to_compare_says_so(): void
    {
        $r = (new C())->compare(['regions' => []], null, $this->opp());
        $this->assertSame([], $r['criteria']);
        $this->assertNotSame([], $r['notes']);
    }

    /** The comparison uses IcpScorer's own rule: it cannot disagree with the scoring about who passes a must-have. */
    public function test_the_checks_agree_with_the_scorers_disqualification(): void
    {
        $scorer = new IcpScorer();
        foreach ([['beds' => 20], ['beds' => 250], []] as $attr) {
            $account = ['name' => 'X Hospital', 'state' => 'Gujarat', 'attributes' => $attr];
            $failed = array_filter($scorer->mustHaveChecks($account, $this->pack()), fn ($c) => $c['status'] === 'fail');
            $disqualified = ($scorer->score($account, $this->pack())['status'] ?? '') === 'disqualified';
            $this->assertSame($failed !== [], $disqualified, json_encode($attr));
        }
    }
}
