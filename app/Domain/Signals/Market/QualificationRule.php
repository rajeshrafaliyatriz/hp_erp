<?php

namespace App\Domain\Signals\Market;

/**
 * Qualification decided from MORE than the evidence strength. Pure; no database, network or config.
 *
 * Today the label is a lookup from claim_level, and so is confidence: two names for one number. This rule keeps confidence as it is (how strong the
 * evidence is) and makes the qualification label answer a different question: is this a real, current, in-scope opportunity worth acting on?
 * It uses the signal grade (named organisation, region, recency, trigger, evidence), the evidence maturity E0-E3 and the ICP fit score.
 *
 *   qualified label (New Opportunity, or Relevant Requirement Found for a tender)
 *       needs ALL of: grade ready, evidence E2 or E3 (corroborated or a primary source: a page read alone, E1, never qualifies), and ICP fit >= min fit
 *   Needs Verification  grade ready but evidence E1 or fit below the minimum, or grade verify
 *   Monitoring          grade lead or reject, E0, or no grade
 *
 * OFF by default (signals.qualification_rule.by_grade); with it off the importer keeps the old claim_level lookup exactly.
 */
final class QualificationRule
{
    public const QUALIFIED_LEVELS = ['E2', 'E3'];

    /**
     * @param  array<string, mixed>|null  $assessment  the stored assessment (signal_grade, evidence_maturity, fit)
     */
    public static function label(string $claimLevel, string $triggerType, ?array $assessment, array $tenderTypes, int $minFit = 45): string
    {
        $grade = is_array($assessment['signal_grade'] ?? null) ? (string) ($assessment['signal_grade']['grade'] ?? '') : '';
        if ($grade === '') {
            return self::byClaim($claimLevel, $triggerType, $tenderTypes);   // no grade recorded (an older signal, or the annotation failed): the old rule
        }
        $level = (string) ($assessment['evidence_maturity']['level'] ?? 'not_assessed');
        $fit = (int) ($assessment['fit']['total'] ?? 0);

        return match ($grade) {
            'ready' => in_array($level, self::QUALIFIED_LEVELS, true) && $fit >= $minFit
                ? (in_array($triggerType, $tenderTypes, true) ? 'Relevant Requirement Found' : 'New Opportunity')
                : 'Needs Verification',
            'verify' => 'Needs Verification',
            default => 'Monitoring',   // lead, reject
        };
    }

    /** The existing rule, unchanged. */
    public static function byClaim(string $claimLevel, string $triggerType, array $tenderTypes): string
    {
        return $claimLevel === 'confirmed'
            ? (in_array($triggerType, $tenderTypes, true) ? 'Relevant Requirement Found' : 'New Opportunity')
            : ($claimLevel === 'inference' ? 'Needs Verification' : 'Monitoring');
    }
}
