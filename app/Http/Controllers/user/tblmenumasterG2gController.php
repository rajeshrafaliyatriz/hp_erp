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
     * Returns the full Modules -> Menus -> Submenus hierarchy from
     * tblmenumaster_g2g as a nested tree, filtered to the rows the given
     * profile_id has can_view rights on (tblgroupwise_rights_g2g).
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

        $modules = tblmenumaster_g2gModel::where(['level' => 1, 'status' => 1])
            ->whereRaw('FIND_IN_SET(?, sub_institute_id)', [$sub_institute_id])
            ->orderBy('sort_order', 'ASC')
            ->get();

        $menus = tblmenumaster_g2gModel::where(['level' => 2, 'status' => 1])
            ->whereRaw('FIND_IN_SET(?, sub_institute_id)', [$sub_institute_id])
            ->orderBy('sort_order', 'ASC')
            ->get();

        $submenus = tblmenumaster_g2gModel::where(['level' => 3, 'status' => 1])
            ->whereRaw('FIND_IN_SET(?, sub_institute_id)', [$sub_institute_id])
            ->orderBy('sort_order', 'ASC')
            ->get();

        $menusByParent = $menus->groupBy('parent_id');
        $submenusByParent = $submenus->groupBy('parent_id');

        $rightsByMenuId = tblgroupwise_rights_g2gModel::where('profile_id', $profile_id)
            ->get()
            ->keyBy('menu_id');

        $data = [];

        foreach ($modules as $module) {
            if (! $this->canView($module->id, $rightsByMenuId)) {
                continue;
            }

            $menuNodes = [];
            foreach ($menusByParent->get($module->id, []) as $menu) {
                if (! $this->canView($menu->id, $rightsByMenuId)) {
                    continue;
                }

                $submenuNodes = [];
                foreach ($submenusByParent->get($menu->id, []) as $submenu) {
                    if (! $this->canView($submenu->id, $rightsByMenuId)) {
                        continue;
                    }

                    $submenuNodes[] = $this->formatNode($submenu, $rightsByMenuId);
                }

                $hadSubmenus = $submenusByParent->has($menu->id);
                if ($hadSubmenus && empty($submenuNodes)) {
                    continue;
                }

                $menuNode = $this->formatNode($menu, $rightsByMenuId);
                $menuNode['submenus'] = $submenuNodes;
                $menuNodes[] = $menuNode;
            }

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
