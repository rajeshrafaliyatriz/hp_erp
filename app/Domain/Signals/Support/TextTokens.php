<?php

namespace App\Domain\Signals\Support;

/**
 * Distinct, lightly stemmed words of a text, for comparing two records by what they actually say. Deterministic: the same text always gives the same
 * words. Words too common to show that two texts are about the same thing (school, system, platform ...) are dropped when asked.
 */
final class TextTokens
{
    /** Words too common to mean anything on their own. */
    private const GENERIC = ['school', 'schools', 'system', 'systems', 'platform', 'management', 'manager', 'data', 'new', 'need', 'needs', 'from', 'with', 'that', 'this', 'their', 'have', 'has', 'for', 'and', 'the', 'are', 'its', 'one', 'day', 'into', 'across', 'which', 'when', 'where', 'while', 'using', 'used', 'use', 'run', 'running', 'all', 'can', 'will', 'also', 'more', 'than', 'our', 'your', 'who', 'what', 'about', 'over', 'such', 'each', 'any', 'may', 'been', 'being', 'were', 'was', 'solution', 'solutions', 'software', 'service', 'services', 'product', 'products', 'support', 'help', 'based', 'including', 'provides', 'provide', 'offers', 'offer', 'company', 'organisation', 'organization', 'year', 'years', 'announced', 'announces', 'said', 'says'];

    /** @return list<string> */
    public static function of(string $text, bool $dropGeneric = true): array
    {
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($words as $w) {
            $w = preg_replace('/(ies)$/', 'y', $w) ?? $w;
            $w = preg_replace('/(es|s)$/', '', $w) ?? $w;
            if ($dropGeneric && (strlen($w) < 4 || in_array($w, self::GENERIC, true) || in_array($w . 's', self::GENERIC, true))) {
                continue;
            }
            if (! $dropGeneric && strlen($w) < 2) {
                continue;
            }
            $out[$w] = true;
        }

        return array_keys($out);
    }

    /** @return list<string> the words both texts share */
    public static function shared(array $a, array $b): array
    {
        return array_values(array_intersect($a, $b));
    }
}
