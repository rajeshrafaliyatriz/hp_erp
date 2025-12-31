<?php

namespace App\Http\Controllers\talent\candidate;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use App\Models\talent\talent_interviewschedules;
use App\Models\talent\talent_jobapplication;

class candidateController extends Controller
{
    public function getCandidate(Request $request)
    {
                $type = $request->type;

        if ($type == "API") {

            $token = $request->input('token');
            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            $accessToken = PersonalAccessToken::findToken($token);
            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }
        }
        $sub_institute_id = $request->sub_institute_id; // Get institute wise data

        $data = DB::table('talent_job_applications as tja')
            ->leftJoin('talent_interview_schedules as tis', function($join){
                $join->on('tja.id','=','tis.applicant_id')
                     ->whereRaw('tis.round_no = (SELECT MAX(round_no) FROM talent_interview_schedules WHERE applicant_id = tja.id)');
            })
            ->select(
                // Candidate Name
                DB::raw("CONCAT(tja.first_name,' ',tja.last_name,' ',tja.email) AS candidate_name"),
            
                // Position / Job ID
                'tja.job_id as position',

                // Status
                'tja.status',

                // Stage from Interview Schedules
                'tis.status as stage',

                // Applied Date
                'tja.applied_date',

                // Next Interview
                DB::raw("CONCAT(tis.interview_date,' ',tis.time) AS next_interview"),

                // Score / Rating
                'tis.rating as score'
            )
            ->where('tja.sub_institute_id', $sub_institute_id)
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Candidate List',
            'data' => $data
        ], 200);
    }

    
}
