<?php

namespace App\Domain\Signals\Market;

/**
 * A plain-English quality grade for one found signal. An ANNOTATION only: it never reads or changes claim_level / qualification / confidence. Pure.
 *
 *  ready  - named organisation, region inside, dated within the recency window, trigger matched, evidence E1+ with a verified quote
 *  verify - everything is good except the evidence (E0 or quote not verified): needs a page read or a second source
 *  lead   - a named organisation, but only lead-hint platform evidence, or region unknown/unconfirmed, or undated/stale, or trigger not matched
 *  reject - a blocker: no named organisation, region outside, expired deadline, disqualifier, opinion/research item
 */
final class SignalGrade
{
    /**
     * @param array{organisation?: ?string, kind?: ?string, source_url?: ?string, source_host_policy?: ?string, evidence_level?: ?string,
     *     quote_verified?: bool, event_date?: ?string, source_published_at?: ?string, deadline?: ?string, today?: string, recency_days?: int,
     *     trigger_matched?: bool, region_status?: ?string, disqualifier_hit?: bool, is_opinion_or_research?: bool, independent_domains?: int} $c
     * @return array{grade: string, reasons: list<string>, missing: list<string>, blockers: list<string>}
     */
    public static function grade(array $c): array
    {
        $org = trim((string) ($c['organisation'] ?? ''));
        $policy = (string) ($c['source_host_policy'] ?? '');
        $level = (string) ($c['evidence_level'] ?? 'not_assessed');
        $quote = (bool) ($c['quote_verified'] ?? false);
        $region = (string) ($c['region_status'] ?? 'unknown');
        $today = self::date($c['today'] ?? null) ?? date('Y-m-d');
        $recency = max(1, (int) ($c['recency_days'] ?? 365));
        $deadline = self::date($c['deadline'] ?? null);
        $dated = self::date($c['event_date'] ?? null) ?? self::date($c['source_published_at'] ?? null);
        $domains = (int) ($c['independent_domains'] ?? 0);

        $blockers = [];
        $missing = [];
        $reasons = [];

        // blockers
        if ($org === '') {
            $blockers[] = 'No named organisation is identified.';
        } elseif (GenericName::isGeneric($org)) {
            $blockers[] = "\"{$org}\" is a category, not a named organisation.";
        }
        if ($region === 'outside') {
            $blockers[] = 'The organisation is outside the ICP regions.';
        }
        if ($deadline !== null && $deadline < $today) {
            $blockers[] = "The deadline ({$deadline}) has already passed.";
        }
        if (! empty($c['disqualifier_hit'])) {
            $blockers[] = 'A disqualifier from the ICP applies.';
        }
        if (! empty($c['is_opinion_or_research'])) {
            $blockers[] = 'This is an opinion or research item with no buyer.';
        }
        if ($blockers !== []) {
            return ['grade' => 'reject', 'reasons' => $blockers, 'missing' => [], 'blockers' => $blockers];
        }

        $reasons[] = "Named organisation: {$org}.";

        // lead-level gaps
        $lead = false;
        if ($policy === 'lead_hint_only') {
            $lead = true;
            $missing[] = 'Evidence comes only from a lead-hint platform (social post or search snippet); find the organisation\'s own page or a news or tender source.';
        }
        if ($region === 'inside') {
            $reasons[] = 'Region is inside the ICP regions.';
        } else {
            $lead = true;
            $missing[] = $region === 'unconfirmed' ? 'Region is not confirmed by the source.' : 'Region could not be determined.';
        }
        if ($dated === null && $deadline === null) {
            $lead = true;
            $missing[] = 'No event or publication date.';
        } elseif ($dated !== null && $dated < date('Y-m-d', strtotime($today . " -{$recency} days"))) {
            $lead = true;
            $missing[] = "The dated event ({$dated}) is older than {$recency} days.";
        } else {
            $reasons[] = 'Dated within the recency window' . ($deadline !== null ? " (open deadline {$deadline})." : '.');
        }
        if (empty($c['trigger_matched'])) {
            $lead = true;
            $missing[] = 'No ICP trigger is matched.';
        } else {
            $reasons[] = 'Matches an ICP trigger.';
        }
        if ($lead) {
            return ['grade' => 'lead', 'reasons' => $reasons, 'missing' => $missing, 'blockers' => []];
        }

        // evidence
        $strong = in_array($level, ['E1', 'E2', 'E3'], true);
        if (! $strong) {
            $missing[] = 'Evidence is E0 or not assessed: read the source page or add a second source.';
        }
        if (! $quote) {
            $missing[] = 'The supporting quote was not found on the page.';
        }
        if (! $strong || ! $quote) {
            return ['grade' => 'verify', 'reasons' => $reasons, 'missing' => $missing, 'blockers' => []];
        }

        $reasons[] = "Evidence {$level} with a verified quote" . ($domains > 1 ? " and {$domains} independent domains." : '.');

        return ['grade' => 'ready', 'reasons' => $reasons, 'missing' => [], 'blockers' => []];
    }

    private static function date(mixed $v): ?string
    {
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
            return null;
        }

        return substr($v, 0, 10);
    }
}
