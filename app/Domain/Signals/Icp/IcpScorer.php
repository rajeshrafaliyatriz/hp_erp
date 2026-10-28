<?php

namespace App\Domain\Signals\Icp;

use Illuminate\Support\Carbon;

/**
 * Scores a target account against ANY organisation's ICP. Pure: no database, no clock, nothing school-specific:
 * criteria, must-haves, disqualifier phrases and weights all come from the ICP data.
 *
 * THE RULE THAT MATTERS: a fact we do not know scores nothing AND is reported as unknown. It is never treated as a
 * bad fit, and a must-have is checked only when its fact is known. An account whose key facts are unknown is
 * "unscored", not "not ICP".
 *
 * Statuses: disqualified (a hard rule applies) | watch (a watch rule holds, e.g. a new ERP signed in the last 12 months:
 * kept, not rejected) | unscored (too few facts) | candidate (passes every known must-have and enough facts are known).
 *
 * Only the facts about an account are scored here (`criteria`). The ICP's judgement and live-evidence points are added
 * when a lead is reviewed. The entry tier is judged by a person and is never computed here.
 */
final class IcpScorer
{
    /**
     * @param  array<string, mixed>  $account  name, state, board, level, website, attributes[...]
     * @param  array<string, mixed>  $pack  the ICP configuration
     * @return array{status: string, tier: null, fit_pct: ?int, static_points: int, known_share: int, watch_priority: int, notes: array<string, mixed>}
     */
    public function score(array $account, array $pack, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $name = (string) ($account['name'] ?? '');
        $attr = (array) ($account['attributes'] ?? []);
        [$facts, $openQuestions] = $this->facts($account, $attr, $pack);

        $disq = $this->disqualifier($name, $pack) ?? $this->mustHaveFailure($facts, $attr, $account, $pack) ?? $this->manualFlag($attr, $pack);
        $watch = $disq === null ? $this->watchRule($facts, $attr, $account, $pack, $now) : null;

        $earned = 0;
        $knownMax = 0;
        $totalMax = 0;
        $known = [];
        $unknown = [];
        foreach ((array) ($pack['criteria'] ?? []) as $def) {
            $key = (string) $def['key'];
            $max = (int) $def['max'];
            $totalMax += $max;
            $value = $facts[$key] ?? null;
            if ($value === null || $value === '') {
                $unknown[] = $key;

                continue;
            }
            $points = $this->points($def, $value);
            $earned += $points;
            $knownMax += $max;
            $known[$key] = ['value' => $value, 'points' => $points, 'of' => $max];
        }

        $knownShare = $totalMax > 0 ? (int) round($knownMax / $totalMax * 100) : 0;
        $fitPct = $knownMax > 0 ? (int) round($earned / $knownMax * 100) : null;
        $enough = $knownShare >= (int) round(((float) ($pack['min_known_share'] ?? 0.5)) * 100);

        $status = match (true) {
            $disq !== null => 'disqualified',
            $watch !== null => 'watch',
            ! $enough || $fitPct === null => 'unscored',
            default => 'candidate',
        };

        return [
            'status' => $status,
            'tier' => null,
            'fit_pct' => $fitPct,
            'static_points' => $earned,
            'known_share' => $knownShare,
            'watch_priority' => $this->watchPriority($status, $facts, $account, $pack),
            'notes' => array_filter([
                'known' => $known,
                'unknown' => $unknown,
                'disqualified_by' => $disq,
                'watch' => $watch,
                'open_questions' => $openQuestions,
                'matches_watch_first' => IcpPhrases::matches((array) ($pack['watch_first_phrases'] ?? []), $name) ? true : null,
            ], fn ($v) => $v !== null && $v !== []),
        ];
    }

    /** The first disqualifier phrase list the text trips, as its label, or null. */
    public function disqualifier(string $text, array $pack): ?string
    {
        foreach ((array) ($pack['disqualifiers'] ?? []) as $d) {
            if (IcpPhrases::matches((array) ($d['phrases'] ?? []), $text)) {
                return (string) $d['label'];
            }
        }

        return null;
    }

    /** Rupees the way people write them in India: 100000 -> 1,00,000. */
    public static function inr(int $n): string
    {
        $s = (string) abs($n);
        if (strlen($s) > 3) {
            $s = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($s, 0, -3)) . ',' . substr($s, -3);
        }

        return ($n < 0 ? '-' : '') . $s;
    }

    /**
     * Every must-have of the ICP checked against one account, one result each, by the SAME value lookup and the SAME `passes` rule as the
     * scoring above. `unknown` (the account does not record the fact) is never a pass and never a failure. Read-only: it scores nothing.
     *
     * @param  array<string, mixed>  $account  name, state, board, level, website, attributes[...]
     * @param  array<string, mixed>  $pack  the ICP configuration
     * @return list<array{rule: array<string, mixed>, subject: string, value: mixed, status: string}> status: pass | fail | unknown
     */
    public function mustHaveChecks(array $account, array $pack): array
    {
        $attr = (array) ($account['attributes'] ?? []);
        [$facts] = $this->facts($account, $attr, $pack);
        $out = [];
        foreach ((array) ($pack['must_have'] ?? []) as $rule) {
            $value = isset($rule['field'])
                ? ($account[$rule['field']] ?? null)
                : ($facts[$rule['attribute']] ?? $attr[$rule['attribute']] ?? null);
            $status = ($value === null || $value === '') ? 'unknown' : ($this->passes($rule, $value, $pack) ? 'pass' : 'fail');
            $out[] = ['rule' => (array) $rule, 'subject' => (string) ($rule['field'] ?? $rule['attribute'] ?? ''), 'value' => $value, 'status' => $status];
        }

        return $out;
    }

    /** A must-have that a KNOWN fact fails, as its label, or null. Unknown facts never fail anything. */
    private function mustHaveFailure(array $facts, array $attr, array $account, array $pack): ?string
    {
        foreach ((array) ($pack['must_have'] ?? []) as $rule) {
            $value = isset($rule['field'])
                ? ($account[$rule['field']] ?? null)
                : ($facts[$rule['attribute']] ?? $attr[$rule['attribute']] ?? null);
            if ($value === null || $value === '') {
                continue;   // unknown: never a failure
            }
            if (! $this->passes($rule, $value, $pack)) {
                return str_replace('{value}', is_scalar($value) ? (string) $value : '', (string) $rule['label']);
            }
        }

        return null;
    }

    private function passes(array $rule, mixed $value, array $pack): bool
    {
        $target = $rule['value'] ?? null;
        $text = static fn ($v) => strtolower(trim((string) $v));

        return match ($rule['op']) {
            '>=' => is_numeric($value) ? $value >= $target : true,
            '<=' => is_numeric($value) ? $value <= $target : true,
            '>' => is_numeric($value) ? $value > $target : true,
            '<' => is_numeric($value) ? $value < $target : true,
            'between' => is_numeric($value) ? ($value >= $target[0] && $value <= $target[1]) : true,
            '=' => $text($value) === $text($target),
            '!=' => $text($value) !== $text($target),
            'in' => in_array($text($value), array_map($text, (array) $target), true),
            'not_in' => ! in_array($text($value), array_map($text, (array) $target), true),
            'in_regions' => in_array($text($value), array_map($text, (array) ($pack['regions'] ?? [])), true),
            default => true,
        };
    }

    /** Disqualifiers nobody can read off a web page: a person sets them on the account (attributes.flags). */
    private function manualFlag(array $attr, array $pack): ?string
    {
        foreach ((array) ($attr['flags'] ?? []) as $flag) {
            if (isset($pack['manual_flags'][$flag])) {
                return (string) $pack['manual_flags'][$flag];
            }
        }

        return null;
    }

    /** 1 = watch first ... 9 = never. Facts beat guesses; a name that looks like a better fit is the tie-breaker. */
    private function watchPriority(string $status, array $facts, array $account, array $pack): int
    {
        if ($status === 'disqualified') {
            return 9;
        }
        if ($status === 'watch') {
            return 3;   // switches only at renewal: checked rarely, never first
        }
        $city = strtolower(trim((string) ($account['city'] ?? '')));
        $cityHit = $city !== '' && in_array($city, array_map('strtolower', (array) ($pack['priority_cities'] ?? [])), true);
        $hint = IcpPhrases::matches((array) ($pack['watch_first_phrases'] ?? []), (string) ($account['name'] ?? ''));
        $hasDetail = ! empty($account['website']) || ! empty($account['level']);
        $top = (array) ($pack['criteria'][0] ?? []);
        $firstValue = isset($top['key']) ? ($facts[$top['key']] ?? null) : null;
        $strongest = is_numeric($firstValue) && $this->points($top, $firstValue) === (int) ($top['max'] ?? -1);

        return match (true) {
            $status === 'candidate' && $strongest, $hint => 1,
            $status === 'candidate', $hasDetail, $cityHit => 2,
            default => 3,
        };
    }

    /** A watch rule that holds, as its reason (and when to look again), or null. Unknown facts never trigger one. */
    private function watchRule(array $facts, array $attr, array $account, array $pack, Carbon $now): ?array
    {
        foreach ((array) ($pack['watch_rules'] ?? []) as $rule) {
            $value = isset($rule['field'])
                ? ($account[$rule['field']] ?? null)
                : ($facts[$rule['attribute']] ?? $attr[$rule['attribute']] ?? null);
            if ($value === null || $value === '') {
                continue;
            }
            if ($rule['op'] === 'within_days') {
                try {
                    $when = Carbon::parse((string) $value);
                } catch (\Throwable) {
                    continue;
                }
                $days = (int) $rule['value'];
                if (($now->getTimestamp() - $when->getTimestamp()) / 86400 <= $days) {
                    return ['reason' => (string) $rule['label'], 'recheck_after' => $when->copy()->addDays($days)->toDateString()];
                }

                continue;
            }
            if ($this->passes($rule, $value, $pack)) {
                return ['reason' => (string) $rule['label'], 'recheck_after' => null];
            }
        }

        return null;
    }

    /**
     * Points for an evidence criterion that is COUNTED (how many triggers in the last 90 days, how many bundle parts show
     * a pain), from the ICP's `evidence_rules`: the highest step whose `min` the count reaches.
     */
    public function evidencePoints(string $key, int $count, array $pack): int
    {
        $steps = (array) ($pack['evidence_rules'][$key] ?? []);
        usort($steps, fn ($a, $b) => $b['min'] <=> $a['min']);
        foreach ($steps as $s) {
            if ($count >= (int) $s['min']) {
                return (int) $s['points'];
            }
        }

        return 0;
    }

    /**
     * The full 100-point score and the priority band, from the account's stored facts plus VERIFIED evidence points.
     * `$evidence` maps each evidence criterion to the points awarded (judged ones directly; counted ones via
     * evidencePoints()). A value above its maximum is capped, never trusted. Only a CANDIDATE gets a priority: an
     * account that is unscored, on watch or disqualified is not ranked.
     *
     * @param  array<string, int>  $evidence
     * @return array{status: string, static_points: int, evidence_points: int, total: int, priority: ?int, pursue: bool}
     */
    public function total(array $account, array $evidence, array $pack, ?Carbon $now = null): array
    {
        $score = $this->score($account, $pack, $now);
        $ev = 0;
        foreach ((array) ($pack['evidence_points'] ?? []) as $key => $max) {
            $ev += max(0, min((int) $max, (int) ($evidence[$key] ?? 0)));
        }
        $total = $score['static_points'] + $ev;

        $priority = null;
        if ($score['status'] === 'candidate') {
            $bands = (array) ($pack['priority'] ?? []);
            ksort($bands);
            foreach ($bands as $band => $min) {
                if ($total >= (int) $min) {
                    $priority = (int) $band;

                    break;
                }
            }
        }

        return ['status' => $score['status'], 'static_points' => $score['static_points'], 'evidence_points' => $ev, 'total' => $total, 'priority' => $priority, 'pursue' => $priority !== null];
    }

    /**
     * One value per criterion: stored attribute, else the account's own field, else derived from another field; then any
     * override (a value rewritten, or set to unknown so an open question never scores either way).
     *
     * @return array{0: array<string, mixed>, 1: list<string>} facts, criteria whose value an override made unknown
     */
    private function facts(array $account, array $attr, array $pack): array
    {
        $facts = [];
        $open = [];
        foreach ((array) ($pack['criteria'] ?? []) as $def) {
            $key = (string) $def['key'];
            $value = $attr[$key] ?? $account[$key] ?? null;
            if (($value === null || $value === '') && isset($def['derive'])) {
                $from = $attr[$def['derive']['from']] ?? $account[$def['derive']['from']] ?? null;
                $value = $from === null ? null : ($def['derive']['map'][strtolower(trim((string) $from))] ?? null);
            }
            if (is_string($value)) {
                $value = trim($value) === '' ? null : trim($value);
            }
            foreach ((array) ($def['value_overrides'] ?? []) as $o) {
                if (is_string($value) && strcasecmp($value, (string) $o['when_value']) === 0 && $this->overrideApplies($o, $attr)) {
                    $value = $o['then'];
                    if ($value === null) {
                        $open[] = $key;
                    }

                    break;
                }
            }
            $facts[$key] = $value;
        }

        return [$facts, $open];
    }

    private function overrideApplies(array $o, array $attr): bool
    {
        foreach ((array) ($o['attribute_equals'] ?? []) as $k => $v) {
            if (strtolower(trim((string) ($attr[$k] ?? ''))) !== strtolower(trim((string) $v))) {
                return false;
            }
        }

        return true;
    }

    private function points(array $def, mixed $value): int
    {
        if (isset($def['values'])) {
            foreach ($def['values'] as $k => $pts) {
                if (strcasecmp((string) $k, (string) $value) === 0) {
                    return (int) $pts;
                }
            }

            return 0;
        }
        foreach ((array) ($def['rules'] ?? []) as $rule) {
            [$lo, $hi] = $rule['range'];
            if (is_numeric($value) && $value >= $lo && $value <= $hi) {
                return (int) $rule['points'];
            }
        }

        return 0;
    }
}
