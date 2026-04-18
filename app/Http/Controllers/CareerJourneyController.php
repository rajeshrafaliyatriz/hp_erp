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

        // 1. Get user's current jobrole
        $user = DB::table('tbluser')
            ->where('id', $userId)
            ->first();

        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User not found']);
        }

        $currentJobRoleId = $user->allocated_standards;

        // 2. Get all career journey steps for that sub institute
        $journeys = DB::table('career_journey')
            ->where('sub_institute_id', $subInstituteId)
            ->orderBy('id')
            ->get();

        $result = [];

        foreach ($journeys as $journey) {

            // Get TO job role details
            $toRole = DB::table('s_user_jobrole')
                ->where('id', $journey->to_jobrole_id)
                ->first();

            if (!$toRole) continue;

            // Status logic
            $status = 'upcoming';

            if ($journey->jobrole_id == $currentJobRoleId) {
                $status = 'current';
            } elseif ($journey->jobrole_id < $currentJobRoleId) {
                $status = 'completed';
            }

            $result[] = [
                'id' => $journey->id,
                'from_jobrole_id' => $journey->jobrole_id,
                'to_jobrole_id' => $journey->to_jobrole_id,

                'role_name' => $toRole->jobrole,
                'job_level' => $toRole->job_level, // MID / SENIOR

                'movement_type' => $journey->vertical_lateral_movement == 1 ? 'vertical' : 'lateral',

                'status' => $status,

                // Optional UI percentage
                'progress' => $status == 'current' ? '100%' :
                              ($status == 'completed' ? '100%' : '0%')
            ];
        }

        return response()->json([
            'status' => true,
            'data' => $result
        ]);
    }
}
