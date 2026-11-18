<?php

namespace App\Domain\Signals\Support;

use Illuminate\Support\Facades\Http;

/**
 * Fetches a PUBLIC web page on behalf of a user, without letting them (or a
 * redirect, or DNS trickery) reach the server's own network.
 *
 * WHAT IS BLOCKED, AND WHERE
 *  - Scheme other than http/https, embedded credentials, ports other than 80/443.
 *  - localhost / .local / .internal names.
 *  - Any host whose DNS answer includes a private, loopback, link-local (which
 *    covers the 169.254.169.254 cloud-metadata address), CGNAT, reserved or
 *    IPv6-local address. ALL answers are checked, not just the first.
 *  - Redirects: never followed by the HTTP client. Each hop is re-validated from
 *    scratch here, so a public URL that 302s to http://169.254.169.254/ is refused.
 *  - DNS rebinding: the connection is pinned to the address that was validated
 *    (CURLOPT_RESOLVE), so a second lookup cannot return a different one.
 *  - Size and type: the body is read only up to a byte cap, and only text-like
 *    content types are accepted.
 *  - Time: a REAL deadline. The request goes through Guzzle's cURL handler, where `timeout` is
 *    CURLOPT_TIMEOUT_MS: a cap on the whole transfer (connect, headers and body), not on each read.
 *    `fetch()`'s timeout is the budget for ALL its hops: every redirect hop gets only what is left.
 *    withinSeconds() carries one deadline across several calls (robots.txt, then the page).
 *
 * WHY NOT `'stream' => true` (what this class used before): Guzzle routes streaming requests to PHP's
 * StreamHandler. There `timeout` is only a per-read socket timeout (a server that drips a byte every few
 * seconds is never cut off), and the `curl` option, including CURLOPT_RESOLVE, is silently ignored, so the
 * DNS pin did nothing. With the cURL handler both work. The byte cap is enforced in a write callback that
 * aborts the transfer, so nothing beyond the cap is downloaded.
 */
class SafeUrlFetcher
{
    private const MAX_REDIRECTS = 3;

    /** Do not start another request with less than this left of the deadline: it could only time out. */
    private const MIN_ATTEMPT_SECONDS = 0.25;

    /** Absolute microtime() after which no request is started and a running one is cut off (null = only fetch()'s own timeout). */
    private ?float $deadline = null;

    /**
     * A copy of this fetcher that must finish all its work within $seconds from now (and never later than a deadline this one
     * already carries). Use it for one logical operation made of several fetches, so robots.txt, redirects and the page share one
     * budget instead of each getting a full timeout of their own.
     */
    public function withinSeconds(float $seconds): static
    {
        $copy = clone $this;
        $deadline = microtime(true) + max(0.0, $seconds);
        $copy->deadline = $this->deadline === null ? $deadline : min($this->deadline, $deadline);

        return $copy;
    }

    /** @var (callable(string): array<int, string>)|null */
    private $resolver;

    /** @param (callable(string): array<int, string>)|null $resolver host => list of IPs (tests inject one) */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver;
    }

    /**
     * @return array{url: string, status: int, content_type: string, body: string, truncated: bool}
     *
     * @throws UnsafeUrlException when the URL, a redirect target, or the content is not acceptable
     */
    /**
     * @param  bool  $verifyTls  false ONLY for a page being read to check who it is, after the site's identity was verified by other
     *                           evidence; the caller must say the certificate was not verified. Every other protection still applies.
     */
    private static ?HostRateLimiter $limiter = null;
    private static int $limiterInterval = -1;

    /** One limiter per process, rebuilt if the configured interval changes. */
    private static function limiter(): HostRateLimiter
    {
        $interval = max(0, (int) config('signals.ingestion.min_host_interval_ms', 300));
        if (self::$limiter === null || self::$limiterInterval !== $interval) {
            self::$limiter = new HostRateLimiter($interval);
            self::$limiterInterval = $interval;
        }

        return self::$limiter;
    }

    public function fetch(string $url, ?int $maxBytes = null, ?int $timeout = null, bool $verifyTls = true, array $extraTypes = []): array
    {
        $maxBytes ??= (int) config('signals.ingestion.url_max_bytes', 2097152);
        $timeout ??= (int) config('signals.ingestion.url_timeout', 12);

        if (! defined('CURLOPT_RESOLVE')) {
            // Without cURL, Guzzle would use PHP's stream handler: no DNS pin and no total timeout. Refuse rather than fetch unpinned.
            throw new UnsafeUrlException('The page could not be retrieved.', 'connection');
        }

        // One budget for the whole call, redirect hops included; a carried deadline (withinSeconds) can only shorten it.
        $deadline = microtime(true) + $timeout;
        if ($this->deadline !== null) {
            $deadline = min($deadline, $this->deadline);
        }

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$host, $port, $ip] = $this->validate($url);
            // A verification run has a hard ceiling on page requests (each redirect hop, robots.txt and sitemap request counts). Off otherwise.
            if (! \App\Domain\Signals\Run\RunBudget::take(\App\Domain\Signals\Run\RunBudget::PAGE)) {
                throw new UnsafeUrlException('The page request limit for this run was reached.', 'budget');
            }

            // Be polite to one host: a minimum gap between requests to it (config; 0 = off). It only waits, it adds no request.
            self::limiter()->wait($host);

            // What this hop may still use of the budget. Computed after validation and the politeness wait, which also take time.
            $remaining = $deadline - microtime(true);
            if ($remaining < self::MIN_ATTEMPT_SECONDS) {
                throw new UnsafeUrlException(self::failureMessage('timeout'), 'timeout');
            }

            // The body is collected by our own write callback (cURL handler), so the cap can abort the transfer; `$collected`
            // is only used when the callback ran (under Http::fake the callback never runs and the response carries the body).
            $buffer = '';
            $collected = false;
            $overflow = false;
            $headersSeen = null;
            $capture = static function ($handle, string $chunk) use (&$buffer, &$collected, &$overflow, $maxBytes): int {
                $collected = true;
                $room = ($maxBytes + 1) - strlen($buffer);
                if ($room <= 0) {
                    $overflow = true;

                    return 0;   // a short write makes cURL abort the transfer
                }
                if (strlen($chunk) > $room) {
                    $buffer .= substr($chunk, 0, $room);
                    $overflow = true;

                    return $room;
                }
                $buffer .= $chunk;

                return strlen($chunk);
            };

            $response = null;
            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'verify' => $verifyTls,
                    'curl' => [
                        CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
                        CURLOPT_WRITEFUNCTION => $capture,
                    ],
                    'on_headers' => static function (\Psr\Http\Message\ResponseInterface $received) use (&$headersSeen): void {
                        $headersSeen = $received;
                    },
                ])->timeout($remaining)->connectTimeout(min(8.0, $remaining))
                    ->withHeaders([
                        // The standard crawler form ("compatible; <bot>; +info"): still announces itself as a bot (robots.txt is read under that
                        // name), but looks like the ordinary client many site firewalls expect, so a plain bot token alone is not refused.
                        'User-Agent' => self::headerUserAgent((string) config('signals.ingestion.user_agent')),
                        'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.5',
                        'Accept-Language' => 'en-IN,en;q=0.9',
                    ])
                    ->get($url);
            } catch (\Throwable $transport) {
                // Aborting on the byte cap surfaces as a transport error; it is a truncated page, not a failure.
                if (! $overflow || $headersSeen === null) {
                    $category = FetchFailure::transport($transport);

                    throw new UnsafeUrlException(self::failureMessage($category), $category, null, $transport);
                }
            }

            if ($response !== null) {
                $status = $response->status();
                $header = static fn (string $name): string => (string) $response->header($name);
            } else {
                $status = $headersSeen->getStatusCode();
                $header = static fn (string $name): string => $headersSeen->getHeaderLine($name);
            }

            if ($status >= 300 && $status < 400 && $header('Location') !== '') {
                $url = $this->absolute($url, $header('Location'));

                continue; // re-validated at the top of the loop
            }

            if ($status < 200 || $status >= 300) {
                throw new UnsafeUrlException("The page returned HTTP {$status}.", 'http_status', $status);
            }

            $type = strtolower(trim(explode(';', $header('Content-Type'))[0]));
            if ($type !== '' && ! in_array($type, array_merge(['text/html', 'text/plain', 'application/xhtml+xml'], $extraTypes), true)) {
                throw new UnsafeUrlException('Only HTML or plain-text pages can be ingested.', 'non_html');
            }

            if ($collected) {
                $body = $buffer;
            } else {
                $stream = $response->toPsrResponse()->getBody();
                $body = '';
                while (! $stream->eof() && strlen($body) <= $maxBytes) {
                    $body .= $stream->read(8192);
                }
            }
            $truncated = strlen($body) > $maxBytes;

            return [
                'url' => $url,
                'status' => $status,
                'content_type' => $type ?: 'text/html',
                'body' => $truncated ? substr($body, 0, $maxBytes) : $body,
                'truncated' => $truncated,
                'last_modified' => trim($header('Last-Modified')) ?: null,
            ];
        }

        throw new UnsafeUrlException('Too many redirects.', 'too_many_redirects');
    }

    /** The message shown for a failed transfer. Fixed text per category: nothing from the transport error (a URL, an IP) is exposed. */
    /** The User-Agent header sent: the configured bot name wrapped in the standard "Mozilla/5.0 (compatible; ...)" form, unless it already is one. */
    private static function headerUserAgent(string $bot): string
    {
        $bot = trim($bot) !== '' ? trim($bot) : 'G2G-Signals-Bot/1.0';

        return stripos($bot, 'mozilla/') === 0 ? $bot : 'Mozilla/5.0 (compatible; ' . $bot . ')';
    }

    private static function failureMessage(string $category): string
    {
        return match ($category) {
            'timeout' => 'The website did not respond in time.',
            'dns' => 'The website address could not be resolved.',
            default => 'The page could not be retrieved.',
        };
    }

    /**
     * @return array{0: string, 1: int, 2: string} host, port, validated IP to pin
     *
     * @throws UnsafeUrlException
     */
    public function validate(string $url): array
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeUrlException('Enter a valid http(s) URL.', 'invalid_url');
        }
        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new UnsafeUrlException('Only http and https URLs are allowed.', 'blocked_url');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrlException('URLs with embedded credentials are not allowed.', 'blocked_url');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = (int) ($parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80));

        if (! in_array($port, [80, 443], true)) {
            throw new UnsafeUrlException('Only standard web ports are allowed.', 'blocked_url');
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new UnsafeUrlException('That address is not a public website.', 'blocked_address');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            try {
                $ips = $this->resolve($host);
            } catch (\Throwable $e) {
                // A resolver that fails is a failed lookup: never "allowed because we could not check", never an unpinned request.
                throw new UnsafeUrlException('The website address could not be resolved.', 'dns', null, $e);
            }
            // The system resolver cannot be interrupted from PHP, but it can be refused AFTER it returns: if it used up the time this
            // operation was given, nothing more is requested (see withinSeconds()).
            if ($this->deadline !== null && microtime(true) >= $this->deadline) {
                throw new UnsafeUrlException(self::failureMessage('timeout'), 'timeout');
            }
        }
        if ($ips === []) {
            throw new UnsafeUrlException('The website address could not be resolved.', 'dns');
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new UnsafeUrlException('That address is not a public website.', 'blocked_address');
            }
        }

        // Prefer IPv4 for the pin; curl's resolve list is simplest with it.
        usort($ips, fn ($a, $b) => (int) str_contains($a, ':') <=> (int) str_contains($b, ':'));

        return [$host, $port, $ips[0]];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // IPv4-mapped IPv6 (::ffff:10.0.0.1) is judged as the IPv4 address it wraps.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        if (str_contains($ip, ':')) {
            $bin = inet_pton($ip);
            $first = ord($bin[0]);
            // fc00::/7 unique-local, fe80::/10 link-local, ::/128 and ::1/128
            if (($first & 0xFE) === 0xFC || ($first === 0xFE && (ord($bin[1]) & 0xC0) === 0x80) || $bin === str_repeat("\0", 16) || $bin === str_repeat("\0", 15) . "\1") {
                return false;
            }

            return true;
        }

        $long = ip2long($ip);
        foreach ([['224.0.0.0', 4], ['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15], ['192.0.2.0', 24], ['198.51.100.0', 24], ['203.0.113.0', 24]] as [$net, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($long & $mask) === (ip2long($net) & $mask)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, string> */
    private function resolve(string $host): array
    {
        if ($this->resolver !== null) {
            return array_values(($this->resolver)($host));
        }

        $ips = @gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }

    private function absolute(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $b = parse_url($base);
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($location, '//')) {
            return $b['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        return $origin . rtrim(dirname($b['path'] ?? '/'), '/') . '/' . $location;
    }
}
