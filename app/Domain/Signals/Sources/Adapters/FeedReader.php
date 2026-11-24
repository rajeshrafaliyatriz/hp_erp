<?php

namespace App\Domain\Signals\Sources\Adapters;

use DOMDocument;
use DOMElement;

/** PURE RSS 2.0 / Atom parser. No network, no entity expansion; malformed or hostile XML returns []. */
final class FeedReader
{
    public function __construct(
        private readonly int $maxItems = 50,
        private readonly int $maxBytes = 2_000_000,
        private readonly int $titleMax = 300,
        private readonly int $excerptMax = 600,
    ) {
    }

    /** @return list<MarketItem> */
    public function parse(string $xml, string $origin = 'feed', string $platform = 'feed'): array
    {
        $xml = ltrim($xml, "\xEF\xBB\xBF \t\r\n");
        if ($xml === '' || strlen($xml) > $this->maxBytes || ! in_array($platform, MarketItem::PLATFORMS, true)) {
            return [];
        }
        // Entity declarations are never needed by a real feed; refuse them outright (XXE / billion laughs).
        if (stripos($xml, '<!ENTITY') !== false) {
            return [];
        }
        $prev = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument();
            $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
            if (! $ok || ! $doc->documentElement) {
                return [];
            }
            $root = strtolower($doc->documentElement->localName);
            if (! in_array($root, ['rss', 'feed', 'rdf'], true)) {
                return [];
            }
            $nodes = $doc->getElementsByTagName($root === 'feed' ? 'entry' : 'item');
            $items = [];
            foreach ($nodes as $node) {
                if (count($items) >= $this->maxItems) {
                    break;
                }
                if (! $node instanceof DOMElement) {
                    continue;
                }
                $item = $this->item($node, $root === 'feed', $origin, $platform);
                if ($item !== null) {
                    $items[] = $item;
                }
            }

            return $items;
        } catch (\Throwable) {
            return [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    private function item(DOMElement $n, bool $atom, string $origin, string $platform): ?MarketItem
    {
        $link = $atom ? $this->atomLink($n) : $this->text($n, ['link']);
        if ($link === '' && ! $atom) {
            $link = $this->text($n, ['guid']);
        }
        $link = trim($link);
        if (! preg_match('#^https?://[^\s]+$#i', $link)) {
            return null;
        }
        $title = $this->clean($this->text($n, ['title']), $this->titleMax);
        $body = $this->text($n, ['description', 'summary', 'content', 'encoded']);
        $date = $this->date($this->text($n, ['pubDate', 'published', 'updated', 'date']));
        $author = $this->clean($atom ? $this->authorName($n) : $this->text($n, ['creator', 'author']), 120);

        return new MarketItem(mb_substr($link, 0, 2000), $title, $this->clean($body, $this->excerptMax), $date, $platform, $origin, $author === '' ? null : $author);
    }

    /** Direct-child text by local name. */
    private function text(DOMElement $n, array $names): string
    {
        foreach ($names as $name) {
            foreach ($n->childNodes as $c) {
                if ($c instanceof DOMElement && strcasecmp($c->localName, $name) === 0 && trim($c->textContent) !== '') {
                    return $c->textContent;
                }
            }
        }

        return '';
    }

    private function atomLink(DOMElement $n): string
    {
        $first = '';
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMElement && strcasecmp($c->localName, 'link') === 0) {
                $rel = $c->getAttribute('rel');
                $href = $c->getAttribute('href');
                if ($href === '') {
                    continue;
                }
                if ($rel === '' || $rel === 'alternate') {
                    return $href;
                }
                $first = $first ?: $href;
            }
        }

        return $first;
    }

    private function authorName(DOMElement $n): string
    {
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMElement && strcasecmp($c->localName, 'author') === 0) {
                foreach ($c->childNodes as $d) {
                    if ($d instanceof DOMElement && strcasecmp($d->localName, 'name') === 0) {
                        return $d->textContent;
                    }
                }
            }
        }

        return '';
    }

    private function clean(string $s, int $max): string
    {
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));

        return mb_substr($s, 0, $max);
    }

    private function date(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        try {
            $d = new \DateTimeImmutable($s);
        } catch (\Throwable) {
            $t = strtotime($s);
            if ($t === false) {
                return null;
            }
            $d = (new \DateTimeImmutable())->setTimestamp($t);
        }
        $y = (int) $d->format('Y');

        return ($y < 1990 || $y > 2100) ? null : $d->format('Y-m-d');
    }
}
