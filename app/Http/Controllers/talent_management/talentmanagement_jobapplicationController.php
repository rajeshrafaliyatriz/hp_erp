<?php

namespace App\Http\Controllers\talent_management;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use function App\Helpers\is_mobile;
use Illuminate\Support\Facades\Validator;
use App\Models\talent_management\talentmanagement_jobapplication;
use App\Models\talent_management\talentmanagement_jobposting; // ✅ ADD THIS


class talentmanagement_jobapplicationController extends Controller
{
    /**
     * Display a listing of the resource.
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

                $sub_institute_id = $request->sub_institute_id;

                // fetch jobrole data from table
                $talent = DB::table('talent_job_applications as a')
                            ->select('*')
                            ->where('a.sub_institute_id',$sub_institute_id)
                            ->get();


                return response()->json([
                    'message' => ' fetched successfully',
                    'data'    => $talent
                ], 200);
            }
            $res['talent'] = DB::table('talent_job_applications')
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

        // Convert status to lowercase for case-insensitive validation
        $request->merge(['status' => strtolower($request->status)]);

        $validator = \Validator::make($request->all(), [
            'job_id'           => 'required|exists:talent_job_postings,id',
            'first_name'       => 'required|string|max:255',
            'middle_name'      => 'nullable|string|max:255',
            'last_name'        => 'required|string|max:255',
            'email'            => 'required|email|max:255',
            'mobile'           => 'required|string|max:20',
            'current_location' => 'nullable|string|max:255',
            'expected_salary'  => 'nullable|numeric|min:0',
            'resume_path'      => 'nullable|string|max:255',
            'applied_date'     => 'nullable|date',
            'status'           => 'required|in:pending,accepted,
            ,active',
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
            // ✅ Fetch job-posting record
            $jobData = talentmanagement_jobposting::find($request->job_id);

            if (!$jobData) {
                return response()->json(['message' => 'Job not found'], 404);
            }

            // ✅ Create new application
            $objtalent = new talentmanagement_jobapplication();
            $objtalent->job_id = $jobData->id;
            $objtalent->first_name = $request->first_name;
            $objtalent->middle_name = $request->middle_name;
            $objtalent->last_name = $request->last_name;
            $objtalent->email = $request->email;
            $objtalent->mobile = $request->mobile;
            $objtalent->current_location = $request->current_location;

            // ✅ Copy these fields automatically from job-posting
            $objtalent->employment_type = $jobData->employment_type;
            $objtalent->experience = $jobData->experience;
            $objtalent->education = $jobData->education;
            $objtalent->skills = $jobData->skills;
            $objtalent->certifications = $jobData->certifications;
            $objtalent->status = $request->status; // from job_posting

            $objtalent->expected_salary = $request->expected_salary;
            $objtalent->resume_path = $request->resume_path;
            $objtalent->applied_date = $request->applied_date;
            $objtalent->sub_institute_id = $sub_institute_id;
            $objtalent->created_by = $request->user_id;

            if ($objtalent->save()) {
                return response()->json([
                    'message' => 'Application added successfully!',
                    'data' => $objtalent
                ], 200);
            }

            return response()->json(['message' => 'Something went wrong!'], 500);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}

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
    $sub_institute_id = $request->sub_institute_id;

    // Validate required fields
    $validator = Validator::make($request->all(), [
        'job_id' => 'required|integer',
        'first_name' => 'required|string',
        'last_name' => 'required|string',
        'email' => 'required|email',
        'mobile' => 'required|string',
        'status' => 'required|string',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    // Find existing job application record
    $application = talentmanagement_jobapplication::where([
        'sub_institute_id' => $sub_institute_id,
        'id' => $id
    ])->first();

    if (!$application) {
        return response()->json([
            'message' => 'Job application not found',
            'data' => $id
        ], 404);
    }

    // Update job application fields
    $update = $application->update([
        'job_id' => $request->job_id,
        'first_name' => $request->first_name,
        'middle_name' => $request->middle_name,
        'last_name' => $request->last_name,
        'email' => $request->email,
        'mobile' => $request->mobile,
        'current_location' => $request->current_location,
        'employment_type' => $request->employment_type,
        'experience' => $request->experience,
        'education' => $request->education,
        'skills' => $request->skills,
        'certifications' => $request->certifications,
        'status' => $request->status,
        'updated_by' => $request->user_id,
        'updated_at' => now()
    ]);

    if ($update) {
        return response()->json([
            'message' => 'Job application updated successfully',
            'data' => $application
        ], 200);
    }

    return response()->json([
        'message' => 'Failed to update job application',
        'data' => $id
    ], 400);
}


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
