<?php

namespace Tests\Feature;

use App\Domain\Signals\Connectors\DataConnectorRegistry;
use App\Domain\Signals\Connectors\SocialConnectorApiException;
use App\Domain\Signals\Ingestion\DataConnector;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Domain\Signals\Support\UnsafeUrlException;
use App\Support\QueueWorkerLauncher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * What one connector sync is allowed to ask of the outside world, and what it does when the outside world misbehaves.
 *
 * Provider traffic is mocked (Http::fake): this proves our handling of pagination, cursors and error responses, not any provider's
 * real behaviour. The worker launcher never spawns a process here (a runner double replaces the spawn).
 */
class ConnectorFetchBoundsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        foreach (['2026_11_04_120000_create_g2g_data_connector_tables', '2026_11_04_140000_extend_g2g_data_connectors_for_social_sync'] as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }
        config(['signals_connectors.max_items' => 25, 'signals_connectors.max_fetch_pages' => 3, 'signals_connectors.linkedin.version' => '202610']);
    }

    protected function tearDown(): void
    {
        QueueWorkerLauncher::fakeRunner(null);
        parent::tearDown();
    }

    private function connector(string $type, array $o = []): DataConnector
    {
        return DataConnector::create($o + [
            'sub_institute_id' => 1, 'type' => $type, 'name' => 'c', 'product_key' => 'scholarclone', 'connection_status' => 'connected',
            'sync_enabled' => true, 'credentials' => ['access_token' => 'tok'], 'external_account_id' => $type === 'linkedin' ? '12345' : 'ACCT1',
        ]);
    }

    private function adapter(string $key): object
    {
        return app(DataConnectorRegistry::class)->get($key);
    }

    private function failure(callable $call): SocialConnectorApiException
    {
        try {
            $call();
        } catch (SocialConnectorApiException $e) {
            return $e;
        }
        $this->fail('expected the provider error to surface, not an empty or partial success');
    }

    // ── YouTube ──────────────────────────────────────────────────────────────

    /** A new, empty HTTP fake: stubs registered earlier in the same test would otherwise keep answering first. */
    private function freshHttp(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
    }

    private function fakeYouTube(callable $playlistPage): void
    {
        $this->freshHttp();
        $page = 0;
        Http::fake(function ($request) use (&$page, $playlistPage) {
            if (str_contains($request->url(), '/channels')) {
                return Http::response(['items' => [['contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU1']]]]], 200);
            }
            $page++;

            return $playlistPage($page);
        });
    }

    private function playlistRequests(): int
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), '/playlistItems'))->count();
    }

    public function test_youtube_stops_at_the_page_cap_when_every_entry_is_unusable(): void
    {
        $this->fakeYouTube(fn (int $n) => Http::response(['items' => [['contentDetails' => []]], 'nextPageToken' => "t{$n}"], 200));

        $items = $this->adapter('youtube')->fetch($this->connector('youtube'));

        $this->assertSame([], $items);
        $this->assertSame(3, $this->playlistRequests());
    }

    public function test_youtube_stops_on_a_repeated_page_token_and_on_an_empty_last_page(): void
    {
        $this->fakeYouTube(fn (int $n) => Http::response(['items' => [['contentDetails' => ['videoId' => "v{$n}"], 'snippet' => ['title' => "Video {$n}"]]], 'nextPageToken' => 'same'], 200));
        $items = $this->adapter('youtube')->fetch($this->connector('youtube'));
        $this->assertCount(2, $items, 'page 1, then page 2 introduced the token "same", page 3 would repeat it');
        $this->assertSame(2, $this->playlistRequests());

        $this->fakeYouTube(fn (int $n) => Http::response(['items' => []], 200));
        $this->assertSame([], $this->adapter('youtube')->fetch($this->connector('youtube')));
    }

    public function test_youtube_reads_no_more_than_the_item_limit(): void
    {
        config(['signals_connectors.max_items' => 2]);
        $this->fakeYouTube(fn (int $n) => Http::response(['items' => [
            ['contentDetails' => ['videoId' => "a{$n}"], 'snippet' => ['title' => 'A']],
            ['contentDetails' => ['videoId' => "b{$n}"], 'snippet' => ['title' => 'B']],
            ['contentDetails' => ['videoId' => "c{$n}"], 'snippet' => ['title' => 'C']],
        ], 'nextPageToken' => "t{$n}"], 200));

        $this->assertCount(2, $this->adapter('youtube')->fetch($this->connector('youtube')));
        $this->assertSame(1, $this->playlistRequests(), 'no second request once the limit is met');
    }

    // ── LinkedIn ─────────────────────────────────────────────────────────────

    public function test_linkedin_stops_at_the_page_cap_and_on_an_empty_page(): void
    {
        $requests = 0;
        $this->freshHttp();
        Http::fake(function () use (&$requests) {
            $requests++;

            return Http::response(['elements' => [['commentary' => 'no id on this element']]], 200);
        });
        $this->assertSame([], $this->adapter('linkedin')->fetch($this->connector('linkedin')));
        $this->assertSame(3, $requests, 'bounded even though the provider never runs out');

        $requests = 0;
        $this->freshHttp();
        Http::fake(function () use (&$requests) {
            $requests++;

            return Http::response(['elements' => []], 200);
        });
        $this->assertSame([], $this->adapter('linkedin')->fetch($this->connector('linkedin')));
        $this->assertSame(1, $requests);
    }

    // ── Instagram ────────────────────────────────────────────────────────────

    public function test_instagram_stops_on_a_repeated_cursor_and_at_the_page_cap(): void
    {
        $requests = 0;
        $this->freshHttp();
        Http::fake(function () use (&$requests) {
            $requests++;

            return Http::response(['data' => [], 'paging' => ['cursors' => ['after' => 'same']]], 200);
        });
        $this->assertSame([], $this->adapter('instagram_business')->fetch($this->connector('instagram_business')));
        $this->assertSame(1, $requests, 'an empty page ends the feed');

        $requests = 0;
        $this->freshHttp();
        Http::fake(function () use (&$requests) {
            $requests++;

            return Http::response(['data' => [['id' => "m{$requests}", 'permalink' => 'https://i.example/p']], 'paging' => ['cursors' => ['after' => "c{$requests}"]]], 200);
        });
        $this->adapter('instagram_business')->fetch($this->connector('instagram_business'));
        $this->assertSame(3, $requests, 'the cap, because every page has a fresh cursor');
    }

    // ── Provider errors are never an empty success ───────────────────────────

    public function test_a_rate_limit_is_reported_as_one_with_its_retry_delay_not_as_an_empty_feed(): void
    {
        Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => '120'])]);

        $e = $this->failure(fn () => $this->adapter('linkedin')->fetch($this->connector('linkedin')));

        $this->assertSame('rate_limited', $e->connectionStatus);
        $this->assertSame(120, $e->retryAfterSeconds);
    }

    public function test_an_expired_token_response_is_reported_as_token_expired(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 190]], 401)]);

        $this->assertSame('token_expired', $this->failure(fn () => $this->adapter('facebook_page')->fetch($this->connector('facebook_page')))->connectionStatus);
    }

    public function test_an_error_on_a_later_page_fails_the_fetch_instead_of_returning_a_partial_list_as_complete(): void
    {
        $requests = 0;
        $this->freshHttp();
        Http::fake(function () use (&$requests) {
            $requests++;

            return $requests === 1
                ? Http::response(['data' => [['id' => 'm1', 'permalink' => 'https://i.example/p', 'caption' => 'first page post with enough text']], 'paging' => ['cursors' => ['after' => 'c1']]], 200)
                : Http::response(['error' => ['message' => 'oops']], 500);
        });

        $e = $this->failure(fn () => $this->adapter('instagram_business')->fetch($this->connector('instagram_business')));

        $this->assertSame(500, $e->httpStatus);
        $this->assertSame(2, $requests, 'one retry is the job\'s decision (bounded by its own tries), never an endless loop here');
    }

    // ── DNS resolution failure and deadline ──────────────────────────────────

    public function test_a_resolver_that_throws_or_finds_nothing_is_a_dns_failure_and_no_request_is_made(): void
    {
        Http::fake();

        foreach ([fn () => throw new \RuntimeException('resolver down'), fn () => []] as $resolver) {
            try {
                (new SafeUrlFetcher($resolver))->fetch('https://news.example.com/', null, 10);
                $this->fail('expected a DNS failure');
            } catch (UnsafeUrlException $e) {
                $this->assertSame('dns', $e->category);
                $this->assertSame('The website address could not be resolved.', $e->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_a_lookup_that_uses_up_the_operation_deadline_ends_as_a_timeout_and_requests_nothing(): void
    {
        Http::fake();
        $slow = new SafeUrlFetcher(function () {
            usleep(400_000);

            return ['93.184.216.34'];
        });

        try {
            $slow->withinSeconds(0.2)->validate('https://news.example.com/');
            $this->fail('expected a timeout');
        } catch (UnsafeUrlException $e) {
            $this->assertSame('timeout', $e->category);
            $this->assertSame('The website did not respond in time.', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_a_private_address_in_any_answer_is_refused_and_a_timeout_never_falls_back_to_an_unpinned_request(): void
    {
        Http::fake();
        foreach (['10.0.0.8', '127.0.0.1', '169.254.169.254', '100.64.0.1', 'fd00::1', '::1', 'fe80::1', '::ffff:10.0.0.1'] as $private) {
            try {
                (new SafeUrlFetcher(fn () => ['93.184.216.34', $private]))->fetch('https://news.example.com/', null, 10);
                $this->fail("{$private} was accepted");
            } catch (UnsafeUrlException $e) {
                $this->assertSame('blocked_address', $e->category, $private);
            }
        }
        Http::assertNothingSent();
    }

    // ── Worker launcher ──────────────────────────────────────────────────────

    public function test_the_launcher_spawns_one_worker_for_a_burst_of_requests_and_records_what_it_did(): void
    {
        Log::spy();
        $spawned = [];
        QueueWorkerLauncher::fakeRunner(function (string $command, bool $windows) use (&$spawned) {
            $spawned[] = $command;
        });

        $this->assertTrue(QueueWorkerLauncher::start('signals'));
        $this->assertTrue(QueueWorkerLauncher::start('signals'), 'a second click inside the guard window is covered by the first worker');
        $this->assertTrue(QueueWorkerLauncher::start('signals'));

        $this->assertCount(1, $spawned, 'no pile of background workers');
        $this->assertStringContainsString('queue:work --queue=signals,default', $spawned[0]);
        $this->assertStringContainsString('--stop-when-empty', $spawned[0]);
        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $c = []) => $m === 'signals.queue_worker_launch' && $c['outcome'] === 'requested')->once();
        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $c = []) => $m === 'signals.queue_worker_launch' && $c['outcome'] === 'skipped_recent')->twice();

        Cache::flush();   // the guard window has passed
        QueueWorkerLauncher::start('signals');
        $this->assertCount(2, $spawned);
        QueueWorkerLauncher::start('other');
        $this->assertCount(3, $spawned, 'the guard is per queue');
    }

    public function test_a_launch_that_fails_is_reported_as_false_and_logged_without_leaking_anything(): void
    {
        Log::spy();
        QueueWorkerLauncher::fakeRunner(function () {
            throw new \RuntimeException('proc_open disabled');
        });

        $this->assertFalse(QueueWorkerLauncher::start('signals'));

        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c = []) => $m === 'signals.queue_worker_launch' && $c['outcome'] === 'failed' && $c['exception'] === \RuntimeException::class)->once();
    }

    public function test_the_launch_command_is_absolute_escaped_and_bounded_on_both_platforms(): void
    {
        $unix = QueueWorkerLauncher::command('signals', 'Linux', '/usr/bin/php', '/var/www/my app');
        $this->assertStringContainsString('cd ' . escapeshellarg('/var/www/my app') . ' && ' . escapeshellarg('/usr/bin/php') . ' artisan queue:work --queue=' . escapeshellarg('signals,default'), $unix);
        $this->assertStringContainsString('--tries=1 --timeout=900 --max-time=1500 --stop-when-empty > /dev/null 2>&1 &', $unix);

        $injection = QueueWorkerLauncher::command('signals; rm -rf /', 'Linux', '/usr/bin/php', '/var/www/g2gb');
        $this->assertStringContainsString('--queue=' . escapeshellarg('signals; rm -rf /,default'), $injection, 'the queue name is one quoted argument, never shell syntax');

        $win = QueueWorkerLauncher::command('signals', 'WINNT', 'C:\\php\\php.exe', 'C:\\app\\g2gb');
        $this->assertStringContainsString('start /B ""', $win);
        $this->assertStringContainsString('--stop-when-empty', $win);
    }
}
