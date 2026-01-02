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
        $data = DB::table('talent_evaluation_form as tef')
            ->leftJoin('talent_job_postings as tjp', 'tef.job_id', '=', 'tjp.id')
            ->leftJoin('talent_job_applications as tja', 'tef.candidate_id', '=', 'tja.id')
            ->leftJoin('talent_interview_panel as tip', 'tef.panel_id', '=', 'tip.id')
            ->select(
                'tef.*',
                'tjp.title as job_title',
                DB::raw("CONCAT(tja.first_name, ' ', COALESCE(tja.middle_name, ''), ' ', tja.last_name) as candidate_name"),
                'tja.email as candidate_email',
                'tip.panel_name'
            )
            ->where('tef.candidate_id', $id)
            ->orderBy('tef.created_at', 'DESC') // latest feedback
            ->first();

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'No feedback found for this candidate'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Feedback Found',
            'data' => $data
        ], 200);
    }
    public function storeFeedback(Request $request)
    {
        $type = $request->type;

        // 🔐 API Token Validation
        if ($type === "API") {

            $token = $request->bearerToken()
                ?? $request->input('token');

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
            'job_id'                      => 'required|integer',
            'candidate_id'                => 'required|integer',
            'panel_id'              => 'required|integer',

            'evaluation_criteria'         => 'required|array',
            'evaluation_criteria.*.name'  => 'required|string',
            'evaluation_criteria.*.score' => 'required|numeric|min:1|max:10',

            'recommendation'              => 'nullable|string',
            'key_strengths'               => 'nullable|string',
            'areas_of_concern'            => 'nullable|string',
            'additional_comments'         => 'nullable|string',
        ]);
        // No conversion needed, store as array (will be JSON in DB due to cast)

        // 💾 Save into database
        $evaluation = TalentEvaluationForm::create([
            'job_id'               => $request->job_id,
            'candidate_id'         => $request->candidate_id,
            'panel_id'       => $request->panel_id,
            'evaluation_criteria'  => $request->evaluation_criteria, // array, will be stored as JSON
            'recommendation'       => $request->recommendation,
            'key_strengths'        => $request->key_strengths,
            'areas_of_concern'     => $request->areas_of_concern,
            'additional_comments'  => $request->additional_comments,
            'sub_institute_id'     => $subInstituteId,
        ]);

        // 📤 Response
        return response()->json([
            'status' => true,
            'message' => 'Feedback saved successfully'
        ], 200);
    }

    public function updateFeedback(Request $request, $id)
    {
        $type = $request->type;

        // 🔐 API Token Validation
        if ($type === "API") {

            $token = $request->bearerToken()
                ?? $request->input('token');

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
            'job_id'                      => 'required|integer',
            'candidate_id'                => 'required|integer',
            'panel_id'              => 'required|integer',

            'evaluation_criteria'         => 'required|array',
            'evaluation_criteria.*.name'  => 'required|string',
            'evaluation_criteria.*.score' => 'required|numeric|min:1|max:10',

            'recommendation'              => 'nullable|string',
            'key_strengths'               => 'nullable|string',
            'areas_of_concern'            => 'nullable|string',
            'additional_comments'         => 'nullable|string',
        ]);

        // Find the feedback record
        $evaluation = TalentEvaluationForm::find($id);
        if (!$evaluation) {
            return response()->json([
                'status' => false,
                'message' => 'Feedback not found'
            ], 404);
        }

        // 💾 Update the database
        $evaluation->update([
            'job_id'               => $request->job_id,
            'candidate_id'         => $request->candidate_id,
            'panel_id'       => $request->panel_id,
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
            'message' => 'Feedback updated successfully'
        ], 200);
    }
}
