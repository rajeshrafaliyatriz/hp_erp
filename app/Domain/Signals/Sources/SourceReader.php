<?php

namespace App\Domain\Signals\Sources;

use App\Domain\Signals\Scholar\SourceScreen;

/**
 * Reading the structure of a source's pages: its feeds, the items in a feed, and the links of a listing page. Pure: strings in, arrays out. It
 * fetches nothing, and it never follows a link that leaves the source's own host.
 */
final class SourceReader
{
    public const FEED_TYPES = ['application/rss+xml', 'application/atom+xml', 'application/xml', 'text/xml', 'application/rdf+xml'];

    /** Links that are never worth reading. */
    private const SKIP = '/(login|log-in|signin|sign-in|logout|register|wp-admin|wp-login|cart|checkout|password|privacy|terms|cookie|mailto:|tel:|javascript:|whatsapp|(facebook|twitter|instagram|linkedin|youtube)\.com|\.(jpe?g|png|gif|webp|svg|zip|docx?|xlsx?|pptx?|mp4|mp3|css|js)(\?|$))/i';
    /** Words in a link's address or text that mark an OFFICIAL notice worth a look even when its text has no school word. Never used for news pages. */
    private const WANT_OFFICIAL = '/(circular|tender|notice|notification|eoi|rfp|rfq|vacanc|recruit|advertis|affiliat|admission)/i';

    /** @return list<string> absolute feed addresses on the same host (or a subdomain of it), at most 3 */
    public static function feedLinks(string $html, string $base): array
    {
        $dom = self::dom($html);
        $host = self::host($base);
        $out = [];
        foreach ($dom->getElementsByTagName('link') as $l) {
            $type = strtolower(trim($l->getAttribute('type')));
            if (! str_contains(strtolower($l->getAttribute('rel')), 'alternate') || ! in_array($type, ['application/rss+xml', 'application/atom+xml'], true)) {
                continue;
            }
            $abs = self::absolute($base, trim($l->getAttribute('href')));
            if ($abs !== null && self::sameSite(self::host($abs), $host)) {
                $out[$abs] = true;
            }
        }

        return array_slice(array_keys($out), 0, 3);
    }

    /**
     * The entries of an RSS 2.0 or Atom feed, newest first (undated entries keep their order, after the dated ones).
     *
     * @return list<array{url: string, title: string, date: ?string, summary: string}>
     */
    public static function parseFeed(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $root = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $root instanceof \SimpleXMLElement) {
            return [];
        }

        $items = [];
        if (isset($root->channel->item)) {
            foreach ($root->channel->item as $i) {
                $items[] = ['url' => trim((string) $i->link), 'title' => (string) $i->title, 'date' => (string) $i->pubDate, 'summary' => (string) $i->description];
            }
        } elseif (isset($root->entry)) {
            foreach ($root->entry as $e) {
                $href = '';
                foreach ($e->link as $link) {
                    $rel = (string) ($link['rel'] ?? '');
                    if ($href === '' || $rel === 'alternate') {
                        $href = trim((string) ($link['href'] ?? ''));
                    }
                }
                $items[] = ['url' => $href, 'title' => (string) $e->title, 'date' => (string) ($e->published ?: $e->updated), 'summary' => (string) ($e->summary ?: $e->content)];
            }
        }

        $out = [];
        foreach ($items as $n => $i) {
            if (! preg_match('#^https?://#i', $i['url'])) {
                continue;
            }
            // A leading weekday name is dropped: feeds sometimes get it wrong, and strtotime would then move the date to the next such weekday.
            $ts = $i['date'] !== '' ? strtotime(preg_replace('/^[A-Za-z]{3,9},\s*/', '', $i['date']) ?? $i['date']) : false;
            $out[] = ['url' => $i['url'], 'title' => self::plain($i['title'], 200), 'date' => $ts ? gmdate('Y-m-d', $ts) : null,
                'summary' => self::plain($i['summary'], 300), 'order' => $n];
        }
        usort($out, fn ($a, $b) => [$b['date'] ?? '', $a['order']] <=> [$a['date'] ?? '', $b['order']]);

        return array_map(fn ($i) => array_diff_key($i, ['order' => 1]), $out);
    }

    /**
     * The links of a listing page that look like announcements, in page order (listings are usually newest first). Same site only.
     * A link must name something educational in its own text. On an official source (`$newsLike` false) a notice-like word in the address or text is
     * also enough (a numbered circular); on a news or aggregator page it is not, because "notice issued to organisers" is not school news.
     *
     * @return list<array{url: string, title: string}>
     */
    public static function listingLinks(string $html, string $base, int $max = 12, bool $newsLike = false): array
    {
        $dom = self::dom($html);
        $host = self::host($base);
        $basePath = rtrim((string) parse_url($base, PHP_URL_PATH), '/');
        $out = [];
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || preg_match(self::SKIP, $href) === 1) {
                continue;
            }
            $abs = self::absolute($base, $href);
            if ($abs === null || ! self::sameSite(self::host($abs), $host)) {
                continue;
            }
            $path = rtrim((string) parse_url($abs, PHP_URL_PATH), '/');
            if ($path === '' || $path === $basePath) {
                continue;
            }
            $label = trim(preg_replace('/\s+/u', ' ', $a->textContent) ?? '');
            $isPdf = str_ends_with(strtolower($path), '.pdf');
            $wanted = ! SourceScreen::offTopic($label, '') || (! $newsLike && preg_match(self::WANT_OFFICIAL, $path . ' ' . $label) === 1);
            if (mb_strlen($label) < ($isPdf ? 8 : 20) || ! $wanted) {
                continue;
            }
            $key = self::host($abs) . $path . (parse_url($abs, PHP_URL_QUERY) ? '?' . parse_url($abs, PHP_URL_QUERY) : '');
            $out[$key] ??= ['url' => $abs, 'title' => mb_substr($label, 0, 200)];
            if (count($out) >= $max) {
                break;
            }
        }

        return array_values($out);
    }

    public static function host(string $url): string
    {
        return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST))) ?? '';
    }

    /** Same host, or one is a subdomain of the other's registrable part (news.example.org vs example.org). */
    public static function sameSite(string $a, string $b): bool
    {
        return $a !== '' && $b !== '' && ($a === $b || str_ends_with($a, '.' . $b) || str_ends_with($b, '.' . $a));
    }

    public static function absolute(string $base, string $href): ?string
    {
        if ($href === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $b = parse_url($base);
        if (! isset($b['scheme'], $b['host'])) {
            return null;
        }
        $origin = $b['scheme'] . '://' . $b['host'];
        if (str_starts_with($href, '//')) {
            return $b['scheme'] . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
            return null;   // mailto:, javascript:, data: …
        }
        $dir = rtrim(dirname((string) ($b['path'] ?? '/') . 'x'), '/');

        return $origin . $dir . '/' . $href;
    }

    private static function dom(string $html): \DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . mb_substr($html, 0, 500000), LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    private static function plain(string $s, int $max): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''), 0, $max);
    }
}
