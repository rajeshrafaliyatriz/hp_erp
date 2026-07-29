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
                            ->where('a.status', 'Scheduled')
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

      

          // Normalize interviewer_id to array
          if (is_string($request->interviewer_id)) {
              $decoded = json_decode($request->interviewer_id, true);
              if (is_array($decoded)) {
                  $request->merge(['interviewer_id' => array_map('intval', $decoded)]);
              } else {
                  $request->merge(['interviewer_id' => array_map('intval', array_map('trim', explode(',', $request->interviewer_id)))]);
              }
          }

          $validator = Validator::make($request->all(), [
            'job_id'            => 'required|integer|exists:talent_job_postings,id',
            'applicant_id'      => 'required|string|max:255',
            'round_no'          => 'nullable|string|max:255',
            'interview_date'    => 'nullable|date|after_or_equal:today',
            'time'              => 'nullable|string|max:255',
            'duration'          => 'nullable|integer',
            'location'          => 'nullable|string|max:255',
            'interviewer_id'    => 'required|array',
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

        $candidate = \App\Models\talent\talent_jobapplication::where('id', $request->applicant_id)
            ->where('sub_institute_id', $sub_institute_id)
            ->first();
        if (!$candidate) {
            return response()->json(['message' => 'Candidate not found.'], 404);
        }
        if ($candidate->hasReachedOfferOrHiredStage()) {
            return response()->json([
                'message' => 'An interview cannot be scheduled after an offer has been sent or the candidate has been hired.',
            ], 422);
        }

        try {
            $objtalent = new talent_interviewschedules();
            $objtalent->job_id = $request->job_id;
            $objtalent->applicant_id = $request->applicant_id;
            $objtalent->round_no = ((int) talent_interviewschedules::where(
                'applicant_id',
                $request->applicant_id
            )->max('round_no')) + 1;
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

            // Normalize interviewer_id to array
            if (is_string($request->interviewer_id)) {
                $decoded = json_decode($request->interviewer_id, true);
                if (is_array($decoded)) {
                    $request->merge(['interviewer_id' => array_map('intval', $decoded)]);
                } else {
                    $request->merge(['interviewer_id' => array_map('intval', array_map('trim', explode(',', $request->interviewer_id)))]);
                }
            }

            // 🧾 Validation
            $validator = Validator::make($request->all(), [
                'job_id'            => 'nullable|integer|exists:talent_job_postings,id',
                'applicant_id'      => 'nullable|string|max:255',
                'round_no'          => 'nullable|string|max:255',
                'interview_date'    => 'nullable|date',
                'time'              => 'nullable|string|max:255',
                'duration'          => 'nullable|integer',
                'location'          => 'nullable|string|max:255',
                'interviewer_id'    => 'required|array',
                'status' => 'required|in:Scheduled,Completed,Under Review,Pending Review,Rejected,Selected,Accepted,active',
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
                    'Scheduled',
                    'Completed',
                    'Under Review',
                    'Pending Review',
                    'Rejected',
                    'Selected',
                    'Accepted',
                    'active'
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

    public function customUpdate(Request $request)
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

            // Normalize interviewer_id to array
            if (is_string($request->interviewer_id)) {
                $decoded = json_decode($request->interviewer_id, true);
                if (is_array($decoded)) {
                    $request->merge(['interviewer_id' => array_map('intval', $decoded)]);
                } else {
                    $request->merge(['interviewer_id' => array_map('intval', array_map('trim', explode(',', $request->interviewer_id)))]);
                }
            }

            // 🧾 Validation
            $validator = Validator::make($request->all(), [
                'id'                => 'required|integer',
                'round_no'          => 'nullable|string|max:255',
                'interview_date'    => 'nullable|date',
                'time'              => 'nullable|string|max:255',
                'duration'          => 'nullable|integer',
                'location'          => 'nullable|string|max:255',
                'interviewer_id'    => 'required|array',
                'status'            => 'required|in:Scheduled,Completed,Under Review,Pending Review,Rejected,Selected,Accepted,active',
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
                $interview_schedules = talent_interviewschedules::find($request->id);

                if (!$interview_schedules) {
                    return response()->json(['message' => 'Interview schedule not found'], 404);
                }

                // 🧱 Update editable fields
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
                    'Scheduled',
                    'Completed',
                    'Under Review',
                    'Pending Review',
                    'Rejected',
                    'Selected',
                    'Accepted',
                    'active'
                ];

                if ($request->filled('status') && in_array($request->status, $allowedStatuses)) {
                    $interview_schedules->status = $request->status;
                }

                $interview_schedules->updated_by = $request->user_id;
                $interview_schedules->sub_institute_id = $sub_institute_id;

                // 💾 Save update
                if ($interview_schedules->save()) {
                    return response()->json([
                        'message' => 'Interview schedule updated successfully!',
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

    /**
     * Get candidate details for frontend.
     */
    public function getInterviewDetails(Request $request)
    {
        try {
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
            } else {
                $sub_institute_id = $request->sub_institute_id ?? null;
            }

            // Fetch candidate details from database
            $candidates = DB::table('talent_job_applications as tja')
                ->leftJoin('talent_interview_schedules as tis', function($join){
                    $join->on('tja.id','=','tis.applicant_id')
                         ->whereRaw('tis.round_no = (SELECT MAX(round_no) FROM talent_interview_schedules WHERE applicant_id = tja.id)');
                })
                ->leftJoin('talent_job_postings as tjp', 'tja.job_id', '=', 'tjp.id')
                ->leftJoin('talent_interview_panel as tip', 'tis.panel_id', '=', 'tip.id')
                ->select(
                    'tja.id as candidate_id',
                    DB::raw("CONCAT_WS(' ', tja.first_name, tja.middle_name, tja.last_name) AS candidate_name"),
                    'tja.email',
                    'tjp.id as position_id',
                    'tjp.title as position',
                    'tja.status',
                    'tis.status as stage',
                    'tja.applied_date',
                    DB::raw("CONCAT(tis.interview_date,' ',tis.time) AS next_interview"),
                    'tis.rating as score',
                    'tis.panel_id',
                    'tis.id as scheduled_id',
                    'tip.panel_name',
                    'tis.location',
                    'tis.duration'
                )
                ->where('tja.sub_institute_id', $sub_institute_id)
                ->where('tis.status', 'Scheduled')
                ->where('tja.status', '!=', 'Hired')
                ->get();

            return response()->json([
                'message' => 'Candidate details fetched successfully',
                'data' => $candidates
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
     public function candidatepipeline(Request $request)
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

                // Define the pipeline stages
                $stages = [
                    'Application Review' => ['status' => ['applied', 'under_review']],
                    'Phone Screening' => ['status' => ['shortlisted', 'phone_screening']],
                    'Technical Interview' => ['round' => 'technical'],
                    'Final Interview' => ['round' => 'final'],
                    'Offer Extended' => ['status' => ['offered']],
                ];

                $data = [];
                $totalApplications = DB::table('talent_job_applications')
                    ->where('sub_institute_id', $sub_institute_id)
                    ->whereNull('deleted_at')
                    ->count();

                foreach ($stages as $label => $criteria) {
                    $query = DB::table('talent_job_applications as ja')
                        ->where('ja.sub_institute_id', $sub_institute_id)
                        ->whereNull('ja.deleted_at');

                    if (isset($criteria['status'])) {
                        $query->whereIn('ja.status', $criteria['status']);
                    } elseif (isset($criteria['round'])) {
                        $query->join('talent_interview_schedules as tis', 'ja.id', '=', 'tis.applicant_id')
                              ->where('tis.round_no', $criteria['round'])
                              ->where('tis.status', 'scheduled');
                    }

                    $count = $query->count();

                    // Calculate trend (compare with previous week)
                    $previousQuery = clone $query;
                    $previousCount = $previousQuery->where('ja.created_at', '<', now()->subDays(7))->count();
                    $change = $previousCount > 0 ? (($count - $previousCount) / $previousCount) * 100 : 0;

                    $data[] = [
                        'stage' => $label,
                        'count' => $count,
                        'change' => ($change > 0 ? '+' : '') . round($change, 1) . '%',
                    ];
                }

                // Calculate conversion rate (hired / total applications)
                $hiredCount = DB::table('talent_job_applications')
                    ->where('sub_institute_id', $sub_institute_id)
                    ->where('status', 'hired')
                    ->whereNull('deleted_at')
                    ->count();
                $conversionRate = $totalApplications > 0 ? round(($hiredCount / $totalApplications) * 100, 1) . '%' : '0%';

                // Calculate average time to hire
                $avgTimeToHire = DB::select("
                    SELECT AVG(DATEDIFF(ja.updated_at, ja.created_at)) as avg_days
                    FROM talent_job_applications ja
                    WHERE ja.sub_institute_id = ? AND ja.status = 'hired' AND ja.updated_at IS NOT NULL
                ", [$sub_institute_id]);

                $averageTimeToHire = $avgTimeToHire[0]->avg_days ? round($avgTimeToHire[0]->avg_days) . ' days' : 'N/A';

                return response()->json([
                    'message' => 'Candidate pipeline fetched successfully',
                    'data' => [
                        'pipeline' => $data,
                        'conversion_rate' => $conversionRate,
                        'average_time_to_hire' => $averageTimeToHire
                    ]
                ], 200);
            }

            return response()->json(['message' => 'Invalid type'], 400);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

}
