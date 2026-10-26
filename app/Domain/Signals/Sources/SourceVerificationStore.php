<?php

namespace App\Domain\Signals\Sources;

/**
 * What a real verification fetch found out about each registry source, and which of a source's pages a run has already read. A small JSON file
 * (storage/app/signals/source_verification.json) so it needs no migration and works the same locally and on a server.
 *
 * Only facts a fetch established are written: when it was checked, the HTTP status, how the source can be read, and a failure category. Never page
 * text, never a URL's query string, never a header. A source is "verified" in the registry only because a record with machine_readable = true is here.
 *
 * Shape: {"sources": {"src-…": {verified_at, http_status, machine_readable, method, feed_url, listing_links, content_type, failure}},
 *         "seen": {"src-…": {"<sha1 of page URL>": "YYYY-MM-DD", …}}}
 */
final class SourceVerificationStore
{
    private const SEEN_CAP = 400;

    public function __construct(private ?string $path = null)
    {
    }

    public function path(): string
    {
        return $this->path ?? (function_exists('storage_path') ? storage_path('app/signals/source_verification.json') : sys_get_temp_dir() . '/source_verification.json');
    }

    /** @return array{sources: array<string, array<string, mixed>>, seen: array<string, array<string, string>>, reads: array<string, array<string, mixed>>} */
    private function load(): array
    {
        $raw = is_file($this->path()) ? (string) @file_get_contents($this->path()) : '';
        $data = $raw === '' ? [] : (json_decode($raw, true) ?: []);

        return ['sources' => (array) ($data['sources'] ?? []), 'seen' => (array) ($data['seen'] ?? []), 'reads' => (array) ($data['reads'] ?? [])];
    }

    /** @return array<string, array{last_attempt?: ?string, fail_streak?: int}> when each source was last tried for direct reading, and how many tries in a row read nothing */
    public function readStats(): array
    {
        return $this->load()['reads'];
    }

    /** Record one direct-reading attempt on a source: `$itemsRead` items read this time. Reading at least one item ends a failure streak. */
    public function touch(string $id, string $date, int $itemsRead): void
    {
        $data = $this->load();
        $streak = (int) ($data['reads'][$id]['fail_streak'] ?? 0);
        $data['reads'][$id] = ['last_attempt' => $date, 'fail_streak' => $itemsRead > 0 ? 0 : $streak + 1];
        $this->save($data);
    }

    private function save(array $data): void
    {
        $dir = dirname($this->path());
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $this->path() . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        rename($tmp, $this->path());
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->load()['sources'];
    }

    /** The map the registry reads: id => {verified_at, http_status, machine_readable}. @return array<string, array<string, mixed>> */
    public function verifiedMap(): array
    {
        $out = [];
        foreach ($this->all() as $id => $r) {
            $out[$id] = ['verified_at' => $r['verified_at'] ?? null, 'http_status' => $r['http_status'] ?? null, 'machine_readable' => (bool) ($r['machine_readable'] ?? false)];
        }

        return $out;
    }

    public function get(string $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    /** @param array<string, mixed> $record */
    public function record(string $id, array $record): void
    {
        $data = $this->load();
        $data['sources'][$id] = $record;
        $this->save($data);
    }

    public static function pageKey(string $url): string
    {
        $p = parse_url(strtolower(trim($url)));

        return sha1(preg_replace('/^www\./', '', (string) ($p['host'] ?? '')) . rtrim((string) ($p['path'] ?? ''), '/') . (isset($p['query']) ? '?' . $p['query'] : ''));
    }

    public function hasSeen(string $id, string $url): bool
    {
        return isset($this->load()['seen'][$id][self::pageKey($url)]);
    }

    /** @param list<string> $urls */
    public function markSeen(string $id, array $urls, string $date): void
    {
        if ($urls === []) {
            return;
        }
        $data = $this->load();
        foreach ($urls as $u) {
            $data['seen'][$id][self::pageKey($u)] = $date;
        }
        // Bounded: the oldest entries go first.
        if (count($data['seen'][$id]) > self::SEEN_CAP) {
            asort($data['seen'][$id]);
            $data['seen'][$id] = array_slice($data['seen'][$id], -self::SEEN_CAP, null, true);
        }
        $this->save($data);
    }
}
