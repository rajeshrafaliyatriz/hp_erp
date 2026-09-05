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
        if (!$subInstituteId) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        /*
         * ── THE CONTRACT, AND ITS THREE FAULTS ──────────────────────────────
         *
         * The hiring decision screen has never worked. It sends
         * { decision: 'hired' | 'rejected' } to POST /interviews/{id}/decision,
         * and this method disagreed with it in three separate ways:
         *
         *   1. it required a field called `status`, so `required` always failed;
         *   2. its `in:` list was Title Case, so even renamed the value failed;
         *   3. the {id} in the path is an INTERVIEW id, and it was looked up as a
         *      TalentEvaluationForm id - so a correct call would still 404.
         *
         * The route name is the honest one, so the id is resolved as an interview
         * and the evaluation form is found through it. Both field names and either
         * casing are accepted: the client is not wrong to say "decision".
         */
        $decision = $request->input('decision', $request->input('status'));
        $decision = is_string($decision) ? ucwords(strtolower(trim($decision))) : $decision;
        $request->merge(['decision' => $decision]);

        $validator = Validator::make($request->all(), [
            'decision' => 'required|in:Hired,Rejected,Under Review,Completed',
            'notes'    => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // The path id is an interview. Fall back to an evaluation-form id so any
        // caller built against the old (broken) shape keeps working.
        $interview = \App\Models\talent\talent_interviewschedules::where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->first();

        $evaluation = $interview
            ? TalentEvaluationForm::where('candidate_id', $interview->applicant_id)
                ->where('sub_institute_id', $subInstituteId)
                ->latest('id')
                ->first()
            : TalentEvaluationForm::where('id', $id)
                ->where('sub_institute_id', $subInstituteId)
                ->first();

        $candidateId = $interview->applicant_id ?? $evaluation->candidate_id ?? null;

        if (!$candidateId) {
            return response()->json([
                'status' => false,
                'message' => 'Interview not found'
            ], 404);
        }

        DB::transaction(function () use ($evaluation, $candidateId, $decision, $request, $subInstituteId) {
            if ($evaluation) {
                $updateData = ['status' => $decision];
                if ($request->has('notes')) {
                    $updateData['notes'] = $request->notes;
                }
                $evaluation->update($updateData);
            }

            // Tenant-scoped. This update carried no tenant predicate, so a decision
            // recorded against a foreign candidate id would have written to it.
            talent_jobapplication::where('id', $candidateId)
                ->where('sub_institute_id', $subInstituteId)
                ->update(['status' => $decision, 'updated_at' => now()]);
        });

        return response()->json([
            'status'  => true,
            'message' => 'Hiring decision recorded successfully',
            'data'    => [
                'candidate_id' => (int) $candidateId,
                'decision'     => $decision,
            ],
        ], 200);
    }
}
