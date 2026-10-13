<?php

namespace App\Domain\Signals\Icp;

/**
 * Turns an organisation's PHRASE LIST into a safe matcher.
 *
 * Organisations edit their own ICPs, so a disqualifier must never be a raw regular expression: a pattern like
 * (a+)+$ can freeze a server (ReDoS). Phrases are escaped and matched as whole words or whole phrases,
 * case-insensitively, with any run of spaces matching any run of spaces.
 */
final class IcpPhrases
{
    /** Characters that make a phrase look like a pattern rather than words. */
    public const FORBIDDEN = '/[\\\\()\[\]{}|*+?^$<>]/u';

    /** @param list<string> $phrases */
    public static function regex(array $phrases): ?string
    {
        $parts = [];
        foreach ($phrases as $p) {
            $p = trim(preg_replace('/\s+/u', ' ', (string) $p) ?? '');
            if ($p !== '') {
                $parts[] = str_replace(' ', '\s+', preg_quote($p, '/'));
            }
        }
        if ($parts === []) {
            return null;
        }
        usort($parts, fn ($a, $b) => strlen($b) <=> strlen($a));

        return '/(?<![\p{L}\p{N}])(?:' . implode('|', $parts) . ')(?![\p{L}\p{N}])/iu';
    }

    /** @param list<string> $phrases */
    public static function matches(array $phrases, string $text): bool
    {
        $re = self::regex($phrases);

        return $re !== null && preg_match($re, $text) === 1;
    }
}
