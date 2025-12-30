<?php

namespace App\Http\Controllers\talent;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use function App\Helpers\is_mobile;
use Illuminate\Support\Facades\Validator;
use App\Models\talent\talent_interviewschedules;
use App\Models\talent\talent_jobposting;


class talent_interviewschedulescontroller extends Controller
{
    public function index(request $request)
    {
        {
        try {
            $type = $request->type; // API or web

            if ($type == 'API') {
                // validate token
                $token = $request->input('token');
                if (!$token) {
                    return response()->json(['message' => 'Token not provided'], 401);
                }

                $accessToken = PersonalAccessToken::findToken($token);
                if (!$accessToken) {
                    return response()->json(['message' => 'Invalid token'], 401);
                }

                // validate required params
                $validator = Validator::make($request->all(), [
                    'sub_institute_id' => 'required',
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'status_code' => 0,
                        'message' => $validator->errors()->first()
                    ], 400);
                }

                $sub_institute_id = $request->sub_institute_id;

                // fetch jobrole data from table
                $interview_schedules = DB::table('talent_interview_schedules as a')
                            ->select('*')
                            ->where('a.sub_institute_id',$sub_institute_id)
                            ->get();


                return response()->json([
                    'message' => ' fetched successfully',
                    'data'    => $interview_schedules
                ], 200);
            }
            $res['interview_schedules'] = DB::table('talent_interview_schedules')
                    ->select('*')
                    ->get();
            return is_mobile($type, 'interview_schedules.index', $res, 'view');

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
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

        $sub_institute_id = $request->get('sub_institute_id');

      

          $validator = Validator::make($request->all(), [
            'job_id'            => 'required|integer|exists:talent_job_postings,id',
            'applicant_id'      => 'required|string|max:255',
            'round_no'          => 'nullable|string|max:255',
            'interview_date'    => 'nullable|date|after_or_equal:today',
            'time'              => 'nullable|string|max:255',
            'duration'          => 'nullable|integer',
            'location'          => 'nullable|string|max:255',
            'interviewer_id'    => 'required|string|max:255',
            'status'            => 'required|in:Scheduled,Completed,Under Review,Pending Review,Rejected,Selected,Accepted,active',
            'rating'            => 'nullable|string|max:255',
            'feedback'          => 'nullable|string|max:100',
            'additional_notes'  => 'nullable|string|max:1000',
            'sub_institute_id'  => 'required|integer',
            'user_id'           => 'required|integer',
            'panel_id'          => 'nullable|integer|exists:talent_interview_panel,id'
        ], [
            'interview_date.after_or_equal' => 'Previous date is not allowed. Please select today or a future date.'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->messages()->first()
            ], 422);
        }

        try {
            $objtalent = new talent_interviewschedules();
            $objtalent->job_id = $request->job_id;
            $objtalent->applicant_id = $request->applicant_id;
            $objtalent->round_no = $request->round_no;
            $objtalent->interview_date = $request->interview_date;
            $objtalent->time = $request->time;
            $objtalent->duration = $request->duration;
            $objtalent->location = $request->location;
            $objtalent->interviewer_id = $request->interviewer_id;
            $objtalent->status = $request->status;
            $objtalent->rating = $request->rating;
            $objtalent->feedback = $request->feedback;
            $objtalent->additional_notes = $request->additional_notes;
            $objtalent->sub_institute_id = $sub_institute_id;
            $objtalent->created_by = $request->user_id;
            $objtalent->panel_id = $request->panel_id;

            if ($objtalent->save()) {
                return response()->json(['message' => 'added successfully !!','data' => $objtalent], 200);
            }

            return response()->json(['message' => 'Something went wrong !!'], 500);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }

    }

    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $type = $request->type;
    
        if ($type == "API") {
            $token = $request->input('token');
    
            // 🔒 Validate token
            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }
    
            $accessToken = PersonalAccessToken::findToken($token);
            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }
    
            $sub_institute_id = $request->get('sub_institute_id');
    
            // 🧾 Validation
            $validator = \Validator::make($request->all(), [
                'job_id'            => 'required|integer|exists:talent_job_postings,id',
                'applicant_id'      => 'required|string|max:255',
                'round_no'          => 'nullable|string|max:255',
                'interview_date'    => 'nullable|date',
                'time'              => 'nullable|string|max:255',
                'duration'          => 'nullable|integer',
                'location'          => 'nullable|string|max:255',
                'interviewer_id'    => 'required|string|max:255',
                'status'            => 'required|in:Pending Review,Under Review,Shortlisted,Interview Scheduled,Rejected,Hired',
                'rating'            => 'nullable|string|max:255',
                'feedback'          => 'nullable|string|max:100',
                'additional_notes'  => 'nullable|string|max:1000',
                'sub_institute_id'  => 'required|integer',
                'user_id'           => 'required|integer',
                'panel_id'          => 'nullable|integer|exists:talent_interview_panel,id'
                ]);
    
            if ($validator->fails()) {
                return response()->json([
                    'status' => 0,
                    'message' => $validator->messages()->first()
                ], 422);
            }
    
            try {
                // 🧠 Find existing application
                $interview_schedules = talent_interviewschedules::find($id);
    
                if (!$interview_schedules) {
                    return response()->json(['message' => 'interview schedules not found'], 404);
                }
    
                // 🧩 Update job info if job_id is changed
                if ($request->filled('job_id')) {
                    $jobData = talent_jobposting::find($request->job_id);
                    if ($jobData) {
                        $interview_schedules->job_id = $jobData->id;
                    }
                }
    
                // 🧱 Update editable fields
                $interview_schedules->applicant_id = $request->applicant_id ?? $interview_schedules->applicant_id;
                $interview_schedules->round_no = $request->round_no ?? $interview_schedules->round_no;
                $interview_schedules->interview_date = $request->interview_date ?? $interview_schedules->interview_date;
                $interview_schedules->time = $request->time ?? $interview_schedules->time;
                $interview_schedules->duration = $request->duration ?? $interview_schedules->duration;
                $interview_schedules->location = $request->location ?? $interview_schedules->location;
                $interview_schedules->interviewer_id = $request->interviewer_id ?? $interview_schedules->interviewer_id;
                $interview_schedules->status = $request->status ?? $interview_schedules->status;
                $interview_schedules->rating = $request->rating ?? $interview_schedules->rating;
                $interview_schedules->feedback = $request->feedback ?? $interview_schedules->feedback;
                $interview_schedules->additional_notes = $request->additional_notes ?? $interview_schedules->additional_notes;
                $interview_schedules->panel_id = $request->panel_id ?? $interview_schedules->panel_id;
    
                // 🎯 Dynamic Status Validation
                $allowedStatuses = [
                    'Pending Review',
                    'Under Review',
                    'Shortlisted',
                    'Interview Scheduled',
                    'Rejected',
                    'Hired'
                ];
    
                if ($request->filled('status') && in_array($request->status, $allowedStatuses)) {
                    $interview_schedules->status = $request->status;
                }
    
                $interview_schedules->updated_by = $request->user_id;
                $interview_schedules->sub_institute_id = $sub_institute_id;
    
                // 💾 Save update
                if ($interview_schedules->save()) {
                    return response()->json([
                        'message' => 'interview schedules updated successfully!',
                        'data' => $interview_schedules
                    ], 200);
                }
    
                return response()->json(['message' => 'Update failed!'], 500);
    
            } catch (\Exception $e) {
                return response()->json(['error' => $e->getMessage()], 500);
            }
        }
    
        return response()->json(['message' => 'Invalid request type'], 400);
    }
    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        //
    }
}
