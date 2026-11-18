<?php

namespace App\Domain\Signals\Support;

/**
 * Does a source really say this about THIS organisation? A quote that sits on a page which merely mentions the organisation somewhere else (or
 * contains its name inside another word) is not evidence about it.
 *
 * The name must appear as whole words (so "DPS" is not found inside "dpsx", nor "Podar" inside "Podarsh"), and it must be reliably tied to the
 * quote: in the source's title, in the source's address, or within a window of characters around the quote. Texts are the normalised form the
 * researcher already uses (lower case, single spaces). Pure.
 */
final class EvidenceAttribution
{
    /** Characters either side of the quote within which the name must appear. */
    public const DEFAULT_WINDOW = 1200;

    /**
     * @return array{ok: bool, basis: ?string} basis: title | address | near_quote (null when not ok)
     */
    public static function names(string $sourceNorm, string $nameNorm, string $quoteNorm, string $titleNorm = '', string $url = '', int $window = self::DEFAULT_WINDOW): array
    {
        $name = trim($nameNorm);
        if ($name === '' || ! self::containsWord($sourceNorm, $name)) {
            return ['ok' => false, 'basis' => null];
        }
        if ($titleNorm !== '' && self::containsWord($titleNorm, $name)) {
            return ['ok' => true, 'basis' => 'title'];
        }
        $compact = preg_replace('/[^a-z0-9]/', '', $name) ?? '';
        $host = strtolower((string) parse_url($url, PHP_URL_HOST) . (string) parse_url($url, PHP_URL_PATH));
        if (strlen($compact) >= 4 && str_contains(preg_replace('/[^a-z0-9]/', '', $host) ?? '', $compact)) {
            return ['ok' => true, 'basis' => 'address'];
        }
        $at = $quoteNorm === '' ? false : mb_strpos($sourceNorm, $quoteNorm);
        if ($at === false) {
            return ['ok' => false, 'basis' => null];
        }
        $from = max(0, $at - $window);
        $span = mb_substr($sourceNorm, $from, $window * 2 + mb_strlen($quoteNorm));

        return self::containsWord($span, $name) ? ['ok' => true, 'basis' => 'near_quote'] : ['ok' => false, 'basis' => null];
    }

    /** Whole-word containment: the name is not part of a longer word on either side. */
    public static function containsWord(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        return preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u', $haystack) === 1;
    }
}
