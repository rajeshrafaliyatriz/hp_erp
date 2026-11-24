<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Market\MarketImporter;
use App\Domain\Signals\Market\QualificationGate;
use App\Domain\Signals\Opportunities\OpportunityResearcher;
use App\Domain\Signals\Products\ProductContext;
use App\Domain\Signals\Run\RunAccounting;
use App\Domain\Signals\SignalRunner;
use App\Domain\Signals\Sources\SourceVerificationStore;
use App\Domain\Signals\Support\StructuredAi;
use App\Domain\Signals\Support\UnsafeUrlException;
use Tests\TestCase;

require_once __DIR__ . '/EvidenceDepthFetchTest.php';   // defines StubFetcher

/** Government source register in Government (B2G) runs: off by default, B2G non-school only, polite, bounded. A stub fetcher: no network, no database. */
class GovSourcesRunTest extends TestCase
{
    private string $storePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storePath = sys_get_temp_dir() . '/gov_sources_test_' . uniqid() . '.json';
        config(['signals_gov_sources' => ['sources' => [
            ['a', 'Source A', 'https://a.gov.example/home', 'tender_portal', 'usable', ''],
            ['b', 'Source B', 'https://b.gov.example/home', 'tender_portal', 'usable', ''],
            ['c', 'Source C', 'https://c.gov.example/home', 'tender_portal', 'usable', ''],
            ['d', 'Source D', 'https://d.gov.example/home', 'tender_portal', 'usable', ''],
            ['e', 'Source E', 'https://e.gov.example/home', 'ministry_news', 'needs_feed', ''],
        ]], 'signals.gov_sources.per_run' => 3, 'signals.gov_sources.enabled' => true]);
    }

    protected function tearDown(): void
    {
        @unlink($this->storePath);
        parent::tearDown();
    }

    public function test_the_flag_is_off_and_per_run_is_three_by_default(): void
    {
        $cfg = require __DIR__ . '/../../../config/signals.php';
        $this->assertFalse($cfg['gov_sources']['enabled']);
        $this->assertSame(3, $cfg['gov_sources']['per_run']);
    }

    public function test_flag_off_makes_no_fetch_and_records_nothing(): void
    {
        config(['signals.gov_sources.enabled' => false]);
        $f = new StubFetcher($this->pages());
        $r = $this->researcher($f, 'g2g');

        $this->assertSame([], $this->invoke($r, 'govSourcesIfEnabled', ['b2g']));
        $this->assertSame([], $f->pageCalls);
        $this->assertArrayNotHasKey('gov_sources', $r->diagnostics());
        $this->assertArrayNotHasKey('gov_sources', RunAccounting::build($r->diagnostics(), [], ['sources' => 0, 'candidates' => 0, 'considered' => 0]));
        $this->assertFileDoesNotExist($this->storePath);
    }

    public function test_flag_on_b2g_g2g_reads_up_to_per_run_usable_sources(): void
    {
        $f = new StubFetcher($this->pages());
        $r = $this->researcher($f, 'g2g');
        $out = $this->invoke($r, 'govSourcesIfEnabled', ['b2g']);

        $this->assertCount(3, $out, 'per_run caps the reads (4 usable, 1 needs_feed)');
        $this->assertCount(3, $f->pageRequests());
        $this->assertNotContains('https://e.gov.example/home', $f->pageRequests(), 'a source that needs a feed is never read');
        $this->assertTrue($out[0]['page_fetched']);
        $this->assertGreaterThan(80, mb_strlen($out[0]['text']));
        $g = RunAccounting::build($r->diagnostics(), [], ['sources' => 0, 'candidates' => 0, 'considered' => 0])['gov_sources'];
        $this->assertSame(['selected' => 3, 'read' => 3, 'failed_by_category' => []], $g);
        $this->assertSame(1, $r->diagnostics()['fetch_by_host']['a.gov.example']['read']);
    }

    public function test_least_recently_read_goes_first_on_the_next_run(): void
    {
        $f = new StubFetcher($this->pages());
        $this->invoke($this->researcher($f, 'g2g'), 'govSourcesIfEnabled', ['b2g']);
        $f2 = new StubFetcher($this->pages());
        $this->invoke($this->researcher($f2, 'g2g'), 'govSourcesIfEnabled', ['b2g']);

        $this->assertContains('https://d.gov.example/home', $f2->pageRequests(), 'the source not yet read is picked next');
    }

    public function test_flag_on_b2b_reads_nothing(): void
    {
        $f = new StubFetcher($this->pages());
        $r = $this->researcher($f, 'g2g');

        $this->assertSame([], $this->invoke($r, 'govSourcesIfEnabled', ['b2b']));
        $this->assertSame([], $f->pageCalls);
        $this->assertArrayNotHasKey('gov_sources', $r->diagnostics());
    }

    public function test_flag_on_scholarclone_b2g_reads_nothing(): void
    {
        $f = new StubFetcher($this->pages());
        $r = $this->researcher($f, 'scholarclone');

        $this->assertSame([], $this->invoke($r, 'govSourcesIfEnabled', ['b2g']));
        $this->assertSame([], $f->pageCalls);
        $this->assertArrayNotHasKey('gov_sources', $r->diagnostics());
    }

    public function test_a_source_refused_by_robots_is_recorded_and_not_fetched(): void
    {
        $f = new StubFetcher($this->pages(), robotsDeny: ['a.gov.example']);
        $r = $this->researcher($f, 'g2g');
        $out = $this->invoke($r, 'govSourcesIfEnabled', ['b2g']);

        $this->assertNotContains('https://a.gov.example/home', $f->pageRequests());
        $this->assertCount(2, $out);
        $this->assertSame(1, $r->diagnostics()['fetch_by_host']['a.gov.example']['failed:robots_disallowed']);
        $this->assertSame(['selected' => 3, 'read' => 2, 'failed_by_category' => ['robots_disallowed' => 1]], $r->diagnostics()['gov_sources']);
    }

    public function test_a_failing_source_does_not_stop_the_run(): void
    {
        $pages = $this->pages();
        $pages['https://a.gov.example/home'] = new UnsafeUrlException('x', 'http_status', 403);
        $pages['https://b.gov.example/home'] = new UnsafeUrlException('x', 'timeout', null);
        $f = new StubFetcher($pages);
        $r = $this->researcher($f, 'g2g');
        $out = $this->invoke($r, 'govSourcesIfEnabled', ['b2g']);

        $this->assertCount(1, $out, 'the third source is still read');
        $d = $r->diagnostics();
        $this->assertSame(1, $d['fetch_by_host']['a.gov.example']['failed:http_403']);
        $this->assertSame(1, $d['fetch_by_host']['b.gov.example']['failed:timeout']);
        $this->assertSame(['http_403' => 1, 'timeout' => 1], $d['gov_sources']['failed_by_category']);
        $this->assertSame(1, $d['gov_sources']['read']);
    }

    public function test_per_run_zero_selects_nothing(): void
    {
        config(['signals.gov_sources.per_run' => 0]);
        $f = new StubFetcher($this->pages());
        $r = $this->researcher($f, 'g2g');

        $this->assertSame([], $this->invoke($r, 'govSourcesIfEnabled', ['b2g']));
        $this->assertSame([], $f->pageCalls);
        $this->assertArrayNotHasKey('gov_sources', $r->diagnostics());
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function pages(): array
    {
        $out = [];
        foreach (['a', 'b', 'c', 'd'] as $h) {
            $out["https://{$h}.gov.example/home"] = '<html><head><title>Portal ' . $h . '</title></head><body>' . str_repeat('Tender notices and procurement circulars for departments. ', 6) . '</body></html>';
        }

        return $out;
    }

    private function researcher(StubFetcher $f, string $productKey): OpportunityResearcher
    {
        $gate = (new \ReflectionClass(QualificationGate::class))->newInstanceWithoutConstructor();
        $r = new OpportunityResearcher($this->createMock(StructuredAi::class), $this->createMock(SignalRunner::class), $f, $this->createMock(MarketImporter::class), $gate);
        $ctx = (new \ReflectionClass(ProductContext::class))->newInstanceWithoutConstructor();
        \Closure::bind(function () use ($productKey) {
            $this->key = $productKey;
            $this->businessFit = $productKey === 'scholarclone' ? 'scholar' : 'other';
        }, $ctx, ProductContext::class)();
        $this->setProp($r, 'ctx', $ctx);
        $this->setProp($r, 'govStore', new SourceVerificationStore($this->storePath));
        $this->setProp($r, 'diag', $this->invoke($r, 'freshDiagnostics', []));
        $this->setProp($r, 'startedAt', microtime(true));

        return $r;
    }

    private function invoke(object $o, string $method, array $args): mixed
    {
        return (new \ReflectionMethod($o, $method))->invokeArgs($o, $args);
    }

    private function setProp(object $o, string $prop, mixed $value): void
    {
        (new \ReflectionProperty($o, $prop))->setValue($o, $value);
    }
}
