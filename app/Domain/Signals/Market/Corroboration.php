<?php

namespace App\Domain\Signals\Market;

use App\Domain\Signals\Sources\Adapters\PlatformPolicy;

/**
 * Decides which independently read pages CORROBORATE a candidate signal. Pure (no network, no clock, no database); an ANNOTATION helper that
 * never touches claim_level, qualification, confidence, priority or fit.
 *
 * A page corroborates only when ALL of these hold:
 *   - its registrable domain differs from the original source's and from every earlier corroborating page (SourceIndependence);
 *   - it is not on a lead-hint-only platform (social networks, PlatformPolicy);
 *   - it names the organisation (normalised, whole words);
 *   - at least MIN_WORDS distinctive content words of the event description appear in the same page.
 */
final class Corroboration
{
    public const MIN_WORDS = 3;
    private const MAX_EVENT_WORDS = 24;
    private const MIN_WORD_LENGTH = 4;

    private const STOP = [
        'about', 'above', 'after', 'again', 'also', 'among', 'announced', 'announces', 'been', 'before', 'being', 'between', 'both', 'could',
        'during', 'each', 'from', 'further', 'have', 'having', 'here', 'into', 'just', 'more', 'most', 'much', 'only', 'other', 'over', 'same',
        'said', 'says', 'some', 'such', 'than', 'that', 'their', 'them', 'then', 'there', 'these', 'they', 'this', 'those', 'through', 'under',
        'until', 'upon', 'very', 'was', 'were', 'what', 'when', 'where', 'which', 'while', 'will', 'with', 'within', 'would', 'your', 'has',
        'had', 'its', 'for', 'and', 'the', 'are', 'not', 'new', 'now', 'one', 'two', 'per', 'via', 'school', 'schools', 'institute', 'company',
        'next', 'last', 'year', 'years', 'many', 'made', 'make', 'organisation', 'organization', 'plans', 'plan', 'planned', 'recently', 'currently', 'including', 'reported', 'according',
    ];

    private const NEGATION = '/\b(?:denied|denies|denial|false report|fake news|rumou?rs?|cancel(?:led|ed)|called off|withdrawn|scrapped|no plans?)\b/u';

    /**
     * @param  array{organisation?: ?string, event?: ?string, source_url?: ?string}  $candidate
     * @param  list<array{url?: ?string, title?: ?string, text?: ?string}>  $pages  pages that were actually READ
     * @return array{corroborating: list<array{url: string, host: string, matched_words: list<string>}>, independent_domains: int, confirmed: bool, contradictions: list<string>}
     */
    public static function assess(array $candidate, array $pages): array
    {
        $org = self::normalise(self::withoutParenthetical((string) ($candidate['organisation'] ?? '')));
        $words = self::eventWords((string) ($candidate['event'] ?? ''), $org);
        $originalDomain = SourceIndependence::registrable(SourceIndependence::host((string) ($candidate['source_url'] ?? '')));
        $policy = new PlatformPolicy();

        $used = $originalDomain !== '' ? [$originalDomain => true] : [];
        $out = [];
        $contradictions = [];
        if ($org === '' || count($words) < self::MIN_WORDS) {
            return ['corroborating' => [], 'independent_domains' => count($used), 'confirmed' => false, 'contradictions' => []];
        }

        foreach ($pages as $p) {
            $url = trim((string) ($p['url'] ?? ''));
            $host = SourceIndependence::host($url);
            if ($url === '' || $host === '') {
                continue;
            }
            $domain = SourceIndependence::registrable($host);
            if ($domain === '' || isset($used[$domain])) {
                continue;
            }
            if ($policy->forHost($host)['mode'] === PlatformPolicy::LEAD_HINT_ONLY || SourceIndependence::sourceType($url) === 'social') {
                continue;
            }
            $norm = ' ' . self::normalise((string) ($p['title'] ?? '') . ' ' . (string) ($p['text'] ?? '')) . ' ';
            if (! str_contains($norm, ' ' . $org . ' ')) {
                continue;
            }
            $matched = [];
            foreach ($words as $w) {
                if (str_contains($norm, ' ' . $w . ' ')) {
                    $matched[] = $w;
                }
            }
            if (count($matched) < self::MIN_WORDS) {
                continue;
            }
            $note = self::contradiction($norm, $org);
            if ($note !== null) {
                $contradictions[] = $note . ' (' . $host . ')';

                continue;
            }
            $used[$domain] = true;
            $out[] = ['url' => $url, 'host' => $host, 'matched_words' => $matched];
        }

        return [
            'corroborating' => $out,
            'independent_domains' => count($used),
            'confirmed' => $out !== [] && $contradictions === [],
            'contradictions' => $contradictions,
        ];
    }

    /** @return list<string> distinctive content words of the event text, in order of first appearance */
    public static function eventWords(string $event, string $orgNormalised = ''): array
    {
        $orgWords = array_flip(array_filter(explode(' ', $orgNormalised)));
        $words = [];
        foreach (explode(' ', self::normalise($event)) as $w) {
            if (mb_strlen($w) < self::MIN_WORD_LENGTH || in_array($w, self::STOP, true) || isset($orgWords[$w]) || ctype_digit($w)) {
                continue;
            }
            $words[$w] = true;
            if (count($words) >= self::MAX_EVENT_WORDS) {
                break;
            }
        }

        return array_map('strval', array_keys($words));
    }

    public static function normalise(string $s): string
    {
        $s = mb_strtolower($s);
        $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    private static function withoutParenthetical(string $s): string
    {
        return (string) preg_replace('/\([^)]*\)/u', ' ', $s);
    }

    /** Minimal: the organisation's name within 80 characters of a clear negation. Anything subtler is not guessed. */
    private static function contradiction(string $norm, string $org): ?string
    {
        $needle = ' ' . $org . ' ';
        $pos = strpos($norm, $needle);
        while ($pos !== false) {
            $window = substr($norm, max(0, $pos - 80), strlen($needle) + 160);
            if (preg_match(self::NEGATION, $window, $m) === 1) {
                return 'The page appears to dispute it ("' . $m[0] . '")';
            }
            $pos = strpos($norm, $needle, $pos + 1);
        }

        return null;
    }
}
