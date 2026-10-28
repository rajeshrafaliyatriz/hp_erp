<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Support\MenuRight;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * A small admin-managed tax-rate master list - this app had no equivalent
 * anywhere before this phase. Gated on the Quotes right (the admin surface
 * for this lives as a tab on the Master Fields console, same precedent as
 * Marketing's Picklist Admin) - Products also *references* a tax rate
 * (crm_products.tax_rate_id) but doesn't manage this list itself.
 *
 * `percentage` is immutable after creation, same reasoning as a picklist
 * value's own `value` column: crm_quote_line_items freezes a snapshot of
 * the percentage at save time specifically so editing a rate later never
 * silently changes an already-saved quote's total - allowing an in-place
 * percentage edit here would quietly defeat that guarantee for every row
 * not yet re-saved. A rate that needs a different percentage is deactivated
 * and replaced with a new one, not edited in place.
 */
class CrmTaxRateController extends Controller
{
    use ResolvesApiIdentity;

    private const QUOTES_LINK = '/module/crm/sales/quotes';

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $rows = DB::table('crm_tax_rates')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json(['status' => 1, 'message' => 'Tax rates.', 'data' => $rows->map(fn ($row) => $this->resource($row))->all()]);
    }

    /** Every rate, active or not - the admin screen needs the inactive ones too, to let them be reactivated. */
    public function adminIndex(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->canEdit($identity)) {
            return $this->forbidden();
        }

        $rows = DB::table('crm_tax_rates')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->orderBy('name')
            ->get();

        return response()->json(['status' => 1, 'message' => 'Tax rates.', 'data' => $rows->map(fn ($row) => $this->resource($row))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->canEdit($identity)) {
            return $this->forbidden();
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'percentage' => 'required|numeric|min:0|max:100',
            'isDefault' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $isDefault = $request->boolean('isDefault');

        $id = DB::transaction(function () use ($request, $identity, $isDefault) {
            if ($isDefault) {
                $this->clearDefaults($identity['sub_institute_id']);
            }

            return DB::table('crm_tax_rates')->insertGetId([
                'name' => $request->input('name'),
                'percentage' => $request->input('percentage'),
                'is_default' => $isDefault,
                'is_active' => true,
                'created_by' => $identity['user_id'],
                'sub_institute_id' => $identity['sub_institute_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $row = DB::table('crm_tax_rates')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'Tax rate added.', 'data' => $this->resource($row)], 201);
    }

    /** Partial update: name, default flag, and the active/inactive toggle - percentage never changes once created (see class docblock). */
    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->canEdit($identity)) {
            return $this->forbidden();
        }

        $existing = DB::table('crm_tax_rates')
            ->where('id', $id)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->first();

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Tax rate not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:100',
            'isDefault' => 'sometimes|required|boolean',
            'isActive' => 'sometimes|required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        DB::transaction(function () use ($request, $identity, $id) {
            if ($request->has('isDefault') && $request->boolean('isDefault')) {
                $this->clearDefaults($identity['sub_institute_id']);
            }

            $data = ['updated_at' => now()];

            if ($request->has('name')) {
                $data['name'] = $request->input('name');
            }
            if ($request->has('isDefault')) {
                $data['is_default'] = $request->boolean('isDefault');
            }
            if ($request->has('isActive')) {
                $data['is_active'] = $request->boolean('isActive');
            }

            DB::table('crm_tax_rates')->where('id', $id)->update($data);
        });

        $row = DB::table('crm_tax_rates')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'Tax rate updated.', 'data' => $this->resource($row)]);
    }

    private function clearDefaults(int $tenantId): void
    {
        DB::table('crm_tax_rates')->where('sub_institute_id', $tenantId)->update(['is_default' => false]);
    }

    /** @param array{user: object, user_id: int, sub_institute_id: int} $identity */
    private function canEdit(array $identity): bool
    {
        $profileId = (int) ($identity['user']->user_profile_id ?? 0);
        $menuId = MenuRight::idForAccessLink(self::QUOTES_LINK);

        return $menuId !== null && MenuRight::can($profileId, $menuId, $identity['sub_institute_id'], 'edit');
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => 'You do not have permission to manage tax rates.'], 403);
    }

    /** @return array<string, mixed> */
    private function resource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'name' => $row->name,
            'percentage' => (float) $row->percentage,
            'isDefault' => (bool) $row->is_default,
            'isActive' => (bool) $row->is_active,
        ];
    }
}
