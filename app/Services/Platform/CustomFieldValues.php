<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reading and writing the answers to custom fields.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A SERVICE AND NOT A CONTROLLER METHOD
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * A custom field is only worth defining if a form renders it, and the employee
 * record is the first form to do so — but it will not be the last. Putting the
 * read and the write on the employee controller would mean the second form
 * copies them, and the copy is where the tenant filter gets forgotten.
 *
 * So any form asks the same two questions here: what fields apply to this
 * record, and what are its current answers.
 *
 * ── DEFINITIONS AND ANSWERS ARE SEPARATE, DELIBERATELY ──────────────────────
 *
 * `tblcustom_fields` says a field exists; `g2g_custom_field_values` says what
 * somebody put in it. A field removed from the definition table is soft-deleted,
 * and its answers stay — deleting a field must not destroy the data captured
 * under it, because "we removed the field by mistake" is a recoverable error and
 * "we deleted everybody's answers" is not.
 *
 * The consequence to know: `valuesFor()` returns answers keyed by field id
 * including ones whose field is gone. `formFor()` joins them to live definitions
 * and so shows only what is still defined, which is what a form wants.
 */
class CustomFieldValues
{
    private const FIELDS = 'tblcustom_fields';
    private const OPTIONS = 'tblfields_data';
    private const VALUES = 'g2g_custom_field_values';

    public function __construct(private readonly PlatformRegistry $registry)
    {
    }

    /**
     * The fields that apply to a record type for a tenant, with this record's
     * answers filled in — everything a form needs to render them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function formFor(string $recordTable, int $recordId, int $tenantId): array
    {
        if (! $this->usable($recordTable)) {
            return [];
        }

        $fields = DB::table(self::FIELDS)
            /*
             * A tenant sees its own fields and the platform-wide ones.
             * `common_to_all` rows belong to the product, not to an organisation,
             * and are answered per record like any other.
             */
            ->where(function ($query) use ($tenantId) {
                $query->where('sub_institute_id', $tenantId)
                    ->orWhere('common_to_all', 1);
            })
            ->where('table_name', $recordTable)
            ->where('status', 1)
            ->where('is_deleted', 'N')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($fields->isEmpty()) {
            return [];
        }

        $values = $this->valuesFor($recordTable, $recordId);
        $options = $this->optionsFor($fields->pluck('id')->all());

        return $fields->map(fn ($field) => [
            'id' => (int) $field->id,
            'field_name' => $field->field_name,
            'field_label' => $field->field_label,
            'field_type' => $field->field_type,
            'field_message' => $field->field_message,
            'required' => (bool) $field->required,
            'sort_order' => (int) $field->sort_order,
            'options' => $options[(int) $field->id] ?? [],
            // Null means unanswered, which is different from an empty answer only
            // in a form that distinguishes them. Neither is an error.
            'value' => $values[(int) $field->id] ?? null,
        ])->values()->all();
    }

    /**
     * This record's answers, keyed by field id.
     *
     * @return array<int, string|null>
     */
    public function valuesFor(string $recordTable, int $recordId): array
    {
        if (! Schema::hasTable(self::VALUES)) {
            return [];
        }

        return DB::table(self::VALUES)
            ->where('record_table', $recordTable)
            ->where('record_id', $recordId)
            ->pluck('value', 'field_id')
            ->mapWithKeys(fn ($value, $fieldId) => [(int) $fieldId => $value])
            ->all();
    }

    /**
     * Save a record's answers.
     *
     * `$answers` is field id => value. A field not present is left alone rather
     * than blanked: a form that renders three of a record's five fields must not
     * silently erase the other two.
     *
     * @param  array<int|string, mixed>  $answers
     * @return array{saved: int, ignored: array<int, int>}
     */
    public function save(string $recordTable, int $recordId, int $tenantId, array $answers, ?int $actorId): array
    {
        if ($answers === [] || ! $this->usable($recordTable)) {
            return ['saved' => 0, 'ignored' => []];
        }

        /*
         * ONLY FIELDS THAT EXIST, ON THIS RECORD TYPE, FOR THIS TENANT.
         *
         * The ids arrive from a form, which means they arrive from the caller.
         * Without this an answer could be written against another organisation's
         * field, or against a field belonging to a different record type — and
         * the value would then surface on a form nobody expected it on.
         *
         * Unknown ids are IGNORED and reported rather than refused: a stale form
         * naming a field somebody deleted a minute ago should save the rest of its
         * answers, not fail wholesale.
         */
        $allowed = DB::table(self::FIELDS)
            ->where(function ($query) use ($tenantId) {
                $query->where('sub_institute_id', $tenantId)
                    ->orWhere('common_to_all', 1);
            })
            ->where('table_name', $recordTable)
            ->where('status', 1)
            ->where('is_deleted', 'N')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $saved = 0;
        $ignored = [];
        $now = now();

        foreach ($answers as $fieldId => $value) {
            $fieldId = (int) $fieldId;

            if (! in_array($fieldId, $allowed, true)) {
                $ignored[] = $fieldId;
                continue;
            }

            /*
             * An upsert on the unique key, so two saves race into one row rather
             * than two the next read would have to choose between.
             */
            DB::table(self::VALUES)->updateOrInsert(
                [
                    'record_table' => $recordTable,
                    'record_id' => $recordId,
                    'field_id' => $fieldId,
                ],
                [
                    'sub_institute_id' => $tenantId,
                    'value' => $value === null || $value === '' ? null : (string) $value,
                    'updated_by' => $actorId,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            $saved++;
        }

        return ['saved' => $saved, 'ignored' => $ignored];
    }

    /**
     * Whether custom fields can be used on this record type at all.
     *
     * The same allowlist the definition API enforces. Checked again here because
     * this is a second door to the same data, and a rule enforced at one door is
     * not a rule.
     */
    private function usable(string $recordTable): bool
    {
        return Schema::hasTable(self::FIELDS)
            && $this->registry->allowsCustomFieldTable($recordTable);
    }

    /**
     * Options for a set of fields, in one query.
     *
     * @param  array<int, mixed>  $fieldIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function optionsFor(array $fieldIds): array
    {
        if ($fieldIds === [] || ! Schema::hasTable(self::OPTIONS)) {
            return [];
        }

        $out = [];

        foreach (DB::table(self::OPTIONS)->whereIn('field_id', $fieldIds)->get() as $row) {
            $out[(int) $row->field_id][] = [
                'display_text' => $row->display_text,
                'display_value' => $row->display_value,
            ];
        }

        return $out;
    }
}
