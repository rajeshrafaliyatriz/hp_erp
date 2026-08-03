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

        $data = [];

        foreach ($modules as $module) {
            if (! $this->canView($module->id, $profile_id)) {
                continue;
            }

            $menuNodes = [];
            foreach ($menusByParent->get($module->id, []) as $menu) {
                if (! $this->canView($menu->id, $profile_id)) {
                    continue;
                }

                $submenuNodes = [];
                foreach ($submenusByParent->get($menu->id, []) as $submenu) {
                    if (! $this->canView($submenu->id, $profile_id)) {
                        continue;
                    }

                    $submenuNodes[] = $this->formatNode($submenu);
                }

                $hadSubmenus = $submenusByParent->has($menu->id);
                if ($hadSubmenus && empty($submenuNodes)) {
                    continue;
                }

                $menuNode = $this->formatNode($menu);
                $menuNode['submenus'] = $submenuNodes;
                $menuNodes[] = $menuNode;
            }

            $hadMenus = $menusByParent->has($module->id);
            if ($hadMenus && empty($menuNodes)) {
                continue;
            }

            $moduleNode = $this->formatNode($module);
            $moduleNode['menus'] = $menuNodes;
            $data[] = $moduleNode;
        }

        return response()->json([
            'status_code' => 1,
            'message' => 'Success',
            'data' => $data,
        ]);
    }

    private function canView($menuId, $profileId): bool
    {
        $rights = tblgroupwise_rights_g2gModel::where(['profile_id' => $profileId, 'menu_id' => $menuId])->first();

        return ($rights->can_view ?? 0) == 1;
    }

    private function formatNode($node): array
    {
        return [
            'id' => $node->id,
            'label' => $node->menu_name,
            'icon' => $node->icon,
            'access_link' => $node->access_link,
            'page_type' => $node->page_type,
            'sort_order' => $node->sort_order,
        ];
    }
}
