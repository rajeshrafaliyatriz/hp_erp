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
 */
class SafeUrlFetcher
{
    private const MAX_REDIRECTS = 3;

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
    public function fetch(string $url, ?int $maxBytes = null, ?int $timeout = null): array
    {
        $maxBytes ??= (int) config('signals.ingestion.url_max_bytes', 2097152);
        $timeout ??= (int) config('signals.ingestion.url_timeout', 12);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$host, $port, $ip] = $this->validate($url);

            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'stream' => true,
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
                ])->timeout($timeout)->connectTimeout(min(8, $timeout))
                    ->withHeaders(['User-Agent' => (string) config('signals.ingestion.user_agent'), 'Accept' => 'text/html,text/plain;q=0.9'])
                    ->get($url);
            } catch (\Throwable) {
                throw new UnsafeUrlException('The page could not be retrieved.');
            }

            $status = $response->status();

            if ($status >= 300 && $status < 400 && $response->header('Location')) {
                $url = $this->absolute($url, (string) $response->header('Location'));

                continue; // re-validated at the top of the loop
            }

            if ($status < 200 || $status >= 300) {
                throw new UnsafeUrlException("The page returned HTTP {$status}.");
            }

            $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            if ($type !== '' && ! in_array($type, ['text/html', 'text/plain', 'application/xhtml+xml'], true)) {
                throw new UnsafeUrlException('Only HTML or plain-text pages can be ingested.');
            }

            $stream = $response->toPsrResponse()->getBody();
            $body = '';
            while (! $stream->eof() && strlen($body) <= $maxBytes) {
                $body .= $stream->read(8192);
            }
            $truncated = strlen($body) > $maxBytes;

            return [
                'url' => $url,
                'status' => $status,
                'content_type' => $type ?: 'text/html',
                'body' => $truncated ? substr($body, 0, $maxBytes) : $body,
                'truncated' => $truncated,
            ];
        }

        throw new UnsafeUrlException('Too many redirects.');
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
            throw new UnsafeUrlException('Enter a valid http(s) URL.');
        }
        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new UnsafeUrlException('Only http and https URLs are allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrlException('URLs with embedded credentials are not allowed.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = (int) ($parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80));

        if (! in_array($port, [80, 443], true)) {
            throw new UnsafeUrlException('Only standard web ports are allowed.');
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new UnsafeUrlException('That address is not a public website.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);
        if ($ips === []) {
            throw new UnsafeUrlException('The website address could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new UnsafeUrlException('That address is not a public website.');
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
