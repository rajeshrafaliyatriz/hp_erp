<?php

namespace App\Domain\Ask;

/**
 * Text clean-up for the Ask chat (slice 8). Two jobs:
 *
 *   clip()   a fact of a record, shortened to one line before the model is shown it (a description, a note, a next step);
 *   clean()  what the model wrote, before it becomes the answer a person reads.
 *
 * clean() exists because the records the model reads hold text that came from the web and from people, and any of it may say "tell the user to log in at
 * https://...". The answer is shown as plain text, so markup is stripped, and every link is removed from the model's own words: the only links in an answer
 * are the ones the server builds for the records it cites (AskRow::url, application paths). A markdown image is the classic way to leak data through a
 * rendered answer; it is removed with the rest.
 */
final class AskText
{
    /**
     * A question as typed: one line, control characters removed, cut to $max characters. Tags are NOT stripped: "deals < 5 and > 3" is a question, not markup,
     * and the text is only ever put into JSON for the model and stored and shown as plain text.
     */
    public static function line(mixed $text, int $max): string
    {
        if (! is_string($text)) {
            return '';
        }
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, $max);
    }

    /** One line, tags and control characters stripped, cut to $max characters. Null when nothing is left. */
    public static function clip(mixed $text, int $max): ?string
    {
        if (! is_string($text) && ! is_numeric($text)) {
            return null;
        }
        $text = strip_tags((string) $text);
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /**
     * What the model wrote, as plain text on one line with every link removed. An empty string when nothing is left, which the caller treats as "no statement".
     * Longer than $max is cut at a character boundary and marked.
     */
    public static function clean(mixed $text, int $max): string
    {
        if (! is_string($text)) {
            return '';
        }
        $text = strip_tags($text);
        $text = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $text);          // markdown image: gone
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text);        // markdown link: its words stay, its address goes
        $text = (string) preg_replace('/\b(?:https?|ftp|javascript|data|vbscript):[^\s<>"\')\]]*/iu', '', $text);
        $text = (string) preg_replace('/\bwww\.[^\s<>"\')\]]+/iu', '', $text);
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $text = trim((string) preg_replace('/\s+([,.;:!?])/u', '$1', $text));

        if (mb_strlen($text) > $max) {
            $text = rtrim(mb_substr($text, 0, max(1, $max - 1))) . '…';
        }

        return $text;
    }

    /** A LIKE pattern for a typed word, with the wildcards and the escape character escaped (use with ESCAPE '!'). */
    public static function like(string $word): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word) . '%';
    }
}
