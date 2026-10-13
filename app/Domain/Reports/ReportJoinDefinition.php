<?php

namespace App\Domain\Reports;

/**
 * How one module reaches another, declared by the module author - never
 * built from a saved report's own JSON. The execution engine has exactly
 * one fixed code path per `kind`; there is no way to express an arbitrary
 * join from a report definition.
 *
 *   direct         primary.localKey = target.foreignKey
 *   pivot          primary.id = pivot.pivotLocalKey AND pivot.pivotForeignKey = target.id
 *                  (e.g. task <-> task_management_project_tasks <-> task_management_projects)
 *   polymorphicOut primary.idColumn = target.id AND primary.typeColumn = typeValue
 *                  — THIS module owns the polymorphic pointer and joins OUT to one
 *                  concrete target type. This is the direction crm.activities (primary)
 *                  needs to reach "the lead/org/contact it's related to"
 *                  (related_id/related_type live on crm_activities itself).
 *   polymorphicIn  target.idColumn = primary.id AND target.typeColumn = typeValue
 *                  — the TARGET owns the polymorphic pointer, pointing back at this
 *                  module. This is the direction crm.leads (primary) would need to
 *                  reach "activities related to this lead" (related_id/related_type
 *                  live on crm_activities, the target here, not on crm_leads).
 *
 * Only one direction is normally registered per (primary, target) pair, chosen by
 * whichever module actually owns the polymorphic columns — never both, since that
 * would let a report author pick an invalid direction the schema doesn't support.
 */
final class ReportJoinDefinition
{
    private function __construct(
        public readonly string $targetModule,
        public readonly string $kind,
        public readonly ?string $localKey = null,
        public readonly ?string $foreignKey = null,
        public readonly ?string $viaPivot = null,
        public readonly ?string $pivotLocalKey = null,
        public readonly ?string $pivotForeignKey = null,
        public readonly ?string $polymorphicTypeColumn = null,
        public readonly ?string $polymorphicIdColumn = null,
        public readonly ?string $polymorphicTypeValue = null,
    ) {
    }

    public static function direct(string $targetModule, string $localKey, string $foreignKey): self
    {
        return new self($targetModule, 'direct', localKey: $localKey, foreignKey: $foreignKey);
    }

    public static function pivot(string $targetModule, string $viaPivot, string $pivotLocalKey, string $pivotForeignKey): self
    {
        return new self($targetModule, 'pivot', viaPivot: $viaPivot, pivotLocalKey: $pivotLocalKey, pivotForeignKey: $pivotForeignKey);
    }

    /** This module owns the polymorphic pointer (e.g. crm_activities.related_id/related_type) and joins OUT to one concrete target type. */
    public static function polymorphicOut(string $targetModule, string $typeColumn, string $idColumn, string $typeValue): self
    {
        return new self($targetModule, 'polymorphicOut', polymorphicTypeColumn: $typeColumn, polymorphicIdColumn: $idColumn, polymorphicTypeValue: $typeValue);
    }

    /** The target owns the polymorphic pointer, pointing back at this module. */
    public static function polymorphicIn(string $targetModule, string $typeColumn, string $idColumn, string $typeValue): self
    {
        return new self($targetModule, 'polymorphicIn', polymorphicTypeColumn: $typeColumn, polymorphicIdColumn: $idColumn, polymorphicTypeValue: $typeValue);
    }
}
