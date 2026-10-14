<?php

namespace App\Domain\Signals\Scholar;

use App\Domain\Signals\Support\HtmlText;
use App\Domain\Signals\Support\RobotsPolicy;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Domain\Signals\Support\SourceDates;
use Illuminate\Support\Facades\Cache;

/**
 * Reads a target school's OWN website, a few pages at a time, so the evidence comes from the school itself.
 *
 *  - Only a website that was VERIFIED (attributes.website_verification, written by `signals:verify-websites --record`) is used. A guessed or
 *    unverified domain is never fetched here.
 *  - Only the school's own host: no external links, no login/admin/cart/file URLs. The home page plus at most `max_pages - 1` internal pages
 *    chosen because their address or link text says news, announcement, notice, careers, tender, project, technology or expansion. It never
 *    crawls the site.
 *  - Every request goes through SafeUrlFetcher (public addresses only, pinned IP, redirects re-checked, size and time caps) and robots.txt
 *    is honoured per page. A page fetched recently is not fetched again (`recheck_hours`).
 *  - A page's date is whatever the page says (SourceDates); a page with no date is undated. The fetch time is kept separately.
 *  - If the site cannot be read, that is recorded as a limitation in the report. Nothing is invented.
 *
 * The result is a list of sources shaped like the researcher's search sources (plus `official => true`) and a per-page report.
 */
final class OfficialSiteDiscovery
{
    private const WANT = '/(news|announce|event|notice|circular|career|vacanc|recruit|join-us|work-with|tender|procure|quotation|rfp|eoi|initiative|technology|digital|smart|erp|lms|project|expansion|new-campus|campus|press|media|update|latest|whats-new|blog|achievement|admission-notice)/i';
    private const SKIP = '/(login|log-in|signin|sign-in|logout|register|wp-admin|wp-login|admin|cart|checkout|account|password|privacy|terms|cookie|sitemap|feed|mailto:|tel:|javascript:|whatsapp|\.(pdf|jpe?g|png|gif|webp|svg|zip|docx?|xlsx?|pptx?|mp4|mp3)(\?|$))/i';

    public function __construct(private readonly SafeUrlFetcher $fetcher)
    {
    }

    /**
     * The verified website recorded for a target account, or null. Never a guess.
     *
     * @param  array<string, mixed>  $account  a target account row (attributes may be a JSON string or an array)
     * @return array{url: string, scope: string, tls_verified: bool}|null
     */
    public static function verifiedSite(array $account): ?array
    {
        $attr = $account['attributes'] ?? null;
        $attr = is_string($attr) ? (json_decode($attr, true) ?: []) : (array) $attr;
        $v = $attr['website_verification'] ?? null;
        if (! is_array($v) || ! in_array($v['verdict'] ?? null, ['verified', 'verified_tls_warning', 'verified_group', 'verified_group_tls_warning'], true) || empty($v['final_url'])) {
            return null;
        }

        return ['url' => (string) $v['final_url'], 'scope' => (string) ($v['scope'] ?? 'group'), 'tls_verified' => in_array($v['verdict'], ['verified', 'verified_group'], true)];
    }

    /**
     * @param  array<string, mixed>  $account
     * @return array{sources: list<array<string, mixed>>, report: array<string, mixed>}
     */
    public function discover(int $tenantId, array $account, \Illuminate\Support\Carbon $now): array
    {
        $report = ['account' => $account['name'] ?? null, 'crm_ref' => $account['crm_ref'] ?? null, 'site' => null, 'scope' => null,
            'limitation' => null, 'pages' => [], 'fetched' => 0, 'failed' => 0];
        $site = self::verifiedSite($account);
        if ($site === null) {
            $report['limitation'] = 'No verified official website is recorded for this school, so its site was not read.';

            return ['sources' => [], 'report' => $report];
        }
        $report['site'] = $site['url'];
        $report['scope'] = $site['scope'];

        $max = max(1, (int) config('signals.official_site.max_pages', 6));
        $hours = (int) config('signals.official_site.recheck_hours', 12);
        $verify = $site['tls_verified'];
        $robots = new RobotsPolicy($this->fetcher);
        $host = $this->host($site['url']);
        $seen = [];
        $sources = [];

        $home = $this->get($site['url'], $verify, $robots, $report, $seen, $hours, $tenantId);
        if ($home === null) {
            $report['limitation'] = $report['limitation'] ?? 'The official site could not be read now (see the page outcomes).';

            return ['sources' => $sources, 'report' => $report];
        }
        // The sitemap (one small request): extra internal pages worth reading, and each page's LAST-MODIFIED date. A sitemap lastmod is when a page
        // last changed, never when it was published: it is stored as a modification date and nothing more.
        $map = $this->sitemap($site['url'], $verify, $robots, $host, $report, $hours, $tenantId);
        $sources[] = $this->source($home, $now, $site, $map[$this->canonical($home['url'])] ?? null);

        $candidates = $this->links($home['body'], $home['url'], $host);
        foreach ($this->sitemapLinks($map) as $u) {
            if (! in_array($u, $candidates, true)) {
                $candidates[] = $u;
            }
        }
        foreach (array_slice($candidates, 0, $max - 1) as $url) {
            $page = $this->get($url, $verify, $robots, $report, $seen, $hours, $tenantId);
            if ($page !== null) {
                $sources[] = $this->source($page, $now, $site, $map[$this->canonical($page['url'])] ?? null);
            }
            usleep(max(0, (int) config('signals.official_site.delay_ms', 300)) * 1000);
        }
        $report['fetched'] = count($sources);

        return ['sources' => $sources, 'report' => $report];
    }

    /** @return array{url: string, body: string, last_modified: ?string}|null */
    private function get(string $url, bool $verify, RobotsPolicy $robots, array &$report, array &$seen, int $cacheHours, int $tenantId): ?array
    {
        $key = $this->canonical($url);
        if (isset($seen[$key])) {
            $report['pages'][] = ['url' => $url, 'outcome' => 'duplicate_in_run'];

            return null;
        }
        $seen[$key] = true;

        if ($cacheHours > 0 && ! Cache::add('signals:official:' . $tenantId . ':' . md5($key), 1, now()->addHours($cacheHours))) {
            $report['pages'][] = ['url' => $url, 'outcome' => 'fetched_recently'];

            return null;
        }
        if (! $robots->allows($url, $verify)) {
            $report['pages'][] = ['url' => $url, 'outcome' => 'blocked_by_robots_txt'];

            return null;
        }
        try {
            $page = $this->fetcher->fetch($url, 600000, (int) config('signals.official_site.timeout', 8), $verify);
        } catch (\Throwable $e) {
            $report['failed']++;
            $report['pages'][] = ['url' => $url, 'outcome' => 'failed', 'reason' => mb_substr($e->getMessage(), 0, 100)];

            return null;
        }
        $report['pages'][] = ['url' => $page['url'], 'outcome' => 'fetched', 'status' => $page['status']];

        return ['url' => $page['url'], 'body' => $page['body'], 'last_modified' => $page['last_modified'] ?? null];
    }

    /** @return array<string, mixed> */
    private function source(array $page, \Illuminate\Support\Carbon $now, array $site, ?string $sitemapLastmod = null): array
    {
        $html = HtmlText::extract($page['body']);
        $tz = (string) config('signals.timezone', 'Asia/Kolkata');
        $date = SourceDates::extract($page['body'], $html['text'], $now, $tz, false);
        // The last-modified date (never the publication date): from the page, else the sitemap.
        $mod = SourceDates::modified($page['body'], $html['text'], $now, $tz, $page['last_modified'] ?? null);
        if ($mod['date'] === null && $sitemapLastmod !== null) {
            $mod = ['date' => $sitemapLastmod, 'provenance' => 'sitemap_lastmod', 'confidence' => 'medium'];
        }

        return [
            'id' => null, 'url' => $page['url'], 'title' => $html['title'] ?: $this->host($page['url']),
            'published_at' => $date['date'], 'date_provenance' => $date['provenance'], 'date_confidence' => $date['confidence'],
            'modified_at' => $mod['date'], 'modified_provenance' => $mod['provenance'],
            'text' => mb_substr($html['text'], 0, 6000), 'page_fetched' => true, 'retrieved_at' => $now->toIso8601String(),
            'official' => true, 'official_scope' => $site['scope'], 'tls_verified' => $site['tls_verified'],
        ];
    }

    /**
     * The site's sitemap as canonical URL => lastmod date (Y-m-d, or null). One request for /sitemap.xml, plus at most two child sitemaps of a
     * sitemap index; same host only; robots.txt honoured; size-capped; XML read with network access disabled.
     * A missing or unreadable sitemap is simply no extra pages and no lastmod: it is noted in the report, never an error.
     *
     * @return array<string, ?string>
     */
    private function sitemap(string $siteUrl, bool $verify, RobotsPolicy $robots, string $host, array &$report, int $cacheHours = 0, int $tenantId = 0): array
    {
        $base = (string) parse_url($siteUrl, PHP_URL_SCHEME) . '://' . (string) parse_url($siteUrl, PHP_URL_HOST) . '/';
        $map = [];
        $this->sitemapUrls = [];
        $queue = [$base . 'sitemap.xml'];
        for ($fetched = 0; $queue !== [] && $fetched < 3;) {
            $url = array_shift($queue);
            // Like a page: a sitemap read within the recheck window is not read again.
            if ($cacheHours > 0 && ! Cache::add('signals:official:' . $tenantId . ':' . md5($this->canonical($url)), 1, now()->addHours($cacheHours))) {
                $report['pages'][] = ['url' => $url, 'outcome' => 'sitemap_fetched_recently'];

                continue;
            }
            if (! $robots->allows($url, $verify)) {
                $report['pages'][] = ['url' => $url, 'outcome' => 'sitemap_blocked_by_robots_txt'];

                continue;
            }
            try {
                $page = $this->fetcher->fetch($url, 600000, (int) config('signals.official_site.timeout', 8), $verify);
            } catch (\Throwable) {
                $report['pages'][] = ['url' => $url, 'outcome' => 'sitemap_unavailable'];
                $fetched++;

                continue;
            }
            $fetched++;
            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($page['body'], \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if ($xml === false) {
                $report['pages'][] = ['url' => $url, 'outcome' => 'sitemap_unreadable'];

                continue;
            }
            $report['pages'][] = ['url' => $page['url'], 'outcome' => 'sitemap_read'];
            if ($xml->getName() === 'sitemapindex') {
                foreach ($xml->sitemap as $child) {
                    $loc = trim((string) $child->loc);
                    if ($loc !== '' && $this->host($loc) === $host && count($queue) < 2) {
                        $queue[] = $loc;
                    }
                }

                continue;
            }
            foreach ($xml->url as $u) {
                $loc = trim((string) $u->loc);
                if ($loc === '' || $this->host($loc) !== $host || count($map) >= 3000) {
                    continue;
                }
                $d = null;
                $raw = trim((string) $u->lastmod);
                if ($raw !== '') {
                    try {
                        $c = \Illuminate\Support\Carbon::parse($raw);
                        $d = ($c->isFuture() || $c->year < ((int) now()->format('Y') - 15)) ? null : $c->toDateString();
                    } catch (\Throwable) {
                        $d = null;
                    }
                }
                $map[$this->canonical($loc)] = $d;
                $this->sitemapUrls[$this->canonical($loc)] = $loc;
            }
        }

        return $map;
    }

    /** @var array<string, string> canonical => original URL, for the sitemap just read */
    private array $sitemapUrls = [];

    /**
     * Sitemap pages worth reading, best first, by the same address rules as home-page links (never login/files/external).
     *
     * @param  array<string, ?string>  $map
     * @return list<string>
     */
    private function sitemapLinks(array $map): array
    {
        $scored = [];
        foreach (array_keys($map) as $key) {
            $url = $this->sitemapUrls[$key] ?? null;
            $path = $url ? (string) parse_url($url, PHP_URL_PATH) : '';
            if ($url === null || $path === '' || $path === '/' || preg_match(self::SKIP, $url) === 1) {
                continue;
            }
            $score = (preg_match_all(self::WANT, $path) ?: 0) * 2;
            if ($score > 0) {
                $scored[$url] = $score;
            }
        }
        arsort($scored);

        return array_keys($scored);
    }

    /**
     * Internal links worth reading, best first: same host, not a login/file/etc., scored by what their address and link text say.
     *
     * @return list<string>
     */
    public function links(string $html, string $base, string $host): array
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . mb_substr($html, 0, 400000), LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $scored = [];
        $urls = [];
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || preg_match(self::SKIP, $href) === 1) {
                continue;
            }
            $abs = $this->absolute($base, $href);
            if ($abs === null || $this->host($abs) !== $host) {
                continue;
            }
            $path = (string) parse_url($abs, PHP_URL_PATH);
            if ($path === '' || $path === '/' || preg_match(self::SKIP, $path) === 1) {
                continue;
            }
            $label = trim(preg_replace('/\s+/', ' ', $a->textContent) ?? '');
            $score = (preg_match_all(self::WANT, $path) ?: 0) * 2 + (preg_match_all(self::WANT, $label) ?: 0);
            if ($score > 0) {
                $key = $this->canonical($abs);
                $scored[$key] = max($scored[$key] ?? 0, $score);
                $urls[$key] ??= $this->clean($abs);   // the first form seen, without tracking parameters
            }
        }
        arsort($scored);

        return array_values(array_map(fn ($k) => $urls[$k], array_keys($scored)));
    }

    private function absolute(string $base, string $href): ?string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $p = parse_url($base);
        if (! isset($p['scheme'], $p['host'])) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            return $p['scheme'] . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $p['scheme'] . '://' . $p['host'] . $href;
        }
        $dir = rtrim(substr((string) ($p['path'] ?? '/'), 0, (int) strrpos((string) ($p['path'] ?? '/'), '/') + 1), '/');

        return $p['scheme'] . '://' . $p['host'] . $dir . '/' . $href;
    }

    private function host(string $url): string
    {
        return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST))) ?? '';
    }

    /** The address with the fragment and tracking parameters removed (the page is the same page). */
    private function clean(string $url): string
    {
        $p = parse_url($url);
        $q = [];
        parse_str((string) ($p['query'] ?? ''), $q);
        foreach (array_keys($q) as $k) {
            if (preg_match('/^(utm_|fbclid|gclid|ref$|source$)/i', (string) $k)) {
                unset($q[$k]);
            }
        }

        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '') . ($p['path'] ?? '') . ($q ? '?' . http_build_query($q) : '');
    }

    /** Fragment and tracking parameters do not make a different page. */
    private function canonical(string $url): string
    {
        $p = parse_url($url);
        $q = [];
        parse_str((string) ($p['query'] ?? ''), $q);
        foreach (array_keys($q) as $k) {
            if (preg_match('/^(utm_|fbclid|gclid|ref$|source$)/i', (string) $k)) {
                unset($q[$k]);
            }
        }
        ksort($q);

        return $this->host($url) . rtrim((string) ($p['path'] ?? '/'), '/') . ($q ? '?' . http_build_query($q) : '');
    }
}
