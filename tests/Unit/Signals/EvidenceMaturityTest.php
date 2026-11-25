<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Market\EvidenceGrader;
use App\Domain\Signals\Market\EvidenceItems;
use App\Domain\Signals\Market\EvidenceMaturity;
use App\Domain\Signals\Market\SourceIndependence;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Evidence maturity E0-E3, its guardrails, source independence and the evidence items. No database, no network. */
class EvidenceMaturityTest extends TestCase
{
    // ── levels ──────────────────────────────────────────────────────────────

    public function test_snippet_only_is_e0_even_with_reasoning_and_many_domains(): void
    {
        $m = EvidenceMaturity::assess(['fetch_level' => 'search_snippet', 'quote_verified' => true, 'primary' => true, 'corroborating_domains' => 5, 'inference_reasoning' => 'Because.']);

        $this->assertSame('E0', $m['level']);
        $this->assertFalse($m['page_read']);
        $this->assertNotEmpty($m['missing']);
    }

    public function test_page_read_secondary_single_source_is_e1_and_says_what_is_missing(): void
    {
        $m = EvidenceMaturity::assess(['fetch_level' => 'page_text', 'quote_verified' => true, 'primary' => false, 'corroborating_domains' => 1]);

        $this->assertSame('E1', $m['level']);
        $this->assertStringContainsString('primary source', implode(' ', $m['missing']));
    }

    public function test_page_read_without_verified_quote_stays_e1(): void
    {
        $m = EvidenceMaturity::assess(['fetch_level' => 'page_text', 'quote_verified' => false, 'primary' => true, 'corroborating_domains' => 3, 'inference_reasoning' => 'A long enough reasoning text.']);

        $this->assertSame('E1', $m['level']);
    }

    public function test_two_independent_domains_with_reasoning_is_e2(): void
    {
        $m = EvidenceMaturity::assess(['fetch_level' => 'page_text', 'quote_verified' => true, 'primary' => false, 'corroborating_domains' => 2, 'inference_reasoning' => 'Reasoned from a verified fact.']);

        $this->assertSame('E2', $m['level']);
    }

    public function test_verified_primary_page_is_e3(): void
    {
        $m = EvidenceMaturity::assess(['fetch_level' => 'page_text', 'quote_verified' => true, 'primary' => true]);

        $this->assertSame('E3', $m['level']);
        $this->assertSame('Confirmed by a primary source', $m['label']);
    }

    public function test_contradicting_evidence_is_preserved_and_caps_the_level(): void
    {
        $m = EvidenceMaturity::assess(['fetch_level' => 'page_text', 'quote_verified' => true, 'primary' => true, 'contradictions' => ['A later notice says the tender was cancelled.']]);

        $this->assertSame('E1', $m['level']);
        $this->assertTrue($m['contested']);
        $this->assertSame(['A later notice says the tender was cancelled.'], $m['contradictions']);
    }

    // ── fetch failures never count as a page read ───────────────────────────

    #[DataProvider('failures')]
    public function test_a_failed_fetch_is_e0_and_the_reason_is_recorded(string $status): void
    {
        $m = EvidenceMaturity::assess(['fetch_level' => 'search_snippet', 'fetch_status' => $status]);

        $this->assertSame('E0', $m['level']);
        $this->assertFalse($m['page_read']);
        $this->assertStringContainsString($status, $m['reasons'][0]);
    }

    /** @return array<string, array{0: string}> */
    public static function failures(): array
    {
        return array_combine($c = ['robots_disallowed', 'http_403', 'http_404', 'http_429', 'http_5xx', 'timeout', 'connection', 'non_html', 'social_unreadable'], array_map(fn ($x) => [$x], $c));
    }

    // ── guardrails: maturity is an annotation, and agrees with the grader ───

    public function test_assess_does_not_return_or_alter_qualification_priority_confidence_or_fit(): void
    {
        $m = EvidenceMaturity::assess(['fetch_level' => 'page_text', 'quote_verified' => true, 'primary' => true]);

        foreach (['qualification', 'priority', 'confidence', 'fit', 'claim_level'] as $k) {
            $this->assertArrayNotHasKey($k, $m);
        }
    }

    public function test_e0_can_never_map_to_a_graded_inference_or_confirmed(): void
    {
        $grader = new EvidenceGrader();
        // A snippet is never a verified quote (the researcher sets quote_verified = page_fetched), so the grader returns hypothesis.
        $g = $grader->grade(['quote_verified' => false, 'corroborating_domains' => 4, 'inference_reasoning' => 'Reasoned from a verified fact.'], 'search_snippet', 'https://podarworld.com/x', ['name' => 'Podar World School', 'website' => 'https://podarworld.com']);

        $this->assertSame('hypothesis', $g['claim_level']);
        $this->assertSame('E0', EvidenceMaturity::fromStored($g['claim_level'], 'search_snippet'));
    }

    #[DataProvider('graderCases')]
    public function test_maturity_agrees_with_the_graders_claim_level(array $evidence, string $fetch, string $url, string $expectedClaim, string $expectedLevel): void
    {
        $buyer = ['name' => 'Podar World School', 'website' => 'https://podarworld.com'];
        $g = (new EvidenceGrader())->grade($evidence, $fetch, $url, $buyer);
        $primary = (new EvidenceGrader())->isPrimary($url, $buyer);
        $m = EvidenceMaturity::assess($evidence + ['fetch_level' => $fetch, 'primary' => $primary]);

        $this->assertSame($expectedClaim, $g['claim_level']);
        $this->assertSame($expectedLevel, $m['level']);
        $this->assertSame($expectedLevel, EvidenceMaturity::fromStored($g['claim_level'], $fetch));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: string, 3: string, 4: string}> */
    public static function graderCases(): array
    {
        return [
            'snippet' => [['quote_verified' => false], 'search_snippet', 'https://news.example.com/a', 'hypothesis', 'E0'],
            'secondary page, one domain' => [['quote_verified' => true, 'corroborating_domains' => 1], 'page_text', 'https://news.example.com/a', 'hypothesis', 'E1'],
            'secondary page, two domains and reasoning' => [['quote_verified' => true, 'corroborating_domains' => 2, 'inference_reasoning' => 'Reasoned from a verified fact.'], 'page_text', 'https://news.example.com/a', 'inference', 'E2'],
            'primary page' => [['quote_verified' => true], 'page_text', 'https://podarworld.com/tender', 'confirmed', 'E3'],
        ];
    }

    public function test_stored_signal_with_no_claim_level_is_not_assessed(): void
    {
        $this->assertSame('not_assessed', EvidenceMaturity::fromStored(null, null));
        $this->assertSame('not_assessed', EvidenceMaturity::fromStored('', 'page_text'));
    }

    // ── source independence ─────────────────────────────────────────────────

    public function test_subdomains_of_one_site_are_one_source(): void
    {
        $this->assertSame(1, SourceIndependence::count([['url' => 'https://www.example.com/a'], ['url' => 'https://news.example.com/b']]));
        $this->assertSame(2, SourceIndependence::count([['url' => 'https://example.com/a'], ['url' => 'https://other.org/b']]));
    }

    public function test_registrable_domain_handles_two_part_suffixes(): void
    {
        $this->assertSame('school.co.in', SourceIndependence::registrable('www.school.co.in'));
        $this->assertSame('x.gov.in', SourceIndependence::registrable('eprocure.x.gov.in'));
        $this->assertSame('example.com', SourceIndependence::registrable('a.b.example.com'));
    }

    public function test_syndicated_copies_on_different_domains_are_one_source(): void
    {
        $title = 'ABC Group of Schools announces a new campus in Pune for the 2027 session';
        $n = SourceIndependence::count([
            ['url' => 'https://paper-one.com/a', 'title' => $title],
            ['url' => 'https://paper-two.com/b', 'title' => $title],
            ['url' => 'https://paper-three.com/c', 'title' => 'A different report entirely about a CBSE circular on teacher training'],
        ]);

        $this->assertSame(2, $n);
    }

    public function test_source_types(): void
    {
        $this->assertSame('social', SourceIndependence::sourceType('https://www.linkedin.com/posts/x'));
        $this->assertSame('government', SourceIndependence::sourceType('https://bidplus.gem.gov.in/x'));
        $this->assertSame('job_board', SourceIndependence::sourceType('https://in.indeed.com/viewjob'));
        $this->assertSame('web', SourceIndependence::sourceType('https://school.example.com/news'));
    }

    // ── evidence items ──────────────────────────────────────────────────────

    public function test_evidence_items_are_traceable_and_mark_dependent_sources(): void
    {
        $items = EvidenceItems::build([
            ['url' => 'https://school-one.com/news/1', 'title' => 'New campus', 'published_at' => '2026-11-01', 'retrieved_at' => '2026-11-21T10:00:00+05:30', 'page_fetched' => true, 'text' => 'The school will open a new campus.', 'fetch_status' => 'ok'],
            ['url' => 'https://www.example.com/x', 'title' => 'Snippet only', 'page_fetched' => false, 'text' => 'snippet', 'fetch_status' => 'http_403', 'fetch_failure' => 'http_403'],
            ['url' => 'https://news.example.com/y', 'title' => 'Same site', 'page_fetched' => false, 'text' => 's2'],
        ], 'The school is opening a new campus.', 'E1', 'https://school-one.com/news/1');

        $this->assertCount(3, $items);
        $this->assertSame('E1', $items[0]['maturity']);
        $this->assertSame('The school will open a new campus.', $items[0]['passage']);
        $this->assertNotNull($items[0]['claim_supported']);
        $this->assertNull($items[1]['passage'], 'a snippet is never recorded as a read passage');
        $this->assertSame('http_403', $items[1]['fetch_failure']);
        $this->assertSame('E0', $items[1]['maturity']);
        $this->assertTrue($items[1]['independent']);
        $this->assertFalse($items[2]['independent'], 'news.example.com shares a registrable domain with www.example.com');
    }

    public function test_evidence_items_are_capped(): void
    {
        $src = array_map(fn ($i) => ['url' => "https://site{$i}.com/a", 'page_fetched' => false, 'text' => 't'], range(1, 12));

        $this->assertCount(EvidenceItems::MAX_ITEMS, EvidenceItems::build($src, 'c', 'E0'));
    }
}
