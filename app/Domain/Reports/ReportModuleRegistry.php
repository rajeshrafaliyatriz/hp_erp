<?php

namespace App\Domain\Reports;

use Illuminate\Support\Facades\DB;

/**
 * THE SINGLE SOURCE OF TRUTH FOR "WHAT CAN A REPORT BE BUILT FROM."
 *
 * Sibling to App\Domain\AI\Reports\ModuleDataSourceCatalog, deliberately not
 * an extension of it: that catalogue is admin-curated, fixed SQL authored in
 * PHP for the AI Stack's Templates tab. This registry instead describes
 * modules/fields/joins DECLARATIVELY, so an end user can pick arbitrary
 * columns/filters/groupings and the engine (ReportQueryExecutionService)
 * turns that into SQL itself — the same shape of problem
 * ModuleDataSourceCatalog solves differently for a different need.
 *
 * Hand-authored PHP metadata, not DB introspection — on purpose. A picker
 * backed by "every real column in information_schema" would leak internal
 * columns (deleted_by, raw FK ids) and be a SQL-injection-shaped surface.
 * Vtiger's own Reports module (the system this is modeled on) is exactly
 * this: admin/install-time curated fields (vtiger_field), not discovery.
 *
 * Module keys are dotted strings: "task_management.tasks", "crm.leads". Each
 * domain contributes its own definitions() array; this class only merges
 * them (same pattern as ModuleDataSourceCatalog::definitions() concatenating
 * $core with SourceRegistry::definitions()). Phase 1 adds Task Management's
 * definitions; Phase 3 adds CRM's — this class itself does not change.
 */
final class ReportModuleRegistry
{
    /** @var array<string, ReportModuleDefinition>|null */
    private static ?array $cache = null;

    /** @return array<string, ReportModuleDefinition> */
    public function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $definitions = [];

        foreach (\App\Domain\Reports\TaskManagement\TaskManagementReportModules::definitions() as $def) {
            $definitions[$def->key] = $def;
        }

        foreach (\App\Domain\Reports\Crm\CrmReportModules::definitions() as $def) {
            $definitions[$def->key] = $def;
        }

        return self::$cache = $definitions;
    }

    public function get(string $moduleKey): ReportModuleDefinition
    {
        $all = $this->all();

        if (!isset($all[$moduleKey])) {
            throw new \InvalidArgumentException("Unknown report module: {$moduleKey}");
        }

        return $all[$moduleKey];
    }

    public function exists(string $moduleKey): bool
    {
        return isset($this->all()[$moduleKey]);
    }

    /** @return string[] module keys this primary module declares a join to */
    public function joinableFrom(string $primaryModuleKey): array
    {
        return array_keys($this->get($primaryModuleKey)->joins);
    }

    /** Every field for a module, including that tenant's active custom fields folded in as virtual, display-only fields. */
    public function fieldsFor(string $moduleKey, int $subInstituteId): array
    {
        return array_merge($this->get($moduleKey)->fields, $this->customFieldsFor($moduleKey, $subInstituteId));
    }

    /** @return ReportFieldDefinition[] */
    private function customFieldsFor(string $moduleKey, int $subInstituteId): array
    {
        $module = $this->get($moduleKey);

        return DB::table('tblcustom_fields')
            ->where('table_name', $module->table)
            ->where('status', 1)
            ->where('is_deleted', 'N')
            ->where(function ($query) use ($subInstituteId) {
                $query->where('sub_institute_id', $subInstituteId)->orWhere('common_to_all', 1);
            })
            ->get(['id', 'field_name', 'field_label', 'column_header'])
            ->map(fn ($row) => ReportFieldDefinition::customField(
                (string) $row->field_name,
                (string) ($row->field_label ?: $row->column_header ?: $row->field_name),
                (int) $row->id,
            ))
            ->all();
    }

    /** @return ReportFieldDefinition[] */
    public function dateFieldsFor(string $moduleKey, int $subInstituteId): array
    {
        return array_values(array_filter($this->fieldsFor($moduleKey, $subInstituteId), fn ($f) => $f->type === 'date'));
    }

    /** @return ReportFieldDefinition[] */
    public function groupableFieldsFor(string $moduleKey, int $subInstituteId): array
    {
        return array_values(array_filter($this->fieldsFor($moduleKey, $subInstituteId), fn ($f) => $f->groupable));
    }

    /** @return ReportFieldDefinition[] */
    public function numericFieldsFor(string $moduleKey, int $subInstituteId): array
    {
        return array_values(array_filter($this->fieldsFor($moduleKey, $subInstituteId), fn ($f) => $f->aggregatable));
    }

    /**
     * Resolve a {module, field} reference (exactly the shape every field
     * reference takes in a report's `definition` JSON - see
     * Docs/reports-engine/PLAN.md §2) to its ReportFieldDefinition, or null
     * if either half doesn't exist.
     *
     * Deliberately NOT a single concatenated "module.field" string: module
     * keys are themselves dotted ("task_management.tasks") and so are
     * custom-field keys ("custom.estimated_value") - splitting on "the last
     * dot" is ambiguous the moment a custom field is involved. Two explicit
     * arguments have no such ambiguity.
     */
    public function resolve(string $moduleKey, string $fieldKey, int $subInstituteId): ?ReportFieldDefinition
    {
        if (!$this->exists($moduleKey)) {
            return null;
        }

        foreach ($this->fieldsFor($moduleKey, $subInstituteId) as $field) {
            if ($field->key === $fieldKey) {
                return $field;
            }
        }

        return null;
    }
}
