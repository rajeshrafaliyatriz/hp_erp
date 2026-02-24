<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class courseRecommandation extends Controller
{
    /**
     * Get recommended courses for a logged-in user based on similar users
     * with matching allocated standards within the same sub_institute.
     * 
     * Route: COURSE-RECOMMENDATION
     * Method: GET
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Get the authenticated user or use request parameters
        $userId = $request->user_id ?? null;
        $subInstituteId = $request->sub_institute_id ?? null;

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated or user_id not provided'
            ], 401);
        }

        if (!$subInstituteId) {
            return response()->json([
                'success' => false,
                'message' => 'Sub institute ID not found'
            ], 400);
        }

        // Build the subquery for similar users
        $similarUsersSubquery = DB::table('tbluser as b1')
            ->select(
                'b1.sub_institute_id',
                'b1.id',
                DB::raw("GROUP_CONCAT(DISTINCT CONCAT_WS(' ', b2.first_name, b2.last_name, b2.middle_name) SEPARATOR '||') as similar_users_name"),
                DB::raw('GROUP_CONCAT(DISTINCT b2.id) as similar_users_ids')
            )
            ->leftJoin('tbluser as b2', function ($join) {
                $join->on('b2.sub_institute_id', '=', 'b1.sub_institute_id')
                    ->where('b2.id', '!=', DB::raw('b1.id'))
                    ->where(function ($query) {
                        $query->whereRaw('FIND_IN_SET(b1.allocated_standards, b2.allocated_standards)')
                              ->orWhereRaw('FIND_IN_SET(b2.allocated_standards, b1.allocated_standards)');
                    });
            })
            ->groupBy('b1.sub_institute_id', 'b1.id');

        // Build the main query
        $results = DB::table('tbluser as a')
            ->select([
                'a.id',
                DB::raw("CONCAT_WS(' ', a.first_name, a.last_name, a.middle_name) as name"),
                'similar_users.similar_users_name',
                'similar_users.similar_users_ids',
                'c.course_id',
                'c.user_id as created_course_user',
                DB::raw("CONCAT_WS(' ', u.first_name, u.last_name, u.middle_name) as created_user_name"),
                'd.display_name'
            ])
            ->leftJoinSub($similarUsersSubquery, 'similar_users', function ($join) {
                $join->on('similar_users.sub_institute_id', '=', 'a.sub_institute_id')
                    ->on('similar_users.id', '=', 'a.id');
            })
            ->leftJoin('lms_course_enroll as c', function ($join) {
                $join->on('c.sub_institute_id', '=', 'a.sub_institute_id')
                    ->whereRaw('FIND_IN_SET(c.user_id, similar_users.similar_users_ids)');
            })
            ->leftJoin('sub_std_map as d', 'd.id', '=', 'c.course_id')
            ->leftJoin('tbluser as u', 'u.id', '=', 'c.user_id')
            ->where('a.sub_institute_id', $subInstituteId)
            ->where('a.id', $userId)
            ->groupBy(
                'c.course_id',
                'd.display_name',
                'similar_users.similar_users_name',
                'similar_users.similar_users_ids',
                'a.id',
                'a.first_name',
                'a.last_name',
                'a.middle_name',
                'c.user_id',
                'u.first_name',
                'u.last_name',
                'u.middle_name'
            )
            ->orderBy('d.display_name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Course recommendations fetched successfully',
            'data' => $results
        ], 200);
    }
}
