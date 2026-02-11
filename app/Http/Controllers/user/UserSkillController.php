<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class UserSkillController extends Controller
{
    public function getUserSkills(Request $request, $user_id)
    {
        try {

            /* =========================================
             * 0️⃣ API Security Validation
             * ========================================= */

            $type        = $request->type;
            $token           = $request->token;
            $sub_institute_id = $request->sub_institute_id;

            // Check Required Params
            if (!$type || !$token || !$sub_institute_id) {
                return response()->json([
                    'status_code' => 0,
                    'message' => 'Missing API security parameters'
                ]);
            }

            // Validate Token (Sanctum)
            $accessToken = PersonalAccessToken::findToken($token);

            if (!$accessToken) {
                return response()->json([
                    'status_code' => 0,
                    'message' => 'Invalid Token'
                ]);
            }

            // OPTIONAL: If you want to verify sub_institute_id from token user
            $tokenUser = $accessToken->tokenable;

            if ($tokenUser->sub_institute_id != $sub_institute_id) {
                return response()->json([
                    'status_code' => 0,
                    'message' => 'Invalid sub institute access'
                ]);
            }

            /* =========================================
             * 1️⃣ Get allocated_standard (Jobrole ID)
             * ========================================= */
            $user = DB::table('tbluser')
                ->select('allocated_standards')
                ->where('id', $user_id)
                ->where('sub_institute_id', $sub_institute_id)
                ->first();

            if (!$user || empty($user->allocated_standards)) {
                return response()->json([
                    'status_code' => 0,
                    'message' => 'Jobrole not allocated to user'
                ]);
            }

            $jobrole_id = $user->allocated_standards;

            /* =========================================
             * 2️⃣ Get skill_ids from s_library_map
             * ========================================= */
            $library = DB::table('s_library_map')
                ->where('type', 'jobrole')
                ->where('type_id', $jobrole_id)
                ->first();

            if (!$library || empty($library->skill_ids)) {
                return response()->json([
                    'status_code' => 0,
                    'message' => 'No skills mapped with this jobrole'
                ]);
            }

            /* =========================================
             * 3️⃣ Convert skill_ids string to array
             * ========================================= */
            $skillIds = explode(',', $library->skill_ids);

            /* =========================================
             * 4️⃣ Fetch Skill Names from s_users_skills
             * ========================================= */
            $skills = DB::table('s_users_skills')
                ->whereIn('id', $skillIds)
                ->where('sub_institute_id', $sub_institute_id)
                ->select('id', 'title as skill_name')
                ->get();
            /* =========================================
             * 5️⃣ Response
             * ========================================= */
            return response()->json([
                'status_code' => 1,
                'message' => 'Skills fetched successfully',
                'data' => $skills
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status_code' => 0,
                'message' => $e->getMessage()
            ]);
        }
    }
}
