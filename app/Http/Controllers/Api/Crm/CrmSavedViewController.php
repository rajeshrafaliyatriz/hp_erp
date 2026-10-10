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
 * Saved Views - a named shortcut for a list view's current search/filter/
 * sort state, shared across all 4 CRM modules via one `module` column
 * rather than 4 near-identical controllers. A real, working version of the
 * abandoned `crm_lists` table's idea (left untouched per the plan).
 *
 * No static route middleware (the module name lives in the request, not
 * the URL), so every action resolves and checks that module's own existing
 * "view" right itself - matching CrmRecycleBinController's precedent for a
 * cross-module endpoint. "view" (not "add"/"delete") is enough for every
 * action here: a saved view is a personal shortcut onto data the caller can
 * already see, not a business record that needs edit/delete rights of its
 * own.
 */
class CrmSavedViewController extends Controller
{
    use ResolvesApiIdentity;

    private const MODULES = [
        'leads', 'contacts', 'organizations', 'campaigns',
        'opportunities', 'quotes', 'products', 'services', 'sms_log',
    ];

    /** @var array<string, array{menuId: int|null, link: string|null}> */
    private const MODULE_RIGHTS = [
        'leads' => ['menuId' => 201, 'link' => null],
        'contacts' => ['menuId' => null, 'link' => '/module/crm/marketing/contacts'],
        'organizations' => ['menuId' => null, 'link' => '/module/crm/marketing/organizations'],
        'campaigns' => ['menuId' => null, 'link' => '/module/crm/marketing/campaigns'],
        'opportunities' => ['menuId' => null, 'link' => '/module/crm/sales/opportunities'],
        'quotes' => ['menuId' => null, 'link' => '/module/crm/sales/quotes'],
        'products' => ['menuId' => null, 'link' => '/module/crm/sales/products'],
        'services' => ['menuId' => null, 'link' => '/module/crm/sales/services'],
        'sms_log' => ['menuId' => null, 'link' => '/module/crm/sales/sms-notifier'],
    ];

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'module' => 'required|string|in:' . implode(',', self::MODULES),
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $module = $request->input('module');

        if (! $this->canViewModule($identity, $module)) {
            return response()->json(['status' => 0, 'message' => 'You do not have permission to view this.'], 403);
        }

        $rows = DB::table('crm_saved_views')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->where('module', $module)
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 1,
            'message' => 'Saved views.',
            'data' => $rows->map(fn ($row) => $this->resource($row))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'module' => 'required|string|in:' . implode(',', self::MODULES),
            'name' => 'required|string|max:191',
            'conditions' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $module = $request->input('module');

        if (! $this->canViewModule($identity, $module)) {
            return response()->json(['status' => 0, 'message' => 'You do not have permission to view this.'], 403);
        }

        $id = DB::table('crm_saved_views')->insertGetId([
            'module' => $module,
            'name' => $request->input('name'),
            'conditions' => json_encode($request->input('conditions')),
            'sub_institute_id' => $identity['sub_institute_id'],
            'created_by' => $identity['user_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('crm_saved_views')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'View saved.', 'data' => $this->resource($row)], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = DB::table('crm_saved_views')
            ->where('id', $id)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->first();

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Saved view not found.'], 404);
        }

        if (! $this->canViewModule($identity, $existing->module)) {
            return response()->json(['status' => 0, 'message' => 'You do not have permission to view this.'], 403);
        }

        DB::table('crm_saved_views')->where('id', $id)->delete();

        return response()->json(['status' => 1, 'message' => 'Saved view removed.']);
    }

    /** @param array{user: object, user_id: int, sub_institute_id: int} $identity */
    private function canViewModule(array $identity, string $module): bool
    {
        $profileId = (int) ($identity['user']->user_profile_id ?? 0);
        $meta = self::MODULE_RIGHTS[$module] ?? null;

        if (! $meta) {
            return false;
        }

        $menuId = $meta['menuId'] ?? MenuRight::idForAccessLink($meta['link']);

        return $menuId !== null && MenuRight::can($profileId, $menuId, $identity['sub_institute_id'], 'view');
    }

    /** @return array<string, mixed> */
    private function resource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'module' => $row->module,
            'name' => $row->name,
            'conditions' => json_decode($row->conditions, true) ?? [],
            'createdAt' => $row->created_at,
        ];
    }
}
