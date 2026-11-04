<?php

namespace App\Domain\Crm\Sales;

/**
 * The configurable rules of the opportunity pipeline (config/crm_sales.php, slice 11): the coded loss reasons and the fields a stage can ask for. Pure reading of
 * configuration; no database in it.
 */
final class OpportunityRules
{
    public const CLOSED_LOST = 'Closed Lost';

    /** The fields a rule may ask for, and the name each goes by in the API (the key an error is filed under) and in a sentence. @var array<string, array{key: string, text: string}> */
    private const FIELDS = [
        'amount' => ['key' => 'amount', 'text' => 'an amount'],
        'closing_date' => ['key' => 'closingDate', 'text' => 'a closing date'],
        'probability' => ['key' => 'probability', 'text' => 'a probability'],
        'product_master_id' => ['key' => 'productMasterId', 'text' => 'a product'],
    ];

    /** @return array<string, string> code => label */
    public static function lossReasons(): array
    {
        $reasons = [];
        foreach ((array) config('crm_sales.loss_reasons', []) as $code => $label) {
            if (is_string($code) && $code !== '' && strlen($code) <= 30) {
                $reasons[$code] = (string) $label;
            }
        }

        return $reasons;
    }

    /** @return list<string> */
    public static function lossReasonCodes(): array
    {
        return array_keys(self::lossReasons());
    }

    /** The label of a code; a code no longer in the list is its own label (it is never hidden). @return array{code: string, label: string}|null */
    public static function lossReason(?string $code): ?array
    {
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }

        return ['code' => $code, 'label' => self::lossReasons()[$code] ?? $code];
    }

    public static function lossReasonRequired(): bool
    {
        return (bool) config('crm_sales.loss_reason_required', false);
    }

    public static function isClosedLost(?string $stage): bool
    {
        return self::same($stage, self::CLOSED_LOST);
    }

    /** Two stage names are the same stage (case and surrounding spaces do not matter). */
    public static function same(?string $a, ?string $b): bool
    {
        return mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
    }

    /**
     * The fields that must have a value before an opportunity can enter $stage, as configured: db column names. Empty when the stage has no rule (the default).
     *
     * @return list<string>
     */
    public static function requiredToEnter(?string $stage): array
    {
        $stage = mb_strtolower(trim((string) $stage));
        if ($stage === '') {
            return [];
        }

        foreach ((array) config('crm_sales.required_to_enter', []) as $name => $fields) {
            if (mb_strtolower(trim((string) $name)) === $stage) {
                return array_values(array_filter((array) $fields, fn ($f) => is_string($f) && isset(self::FIELDS[$f])));
            }
        }

        return [];
    }

    /**
     * The required fields that have no value in $effective (db column => value). A value of null, '' or only spaces is no value; 0 is one.
     *
     * @param  array<string, mixed>  $effective
     * @return list<string> db column names
     */
    public static function missing(?string $stage, array $effective): array
    {
        $missing = [];
        foreach (self::requiredToEnter($stage) as $field) {
            $value = $effective[$field] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /** The 422 body for fields that are missing: a sentence, and each missing field filed under its API name. @param list<string> $missing @return array<string, mixed> */
    public static function missingBody(string $stage, array $missing): array
    {
        $texts = array_map(fn ($f) => self::FIELDS[$f]['text'], $missing);
        $last = array_pop($texts);
        $list = $texts === [] ? $last : implode(', ', $texts) . ' and ' . $last;
        $message = 'Add ' . $list . ' before moving this to ' . $stage . '.';

        $errors = [];
        foreach ($missing as $field) {
            $errors[self::FIELDS[$field]['key']] = ['Required before this can move to ' . $stage . '.'];
        }

        return ['status' => 0, 'code' => 'fields_required', 'message' => $message, 'errors' => $errors];
    }
}
