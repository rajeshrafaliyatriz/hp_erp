<?php

namespace App\Domain\Signals\Sources;

/**
 * Which verified sources a run reads directly. Pure.
 *
 * Least recently read first (a source never read comes first), so repeated runs, on one day or many, walk through every readable source instead of
 * rereading the same few; ties go to the stronger source (SourceSelector::score). A source whose last attempts all read nothing is backed off: after
 * `$failStreakLimit` consecutive attempts that read no item it is skipped for `$backoffDays`, then tried again.
 */
final class DirectSourcePicker
{
    /**
     * @param  list<array<string, mixed>>  $rows  verified registry rows (id, region, classification, signal_types, products …)
     * @param  array<string, array{last_attempt?: ?string, fail_streak?: int}>  $stats  from SourceVerificationStore::readStats()
     * @return list<array<string, mixed>>
     */
    public static function pick(array $rows, array $stats, int $n, string $today, int $failStreakLimit = 2, int $backoffDays = 7): array
    {
        $eligible = [];
        foreach ($rows as $row) {
            $st = $stats[$row['id']] ?? [];
            $last = $st['last_attempt'] ?? null;
            if ((int) ($st['fail_streak'] ?? 0) >= $failStreakLimit && $last !== null
                && (strtotime($today) - strtotime($last)) < $backoffDays * 86400) {
                continue;
            }
            $eligible[] = $row + ['_last' => $last ?? '', '_score' => SourceSelector::score($row)];
        }
        usort($eligible, fn ($a, $b) => [$a['_last'], -$a['_score'], $a['id']] <=> [$b['_last'], -$b['_score'], $b['id']]);

        return array_map(fn ($r) => array_diff_key($r, ['_last' => 1, '_score' => 1]), array_slice($eligible, 0, max(0, $n)));
    }
}
