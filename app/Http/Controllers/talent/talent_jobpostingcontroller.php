<?php

namespace App\Http\Controllers\talent;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use function App\Helpers\is_mobile;
use Illuminate\Support\Facades\Validator;
use App\Models\talent\talent_jobposting;
use App\Models\talent\talent_jobapplication;
use App\Models\talent\talent_interviewschedules;
use App\Models\talent\TalentOffer;
use App\Models\talent\feedback\TalentEvaluationForm;


class talent_jobpostingcontroller extends Controller
{
    use ResolvesApiIdentity;
    use \App\Http\Controllers\Concerns\ResolvesG2gActor;

    /**
     * The ACTING user, resolved from the token and never from the request.
     *
     * G-SEC-12. created_by / updated_by were taken from request input, so a caller
     * could attribute their own write to another user and the audit trail would
     * record it as fact. A leak exposes data; this corrupts the record of who did
     * what - the evidence you would rely on when investigating a leak.
     *
     * Blocks the event store: actor_id on every event has to be trustworthy or the
     * store inherits a corrupted audit trail on day one.
     *
     * Same shape as payrollActorId (D-004): token first, session fallback.
     */


    public function index(request $request)
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

                $sub_institute_id = $this->apiTenantId($request);

                // Auto-update expired job postings to inactive
                DB::table('talent_job_postings')
                    ->where('sub_institute_id', $sub_institute_id)
                    ->where('status', 'active')
                    ->whereNotNull('deadline')
                    ->where('deadline', '<', now())
                    ->update(['status' => 'inactive', 'updated_at' => now()]);

                // fetch jobrole data from table
                $talent = DB::table('talent_job_postings as a')
                            ->leftJoin('hrms_departments as d', 'a.department_id', '=', 'd.id')
                            ->select('a.*', 'd.department as department_name')
                            ->where('a.sub_institute_id',$sub_institute_id)
                            ->whereNull('a.deleted_at')
                            ->get();


                return response()->json([
                    'message' => ' fetched successfully',
                    'data'    => $talent
                ], 200);
            }
            $res['talent'] = DB::table('talent_job_postings')
                    ->select('id', 'sub_institute_id', 'status')
                    ->whereNull('deleted_at')
                    ->get();
            return is_mobile($type, 'talent.index', $res, 'view');

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
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
            'title'            => 'required|string|max:255',
            'department_id'    => 'required|integer',
            'location'         => 'required|string|max:255',
            'employment_type'  => 'required|string|max:100',
            'experience'       => 'nullable|string|max:255',
            'education'        => 'nullable|string|max:255',
            'priority_level'   => 'nullable|string|max:100',
            'positions'        => 'required|integer|min:1',
            'min_salary'       => 'nullable|numeric|min:0',
            'max_salary'       => 'nullable|numeric|min:0',
            'deadline'         => 'nullable|date',
            'skills'           => 'nullable|string',
            'certifications'   => 'nullable|string',
            'benefits'         => 'nullable|string',
            'description'      => 'nullable|string',
            'status'           => 'required|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->messages()->first()
            ], 422);
        }

        try {
            $objtalent = new talent_jobposting();
            $objtalent->title = $request->title;
            $objtalent->department_id = $request->department_id;
            $objtalent->location = $request->location; 
            $objtalent->employment_type = $request->employment_type; 
            $objtalent->experience = $request->experience;
            $objtalent->education = $request->education;
            $objtalent->priority_level = $request->priority_level;
            $objtalent->positions = $request->positions;
            $objtalent->min_salary = $request->min_salary;
            $objtalent->max_salary = $request->max_salary;
            $objtalent->deadline = $request->deadline;
            $objtalent->skills = $request->skills;
            $objtalent->certifications = $request->certifications;
            $objtalent->benefits = $request->benefits;
            $objtalent->description = $request->description;
            $objtalent->status = $request->status;
            $objtalent->sub_institute_id = $sub_institute_id;
            $objtalent->created_by = $request->user_id;

            if ($objtalent->save()) {
                return response()->json(['message' => 'added successfully !!','data' => $objtalent], 200);
            }

            return response()->json(['message' => 'Something went wrong !!'], 500);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }
    }
    public function getHiringStatus(Request $request)
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

                $sub_institute_id = $this->apiTenantId($request);

                // Auto-update expired job postings to inactive
                DB::table('talent_job_postings')
                    ->where('sub_institute_id', $sub_institute_id)
                    ->where('status', 'active')
                    ->whereNotNull('deadline')
                    ->where('deadline', '<', now())
                    ->update(['status' => 'inactive', 'updated_at' => now()]);

                // Execute the query
                $data = DB::select("
                    SELECT
                        d.department AS department_name,
                        COUNT(DISTINCT jp.id) AS total_positions,
                        COUNT(CASE WHEN ja.status = 'hired' THEN ja.id END) AS hired
                    FROM talent_job_postings jp
                    LEFT JOIN hrms_departments d
                        ON jp.department_id = d.id
                    LEFT JOIN talent_job_applications ja
                        ON ja.job_id = jp.id
                    WHERE jp.sub_institute_id = ?
                    GROUP BY d.id, d.department
                ", [$sub_institute_id]);

                // Fetch recent team updates dynamically
                $recentTeamUpdates = [];

                // Recent hires
                $recentHires = DB::select("
                    SELECT CONCAT(ja.first_name, ' ', ja.last_name) as candidate_name, d.department
                    FROM talent_job_applications ja
                    JOIN talent_job_postings jp ON ja.job_id = jp.id
                    JOIN hrms_departments d ON jp.department_id = d.id
                    WHERE ja.status = 'hired' AND ja.sub_institute_id = ? AND ja.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    ORDER BY ja.updated_at DESC
                    LIMIT 5
                ", [$sub_institute_id]);

                foreach ($recentHires as $hire) {
                    $recentTeamUpdates[] = $hire->candidate_name . ' joined ' . $hire->department . ' team';
                }

                // Open positions
                $openPositionsCount = DB::select("
                    SELECT COUNT(*) as count FROM talent_job_postings
                    WHERE status = 'active' AND sub_institute_id = ? AND deleted_at IS NULL
                ", [$sub_institute_id])[0]->count;

                if ($openPositionsCount > 0) {
                    $recentTeamUpdates[] = $openPositionsCount . ' position(s) still open';
                }

                // Positions needing attention (deadline within 7 days)
                $attentionPositions = DB::select("
                    SELECT title FROM talent_job_postings
                    WHERE status = 'active' AND sub_institute_id = ? AND deadline <= DATE_ADD(NOW(), INTERVAL 7 DAY) AND deleted_at IS NULL
                    LIMIT 3
                ", [$sub_institute_id]);

                foreach ($attentionPositions as $pos) {
                    $recentTeamUpdates[] = $pos->title . ' role needs attention';
                }

                // Upcoming interviews
                $upcomingInterviews = DB::select("
                    SELECT COUNT(*) as count FROM talent_interview_schedules
                    WHERE sub_institute_id = ? AND interview_date >= CURDATE() AND status = 'scheduled'
                ", [$sub_institute_id])[0]->count;

                if ($upcomingInterviews > 0) {
                    $recentTeamUpdates[] = $upcomingInterviews . ' interview(s) scheduled';
                }

                // Tomorrow's interviews
                $tomorrowInterviews = DB::select("
                    SELECT jp.title FROM talent_interview_schedules tis
                    JOIN talent_job_postings jp ON tis.job_id = jp.id
                    WHERE tis.sub_institute_id = ? AND tis.interview_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND tis.status = 'scheduled'
                    LIMIT 3
                ", [$sub_institute_id]);

                foreach ($tomorrowInterviews as $interview) {
                    $recentTeamUpdates[] = $interview->title . ' candidate tomorrow';
                }

                return response()->json([
                    'message' => 'Hiring status fetched successfully',
                    'data' => $data,
                    'recent_team_updates' => $recentTeamUpdates
                ], 200);
            }

            // For web, perhaps return view or something, but since it's API focused, maybe just API
            return response()->json(['message' => 'Invalid type'], 400);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $sub_institute_id = $this->apiTenantId($request);
    
        // Check if record exists
        $exists = talent_jobposting::where([
            'sub_institute_id' => $sub_institute_id,
            'id' => $id
        ])->exists();
    
        if(!$exists){
            return response()->json([
                'message' => 'No talent record found for this department',
                'data' => $id
            ], 404);
        }
    
        // Perform update
        $updated = talent_jobposting::where([
            'sub_institute_id' => $sub_institute_id,
            'id' => $id
        ])->update([
            'title' => $request->title,
            'location' => $request->location,
            'employment_type' => $request->employment_type,
            'experience' => $request->experience,
            'department_id' => $request->department_id,
            'education' => $request->education,
            'priority_level' => $request->priority_level,
            'positions' => $request->positions,
            'min_salary' => $request->min_salary,
            'max_salary' => $request->max_salary,
            'deadline' => $request->deadline,
            'skills' => $request->skills,
            'certifications' => $request->certifications,
            'benefits' => $request->benefits,
            'description' => $request->description,
            'status' => $request->status,
            'updated_by' => $this->g2gActorId($request),
            'updated_at' => now()
        ]);
    
        return response()->json([
            'message' => $updated ? 'Updated successfully' : 'Failed to update',
            'data' => $id
        ], $updated ? 200 : 400);
    }
    
    /**
     * Remove the specified resource from storage.
     */
    public function destroy(request $request ,string $id)
    {
        $type = $request->type;
        $sub_institute_id = session()->get('sub_institute_id');
        if ($type == "API") {
            $token = $request->input('token');  // get token from input field 'token'

            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }
            $sub_institute_id = $request->get('sub_institute_id');
        }

        try {
            // Check if the job posting exists and belongs to the sub_institute and not already deleted
            $exists = talent_jobposting::where([
                'id' => $id,
                'sub_institute_id' => $sub_institute_id
            ])->whereNull('deleted_at')->exists();

            if (!$exists) {
                return response()->json(['message' => 'Job posting not found or already deleted'], 404);
            }

            // Soft delete related records
            TalentEvaluationForm::where('job_id', $id)->whereNull('deleted_at')->update([
                'deleted_at' => now(),
            ]);

            talent_interviewschedules::where('job_id', $id)->whereNull('deleted_at')->update([
                'deleted_at' => now(),
            ]);

            talent_jobapplication::where('job_id', $id)->whereNull('deleted_at')->update([
                'deleted_at' => now(),
            ]);

            TalentOffer::where('job_id', $id)->whereNull('deleted_at')->update([
                'deleted_at' => now(),
            ]);

            $delete = talent_jobposting::where('id', $id)->update([
                'deleted_at' => now(),
                'deleted_by' => $this->g2gActorId($request),
            ]);

            if ($delete) {
                return response()->json(['message' => 'Job posting and related data deleted successfully'], 200);
            }

            return response()->json(['message' => 'Failed to delete job posting'], 400);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


}
