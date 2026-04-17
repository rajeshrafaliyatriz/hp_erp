<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use App\Models\tblmenumasterModel;
use App\Models\user\tblgroupwise_rightsModel;
use App\Models\user\tbluserprofilemasterModel;
use function App\Helpers\is_mobile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Validator;
use Response;

class tblgroupwise_rightsController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->input('type');
        // $sub_institute_id = $request->session()->get('sub_institute_id');
        //    if ($type == 'API') {
        $token = $request->input('token');  // get token from input field 'token'

        // Check if token is provided
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        // Find the token in the database
        $accessToken = PersonalAccessToken::findToken($token);

        // If token is invalid
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }
        // Validate required fields
        $validator = Validator::make($request->all(), [
            'sub_institute_id' => 'required',
            'profile_id' => 'required',
        ]);

        // If validation fails
        if ($validator->fails()) {
            return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
        }
        $sub_institute_id = $request->get('sub_institute_id');
        $profile_id = $request->get('profile_id');
        // }
        $user_data = tblgroupwise_rightsModel::select('tblgroupwise_rights.*', 'tbluserprofilemaster.name as profile_name', 'tblmenumaster.menu_name')
            ->join('tbluserprofilemaster', 'tblgroupwise_rights.profile_id', '=', 'tbluserprofilemaster.id')
            ->join('tblmenumaster', 'tblgroupwise_rights.menu_id', '=', 'tblmenumaster.id')
            ->where(['tblgroupwise_rights.sub_institute_id' => $sub_institute_id])
            ->get();

        $res['status_code'] = 1;
        $res['message'] = "Success";
        $res['data'] = $user_data;
        $type = $request->input('type');
        return is_mobile($type, "user/show_groupwise_rights", $res, "view");
    }

    public function create(Request $request)
    {

        $sub_institute_id = $request->session()->get('sub_institute_id');
        $user_profiles = tbluserprofilemasterModel::where(['sub_institute_id' => $sub_institute_id, 'status' => '1'])->orderBy('sort_order')->get()->toArray();

        // $data = tblmenumasterModel::where(['LEVEL' => 1,'status' => 1])->whereRaw("find_in_set('$sub_institute_id',sub_institute_id)")->orderBy('sort_order')->get()->toArray();

        // $subMenuData = tblmenumasterModel::where(['LEVEL' => 2,'status' => 1])->whereRaw("find_in_set('$sub_institute_id',sub_institute_id)")->orderBy('sort_order')->get()->toArray();

        // $SubsubMenuData = tblmenumasterModel::where(['LEVEL' => 3,'status' => 1])->whereRaw("find_in_set('$sub_institute_id',sub_institute_id)")->orderBy('sort_order')->get()->toArray();

        // $i = 0;
        // foreach ($subMenuData as $key => $value) {
        //  $finalSubMenu[$value['parent_id']][$i] = $subMenuData[$key];
        //  $i++;
        // }

        // $i = 0;
        // foreach ($SubsubMenuData as $key => $value) {
        //  $finalSubSubMenu[$value['parent_id']][$i] = $SubsubMenuData[$key];
        //  $i++;
        // }

        // view()->share('groupwisemenuMaster', $data);
        // if (isset($finalSubMenu)) {
        //  view()->share('groupwisesubmenuMaster', $finalSubMenu);
        // }

        // if (isset($finalSubSubMenu)) {
        //  view()->share('groupwiseSubsubmenuMaster', $finalSubSubMenu);
        // }

        return view('user/add_groupwise_rights', ['user_profiles' => $user_profiles]);
    }

    public function store(Request $request)
    {
        $token = $request->input('token');  // get token from input field 'token'

        // Check if token is provided
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        // Find the token in the database
        $accessToken = PersonalAccessToken::findToken($token);

        // If token is invalid
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }
        // Validate required fields
        $validator = Validator::make($request->all(), [
            'sub_institute_id' => 'required',
            'profile_id' => 'required',
        ]);

        // If validation fails
        if ($validator->fails()) {
            return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
        }
        $sub_institute_id = $request->get('sub_institute_id');

        $addRights = $request->input('add');
        $editRights = $request->input('edit');
        $deleteRights = $request->input('delete');
        $viewRights = $request->input('view');
        $dashboardRights = $request->input('dashboard');
        $is_mobile = $request->input('is_mobile');

        if (!isset($addRights)) {
            $addRights = array();
        }
        if (!isset($editRights)) {
            $editRights = array();
        }
        if (!isset($deleteRights)) {
            $deleteRights = array();
        }
        if (!isset($viewRights)) {
            $viewRights = array();
        }
         if (!isset($dashboardRights)) {
            $dashboardRights = array();
        }
        if (!isset($is_mobile)) {
            $is_mobile = array();
        }

        $arrayKeys = array_replace($addRights, $editRights, $deleteRights, $viewRights,$dashboardRights);

        tblgroupwise_rightsModel::where(["profile_id" => $request->input('profile_id')])->delete();
        $i = 0;
        foreach ($arrayKeys as $key => $value) {
            $finalArray = array(
                'menu_id' => $key,
                'profile_id' => $request->input('profile_id'),
                'sub_institute_id' => $sub_institute_id,
                'is_mobile' => $request->input('is_mobile', 0),
                'created_at' => now(),
            );

            if (isset($viewRights[$key])) {
                $finalArray['can_view'] = 1;
            }
            if (isset($addRights[$key])) {
                $finalArray['can_add'] = 1;
            }
            if (isset($editRights[$key])) {
                $finalArray['can_edit'] = 1;
            }
            if (isset($deleteRights[$key])) {
                $finalArray['can_delete'] = 1;
            }
              if (isset($dashboardRights[$key])) {
                $finalArray['dashboard_right'] = 1;
            }
            if (isset($is_mobile[$key])) {
                $finalArray['is_mobile'] = 1;
            }
            $insert = tblgroupwise_rightsModel::insert($finalArray);
            if ($insert) {
                $i++;
            }
        }
        if (empty($arrayKeys)) {
            $res['status_code'] = "0";
            $res['message'] = "Please Select View/Add/Edit/Delete";
        } else if ($i == 0) {
            $res['status_code'] = "0";
            $res['message'] = "Groupwise Rights Failed to Add";
        } else {
            $res['status_code'] = "1";
            $res['message'] = "Groupwise Rights Added successfully";
        }
        $type = $request->input('type');
        return is_mobile($type, "add_groupwise_rights.index", $res);
    }

    public function displayGroupwiseRights(Request $request)
    {

        $type = $request->input("type");
        $profile_id = $request->input("profile_id");
        $sub_institute_id = $request->session()->get('sub_institute_id');
        // if ($type == 'API') {
        $token = $request->input('token');  // get token from input field 'token'

        // Check if token is provided
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        // Find the token in the database
        $accessToken = PersonalAccessToken::findToken($token);

        // If token is invalid
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }
        // Validate required fields
        $validator = Validator::make($request->all(), [
            'sub_institute_id' => 'required',
            'profile_id' => 'required',
        ]);

        // If validation fails
        if ($validator->fails()) {
            return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
        }
        $sub_institute_id = $request->get('sub_institute_id');
        $is_mobile = $request->input('is_mobile');
        // }

        $allowed_menu_ids = null;
        if ($is_mobile !== null) {
            $allowed_menu_ids = tblgroupwise_rightsModel::where(['profile_id' => $profile_id, 'is_mobile' => $is_mobile])->pluck('menu_id')->toArray();
        }

        $dataQuery = tblmenumasterModel::where(['LEVEL' => 1, 'status' => 1])
            ->whereRaw('FIND_IN_SET(?, sub_institute_id)', [$sub_institute_id]);
        if ($allowed_menu_ids) {
            $dataQuery->whereIn('id', $allowed_menu_ids);
        }
        $data = $dataQuery->groupBy('tblmenumaster.id')
            ->orderBy('tblmenumaster.sort_order', 'ASC')
            ->get()
            ->toArray();


        $subMenuQuery = tblmenumasterModel::where(['LEVEL' => 2, 'status' => 1])
            ->whereRaw('FIND_IN_SET(?, sub_institute_id)', [$sub_institute_id]);
        if ($allowed_menu_ids) {
            $subMenuQuery->whereIn('id', $allowed_menu_ids);
        }
        $subMenuData = $subMenuQuery->orderBy('tblmenumaster.sort_order', 'ASC')
            ->get()
            ->toArray();

        $SubsubMenuQuery = tblmenumasterModel::where(['LEVEL' => 3, 'status' => 1])
            ->whereRaw('FIND_IN_SET(?, sub_institute_id)', [$sub_institute_id]);
        if ($allowed_menu_ids) {
            $SubsubMenuQuery->whereIn('id', $allowed_menu_ids);
        }
        $SubsubMenuData = $SubsubMenuQuery->orderBy('tblmenumaster.sort_order', 'ASC')
            ->get()
            ->toArray();

        $i = 0;
        foreach ($data as $key => $value) {
            $data[$i] = $data[$key];

            $rights = tblgroupwise_rightsModel::where(['profile_id' => $profile_id,'menu_id'=>$value['id']])->first();
            $data[$i]['can_view'] = $rights->can_view ?? 0;
            $data[$i]['can_add'] = $rights->can_add ?? 0;
            $data[$i]['can_edit'] = $rights->can_edit ?? 0;
            $data[$i]['can_delete'] = $rights->can_delete ?? 0;
            $data[$i]['dashboard_right'] = $rights->dashboard_right ?? 0;
            $data[$i]['is_mobile'] = $rights->is_mobile ?? 0;
            $i++;
        }

        $i = 0;
        foreach ($subMenuData as $key => $value) {
            $finalSubMenu[$value['parent_id']][$i] = $subMenuData[$key];
            $rights = tblgroupwise_rightsModel::where(['profile_id' => $profile_id,'menu_id'=>$value['id']])->first();

            $finalSubMenu[$value['parent_id']][$i]['can_view'] = $rights->can_view ?? 0;
            $finalSubMenu[$value['parent_id']][$i]['can_add'] = $rights->can_add ?? 0;
            $finalSubMenu[$value['parent_id']][$i]['can_edit'] = $rights->can_edit ?? 0;
            $finalSubMenu[$value['parent_id']][$i]['can_delete'] = $rights->can_delete ?? 0;
            $finalSubMenu[$value['parent_id']][$i]['dashboard_right'] = $rights->dashboard_right ?? 0;
            $finalSubMenu[$value['parent_id']][$i]['is_mobile'] = $rights->is_mobile ?? 0;
            $i++;
        }

        $i = 0;
        foreach ($SubsubMenuData as $key => $value) {
            $finalSubSubMenu[$value['parent_id']][$i] = $SubsubMenuData[$key];
            $rights = tblgroupwise_rightsModel::where(['profile_id' => $profile_id,'menu_id'=>$value['id']])->first();

            $finalSubSubMenu[$value['parent_id']][$i]['can_view'] = $rights->can_view ?? 0;
            $finalSubSubMenu[$value['parent_id']][$i]['can_add'] = $rights->can_add ?? 0;
            $finalSubSubMenu[$value['parent_id']][$i]['can_edit'] = $rights->can_edit ?? 0;
            $finalSubSubMenu[$value['parent_id']][$i]['can_delete'] = $rights->can_delete ?? 0;
            $finalSubSubMenu[$value['parent_id']][$i]['dashboard_right'] = $rights->dashboard_right ?? 0;
            $finalSubSubMenu[$value['parent_id']][$i]['is_mobile'] = $rights->is_mobile ?? 0;
            $i++;
        }

        view()->share('groupwisemenuMaster', $data);
        if (isset($finalSubMenu)) {
            view()->share('groupwisesubmenuMaster', $finalSubMenu);
        } else {
            $finalSubMenu = [];
        }

        if (isset($finalSubSubMenu)) {
            view()->share('groupwiseSubsubmenuMaster', $finalSubSubMenu);
        }

        $rightsData = tblgroupwise_rightsModel::join('tblmenumaster', 'tblgroupwise_rights.menu_id', '=', 'tblmenumaster.id')->where(['tblgroupwise_rights.profile_id' => $profile_id])->get()->toArray();

        $rights = [];

        if (!empty($rightsData)) {
            foreach ($rightsData as $key => $value) {
                if ($value['can_view'] == 1) {
                    $rights['view'][$value['menu_id']] = $value['can_view'];
                }
                if ($value['can_add'] == 1) {
                    $rights['add'][$value['menu_id']] = $value['can_add'];
                }
                if ($value['can_edit'] == 1) {
                    $rights['edit'][$value['menu_id']] = $value['can_edit'];
                }
                if ($value['can_delete'] == 1) {
                    $rights['delete'][$value['menu_id']] = $value['can_delete'];
                }
            }
        }

        $response = array(
            'level_1'=>$data,
            'level_2'=>$finalSubMenu ?? [],
            'level_3'=>$finalSubSubMenu ?? [],
            // 'profile_rights'=>$rights
        );

        // return $response;
        return response()->json($response);

        // return view('user.add_groupwise_rights');

        // return view('user/add_groupwise_rights', ['user_profiles' => $user_profiles]);
        // return back()->with('success','added');
    }
}
