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
     * @param array<string, ReportJoinDefinition> $joins keyed by target module key
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
