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
        $userId = $request->user_id ?? Auth::id();
        $subInstituteId = $request->sub_institute_id ?? Auth::user()->sub_institute_id ?? null;

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

        // Get user's allocated standards
        $user = DB::table('tbluser')
            ->where('id', $userId)
            ->where('sub_institute_id', $subInstituteId)
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $allocatedStandards = $user->allocated_standards;

        // Get similar users (users with matching allocated standards in same sub_institute)
        $similarUsers = DB::table('tbluser as b')
            ->where('b.sub_institute_id', $subInstituteId)
            ->where('b.id', '!=', $userId)
            ->where(function ($query) use ($allocatedStandards) {
                $query->whereRaw('FIND_IN_SET(?, b.allocated_standards)', [$allocatedStandards])
                      ->orWhereRaw('FIND_IN_SET(b.allocated_standards, ?)', [$allocatedStandards]);
            })
            ->pluck('b.id')
            ->toArray();

        // Build the main query
        $results = DB::table('tbluser as a')
            ->select([
                'a.id',
                DB::raw("CONCAT_WS(' ', a.first_name, a.last_name, a.middle_name) as name"),
                DB::raw('(
                    SELECT GROUP_CONCAT(DISTINCT b.id)
                    FROM tbluser b
                    WHERE b.sub_institute_id = a.sub_institute_id
                    AND b.id != a.id
                    AND (
                        FIND_IN_SET(a.allocated_standards, b.allocated_standards)
                        OR FIND_IN_SET(b.allocated_standards, a.allocated_standards)
                    )
                ) as similar_users'),
                'c.course_id',
                'c.user_id as created_course_user',
                'd.display_name'
            ])
            ->leftJoin('lms_course_enroll as c', function ($join) use ($subInstituteId, $similarUsers) {
                $join->on('c.sub_institute_id', '=', 'a.sub_institute_id')
                    ->whereIn('c.user_id', $similarUsers);
            })
            ->leftJoin('sub_std_map as d', 'd.id', '=', 'c.course_id')
            ->where('a.sub_institute_id', $subInstituteId)
            ->where('a.id', $userId)
            ->groupBy('c.course_id', 'd.display_name', 'a.id', 'a.first_name', 'a.last_name', 'a.middle_name', 'c.user_id')
            ->orderBy('d.display_name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Course recommendations fetched successfully',
            'data' => $results
        ], 200);
    }
}
