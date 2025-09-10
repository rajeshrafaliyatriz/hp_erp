<?php

namespace App\Http\Controllers\dashboards;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\HRMS\hrmsDepartmentModel;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use App\Models\settings\discliplinaryManagementModel;
use App\Models\settings\organizationDetails;
use App\Models\settings\organizationSisterDetails;

class orgDashboardContorller extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // return $request;
       $type = $request->input('type');
        $sub_institute_id = session()->get('sub_institute_id');
        $user_id = session()->get('user_id');
        $user_profile_name = session()->get('user_profile_name');
        $user_profile_id = session()->get('user_profile_id');

        if ($type == 'API') {
            $token = $request->input('token');  // get token from input field 'token'

            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }

            $validator = Validator::make($request->all(), [
                'sub_institute_id' => 'required',
                'user_id' => 'required',
                'user_profile_name' =>'required',
                'user_profile_id'=>'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
            $sub_institute_id = $request->get('sub_institute_id');
            $user_id = $request->get('user_id');
            $user_profile_name = $request->get('user_profile_name');
            $user_profile_id = $request->get('user_profile_id');
        }
        $res['status'] = 1;
        $res['message'] = "welcome user!";
        $res['total_employees'] = DB::table('tbluser')->where(['sub_institute_id'=>$sub_institute_id,'status'=>1])->whereNull('deleted_at')->count();
        $res['total_departments'] = hrmsDepartmentModel::where(['sub_institute_id'=>$sub_institute_id,'status'=>1])->whereNull('deleted_at')->count();
        $res['total_complainces'] =  DB::table('master_compliance')->where(['sub_institute_id'=>$sub_institute_id])->whereNull('deleted_at')->count();
        $res['total_disciplinary'] =  DB::table('discliplinary_management')->where(['sub_institute_id'=>$sub_institute_id])->whereNull('deleted_at')->count();

         $res['org_data'] = organizationDetails::with('sistersOrg')->where('sub_institute_id',$request->sub_institute_id)->get();

        $department = hrmsDepartmentModel::where(['sub_institute_id'=>$sub_institute_id,'status'=>1])->whereNull('deleted_at')->get()->toArray();
        $res['departments'] = [];
        foreach ($department as $key => $value) {
            if($value['parent_id']==0){
                $value['total_employees'] = DB::table('tbluser')->where(['sub_institute_id'=>$sub_institute_id,'status'=>1,'department_id'=>$value['id']])->count();
                $res['departments'][] = $value;
            }
            else{
                $value['total_employees'] = DB::table('tbluser')->where(['sub_institute_id'=>$sub_institute_id,'status'=>1,'department_id'=>$value['id']])->count();
                $res['departments']['sub_department'][$value['parent_id']][] = $value;
            }
        }

        $res['complainceData'] = DB::table('master_compliance as mc')
                                ->select('mc.*',DB::Raw('(SELECT CONCAT_WS(" ",COALESCE(first_name,"-"),COALESCE(middle_name,"-"),COALESCE(last_name,"-")) FROM tbluser WHERE id=mc.assigned_to) as assigned_user'))
                                ->where('mc.sub_institute_id',$sub_institute_id)
                                ->whereNull('mc.deleted_at')->get()->toArray();

         $res['discliplinaryManagement'] = discliplinaryManagementModel::with([
            'departmentData' => function($query) {
                $query->select('id', 'department as department_name');
            },
            'employeeData' => function($query) {
                $query->select(
                    'id', 
                    DB::raw('CONCAT_WS(" ", COALESCE(first_name,"-"), COALESCE(middle_name,"-"), COALESCE(last_name,"-")) as employee_name')
                );
            },
            'witnessData' => function($query) {
                $query->select(
                    'id', 
                    DB::raw('CONCAT_WS(" ", COALESCE(first_name,"-"), COALESCE(middle_name,"-"), COALESCE(last_name,"-")) as employee_name')
                );
            },
            'reportByData' => function($query) {
                $query->select(
                    'id', 
                    DB::raw('CONCAT_WS(" ", COALESCE(first_name,"-"), COALESCE(middle_name,"-"), COALESCE(last_name,"-")) as employee_name')
                );
            },
        ])
        ->where('sub_institute_id', $sub_institute_id)
        ->whereNull('deleted_at')
        ->get()
        ->map(function ($item) {
            // Flatten the relationships into the main object
            $item->department_name = $item->departmentData->department_name ?? null;
            $item->employee_name = $item->employeeData->employee_name ?? null;
            $item->witness_name = $item->witnessData->employee_name ?? null;
            $item->reported_by_name = $item->reportByData->employee_name ?? null;
            
            // Remove the relationship objects
            unset($item->departmentData);
            unset($item->employeeData);
            unset($item->witnessData);
            unset($item->reportByData);
            
            return $item;
        })
        ->toArray();

        return response()->json($res);
      
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
