<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use function App\Helpers\is_mobile;
use App\Models\auth\tbluserModel;
use App\Models\user\tblgroupwise_rightsModel;
use App\Http\Controllers\user\tbluserController;
use App\Http\Controllers\lms\lmsActivityStreamController;
use App\Models\HRMS\hrmsDepartmentModel;

class dashboardController extends Controller
{
    //
    public function index(Request $request){
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

        $user = tbluserModel::where('id', $user_id)
        ->where(['sub_institute_id'=>$sub_institute_id,'status'=>1])
        ->whereNull('deleted_at')
        ->first();

        if (!$user) {
            return response()->json([
                'status_code' => 0,
                'message' => 'Failed to get user data',
            ]);
        }

        $getUsersRights = tblgroupwise_rightsModel::with([
            'menuData' => function($query) {
                $query->select('id', 'menu_name', 'parent_id', 'level', 'sub_institute_id')
                      ->where('level', 3);
            },
        ])
        ->whereHas('menuData', function($query) {
            $query->where('level', 3);
        })
        ->where([
            'sub_institute_id' => $sub_institute_id,
            'profile_id' => $user_profile_id
        ])
        ->get()
        ->map(function ($item) {
            // Check if menuData exists before accessing its properties
            if ($item->menuData) {
                $item->menu_name = $item->menuData->menu_name;
                $item->parent_id = $item->menuData->parent_id;
                $item->level = $item->menuData->level;
                unset($item->menuData);
            } else {
                // Set default values if menuData is null
                $item->menu_name = null;
                $item->parent_id = null;
                $item->level = null;
            }
            return $item;
        });

        $res['status_code'] = 1;
        $res['message'] = 'Welcome User '.$user->first_name.' '.$user->last_name;
        
        $SkillLevels= DB::table('s_proficiency_levels')->where(['sub_institute_id'=>$sub_institute_id])->whereNull('deleted_at')->get()->toArray();
        $usercontroller =  new tbluserController;
        $request->merge([
            'active_status' => 1,
            'menu_type' => 'Dashboard'
        ])->toArray();
        // get employee list
        $empData = $usercontroller->index($request);
        $department = hrmsDepartmentModel::where(['sub_institute_id'=>$sub_institute_id,'status'=>1])->whereNull('deleted_at')->get()->toArray();
        $mySKill = $myGrowth = $skillHatMap= $userRatedSkills=$unRatedSkills=$departmentWiseSkill=[];
        $currentLevel = $orgSkillLevel = 0;
        // get total skills
        if(in_array(strtoupper($user_profile_name),["ADMIN", "SUPER ADMIN"])){
           $getTotalJobroles = DB::table('s_user_jobrole')
           ->where(['sub_institute_id'=>$sub_institute_id])
           ->whereNotNull('jobrole')
           ->where('jobrole', '!=', '')
           ->distinct('jobrole')
           ->count();
           $getTotalSkill = DB::table('s_users_skills')
           ->where(['sub_institute_id'=>$sub_institute_id,'status'=>'Active'])
           ->whereNull('deleted_at')
        //    ->groupBy('title')
           ->count();

            foreach($department as $key=>$value){
                foreach($SkillLevels as $sk=>$sv){
                    $getSkillRating = DB::table('s_skill_matrix as sm')
                    ->join('tbluser as tu',function($join) use($sub_institute_id,$value){
                            $join->on('sm.user_id','=','tu.id')->where(['sub_institute_id'=>$sub_institute_id,'tu.status'=>1,'tu.department_id'=>$value['id']]); 
                    })
                    ->join('s_user_skill_jobrole as usj',function($join) use($sub_institute_id){
                                $join->on('sm.skill_id','=','usj.id');
                                // ->where(['usj.sub_institute_id'=>$sub_institute_id]); 
                        })
                    ->selectRaw('sm.*,CONCAT_WS(" ",COALESCE(tu.first_name,"-"),COALESCE(tu.middle_name,"-"),COALESCE(tu.last_name,"-")) as user_name,CASE WHEN tu.image IS NOT NULL 
                    THEN CONCAT("https://s3-triz.fra1.cdn.digitaloceanspaces.com/public/hp_user/", tu.image)
                    ELSE NULL 
                END as image,usj.jobrole,count(usj.skill) as total_skills,GROUP_CONCAT(usj.skill SEPARATOR "|||") as skillList')
                    ->where(['tu.sub_institute_id'=>$sub_institute_id,'sm.skill_level'=>$sv->proficiency_type])->groupBy('sm.user_id')->get();
                    
                    $totalRatedSkills = count($getSkillRating);
                    $heatMap = [
                        'total_emp'=>$totalRatedSkills,
                        'level_name' => $sv->proficiency_level,
                        'skillData'=>$getSkillRating,
                    ];
                    $departmentWiseSkill[$value['department']][$sv->proficiency_type] = $heatMap; 
                }
            }
        //    $departmentWiseSkill = 
        }
        else{
            $userjobrole = DB::table('s_user_skill_jobrole')
            ->where(['sub_institute_id'=>$sub_institute_id])
            ->where('id',$user->allocated_standards)
            ->whereNull('deleted_at')
            ->get();

            $getTotalJobroles = count($userjobrole);
 
            $getTotalSkill = DB::table('s_users_skills')
            ->where(['sub_institute_id'=>$sub_institute_id,'status'=>'Active'])
            ->where('title',$userjobrole[0]->jobrole ?? '')
            ->whereNull('deleted_at')
            ->count();

            $PersonalData = $usercontroller->edit($request,$user_id);
            // return $PersonalData;
            $mySKill = $PersonalData->original['jobroleSkills'] ?? [];
            $unRatedSkills = $PersonalData->original['skills'] ?? [];
            $userRatedSkills = $PersonalData->original['userRatedSkills'] ?? [];
            $currentLevel = (count($userRatedSkills) > 0 && count($mySKill) > 0) 
                ? round((count($userRatedSkills) / count($mySKill)) * 100, 2)
                : 0;
            $orgSkillLevel = DB::table('s_proficiency_levels')->where('sub_institute_id',$sub_institute_id)->whereNull('deleted_at')->count();
            // return $orgSkillLevel;
        }

        $lmsActivityStreamController = new lmsActivityStreamController;
        $taskList = $lmsActivityStreamController->index($request);
        // return $taskList;
        // Handle JsonResponse object properly
        $taskListArray = $taskList->original ?? [];
        // return $taskListArray['today']['taskAssigned'];
        $weekTasks = [];
        $currentDate = now();
        $weekStart = $currentDate->copy()->startOfWeek();
        $weekEnd = $currentDate->copy()->endOfWeek();

        // Check all task arrays (today, upcoming, recent) for tasks within current week
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

        $res['totle_employees'] = count($empData->original['data']) ?? 0;
        $res['mapped_jobrole'] = tbluserModel::where(['sub_institute_id'=>$sub_institute_id,'status'=>1])
        ->whereNotNull('allocated_standards')
        ->count();
        $res['unmapped_employees'] = tbluserModel::where(['sub_institute_id'=>$sub_institute_id,'status'=>1])
        ->whereNull('allocated_standards')
        ->count();
        $res['totle_skills'] = $getTotalSkill;
        $res['widget'] = ['Employee List','Weekly Task Progress','Today Task List','Week Task List'];
        $res['totle_jobroles'] = $getTotalJobroles;
        $res['today_task'] = $taskListArray['today']['taskAssigned'] ?? [];
        $res['week_task'] = $weekTasks;
        $res['current_level'] = $currentLevel;
        $res['orgSkillLevel'] = $orgSkillLevel;
        $res['mySKill'] = $mySKill;
        $res['departmentList'] = $department;
        $res['SkillLevels'] = $SkillLevels;
        $res['skillHeatmap'] = $departmentWiseSkill;
        $res['myGrowth'] = $userRatedSkills;
        $res['employeeList'] = $empData->original['data'] ?? [];

        return response()->json($res);
    }
}

