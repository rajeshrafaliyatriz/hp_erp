<?php

namespace App\Http\Controllers\talent;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\DB;
use App\Models\talent\talent_jobapplication;

class InterviewController extends Controller
{
    public function getPositions(Request $request)
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
        $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id');

        $positions = DB::table('talent_job_postings')
                    ->where('sub_institute_id', $subInstituteId)
                    ->select('id','title')
                    ->distinct()
                    ->get();

        return response()->json([
            'status' => 1,
            'data' => $positions
        ]);
    }

    public function getInterviewers(Request $request)
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
        $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id');
        
        
        $candidates = DB::table('talent_job_applications')
        ->whereRaw('status = "Shortlisted"')        // ✅ only shortlisted
        ->where('sub_institute_id', $subInstituteId)
        ->select(
            'id',
            'job_id',
            DB::raw("CONCAT(first_name, ' ', last_name) as candidate_name"),
            'email',
            'mobile'
        )
        ->get();
        return response()->json([
            'status' => 1,
            'data' => $candidates
        ]);
    }
}
