<?php

namespace App\Http\Controllers\talent;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use function App\Helpers\is_mobile;
use Illuminate\Support\Facades\Validator;
use App\Models\talent\talent_jobapplication;
use App\Models\talent\talent_jobposting;
use Storage;

class talent_jobapplicationcontroller extends Controller
{
    use ResolvesApiIdentity;
    use \App\Http\Controllers\Concerns\ResolvesG2gActor;

    /**
     * The recruitment pipeline vocabulary. One list, three consumers.
     *
     * ── WHY THIS CONST EXISTS ───────────────────────────────────────────────
     *
     * The kanban has six columns. Two of them - Assessment and Offer - wrote
     * 'Assessment' and 'Offered', and neither value appeared in the column ENUM,
     * in store()'s `in:` rule, or in update()'s $allowedStatuses array. The last
     * of those is the one that mattered: it was an `if (in_array(...))` with no
     * else, so dragging a candidate to either column returned 200 and changed
     * nothing. The card moved, the refresh put it back, and no error was ever
     * raised. Two of six columns were decoration.
     *
     * The value list now lives here, the column is VARCHAR (see
     * 2026_09_03_110000_widen_talent_application_status_to_varchar), and index()
     * returns it to the client so the frontend stops keeping its own copy.
     *
     * VARCHAR + const, never ENUM: adding a stage later must not mean an ALTER
     * TABLE rebuild on live.
     */
    public const STATUSES = [
        'Pending Review',
        'Under Review',
        'Shortlisted',
        'Assessment',
        'Interview Scheduled',
        'Offered',
        'Hired',
        'Rejected',
        'Completed',
    ];

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

                // fetch jobrole data from table
                $talent = DB::table('talent_job_applications as a')
                            ->select('*')
                            ->where('a.sub_institute_id',$sub_institute_id)
                            ->get();


                return response()->json([
                    'message' => ' fetched successfully',
                    'data'    => $talent,
                    // The pipeline vocabulary travels with the data so the client
                    // does not keep its own copy and drift from it, which is how
                    // 'Assessment' and 'Offered' came to be written by a kanban
                    // whose backend had never heard of them.
                    'options' => ['statuses' => self::STATUSES],
                ], 200);
            }
            $res['talent'] = DB::table('talent_job_postings')
                    ->select('id', 'sub_institute_id', 'status')
                    ->get();
            return is_mobile($type, 'talent.index', $res, 'view');

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
        $type = $request->input('type');

        // Allow execution only if request type is API
        if ($type !== "API") {
            return response()->json(['message' => 'Invalid request type'], 400);
        }

        // Check and validate token
        $token = $request->input('token');
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $sub_institute_id = $this->apiTenantId($request);

        // Validation rules
        $validator = Validator::make($request->all(), [
            'job_id'            => 'required|exists:talent_job_postings,id',
            'first_name'        => 'required|string|max:255',
            'middle_name'       => 'nullable|string|max:255',
            'last_name'         => 'required|string|max:255',
            'email'             => 'required|email|max:255',
            'mobile'            => 'required|string|max:20',
            'current_location'  => 'nullable|string|max:255',
            'employment_type'   => 'nullable|string|max:100',
            'experience'        => 'nullable|string|max:255',
            'education'         => 'nullable|string|max:255',
            'expected_salary'   => 'nullable|numeric|min:0',
            'skills'            => 'nullable|string',
            'certifications'    => 'nullable|string',
            'applied_date'      => 'nullable|date',
            'status'            => ['required', \Illuminate\Validation\Rule::in(self::STATUSES)],
            'sub_institute_id'  => 'required|integer',
            'user_id'           => 'required|integer',
            'resume_path'       => 'required|file|mimes:pdf,doc,docx|max:5120', // 5MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->messages()->first()
            ], 422);
        }

        try {
            $resumeFileName = $resumeFullUrl = null;

            if ($request->hasFile('resume_path')) {
                $file = $request->file('resume_path');
                $extension = $file->getClientOriginalExtension();
                $resumeFileName = 'resume_' . $sub_institute_id .'_'.$request->first_name . '_' . $request->middle_name . '_' . $request->last_name . '.' . $extension;

                // Upload file to DigitalOcean Spaces (or S3)
                $filePath = 'public/hp_resume/' . $resumeFileName;

                Storage::disk('digitalocean')->putFileAs('public/hp_resume/', $file, $resumeFileName, 'public');

                // ✅ Generate full public URL
                $resumeFullUrl = Storage::disk('digitalocean')->url($filePath);
            }

            // Create new job application entry
            $objtalent = new talent_jobapplication([
                'job_id'           => $request->job_id,
                'first_name'       => $request->first_name,
                'middle_name'      => $request->middle_name,
                'last_name'        => $request->last_name,
                'email'            => $request->email,
                'mobile'           => $request->mobile,
                'current_location' => $request->current_location,
                'employment_type'  => $request->employment_type,
                'experience'       => $request->experience,
                'education'        => $request->education,
                'expected_salary'  => $request->expected_salary,
                'skills'           => $request->skills,
                'certifications'   => $request->certifications,
                'resume_path'      => $resumeFullUrl,
                'applied_date'     => $request->applied_date,
                'status'           => $request->status,
                'sub_institute_id' => $sub_institute_id,
                'created_by'       => $this->g2gActorId($request),
            ]);

            if ($objtalent->save()) {
                return response()->json([
                    'status' => 1,
                    'message' => 'Job application added successfully!',
                    'data' => $objtalent
                ], 200);
            }

            return response()->json(['message' => 'Failed to save application'], 500);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }
    /**
     * Display the specified resource.
     */
    public function show(Request $request, $id)
{
    try {
        $type = $request->type;

        if ($type == 'API') {
            // 🔒 Token validation
            $token = $request->input('token');
            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }

            // 🧾 Validate input
            $validator = \Validator::make($request->all(), [
                'sub_institute_id' => 'required|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 0,
                    'message' => $validator->errors()->first()
                ], 400);
            }

            $sub_institute_id = $this->apiTenantId($request);

            // 🧩 Fetch single application with job details
            $application = DB::table('talent_job_applications as a')
                
                ->select(
                    'a.id',
                    'a.job_id',
                    'a.first_name',
                    'a.middle_name',
                    'a.last_name',
                    'a.email',
                    'a.mobile',
                    'a.current_location',
                    'a.employment_type',
                    'a.experience',
                    'a.education',
                    'a.expected_salary',
                    'a.skills',
                    'a.certifications',
                    'a.resume_path',
                    'a.applied_date',
                    'a.status',
                    'a.created_by',
                    'a.updated_by'
                )
                ->where('a.sub_institute_id', $sub_institute_id)
                ->where('a.id', $id)
                ->first();

            if (!$application) {
                return response()->json([
                    'message' => 'Job application not found.'
                ], 404);
            }

            return response()->json([
                'message' => 'Job application details fetched successfully!',
                'data' => $application
            ], 200);
        }

        // 🌐 Web version (optional view)
        $res['application'] = talent_jobapplication::find($id);
        return is_mobile($type, 'talent.application-detail', $res, 'view');

    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
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

        // From the token, matching index() at :66 and store() at :116. Taking it from
        // the request let a caller move an application into another organisation.
        $sub_institute_id = $this->apiTenantId($request);
        if (!$sub_institute_id) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        // 🧾 Validation
        $validator = Validator::make($request->all(), [
            'job_id'           => 'nullable|exists:talent_job_postings,id',
            'first_name'       => 'nullable|string|max:255',
            'middle_name'      => 'nullable|string|max:255',
            'last_name'        => 'nullable|string|max:255',
            'email'            => 'nullable|email|max:255',
            'mobile'           => 'nullable|string|max:20',
            'current_location' => 'nullable|string|max:255',
            'expected_salary'  => 'nullable|numeric|min:0',
            'resume_path'      => 'nullable|file|mimes:pdf,doc,docx|max:5120',
            'applied_date'     => 'nullable|date',
            // Checked against the pipeline vocabulary, so an unknown stage is
            // refused with a message rather than accepted and written.
            'status'           => ['nullable', \Illuminate\Validation\Rule::in(self::STATUSES)],
            'sub_institute_id' => 'required|integer',
            'user_id'          => 'required|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->messages()->first()
            ], 422);
        }

        try {
            /*
             * Scoped to the caller's tenant, taken from the token.
             *
             * This was `talent_jobapplication::find($id)` - no tenant filter -
             * while the tenant was resolved just above and then never used. A
             * tenant-1 admin PUTting another institute's application id got HTTP
             * 200 and rewrote the row; executed on live during the lifecycle
             * re-audit, it changed a candidate's name and, because
             * sub_institute_id below was taken from the request, moved the row
             * into the attacker's tenant. Audit F-67.
             *
             * A row from another institute now answers 404, the same as one that
             * does not exist - a 403 would confirm it does.
             */
            $application = talent_jobapplication::where('id', $id)
                ->where('sub_institute_id', $sub_institute_id)
                ->first();

            if (!$application) {
                return response()->json(['message' => 'Application not found'], 404);
            }

            if (
                $request->filled('status')
                && $request->status === 'Shortlisted'
                && $application->hasReachedOfferOrHiredStage()
            ) {
                return response()->json(['message' => 'A candidate cannot be shortlisted after an offer has been sent or the candidate has been hired.'], 422);
            }

            // 🧩 Update job info if job_id is changed
            if ($request->filled('job_id')) {
                $jobData = talent_jobposting::find($request->job_id);
                if ($jobData) {
                    $application->job_id = $jobData->id;
                    $application->employment_type = $jobData->employment_type;
                    $application->experience = $jobData->experience;
                    $application->education = $jobData->education;
                    $application->skills = $jobData->skills;
                    $application->certifications = $jobData->certifications;
                }
            }

            // 🧱 Update editable fields
            $application->first_name = $request->first_name ?? $application->first_name;
            $application->middle_name = $request->middle_name ?? $application->middle_name;
            $application->last_name = $request->last_name ?? $application->last_name;
            $application->email = $request->email ?? $application->email;
            $application->mobile = $request->mobile ?? $application->mobile;
            $application->current_location = $request->current_location ?? $application->current_location;
            $application->expected_salary = $request->expected_salary ?? $application->expected_salary;
            $application->applied_date = $request->applied_date ?? $application->applied_date;
            // status is deliberately NOT assigned here. It was, unconditionally, and
            // that made the checked assignment further down dead code - any string
            // at all reached the column. Status is written in exactly one place
            // below, after it has been checked against self::STATUSES.

            // 📂 If new resume file uploaded, replace existing one
            if ($request->hasFile('resume_path')) {
                $file = $request->file('resume_path');
                $extension = $file->getClientOriginalExtension();

                $resumeFileName = 'resume_' . $request->first_name . '_' . $request->middle_name . '_' . $request->last_name . '.' . $extension;

                // Upload to DigitalOcean Spaces
                $path = Storage::disk('digitalocean')->putFileAs(
                    'public/hp_resume/',
                    $file,
                    $resumeFileName,
                    'public'
                );

                // Generate full URL from Spaces
                $resumeUrl = Storage::disk('digitalocean')->url('public/hp_resume/' . $resumeFileName);

                // Update DB field with full link
                $application->resume_path = $resumeUrl;
            }

            // 🎯 Dynamic Status Validation
            $allowedStatuses = self::STATUSES;

            if ($request->filled('status') && in_array($request->status, $allowedStatuses)) {
                $application->status = $request->status;
            }

            /*
             * The actor is the token's owner, not $request->user_id. Letting the
             * caller name who made a change lets one person attribute an edit to
             * another, which is the same class of problem as the tenant hole
             * above - identity taken from the request rather than the token.
             *
             * sub_institute_id is NOT reassigned. It was set from $sub_institute_id
             * here, so a caller passing sub_institute_id in the body could carry
             * another tenant's row into their own once the untenanted find()
             * above had let them reach it. With the lookup now tenant-scoped the
             * row already belongs to this tenant; writing it again would only
             * reintroduce a way to move it.
             */
            $application->updated_by = $this->apiUserId($request) ?? $application->updated_by;

            // 💾 Save update
            if ($application->save()) {
                return response()->json([
                    'message' => 'Application updated successfully!',
                    'data' => $application
                ], 200);
            }

            return response()->json(['message' => 'Update failed!'], 500);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    return response()->json(['message' => 'Invalid request type'], 400);
}

public function updateStatus(Request $request, $id)
{
    try {
        $type = $request->type;

        if ($type === 'API') {
            // 🔒 Validate token
            $token = $request->input('token');
            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }

            // 🧾 Validate request
            $validator = \Validator::make($request->all(), [
                'sub_institute_id' => 'required|integer',
                'user_id'          => 'required|integer',
                'status'           => ['required', 'string', \Illuminate\Validation\Rule::in(array_merge(self::STATUSES, ['inactive']))]
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 0,
                    'message' => $validator->errors()->first()
                ], 400);
            }

            $sub_institute_id = $this->apiTenantId($request);

            // 🔍 Find the application
            $application = \App\Models\talent\talent_jobapplication::where([
                'id' => $id,
                'sub_institute_id' => $sub_institute_id
            ])->first();

            if (!$application) {
                return response()->json(['message' => 'Job application not found'], 404);
            }

            if ($request->status === 'Shortlisted' && $application->hasReachedOfferOrHiredStage()) {
                return response()->json(['message' => 'A candidate cannot be shortlisted after an offer has been sent or the candidate has been hired.'], 422);
            }

            // 🧠 Update status only. The actor is the token's owner - the tenant
            // guard on the lookup above is already correct, this closes the same
            // request-supplied-identity gap on updated_by (F-67).
            $application->status = $request->status;
            $application->updated_by = $this->apiUserId($request) ?? $application->updated_by;

            if ($application->save()) {
                return response()->json([
                    'message' => 'Application status updated successfully!',
                    'data' => [
                        'id' => $application->id,
                        'status' => $application->status,
                        'updated_by' => $application->updated_by
                    ]
                ], 200);
            }

            return response()->json(['message' => 'Failed to update status'], 500);
        }

        return response()->json(['message' => 'Invalid request type'], 400);

    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

public function getCandidateApplications(Request $request, $candidate_id)
{
    try {
        $type = $request->type;

        if ($type === 'API') {
            // 🔒 Validate token
            $token = $request->input('token');
            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }

            // 🧾 Validate inputs
            $validator = \Validator::make($request->all(), [
                'sub_institute_id' => 'required|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 0,
                    'message' => $validator->errors()->first()
                ], 400);
            }

            $sub_institute_id = $this->apiTenantId($request);

            // 🧠 Fetch applications for the given candidate
            $applications = DB::table('talent_job_applications as a')
            ->join('talent_job_postings as j', 'a.job_id', '=', 'j.id')
            ->select(
                'a.id as application_id',
                'a.job_id',
                'j.title as job_title',
                'j.location as job_location', // keep only existing columns
                'a.status',
                'a.applied_date',
                'a.expected_salary',
                'a.resume_path',
                'a.education',
                'a.experience'
            )
            ->where('a.sub_institute_id', $sub_institute_id)
            ->where('a.created_by', $candidate_id)
            ->orderBy('a.applied_date', 'desc')
            ->get();

            if ($applications->isEmpty()) {
                return response()->json([
                    'message' => 'No job applications found for this candidate.',
                    'data' => []
                ], 200);
            }

            return response()->json([
                'message' => 'Candidate job applications fetched successfully!',
                'count' => $applications->count(),
                'data' => $applications
            ], 200);
        }

        // 🌐 Web view (optional)
        $res['applications'] = DB::table('talent_job_applications')
            ->where('created_by', $candidate_id)
            ->get();

        return is_mobile($type, 'talent.candidate-applications', $res, 'view');

    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

public function getShortlistedCandidates(Request $request)
{
    try {
        $type = $request->type;

        if ($type === 'API') {
            // 🔒 Validate token
            $token = $request->input('token');
            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }

            // 🧾 Validate inputs
            $validator = \Validator::make($request->all(), [
                'sub_institute_id' => 'required|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 0,
                    'message' => $validator->errors()->first()
                ], 400);
            }

            $sub_institute_id = $this->apiTenantId($request);

            // 🧠 Fetch shortlisted applications
            $applications = DB::table('talent_job_applications as a')
                ->select('*')
                ->where('a.sub_institute_id', $sub_institute_id)
                ->where('a.status', 'Shortlisted')
                ->get();

            return response()->json([
                'message' => 'Shortlisted candidates fetched successfully!',
                'data' => $applications
            ], 200);
        }

        return response()->json(['message' => 'Invalid request type'], 400);

    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

}
