<?php

namespace App\Domain\OutreachGate;

use InvalidArgumentException;

/**
 * The small pure text helpers the checks share: sentences, a normal form for comparing a sentence with an approved claim, and the one place a config pattern
 * becomes a regular expression.
 */
final class Text
{
    /** A configured pattern (no delimiters) as a case-insensitive unicode regular expression. A pattern that does not compile is a configuration error. */
    public static function regex(string $pattern): string
    {
        $regex = '~' . str_replace('~', '\~', $pattern) . '~iu';
        if (@preg_match($regex, '') === false) {
            throw new InvalidArgumentException("The pattern /{$pattern}/ is not a valid regular expression.");
        }

        return $regex;
    }

    /**
     * Sentences, in order: split after . ! ? followed by a space, and at every line break. Empty pieces are dropped. A link such as example.org/page is not split (no
     * space after the dot).
     *
     * @return list<string>
     */
    public static function sentences(string $text): array
    {
        // "No. 1" is one claim, not the sentence "No." followed by the sentence "1 ...": a full stop after "no" that is followed by a number does not end a sentence.
        $parts = preg_split('/(?<=[.!?])(?<![Nn][Oo]\.)\s+|(?<=[Nn][Oo]\.)(?!\s*\d)\s+|[\r\n]+/u', $text) ?: [];

        return array_values(array_filter(array_map(fn (string $p) => trim($p), $parts), fn (string $p) => $p !== ''));
    }

    /**
     * The normal form two sentences are compared in: lower case, every kind of quote and dash folded, punctuation dropped (a % and a # are kept: they change a claim),
     * spacing collapsed, and a space before a % closed up. "Cuts paperwork by 60%." and "  cuts PAPERWORK  by 60 % " are one claim; "by 6%" and "by 60%" are not.
     */
    public static function normalise(string $sentence): string
    {
        $s = mb_strtolower($sentence);
        $s = strtr($s, ["\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"', "\u{2013}" => '-', "\u{2014}" => '-', "\u{00A0}" => ' ']);
        $s = preg_replace('/\s*%/u', '%', $s) ?? $s;   // "60 %" and "60%" are one claim
        $s = preg_replace('/[^\p{L}\p{N}%#\s]+/u', ' ', $s) ?? $s;

        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /** How many words a normalised sentence has. */
    public static function words(string $normalised): int
    {
        return $normalised === '' ? 0 : count(explode(' ', $normalised));
    }

    /** A sentence short enough to quote inside a one-line reason. */
    public static function quote(string $sentence, int $max = 90): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', $sentence));

        return '“' . (mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s) . '”';
    }
}
