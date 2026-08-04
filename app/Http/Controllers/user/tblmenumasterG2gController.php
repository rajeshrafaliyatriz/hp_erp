<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use App\Models\tblmenumaster_g2gModel;
use App\Models\user\tblgroupwise_rights_g2gModel;
use Illuminate\Http\Request;
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
        $token = $request->input('token');

        if (! $token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (! $accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $validator = Validator::make($request->all(), [
            'sub_institute_id' => 'required',
            'profile_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
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
