<?php

namespace App\Http\Controllers\talent\feedback;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\DB;
use App\Models\auth\tbluserModel;
use App\Models\talent\talent_interviewschedules;
use App\Models\talent\interview_panel\talent_interviewpanel;
use App\Models\talent\talent_jobapplication;
use App\Models\talent\feedback\TalentEvaluationForm;


class feedbackController extends Controller
{
     public function getFeedback(Request $request, $id)
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
            $data = DB::table('talent_job_applications as tja')
            ->leftJoin('talent_interview_schedules as tis', 'tja.id', '=', 'tis.applicant_id')
            ->leftJoin('tbluser as u', 'tis.interviewer_id', '=', 'u.id') // interviewer tbl
            ->select(
                DB::raw("CONCAT(tja.first_name,' ',tja.last_name) AS candidate_name"),
                'tja.job_id as position',
                'tis.interview_date',
                'tis.time',
                'tis.status as interview_type',
                DB::raw("CONCAT(u.first_name,' ',u.last_name) as interviewer_name")
            )
            ->where('tja.id', $id)
            ->orderBy('tis.round_no', 'DESC') // last scheduled round
            ->first();

        if(!$data){
            return response()->json([
                'status' => false,
                'message' => 'No interview found for this candidate'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Interview Details Found',
            'data' => $data
        ], 200);
    }
    public function storeFeedback(Request $request)
{
    $type = $request->type;

    // 🔐 API Token Validation
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

    // 📥 Convert JSON string to array if needed
    if (is_string($request->evaluation_criteria)) {
        $request->merge(['evaluation_criteria' => json_decode($request->evaluation_criteria, true)]);
    }

    // ✅ Validation
    $request->validate([
        'job_id'                  => 'required|integer',
        'candidate_id'            => 'required|integer',
        'interviewer_id'          => 'required|integer',
        'evaluation_criteria'     => 'required|array',
        'evaluation_criteria.*.name'  => 'required|string|max:50',
        'evaluation_criteria.*.score' => 'required|numeric|min:0|max:100',
        'recommendation'          => 'required|string',
        'key_strengths'           => 'nullable|string|max:50',
        'areas_of_concern'        => 'nullable|string|max:50',
        'additional_comments'     => 'nullable|string|max:255',
    ]);

    // 🛠️ Convert Evaluation Criteria Array → Comma Separated String
    if (is_array($request->evaluation_criteria)) {
        $formattedCriteria = collect($request->evaluation_criteria)->map(function ($item) {
            return "{$item['name']}: {$item['score']}";
        })->implode(', ');
        // ⬇️ Update request data
        $request->merge(['evaluation_criteria' => $formattedCriteria]);
    }

    // 💾 Save into database
    $evaluation = TalentEvaluationForm::create([
        'job_id'               => $request->job_id,
        'candidate_id'         => $request->candidate_id,
        'interviewer_id'       => $request->interviewer_id,
        'evaluation_criteria'  => $request->evaluation_criteria,
        'recommendation'       => $request->recommendation,
        'key_strengths'        => $request->key_strengths,
        'areas_of_concern'     => $request->areas_of_concern,
        'additional_comments'  => $request->additional_comments,
        'sub_institute_id'     => $subInstituteId,
    ]);

    // 📤 Response
    return response()->json([
        'status' => true,
        'message' => 'Evaluation submitted successfully',
        'data' => $evaluation
    ], 201);
}

}
