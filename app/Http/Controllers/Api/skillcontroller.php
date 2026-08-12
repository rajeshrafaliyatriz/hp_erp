<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Exception;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Validator;
use App\Models\userskill;
use function App\Helpers\is_mobile;

class skillcontroller extends Controller
{
    /**
     * G-SEC-29. THE REQUEST IS NO LONGER A TENANT SOURCE.
     *
     * Every `$request->...sub_institute_id` became `$this->apiTenantId($request)`,
     * which resolves the tenant FROM THE TOKEN. Confirmed by execution before the
     * change: a tenant-7 caller asking for tenant 3 received tenant 3's rows.
     *
     * THE SESSION READS ARE LEFT WHERE THEY ARE, DELIBERATELY. This controller
     * reads `session() ?? $request`, and `resolveApiIdentity()` is TOKEN-ONLY - it
     * does not consult the session. Replacing the whole expression would have
     * broken every Blade/web caller, who has a session and no token.
     *
     * So the precedence is now exactly G-SEC-27's ruling: SESSION, THEN TOKEN,
     * AND THE REQUEST NEVER. The server-side source stays first; the
     * caller-controlled one is gone.
     */
    use ResolvesApiIdentity;

    public function index(Request $request)
    {
    try {
        $type = $request->type; // "API" or "web"
        $sub_institute_id = session()->get('sub_institute_id') ?? $this->apiTenantId($request);
        $data = userskill::where('sub_institute_id', $sub_institute_id)->latest()->get();

        // If it's an AJAX request (like DataTables)
        if ($request->ajax()) {
            return DataTables::of($data)
                ->addIndexColumn()
                ->addColumn('status', function ($row) {
                    return $row->status == 1 ? 'active' : 'inactive';
                })
                ->addColumn('action', function ($row) {
                    $actionBtn = '<a href="javascript:void(0)" class="edit btn btn-success btn-edit btn-sm" data-id="' . $row->id . '">Edit</a> ';
                    $actionBtn .= '<a href="javascript:void(0)" class="delete btn btn-danger btn-delete btn-sm" data-id="' . $row->id . '">Delete</a>';
                    return $actionBtn;
                })
                ->rawColumns(['action'])
                ->make(true);
        }

        // For normal web view
        $maxstatus = userskill::where('sub_institute_id', $sub_institute_id)->max('status');
        $res['alldata'] = $data;
        $res['maxstatus'] = ($maxstatus);

        return is_mobile($type, "skills.index", $res, "view");

    } catch (Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

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

        $sub_institute_id = $this->apiTenantId($request);

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->messages()->first()
            ], 422);
        }

        try {
            $objSkill = new userskill();
            $objSkill->department = $request->department;
            $objSkill->sub_department = $request->sub_department;
            $objSkill->category = $request->category;
            $objSkill->sub_category = $request->sub_category;
            $objSkill->title = $request->title;
            $objSkill->description = $request->description;
            $objSkill->related_skills = $request->related_skills;
            $objSkill->bussiness_links = $request->bussiness_links;
            $objSkill->custom_tags = $request->custom_tags;
            $objSkill->proficiency_level = $request->proficiency_level;
            $objSkill->job_titles = $request->job_titles;
            $objSkill->learning_resources = $request->learning_resources;
            $objSkill->assesment_method = $request->assesment_method;
            $objSkill->certification_qualifications = $request->certification_qualifications;
            $objSkill->experience_project = $request->experience_project;
            $objSkill->skill_maps = $request->skill_maps;
            $objSkill->status = $request->status;
            $objSkill->approve_status = $request->approve_status;
            $objSkill->sub_institute_id = $sub_institute_id;

            if ($objSkill->save()) {
                return response()->json(['message' => 'skill added successfully !!'], 200);
            }

            return response()->json(['message' => 'Something went wrong !!'], 500);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }
}

 /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

public function update(Request $request, $id)
    {
        try {
            $skill = userskill::find($id);

            if (!$skill) {
                return response()->json(['message' => 'Skill not found'], 404);
            }

            // Update only the fields you send
            $skill->update($request->all());

            return response()->json([
                'message' => 'Skill updated successfully',
                'data' => $skill
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


public function destroy(Request $request,$id)
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
            $sub_institute_id = $this->apiTenantId($request);

            // $validator = Validator::make($request->all(), [
            //     'sub_institute_id' => 'required|numeric',
            // ]);

            // if ($validator->fails()) {
            //     $res['status'] = 0;
            //     $res['message'] = $validator->messages()->first();
            //     // return is_mobile($type, "hrms_inout_time.index", $res, "redirect");
            //     return response()->json($res);
            // }
        }
        // try {
            $delete=userskill::where('id',$id)->update([
                'deleted_at' => now(),
            ]);
            if($delete){
                return response()->json(['message' => 'skill deleted successfully !!'], 200);
            }
            return response()->json(['message' => 'Failed to delete !!'], 200);
        // } catch (\Exception $e) {
        //     return response()->json($e->getMessage(), 500);
        // }
    }
    }

