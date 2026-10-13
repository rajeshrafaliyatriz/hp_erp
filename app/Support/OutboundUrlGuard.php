<?php

namespace App\Support;

/**
 * Blocks the agentic endpoint-dispatch SSRF path: a tenant-saved
 * `endpoint_url` is checked here before it is ever requested. Any address
 * the host resolves to that is private, loopback, link-local (which covers
 * cloud metadata endpoints like 169.254.169.254), or otherwise reserved is
 * refused — the dispatch payload this guards includes the tenant's
 * decrypted saved secrets, so a hostname that resolves inward is a direct
 * exfiltration path, not just an availability concern.
 *
 * Checked twice by design: once when an agent's endpoint_url is saved
 * (AgentController) and again immediately before dispatch (RunController),
 * since a hostname's DNS answer can legitimately change between the two.
 */
class OutboundUrlGuard
{
    /** @return string|null the reason this URL is unsafe, or null when it is fine to call. */
    public static function reasonUnsafe(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return 'This is not a valid absolute URL.';
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return 'Only http and https endpoints are allowed.';
        }

        $host = $parts['host'];
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);

        if ($ips === []) {
            return "'{$host}' could not be resolved.";
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return "'{$host}' resolves to {$ip}, a private, loopback, or reserved address, "
                    . 'and cannot be used as an agent endpoint.';
            }
        }

        return null;
    }

    public static function isSafe(string $url): bool
    {
        return self::reasonUnsafe($url) === null;
    }

    /** @return array<int, string> */
    private static function resolve(string $host): array
    {
        $ips = [];

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $ipv4 = @gethostbyname($host);

            if ($ipv4 !== $host) {
                $ips[] = $ipv4;
            }
        }

        return $ips;
    }
}
