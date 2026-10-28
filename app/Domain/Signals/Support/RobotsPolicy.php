<?php

namespace App\Domain\Signals\Support;

/**
 * A robots.txt reader aligned with RFC 9309.
 *
 * Fetch outcome -> decision
 *  - 2xx, parsable body          -> the rules decide ('allowed' / 'robots_disallowed')
 *  - 404, 410 and other 4xx      -> 'no_robots_file' (allowed); 429 is the exception: rate limiting is transient
 *  - 5xx, 429, timeout, DNS or connection failure, blocked redirect target, wrong content type,
 *    truncated (oversized) body  -> 'robots_unavailable' (NOT allowed for this attempt, but transient: the caller should retry later)
 *  - the run's request ceiling   -> the UnsafeUrlException (category 'budget') is rethrown, never read as a decision
 *
 * Redirects: SafeUrlFetcher follows and re-validates every hop of the robots.txt request itself (SSRF checks per hop).
 *
 * Caching: a body and 'no_robots_file' are remembered for the life of this object, so one site is asked once. A transient
 * 'robots_unavailable' is remembered only for $unavailableTtl seconds (default 60, 0 = not at all), so a later run or retry asks again.
 *
 * Rules: groups by user-agent (case-insensitive, several UA lines per group, same-name groups merged, fallback to `*`), `*` wildcard,
 * `$` end anchor, longest pattern wins and Allow wins a tie, empty `Disallow:` allows all, percent-encoding is normalised.
 */
class RobotsPolicy
{
    public const MAX_BYTES = 65536;

    /** @var array<string, array{kind: 'body'|'none'|'unavailable', body: string, until: ?float}> by origin */
    private array $cache = [];

    /** @var \Closure(): float */
    private \Closure $clock;

    /**
     * @param  (callable(): float)|null  $clock  seconds; tests inject one
     */
    public function __construct(private readonly SafeUrlFetcher $fetcher, ?callable $clock = null, private readonly int $unavailableTtl = 60)
    {
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : fn (): float => microtime(true);
    }

    public function allows(string $url, bool $verifyTls = true): bool
    {
        return $this->decision($url, $verifyTls)['allowed'];
    }

    /**
     * @return array{allowed: bool, reason: 'allowed'|'no_robots_file'|'robots_disallowed'|'robots_unavailable'}
     *                                                                                                          'robots_unavailable' is transient: not allowed now, worth retrying in a later run.
     */
    public function decision(string $url, bool $verifyTls = true): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return ['allowed' => false, 'reason' => 'robots_disallowed'];
        }

        $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $path = $path === '' ? '/' : $path;

        $entry = $this->cache[$origin] ?? null;
        if ($entry !== null && $entry['kind'] === 'unavailable' && ($this->clock)() >= $entry['until']) {
            $entry = null;   // transient failure has aged out: ask again
        }
        if ($entry === null) {
            $entry = $this->load($origin, $verifyTls);
            if ($entry['kind'] !== 'unavailable' || $this->unavailableTtl > 0) {
                $this->cache[$origin] = $entry;
            }
        }

        if ($entry['kind'] === 'unavailable') {
            return ['allowed' => false, 'reason' => 'robots_unavailable'];
        }
        if ($entry['kind'] === 'none') {
            return ['allowed' => true, 'reason' => 'no_robots_file'];
        }

        $ok = self::evaluate($entry['body'], $path, self::botName());

        return ['allowed' => $ok, 'reason' => $ok ? 'allowed' : 'robots_disallowed'];
    }

    /** @return array{kind: 'body'|'none'|'unavailable', body: string, until: ?float} */
    private function load(string $origin, bool $verifyTls): array
    {
        try {
            $robots = $this->fetcher->fetch($origin . '/robots.txt', self::MAX_BYTES, 6, $verifyTls, ['text/plain', 'text/html', 'application/octet-stream']);
        } catch (UnsafeUrlException $e) {
            if ($e->category === 'budget') {
                throw $e;   // a run's request ceiling is never read as "no robots.txt, so allowed"
            }
            if ($e->category === 'http_status' && $e->httpStatus !== null && $e->httpStatus >= 400 && $e->httpStatus < 500 && $e->httpStatus !== 429) {
                return ['kind' => 'none', 'body' => '', 'until' => null];
            }

            return $this->unavailable();
        } catch (\Throwable) {
            return $this->unavailable();
        }

        if (! is_string($robots['body'] ?? null) || ! empty($robots['truncated']) || str_contains($robots['body'], "\0")) {
            return $this->unavailable();   // oversized or binary: cannot be trusted to have been read in full
        }

        return ['kind' => 'body', 'body' => $robots['body'], 'until' => null];
    }

    /** @return array{kind: 'unavailable', body: string, until: float} */
    private function unavailable(): array
    {
        return ['kind' => 'unavailable', 'body' => '', 'until' => ($this->clock)() + $this->unavailableTtl];
    }

    private static function botName(): string
    {
        try {
            $ua = (string) config('signals.ingestion.user_agent', 'G2G-Signals-Bot/1.0');
        } catch (\Throwable) {
            $ua = 'G2G-Signals-Bot/1.0';   // no application booted (pure unit test)
        }

        return strtolower(explode('/', $ua)[0]);
    }

    public static function evaluate(string $robots, string $path, string $botName): bool
    {
        $groups = [];
        $agents = [];
        $lastWasAgent = false;

        foreach (preg_split('/\R/', $robots) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if (! $lastWasAgent) {
                    $agents = [];
                }
                $agent = strtolower($value);
                $agents[] = $agent;
                $groups[$agent] ??= [];
                $lastWasAgent = true;

                continue;
            }
            $lastWasAgent = false;

            if (in_array($field, ['allow', 'disallow'], true)) {
                foreach ($agents as $agent) {
                    $groups[$agent][] = [$field, $value];
                }
            }
        }

        $rules = $groups[strtolower($botName)] ?? $groups['*'] ?? [];
        $target = self::normalise($path === '' ? '/' : $path);
        $verdict = true;
        $best = -1;
        foreach ($rules as [$type, $pattern]) {
            if ($pattern === '') {
                continue;   // `Disallow:` with nothing = allow all; an empty Allow changes nothing
            }
            $pattern = self::normalise($pattern);
            if (! self::matches($pattern, $target)) {
                continue;
            }
            $len = strlen($pattern);
            if ($len > $best || ($len === $best && $type === 'allow')) {
                $best = $len;
                $verdict = $type === 'allow';
            }
        }

        return $verdict;
    }

    private static function matches(string $pattern, string $target): bool
    {
        $anchored = str_ends_with($pattern, '$');
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }
        $regex = implode('.*', array_map(fn (string $s): string => preg_quote($s, '#'), explode('*', $pattern)));

        return (bool) preg_match('#^' . $regex . ($anchored ? '\z' : '') . '#s', $target);
    }

    /** Decode %XX of unreserved characters, upper-case the hex of the rest, and percent-encode raw non-ASCII bytes. */
    private static function normalise(string $s): string
    {
        $s = preg_replace_callback('/%([0-9a-fA-F]{2})/', function (array $m): string {
            $c = chr(hexdec($m[1]));

            return preg_match('/^[A-Za-z0-9\-._~]$/', $c) ? $c : '%' . strtoupper($m[1]);
        }, $s) ?? $s;

        return preg_replace_callback('/[^\x21-\x7E]/', fn (array $m): string => sprintf('%%%02X', ord($m[0])), $s) ?? $s;
    }
}
