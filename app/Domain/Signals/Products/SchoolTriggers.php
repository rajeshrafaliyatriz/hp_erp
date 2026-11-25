<?php

namespace App\Domain\Signals\Products;

/**
 * Business-event triggers for school prospects ("why now"), plus an event fingerprint and a priority score.
 * Pure text and arithmetic: no provider, no database. It annotates a signal; it never qualifies or rejects one (that stays with QualificationGate).
 *
 * Priority and confidence are separate on purpose: confidence says how sure we are the event is real (evidence), priority says how much it
 * matters to us now. A rumour can be high priority and low confidence. The weights are a starting rubric to recalibrate from outcomes,
 * not a validated model.
 */
final class SchoolTriggers
{
    /** An undated event has no "why now": its priority is capped here. */
    public const UNDATED_PRIORITY_CAP = 60;

    /**
     * key => [label, strength 0-100, expiry in days, suggested action, phrases (lower case)]
     * Ordered by strength only for readability; detect() sorts.
     */
    private const TRIGGERS = [
        'erp_lms_tender' => ['ERP / LMS tender or stated requirement', 95, 45, 'Check the tender deadline and eligibility; prepare a response', ['tender for', 'rfp', 'request for proposal', 'expression of interest', 'eoi', 'invites bids', 'bids invited', 'rfq']],
        'campus_expansion' => ['New campus or expansion', 85, 120, 'Find the decision maker and open with multi-campus needs', ['new campus', 'second campus', 'new branch', 'inaugurat', 'foundation stone', 'expansion of the school', 'additional grades', 'new building']],
        'new_affiliation' => ['New affiliation or upgrade', 80, 120, 'Reach the principal or trustee with a set-up pitch', ['newly affiliated', 'affiliation granted', 'granted affiliation', 'affiliation upgrade', 'upgraded to senior secondary', 'cbse affiliation', 'icse affiliation']],
        'group_formation' => ['School group formation or acquisition', 85, 150, 'Treat as an enterprise opportunity; find group-level buyer', ['acquires', 'acquisition of', 'merger', 'school group', 'chain of schools', 'multi-campus', 'joins hands with']],
        'leadership_change' => ['Leadership change', 70, 90, 'Personalised outreach to the new leader', ['new principal', 'appointed principal', 'joins as principal', 'new director', 'new chairman', 'new head of school', 'takes charge as']],
        'teacher_hiring' => ['Teacher recruitment surge', 65, 60, 'Offer teacher capability and onboarding support', ['recruitment of teachers', 'teachers required', 'we are hiring teachers', 'invites applications for teachers', 'teacher vacancies', 'walk-in interview for teachers']],
        'government_programme' => ['Government programme or mandate', 75, 180, 'Check eligibility for the programme; position the G2G offer', ['acbp', 'training needs assessment', 'competency framework', 'nep implementation', 'udise', 'mandatory training', 'government directive']],
        'digital_initiative' => ['Digital transformation initiative', 70, 120, 'Assess product fit and approach the sponsor', ['digitisation', 'digitization', 'digital transformation', 'smart classroom', 'implementing erp', 'adopting lms', 'technology upgrade']],
        'funding' => ['Funding or investment', 60, 120, 'Research budget and timeline before outreach', ['raises funding', 'investment in school', 'grant sanctioned', 'csr funding', 'sanctioned amount']],
        'event_participation' => ['Relevant event participation', 35, 30, 'Light relationship-building only', ['principal attended', 'conference', 'summit', 'workshop for principals', 'principals meet']],
    ];

    /**
     * @return list<array{key: string, label: string, strength: int, expires_in_days: int, action: string, matched: string}>  strongest first
     */
    public static function detect(string $text): array
    {
        $t = mb_strtolower($text);
        $found = [];
        foreach (self::TRIGGERS as $key => [$label, $strength, $days, $action, $phrases]) {
            foreach ($phrases as $p) {
                if (str_contains($t, $p)) {
                    $found[] = ['key' => $key, 'label' => $label, 'strength' => $strength, 'expires_in_days' => $days, 'action' => $action, 'matched' => $p];
                    break;
                }
            }
        }
        usort($found, fn ($a, $b) => $b['strength'] <=> $a['strength']);

        return $found;
    }

    /**
     * One id for one underlying event, however many pages report it: company + trigger + month of the event (or "undated").
     */
    public static function fingerprint(string $company, string $triggerKey, ?string $eventDate): string
    {
        $name = mb_strtolower($company);
        $name = preg_replace('/[^a-z0-9 ]+/', ' ', $name) ?? '';
        $name = preg_replace('/\b(the|school|schools|vidyalaya|international|public|high|sr|sec|senior|secondary|pvt|ltd)\b/', ' ', $name) ?? '';
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $month = ($eventDate !== null && preg_match('/^(\d{4}-\d{2})/', $eventDate, $m)) ? $m[1] : 'undated';

        return sha1($name . '|' . $triggerKey . '|' . $month);
    }

    /**
     * @param  array{fit?: int, trigger_strength?: int, evidence?: int, has_contact?: bool, event_date?: ?string, today?: ?string, expires_in_days?: int}  $in
     *         fit, trigger_strength and evidence are 0-100; event_date is YYYY-MM-DD or null
     * @return array{priority: int, confidence: int, recency: int, expired: bool, notes: list<string>}
     */
    public static function score(array $in): array
    {
        $fit = self::clamp($in['fit'] ?? 0);
        $strength = self::clamp($in['trigger_strength'] ?? 0);
        $evidence = self::clamp($in['evidence'] ?? 0);
        $contact = ! empty($in['has_contact']) ? 100 : 0;
        $notes = [];

        $recency = 0;
        $expired = false;
        $date = $in['event_date'] ?? null;
        if ($date === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $notes[] = 'No event date: there is no way to say why now, so priority is capped.';
        } else {
            $today = $in['today'] ?? date('Y-m-d');
            $age = (int) floor((strtotime($today) - strtotime($date)) / 86400);
            $expiry = max(1, (int) ($in['expires_in_days'] ?? 90));
            if ($age < 0) {
                $notes[] = 'Event date is in the future.';
                $recency = 100;
            } elseif ($age > $expiry) {
                $expired = true;
                $notes[] = "Older than the {$expiry}-day window for this trigger.";
            } else {
                $recency = (int) round(100 * (1 - $age / $expiry));
            }
        }

        $priority = (int) round(0.25 * $fit + 0.25 * $strength + 0.20 * $evidence + 0.20 * $recency + 0.10 * $contact);
        if ($date === null) {
            $priority = min($priority, self::UNDATED_PRIORITY_CAP);
        }
        if ($expired) {
            $priority = min($priority, 30);
        }
        if ($evidence < 40) {
            $notes[] = 'Weak evidence: confirm the event before acting.';
        }

        return ['priority' => $priority, 'confidence' => $evidence, 'recency' => $recency, 'expired' => $expired, 'notes' => $notes];
    }

    private static function clamp(int|float $v): int
    {
        return (int) max(0, min(100, round($v)));
    }
}
