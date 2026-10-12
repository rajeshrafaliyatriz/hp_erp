<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Support\MenuRight;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One shared Recycle Bin across all 4 CRM modules. No generic "list trashed
 * records across modules" pattern exists elsewhere in this codebase (both
 * existing Documents trash screens are single-table), so this unions
 * crm_leads/crm_contacts/crm_organizations/crm_campaigns itself.
 *
 * Each type is gated by that module's own menu/platform right rather than
 * one shared permission - matches every other CRM list (a caller only ever
 * sees what they already have "view" rights on) and avoids over-granting a
 * caller visibility into a module they can't otherwise open.
 */
class CrmRecycleBinController extends Controller
{
    use ResolvesApiIdentity;

    /**
     * @var array<string, array{table: string, menuId: int|null, link: string|null, typeFilter?: array{string, string}}>
     *
     * `typeFilter` scopes 'products'/'services' to the right half of the
     * unified crm_products table - both menu leaves share one physical
     * table, so without this a Products-only right would leak Services'
     * trashed rows (and vice versa) into the wrong tab.
     */
    private const TYPES = [
        'leads' => ['table' => 'crm_leads', 'menuId' => 201, 'link' => null],
        'contacts' => ['table' => 'crm_contacts', 'menuId' => null, 'link' => '/module/crm/marketing/contacts'],
        'organizations' => ['table' => 'crm_organizations', 'menuId' => null, 'link' => '/module/crm/marketing/organizations'],
        'campaigns' => ['table' => 'crm_campaigns', 'menuId' => null, 'link' => '/module/crm/marketing/campaigns'],
        'opportunities' => ['table' => 'crm_opportunities', 'menuId' => null, 'link' => '/module/crm/sales/opportunities'],
        'quotes' => ['table' => 'crm_quotes', 'menuId' => null, 'link' => '/module/crm/sales/quotes'],
        'products' => ['table' => 'crm_products', 'menuId' => null, 'link' => '/module/crm/sales/products', 'typeFilter' => ['item_type', 'product']],
        'services' => ['table' => 'crm_products', 'menuId' => null, 'link' => '/module/crm/sales/services', 'typeFilter' => ['item_type', 'service']],
    ];

    /** @var array<string, string> Reverse of crm_campaign_targets.target_type. */
    private const TARGET_TYPE = [
        'leads' => 'lead',
        'contacts' => 'contact',
        'organizations' => 'organization',
    ];

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $profileId = (int) ($identity['user']->user_profile_id ?? 0);
        $tenantId = $identity['sub_institute_id'];

        $rows = [];

        foreach (self::TYPES as $type => $meta) {
            if (! $this->canOnType($profileId, $tenantId, $type, 'view')) {
                continue;
            }

            foreach ($this->trashedRows($meta['table'], $tenantId, $meta['typeFilter'] ?? null) as $row) {
                $rows[] = $this->resource($type, $row);
            }
        }

        usort($rows, fn ($a, $b) => strcmp($b['deletedAt'] ?? '', $a['deletedAt'] ?? ''));

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = max(1, (int) $request->input('page', 1));
        $total = count($rows);
        $items = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'status' => 1,
            'message' => 'Recycle Bin.',
            'data' => [
                'items' => array_values($items),
                'pagination' => [
                    'current_page' => $page,
                    'last_page' => (int) max(1, ceil($total / $perPage)),
                    'per_page' => $perPage,
                    'total' => $total,
                ],
            ],
        ]);
    }

    public function restore(Request $request, string $type, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! array_key_exists($type, self::TYPES)) {
            return response()->json(['status' => 0, 'message' => 'Unknown record type.'], 404);
        }

        $profileId = (int) ($identity['user']->user_profile_id ?? 0);

        // Restoring undoes a delete, so it is gated on the same right as
        // deleting rather than a separate "restore" permission that doesn't
        // exist anywhere in this app's rights model.
        if (! $this->canOnType($profileId, $identity['sub_institute_id'], $type, 'delete')) {
            return response()->json(['status' => 0, 'message' => 'You do not have permission to perform this action.'], 403);
        }

        $table = self::TYPES[$type]['table'];

        $existing = $this->scopedQuery($type, $table)
            ->where('id', $id)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNotNull('deleted_at')
            ->first();

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Record not found in Recycle Bin.'], 404);
        }

        DB::table($table)->where('id', $id)->update(['deleted_at' => null, 'deleted_by' => null]);

        return response()->json(['status' => 1, 'message' => 'Restored.']);
    }

    public function forceDelete(Request $request, string $type, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! array_key_exists($type, self::TYPES)) {
            return response()->json(['status' => 0, 'message' => 'Unknown record type.'], 404);
        }

        $profileId = (int) ($identity['user']->user_profile_id ?? 0);

        if (! $this->canOnType($profileId, $identity['sub_institute_id'], $type, 'delete')) {
            return response()->json(['status' => 0, 'message' => 'You do not have permission to perform this action.'], 403);
        }

        $table = self::TYPES[$type]['table'];

        $existing = $this->scopedQuery($type, $table)
            ->where('id', $id)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNotNull('deleted_at')
            ->first();

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Record not found in Recycle Bin.'], 404);
        }

        DB::transaction(function () use ($type, $table, $id) {
            // crm_campaign_targets is a polymorphic pivot with no real FK in
            // either direction - nothing cascades on its own. Clear it first
            // or a campaign's target list silently starts pointing at a
            // row that no longer exists. Opportunities/Quotes/Products/
            // Services aren't campaign-targetable types, and their own
            // junction tables (crm_opportunity_contacts/_products,
            // crm_quote_line_items) have real FK constraints that cascade
            // or null out on their own - nothing to clear by hand here.
            if ($type === 'campaigns') {
                DB::table('crm_campaign_targets')->where('campaign_id', $id)->delete();
            } elseif (array_key_exists($type, self::TARGET_TYPE)) {
                DB::table('crm_campaign_targets')
                    ->where('target_type', self::TARGET_TYPE[$type])
                    ->where('target_id', $id)
                    ->delete();
            }

            DB::table($table)->where('id', $id)->delete();
        });

        return response()->json(['status' => 1, 'message' => 'Permanently deleted.']);
    }

    private function canOnType(int $profileId, int $tenantId, string $type, string $action): bool
    {
        $meta = self::TYPES[$type];
        $menuId = $meta['menuId'] ?? MenuRight::idForAccessLink($meta['link']);

        return $menuId !== null && MenuRight::can($profileId, $menuId, $tenantId, $action);
    }

    /**
     * @param array{string, string}|null $typeFilter
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function trashedRows(string $table, int $tenantId, ?array $typeFilter = null)
    {
        $query = DB::table($table)
            ->where('sub_institute_id', $tenantId)
            ->whereNotNull('deleted_at');

        if ($typeFilter !== null) {
            $query->where($typeFilter[0], $typeFilter[1]);
        }

        return $query->orderByDesc('deleted_at')->limit(200)->get();
    }

    /** A query builder for one type, pre-scoped by its typeFilter if any. */
    private function scopedQuery(string $type, string $table): \Illuminate\Database\Query\Builder
    {
        $query = DB::table($table);
        $typeFilter = self::TYPES[$type]['typeFilter'] ?? null;

        if ($typeFilter !== null) {
            $query->where($typeFilter[0], $typeFilter[1]);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function resource(string $type, object $row): array
    {
        $name = match ($type) {
            'leads', 'contacts' => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')),
            'quotes' => $row->subject ?? '',
            default => $row->name ?? '',
        };

        $subLabel = match ($type) {
            'leads' => $row->company ?? null,
            'opportunities' => $row->sales_stage ?? null,
            'quotes' => $row->quote_no ?? null,
            'products', 'services' => $row->product_no ?? null,
            default => null,
        };

        return [
            'type' => $type,
            'id' => (string) $row->id,
            'name' => $name !== '' ? $name : '(untitled)',
            'subLabel' => $subLabel,
            'deletedAt' => $row->deleted_at,
            'deletedBy' => $row->deleted_by ? (string) $row->deleted_by : null,
        ];
    }
}
