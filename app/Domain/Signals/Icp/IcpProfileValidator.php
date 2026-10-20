<?php

namespace App\Domain\Signals\Icp;

/**
 * Validates an organisation's ICP configuration before it can be saved. Pure.
 *
 * An ICP is data an organisation edits, so everything is bounded and typed: sizes, counts, vocabularies, and no
 * raw patterns anywhere (phrases only). Unknown settings are rejected: anything not listed here is never read,
 * so it must not be accepted. Returns a list of human-readable problems; an empty list means the ICP is valid.
 */
final class IcpProfileValidator
{
    public const SOURCES = ['news', 'job', 'tender', 'leadership', 'regulation', 'social', 'concept', 'other'];
    public const OPS = ['>=', '<=', '>', '<', 'between', 'in', 'not_in', '=', '!=', 'in_regions'];
    public const KEYS = [
        'label', 'pitch', 'competitors', 'product_line', 'industry_label', 'guidance', 'watch_rules', 'vendor_phrases', 'vendor_news_phrases', 'priority_cities', 'evidence_rules', 'tiers', 'buyer_types', 'regions', 'parts', 'must_have', 'disqualifiers', 'manual_flags',
        'watch_first_phrases', 'criteria', 'evidence_points', 'priority', 'min_known_share', 'triggers', 'queries', 'per_run',
        'account_query', 'account_days', 'account_recheck_days', 'decay_by_trigger_type', 'buying_window_months', 'buying_centre',
        'offer_ids',
    ];
    private const MAX_BYTES = 65536;

    /** @param array<string, mixed> $c @return list<string> */
    public function validate(array $c): array
    {
        $e = [];
        $add = function (string $path, string $msg) use (&$e) {
            $e[] = "{$path}: {$msg}";
        };

        if (strlen((string) json_encode($c)) > self::MAX_BYTES) {
            return ['config: too large (limit ' . (self::MAX_BYTES / 1024) . ' KB)'];
        }
        foreach (array_diff(array_keys($c), self::KEYS) as $k) {
            $add((string) $k, 'unknown setting (it would be ignored)');
        }

        // The portfolio products this ICP is for, by Offer ID. Empty is valid and means "none chosen" (nothing is added for the person).
        if (array_key_exists('offer_ids', $c)) {
            $ids = $c['offer_ids'];
            if (! is_array($ids) || ! array_is_list($ids) || count($ids) > 60) {
                $add('offer_ids', 'a list of at most 60 Offer IDs');
            } else {
                foreach ($ids as $i => $id) {
                    if (! is_string($id) || preg_match('/^[A-Za-z0-9._\-]{1,50}$/', $id) !== 1) {
                        $add("offer_ids[{$i}]", 'an Offer ID: letters, digits, dot, dash or underscore, up to 50 characters');
                    }
                }
                if (count($ids) !== count(array_unique(array_map('strval', $ids)))) {
                    $add('offer_ids', 'each Offer ID can be listed once');
                }
            }
        }

        $this->text($c, 'label', 1, 100, true, $add);
        $this->text($c, 'industry_label', 1, 120, true, $add);
        $this->text($c, 'guidance', 0, 400, false, $add);
        if (isset($c['product_line']) && $c['product_line'] !== null && ! preg_match('/^[a-z0-9_-]{1,60}$/i', (string) $c['product_line'])) {
            $add('product_line', 'letters, digits, - and _ only (max 60)');
        }
        $this->stringList($c, 'buyer_types', 10, 40, $add, slug: true);
        $this->stringList($c, 'regions', 50, 80, $add);
        $this->stringMap($c, 'parts', 12, 200, $add);
        $this->stringMap($c, 'manual_flags', 12, 120, $add);
        $this->stringMap($c, 'buying_centre', 12, 200, $add);
        $this->phraseList($c['watch_first_phrases'] ?? [], 'watch_first_phrases', 40, $add);

        $this->phraseList($c['vendor_phrases'] ?? [], 'vendor_phrases', 60, $add);
        $this->phraseList($c['vendor_news_phrases'] ?? [], 'vendor_news_phrases', 60, $add);
        $this->stringList($c, 'priority_cities', 60, 60, $add);
        $this->stringMap($c, 'tiers', 6, 300, $add);
        $this->watchRules($c, $add);
        $this->evidenceRules($c, $add);
        $this->mustHave($c, $add);
        $this->disqualifiers($c, $add);
        $this->criteria($c, $add);
        $this->triggers($c, $add);
        $this->queries($c, $add);
        $this->misc($c, $add);

        return $e;
    }

    private function mustHave(array $c, callable $add): void
    {
        $list = $c['must_have'] ?? [];
        if (! is_array($list) || count($list) > 12) {
            $add('must_have', 'a list of at most 12 rules');

            return;
        }
        foreach ($list as $i => $r) {
            $p = "must_have[{$i}]";
            if (! is_array($r)) {
                $add($p, 'must be an object');

                continue;
            }
            $subject = ($r['attribute'] ?? null) ?? ($r['field'] ?? null);
            if (isset($r['attribute']) === isset($r['field']) || ! is_string($subject) || ! preg_match('/^[a-z0-9_]{1,40}$/i', $subject)) {
                $add($p, 'give exactly one of "attribute" or "field" (letters, digits, _)');
            }
            $op = $r['op'] ?? null;
            if (! in_array($op, self::OPS, true)) {
                $add("{$p}.op", 'one of ' . implode(', ', self::OPS));

                continue;
            }
            if ($op === 'in_regions') {
                if (empty($c['regions'])) {
                    $add("{$p}.op", 'in_regions needs the ICP to list its regions');
                }
            } elseif (! array_key_exists('value', $r)) {
                $add("{$p}.value", 'required');
            } elseif ($op === 'between' && (! is_array($r['value']) || count($r['value']) !== 2 || ! is_numeric($r['value'][0] ?? null) || ! is_numeric($r['value'][1] ?? null) || $r['value'][0] > $r['value'][1])) {
                $add("{$p}.value", 'between needs [low, high]');
            } elseif (in_array($op, ['in', 'not_in'], true) && (! is_array($r['value']) || $r['value'] === [] || count($r['value']) > 50)) {
                $add("{$p}.value", 'a non-empty list (max 50)');
            } elseif (in_array($op, ['>=', '<=', '>', '<'], true) && ! is_numeric($r['value'])) {
                $add("{$p}.value", 'a number');
            }
            $this->text($r, 'label', 1, 120, true, fn ($path, $msg) => $add("{$p}.{$path}", $msg));
            $this->text($r, 'prompt', 0, 120, false, fn ($path, $msg) => $add("{$p}.{$path}", $msg));
        }
    }

    /** Rules that keep an account on WATCH (not reject) while a fact holds. */
    private function watchRules(array $c, callable $add): void
    {
        $list = $c['watch_rules'] ?? [];
        if (! is_array($list) || count($list) > 5) {
            $add('watch_rules', 'a list of at most 5 rules');

            return;
        }
        foreach ($list as $i => $r) {
            $p = "watch_rules[{$i}]";
            $subject = is_array($r) ? (($r['attribute'] ?? null) ?? ($r['field'] ?? null)) : null;
            if (! is_array($r) || isset($r['attribute']) === isset($r['field']) || ! is_string($subject) || ! preg_match('/^[a-z0-9_]{1,40}$/i', $subject)) {
                $add($p, 'give exactly one of "attribute" or "field" (letters, digits, _)');

                continue;
            }
            $op = $r['op'] ?? null;
            if (! in_array($op, array_merge(self::OPS, ['within_days']), true) || $op === 'in_regions') {
                $add("{$p}.op", 'within_days, or a comparison such as =, in, >=');
            } elseif ($op === 'within_days' && (! is_int($r['value'] ?? null) || $r['value'] < 1 || $r['value'] > 3650)) {
                $add("{$p}.value", 'within_days needs a number of days, 1 to 3650');
            } elseif ($op !== 'within_days' && ! array_key_exists('value', $r)) {
                $add("{$p}.value", 'required');
            }
            $this->text($r, 'label', 1, 160, true, fn ($path, $msg) => $add("{$p}.{$path}", $msg));
        }
    }

    /** Points for the evidence criteria that are COUNTED (triggers, parts with a pain). */
    private function evidenceRules(array $c, callable $add): void
    {
        $rules = $c['evidence_rules'] ?? [];
        if (! is_array($rules) || count($rules) > 12) {
            $add('evidence_rules', 'a map of at most 12');

            return;
        }
        foreach ($rules as $key => $list) {
            $max = $c['evidence_points'][$key] ?? null;
            if (! is_int($max)) {
                $add("evidence_rules.{$key}", 'must name one of the evidence_points');

                continue;
            }
            if (! is_array($list) || count($list) > 6) {
                $add("evidence_rules.{$key}", 'a list of at most 6 steps');

                continue;
            }
            foreach ($list as $j => $step) {
                if (! is_int($step['min'] ?? null) || $step['min'] < 1 || ! is_int($step['points'] ?? null) || $step['points'] < 0 || $step['points'] > $max) {
                    $add("evidence_rules.{$key}[{$j}]", "needs min (1 or more) and points (0 to {$max})");
                }
            }
        }
    }

    private function disqualifiers(array $c, callable $add): void
    {
        $list = $c['disqualifiers'] ?? [];
        if (! is_array($list) || count($list) > 30) {
            $add('disqualifiers', 'a list of at most 30 rules');

            return;
        }
        foreach ($list as $i => $d) {
            $p = "disqualifiers[{$i}]";
            if (! is_array($d)) {
                $add($p, 'must be an object with label and phrases');

                continue;
            }
            $this->text($d, 'label', 1, 120, true, fn ($path, $msg) => $add("{$p}.{$path}", $msg));
            $this->phraseList($d['phrases'] ?? null, "{$p}.phrases", 30, $add, required: true);
        }
    }

    private function criteria(array $c, callable $add): void
    {
        $list = $c['criteria'] ?? [];
        if (! is_array($list) || count($list) > 12) {
            $add('criteria', 'a list of at most 12');

            return;
        }
        $seen = [];
        $sum = 0;
        foreach ($list as $i => $cr) {
            $p = "criteria[{$i}]";
            if (! is_array($cr) || ! preg_match('/^[a-z0-9_]{1,40}$/i', (string) ($cr['key'] ?? ''))) {
                $add($p, 'needs a key (letters, digits, _)');

                continue;
            }
            if (isset($seen[$cr['key']])) {
                $add("{$p}.key", 'repeated');
            }
            $seen[$cr['key']] = true;
            $this->text($cr, 'label', 1, 80, true, fn ($path, $msg) => $add("{$p}.{$path}", $msg));
            $max = $cr['max'] ?? null;
            if (! is_int($max) || $max < 0 || $max > 100) {
                $add("{$p}.max", 'a whole number 0 to 100');

                continue;
            }
            $sum += $max;
            if (isset($cr['rules']) === isset($cr['values'])) {
                $add($p, 'give exactly one of "rules" (number ranges) or "values" (exact values)');
            }
            foreach ((array) ($cr['rules'] ?? []) as $j => $rule) {
                $r = $rule['range'] ?? null;
                if (! is_array($r) || count($r) !== 2 || ! is_numeric($r[0] ?? null) || ! is_numeric($r[1] ?? null) || $r[0] > $r[1]) {
                    $add("{$p}.rules[{$j}].range", '[low, high]');
                }
                if (! is_int($rule['points'] ?? null) || $rule['points'] < 0 || $rule['points'] > $max) {
                    $add("{$p}.rules[{$j}].points", "a whole number 0 to {$max}");
                }
            }
            foreach ((array) ($cr['values'] ?? []) as $v => $pts) {
                if (! is_int($pts) || $pts < 0 || $pts > $max) {
                    $add("{$p}.values.{$v}", "points must be a whole number 0 to {$max}");
                }
            }
            if (isset($cr['derive']) && (! is_array($cr['derive']) || ! preg_match('/^[a-z0-9_]{1,40}$/i', (string) ($cr['derive']['from'] ?? '')) || ! is_array($cr['derive']['map'] ?? null) || count($cr['derive']['map']) > 50)) {
                $add("{$p}.derive", 'needs "from" (a field) and a "map" of at most 50 entries');
            }
            foreach ((array) ($cr['value_overrides'] ?? []) as $j => $o) {
                if (! is_array($o) || ! is_string($o['when_value'] ?? null) || ! array_key_exists('then', $o) || ($o['then'] !== null && ! is_string($o['then']))) {
                    $add("{$p}.value_overrides[{$j}]", 'needs when_value and then (a value, or null for unknown)');
                }
            }
        }
        $ev = $c['evidence_points'] ?? [];
        if (! is_array($ev) || count($ev) > 12) {
            $add('evidence_points', 'a map of at most 12');
        } else {
            foreach ($ev as $k => $pts) {
                if (! preg_match('/^[a-z0-9_]{1,40}$/i', (string) $k) || ! is_int($pts) || $pts < 0 || $pts > 100) {
                    $add("evidence_points.{$k}", 'a name and a whole number 0 to 100');
                } else {
                    $sum += $pts;
                }
            }
        }
        if ($sum > 100) {
            $add('criteria + evidence_points', "add up to {$sum}; the total must not exceed 100");
        }
    }

    private function triggers(array $c, callable $add): void
    {
        $list = $c['triggers'] ?? [];
        if (! is_array($list) || count($list) > 30) {
            $add('triggers', 'a map of at most 30');

            return;
        }
        foreach ($list as $key => $t) {
            $p = "triggers.{$key}";
            if (! preg_match('/^[a-z0-9_]{1,40}$/i', (string) $key) || ! is_array($t)) {
                $add($p, 'a name (letters, digits, _) and an object');

                continue;
            }
            if (! in_array($t['source'] ?? null, self::SOURCES, true)) {
                $add("{$p}.source", 'one of ' . implode(', ', self::SOURCES));
            }
            if (isset($t['part']) && ! empty($c['parts']) && ! isset($c['parts'][$t['part']])) {
                $add("{$p}.part", 'must be one of the ICP\'s parts');
            }
            if (array_key_exists('decay', $t) && $t['decay'] !== null && (! is_int($t['decay']) || $t['decay'] < 1 || $t['decay'] > 730)) {
                $add("{$p}.decay", 'days 1 to 730, or null for "until the closing date"');
            }
            $this->text($t, 'pain', 0, 160, false, fn ($path, $msg) => $add("{$p}.{$path}", $msg));
            $this->phraseList($t['phrases'] ?? null, "{$p}.phrases", 20, $add, required: true, max: 80);
        }
    }

    private function queries(array $c, callable $add): void
    {
        $list = $c['queries'] ?? [];
        if (! is_array($list) || count($list) > 40) {
            $add('queries', 'a list of at most 40');

            return;
        }
        $ids = [];
        foreach ($list as $i => $q) {
            $p = "queries[{$i}]";
            if (! is_array($q)) {
                $add($p, 'must be an object');

                continue;
            }
            if (! preg_match('/^[a-z0-9_-]{1,60}$/i', (string) ($q['id'] ?? '')) || isset($ids[$q['id']])) {
                $add("{$p}.id", 'a unique name (letters, digits, - and _)');
            }
            $ids[$q['id'] ?? ''] = true;
            if (! in_array($q['source'] ?? null, self::SOURCES, true)) {
                $add("{$p}.source", 'one of ' . implode(', ', self::SOURCES));
            }
            if (! in_array($q['topic'] ?? null, ['news', 'general'], true)) {
                $add("{$p}.topic", 'news or general');
            }
            if (! is_int($q['days'] ?? null) || $q['days'] < 1 || $q['days'] > 365) {
                $add("{$p}.days", 'days 1 to 365');
            }
            $text = (string) ($q['q'] ?? '');
            if (mb_strlen($text) < 3 || mb_strlen($text) > 300 || preg_match('/[\x00-\x1F]/', $text)) {
                $add("{$p}.q", '3 to 300 characters, single line');
            }
            $domains = $q['domains'] ?? null;
            if ($domains !== null) {
                if (! is_array($domains) || count($domains) > 20) {
                    $add("{$p}.domains", 'a list of at most 20 sites');
                } else {
                    foreach ($domains as $d) {
                        if (! is_string($d) || ! preg_match('/^(?=.{4,100}$)[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $d)) {
                            $add("{$p}.domains", "'{$d}' is not a plain site name like example.gov.in");
                        }
                    }
                }
            }
        }
    }

    private function misc(array $c, callable $add): void
    {
        $max = (int) config('signals.opportunities.max_queries', 6);
        $per = (array) ($c['per_run'] ?? []);
        foreach (['catalogue', 'accounts'] as $k) {
            if (isset($per[$k]) && (! is_int($per[$k]) || $per[$k] < 0 || $per[$k] > 6)) {
                $add("per_run.{$k}", 'a whole number 0 to 6');
            }
        }
        if ((int) ($per['catalogue'] ?? 0) + (int) ($per['accounts'] ?? 0) > $max) {
            $add('per_run', "catalogue + accounts must not exceed {$max} searches per run");
        }
        if (isset($c['account_query']) && (! is_string($c['account_query']) || ! str_contains($c['account_query'], '{name}') || mb_strlen($c['account_query']) > 300 || preg_match('/[\x00-\x1F]/', $c['account_query']))) {
            $add('account_query', 'single line, up to 300 characters, and it must contain {name}');
        }
        foreach (['account_days' => [1, 365], 'account_recheck_days' => [1, 90]] as $k => [$lo, $hi]) {
            if (isset($c[$k]) && (! is_int($c[$k]) || $c[$k] < $lo || $c[$k] > $hi)) {
                $add($k, "days {$lo} to {$hi}");
            }
        }
        if (isset($c['min_known_share']) && (! is_numeric($c['min_known_share']) || $c['min_known_share'] < 0 || $c['min_known_share'] > 1)) {
            $add('min_known_share', 'a number from 0 to 1');
        }
        foreach ((array) ($c['buying_window_months'] ?? []) as $m) {
            if (! is_int($m) || $m < 1 || $m > 12) {
                $add('buying_window_months', 'months 1 to 12');

                break;
            }
        }
        $pri = (array) ($c['priority'] ?? []);
        if (count($pri) > 5 || array_filter($pri, fn ($v) => ! is_int($v) || $v < 0 || $v > 100) !== []) {
            $add('priority', 'at most 5 bands, each a whole number 0 to 100');
        } else {
            $sorted = $pri;
            ksort($sorted);
            $vals = array_values($sorted);
            for ($i = 1; $i < count($vals); $i++) {
                if ($vals[$i] > $vals[$i - 1]) {
                    $add('priority', 'priority 1 must need the highest score, then lower');

                    break;
                }
            }
        }
        foreach ((array) ($c['decay_by_trigger_type'] ?? []) as $k => $v) {
            if (! preg_match('/^[a-z0-9_]{1,40}$/i', (string) $k) || ($v !== null && (! is_int($v) || $v < 1 || $v > 730))) {
                $add("decay_by_trigger_type.{$k}", 'days 1 to 730, or null for "until the closing date"');
            }
        }
    }

    // ── small helpers ────────────────────────────────────────────────────────

    /** @param array<string, mixed> $a */
    private function text(array $a, string $key, int $min, int $max, bool $required, callable $add): void
    {
        $v = $a[$key] ?? null;
        if ($v === null || $v === '') {
            if ($required) {
                $add($key, 'required');
            }

            return;
        }
        if (! is_string($v) || mb_strlen($v) < $min || mb_strlen($v) > $max || preg_match('/[\x00-\x1F]/', $v)) {
            $add($key, "text of {$min} to {$max} characters on one line");
        }
    }

    private function stringList(array $c, string $key, int $maxItems, int $maxLen, callable $add, bool $slug = false): void
    {
        $list = $c[$key] ?? [];
        if (! is_array($list) || count($list) > $maxItems) {
            $add($key, "a list of at most {$maxItems}");

            return;
        }
        foreach ($list as $v) {
            if (! is_string($v) || $v === '' || mb_strlen($v) > $maxLen || preg_match('/[\x00-\x1F]/', $v) || ($slug && ! preg_match('/^[a-z0-9_]+$/', $v))) {
                $add($key, $slug ? 'lowercase letters, digits and _ only' : "each item up to {$maxLen} characters");

                break;
            }
        }
    }

    private function stringMap(array $c, string $key, int $maxItems, int $maxLen, callable $add): void
    {
        $map = $c[$key] ?? [];
        if (! is_array($map) || count($map) > $maxItems) {
            $add($key, "a map of at most {$maxItems}");

            return;
        }
        foreach ($map as $k => $v) {
            if (! is_string($k) || $k === '' || mb_strlen($k) > 80 || ! is_string($v) || mb_strlen($v) > $maxLen || preg_match('/[\x00-\x1F]/', $k . $v)) {
                $add("{$key}.{$k}", "short text (value up to {$maxLen} characters)");
            }
        }
    }

    /** Phrases only: no pattern characters, so nothing can be abused as a regular expression. */
    private function phraseList(mixed $list, string $path, int $maxItems, callable $add, bool $required = false, int $max = 60): void
    {
        if ($list === null || $list === []) {
            if ($required) {
                $add($path, 'give at least one phrase');
            }

            return;
        }
        if (! is_array($list) || count($list) > $maxItems) {
            $add($path, "a list of at most {$maxItems} phrases");

            return;
        }
        foreach ($list as $p) {
            if (! is_string($p) || mb_strlen(trim($p)) < 2 || mb_strlen($p) > $max || preg_match('/[\x00-\x1F]/', $p)) {
                $add($path, "each phrase is 2 to {$max} characters");

                return;
            }
            if (preg_match(IcpPhrases::FORBIDDEN, $p)) {
                $add($path, "'{$p}' contains pattern characters; write plain words or phrases only");

                return;
            }
        }
    }
}
