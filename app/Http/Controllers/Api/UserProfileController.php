<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class UserProfileController extends Controller
{
    public function show(Request $request)
    {
        $token = $request->input('token') ?: $request->bearerToken();

        if (!$token) {
            return response()->json([
                'status_code' => 0,
                'message' => 'Token not provided',
            ], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken || !$accessToken->tokenable) {
            return response()->json([
                'status_code' => 0,
                'message' => 'Invalid token',
            ], 401);
        }

        $tokenUser = $accessToken->tokenable;

        $profile = DB::table('tbluser as u')
            ->join('tbluserprofilemaster as up', function ($join) {
                $join->on('up.id', '=', 'u.user_profile_id')
                    ->on('up.sub_institute_id', '=', 'u.sub_institute_id');
            })
            ->leftJoin('school_setup as ss', 'ss.id', '=', 'u.sub_institute_id')
            ->leftJoin('hrms_departments as hd', 'hd.id', '=', 'u.department_id')
            ->leftJoin('s_user_jobrole as jr', 'jr.id', '=', 'u.allocated_standards')
            ->selectRaw("
                u.id,
                u.user_name,
                u.first_name,
                u.middle_name,
                u.last_name,
                CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.middle_name, ''), NULLIF(u.last_name, '')) as full_name,
                u.email,
                u.mobile,
                u.birthdate,
                u.address,
                u.gender,
                u.join_year,
                u.employee_no,
                u.employee_id,
                u.image,
                u.user_profile_id,
                up.name as user_profile_name,
                u.sub_institute_id,
                u.client_id,
                u.is_admin,
                u.status,
                u.department_id,
                IFNULL(hd.department, '-') as department_name,
                u.allocated_standards as jobrole_id,
                IFNULL(jr.jobrole, '-') as jobrole_name,
                jr.job_level,
                jr.has_vertical_progression,
                jr.has_lateral_movement,
                jr.progression_type,
                ss.SchoolName as school_name,
                ss.ShortCode as school_short_code,
                ss.Logo as school_logo,
                ss.syear
            ")
            ->where('u.id', $tokenUser->id)
            ->first();

        if (!$profile) {
            return response()->json([
                'status_code' => 0,
                'message' => 'Profile details not found',
            ], 404);
        }

        $baseUrl = rtrim($request->getSchemeAndHttpHost(), '/');
        $profile->image = !empty($profile->image) ? $baseUrl . '/storage/user/' . ltrim($profile->image, '/') : '';
        $profile->school_logo = !empty($profile->school_logo) ? $baseUrl . '/admin_dep/images/' . ltrim($profile->school_logo, '/') : '';

        return response()->json([
            'status_code' => 1,
            'message' => 'Profile details fetched successfully',
            'data' => $profile,
        ]);
    }
}
