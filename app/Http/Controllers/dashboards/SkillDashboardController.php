<?php

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\HRMS\hrmsDepartmentModel;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use App\Models\settings\discliplinaryManagementModel;
use App\Models\settings\organizationDetails;
use App\Models\settings\organizationSisterDetails;

class SkillDashboardController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // 
        // return "hello";
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

        $res['total_skills']    = DB::table('s_user_skill_jobrole')->where(['sub_institute_id'=>$sub_institute_id])->whereNull('deleted_at')->groupBy('skill')->count();
         $res['total_jobrole']= DB::table('s_user_skill_jobrole') ->where(['sub_institute_id'=>$sub_institute_id])->whereNull('deleted_at')->groupBy('jobrole')->count();
         $res['total_knowledge'] = DB::table('s_skill_knowledge_ability')->where(['sub_institute_id'=>$sub_institute_id,'classification'=>'knowledge'])->whereNull('deleted_at')->groupBy('classification_item')->count();
        $res['total_behaviour'] = DB::table('s_skill_knowledge_ability')->where(['sub_institute_id'=>$sub_institute_id,
          'classification'=>'behaviour'])->whereNull('deleted_at')->groupBy('classification_item')->count();
        $res['total_ability']   = DB::table('s_skill_knowledge_ability')->where(['sub_institute_id'=>$sub_institute_id,'classification'=>'ability'])->whereNull('deleted_at')->groupBy('classification_item')->count();
        $res['total_attitude']  = DB::table('s_skill_knowledge_ability')->where(['sub_institute_id'=>$sub_institute_id,'classification'=>'ability'])->whereNull('deleted_at')->groupBy('classification_item')->count();
        $res['skill_levels']=db::table('s_proficiency_levels')->whereNull('sub_institute_id')->groupBy('proficiency_level')->get();


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
