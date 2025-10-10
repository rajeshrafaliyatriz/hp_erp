<?php

namespace App\Http\Controllers\talent;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use function App\Helpers\is_mobile;
use Illuminate\Support\Facades\Validator;
use App\Models\talent\talent_jobapplication;
use App\Models\talent\talent_jobposting;

class talent_jobapplicationcontroller extends Controller
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
                $talent = DB::table('talent_job_applications as a')
                            ->select('*')
                            ->where('a.sub_institute_id',$sub_institute_id)
                            ->get();


                return response()->json([
                    'message' => ' fetched successfully',
                    'data'    => $talent
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

      

          $validator = \Validator::make($request->all(), [
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
            'resume_path'       => 'nullable|string|max:255',
            'applied_date'      => 'nullable|date',
            'status'            => 'required|in:pending,accepted,rejected,active',
            'sub_institute_id'  => 'required|integer',
            'user_id'           => 'required|integer'
                ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->messages()->first()
            ], 422);
        }

        try {
            $objtalent = new talent_jobapplication();
            $objtalent->job_id = $request->job_id;
            $objtalent->first_name = $request->first_name;
            $objtalent->middle_name = $request->middle_name; 
            $objtalent->last_name = $request->last_name; 
            $objtalent->email = $request->email;
            $objtalent->mobile = $request->mobile;
            $objtalent->current_location = $request->current_location;
            $objtalent->employment_type = $request->employment_type;
            $objtalent->experience = $request->experience;
            $objtalent->education = $request->education;
            $objtalent->expected_salary = $request->expected_salary;
            $objtalent->skills = $request->skills;
            $objtalent->certifications = $request->certifications;
            $objtalent->resume_path = $request->resume_path;
            $objtalent->applied_date = $request->applied_date;
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
        $validator = Validator::make($request->all(), [
            'job_id'           => 'nullable|exists:talent_job_postings,id',
            'first_name'       => 'nullable|string|max:255',
            'middle_name'      => 'nullable|string|max:255',
            'last_name'        => 'nullable|string|max:255',
            'email'            => 'nullable|email|max:255',
            'mobile'           => 'nullable|string|max:20',
            'current_location' => 'nullable|string|max:255',
            'expected_salary'  => 'nullable|numeric|min:0',
            'resume_path'      => 'nullable|string|max:255',
            'applied_date'     => 'nullable|date',
            'status'           => 'nullable|string|max:50',
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
            // 🧠 Find existing application
            $application = talent_jobapplication::find($id);

            if (!$application) {
                return response()->json(['message' => 'Application not found'], 404);
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
            $application->resume_path = $request->resume_path ?? $application->resume_path;
            $application->applied_date = $request->applied_date ?? $application->applied_date;

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
                $application->status = $request->status;
            }

            $application->updated_by = $request->user_id;
            $application->sub_institute_id = $sub_institute_id;

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

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

}
