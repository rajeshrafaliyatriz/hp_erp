<?php

namespace App\Domain\Signals\Sources;

/**
 * The education source inventory (config/signals_sources.php) as a list of source records.
 *
 * Listing a source never makes it operational: a row is `pending_verification` (it has an address) or `needs_url` (it is a category with no address
 * yet) until config `verified` holds a recorded verification result for it. Nothing here fetches anything. Duplicate addresses are merged and counted
 * once, so the number of candidates is never padded.
 */
final class SourceRegistry
{
    public const PENDING = 'pending_verification';
    public const NEEDS_URL = 'needs_url';
    public const VERIFIED = 'verified_operational';
    public const VERIFIED_BLOCKED = 'verified_not_machine_readable';

    /** @param array<string, mixed>|null $config the signals_sources config (defaults to config('signals_sources')) */
    public function __construct(private ?array $config = null)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $config = $this->config ?? (array) config('signals_sources', []);
        $verified = (array) ($config['verified'] ?? []);
        $seen = [];
        $out = [];
        foreach ((array) ($config['candidates'] ?? []) as $row) {
            [$name, $url, $region, $category, $official, $products, $signals, $method] = array_pad((array) $row, 8, null);
            $key = $url !== null ? $this->canonical((string) $url) : 'name:' . mb_strtolower(trim((string) $name));
            if (isset($seen[$key])) {
                continue;   // the same address (or the same unnamed category) is one source
            }
            $seen[$key] = true;
            $id = 'src-' . substr(sha1($key), 0, 10);
            $proof = $verified[$id] ?? null;

            $out[] = [
                'id' => $id,
                'name' => (string) $name,
                'url' => $url !== null ? (string) $url : null,
                'region' => (string) $region,
                'category' => (string) $category,
                'classification' => $official ? 'official' : 'third_party',
                'products' => array_values(array_filter(array_map('trim', explode(',', (string) $products)))),
                'signal_types' => array_values(array_filter(array_map('trim', explode(',', (string) $signals)))),
                'retrieval_method' => (string) $method,
                'status' => $this->statusOf($url, $proof),
                'last_verified_at' => $proof['verified_at'] ?? null,
                'last_http_status' => $proof['http_status'] ?? null,
            ];
        }

        return $out;
    }

    /** @param array<string, mixed>|null $proof */
    private function statusOf(?string $url, ?array $proof): string
    {
        if ($url === null) {
            return self::NEEDS_URL;
        }
        if ($proof === null) {
            return self::PENDING;
        }

        return ($proof['machine_readable'] ?? false) ? self::VERIFIED : self::VERIFIED_BLOCKED;
    }

    /** Lower-case host without "www." plus the path without a trailing slash: two spellings of one address match. */
    public function canonical(string $url): string
    {
        $p = parse_url(trim($url));
        $host = preg_replace('/^www\./', '', mb_strtolower((string) ($p['host'] ?? $url)));

        return $host . rtrim((string) ($p['path'] ?? ''), '/');
    }

    /** @return list<array<string, mixed>> only sources that may be fetched: verified AND readable. Candidates are never returned here. */
    public function operational(): array
    {
        return array_values(array_filter($this->all(), fn ($s) => $s['status'] === self::VERIFIED));
    }

    /** @return list<array<string, mixed>> */
    public function forProduct(string $product): array
    {
        return array_values(array_filter($this->all(), fn ($s) => in_array($product, $s['products'], true)));
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $all = $this->all();
        $count = fn (string $status) => count(array_filter($all, fn ($s) => $s['status'] === $status));

        return [
            'candidates' => count($all),
            'with_address' => count(array_filter($all, fn ($s) => $s['url'] !== null)),
            'needs_url' => $count(self::NEEDS_URL),
            'pending_verification' => $count(self::PENDING),
            'verified_operational' => $count(self::VERIFIED),
            'verified_not_machine_readable' => $count(self::VERIFIED_BLOCKED),
        ];
    }
}
