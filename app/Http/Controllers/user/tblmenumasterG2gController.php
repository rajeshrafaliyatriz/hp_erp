<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use App\Models\tblmenumaster_g2gModel;
use App\Models\user\tblgroupwise_rights_g2gModel;
use App\Models\user\tbluserModel;
use App\Models\user\tbluserprofilemasterModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

class tblmenumasterG2gController extends Controller
{
    /**
     * Returns the full Modules -> Menus -> Submenus (-> ... unlimited depth)
     * hierarchy from tblmenumaster_g2g as a nested tree, filtered to the
     * rows the given profile_id has can_view rights on
     * (tblgroupwise_rights_g2g).
     *
     * The tree is built purely from parent_id (root nodes have
     * parent_id = 0), so any additional nesting depth added in the
     * database is picked up automatically without code changes.
     *
     * Mirrors the auth/param handling of
     * tblgroupwise_rightsController::displayGroupwiseRights.
     */
    public function displaySidebarMenu(Request $request)
    {
        $profile_id = $request->input('profile_id');

        if ($error = $this->guard($request, ['sub_institute_id' => 'required', 'profile_id' => 'required'])) {
            return $error;
        }

        $sub_institute_id = $request->get('sub_institute_id');

        $allMenus = tblmenumaster_g2gModel::where('status', 1)
            ->whereRaw('FIND_IN_SET(?, sub_institute_id)', [$sub_institute_id])
            ->orderBy('sort_order', 'ASC')
            ->get();

        $menusByParent = $allMenus->groupBy('parent_id');

        $rightsByMenuId = tblgroupwise_rights_g2gModel::where('profile_id', $profile_id)
            ->get()
            ->keyBy('menu_id');

        $data = [];

        foreach ($menusByParent->get(0, []) as $module) {
            if (! $this->canView($module->id, $rightsByMenuId)) {
                continue;
            }

            $menuNodes = $this->buildMenuTree($module->id, $menusByParent, $rightsByMenuId);

            $hadMenus = $menusByParent->has($module->id);
            if ($hadMenus && empty($menuNodes)) {
                continue;
            }

            $moduleNode = $this->formatNode($module, $rightsByMenuId);
            $moduleNode['menus'] = $menuNodes;
            $data[] = $moduleNode;
        }

        return response()->json([
            'status_code' => 1,
            'message' => 'Success',
            'data' => $data,
        ]);
    }

    /**
     * Role & Permissions screen: the complete menu tree from
     * tblmenumaster_g2g for the tenant, with the target profile's rights
     * stamped on every node.
     *
     * Unlike displaySidebarMenu this deliberately does NOT drop rows the
     * profile cannot view - an admin editing rights has to see the boxes
     * that are still unticked. `profile_id` here is the role being edited,
     * not the caller's own.
     */
    public function displayGroupwiseRightsG2g(Request $request)
    {
        if ($error = $this->guard($request, ['sub_institute_id' => 'required', 'profile_id' => 'required'])) {
            return $error;
        }

        $sub_institute_id = $request->get('sub_institute_id');
        $profile_id = $request->get('profile_id');

        $allMenus = tblmenumaster_g2gModel::where('status', 1)
            ->whereRaw('FIND_IN_SET(?, sub_institute_id)', [$sub_institute_id])
            ->orderBy('sort_order', 'ASC')
            ->get();

        $menusByParent = $allMenus->groupBy('parent_id');

        $rightsByMenuId = tblgroupwise_rights_g2gModel::where('profile_id', $profile_id)
            ->get()
            ->keyBy('menu_id');

        $data = [];

        foreach ($menusByParent->get(0, []) as $module) {
            $moduleNode = $this->formatNode($module, $rightsByMenuId);
            $moduleNode['menus'] = $this->buildRightsTree($module->id, $menusByParent, $rightsByMenuId);
            $data[] = $moduleNode;
        }

        return response()->json([
            'status_code' => 1,
            'message' => 'Success',
            'data' => $data,
        ]);
    }

    /**
     * Replaces the whole rights set of one profile in
     * tblgroupwise_rights_g2g. Expects `rights` as a list of {menu_id,
     * can_view, can_add, can_edit, can_delete, dashboard_right, is_mobile}.
     * Rows with no flag set are simply not stored - absence of a row is
     * what "no rights" means to displaySidebarMenu. Delete + insert runs in
     * a transaction so a failed save cannot leave the profile with no menu
     * at all.
     */
    public function storeGroupwiseRightsG2g(Request $request)
    {
        if ($error = $this->guard($request, [
            'sub_institute_id' => 'required',
            'profile_id' => 'required',
            'rights' => 'array',
        ])) {
            return $error;
        }

        $sub_institute_id = $request->get('sub_institute_id');
        $profile_id = $request->get('profile_id');
        $rights = $request->input('rights', []);

        $validMenuIds = tblmenumaster_g2gModel::where('status', 1)
            ->whereRaw('FIND_IN_SET(?, sub_institute_id)', [$sub_institute_id])
            ->pluck('id')
            ->flip();

        $rows = [];
        foreach ($rights as $right) {
            $menuId = (int) ($right['menu_id'] ?? 0);
            if (! $menuId || ! $validMenuIds->has($menuId)) {
                continue;
            }

            $row = [
                'menu_id' => $menuId,
                'profile_id' => $profile_id,
                'sub_institute_id' => $sub_institute_id,
                'can_view' => $this->flag($right['can_view'] ?? 0),
                'can_add' => $this->flag($right['can_add'] ?? 0),
                'can_edit' => $this->flag($right['can_edit'] ?? 0),
                'can_delete' => $this->flag($right['can_delete'] ?? 0),
                'dashboard_right' => $this->flag($right['dashboard_right'] ?? 0),
                'is_mobile' => $this->flag($right['is_mobile'] ?? 0),
                'created_at' => now(),
            ];

            if ($row['can_view'] || $row['can_add'] || $row['can_edit'] || $row['can_delete'] || $row['dashboard_right'] || $row['is_mobile']) {
                $rows[] = $row;
            }
        }

        DB::transaction(function () use ($profile_id, $rows) {
            tblgroupwise_rights_g2gModel::where('profile_id', $profile_id)->delete();

            foreach (array_chunk($rows, 200) as $chunk) {
                tblgroupwise_rights_g2gModel::insert($chunk);
            }
        });

        return response()->json([
            'status_code' => 1,
            'message' => 'Groupwise rights saved successfully',
            'data' => ['saved' => count($rows)],
        ]);
    }

    /**
     * The roles shown in the Role & Permissions sidebar: every active
     * profile of the tenant, with how many users currently sit on it.
     */
    public function displayUserProfilesG2g(Request $request)
    {
        if ($error = $this->guard($request, ['sub_institute_id' => 'required'])) {
            return $error;
        }

        $sub_institute_id = $request->get('sub_institute_id');

        $profiles = tbluserprofilemasterModel::where([
            'sub_institute_id' => $sub_institute_id,
            'status' => '1',
        ])->orderBy('sort_order')->get();

        $userCounts = tbluserModel::where('sub_institute_id', $sub_institute_id)
            ->groupBy('user_profile_id')
            ->select('user_profile_id', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'user_profile_id');

        $data = $profiles->map(fn ($profile) => [
            'id' => $profile->id,
            'name' => $profile->name,
            'description' => $profile->description,
            'sort_order' => $profile->sort_order,
            'user_count' => (int) ($userCounts[$profile->id] ?? 0),
        ]);

        return response()->json([
            'status_code' => 1,
            'message' => 'Success',
            'data' => $data,
        ]);
    }

    /**
     * Creates a role from the Role & Permissions screen. The tenant comes
     * from the validated request rather than the session, because the SPA
     * authenticates with a token and has no Laravel session to read.
     */
    public function storeUserProfileG2g(Request $request)
    {
        if ($error = $this->guard($request, [
            'sub_institute_id' => 'required',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ])) {
            return $error;
        }

        $sub_institute_id = $request->get('sub_institute_id');

        $nextSortOrder = (int) tbluserprofilemasterModel::where('sub_institute_id', $sub_institute_id)->max('sort_order') + 1;

        $profile = tbluserprofilemasterModel::create([
            'parent_id' => null,
            'name' => $request->get('name'),
            'description' => $request->get('description'),
            'sort_order' => $nextSortOrder,
            'status' => '1',
            'sub_institute_id' => $sub_institute_id,
            'client_id' => $sub_institute_id,
        ]);

        return response()->json([
            'status_code' => 1,
            'message' => 'Role created successfully',
            'data' => [
                'id' => $profile->id,
                'name' => $profile->name,
                'description' => $profile->description,
                'sort_order' => $profile->sort_order,
                'user_count' => 0,
            ],
        ]);
    }

    /**
     * Recursively builds the submenus array for the given parent menu id,
     * checking can_view rights at every level so it works for any nesting
     * depth without needing to know the depth in advance.
     */
    private function buildMenuTree($parentId, $menusByParent, $rightsByMenuId): array
    {
        $nodes = [];

        foreach ($menusByParent->get($parentId, []) as $menu) {
            if (! $this->canView($menu->id, $rightsByMenuId)) {
                continue;
            }

            $submenuNodes = $this->buildMenuTree($menu->id, $menusByParent, $rightsByMenuId);

            $hadSubmenus = $menusByParent->has($menu->id);
            if ($hadSubmenus && empty($submenuNodes)) {
                continue;
            }

            $menuNode = $this->formatNode($menu, $rightsByMenuId);
            $menuNode['submenus'] = $submenuNodes;
            $nodes[] = $menuNode;
        }

        return $nodes;
    }

    /**
     * Same recursive walk as buildMenuTree, but for the rights-editing
     * screen: nodes are never dropped for lack of can_view, since the
     * admin needs to see every box - ticked or not.
     */
    private function buildRightsTree($parentId, $menusByParent, $rightsByMenuId): array
    {
        $nodes = [];

        foreach ($menusByParent->get($parentId, []) as $menu) {
            $menuNode = $this->formatNode($menu, $rightsByMenuId);
            $menuNode['submenus'] = $this->buildRightsTree($menu->id, $menusByParent, $rightsByMenuId);
            $nodes[] = $menuNode;
        }

        return $nodes;
    }

    private function flag($value): int
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) || (int) $value === 1 ? 1 : 0;
    }

    /**
     * Validates the Sanctum bearer token and the given required fields.
     * Returns a JSON error response if either check fails, or null to
     * signal the caller can proceed.
     */
    private function guard(Request $request, array $rules)
    {
        $token = $request->input('token');

        if (! $token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        if (! PersonalAccessToken::findToken($token)) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
        }

        return null;
    }

    private function canView($menuId, $rightsByMenuId): bool
    {
        $rights = $rightsByMenuId->get($menuId);

        return ($rights->can_view ?? 0) == 1;
    }

    private function formatNode($node, $rightsByMenuId): array
    {
        $rights = $rightsByMenuId->get($node->id);

        return [
            'id' => $node->id,
            'label' => $node->menu_name,
            'icon' => $node->icon,
            'access_link' => $node->access_link,
            'page_type' => $node->page_type,
            'sort_order' => $node->sort_order,
            'can_view' => (int) ($rights->can_view ?? 0),
            'can_add' => (int) ($rights->can_add ?? 0),
            'can_edit' => (int) ($rights->can_edit ?? 0),
            'can_delete' => (int) ($rights->can_delete ?? 0),
            'dashboard_right' => (int) ($rights->dashboard_right ?? 0),
            'is_mobile' => (int) ($rights->is_mobile ?? 0),
        ];
    }
}
