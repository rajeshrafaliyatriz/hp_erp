<?php

namespace App\Domain\Facts;

use InvalidArgumentException;

/**
 * Prices evidence: what a person or an agent SAW becomes a score and a band (slice 4, the contact facts ledger).
 *
 * Ported from Comp AI's CRM research agent (MIT licence, copyright 2026 Comp AI: apps/agent/agent/lib/evidence.ts), with the weights moved to
 * config/facts.php. The caller never sets a confidence: it reports the KIND of each independent source it saw and this class prices it.
 *
 *   score  = 1 - the product of (1 - weight) over the evidence, capped at the ceiling (0.99); a contradiction caps it at 0.45
 *   band   = verified   when the score is at least 0.85 AND at least one source is primary (identifies the person or organisation itself)
 *            probable   when the score is at least 0.55
 *            possible   when the score is at least 0.30
 *            null       below that: not kept at all
 *
 * Deviations from the original, each on purpose:
 *   - The score is ROUNDED TO THREE DECIMALS before the band is read, because that is what is stored (decimal(4,3)) and shown; the band can then never
 *     disagree with the number beside it, and floating-point fuzz (1 - (1 - 0.85) = 0.8499999...) cannot move a fact across a band edge.
 *   - Two pieces of evidence of the same kind from the same address count ONCE. The original's skill tells the agent to report one entry per independent
 *     source and never to split one page in two; here the arithmetic also enforces it, because a page cited three times must not read as three witnesses.
 *     Evidence without an address cannot be matched, so it is never merged.
 *   - An unknown kind is refused (InvalidArgumentException) instead of crashing on an undefined weight.
 */
final class EvidenceScorer
{
    /**
     * @param  list<array{kind: string, quote?: ?string, detail?: ?string, source_url?: ?string}>  $evidence
     * @return array{score: float, band: ?string, has_primary: bool, rationale: string}
     *
     * @throws InvalidArgumentException for a kind that is not in config('facts.weights')
     */
    public static function score(array $evidence): array
    {
        if ($evidence === []) {
            return ['score' => 0.0, 'band' => null, 'has_primary' => false, 'rationale' => 'No evidence.'];
        }

        $weights = self::weights();
        $items = self::independent($evidence);

        $contradicted = false;
        $hasPrimary = false;
        $remaining = 1.0;
        foreach ($items as $item) {
            $kind = (string) $item['kind'];
            $row = $weights[$kind];
            $contradicted = $contradicted || $kind === 'contradiction';
            $hasPrimary = $hasPrimary || (bool) $row['primary'];
            $remaining *= 1 - (float) $row['weight'];
        }

        $score = min((float) config('facts.ceiling', 0.99), 1 - $remaining);
        if ($contradicted) {
            $score = min($score, (float) config('facts.contradicted_cap', 0.45));
        }
        $score = round($score, 3);

        return ['score' => $score, 'band' => self::bandFor($score, $hasPrimary), 'has_primary' => $hasPrimary, 'rationale' => self::rationale($items, $contradicted, $hasPrimary)];
    }

    /** verified needs the score AND a primary source; probable and possible need the score alone; below the last floor there is no band. */
    public static function bandFor(float $score, bool $hasPrimary): ?string
    {
        $floor = (array) config('facts.band_floor');

        if ($score >= (float) $floor['verified'] && $hasPrimary) {
            return 'verified';
        }
        if ($score >= (float) $floor['probable']) {
            return 'probable';
        }
        if ($score >= (float) $floor['possible']) {
            return 'possible';
        }

        return null;
    }

    /** @return array<string, array{weight: float, primary: bool, label: string}> */
    public static function weights(): array
    {
        return (array) config('facts.weights');
    }

    public static function isKnown(string $kind): bool
    {
        return isset(self::weights()[$kind]);
    }

    public static function label(string $kind): string
    {
        return (string) (self::weights()[$kind]['label'] ?? $kind);
    }

    public static function weightOf(string $kind): float
    {
        return (float) (self::weights()[$kind]['weight'] ?? 0.0);
    }

    /**
     * The evidence that counts: every kind checked against the table, and the repeats of one kind from one address dropped.
     *
     * @param  list<array<string, mixed>>  $evidence
     * @return list<array<string, mixed>>
     */
    public static function independent(array $evidence): array
    {
        $weights = self::weights();
        $seen = [];
        $kept = [];
        foreach ($evidence as $item) {
            $kind = (string) ($item['kind'] ?? '');
            if (! isset($weights[$kind])) {
                throw new InvalidArgumentException("Unknown evidence kind '{$kind}'. Kinds: " . implode(', ', array_keys($weights)) . '.');
            }
            $url = FactValue::sameValueKey((string) ($item['source_url'] ?? ''));
            if ($url !== '') {
                $key = $kind . '|' . $url;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
            }
            $kept[] = $item;
        }

        return $kept;
    }

    /** @param list<array<string, mixed>> $items */
    private static function rationale(array $items, bool $contradicted, bool $hasPrimary): string
    {
        if ($contradicted) {
            $clash = null;
            foreach ($items as $item) {
                if ($item['kind'] === 'contradiction') {
                    $clash = (string) ($item['quote'] ?? $item['detail'] ?? '');
                    break;
                }
            }

            return 'Held: ' . ($clash !== null && $clash !== '' ? $clash : 'sources disagree') . '.';
        }

        $reasons = [];
        foreach ($items as $item) {
            if ($item['kind'] !== 'contradiction') {
                $reasons[] = self::label((string) $item['kind']);
            }
        }
        if ($reasons === []) {
            return 'No supporting evidence.';
        }

        $list = count($reasons) === 1 ? $reasons[0] : implode(', ', array_slice($reasons, 0, -1)) . ' and ' . $reasons[array_key_last($reasons)];
        $list = mb_strtoupper(mb_substr($list, 0, 1)) . mb_substr($list, 1);

        return $hasPrimary ? $list : $list . ' — but nothing that identifies them directly.';
    }
}
