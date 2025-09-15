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

    return response()->json([
        'department_id' => $id,
        'job_roles' => $jobRoles
    ], 200);
}

}
