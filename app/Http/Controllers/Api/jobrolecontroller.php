<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class jobrolecontroller extends Controller
{
    public function getJobRolesByDepartment(request $request,$id)
{
     // --- Token Authentication Check ---
        $token = $request->input('token');
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }
        


    $jobRoles = DB::table('s_jobrole')
        ->select('track')
        ->where('sector', $id) 
        ->groupBy('track')
        ->get();

    return response()->json(['department_id' => $id,'job_roles' => $jobRoles], 200);
}


     public function skills(Request $request, $id)
    {

        $token = $request->input('token');
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        // $subInstituteId = $request->query('sub_institute_id'); 
        $skills = DB::table('s_user_skill_jobrole as a')
                ->join('s_users_skills as b', function ($join) use ($request){
                    $join->on('b.title', '=', 'a.skill')
                        ->where('b.sub_institute_id', $request->sub_institute_id)
                        ->whereNull('b.deleted_at');
                        })
                        ->where('a.sub_institute_id', $request->sub_institute_id)
                        ->whereNull('a.deleted_at')
                        ->where('a.jobrole', $id)
                        ->groupBy('a.skill')
                        ->get();
            

         return response()->json(['status' => 1,'skills' => $skills]);

    }


    public function searchskills(Request $request)
    {


        $token = $request->input('token');
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }
        $skill = $request->input('query');
        $skills = DB::table('s_user_skill_jobrole as a')
                 ->join('s_users_skills as b', function($join) use ($request)
                 {
                    $join->on('b.title', '=', 'a.skill')
                        ->where('b.sub_institute_id',$request->sub_institute_id)
                        ->whereNull('b.deleted_at');
                            })
                            ->where('a.sub_institute_id',$request->sub_institute_id)
                            ->whereNull('a.deleted_at')
                            ->where('a.skill', 'LIKE', "%{$skill}%")
                            ->groupBy('a.skill')
                            ->get();


        return response()->json(['status' => 1,'skills' => $skills]);
    
    }
    
}
