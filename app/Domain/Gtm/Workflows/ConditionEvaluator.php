<?php

namespace App\Domain\Gtm\Workflows;

/**
 * Evaluates a workflow's conditions against the facts of one real record. Pure.
 *
 * All conditions must hold (AND). Each is {field, op, value}; `field` is a dot-path key into the flat
 * `values` map the context loader built from the database (e.g. `deal.stage`, `account.icp_fit_score`).
 * Comparison rules are deliberately boring: numbers compare as numbers when both sides are numeric, strings
 * compare case-insensitively, a missing fact counts as empty (so `eq`/`gt`/`contains` against it are false and
 * `empty`/`neq` are true). The result carries a per-condition trace so a person can see why a workflow did or
 * did not fire.
 */
final class ConditionEvaluator
{
    public const OPS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'contains', 'empty', 'not_empty'];

    /**
     * @param  list<array{field: string, op: string, value?: mixed}>  $conditions
     * @param  array<string, mixed>  $values
     * @return array{pass: bool, trace: list<array{field: string, op: string, expected: mixed, actual: mixed, pass: bool}>}
     */
    public static function evaluate(array $conditions, array $values): array
    {
        $trace = [];
        $pass = true;
        foreach ($conditions as $c) {
            $actual = $values[$c['field']] ?? null;
            $ok = self::test((string) $c['op'], $actual, $c['value'] ?? null);
            $trace[] = ['field' => $c['field'], 'op' => $c['op'], 'expected' => $c['value'] ?? null, 'actual' => $actual, 'pass' => $ok];
            $pass = $pass && $ok;
        }

        return ['pass' => $pass, 'trace' => $trace];
    }

    public static function test(string $op, mixed $actual, mixed $expected): bool
    {
        $isEmpty = $actual === null || $actual === '' || $actual === [];

        return match ($op) {
            'empty' => $isEmpty,
            'not_empty' => ! $isEmpty,
            'eq' => ! $isEmpty && self::equal($actual, $expected),
            'neq' => $isEmpty || ! self::equal($actual, $expected),
            'gt' => self::numeric($actual, $expected, fn ($a, $b) => $a > $b),
            'gte' => self::numeric($actual, $expected, fn ($a, $b) => $a >= $b),
            'lt' => self::numeric($actual, $expected, fn ($a, $b) => $a < $b),
            'lte' => self::numeric($actual, $expected, fn ($a, $b) => $a <= $b),
            'in' => ! $isEmpty && is_array($expected) && self::anyEqual($actual, $expected),
            'not_in' => $isEmpty || ! is_array($expected) || ! self::anyEqual($actual, $expected),
            'contains' => ! $isEmpty && is_scalar($expected) && str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            default => false, // an unknown operator never passes
        };
    }

    private static function equal(mixed $a, mixed $b): bool
    {
        if (is_bool($a) || is_bool($b)) {
            return self::bool($a) === self::bool($b);
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return is_scalar($a) && is_scalar($b) && mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
    }

    /** @param array<int, mixed> $list */
    private static function anyEqual(mixed $a, array $list): bool
    {
        foreach ($list as $x) {
            if (self::equal($a, $x)) {
                return true;
            }
        }

        return false;
    }

    private static function numeric(mixed $a, mixed $b, callable $cmp): bool
    {
        return is_numeric($a) && is_numeric($b) && $cmp((float) $a, (float) $b);
    }

    private static function bool(mixed $v): bool
    {
        return is_bool($v) ? $v : in_array(mb_strtolower((string) $v), ['1', 'true', 'yes'], true);
    }
}
