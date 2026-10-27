<?php

namespace Tests\Unit;

use App\Domain\Signals\Support\RobotsPolicy;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Domain\Signals\Support\UnsafeUrlException;
use PHPUnit\Framework\TestCase;

class RobotsPolicyTest extends TestCase
{
    private const BOT = 'g2g-signals-bot';

    private function ev(string $robots, string $path, string $bot = self::BOT): bool
    {
        return RobotsPolicy::evaluate($robots, $path, $bot);
    }

    /** @param array<int, array|\Throwable> $script one entry per fetch call (the last repeats) */
    private function fetcher(array $script, ?\stdClass $counter = null): SafeUrlFetcher
    {
        $counter ??= new \stdClass();
        $counter->n = 0;

        return new class($script, $counter) extends SafeUrlFetcher {
            public function __construct(private array $script, private \stdClass $counter)
            {
            }

            public function fetch(string $url, ?int $maxBytes = null, ?int $timeout = null, bool $verifyTls = true, array $extraTypes = []): array
            {
                $r = $this->script[min($this->counter->n, count($this->script) - 1)];
                $this->counter->n++;
                if ($r instanceof \Throwable) {
                    throw $r;
                }

                return $r + ['url' => $url, 'status' => 200, 'content_type' => 'text/plain', 'truncated' => false];
            }
        };
    }

    private function body(string $b): array
    {
        return ['body' => $b];
    }

    public function test_allow_and_deny_with_prefix(): void
    {
        $r = "User-agent: *\nDisallow: /private";
        $this->assertFalse($this->ev($r, '/private/x'));
        $this->assertTrue($this->ev($r, '/public'));
    }

    public function test_wildcard_and_end_anchor(): void
    {
        $r = "User-agent: *\nDisallow: /*.pdf$\nDisallow: /tmp*/cache";
        $this->assertFalse($this->ev($r, '/a/b.pdf'));
        $this->assertTrue($this->ev($r, '/a/b.pdf?x=1'));
        $this->assertTrue($this->ev($r, '/a/b.pdfx'));
        $this->assertFalse($this->ev($r, '/tmp123/cache/z'));
        $this->assertTrue($this->ev($r, '/tmp123/other'));
    }

    public function test_longest_match_wins_and_allow_wins_ties(): void
    {
        $r = "User-agent: *\nDisallow: /a\nAllow: /a/b\nDisallow: /a/b/c";
        $this->assertFalse($this->ev($r, '/a/x'));
        $this->assertTrue($this->ev($r, '/a/b/x'));
        $this->assertFalse($this->ev($r, '/a/b/c'));
        $this->assertTrue($this->ev("User-agent: *\nDisallow: /p\nAllow: /p", '/p'));
        $this->assertTrue($this->ev("User-agent: *\nAllow: /p\nDisallow: /p", '/p'));
    }

    public function test_user_agent_groups(): void
    {
        $r = "User-agent: *\nDisallow: /\n\nUser-agent: Other\nUser-agent: G2G-Signals-Bot\nDisallow: /secret\n\nUser-agent: g2g-signals-bot\nDisallow: /more";
        $this->assertTrue($this->ev($r, '/ok'));
        $this->assertFalse($this->ev($r, '/secret/a'));
        $this->assertFalse($this->ev($r, '/more'));   // same-name groups merge
        $this->assertFalse($this->ev($r, '/ok', 'unknown'));   // falls back to *
        $this->assertFalse($this->ev($r, '/secret', 'OTHER'));
    }

    public function test_named_group_without_rules_overrides_star(): void
    {
        $this->assertTrue($this->ev("User-agent: *\nDisallow: /\n\nUser-agent: g2g-signals-bot\n", '/x'));
    }

    public function test_comments_blank_lines_and_empty_disallow(): void
    {
        $r = "# header\n\nUser-agent: * # everyone\nDisallow: # nothing\n\n";
        $this->assertTrue($this->ev($r, '/anything'));
        $r2 = "User-agent: *\nDisallow: /x # trailing\nCrawl-delay: 5\nSitemap: https://a.example/s.xml\n";
        $this->assertFalse($this->ev($r2, '/x/y'));
        $this->assertTrue($this->ev($r2, '/y'));
    }

    public function test_percent_encoding_is_normalised(): void
    {
        $this->assertFalse($this->ev("User-agent: *\nDisallow: /a b", '/a%20b'));
        $this->assertFalse($this->ev("User-agent: *\nDisallow: /%7Euser", '/~user/x'));
        $this->assertFalse($this->ev("User-agent: *\nDisallow: /~user", '/%7euser'));
        $this->assertFalse($this->ev("User-agent: *\nDisallow: /caf%c3%a9", '/caf%C3%A9'));
    }

    public function test_decision_reasons_for_fetch_outcomes(): void
    {
        $mk = fn ($script) => new RobotsPolicy($this->fetcher($script));
        $u = 'https://s.example/p';

        $this->assertSame(['allowed' => true, 'reason' => 'allowed'], $mk([$this->body("User-agent: *\nDisallow: /x")])->decision($u));
        $this->assertSame(['allowed' => false, 'reason' => 'robots_disallowed'], $mk([$this->body("User-agent: *\nDisallow: /p")])->decision($u));
        foreach ([404, 410, 403, 401] as $s) {
            $this->assertSame(['allowed' => true, 'reason' => 'no_robots_file'], $mk([new UnsafeUrlException('x', 'http_status', $s)])->decision($u), (string) $s);
        }
        foreach ([500, 503, 429] as $s) {
            $this->assertSame(['allowed' => false, 'reason' => 'robots_unavailable'], $mk([new UnsafeUrlException('x', 'http_status', $s)])->decision($u), (string) $s);
        }
        foreach (['timeout', 'dns', 'connection', 'too_many_redirects', 'blocked_address', 'non_html'] as $c) {
            $this->assertSame(['allowed' => false, 'reason' => 'robots_unavailable'], $mk([new UnsafeUrlException('x', $c)])->decision($u), $c);
        }
        $this->assertSame('robots_unavailable', $mk([new \RuntimeException('boom')])->decision($u)['reason']);
        $this->assertSame('robots_unavailable', $mk([['body' => 'User-agent: *', 'truncated' => true]])->decision($u)['reason']);
        $this->assertSame('robots_unavailable', $mk([['body' => "\0\1binary"]])->decision($u)['reason']);
    }

    public function test_allows_is_the_decision_and_budget_is_rethrown(): void
    {
        $p = new RobotsPolicy($this->fetcher([new UnsafeUrlException('x', 'timeout')]));
        $this->assertFalse($p->allows('https://s.example/p'));
        $this->expectException(UnsafeUrlException::class);
        (new RobotsPolicy($this->fetcher([new UnsafeUrlException('x', 'budget')])))->allows('https://s.example/p');
    }

    public function test_invalid_url_is_not_allowed(): void
    {
        $this->assertFalse((new RobotsPolicy($this->fetcher([$this->body('')])))->allows('not a url'));
    }

    public function test_repeated_host_is_fetched_once_and_origins_are_separate(): void
    {
        $c = new \stdClass();
        $p = new RobotsPolicy($this->fetcher([$this->body("User-agent: *\nDisallow: /no")], $c));
        $this->assertTrue($p->allows('https://s.example/a'));
        $this->assertFalse($p->allows('https://s.example/no'));
        $this->assertSame(1, $c->n);
        $p->allows('https://other.example/a');
        $this->assertSame(2, $c->n);
    }

    public function test_no_robots_file_is_cached_but_transient_failure_is_not_permanent(): void
    {
        $c = new \stdClass();
        $p = new RobotsPolicy($this->fetcher([new UnsafeUrlException('x', 'http_status', 404)], $c));
        $p->allows('https://s.example/a');
        $p->allows('https://s.example/b');
        $this->assertSame(1, $c->n);

        $now = 1000.0;
        $c = new \stdClass();
        $p = new RobotsPolicy($this->fetcher([new UnsafeUrlException('x', 'timeout'), $this->body("User-agent: *\nDisallow: /no")], $c), function () use (&$now) {
            return $now;
        }, 60);
        $this->assertFalse($p->allows('https://s.example/a'));
        $this->assertFalse($p->allows('https://s.example/b'));   // inside the short TTL: no re-fetch
        $this->assertSame(1, $c->n);
        $now += 61;
        $this->assertTrue($p->allows('https://s.example/a'));    // asked again, now readable
        $this->assertSame(2, $c->n);

        $c = new \stdClass();
        $p = new RobotsPolicy($this->fetcher([new UnsafeUrlException('x', 'http_status', 503), $this->body('')], $c), null, 0);
        $this->assertFalse($p->allows('https://s.example/a'));
        $this->assertTrue($p->allows('https://s.example/a'));    // ttl 0: never cached
        $this->assertSame(2, $c->n);
    }
}
