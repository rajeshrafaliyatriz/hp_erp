<?php

namespace App\Domain\Reports;

/**
 * One reportable field on a module: a stable `key` (what a saved report's
 * `definition` JSON references and what never changes once a report exists),
 * a human `label` (what the column/filter picker shows), and `column` (the
 * trusted, fully-aliased SQL fragment the execution engine uses verbatim -
 * e.g. `t.task_date`). Never built from user input; only ever constructed by
 * a module's own ReportModuleDefinition, hand-authored in PHP.
 */
final class ReportFieldDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $column,
        public readonly string $type, // varchar | numeric | date | email | picklist
        public readonly bool $groupable = false,
        public readonly bool $aggregatable = false,
        public readonly ?string $picklistModule = null,
        public readonly ?string $picklistFieldKey = null,
        public readonly bool $isCustomField = false,
        public readonly ?int $customFieldId = null,
    ) {
    }

    public static function varchar(string $key, string $label, string $column, bool $groupable = true): self
    {
        return new self($key, $label, $column, 'varchar', groupable: $groupable);
    }

    public static function numeric(string $key, string $label, string $column, bool $aggregatable = true): self
    {
        return new self($key, $label, $column, 'numeric', aggregatable: $aggregatable);
    }

    public static function date(string $key, string $label, string $column): self
    {
        return new self($key, $label, $column, 'date');
    }

    public static function email(string $key, string $label, string $column): self
    {
        return new self($key, $label, $column, 'email');
    }

    /** A field whose values come from crm_picklist_values (or an equivalent fixed list) — groupable by nature. */
    public static function picklist(string $key, string $label, string $column, ?string $picklistModule = null, ?string $picklistFieldKey = null): self
    {
        return new self($key, $label, $column, 'picklist', groupable: true, picklistModule: $picklistModule, picklistFieldKey: $picklistFieldKey ?? $key);
    }

    /** A user-id column, displayed via a join to tbluser rather than as a raw id. $column should already be the joined display expression (e.g. a CONCAT of first/last name), not the raw FK. */
    public static function userRef(string $key, string $label, string $column): self
    {
        return new self($key, $label, $column, 'varchar', groupable: true);
    }

    /** A tenant's own custom field, folded in by ReportModuleRegistry::fieldsFor() — display-only, never groupable/aggregatable (see Docs/reports-engine/PLAN.md §5). */
    public static function customField(string $fieldName, string $label, int $customFieldId): self
    {
        return new self(
            key: 'custom.' . $fieldName,
            label: $label,
            column: '', // resolved at merge time by ReportQueryExecutionService, not a real SQL column
            type: 'varchar',
            isCustomField: true,
            customFieldId: $customFieldId,
        );
    }
}
