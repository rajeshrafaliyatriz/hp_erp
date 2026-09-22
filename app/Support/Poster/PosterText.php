<?php

namespace App\Support\Poster;

/**
 * Text handling for the hiring poster.
 *
 * ── WHY THIS IS CAREFUL ─────────────────────────────────────────────────────
 *
 * The poster downloads on one click with no preview and no edit step, so every
 * string here reaches social media unread. There is no human between this class
 * and the public, which rules out anything that rewrites meaning.
 *
 * The rules below either keep what the author wrote, mark plainly that
 * something was cut, or drop the line. Nothing is paraphrased or invented.
 */
class PosterText
{
    /**
     * Openers that cost characters and carry no information on a poster.
     *
     * Stripping these buys 15-25 characters per bullet, which is often the
     * difference between printing a requirement in full and truncating it.
     * Order matters: longest first, so "Proven ability to" wins over "Ability to".
     */
    private const FILLER = [
        'You will be responsible for ',
        'The candidate will be able to ',
        'The candidate will ',
        'Proven ability to ',
        'Demonstrated ability to ',
        'Responsible for ',
        'Ability to ',
        "You'll be responsible for ",
        "You'll ",
        'You will ',
    ];

    /**
     * Characters neither renderer can draw, and what to print instead.
     *
     * Verified by walking the cmap of DejaVuSans, DejaVuSans-Bold and
     * Geist-Regular. The rupee sign, en/em dash, ellipsis, middle dot, curly
     * quotes and the arrow are all present in DejaVu, so they are NOT
     * transliterated - the poster keeps the author's punctuation.
     *
     * Emoji is stripped rather than mapped: Satori resolves emoji through a
     * live CDN request per glyph with no timeout, so one emoji in a job
     * description would make the poster depend on a third party.
     */
    private const REPLACE = [
        "\u{00A0}" => ' ',   // non-breaking space
        "\u{2007}" => ' ',   // figure space
        "\u{202F}" => ' ',   // narrow no-break space
        "\u{FEFF}" => '',    // byte-order mark
    ];

    /** Turn a stored description into predictable plain text. */
    public static function normalise(?string $raw): string
    {
        if ($raw === null || trim($raw) === '') {
            return '';
        }

        $text = $raw;

        /*
         * A description pasted from a rich-text editor arrives as HTML. Mapping
         * the list and break tags to newlines FIRST is what turns it into a
         * bullet list rather than one unreadable paragraph.
         */
        $text = preg_replace('~<\s*li[^>]*>~i', "\n• ", $text);
        $text = preg_replace('~<\s*(br|/p|/div|/h[1-6]|/li|/tr)\s*/?\s*>~i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = strtr($text, self::REPLACE);
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Emoji: see REPLACE. Stripped before anything measures a length.
        $text = preg_replace('/\p{Extended_Pictographic}\x{FE0F}?/u', '', $text) ?? $text;

        $lines = array_map(static fn ($line) => trim($line), explode("\n", $text));

        return trim(implode("\n", $lines));
    }

    /** Collapse runs of whitespace to single spaces. */
    public static function flatten(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Shorten a bullet to `$max` characters WITHOUT changing what it says.
     *
     * ── THE RULE THAT MATTERS ───────────────────────────────────────────────
     *
     * The first version of this cut at the earliest clause break, which turned
     *
     *   "3+ years in implementation, project management, or school ERP deployment"
     *
     * into
     *
     *   "3+ years in implementation"
     *
     * That is not a shortened requirement, it is a different and narrower one,
     * and it looks deliberate because nothing signals the cut. A comma in a job
     * description is almost always a list separator, so stopping at one asserts
     * a complete list.
     *
     * A dash is different: it introduces an elaboration, so the text before it
     * is already a complete thought and can stand alone without a marker.
     * Everything else is cut on a word boundary and marked with an ellipsis so
     * the reader can see something was removed.
     *
     * Returns null when the result would be too short to be worth printing - a
     * four-bullet panel beats a five-bullet panel ending in a stub.
     */
    public static function tighten(string $text, int $max): ?string
    {
        $value = self::flatten($text);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) <= $max) {
            return $value;
        }

        // A trailing aside is expendable in full; truncating INTO one is not.
        $withoutAside = preg_replace('/\s*\([^()]*\)\s*$/u', '', $value) ?? $value;
        if (mb_strlen($withoutAside) >= 24) {
            $value = $withoutAside;
            if (mb_strlen($value) <= $max) {
                return $value;
            }
        }

        // A complete clause needs no ellipsis - nothing looks missing.
        foreach ([' — ', ' – ', ' - ', '. ', '; '] as $separator) {
            $cut = mb_strpos($value, $separator);
            if ($cut !== false && $cut >= (int) ($max * 0.6) && $cut <= $max) {
                return rtrim(mb_substr($value, 0, $cut), " .;:-");
            }
        }

        $slice = mb_substr($value, 0, $max - 1);
        $lastSpace = mb_strrpos($slice, ' ');
        $out = ($lastSpace !== false && $lastSpace > 24) ? mb_substr($slice, 0, $lastSpace) : $slice;

        // Never end inside an unclosed bracket: "(GenAI" reads as a typo.
        if (mb_substr_count($out, '(') > mb_substr_count($out, ')')) {
            $open = mb_strrpos($out, '(');
            if ($open !== false && $open > 24) {
                $out = mb_substr($out, 0, $open);
            }
        }

        $out = rtrim($out, " ,;:-(\u{2013}\u{2014}");

        // Too little survived to be a point. Say nothing rather than a stub.
        if (mb_strlen($out) < (int) ($max * 0.5) || mb_strlen($out) < 12) {
            return null;
        }

        return $out . "\u{2026}";
    }

    /** Drop a leading filler phrase, preserving the rest verbatim. */
    public static function deverbose(string $text): string
    {
        $value = self::flatten($text);

        foreach (self::FILLER as $filler) {
            if (mb_stripos($value, $filler) === 0) {
                $rest = mb_substr($value, mb_strlen($filler));
                if (mb_strlen($rest) >= 12) {
                    // Only the first letter is recased - an acronym like "ERP
                    // ownership" must not become "Erp ownership".
                    return mb_strtoupper(mb_substr($rest, 0, 1)) . mb_substr($rest, 1);
                }
            }
        }

        return $value;
    }

    /**
     * Split prose into sentences.
     *
     * Used only as a fallback: some authors write a heading and then a
     * paragraph instead of a list, and dropping those leaves a bare poster.
     */
    public static function sentences(string $paragraph): array
    {
        $parts = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9"\x{2018}\x{201C}(])/u', self::flatten($paragraph)) ?: [];

        return array_values(array_filter(array_map(
            static fn ($sentence) => rtrim(trim($sentence), '.'),
            $parts,
        ), static fn ($sentence) => mb_strlen($sentence) >= 25 && mb_strlen($sentence) <= 200));
    }
}
