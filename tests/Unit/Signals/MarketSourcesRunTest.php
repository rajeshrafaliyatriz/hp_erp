<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Market\MarketImporter;
use App\Domain\Signals\Market\QualificationGate;
use App\Domain\Signals\Opportunities\OpportunityResearcher;
use App\Domain\Signals\Products\ProductContext;
use App\Domain\Signals\SignalRunner;
use App\Domain\Signals\Support\StructuredAi;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

require_once __DIR__ . '/EvidenceDepthFetchTest.php';   // defines StubFetcher

/** The official-API / public-feed sources inside a run: off by default, polite, never social, never fatal. No network, no database. */
class MarketSourcesRunTest extends TestCase
{
    private const RSS = '<?xml version="1.0"?><rss version="2.0"><channel><title>Tenders</title>'
        . '<item><title>RFP for HRMS implementation</title><link>https://dept.gov.example/tender/42</link><description>Department invites bids for HRMS.</description><pubDate>Mon, 16 Nov 2026 10:00:00 +0000</pubDate></item>'
        . '</channel></rss>';

    public function test_the_shipped_defaults_are_all_off(): void
    {
        $cfg = require __DIR__ . '/../../../config/signals_market_sources.php';
        $this->assertFalse($cfg['enabled']);
        $this->assertFalse($cfg['youtube']['enabled']);
        $this->assertFalse($cfg['reddit']['enabled']);
        $this->assertFalse($cfg['google_cse']['enabled']);
        $this->assertSame([], $cfg['feeds'], 'no feed URL is shipped or guessed');
    }

    public function test_flag_off_does_nothing(): void
    {
        config(['signals_market_sources' => ['enabled' => false, 'feeds' => ['https://dept.gov.example/rss']]]);
        $f = new StubFetcher([]);
        $r = $this->researcher($f);

        $this->assertSame([], $this->invoke($r, 'marketSourcesIfEnabled', [[['q' => 'government tender India']]]));
        $this->assertSame([], $f->pageCalls);
        $this->assertArrayNotHasKey('market_sources', $r->diagnostics());
    }

    public function test_enabled_with_nothing_configured_does_nothing(): void
    {
        config(['signals_market_sources' => ['enabled' => true, 'feeds' => []]]);
        $f = new StubFetcher([]);
        $r = $this->researcher($f);

        $this->assertSame([], $this->invoke($r, 'marketSourcesIfEnabled', [[]]));
        $this->assertSame([], $f->pageCalls);
    }

    public function test_a_public_feed_is_read_and_its_items_become_snippet_sources(): void
    {
        config(['signals_market_sources' => ['enabled' => true, 'feeds' => ['https://dept.gov.example/rss'], 'max_total_items' => 50]]);
        $f = new StubFetcher(['https://dept.gov.example/rss' => self::RSS]);
        $r = $this->researcher($f);

        $out = $this->invoke($r, 'marketSourcesIfEnabled', [[]]);
        $this->assertCount(1, $out);
        $this->assertSame('https://dept.gov.example/tender/42', $out[0]['url']);
        $this->assertFalse($out[0]['page_fetched'], 'a feed item is a snippet; its page is read later like any source');
        $this->assertSame('market_feed', $out[0]['origin']);
        $this->assertSame(['adapters' => [], 'feeds' => 1, 'items' => 1, 'lead_only' => 0, 'errors' => []], $r->diagnostics()['market_sources']);
    }

    public function test_a_social_url_is_never_fetched_even_when_listed_as_a_feed(): void
    {
        config(['signals_market_sources' => ['enabled' => true, 'feeds' => ['https://www.linkedin.com/company/x/posts', 'https://www.instagram.com/x/feed']]]);
        $f = new StubFetcher([]);
        $r = $this->researcher($f);

        $this->assertSame([], $this->invoke($r, 'marketSourcesIfEnabled', [[]]));
        $this->assertSame([], $f->pageRequests(), 'LinkedIn and Instagram are never requested');
    }

    public function test_an_adapter_without_credentials_sends_no_request_and_says_why(): void
    {
        Http::fake();
        config(['signals_market_sources' => ['enabled' => true, 'youtube' => ['enabled' => true, 'api_key' => null], 'feeds' => []]]);
        $r = $this->researcher(new StubFetcher([]));

        $this->assertSame([], $this->invoke($r, 'marketSourcesIfEnabled', [[['q' => 'government HRMS tender']]]));
        Http::assertNothingSent();
        $this->assertSame('not_configured', $r->diagnostics()['market_sources']['errors']['youtube']);
    }

    public function test_run_stats_gain_grade_and_market_keys_only_when_recorded_and_keep_every_existing_key(): void
    {
        $totals = ['sources' => 0, 'candidates' => 0, 'considered' => 0];
        $plain = \App\Domain\Signals\Run\RunAccounting::build([], [], $totals);
        $rich = \App\Domain\Signals\Run\RunAccounting::build(['grades' => ['verify' => 2, 'lead' => 1], 'market_sources' => ['adapters' => ['youtube'], 'feeds' => 0, 'items' => 3, 'lead_only' => 1, 'errors' => []]], [], $totals);

        $this->assertArrayNotHasKey('signal_grades', $plain);
        $this->assertArrayNotHasKey('market_sources', $plain);
        $this->assertSame(['lead' => 1, 'verify' => 2], $rich['signal_grades']);
        $this->assertSame(3, $rich['market_sources']['items']);
        foreach (array_keys($plain) as $k) {
            $this->assertArrayHasKey($k, $rich, "existing stage_stats key $k is kept");
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function researcher(StubFetcher $f): OpportunityResearcher
    {
        $gate = (new \ReflectionClass(QualificationGate::class))->newInstanceWithoutConstructor();
        $r = new OpportunityResearcher($this->createMock(StructuredAi::class), $this->createMock(SignalRunner::class), $f, $this->createMock(MarketImporter::class), $gate);
        $ctx = (new \ReflectionClass(ProductContext::class))->newInstanceWithoutConstructor();
        \Closure::bind(function () {
            $this->key = 'g2g';
            $this->businessFit = 'other';
        }, $ctx, ProductContext::class)();
        $this->setProp($r, 'ctx', $ctx);
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
