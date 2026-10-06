<?php

namespace App\Services\Platform;

/**
 * The DSL `WorkflowChainSelector`'s own docblock has always said is missing.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS STAYS DELIBERATELY SMALL
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `field operator value` clauses joined by `AND`, e.g. `"leave_days > 5 AND
 * leave_type_id = 3"`. No `OR`, no parentheses, no functions, no string comparison.
 * A chain selector is choosing which of several real approval ladders a real request
 * walks — a mistake here silently changes who approves what. An expression language
 * rich enough to need its own test suite is a worse trade than a second chain would
 * have been, so the grammar stops exactly where it stops being obviously correct by
 * inspection.
 *
 * ── FAILS CLOSED, ALWAYS ─────────────────────────────────────────────────────
 *
 * A condition this cannot parse, that names a field the caller's context does not
 * provide, or that compares against something non-numeric NEVER matches. This is the
 * rule `WorkflowChainSelector`'s docblock stated for the "not evaluated at all" era
 * and it still holds now that evaluation is real: a chain guarded by "amount > 10000"
 * must not silently govern every request because a typo broke the parser. Fail closed
 * means fail to "this chain does not apply" — the tenant falls back exactly as it did
 * before this class existed, never to "apply anyway".
 */
class WorkflowConditionEvaluator
{
    /** Longest operators first — `>=` must be tried before the `>` it contains. */
    private const OPERATORS = ['>=', '<=', '!=', '=', '>', '<'];

    /**
     * Whether `$condition` holds against `$context`.
     *
     * An empty condition always matches — the caller already treats "no condition" as
     * unconditional and special-cases it, but this returns true for it too so nothing
     * downstream has to remember to check both places.
     *
     * @param  array<string, float|int>  $context
     */
    public function matches(string $condition, array $context): bool
    {
        $condition = trim($condition);

        if ($condition === '') {
            return true;
        }

        foreach (preg_split('/\s+AND\s+/i', $condition) as $clause) {
            if (! $this->clauseMatches(trim($clause), $context)) {
                return false;
            }
        }

        return true;
    }

    /** @param  array<string, float|int>  $context */
    private function clauseMatches(string $clause, array $context): bool
    {
        foreach (self::OPERATORS as $operator) {
            $pos = strpos($clause, $operator);

            if ($pos === false) {
                continue;
            }

            $field = trim(substr($clause, 0, $pos));
            $value = trim(substr($clause, $pos + strlen($operator)));

            if ($field === '' || $value === '' || ! is_numeric($value)) {
                return false;
            }

            if (! array_key_exists($field, $context) || ! is_numeric($context[$field])) {
                // The field is not one this caller resolves for this workflow point, or
                // the caller passed no context at all (the console listing, with no real
                // record to evaluate against). Either way: cannot be proven true.
                return false;
            }

            return $this->compare((float) $context[$field], $operator, (float) $value);
        }

        // No recognised operator anywhere in the clause — an unparseable condition.
        return false;
    }

    private function compare(float $left, string $operator, float $right): bool
    {
        return match ($operator) {
            '>=' => $left >= $right,
            '<=' => $left <= $right,
            '!=' => abs($left - $right) > PHP_FLOAT_EPSILON,
            '=' => abs($left - $right) < PHP_FLOAT_EPSILON,
            '>' => $left > $right,
            '<' => $left < $right,
            default => false,
        };
    }
}
