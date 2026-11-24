<?php

namespace App\Domain\Signals\Market;

/**
 * How deep the evidence behind a signal goes. Pure (no database, no clock, no network) and an ANNOTATION ONLY: it never changes
 * claim_level, qualification, confidence, priority or ICP fit. EvidenceGrader stays the single rule for claim_level; this class reads
 * the same inputs and says which of the four depths was reached, and what is missing for the next one.
 *
 *   E0  only a search snippet (or nothing readable) was seen
 *   E1  the source page was read and its content extracted, but the claim is not yet corroborated
 *   E2  corroborated: quote verified on the page, reasoning recorded, and a primary source or at least two independent domains
 *   E3  confirmed: quote verified on the page and the source is PRIMARY (the buyer's own or an official domain naming it)
 *
 * Contradicting evidence is kept, never dropped: any contradiction caps the level at E1 and marks the signal contested.
 */
final class EvidenceMaturity
{
    public const E0 = 'E0';
    public const E1 = 'E1';
    public const E2 = 'E2';
    public const E3 = 'E3';
    public const NOT_ASSESSED = 'not_assessed';

    public const LABELS = [
        self::E0 => 'Search snippet only',
        self::E1 => 'Page read, not yet corroborated',
        self::E2 => 'Corroborated by reliable evidence',
        self::E3 => 'Confirmed by a primary source',
    ];

    private const READ_LEVELS = ['page_text', 'full_document'];

    /**
     * @param  array{fetch_level?: ?string, fetch_status?: ?string, quote_verified?: mixed, primary?: mixed, corroborating_domains?: mixed, inference_reasoning?: mixed, contradictions?: mixed}  $in
     * @return array{level: string, label: string, page_read: bool, quote_verified: bool, primary: bool, independent_domains: int, contested: bool, reasons: list<string>, missing: list<string>, contradictions: list<string>}
     */
    public static function assess(array $in): array
    {
        $read = in_array((string) ($in['fetch_level'] ?? ''), self::READ_LEVELS, true);
        $verified = filter_var($in['quote_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $primary = filter_var($in['primary'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $domains = max(0, (int) ($in['corroborating_domains'] ?? 0));
        // Same threshold as EvidenceGrader::clean: reasoning shorter than 15 characters does not count.
        $reasoned = is_string($in['inference_reasoning'] ?? null) && mb_strlen(trim((string) preg_replace('/\s+/', ' ', $in['inference_reasoning']))) >= 15;
        $contradictions = array_values(array_filter(array_map(fn ($c) => is_string($c) ? mb_substr(trim($c), 0, 300) : '', (array) ($in['contradictions'] ?? []))));
        $status = (string) ($in['fetch_status'] ?? '');

        $reasons = [];
        $missing = [];

        if (! $read) {
            $level = self::E0;
            $reasons[] = $status !== '' && $status !== 'not_attempted'
                ? "The source page could not be read ({$status}); only the search snippet was seen."
                : 'Only the search snippet was seen; the source page was not read.';
            $missing[] = 'Read the source page and verify the supporting quote on it.';
        } elseif ($verified && $primary) {
            $level = self::E3;
            $reasons[] = 'Stated directly by a primary source; page read and quote verified.';
        } elseif ($verified && $reasoned && ($primary || $domains >= 2)) {
            $level = self::E2;
            $reasons[] = $primary ? 'Reasoned from a verified fact on a primary source.' : "Reasoned from a verified fact reported by {$domains} independent domains.";
            $missing[] = 'A primary source stating it directly would make it E3.';
        } else {
            $level = self::E1;
            $reasons[] = 'The page was read.';
            if (! $verified) {
                $missing[] = 'Verify the supporting quote on the page.';
            }
            if (! $reasoned) {
                $missing[] = 'Record the reasoning that links the fact to a need.';
            }
            if (! $primary && $domains < 2) {
                $missing[] = 'Find a primary source, or at least two independent domains reporting it.';
            }
        }

        $contested = $contradictions !== [];
        if ($contested && in_array($level, [self::E2, self::E3], true)) {
            $reasons[] = 'Contradicting evidence is recorded, so the level is capped at E1 until it is resolved.';
            $level = self::E1;
            $missing[] = 'Resolve the contradicting evidence.';
        }

        return [
            'level' => $level, 'label' => self::LABELS[$level], 'page_read' => $read, 'quote_verified' => $verified, 'primary' => $primary,
            'independent_domains' => $domains, 'contested' => $contested, 'reasons' => $reasons, 'missing' => $missing, 'contradictions' => $contradictions,
        ];
    }

    /**
     * The level implied by what a stored signal records: its fetch_level and the claim_level EvidenceGrader derived. Used to replay history,
     * where the primary-source flag and domain count were not stored. A signal with no claim_level was not graded (an import).
     */
    public static function fromStored(?string $claimLevel, ?string $fetchLevel): string
    {
        if ($claimLevel === null || $claimLevel === '') {
            return self::NOT_ASSESSED;
        }
        if (! in_array((string) $fetchLevel, self::READ_LEVELS, true)) {
            return self::E0;
        }

        return match ($claimLevel) {
            'confirmed' => self::E3,
            'inference' => self::E2,
            default => self::E1,
        };
    }
}
