<?php

namespace App\Domain\Signals\Sources;

use App\Domain\Signals\Market\SourceIndependence;
use App\Domain\Signals\Products\SchoolTriggers;
use App\Domain\Signals\Support\HostClasses;

/**
 * Which sources to read first when the page budget is smaller than the number of sources. Pure: no network, no database.
 * Used only when the evidence-depth flag is on. Without it, pages are read in search order exactly as before.
 *
 * The score says how worthwhile a read is, not whether the source is true:
 *   - a page that can be read at all beats one that cannot (a login-walled social host scores below everything);
 *   - a snippet that already names a business event (SchoolTriggers) is read before one that does not;
 *   - tender and government documents are worth the attempt even though they fail more often.
 */
final class FetchRanker
{
    /** @param list<string> $unreadableHosts hosts that a plain request cannot read (config signals.fetch.skip_hosts) */
    public static function score(string $url, string $title, string $snippet, array $unreadableHosts = HostClasses::UNREADABLE_SOCIAL): int
    {
        if (HostClasses::isUnreadableSocial($url, $unreadableHosts)) {
            return -100;
        }
        $type = SourceIndependence::sourceType($url);
        $score = match ($type) {
            'web' => 50,
            'government' => 60,
            'pdf' => 55,
            'job_board' => 15,
            'social' => 5,   // e.g. linkedin: attempted, but rarely readable
            default => 40,
        };
        $triggers = SchoolTriggers::detect($title . ' ' . $snippet);
        if ($triggers !== []) {
            $score += 20 + (int) round($triggers[0]['strength'] / 10);
        }

        return $score;
    }

    /**
     * The order to read the sources in: highest score first, original (search) order kept among equals.
     *
     * @param  list<array<string, mixed>>  $sources  each with url, title, text
     * @return list<int>  indexes into $sources
     */
    public static function order(array $sources, array $unreadableHosts = HostClasses::UNREADABLE_SOCIAL): array
    {
        $scored = [];
        foreach ($sources as $i => $s) {
            $scored[] = ['i' => $i, 's' => self::score((string) ($s['url'] ?? ''), (string) ($s['title'] ?? ''), (string) ($s['text'] ?? ''), $unreadableHosts)];
        }
        usort($scored, fn ($a, $b) => [$b['s'], $a['i']] <=> [$a['s'], $b['i']]);

        return array_column($scored, 'i');
    }
}
