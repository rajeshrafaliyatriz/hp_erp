<?php

namespace Tests\Feature;

use App\Domain\Signals\Support\RobotsPolicy;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Domain\Signals\Support\UnsafeUrlException;
use Tests\Support\RunsSlowHttpServer;
use Tests\TestCase;

/**
 * SafeUrlFetcher against a REAL local misbehaving HTTP server (tests/Fixtures/slow_http_server.php), through the real
 * cURL transport: no Http::fake, nothing outside 127.0.0.1 is contacted.
 *
 * What this proves: a genuine total deadline (slow body, slow headers, silent server, redirect chains, robots.txt then page),
 * the DNS pin actually being used by the transport, redirect re-validation, and the byte cap aborting the transfer.
 * What it does not prove: behaviour of any real website, TLS, or other operating systems' resolvers.
 */
class SafeUrlFetcherDeadlineTest extends TestCase
{
    use RunsSlowHttpServer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['signals.ingestion.min_host_interval_ms' => 0, 'signals.ingestion.user_agent' => 'G2G-Signals-Bot/1.0']);
    }

    protected function tearDown(): void
    {
        $this->stopSlowServer();
        parent::tearDown();
    }

    private function expectFailure(callable $call, string $category): array
    {
        $started = microtime(true);
        try {
            $call();
        } catch (UnsafeUrlException $e) {
            $this->assertSame($category, $e->category, "unexpected category; message: {$e->getMessage()}");

            return [$e, microtime(true) - $started];
        }
        $this->fail("expected an UnsafeUrlException of category {$category}");
    }

    // ── total deadline ───────────────────────────────────────────────────────

    public function test_a_server_that_drips_the_body_is_cut_off_at_the_total_timeout(): void
    {
        $this->startSlowServer();
        $fetcher = $this->pinnedFetcher();

        [$e, $elapsed] = $this->expectFailure(fn () => $fetcher->fetch($this->slowUrl('/drip'), null, 2), 'timeout');

        $this->assertLessThan(5.0, $elapsed, 'a per-read timeout would let this run for the 20 s the server drips');
        $this->assertGreaterThan(1.5, $elapsed);
        $this->assertSame('The website did not respond in time.', $e->getMessage());
    }

    public function test_a_server_that_drips_its_headers_is_cut_off_at_the_total_timeout(): void
    {
        $this->startSlowServer();

        [, $elapsed] = $this->expectFailure(fn () => $this->pinnedFetcher()->fetch($this->slowUrl('/headers-drip'), null, 2), 'timeout');

        $this->assertLessThan(5.0, $elapsed);
    }

    public function test_a_server_that_never_answers_times_out(): void
    {
        $this->startSlowServer();

        [, $elapsed] = $this->expectFailure(fn () => $this->pinnedFetcher()->fetch($this->slowUrl('/hang'), null, 2), 'timeout');

        $this->assertLessThan(5.0, $elapsed);
    }

    public function test_the_timeout_budget_is_shared_by_every_redirect_hop(): void
    {
        $this->startSlowServer();

        // Each hop takes 1 s, which is well inside a 2.5 s per-request timeout. Three hops cannot fit in 2.5 s in total.
        [, $elapsed] = $this->expectFailure(fn () => $this->pinnedFetcher()->fetch($this->slowUrl('/hop/3'), null, 2), 'timeout');

        $this->assertLessThan(4.5, $elapsed, 'the chain was cut at the total, not allowed 2 s per hop');
        $this->assertGreaterThan(1.8, $elapsed);
    }

    public function test_a_redirect_chain_that_fits_the_budget_is_followed_to_the_page(): void
    {
        $this->startSlowServer();

        $page = $this->pinnedFetcher()->fetch($this->slowUrl('/hop/2'), null, 10);

        $this->assertSame(200, $page['status']);
        $this->assertStringEndsWith('/hop/0', $page['url']);
        $this->assertStringContainsString('Acme builds school software', $page['body']);
    }

    public function test_robots_txt_and_the_page_share_one_operation_deadline(): void
    {
        $this->startSlowServer(robotsDelayMs: 1500);
        $fetcher = $this->pinnedFetcher()->withinSeconds(2.5);
        $started = microtime(true);

        $allowed = (new RobotsPolicy($fetcher))->decision($this->slowUrl('/drip'));
        $robotsTook = microtime(true) - $started;
        $this->assertTrue($allowed['allowed'], 'robots.txt itself answered, slowly');
        $this->assertGreaterThan(1.2, $robotsTook);

        // The page call asks for 12 s of its own, but only what is left of the operation's 2.5 s is available.
        [, $pageTook] = $this->expectFailure(fn () => $fetcher->fetch($this->slowUrl('/drip'), null, 12), 'timeout');

        $this->assertLessThan(2.2, $pageTook, 'the page got the remainder, not a fresh 12 s');
        $this->assertLessThan(4.5, microtime(true) - $started);
    }

    public function test_no_request_is_started_once_the_operation_deadline_has_passed(): void
    {
        $this->startSlowServer();
        $fetcher = $this->pinnedFetcher()->withinSeconds(0.0);

        [, $elapsed] = $this->expectFailure(fn () => $fetcher->fetch($this->slowUrl('/ok'), null, 12), 'timeout');

        $this->assertLessThan(0.5, $elapsed);
    }

    // ── DNS pin ──────────────────────────────────────────────────────────────

    public function test_the_connection_uses_the_validated_address_not_a_fresh_dns_lookup(): void
    {
        $this->startSlowServer();

        // pinned-host.invalid cannot be resolved by any DNS (RFC 2606). Reaching the server proves cURL connected to the
        // address validate() returned (CURLOPT_RESOLVE) and never looked the name up itself.
        $page = $this->pinnedFetcher()->fetch($this->slowUrl('/echo-host'), null, 10);

        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('host=pinned-host.invalid:' . $this->slowServerPortForAssertions(), $page['body'], 'the original Host name is still what is sent');
    }

    // ── redirects are re-validated, never followed blindly ───────────────────

    public function test_a_redirect_to_the_cloud_metadata_address_is_refused(): void
    {
        $this->startSlowServer();

        [$e] = $this->expectFailure(fn () => $this->pinnedFetcher()->fetch($this->slowUrl('/to-metadata'), null, 10), 'blocked_address');

        $this->assertSame('That address is not a public website.', $e->getMessage());
    }

    public function test_a_redirect_to_a_loopback_or_private_address_is_refused(): void
    {
        $this->startSlowServer();

        $this->expectFailure(fn () => $this->pinnedFetcher()->fetch($this->slowUrl('/to-loopback'), null, 10), 'blocked_address');
        $this->expectFailure(fn () => $this->pinnedFetcher()->fetch($this->slowUrl('/to-private'), null, 10), 'blocked_address');
    }

    public function test_a_redirect_loop_stops_after_three_redirects(): void
    {
        $this->startSlowServer();

        $this->expectFailure(fn () => $this->pinnedFetcher()->fetch($this->slowUrl('/loop'), null, 10), 'too_many_redirects');
    }

    public function test_a_host_that_resolves_to_a_private_address_is_refused_before_any_request(): void
    {
        $fetcher = new SafeUrlFetcher(fn () => ['93.184.216.34', '10.1.2.3']);   // one bad answer is enough

        $this->expectFailure(fn () => $fetcher->fetch('https://rebind.example.com/', null, 10), 'blocked_address');
    }

    // ── size cap and content type ────────────────────────────────────────────

    public function test_an_oversized_body_aborts_the_transfer_and_comes_back_truncated(): void
    {
        $this->startSlowServer();
        $started = microtime(true);

        $page = $this->pinnedFetcher()->fetch($this->slowUrl('/big'), 100000, 10);

        $this->assertTrue($page['truncated']);
        $this->assertSame(100000, strlen($page['body']));
        $this->assertSame(200, $page['status']);
        $this->assertLessThan(5.0, microtime(true) - $started);
    }

    public function test_a_normal_page_is_returned_whole(): void
    {
        $this->startSlowServer();

        $page = $this->pinnedFetcher()->fetch($this->slowUrl('/ok'), null, 10);

        $this->assertFalse($page['truncated']);
        $this->assertSame('text/html', $page['content_type']);
        $this->assertStringContainsString('<title>Acme</title>', $page['body']);
    }

    public function test_a_non_html_page_is_still_refused(): void
    {
        $this->startSlowServer();

        $this->expectFailure(fn () => $this->pinnedFetcher()->fetch($this->slowUrl('/non-html'), null, 10), 'non_html');
    }

    private function slowServerPortForAssertions(): int
    {
        return (int) parse_url($this->slowUrl('/'), PHP_URL_PORT);
    }
}
