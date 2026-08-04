<?php

namespace App\Http\Controllers\Api\Talent;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Talent\Concerns\ResolvesTalentContext;
use App\Models\Talent\ExitInterview;
use App\Models\Talent\OffboardingCase;
use Illuminate\Http\Request;

class ExitInterviewController extends Controller
{
    use ResolvesTalentContext;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];

        // Return exit interviews with optional pagination
        $query = ExitInterview::where('sub_institute_id', $tenant);

        if ($request->has('case_id')) {
            $query->where('case_id', $request->query('case_id'));
        }
        
        $interviews = $query->get();

        return response()->json([
            'data' => $interviews,
            'meta' => [
                'total' => $interviews->count()
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];

        $validated = $request->validate([
            'case_id'               => 'required|integer',
            'interviewer_id'        => 'nullable|integer',
            'interview_date'        => 'nullable|date',
            'status'                => 'nullable|string',
            'feedback_rating'       => 'nullable|integer|min:1|max:5',
            'reason_for_leaving'    => 'nullable|string',
            'positive_aspects'      => 'nullable|string',
            'areas_for_improvement' => 'nullable|string',
            'would_recommend'       => 'nullable|boolean',
            'rehire_eligibility'    => 'nullable|boolean',
            'comments'              => 'nullable|string',
        ]);

        $validated['sub_institute_id'] = $tenant;
        $validated['created_by'] = $context['user_id'];
        $validated['updated_by'] = $context['user_id'];

        // Check if an interview already exists for this case
        $existing = ExitInterview::where('sub_institute_id', $tenant)
            ->where('case_id', $validated['case_id'])
            ->first();

        if ($existing) {
            return response()->json(['error' => 'Exit interview already exists for this case.'], 422);
        }

        $interview = ExitInterview::create($validated);
        
        // Also update the OffboardingCase to reflect that an interview is scheduled/done
        $case = OffboardingCase::find($validated['case_id']);
        if ($case) {
            if ($interview->status === 'completed') {
                $case->exit_interview_done = true;
            }
            $case->exit_interview_date = $interview->interview_date;
            $case->save();
        }

        return response()->json(['data' => $interview], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        
        $interview = ExitInterview::where('sub_institute_id', $tenant)->find($id);
        if (!$interview) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json(['data' => $interview]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];

        $interview = ExitInterview::where('sub_institute_id', $tenant)->find($id);
        if (!$interview) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'interviewer_id'        => 'nullable|integer',
            'interview_date'        => 'nullable|date',
            'status'                => 'nullable|string',
            'feedback_rating'       => 'nullable|integer|min:1|max:5',
            'reason_for_leaving'    => 'nullable|string',
            'positive_aspects'      => 'nullable|string',
            'areas_for_improvement' => 'nullable|string',
            'would_recommend'       => 'nullable|boolean',
            'rehire_eligibility'    => 'nullable|boolean',
            'comments'              => 'nullable|string',
        ]);

        $validated['updated_by'] = $context['user_id'];
        $interview->update($validated);
        
        // Synchronize with offboarding case
        $case = OffboardingCase::find($interview->case_id);
        if ($case) {
            if ($interview->status === 'completed') {
                $case->exit_interview_done = true;
            }
            $case->exit_interview_date = $interview->interview_date;
            $case->save();
        }

        return response()->json(['data' => $interview]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];

        $interview = ExitInterview::where('sub_institute_id', $tenant)->find($id);
        if (!$interview) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $interview->deleted_by = $context['user_id'];
        $interview->save();
        
        $interview->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }
}
