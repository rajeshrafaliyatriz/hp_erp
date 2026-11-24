<?php

namespace App\Domain\Signals\Market;

/**
 * Builds the traceable evidence list kept with a signal: for each source, where it came from, what was read, and what it supports.
 * Pure. Annotation only (stored in the assessment JSON). Never a generic summary: every item carries its URL and, when the page was read,
 * the passage taken from it.
 */
final class EvidenceItems
{
    public const MAX_ITEMS = 6;
    public const PASSAGE_CHARS = 300;

    /**
     * @param  list<array<string, mixed>>  $sources  each: url, title, published_at, retrieved_at, page_fetched, text, fetch_status, fetch_failure, reliability
     * @param  string  $claim  the claim this signal makes (what the sources are cited for)
     * @param  string  $maturity  E0..E3 for the signal's main source
     * @return list<array<string, mixed>>
     */
    public static function build(array $sources, string $claim, string $maturity, ?string $mainUrl = null): array
    {
        $sources = array_slice(array_values($sources), 0, self::MAX_ITEMS);
        $groups = SourceIndependence::groups(array_map(fn ($s) => ['url' => (string) ($s['url'] ?? ''), 'title' => $s['title'] ?? null, 'text' => $s['text'] ?? null], $sources));
        $seen = [];
        $out = [];
        foreach ($sources as $i => $s) {
            $url = (string) ($s['url'] ?? '');
            $read = (bool) ($s['page_fetched'] ?? false);
            $g = $groups[$i];
            $independent = ! isset($seen[$g]);
            $seen[$g] = true;
            $isMain = $mainUrl !== null && $url === $mainUrl;
            $out[] = [
                'url' => $url,
                'source_type' => SourceIndependence::sourceType($url),
                'domain' => SourceIndependence::host($url),
                'published_at' => $s['published_at'] ?? null,
                'fetch_status' => (string) ($s['fetch_status'] ?? ($read ? 'ok' : 'not_attempted')),
                'fetch_failure' => $s['fetch_failure'] ?? null,
                'fetched_at' => $read ? ($s['retrieved_at'] ?? null) : null,
                // The passage is only recorded when the page itself was read: a snippet is a search result, not the source.
                'passage' => $read ? mb_substr(trim((string) ($s['text'] ?? '')), 0, self::PASSAGE_CHARS) : null,
                'snippet' => $read ? null : mb_substr(trim((string) ($s['text'] ?? '')), 0, self::PASSAGE_CHARS),
                'claim_supported' => $isMain ? mb_substr($claim, 0, 300) : null,
                'maturity' => $isMain ? $maturity : ($read ? EvidenceMaturity::E1 : EvidenceMaturity::E0),
                'reliability' => $s['reliability'] ?? null,
                'independent' => $independent,
            ];
        }

        return $out;
    }
}
