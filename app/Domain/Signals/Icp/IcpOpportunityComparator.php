<?php

namespace App\Domain\Signals\Icp;

/**
 * Lays one stored opportunity next to its product's ICP, criterion by criterion. Pure (no database, no clock, no provider): the caller passes
 * the ICP configuration, the target account behind the organisation (or null) and the opportunity's stored evidence.
 *
 * It judges nothing new. Each must-have is checked by IcpScorer's own rule on the account's recorded facts, each exclusion by the ICP's own
 * phrases on the same text the research gate used (organisation name + what happened). It never decides whether the opportunity is qualified:
 * that was decided, and stored, by the server's qualification gate when the signal was made.
 *
 *  - Matched      a recorded fact meets the criterion.
 *  - Not found    an exclusion's words do not appear in the texts that were checked (this does not prove the exclusion cannot apply elsewhere).
 *  - Needs review the fact is not recorded (or no account is linked). Missing data is never a match and never a failure.
 *  - Excluded     a recorded fact fails a must-have, or an exclusion's words appear (the ICP's scoring disqualifies on both).
 *
 * Evidence and source are only ever values that are stored; where none is stored they are null and `evidence_available` is false.
 */
final class IcpOpportunityComparator
{
    public const MATCHED = 'Matched';
    public const NOT_FOUND = 'Not found';
    public const NEEDS_REVIEW = 'Needs review';
    public const EXCLUDED = 'Excluded';

    private const OPS = [
        '>=' => 'at least', '<=' => 'at most', '>' => 'more than', '<' => 'less than', 'between' => 'between', 'in' => 'is one of',
        'not_in' => 'is none of', '=' => 'equals', '!=' => 'does not equal', 'in_regions' => 'is inside the target regions',
    ];

    public function __construct(private readonly IcpScorer $scorer = new IcpScorer())
    {
    }

    /**
     * @param  array<string, mixed>  $pack  the ICP configuration as discovery reads it
     * @param  array<string, mixed>|null  $account  the target account (name, state, board, level, website, city, attributes[...]) or null when none is linked
     * @param  array{company_name?: ?string, observed_event?: ?string, source?: ?array{label?: ?string, url?: ?string}}  $opportunity
     * @return array{criteria: list<array<string, mixed>>, summary: array<string, int>, notes: list<string>}
     */
    public function compare(array $pack, ?array $account, array $opportunity): array
    {
        $rows = [];
        $accountRef = $account['crm_ref'] ?? null;
        $accountSource = $account === null ? null : ['label' => 'Target account record', 'url' => null, 'ref' => $accountRef !== null && $accountRef !== '' ? (string) $accountRef : null];

        if ($account !== null) {
            foreach ($this->scorer->mustHaveChecks($account, $pack) as $c) {
                $rows[] = $this->mustHaveRow($c, $accountSource);
            }
        } else {
            foreach ((array) ($pack['must_have'] ?? []) as $rule) {
                $rows[] = $this->row('must_have', $this->describeRule((array) $rule), self::NEEDS_REVIEW, null, null,
                    'No target account is linked to this organisation, so none of its facts are recorded. Missing data is not a match.');
            }
        }

        $text = trim((string) ($opportunity['company_name'] ?? '') . ' ' . (string) ($opportunity['observed_event'] ?? ''));
        $source = $this->source($opportunity['source'] ?? null);
        foreach ((array) ($pack['disqualifiers'] ?? []) as $d) {
            $phrases = array_map('strval', (array) ($d['phrases'] ?? []));
            $label = (string) ($d['label'] ?? 'Exclusion');
            if ($text !== '' && IcpPhrases::matches($phrases, $text)) {
                $rows[] = $this->row('exclusion', $label, self::EXCLUDED, ['kind' => 'recorded_text', 'text' => $text], $source,
                    'One of this exclusion\'s words appears in the organisation\'s name or the recorded event. The ICP excludes it.');
            } else {
                $rows[] = $this->row('exclusion', $label, self::NOT_FOUND, null, null,
                    'None of this exclusion\'s words appear in the organisation\'s name or the recorded event. Only those two texts were checked.');
            }
        }

        // Rules a person sets by hand on the account (flags): only one that is actually set is a finding.
        foreach ((array) (($account['attributes'] ?? [])['flags'] ?? []) as $flag) {
            if (isset($pack['manual_flags'][$flag])) {
                $rows[] = $this->row('exclusion', (string) $pack['manual_flags'][$flag], self::EXCLUDED, ['kind' => 'account_flag', 'text' => 'Flag set on the account: ' . $flag], $accountSource,
                    'A person marked this account with this flag. The ICP excludes it.');
            }
        }

        $summary = [self::MATCHED => 0, self::NOT_FOUND => 0, self::NEEDS_REVIEW => 0, self::EXCLUDED => 0];
        foreach ($rows as $r) {
            $summary[$r['status']]++;
        }
        $notes = [];
        if ($rows === []) {
            $notes[] = 'This ICP has no must-have criteria or exclusions to compare against.';
        }

        return ['criteria' => $rows, 'summary' => $summary, 'notes' => $notes];
    }

    /** @param array{rule: array<string, mixed>, subject: string, value: mixed, status: string} $c */
    private function mustHaveRow(array $c, ?array $accountSource): array
    {
        $criterion = $this->describeRule($c['rule']);
        $shown = is_scalar($c['value']) ? (string) $c['value'] : '';

        return match ($c['status']) {
            'pass' => $this->row('must_have', $criterion, self::MATCHED, ['kind' => 'account_fact', 'text' => "{$c['subject']}: {$shown}"], $accountSource,
                'The recorded fact meets this criterion.'),
            'fail' => $this->row('must_have', $criterion, self::EXCLUDED, ['kind' => 'account_fact', 'text' => "{$c['subject']}: {$shown}"], $accountSource,
                'The recorded fact does not meet this criterion' . (isset($c['rule']['label']) ? ' (' . str_replace('{value}', $shown, (string) $c['rule']['label']) . ')' : '') . '. The ICP\'s scoring disqualifies an account on it.'),
            default => $this->row('must_have', $criterion, self::NEEDS_REVIEW, null, null,
                'This fact is not recorded on the account. Unknown is never a match and never a failure: it needs a person to check.'),
        };
    }

    /** @param array<string, mixed> $rule */
    private function describeRule(array $rule): string
    {
        $subject = (string) ($rule['field'] ?? $rule['attribute'] ?? 'fact');
        $op = self::OPS[$rule['op'] ?? ''] ?? (string) ($rule['op'] ?? '');
        $target = $rule['value'] ?? null;
        $value = ($rule['op'] ?? '') === 'in_regions' ? '' : (is_array($target) ? implode(', ', array_map('strval', $target)) : (is_scalar($target) ? (string) $target : ''));

        return trim("{$subject} {$op} {$value}");
    }

    /** @param array{label?: ?string, url?: ?string}|null $source @return array{label: ?string, url: ?string, ref: null}|null */
    private function source(?array $source): ?array
    {
        $label = $source['label'] ?? null;
        $url = $source['url'] ?? null;

        return ($label === null || $label === '') && ($url === null || $url === '') ? null : ['label' => $label ?: null, 'url' => $url ?: null, 'ref' => null];
    }

    /**
     * @param  array{kind: string, text: string}|null  $evidence
     * @param  array{label: ?string, url: ?string, ref: ?string}|null  $source
     * @return array<string, mixed>
     */
    private function row(string $kind, string $criterion, string $status, ?array $evidence, ?array $source, string $reason): array
    {
        return [
            'kind' => $kind, 'criterion' => $criterion, 'status' => $status,
            'evidence_available' => $evidence !== null, 'evidence' => $evidence, 'source' => $source, 'reason' => $reason,
        ];
    }
}
