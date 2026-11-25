<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Market\MarketImporter;
use App\Domain\Signals\Market\QualificationGate;
use App\Domain\Signals\Opportunities\OpportunityResearcher;
use App\Domain\Signals\SignalRunner;
use App\Domain\Signals\Sources\FetchRanker;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Domain\Signals\Support\StructuredAi;
use App\Domain\Signals\Support\UnsafeUrlException;
use Tests\TestCase;

/** Fetch depth: flag off keeps today's behaviour; flag on ranks, reads PDFs and fetches cited pages. A stub fetcher: no network, no database. */
class EvidenceDepthFetchTest extends TestCase
{
    // ── ranking (pure) ──────────────────────────────────────────────────────

    public function test_ranker_puts_unreadable_social_last_and_events_and_government_first(): void
    {
        $sources = [
            ['url' => 'https://www.facebook.com/p/1', 'title' => 'post', 'text' => 'new campus'],
            ['url' => 'https://in.indeed.com/viewjob', 'title' => 'job', 'text' => 'hiring'],
            ['url' => 'https://school-news.example.com/a', 'title' => 'School announces new campus', 'text' => 'The school will inaugurate a new campus next year.'],
            ['url' => 'https://eprocure.gov.in/tender/9', 'title' => 'Tender', 'text' => 'Tender for school ERP'],
            ['url' => 'https://plain.example.org/a', 'title' => 'About us', 'text' => 'History of the school.'],
        ];

        $order = FetchRanker::order($sources);

        $this->assertSame(0, end($order), 'the login-walled social host (index 0) is read last');
        $this->assertSame([3, 2], array_slice($order, 0, 2), 'a tender and a school with a business event come before plain pages');
        $this->assertLessThan(array_search(1, $order, true), array_search(4, $order, true), 'a plain page is read before a job board');
    }

    public function test_ranker_keeps_search_order_among_equals(): void
    {
        $s = array_map(fn ($i) => ['url' => "https://site{$i}.example.com/a", 'title' => 't', 'text' => 'plain'], range(1, 4));

        $this->assertSame([0, 1, 2, 3], FetchRanker::order($s));
    }

    // ── enrich(): flag off / on ─────────────────────────────────────────────

    public function test_flag_off_reads_in_search_order_and_never_asks_for_pdfs(): void
    {
        config(['signals.evidence_depth.enabled' => false, 'signals.opportunities.fetch_pages' => 1]);
        $f = new StubFetcher(['https://a.example.com/x' => '<html><body>' . str_repeat('first page text ', 30) . '</body></html>', 'https://b.example.com/y' => '<html><body>' . str_repeat('second page text ', 30) . '</body></html>']);
        $out = $this->enrich($f, [$this->src('S1', 'https://a.example.com/x'), $this->src('S2', 'https://b.example.com/y', 'Tender for school ERP')]);

        $this->assertSame(['https://a.example.com/x'], $f->pageRequests(), 'search order, budget 1');
        $this->assertTrue($out[0]['page_fetched']);
        $this->assertFalse($out[1]['page_fetched']);
        $this->assertSame([], $f->pageCalls[0]['types'], 'PDF is not requested with the flag off');
        $this->assertSame(600000, $f->pageCalls[0]['max']);
    }

    public function test_flag_on_reads_the_most_worthwhile_source_first(): void
    {
        config(['signals.evidence_depth.enabled' => true, 'signals.opportunities.fetch_pages' => 1]);
        $f = new StubFetcher(['https://b.example.com/y' => '<html><body>' . str_repeat('tender text ', 40) . '</body></html>']);
        $out = $this->enrich($f, [$this->src('S1', 'https://a.example.com/x', 'About'), $this->src('S2', 'https://b.example.com/y', 'Tender for school ERP issued')]);

        $this->assertSame(['https://b.example.com/y'], $f->pageRequests());
        $this->assertFalse($out[0]['page_fetched']);
        $this->assertTrue($out[1]['page_fetched']);
        $this->assertSame(['application/pdf'], $f->pageCalls[0]['types'], 'PDF is requested with the flag on');
        $this->assertSame('ok', $out[1]['fetch_status']);
    }

    public function test_an_unreadable_pdf_is_never_counted_as_a_page_read(): void
    {
        config(['signals.evidence_depth.enabled' => true, 'signals.opportunities.fetch_pages' => 3]);
        $f = new StubFetcher(['https://x.gov.in/circular.pdf' => ['type' => 'application/pdf', 'body' => "%PDF-not-a-real-pdf\0\0"]]);
        $out = $this->enrich($f, [$this->src('S1', 'https://x.gov.in/circular.pdf', 'Circular')]);

        $this->assertFalse($out[0]['page_fetched'], 'garbage bytes yield no text, so the snippet stays');
        $this->assertSame('no_more_text_than_snippet', $out[0]['fetch_status']);
    }

    // ── failures: recorded by type and by host, never counted as reads ──────

    /** @dataProvider failureKinds */
    public function test_each_failure_keeps_the_snippet_and_is_recorded_by_host_and_type(string $category, ?int $status, string $expected): void
    {
        config(['signals.evidence_depth.enabled' => false, 'signals.opportunities.fetch_pages' => 5]);
        $f = new StubFetcher(['https://gov.example.in/n' => new UnsafeUrlException('x', $category, $status)]);
        $r = $this->researcher($f);
        $out = $this->invoke($r, 'enrich', [[$this->src('S1', 'https://gov.example.in/n')]]);

        $this->assertFalse($out[0]['page_fetched']);
        $this->assertSame($expected, $out[0]['fetch_status']);
        $this->assertSame($expected, $out[0]['fetch_failure']);
        $diag = $r->diagnostics();
        $this->assertSame(1, $diag['fetch_by_host']['gov.example.in']['failed:' . $expected]);
        $this->assertSame(0, $diag['pages_fetched']);
        $this->assertSame(1, $diag['pages_failed']);
    }

    /** @return array<string, array{0: string, 1: ?int, 2: string}> */
    public static function failureKinds(): array
    {
        return [
            'forbidden' => ['http_status', 403, 'http_403'], 'not found' => ['http_status', 404, 'http_404'], 'rate limited' => ['http_status', 429, 'http_429'],
            'server error' => ['http_status', 503, 'http_5xx'], 'timeout' => ['timeout', null, 'timeout'], 'connection' => ['connection', null, 'connection'],
            'not html' => ['non_html', null, 'non_html'],
        ];
    }

    public function test_robots_refusal_and_social_skips_are_recorded_and_make_no_page_request(): void
    {
        config(['signals.evidence_depth.enabled' => false, 'signals.opportunities.fetch_pages' => 5]);
        $f = new StubFetcher([], robotsDeny: ['blocked.example.com']);
        $r = $this->researcher($f);
        $out = $this->invoke($r, 'enrich', [[$this->src('S1', 'https://blocked.example.com/a'), $this->src('S2', 'https://www.facebook.com/p/1')]]);

        $this->assertSame('robots_disallowed', $out[0]['fetch_status']);
        $this->assertSame('social_unreadable', $out[1]['fetch_status']);
        $this->assertSame([], $f->pageRequests());
        $by = $r->diagnostics()['fetch_by_host'];
        $this->assertSame(1, $by['blocked.example.com']['failed:robots_disallowed']);
        $this->assertSame(1, $by['facebook.com']['skipped_social']);
    }

    public function test_host_diagnostics_are_capped(): void
    {
        config(['signals.evidence_depth.enabled' => false, 'signals.evidence_depth.max_hosts_in_diagnostics' => 3, 'signals.opportunities.fetch_pages' => 20]);
        $f = new StubFetcher([]);
        $r = $this->researcher($f);
        $srcs = array_map(fn ($i) => $this->src("S{$i}", "https://host{$i}.example.com/a"), range(1, 8));
        $this->invoke($r, 'enrich', [$srcs]);

        $by = $r->diagnostics()['fetch_by_host'];
        $this->assertLessThanOrEqual(4, count($by), '3 hosts plus the "(other hosts)" bucket');
        $this->assertArrayHasKey('(other hosts)', $by);
    }

    // ── cited-page fetch (flag on) ──────────────────────────────────────────

    public function test_cited_snippet_sources_are_fetched_and_uncited_ones_are_not(): void
    {
        config(['signals.evidence_depth.enabled' => true, 'signals.evidence_depth.cited_page_budget' => 5]);
        $f = new StubFetcher(['https://cited.example.com/a' => '<html><body>' . str_repeat('the school will open a new campus ', 20) . '</body></html>']);
        $r = $this->researcher($f);
        $out = $this->invoke($r, 'fetchCitedPages', [
            [$this->src('S1', 'https://cited.example.com/a'), $this->src('S2', 'https://uncited.example.com/b')],
            [['company_name' => 'X', 'source_ids' => ['S1']]], 6, 99,
        ]);

        $this->assertTrue($out[0]['page_fetched']);
        $this->assertFalse($out[1]['page_fetched']);
        $this->assertSame(['https://cited.example.com/a'], $f->pageRequests(), 'the uncited source is never requested');
        $this->assertSame(1, $r->diagnostics()['cited_pages']['read']);
    }

    public function test_cited_fetch_respects_its_budget_and_records_a_failed_page(): void
    {
        config(['signals.evidence_depth.enabled' => true, 'signals.evidence_depth.cited_page_budget' => 1]);
        $f = new StubFetcher(['https://one.example.com/a' => new UnsafeUrlException('x', 'http_status', 403), 'https://two.example.com/b' => '<html><body>' . str_repeat('text ', 60) . '</body></html>']);
        $r = $this->researcher($f);
        $out = $this->invoke($r, 'fetchCitedPages', [
            [$this->src('S1', 'https://one.example.com/a'), $this->src('S2', 'https://two.example.com/b')],
            [['source_ids' => ['S1', 'S2']]], 6, 99,
        ]);

        // A failure does not use the budget (as in the first pass), so the second page is read.
        $this->assertSame('http_403', $out[0]['fetch_status']);
        $this->assertFalse($out[0]['page_fetched']);
        $this->assertTrue($out[1]['page_fetched']);
        $this->assertSame(1, $r->diagnostics()['cited_pages']['failed']);
    }

    public function test_cited_fetch_does_nothing_when_nothing_is_cited_or_budget_is_zero(): void
    {
        config(['signals.evidence_depth.enabled' => true, 'signals.evidence_depth.cited_page_budget' => 0]);
        $f = new StubFetcher([]);
        $out = $this->invoke($this->researcher($f), 'fetchCitedPages', [[$this->src('S1', 'https://a.example.com/a')], [['source_ids' => ['S1']]], 6, 99]);

        $this->assertSame([], $f->pageRequests());
        $this->assertFalse($out[0]['page_fetched']);
    }

    public function test_the_flag_is_off_by_default(): void
    {
        $this->assertFalse((bool) config('signals.evidence_depth.enabled'));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function src(string $id, string $url, string $text = 'snippet'): array
    {
        return ['id' => $id, 'url' => $url, 'title' => 'Title ' . $id, 'published_at' => null, 'date_provenance' => null, 'date_confidence' => null,
            'text' => $text, 'page_fetched' => false, 'retrieved_at' => '2026-11-21T10:00:00+05:30', 'search_query' => null];
    }

    private function researcher(StubFetcher $f): OpportunityResearcher
    {
        $gate = (new \ReflectionClass(QualificationGate::class))->newInstanceWithoutConstructor();
        $r = new OpportunityResearcher($this->createMock(StructuredAi::class), $this->createMock(SignalRunner::class), $f, $this->createMock(MarketImporter::class), $gate);
        $this->setProp($r, 'diag', $this->invoke($r, 'freshDiagnostics', []));
        $this->setProp($r, 'startedAt', microtime(true));

        return $r;
    }

    private function enrich(StubFetcher $f, array $sources): array
    {
        return $this->invoke($this->researcher($f), 'enrich', [$sources]);
    }

    private function invoke(object $o, string $method, array $args): mixed
    {
        $m = new \ReflectionMethod($o, $method);

        return $m->invokeArgs($o, $args);
    }

    private function setProp(object $o, string $prop, mixed $value): void
    {
        $p = new \ReflectionProperty($o, $prop);
        $p->setValue($o, $value);
    }
}

/** A fetcher that never touches the network: scripted bodies, exceptions, and a robots.txt that allows everything except the hosts denied. */
class StubFetcher extends SafeUrlFetcher
{
    /** @var list<array{url: string, max: ?int, types: array}> */
    public array $pageCalls = [];

    /** @param array<string, string|array{type: string, body: string}|\Throwable> $pages @param list<string> $robotsDeny */
    public function __construct(private readonly array $pages, private readonly array $robotsDeny = [])
    {
    }

    public function fetch(string $url, ?int $maxBytes = null, ?int $timeout = null, bool $verifyTls = true, array $extraTypes = []): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (str_ends_with($url, '/robots.txt')) {
            if (in_array($host, $this->robotsDeny, true)) {
                return ['url' => $url, 'body' => "User-agent: *\nDisallow: /\n", 'content_type' => 'text/plain', 'last_modified' => null];
            }

            throw new UnsafeUrlException('none', 'http_status', 404);   // "no robots.txt": allowed
        }
        $this->pageCalls[] = ['url' => $url, 'max' => $maxBytes, 'types' => $extraTypes];
        $r = $this->pages[$url] ?? new UnsafeUrlException('none', 'http_status', 404);
        if ($r instanceof \Throwable) {
            throw $r;
        }

        return ['url' => $url, 'body' => is_array($r) ? $r['body'] : $r, 'content_type' => is_array($r) ? $r['type'] : 'text/html', 'last_modified' => null];
    }

    /** @return list<string> */
    public function pageRequests(): array
    {
        return array_column($this->pageCalls, 'url');
    }
}
