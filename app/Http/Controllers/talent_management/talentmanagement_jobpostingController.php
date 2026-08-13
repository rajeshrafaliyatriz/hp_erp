<?php

namespace App\Http\Controllers\talent_management;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use function App\Helpers\is_mobile;
use Illuminate\Support\Facades\Validator;
use App\Models\talent_management\talentmanagement_jobposting;


class talentmanagement_jobpostingController extends Controller
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
                $talent = DB::table('talent_job_postings as a')
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
            $objtalent = new talentmanagement_jobposting();
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

    // Get all talent records for this department
    $getAllTalent =talent::where([
        'sub_institute_id' => $sub_institute_id,
        'department_id' => $department_id
    ])->get();

    if($getAllTalent->isEmpty()){
        return response()->json([
            'message' => 'No talent records found for this department',
            'data' => $department_id
        ], 404);
    }

    // Update all matching records
    $update = talent::where([
        'sub_institute_id' => $sub_institute_id,
        'department_id' => $department_id
    ])->update([
        'title' => $request->title,
        'location' => $request->location,
        'employment_type' => $request->employment_type,
        'experience' => $request->experience,
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

    if($update){
        return response()->json([
            'message' => ' updated successfully',
            'data' => $department_id
        ], 200);
    }

    return response()->json([
        'message' => 'Failed to update',
        'data' => $department_id
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
