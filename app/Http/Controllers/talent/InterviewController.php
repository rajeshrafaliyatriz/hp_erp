<?php

namespace App\Http\Controllers\talent;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\DB;
use App\Models\talent\talent_jobapplication;
use App\Models\talent\feedback\TalentEvaluationForm;

class InterviewController extends Controller
{
    use ResolvesApiIdentity;

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
        $subInstituteId = $this->apiTenantId($request);

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
        $subInstituteId = $this->apiTenantId($request);
        
        
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

    public function recordDecision(Request $request, $id)
    {
        $type = $request->type;

        if ($type == "API") {
            $token = $request->bearerToken() ?? $request->input('token');
            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            $accessToken = PersonalAccessToken::findToken($token);
            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }
        }

        $subInstituteId = $this->apiTenantId($request);

        // Validate the request
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:Under Review,Hired,Rejected',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Find the evaluation form
        $evaluation = TalentEvaluationForm::where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->first();

        if (!$evaluation) {
            return response()->json([
                'status' => false,
                'message' => 'Evaluation form not found'
            ], 404);
        }

        // Update evaluation form status
        $evaluationStatus = '';
        if ($request->status === 'Hired') {
            $evaluationStatus = 'Hired';
        } elseif ($request->status === 'Rejected') {
            $evaluationStatus = 'Rejected';
        } elseif ($request->status === 'Completed') {
            $evaluationStatus = 'Completed';
        }
        $updateData = ['status' => $evaluationStatus];
        if ($request->has('notes')) {
            $updateData['notes'] = $request->notes;
        }
        $evaluation->update($updateData);

        // Update job application status
        talent_jobapplication::where('id', $evaluation->candidate_id)->update(['status' => $request->status]);

        return response()->json([
            'status' => true,
            'message' => 'Hiring decision recorded successfully'
        ], 200);
    }
}
