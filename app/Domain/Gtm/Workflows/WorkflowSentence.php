<?php

namespace App\Domain\Gtm\Workflows;

/**
 * A workflow as ONE plain sentence, for a list a salesperson reads ("When a lead replies and "What the reply says" is interested, read the customer's
 * reply and create a follow-up task for the lead; needs your approval"). Pure: it only reads the catalogue, so it cannot say something the engine will not do.
 *
 * The words come from the catalogue and nowhere else: the trigger's `phrase`, each condition's fact label and each action's label (its first letter
 * lowered, because every action label is a verb phrase). Field labels are put in double quotes so an odd label never has to fit a grammar. A value shows
 * as a person would say it: yes / no for a switch, its words without underscores, a list joined by commas.
 */
final class WorkflowSentence
{
    /** the eleven condition operators, as words */
    public const OPERATOR_WORDS = [
        'eq' => 'is', 'neq' => 'is not', 'gt' => 'is more than', 'gte' => 'is at least', 'lt' => 'is less than', 'lte' => 'is at most',
        'in' => 'is one of', 'not_in' => 'is not one of', 'contains' => 'contains', 'empty' => 'is empty', 'not_empty' => 'is not empty',
    ];

    /** @var ?array<string, array<string, mixed>> */
    private static ?array $triggers = null;

    /**
     * @param  list<array<string, mixed>>  $conditions
     * @param  list<array<string, mixed>>  $actions
     */
    public static function build(string $trigger, array $conditions, array $actions, bool $needsApproval): string
    {
        $text = 'When '.self::trigger($trigger);
        foreach ($conditions as $c) {
            if (is_array($c) && isset($c['field'], $c['op'])) {
                $text .= ' and '.self::condition($trigger, $c);
            }
        }
        $labels = [];
        foreach ($actions as $a) {
            $labels[] = self::actionPhrase(is_array($a) ? (string) ($a['type'] ?? '') : '');
        }
        if ($labels !== []) {
            $last = array_pop($labels);
            $text .= ', '.($labels === [] ? $last : implode(', ', $labels).' and '.$last);
        }

        return $text.($needsApproval ? '; needs your approval' : '');
    }

    /** What follows "When": the trigger's phrase, or its label in lower case when the catalogue has no phrase for it. */
    public static function trigger(string $trigger): string
    {
        $t = self::triggers()[$trigger] ?? null;

        return $t['phrase'] ?? ($t !== null ? mb_strtolower((string) $t['label']) : self::plain($trigger));
    }

    /** @param array<string, mixed> $c a condition: field, op and (except for empty / not empty) value */
    public static function condition(string $trigger, array $c): string
    {
        $field = (string) $c['field'];
        $label = (string) (self::triggers()[$trigger]['fields'][$field]['label'] ?? self::plain($field));
        $op = (string) $c['op'];
        $words = self::OPERATOR_WORDS[$op] ?? self::plain($op);
        if (in_array($op, ['empty', 'not_empty'], true)) {
            return "\"{$label}\" {$words}";
        }

        return "\"{$label}\" {$words} ".self::value($c['value'] ?? null);
    }

    /** One action as a verb phrase: its catalogue label with the first letter lowered ("Create a task ..." -> "create a task ..."). */
    public static function actionPhrase(string $type): string
    {
        $label = WorkflowCatalog::actions()[$type]['label'] ?? self::plain($type);

        return self::lowerFirst($label);
    }

    /** a fact value as a person says it: yes / no, words without underscores, a list joined by commas */
    public static function value(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'yes' : 'no';
        }
        if (is_array($v)) {
            return implode(', ', array_map(fn ($x) => self::value($x), $v));
        }
        if ($v === null) {
            return '';
        }

        return str_replace('_', ' ', (string) $v);
    }

    /** lower the first letter, unless the word is an acronym ("ICP fit score" stays) */
    private static function lowerFirst(string $s): string
    {
        $second = mb_substr($s, 1, 1);

        return $second !== '' && $second === mb_strtoupper($second) && $second !== mb_strtolower($second) ? $s : mb_strtolower(mb_substr($s, 0, 1)).mb_substr($s, 1);
    }

    private static function plain(string $id): string
    {
        return str_replace(['_', '.'], ' ', $id);
    }

    /** @return array<string, array<string, mixed>> */
    private static function triggers(): array
    {
        return self::$triggers ??= WorkflowCatalog::triggers();
    }
}
