<?php

namespace App\Domain\Signals\Support;

use Illuminate\Support\Carbon;

/**
 * When was this page PUBLISHED? Read from what the page itself says, never from when we fetched it. Pure.
 *
 * Sources, strongest first (the provenance is stored with the date):
 *   structured_data   JSON-LD `datePublished` / `datePosted` (job posts) / `dateCreated`
 *   meta_tag          `article:published_time`, `og:published_time`, `datePublished`, `date`, `DC.date` meta tags, `itemprop` dates
 *   time_element      the first <time datetime="..."> in the page
 *   visible_absolute  visible text such as "Posted on 12 Oct 2026", "Published: 2026-10-12"
 *   visible_relative  visible text such as "Posted 3 days ago", "2 weeks ago" (resolved from the retrieval time)
 *
 * A MODIFICATION date (dateModified, article:modified_time, "Last updated", Last-Modified, a sitemap lastmod) is a different fact: when the page
 * was last changed, not when it was first published. It is returned by modified(), never by extract(), and is never used as a publication
 * date. It can only say that the content is AT LEAST that old (a page cannot have been written after its last change).
 *
 * Rules that keep it honest:
 *  - a date in the future, or older than 15 years, is rejected (a misparse);
 *  - an ambiguous numeric date like 03/04/2026 is rejected rather than guessed;
 *  - a relative date is only ever resolved from the retrieval time given, in an explicit timezone, and says so (confidence "medium");
 *    "just now" and "today" are the retrieval day; months and years are NOT turned into a day (the date stays unknown);
 *  - nothing found means NULL. The retrieval time is never returned as a publication date.
 *
 * Confidence: high (structured data, meta tags, time element), medium (a labelled visible date, or a relative date in hours/days/weeks).
 */
final class SourceDates
{
    private const MONTHS = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';

    /**
     * @param  string  $html  the page body (may be empty)
     * @param  string  $visibleText  readable text of the page or the search snippet
     * @param  string  $timezone  how "today" and "yesterday" are read: the timezone of the site's audience
     * @param  bool  $jobPost  the page is a job post, where a bare "3 days ago" is the posting age
     * @return array{date: ?string, provenance: ?string, confidence: ?string}
     */
    public static function extract(string $html, string $visibleText, Carbon $retrievedAt, string $timezone = 'Asia/Kolkata', bool $jobPost = false): array
    {
        $now = $retrievedAt->copy()->setTimezone($timezone);

        foreach (self::structured($html) as [$value, $how]) {
            if (($d = self::valid(self::parseAbsolute($value, $timezone), $now)) !== null) {
                return self::result($d, $how, 'high');
            }
        }

        $text = trim(preg_replace('/\s+/u', ' ', $visibleText) ?? $visibleText);
        if ($text !== '') {
            // Only a labelled absolute date ("Posted on ..."): a bare date in running text is usually something else.
            if (preg_match('/\b(?:posted|published|date posted|publication date|date)\s*(?:on|:|-)?\s*(\d{4}-\d{2}-\d{2}|\d{1,2}(?:st|nd|rd|th)?\s+(?:' . self::MONTHS . ')[a-z]*\.?,?\s+\d{4}|(?:' . self::MONTHS . ')[a-z]*\.?\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4})/iu', $text, $m)
                && ($d = self::valid(self::parseAbsolute($m[1], $timezone), $now)) !== null) {
                return self::result($d, 'visible_absolute', 'medium');
            }
            if (($rel = self::relative($text, $now, $jobPost)) !== null) {
                return $rel;
            }
        }

        return ['date' => null, 'provenance' => null, 'confidence' => null];
    }

    /**
     * When was the page LAST CHANGED, as the page (or its server) says? Not a publication date: see the class comment.
     *
     * Sources: JSON-LD `dateModified` of the page's own entity (high), `article:modified_time` / `og:updated_time` / `dateModified` meta (high),
     * a labelled visible "Last updated: ..." (medium), the HTTP Last-Modified header (low, and ignored when it is the retrieval day, which is
     * what dynamic pages report). A sitemap's `lastmod` is attached by the caller (medium).
     *
     * @return array{date: ?string, provenance: ?string, confidence: ?string}
     */
    public static function modified(string $html, string $visibleText, Carbon $retrievedAt, string $timezone = 'Asia/Kolkata', ?string $lastModifiedHeader = null): array
    {
        $now = $retrievedAt->copy()->setTimezone($timezone);
        $none = ['date' => null, 'provenance' => null, 'confidence' => null];

        if (trim($html) !== '') {
            $ld = [];
            if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $blocks)) {
                foreach ($blocks[1] as $json) {
                    $data = json_decode(html_entity_decode(trim($json)), true);
                    if (is_array($data)) {
                        $nodes = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : (array_is_list($data) ? $data : [$data]);
                        foreach ($nodes as $n) {
                            if (is_array($n) && isset($n['dateModified']) && is_string($n['dateModified'])) {
                                $ld[] = $n['dateModified'];
                            }
                        }
                    }
                }
            }
            if (count(array_unique(array_map(fn ($v) => substr(trim($v), 0, 10), $ld))) === 1
                && ($d = self::valid(self::parseAbsolute($ld[0], $timezone), $now)) !== null) {
                return self::result($d, 'structured_data_modified', 'high');
            }

            $previous = libxml_use_internal_errors(true);
            $dom = new \DOMDocument();
            $dom->loadHTML('<?xml encoding="UTF-8">' . mb_substr($html, 0, 400000), LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            foreach ($dom->getElementsByTagName('meta') as $meta) {
                $key = strtolower((string) ($meta->getAttribute('property') ?: $meta->getAttribute('name') ?: $meta->getAttribute('itemprop')));
                if (in_array($key, ['article:modified_time', 'og:updated_time', 'og:modified_time', 'datemodified', 'last-modified', 'revised'], true)
                    && ($d = self::valid(self::parseAbsolute(trim($meta->getAttribute('content')), $timezone), $now)) !== null) {
                    return self::result($d, 'meta_modified', 'high');
                }
            }
        }

        $text = trim(preg_replace('/\s+/u', ' ', $visibleText) ?? $visibleText);
        if ($text !== '' && preg_match('/\b(?:last\s+updated|last\s+modified|last\s+revised|updated|modified)\s*(?:on|:|-)?\s*(\d{4}-\d{2}-\d{2}|\d{1,2}(?:st|nd|rd|th)?\s+(?:' . self::MONTHS . ')[a-z]*\.?,?\s+\d{4}|(?:' . self::MONTHS . ')[a-z]*\.?\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4})/iu', $text, $m)
            && ($d = self::valid(self::parseAbsolute($m[1], $timezone), $now)) !== null) {
            return self::result($d, 'visible_modified', 'medium');
        }

        if ($lastModifiedHeader !== null && ($d = self::valid(self::parseAbsolute($lastModifiedHeader, $timezone), $now)) !== null) {
            // A dynamic page reports "now": that is the time of the request, not of any change.
            if ($d->copy()->timezone($timezone)->toDateString() !== $now->toDateString()) {
                return self::result($d, 'http_last_modified', 'low');
            }
        }

        return $none;
    }

    /** @return list<array{0: string, 1: string}> candidate date strings with how they were found, strongest first */
    private static function structured(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $out = [];

        // All JSON-LD dates on the page, together. A page that lists several posts (a company page with ten posts) has no single
        // publication date: picking one would be arbitrary, so structured data is used only when the page's dates fall on ONE day.
        $ld = [];
        if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $blocks)) {
            foreach ($blocks[1] as $json) {
                $data = json_decode(html_entity_decode(trim($json)), true);
                if (is_array($data)) {
                    foreach (self::walk($data) as $v) {
                        $ld[] = $v;
                    }
                }
            }
        }
        if (count(array_unique(array_map(fn ($v) => substr(trim($v), 0, 10), $ld))) === 1) {
            $out[] = [$ld[0], 'structured_data'];
        }

        // Meta tags and itemprop.
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . mb_substr($html, 0, 400000), LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $metaKeys = ['article:published_time', 'og:published_time', 'article:published', 'datepublished', 'datecreated', 'date', 'dc.date', 'dc.date.issued', 'publishdate', 'pubdate', 'parsely-pub-date', 'sailthru.date'];
        foreach ($dom->getElementsByTagName('meta') as $meta) {
            $key = strtolower((string) ($meta->getAttribute('property') ?: $meta->getAttribute('name') ?: $meta->getAttribute('itemprop')));
            if (in_array($key, $metaKeys, true) && trim($meta->getAttribute('content')) !== '') {
                $out[] = [trim($meta->getAttribute('content')), 'meta_tag'];
            }
        }
        foreach ($dom->getElementsByTagName('*') as $el) {
            if (in_array(strtolower($el->getAttribute('itemprop')), ['datepublished', 'dateposted', 'datecreated'], true)) {
                $v = $el->getAttribute('content') ?: $el->getAttribute('datetime') ?: trim($el->textContent);
                if ($v !== '') {
                    $out[] = [$v, 'meta_tag'];
                }
            }
        }
        $time = $dom->getElementsByTagName('time')->item(0);
        if ($time && trim($time->getAttribute('datetime')) !== '') {
            $out[] = [trim($time->getAttribute('datetime')), 'time_element'];
        }

        return $out;
    }

    /**
     * The dates the page's OWN entities state about themselves: the direct `datePublished` / `datePosted` / `dateCreated` of each top-level
     * node (or each node of an @graph). Dates nested deeper (comments, related posts, an author's other work) belong to other things and
     * are ignored. If the top-level nodes are several posts with different dates (a company page listing its posts), the caller sees
     * several days and treats the page as having no single publication date.
     *
     * @return list<string>
     */
    private static function walk(array $node): array
    {
        $nodes = isset($node['@graph']) && is_array($node['@graph']) ? $node['@graph'] : (array_is_list($node) ? $node : [$node]);
        $found = [];
        foreach ($nodes as $n) {
            if (! is_array($n)) {
                continue;
            }
            foreach (['datePublished', 'datePosted', 'dateCreated'] as $key) {
                if (isset($n[$key]) && is_string($n[$key]) && trim($n[$key]) !== '') {
                    $found[] = $n[$key];

                    break;   // one date per entity: its publication date wins over its creation date
                }
            }
        }

        return $found;
    }

    private static function parseAbsolute(string $value, string $timezone): ?Carbon
    {
        $value = trim($value);
        // A numeric day/month date is ambiguous (03/04/2026): refuse it rather than guess.
        if ($value === '' || preg_match('#^\d{1,2}[/.-]\d{1,2}[/.-]\d{2,4}$#', $value) === 1) {
            return null;
        }
        try {
            return Carbon::parse($value, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function valid(?Carbon $d, Carbon $now): ?Carbon
    {
        if ($d === null) {
            return null;
        }
        // Not in the future (a day of slack for timezones), not absurdly old (a misparse such as a copyright year).
        if ($d->copy()->startOfDay()->gt($now->copy()->addDay()->startOfDay()) || $d->year < ((int) $now->format('Y') - 15)) {
            return null;
        }

        return $d;
    }

    /** @return array{date: string, provenance: string, confidence: string}|null */
    private static function relative(string $text, Carbon $now, bool $bareOk): ?array
    {
        // "Posted today/yesterday/just now" needs the cue word.
        if (preg_match('/(?:posted|published|listed|added)\s+(just now|today|yesterday)/i', $text, $m)) {
            return self::result(strtolower($m[1]) === 'yesterday' ? $now->copy()->subDay() : $now->copy(), 'visible_relative', 'medium');
        }
        // "Posted 3 days ago". A bare "3 days ago" is accepted only on a job-post page, where it IS the posting age; in an article it
        // could be anything ("said 2 days ago"), so it is ignored.
        $cue = $bareOk ? '' : '(?:posted|published|listed|added)\s+';
        if (preg_match('/' . $cue . '(\d{1,3})\s*(minute|hour|day|week|month)s?\s+ago/i', $text, $m)) {
            $n = (int) $m[1];
            $unit = strtolower($m[2]);
            // Months are too coarse to be a day: the date stays unknown.
            if ($n <= 0 || $unit === 'month') {
                return null;
            }
            $d = match ($unit) {
                'minute', 'hour' => $now->copy(),
                'day' => $now->copy()->subDays($n),
                'week' => $now->copy()->subWeeks($n),
            };

            return self::result($d, 'visible_relative', 'medium');
        }

        return null;
    }

    /** @return array{date: string, provenance: string, confidence: string} */
    private static function result(Carbon $d, string $provenance, string $confidence): array
    {
        return ['date' => $d->toDateString(), 'provenance' => $provenance, 'confidence' => $confidence];
    }
}
