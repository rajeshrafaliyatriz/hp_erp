<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CareerJourneyController extends Controller
{
    public function getCareerJourney(Request $request)
    {
        $userId = $request->user_id;
        $subInstituteId = $request->sub_institute_id;

        // 1. Get user
        $user = DB::table('tbluser')
            ->where('id', $userId)
            ->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ]);
        }

        $currentRoleId = $user->allocated_standards;

        $result = [];
        $visited = []; // avoid infinite loop

        // 2. Start from current role
        while ($currentRoleId) {

            // prevent loop
            if (in_array($currentRoleId, $visited)) break;
            $visited[] = $currentRoleId;

            // get next journey step
            $journey = DB::table('career_journey as cj')
                ->join('s_user_jobrole as jr', 'jr.id', '=', 'cj.to_jobrole_id')
                ->where('cj.sub_institute_id', $subInstituteId)
                ->where('cj.jobrole_id', $currentRoleId)
                ->orderByDesc('cj.vertical_lateral_movement') // vertical first (optional)
                ->first();

            if (!$journey) break;

            $result[] = [
                'id' => $journey->id,
                'from_jobrole_id' => $journey->jobrole_id,
                'to_jobrole_id' => $journey->to_jobrole_id,

                'role_name' => $journey->jobrole,
                'job_level' => $journey->job_level,

                'movement_type' => $journey->vertical_lateral_movement == 1 ? 'vertical' : 'lateral',

                'status' => count($result) == 0 ? 'current' : 'upcoming',
                'progress' => count($result) == 0 ? '100%' : '0%'
            ];

            // move to next role
            $currentRoleId = $journey->to_jobrole_id;
        }

        return response()->json([
            'status' => true,
            'data' => $result
        ]);
    }
}
