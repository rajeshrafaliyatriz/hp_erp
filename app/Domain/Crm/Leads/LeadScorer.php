<?php

namespace App\Domain\Crm\Leads;

use Illuminate\Support\Facades\DB;

/**
 * Sales & Marketing gap-closure, Wave 2: rule-based (not predictive/AI) lead
 * scoring. Every active rule for the tenant is evaluated against the lead's
 * CURRENT attribute values (not a frozen snapshot); matching rules sum their
 * points. `score()` is the only place this happens, called from
 * LeadFactory::create() (every new lead) and CrmLeadController::update()
 * (every edit) - never trusted from a client payload, same "server
 * recomputes" rule the rest of this module follows for Opportunities/
 * Invoices.
 *
 * `basis` is returned alongside the total so the caller can store exactly
 * which rule(s) fired - lead scoring here is explainable by construction,
 * not a black-box number (the recommended practice this gap-closure effort
 * is working from; see the gap analysis in Docs/sales-marketing-merge).
 */
final class LeadScorer
{
    /** @var string[] field_key values a rule may reference - never an arbitrary client-supplied column name. */
    public const ALLOWED_FIELDS = ['lead_source', 'industry', 'country', 'rating', 'lead_status', 'annual_revenue', 'number_of_emp'];

    /** @var string[] */
    public const OPERATORS = ['equals', 'not_equals', 'contains', 'is_not_empty', 'greater_than', 'less_than'];

    /** @var string[] operators that only make sense against a numeric field. */
    public const NUMERIC_OPERATORS = ['greater_than', 'less_than'];

    /**
     * @param  array<string, mixed>  $attributes  Lead attributes as they will stand AFTER the write (snake_case columns).
     * @return array{total: int, basis: array<int, array<string, mixed>>}
     */
    public static function score(array $attributes, int $tenantId): array
    {
        $rules = DB::table('crm_lead_scoring_rules')
            ->where('sub_institute_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $total = 0;
        $basis = [];

        foreach ($rules as $rule) {
            if (! self::matches($rule, $attributes)) {
                continue;
            }

            $total += (int) $rule->points;
            $basis[] = [
                'ruleId' => (string) $rule->id,
                'fieldKey' => $rule->field_key,
                'operator' => $rule->operator,
                'value' => $rule->value,
                'points' => (int) $rule->points,
            ];
        }

        return ['total' => $total, 'basis' => $basis];
    }

    private static function matches(object $rule, array $attributes): bool
    {
        if (! in_array($rule->field_key, self::ALLOWED_FIELDS, true)) {
            return false;
        }

        $value = $attributes[$rule->field_key] ?? null;

        return match ($rule->operator) {
            'is_not_empty' => $value !== null && trim((string) $value) !== '',
            'equals' => $value !== null && $rule->value !== null
                && mb_strtolower(trim((string) $value)) === mb_strtolower(trim((string) $rule->value)),
            'not_equals' => $value === null || $rule->value === null
                || mb_strtolower(trim((string) $value)) !== mb_strtolower(trim((string) $rule->value)),
            'contains' => $value !== null && $rule->value !== null
                && mb_stripos((string) $value, (string) $rule->value) !== false,
            'greater_than' => $value !== null && $rule->value !== null && is_numeric($value) && is_numeric($rule->value)
                && (float) $value > (float) $rule->value,
            'less_than' => $value !== null && $rule->value !== null && is_numeric($value) && is_numeric($rule->value)
                && (float) $value < (float) $rule->value,
            default => false,
        };
    }
}
