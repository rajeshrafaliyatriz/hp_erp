<?php

namespace App\Http\Controllers\Platform;

use App\Services\Platform\PlatformRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Custom fields, over the `tblcustom_fields` table that has existed for a year with no
 * API in front of it.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE ALLOWLIST, AND WHY IT IS THE POINT OF THIS CLASS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * LMS K-12's equivalent, `CustomFieldApiController::ensureColumnExists()`, runs
 *
 *     ALTER TABLE <table_name> ADD COLUMN <field_name> VARCHAR NULL
 *
 * where `table_name` is validated only as `required|string|max:50`. There is no
 * allowlist. Any authorised caller can add a column to ANY table in that schema —
 * including the ones holding credentials and rights — and because the DDL is global
 * while the field row is tenant-scoped, one tenant's configuration silently alters
 * every tenant's table.
 *
 * This port does not do that. `config('platform_services.custom_field_tables')` names
 * the tables a field may be attached to, and a request naming anything else is refused
 * whatever it claims. The list is deliberately short — one table — because the honest
 * default is that nothing is writable until somebody has thought about it.
 *
 * ── AND IT STILL DOES NOT RUN DDL ───────────────────────────────────────────
 *
 * Even allowlisted, this controller adds no columns. `tblcustom_fields` describes a
 * field and `tblfields_data` holds its options; the VALUES live in the existing
 * key-value store the legacy screens already use. Adding a real column per custom field
 * is a schema change per tenant per field, which is how a product ends up unable to
 * migrate. The allowlist exists to bound a future that needs it, not to enable one now.
 */
class FieldConfigController extends PlatformController
{
    private const TABLE = 'tblcustom_fields';
    private const OPTIONS = 'tblfields_data';

    /** What `field_type` may be. Anything else has no renderer on either side. */
    private const TYPES = ['text', 'textarea', 'number', 'date', 'checkbox', 'dropdown', 'file'];

    /** The types that carry a list of options. */
    private const OPTION_TYPES = ['checkbox', 'dropdown'];

    public function __construct(private readonly PlatformRegistry $registry)
    {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $scope = $this->scope($request);

            if (! Schema::hasTable(self::TABLE)) {
                return $this->success('Custom fields.', ['rows' => [], 'installed' => false, 'tables' => []]);
            }

            $rows = DB::table(self::TABLE)
                /*
                 * `common_to_all` rows belong to the platform, not to a tenant, and are
                 * readable by every organisation. They are returned with `editable`
                 * false rather than hidden: a field somebody can see on a form and
                 * cannot find on this screen reads as a missing feature.
                 */
                ->where(function ($query) use ($scope) {
                    $query->where('sub_institute_id', $scope->selectedInstituteId)
                        ->orWhere('common_to_all', 1);
                })
                ->where('status', 1)
                ->where('is_deleted', 'N')
                ->orderBy('table_name')
                ->orderBy('sort_order')
                ->get();

            $options = $this->optionsFor($rows->pluck('id')->all());

            return $this->success('Custom fields.', [
                'installed' => true,
                'tables' => $this->registry->customFieldTableOptions(),
                'rows' => $rows->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'table_name' => $row->table_name,
                    'table_alias' => $row->table_alias,
                    'field_name' => $row->field_name,
                    'field_label' => $row->field_label,
                    'column_header' => $row->column_header,
                    'field_type' => $row->field_type,
                    'field_message' => $row->field_message,
                    'user_type' => $row->user_type,
                    'required' => (bool) $row->required,
                    'common_to_all' => (bool) $row->common_to_all,
                    'sort_order' => (int) $row->sort_order,
                    'tab_sort_order' => $row->tab_sort_order === null ? null : (int) $row->tab_sort_order,
                    'file_size_max' => $row->file_size_max,
                    // A platform-wide field is not this organisation's to change.
                    'editable' => ! (bool) $row->common_to_all,
                    'options' => $options[(int) $row->id] ?? [],
                ])->all(),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $scope = $this->scope($request);

            $table = trim((string) $request->input('table_name', ''));

            // THE GATE. See the class note.
            if (! $this->registry->allowsCustomFieldTable($table)) {
                return $this->failure(
                    'Custom fields cannot be added to that record.',
                    422,
                    ['table_name' => ['This platform does not allow custom fields on "' . $table . '".']]
                );
            }

            $problem = $this->validateField($request);

            if ($problem !== null) {
                return $this->failure($problem, 422, ['field' => [$problem]]);
            }

            $fieldName = $this->slug((string) $request->input('field_name'));

            $clash = DB::table(self::TABLE)
                ->where('table_name', $table)
                ->where('field_name', $fieldName)
                ->where('is_deleted', 'N')
                ->where(function ($query) use ($scope) {
                    $query->where('sub_institute_id', $scope->selectedInstituteId)
                        ->orWhere('common_to_all', 1);
                })
                ->exists();

            if ($clash) {
                return $this->failure('A field with that name already exists on this record.', 422, [
                    'field_name' => ['Already in use.'],
                ]);
            }

            $id = DB::table(self::TABLE)->insertGetId([
                'table_name' => $table,
                'table_alias' => $this->text($request, 'table_alias', 10),
                'field_name' => $fieldName,
                'field_label' => $this->text($request, 'field_label', 50),
                'column_header' => $this->text($request, 'column_header', 50),
                'field_type' => (string) $request->input('field_type'),
                'field_message' => $this->text($request, 'field_message', 50),
                'user_type' => $this->text($request, 'user_type', 50),
                'file_size_max' => $this->text($request, 'file_size_max', 50),
                'required' => (int) (bool) $request->input('required', false),
                // NOT settable by a tenant. A platform-wide field affects every
                // organisation, and this endpoint is scoped to one.
                'common_to_all' => 0,
                'sort_order' => (int) $request->input('sort_order', 0),
                'tab_sort_order' => $request->input('tab_sort_order') === null
                    ? null
                    : (int) $request->input('tab_sort_order'),
                'status' => 1,
                'is_deleted' => 'N',
                'sub_institute_id' => $scope->selectedInstituteId,
                'created_by' => $scope->userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->replaceOptions($id, $request);

            return $this->success('Field added.', ['id' => $id], 201);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $row = $this->ownRow($scope->selectedInstituteId, $id);

            if ($row === null) {
                return $this->failure('That field no longer exists.', 404);
            }

            $problem = $this->validateField($request, $row);

            if ($problem !== null) {
                return $this->failure($problem, 422, ['field' => [$problem]]);
            }

            /*
             * `table_name`, `field_name` and `field_type` are NOT editable.
             *
             * Every one of them changes what stored values mean. Renaming a field is
             * orphaning its data; changing a text field to a dropdown makes every
             * existing value invalid. Delete and re-create is the honest path, and it is
             * honest precisely because it makes the data loss visible.
             */
            DB::table(self::TABLE)->where('id', $id)->update([
                'field_label' => $this->text($request, 'field_label', 50) ?? $row->field_label,
                'column_header' => $this->text($request, 'column_header', 50),
                'field_message' => $this->text($request, 'field_message', 50),
                'file_size_max' => $this->text($request, 'file_size_max', 50),
                'required' => (int) (bool) $request->input('required', (bool) $row->required),
                'sort_order' => (int) $request->input('sort_order', $row->sort_order),
                'tab_sort_order' => $request->input('tab_sort_order') === null
                    ? $row->tab_sort_order
                    : (int) $request->input('tab_sort_order'),
                'updated_by' => $scope->userId,
                'updated_at' => now(),
            ]);

            if ($request->has('options')) {
                $this->replaceOptions($id, $request);
            }

            return $this->success('Field updated.', ['id' => $id]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Soft delete, matching what the legacy screens do.
     *
     * The row stays so any value already captured against it can still be explained.
     * A hard delete would leave stored values pointing at a field nobody can name.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $scope = $this->scope($request);

            if ($this->ownRow($scope->selectedInstituteId, $id) === null) {
                return $this->failure('That field no longer exists.', 404);
            }

            DB::table(self::TABLE)->where('id', $id)->update([
                'status' => 0,
                'is_deleted' => 'Y',
                'deleted_by' => $scope->userId,
                'updated_at' => now(),
            ]);

            return $this->success('Field removed.', ['deleted' => $id]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * This organisation's own, editable row.
     *
     * A `common_to_all` row is excluded here even though `index` returns it: readable
     * and writable are different questions, and one tenant editing a platform-wide
     * field would change it for everybody.
     */
    private function ownRow(int $tenantId, int $id): ?object
    {
        return DB::table(self::TABLE)
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->where('common_to_all', 0)
            ->where('is_deleted', 'N')
            ->first();
    }

    private function validateField(Request $request, ?object $existing = null): ?string
    {
        $type = (string) $request->input('field_type', $existing->field_type ?? '');

        if ($existing === null) {
            if (trim((string) $request->input('field_name', '')) === '') {
                return 'A field needs a name.';
            }

            if (trim((string) $request->input('field_label', '')) === '') {
                return 'A field needs a label.';
            }

            if (! in_array($type, self::TYPES, true)) {
                return 'That is not a field type this platform can render.';
            }
        }

        if (in_array($type, self::OPTION_TYPES, true)) {
            $options = $request->input('options', $existing === null ? [] : null);

            // On an edit, absent options mean unchanged — only a supplied empty list is
            // a problem.
            if ($options !== null) {
                if (! is_array($options) || count($options) === 0) {
                    return 'A ' . $type . ' field needs at least one option.';
                }

                foreach (array_values($options) as $index => $option) {
                    $label = trim((string) ($option['display_text'] ?? ''));
                    $value = trim((string) ($option['display_value'] ?? ''));

                    if ($label === '' || $value === '') {
                        return 'Option ' . ($index + 1) . ' needs both a label and a value.';
                    }
                }
            }
        }

        return null;
    }

    /**
     * Options are replaced wholesale, inside a transaction.
     *
     * Delete-then-insert rather than a diff: the list is short, ordering is positional,
     * and a diff would have to answer "is this the same option renamed, or a new one"
     * with no stable id to answer it from.
     */
    private function replaceOptions(int $fieldId, Request $request): void
    {
        if (! Schema::hasTable(self::OPTIONS)) {
            return;
        }

        $options = $request->input('options', []);

        if (! is_array($options)) {
            return;
        }

        DB::transaction(function () use ($fieldId, $options) {
            DB::table(self::OPTIONS)->where('field_id', $fieldId)->delete();

            foreach ($options as $option) {
                $label = trim((string) ($option['display_text'] ?? ''));
                $value = trim((string) ($option['display_value'] ?? ''));

                if ($label === '' || $value === '') {
                    continue;
                }

                DB::table(self::OPTIONS)->insert([
                    'field_id' => $fieldId,
                    'display_text' => mb_substr($label, 0, 50),
                    'display_value' => mb_substr($value, 0, 50),
                ]);
            }
        });
    }

    /**
     * Options for a page of fields, in one query.
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
                'id' => (int) $row->id,
                'display_text' => $row->display_text,
                'display_value' => $row->display_value,
            ];
        }

        return $out;
    }

    /** A column-safe identifier. Never interpolated into SQL, but kept clean anyway. */
    private function slug(string $value): string
    {
        return mb_substr(preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace(' ', '_', trim($value)))) ?? '', 0, 50);
    }

    private function text(Request $request, string $key, int $max): ?string
    {
        $value = trim((string) $request->input($key, ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
