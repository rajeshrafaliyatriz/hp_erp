<?php

namespace App\Domain\AgentGateway\Tools;

use App\Domain\AgentGateway\Rejected;

/** Plain-text clean-up shared by the tools. What a model wrote is stored as text and shown as text; nothing here is ever HTML. */
final class Text
{
    /**
     * Strips tags and control characters, trims. `$multiline` keeps line breaks (a note); otherwise the text becomes one line (a title).
     * Returns the cleaned text; the caller enforces its length with within().
     */
    public static function plain(mixed $value, bool $multiline): string
    {
        if (! is_string($value)) {
            return '';
        }
        $text = strip_tags($value);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = $multiline ? (string) preg_replace("/\r\n?/", "\n", $text) : (string) preg_replace('/\s+/u', ' ', $text);
        if ($multiline) {
            $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
        }

        return trim($text);
    }

    /** @throws Rejected when the text is empty or longer than $max characters */
    public static function within(string $text, int $max, string $field): string
    {
        if ($text === '') {
            throw new Rejected("empty_{$field}", "The {$field} is empty.");
        }
        if (mb_strlen($text) > $max) {
            throw new Rejected("{$field}_too_long", "The {$field} is longer than {$max} characters.");
        }

        return $text;
    }

    /** Lower-cased, whitespace-collapsed text for the identity hash, so a trivially re-worded duplicate is still a duplicate. */
    public static function fold(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
