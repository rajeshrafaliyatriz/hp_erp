<?php

namespace App\Http\Controllers\dashboards;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\HRMS\hrmsDepartmentModel;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use App\Http\Controllers\user\tbluserController;
use App\Models\settings\discliplinaryManagementModel;
use App\Http\Controllers\lms\lmsActivityStreamController;
use App\Models\settings\organizationDetails;
use App\Models\settings\organizationSisterDetails;

class skilldashboardcontroller extends Controller
{

public function index(Request $request)
{
    $type = $request->input('type');
    $sub_institute_id = session()->get('sub_institute_id');
    $user_id = session()->get('user_id');
    $user_profile_name = session()->get('user_profile_name');
    $user_profile_id = session()->get('user_profile_id');

    if ($type == 'API') {
        $token = $request->input('token');
        if (!$token) return response()->json(['message' => 'Token not provided'], 401);

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) return response()->json(['message' => 'Invalid token'], 401);

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

    $res['total_skills']    = DB::table('s_user_skill_jobrole')->where(['sub_institute_id'=>$sub_institute_id])->whereNull('deleted_at')->groupBy('skill')->count();
    $res['total_jobrole']= DB::table('s_user_skill_jobrole') ->where(['sub_institute_id'=>$sub_institute_id])->whereNull('deleted_at')->groupBy('jobrole')->count();
    $res['total_knowledge'] = DB::table('s_skill_knowledge_ability')->where(['sub_institute_id'=>$sub_institute_id,'classification'=>'knowledge'])->whereNull('deleted_at')->groupBy('classification_item')->count();
    $res['total_behaviour'] = DB::table('s_skill_knowledge_ability')->where(['sub_institute_id'=>$sub_institute_id,
          'classification'=>'behaviour'])->whereNull('deleted_at')->groupBy('classification_item')->count();
    $res['total_ability']   = DB::table('s_skill_knowledge_ability')->where(['sub_institute_id'=>$sub_institute_id,'classification'=>'ability'])->whereNull('deleted_at')->groupBy('classification_item')->count();
    $res['total_attitude']  = DB::table('s_skill_knowledge_ability')->where(['sub_institute_id'=>$sub_institute_id,'classification'=>'ability'])->whereNull('deleted_at')->groupBy('classification_item')->count();

    

    $usercontroller = new tbluserController;
    $request->merge(['active_status' => 1, 'menu_type' => 'Dashboard'])->toArray();

    $user_list = $usercontroller->index($request);
    $user_data = $usercontroller->edit($request, $user_id);

    $department = hrmsDepartmentModel::where(['sub_institute_id'=>$sub_institute_id,'status'=>1])->whereNull('deleted_at')->get()->toArray();
  

    

    if(in_array(strtoupper($user_profile_name),["ADMIN", "SUPER ADMIN"])){
        // your admin-specific logic...
    } else {
        $userjobrole = DB::table('s_user_skill_jobrole')
            ->where(['sub_institute_id'=>$sub_institute_id])
            ->where('id', $user_data->original['userInfo']['allocated_standards'] ?? null)
            ->whereNull('deleted_at')
            ->get();

        $res['totle_jobroles'] = count($userjobrole);

        $res['totle_skills'] = DB::table('s_users_skills')
            ->where(['sub_institute_id'=>$sub_institute_id,'status'=>'Active'])
            ->where('title', $userjobrole[0]->jobrole ?? '')
            ->whereNull('deleted_at')
            ->count();


    }
    $lmsActivityStreamController = new lmsActivityStreamController;
    $taskList = $lmsActivityStreamController->index($request);
      
    foreach(['today', 'upcoming', 'recent'] as $period) {
        if(isset($taskListArray[$period]['taskAssigned'])) {
            foreach($taskListArray[$period]['taskAssigned'] as $task) {
                $taskDate = \Carbon\Carbon::parse($task->task_date);
                if($taskDate->between($weekStart, $weekEnd)) {
                    $weekTasks[] = $task;
                }
            }
        }
    }
    
    $res['departmentList'] = $department;
    $res['employeeList'] = $user_list->original['data'] ?? [];

    return response()->json($res);
}
}
?>