<?php

namespace App\Domain\Signals\Support;

/** Reduces an HTML page to its title and readable text. Page content is untrusted. */
class HtmlText
{
    /** @return array{title: ?string, text: string} */
    public static function extract(string $html): array
    {
        if (trim($html) === '') {
            return ['title' => null, 'text' => ''];
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $title = null;
        $titleNode = $dom->getElementsByTagName('title')->item(0);
        if ($titleNode) {
            $title = trim(preg_replace('/\s+/', ' ', $titleNode->textContent) ?? '') ?: null;
        }

        foreach (['script', 'style', 'noscript', 'svg', 'nav', 'footer', 'header', 'form', 'iframe'] as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                $node?->parentNode?->removeChild($node);
            }
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        $text = $body ? $body->textContent : $dom->textContent;
        // Keep paragraph-ish breaks, collapse the rest.
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text) ?? $text;
        $text = trim(preg_replace("/\s*\n\s*/", "\n", $text) ?? $text);

        return ['title' => $title, 'text' => $text];
    }
}
