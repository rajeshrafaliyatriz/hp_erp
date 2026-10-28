<?php

namespace App\Domain\Reports;

/**
 * Everything the report engine needs to know about one reportable module:
 * its table/alias, how tenant-scoping and soft-deletes are applied (always
 * from here, NEVER from a saved report's own JSON - see
 * ReportQueryExecutionService), its fields, and how other modules may join
 * to it.
 */
final class ReportModuleDefinition
{
    /**
     * @param ReportFieldDefinition[] $fields
     * @param array<string, ReportJoinDefinition> $joins keyed by target module key — OPTIONAL,
     *        only applied when a report author picks that secondary module.
     * @param array<array{table:string,alias:string,localColumn:string,foreignColumn:string}> $alwaysJoins
     *        LEFT JOINs applied UNCONDITIONALLY to every report on this module, regardless of
     *        what the author picked — for display joins a field's own `column` expression
     *        depends on (e.g. CONCAT_WS(...) over a joined `tbluser` alias so "Assignee" shows
     *        a name instead of a raw FK id). Kept deliberately separate from `$joins`: those are
     *        a report AUTHOR's choice (bring in a whole secondary module's columns), these are
     *        the module's OWN plumbing for its own fields to render correctly.
     * @param ?string $colorByUserColumn The module's own raw assignee/owner FK column (e.g.
     *        `t.task_allocated_to`), NOT the joined display-name expression a userRef field
     *        uses — when set, ReportQueryExecutionService::runTabular() resolves each row's
     *        `__row_color` from that user's own `user_preferences.task_card_color` (the exact
     *        color Task Calendar's admin-assignable per-employee color feature already writes,
     *        reused rather than re-invented — Docs/reports-engine/PLAN.md's own Phase 4 follow-
     *        up). Null for every module with no admin-assignable per-person color concept.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $table,
        public readonly string $alias,
        public readonly string $tenantColumn,
        public readonly ?string $softDeleteColumn,
        public readonly string $primaryKey,
        public readonly array $fields,
        public readonly array $joins = [],
        public readonly array $alwaysJoins = [],
        public readonly ?string $colorByUserColumn = null,
    ) {
    }

    public function field(string $fieldKey): ?ReportFieldDefinition
    {
        foreach ($this->fields as $field) {
            if ($field->key === $fieldKey) {
                return $field;
            }
        }

        return null;
    }

    public function hasField(string $fieldKey): bool
    {
        return $this->field($fieldKey) !== null;
    }

    public function join(string $targetModuleKey): ?ReportJoinDefinition
    {
        return $this->joins[$targetModuleKey] ?? null;
    }
}
