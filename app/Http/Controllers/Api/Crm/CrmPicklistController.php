<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Support\MenuRight;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Stock + admin-edited picklist values for Leads/Contacts/Organizations/
 * Campaigns — shared across all 4 modules, so it is its own small
 * controller rather than living on one entity controller.
 *
 * `field_key` is a fixed, hardcoded real column name (lead_status,
 * industry, etc.) known at migration time, not a runtime-defined custom
 * field — so the allowed (module, field_key) pairs are a hardcoded map
 * here, not an open allowlist. A brand-new dropdown-backed field belongs
 * on the separate `tblcustom_fields` engine (see `platform_services.php`),
 * not this table.
 *
 * `value` is immutable after creation — same reasoning as
 * FieldConfigController's field_name/field_type: existing records already
 * store this exact string, and renaming it would silently orphan them.
 * Only `label` (the display text), `sort_order` and `is_default` can
 * change, plus `status` for a reversible activate/deactivate toggle —
 * there is no hard delete here, since a value a tenant stops offering may
 * still be sitting on old records and needs to keep resolving to a label.
 */
class CrmPicklistController extends Controller
{
    use ResolvesApiIdentity;

    /**
     * @var array<string, array<string>>
     *
     * 'products' covers Products AND Services - both are the same physical
     * `crm_products` table with one shared `category` column, so there is
     * deliberately no separate 'services' entry here (unlike the Recycle
     * Bin, where item_type scopes which ROWS a right-holder can see, a
     * picklist VALUE like "Software" or "Consulting" is one shared list
     * regardless of which type uses it).
     */
    private const FIELD_KEYS = [
        'leads' => ['lead_status', 'lead_source', 'rating', 'salutation', 'industry'],
        'contacts' => ['salutation', 'lead_source'],
        'organizations' => ['account_type', 'industry', 'rating'],
        'campaigns' => ['campaign_type', 'campaign_status', 'expected_response'],
        'opportunities' => ['sales_stage', 'lead_source', 'potential_type', 'forecast_category'],
        'quotes' => ['quote_stage'],
        'products' => ['category'],
    ];

    /** @var array<string, array{menuId: int|null, link: string|null}> Same shape/reasoning as CrmSavedViewController::MODULE_RIGHTS. */
    private const MODULE_RIGHTS = [
        'leads' => ['menuId' => 201, 'link' => null],
        'contacts' => ['menuId' => null, 'link' => '/module/crm/marketing/contacts'],
        'organizations' => ['menuId' => null, 'link' => '/module/crm/marketing/organizations'],
        'campaigns' => ['menuId' => null, 'link' => '/module/crm/marketing/campaigns'],
        'opportunities' => ['menuId' => null, 'link' => '/module/crm/sales/opportunities'],
        'quotes' => ['menuId' => null, 'link' => '/module/crm/sales/quotes'],
        'products' => ['menuId' => null, 'link' => '/module/crm/sales/products'],
    ];

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('crm_picklist_values')->where('status', true);

        if ($module = $request->input('module')) {
            $query->where('module', $module);
        }

        $rows = $query->orderBy('module')->orderBy('field_key')->orderBy('sort_order')->get();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row->module][$row->field_key][] = [
                'value' => $row->value,
                'label' => $row->label,
                'sortOrder' => (int) $row->sort_order,
                'isDefault' => (bool) $row->is_default,
            ];
        }

        return response()->json(['status' => 1, 'message' => 'Picklist values.', 'data' => $grouped]);
    }

    /**
     * Every (module, field_key) pair an admin is allowed to manage, plus
     * every value currently defined for it (active or not) - the admin
     * screen needs the inactive ones too, to let them be reactivated.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $profileId = (int) ($identity['user']->user_profile_id ?? 0);
        $canEditAny = false;

        foreach (array_keys(self::MODULE_RIGHTS) as $module) {
            if ($this->canEditModule($profileId, $identity['sub_institute_id'], $module)) {
                $canEditAny = true;
                break;
            }
        }

        if (! $canEditAny) {
            return response()->json(['status' => 0, 'message' => 'You do not have permission to manage picklist values.'], 403);
        }

        $rows = DB::table('crm_picklist_values')
            ->orderBy('module')->orderBy('field_key')->orderBy('sort_order')
            ->get(['id', 'module', 'field_key', 'value', 'label', 'sort_order', 'is_default', 'status']);

        return response()->json([
            'status' => 1,
            'message' => 'Picklist values.',
            'data' => [
                'fieldKeys' => self::FIELD_KEYS,
                'rows' => $rows->map(fn ($row) => $this->resource($row))->all(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'module' => 'required|string|in:' . implode(',', array_keys(self::FIELD_KEYS)),
            'fieldKey' => 'required|string|max:50',
            'value' => 'required|string|max:100',
            'label' => 'required|string|max:100',
            'sortOrder' => 'nullable|integer|min:0',
            'isDefault' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $module = $request->input('module');
        $fieldKey = $request->input('fieldKey');

        $profileId = (int) ($identity['user']->user_profile_id ?? 0);

        if (! $this->canEditModule($profileId, $identity['sub_institute_id'], $module)) {
            return response()->json(['status' => 0, 'message' => 'You do not have permission to manage picklist values.'], 403);
        }

        if (! in_array($fieldKey, self::FIELD_KEYS[$module] ?? [], true)) {
            return response()->json(['status' => 0, 'message' => "'{$fieldKey}' is not a recognized field for {$module}."], 422);
        }

        $isDefault = $request->boolean('isDefault');

        try {
            $id = DB::transaction(function () use ($request, $module, $fieldKey, $isDefault) {
                if ($isDefault) {
                    $this->clearDefaults($module, $fieldKey);
                }

                return DB::table('crm_picklist_values')->insertGetId([
                    'module' => $module,
                    'field_key' => $fieldKey,
                    'value' => $request->input('value'),
                    'label' => $request->input('label'),
                    'sort_order' => (int) $request->input('sortOrder', 0),
                    'is_default' => $isDefault,
                    'status' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                return response()->json(['status' => 0, 'message' => 'That value already exists for this field.'], 422);
            }

            throw $e;
        }

        $row = DB::table('crm_picklist_values')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'Value added.', 'data' => $this->resource($row)], 201);
    }

    /** Partial update: label, sort order, default flag, and the active/inactive toggle - module/field_key/value never change once created. */
    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = DB::table('crm_picklist_values')->where('id', $id)->first();

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Picklist value not found.'], 404);
        }

        $profileId = (int) ($identity['user']->user_profile_id ?? 0);

        if (! $this->canEditModule($profileId, $identity['sub_institute_id'], $existing->module)) {
            return response()->json(['status' => 0, 'message' => 'You do not have permission to manage picklist values.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'label' => 'sometimes|required|string|max:100',
            'sortOrder' => 'sometimes|required|integer|min:0',
            'isDefault' => 'sometimes|required|boolean',
            'status' => 'sometimes|required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        DB::transaction(function () use ($request, $existing, $id) {
            if ($request->has('isDefault') && $request->boolean('isDefault')) {
                $this->clearDefaults($existing->module, $existing->field_key);
            }

            $data = ['updated_at' => now()];

            if ($request->has('label')) {
                $data['label'] = $request->input('label');
            }
            if ($request->has('sortOrder')) {
                $data['sort_order'] = (int) $request->input('sortOrder');
            }
            if ($request->has('isDefault')) {
                $data['is_default'] = $request->boolean('isDefault');
            }
            if ($request->has('status')) {
                $data['status'] = $request->boolean('status');
            }

            DB::table('crm_picklist_values')->where('id', $id)->update($data);
        });

        $row = DB::table('crm_picklist_values')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'Value updated.', 'data' => $this->resource($row)]);
    }

    private function canEditModule(int $profileId, int $tenantId, string $module): bool
    {
        $meta = self::MODULE_RIGHTS[$module] ?? null;

        if (! $meta) {
            return false;
        }

        $menuId = $meta['menuId'] ?? MenuRight::idForAccessLink($meta['link']);

        return $menuId !== null && MenuRight::can($profileId, $menuId, $tenantId, 'edit');
    }

    private function clearDefaults(string $module, string $fieldKey): void
    {
        DB::table('crm_picklist_values')
            ->where('module', $module)
            ->where('field_key', $fieldKey)
            ->update(['is_default' => false]);
    }

    /** @return array<string, mixed> */
    private function resource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'module' => $row->module,
            'fieldKey' => $row->field_key,
            'value' => $row->value,
            'label' => $row->label,
            'sortOrder' => (int) $row->sort_order,
            'isDefault' => (bool) $row->is_default,
            'status' => (bool) $row->status,
        ];
    }
}
