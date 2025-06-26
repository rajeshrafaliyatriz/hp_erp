<?php

namespace App\Http\Controllers\libraries;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\libraries\industryModel;
use App\Models\libraries\userSkills;
use function App\Helpers\is_mobile;
use Laravel\Sanctum\PersonalAccessToken;
use App\Models\libraries\jobroleModel;
use App\Models\libraries\skillJobroleMap;
use App\Models\libraries\userJobroleModel;
use App\Models\libraries\userJobroleTask;
use App\Models\libraries\userKnowledgeAbility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class jobroleLibraryController extends Controller
{
    //
    public function index(Request $request)
    {
        $type = $request->type;
        // If the request is from API, validate token and required fields
        if ($type == 'API') {
            $token = $request->input('token');  // get token from input field 'token'

            // Check if token is provided
            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            // If token is invalid
            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }
            // Validate required fields
            $validator = Validator::make($request->all(), [
                'org_type' => 'required',
                'sub_institute_id' => 'required',
            ]);

            // If validation fails
            if ($validator->fails()) {
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
        }
        // Fetch jobrole data based on filters
        $jobroleData = industryModel::from('s_industries as a')
            // ->when($request->has('searchType') && $request->searchType=="department", function ($q) use ($request) {
            //     // Filter by department if provided
            //     $q->where('a.department', $request->searchWord);
            // })
            // ->when($request->has('searchType') && $request->searchType=="sub_department", function ($q) use ($request) {
            //     // Filter by sub_department if provided
            //     $q->whereIn('a.sub_department', explode(',', $request->searchWord));
            // })
            ->join('s_jobrole as c', 'c.track', '=', 'a.sub_department')
            ->where('a.industries', 'like', '%' . $request->org_type . '%')
            ->select('c.*')
            ->groupBy('c.id')
            ->get();

        // Build tree data for jobroles
        $treeData = [];
        foreach ($jobroleData as $key => $value) {
            // If sub_department exists, group by department and sub_department
            if (isset($value['sub_department']) && $value['sub_department'] != null && $value['sub_department'] != '') {
                $treeData[$value['department']][$value['sub_department']][] = $value;
            } else {
                // Otherwise, group under 'no_sub_category'
                $treeData[$value['department']]['no_sub_category'][] = $value;
            }
        }

        // Get sector data, grouped by department or sub_department
        $getSectore = industryModel::where('industries', $request->org_type)
            ->when($request->has('department'), function ($q) use ($request) {
                $q->where('department', $request->department);
                $q->where('sub_department', '!=', '');
                $q->groupBy('sub_department');
            }, function ($q) {
                $q->groupBy('department');
            });

        // Get all user jobroles for the sub_institute
        $usersJobroles = userJobroleModel::where('sub_institute_id', $request->sub_institute_id)
                ->when($request->has('department') && $request->department!='',
                    function ($q) use ($request) {
                        // Only department is provided
                        $q->where('department', $request->department);
                    }
                )
                ->when($request->has('sub_department') && $request->sub_department!='',
                    function ($q) use ($request) {
                        // Both department and sub_department provided
                        $q->whereIn('sub_department', explode(',',$request->sub_department));
                    }
                )
                ->whereNull('deleted_at')
                ->orderBy('id','Desc')
                ->get();


        // Prepare response data
        // $res['jobroleData'] = $jobroleData;
        // $res['alljobroleData'] = $treeData;
        $res['tableData'] = $usersJobroles;
        // $res['usersJobroles'] = $usersJobroles;
        // $res['userTree'] = $userTree ?? [];
        // Return response based on device type
        return is_mobile($type, 'jobrole_library.index', $res, 'redirect');
    }
/**
     * Show the form for creating a new job role or related data.
     */
    public function create(Request $request)
    {
        $type = $request->type;
        // If API, validate token and required fields
        if ($type == 'API') {
            $token = $request->input('token');  // get token from input field 'token'

            // Check if token is provided
            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            // If token is invalid
            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }
            // Validate required fields
            $validator = Validator::make($request->all(), [
                'org_type' => 'required',
                'sub_institute_id' => 'required',
            ]);

            // If validation fails
            if ($validator->fails()) {
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
        }

        $skillFields = ['id', 'category', 'sub_category', 'title', 'description'];
        $jobroleFields = ['id', 'jobrole', 'description'];
        $createdUser = ['id', 'first_name', 'middle_name', 'last_name'];

        // If formType is 'skills', fetch skill-jobrole mapping data
        if ($request->has('formType') && $request->formType == "skills") {

            $res['userskillData'] = skillJobroleMap::with([
                'userSkills' => fn($q) => $q->select($skillFields),
                'userJobrole' => fn($q) => $q->select($jobroleFields),
                'createdUser' => fn($q) => $q->select($createdUser),
            ])
                ->where('jobrole', $request->jobrole)
                ->where('sub_institute_id', $request->sub_institute_id)
                ->whereNull('deleted_at')
                 ->orderBy('id','DESC')
                ->get()
                ->map(function ($item) {
                    $data = $item->toArray();

                    // Add skill fields if available
                    if ($item->userSkills) {
                        $data['skill_id'] = $item->userSkills->id;
                        $data['category'] = $item->userSkills->category;
                        $data['sub_category'] = $item->userSkills->sub_category;
                        $data['skillTitle'] = $item->userSkills->title;
                        $data['skillDescription'] = $item->userSkills->description;
                    }
                    // Add jobrole fields if available
                    if ($item->userJobrole) {
                        $data['jobrole'] = $item->userSkills->jobrole;
                        $data['jobroleDescription'] = $item->userSkills->description;
                    }

                    // Add created user fields if available
                    if ($item->createdUser) {
                        $data['first_name'] = $item->createdUser->first_name;
                        $data['middle_name'] = $item->createdUser->middle_name;
                        $data['last_name'] = $item->createdUser->last_name;
                    }

                    unset($data['user_skills'], $data['created_user']);

                    return $data;
                });
                // echo "<pre>";print_r($res);exit;
        } elseif ($request->has('formType') && $request->formType == "tasks") {
            // If formType is 'tasks', fetch user jobrole tasks
            $res['usertaskData'] = userJobroleTask::with([
                'userJobrole' => fn($q) => $q->select($jobroleFields),
                'createdUser' => fn($q) => $q->select($createdUser),
            ])
                ->where('jobrole', $request->jobrole)
                ->where('sub_institute_id', $request->sub_institute_id)
                ->whereNull('deleted_at')
                ->orderBy('id','DESC')
                ->get()
                ->map(function ($item) {
                    $data = $item->toArray();
                    if ($item->userJobrole) {
                        $data['jobrole'] = $item->userSkills->jobrole;
                        $data['jobroleDescription'] = $item->userSkills->description;
                    }

                    if ($item->createdUser) {
                        $data['first_name'] = $item->createdUser->first_name;
                        $data['middle_name'] = $item->createdUser->middle_name;
                        $data['last_name'] = $item->createdUser->last_name;
                    }

                    unset($data['user_skills'], $data['created_user']);

                    return $data;
                });
        } else {
            // Otherwise, fetch all user jobroles for the sub_institute
            $usersJobroles = userJobroleModel::where('sub_institute_id', $request->sub_institute_id)
                ->whereNull('deleted_at')->orderBy('id','DESC')->get();
            $res['tableData'] = $usersJobroles;
        }
        // Return response based on device type
        return is_mobile($type, 'jobrole_library.index', $res, 'redirect');
    }
 /**
     * Store a newly created job role or related data.
     */
    public function store(Request $request)
    {
        // return $request->all();
        $type = $request->type;
        // If API, validate token and required fields
        if ($type == 'API') {
            $token = $request->input('token');  // get token from input field 'token'

            // Check if token is provided
            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            // If token is invalid
            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }

            // Validate required fields
            $validator = Validator::make($request->all(), [
                'org_type' => 'required',
                'sub_institute_id' => 'required',
                'user_profile_name' => 'required',
                'user_id' => 'required',
                'formType' => 'required',
            ]);

            // If validation fails
            if ($validator->fails()) {
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
        }

        $insertData = [
                'department' => $request->department,
                'sub_department' => $request->sub_department,
                'jobrole' => $request->jobrole,
                'description' => $request->description,
                'performance_expectation' => $request->performance_expectation,
                'company_information' => $request->company_information,
                'contact_information' => $request->contact_information,
                'location' => $request->location,
                'job_posting_date' => $request->job_posting_date,
                'application_deadline' => $request->application_deadline,
                'salary_range' => $request->salary_range,
                'required_skill_experience' => $request->required_skill_experience,
                'responsibilities' => $request->responsibilities,
                'benefits' => $request->benefits,
                'keyword_tags' => $request->keyword_tags,
                'internal_tracking' => $request->internal_tracking,
                'sub_institute_id' => $request->sub_institute_id,
                'created_by' => $request->user_id,
                'created_at' => now(),
            ];

            // Check if the job role already exists for this institute
            $jobExists = UserJobroleModel::where('jobrole', $request->jobrole)
                ->where('sub_institute_id', $request->sub_institute_id)
                ->whereNull('deleted_at')
                ->exists();

            // If jobrole does not exist, insert it
            $i=0;
            if (!$jobExists) {
                userJobroleModel::insert($insertData);
                $i++;
            }

        if ($i > 0) {
            $res['status_code'] = 1;
            $res['message'] = 'Added data successfully !';
        } else {
            $res['status_code'] = 0;
            $res['message'] = 'Failed to Add data !';
        }
        // Return success response
        return is_mobile($type, 'jobrole_library.index', $res, 'redirect');
    }

/**
     * Show the form for editing the specified job role or related data.
     */
    public function edit(Request $request, $id)
    {
        $type = $request->type;
        // If API, validate token and required fields
        if ($type == 'API') {
            $token = $request->input('token');  // get token from input field 'token'

            // Check if token is provided
            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            // If token is invalid
            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }

            // Validate required fields
            $validator = Validator::make($request->all(), [
                'org_type' => 'required',
                'sub_institute_id' => 'required',
                'formType' => 'required',
            ]);

            // If validation fails
            if ($validator->fails()) {
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
        }
        $skillFields = ['id', 'category', 'sub_category', 'title'];
        $createdUser = ['id', 'first_name', 'middle_name', 'last_name'];
        // Fetch jobrole data for editing
        $res['editData'] = jobroleModel::find($id);
        // If editing user jobrole
        if ($request->formType == "user") {
            $res['editData'] = userJobroleModel::find($id);
        }

        // Return response based on device type
        return is_mobile($type, 'jobrole_library.index', $res, 'redirect');
    }

    /**
     * Update the specified job role or related data.
     */
    public function update(Request $request, $id)
    {
        $type = $request->type;
        // If API, validate token and required fields
        if ($type == 'API') {
            $token = $request->input('token');  // get token from input field 'token'

            // Check if token is provided
            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            // If token is invalid
            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }

            // Validate required fields
            $validator = Validator::make($request->all(), [
                'org_type' => 'required',
                'sub_institute_id' => 'required',
                'user_profile_name' => 'required',
                'user_id' => 'required',
                'formType' => 'required',
            ]);

            // If validation fails
            if ($validator->fails()) {
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
        }

        $skillFields = ['id', 'category', 'sub_category', 'title'];
        $createdUser = ['id', 'first_name', 'middle_name', 'last_name'];
        $i = 0;
        // If updating user jobrole
        if ($request->formType == 'user') {
            $updateData = [
                'department' => $request->department,
                'sub_department' => $request->sub_department,
                'jobrole' => $request->jobrole,
                'description' => $request->description,
                'performance_expectation' => $request->performance_expectation,
                'company_information' => $request->company_information,
                'contact_information' => $request->contact_information,
                'location' => $request->location,
                'job_posting_date' => $request->job_posting_date,
                'application_deadline' => $request->application_deadline,
                'salary_range' => $request->salary_range,
                'required_skill_experience' => $request->required_skill_experience,
                'responsibilities' => $request->responsibilities,
                'benefits' => $request->benefits,
                'keyword_tags' => $request->keyword_tags,
                'internal_tracking' => $request->internal_tracking,
                'sub_institute_id' => $request->sub_institute_id,
                'updated_by' => $request->user_id,
                'updated_at' => now(),
            ];

            // Check if the job role already exists for this institute
            $jobExists = UserJobroleModel::where('jobrole', $request->jobrole)
                ->where('sub_institute_id', $request->sub_institute_id)
                ->whereNull('deleted_at')
                ->first();

            // If jobrole exists, update it
            if ($jobExists && isset($jobExists->id)) {
                userJobroleModel::where('id', $jobExists->id)->update($updateData);
            }

            $i++;
        } else if ($request->formType == "skills") {
            // Update or insert skills
            foreach ($request->skillName as $key => $skillName) {
                $skillDescription = $request->description[$key] ?? null;
                $checkSkillExits = userSkills::where('title', $request->skillName)->where('sub_institute_id', $request->sub_institute_id)->first();
                if (!$checkSkillExits) {
                    $insertData = [
                        'department' => $request->department,
                        'sub_department' => $request->sub_department,
                        'category' => $request->category [$key] ?? null,
                        'sub_category' => $request->sub_category [$key] ?? null,
                        'title' => $skillName,
                        'description' => $skillDescription,
                        'sub_institute_id' => $request->sub_institute_id,
                        'created_by' => $request->user_id,
                        'created_at' => now(),
                        'status' => 'Active',
                        'approve_status' => 'approved'
                    ];
                    $lastInsertedId = userSkills::insertGetId($insertData);
                    if ($lastInsertedId && $lastInsertedId != 0) {
                        // $insertArray = [
                        //     'skill' => $skillName,
                        //     'jobrole' => $request->jobrole,
                        //     'description' => null,
                        //     'sub_institute_id' => $request->sub_institute_id,
                        //     'created_by' => $request->user_id,
                        //     'created_at' => now(),
                        // ];
                         $insertArray = [
                             'skill' => $skillName,
                            'jobrole' =>  $request->jobrole,
                            'proficiency_level' => $request->proficiency_level[$key] ?? null,
                            'sub_institute_id' => $request->sub_institute_id,
                            'created_by' => $request->user_id,
                            'created_at' => now(),
                        ];
                        $insert = skillJobroleMap::insert($insertArray);
                    }
                } else if($request->has('id')) {
                    // return $request->all();exit;
                    $checkSkillExits = skillJobroleMap::where('id', $request->id)->first();
                    // return $checkSkillExits;exit;

                    // If skill-jobrole mapping exists, update skill
                    if (isset($checkSkillExits->skill)) {

                        $updateData = [
                            'department' => $request->department,
                            'sub_department' => $request->sub_department,
                            'category' => $request->category [$key] ?? null,
                            'sub_category' => $request->sub_category [$key] ?? null,
                            'title' => $skillName,
                            'description' => $skillDescription,
                            'sub_institute_id' => $request->sub_institute_id,
                            'updated_by' => $request->user_id,
                            'updated_at' => now(),
                            'status' => 'Active',
                            'approve_status' => 'approved'
                        ];
                    // return $updateData;exit;

                        $lastInsertedId = userSkills::where('id', $request->skill_id)->update($updateData);
                         $updateArray = [
                            'skill' => $skillName,
                            'jobrole' =>  $request->jobrole,
                            'proficiency_level' => $request->proficiency_level[$key] ?? null,
                            'sub_institute_id' => $request->sub_institute_id,
                            'updated_by' => $request->user_id,
                            'updated_at' => now(),
                        ];
                        $update = skillJobroleMap::where('id', $request->id)->update($updateArray);
                    }
                }else{
                     $insertArray = [
                            'skill' => $skillName,
                            'jobrole' =>  $request->jobrole,
                            'proficiency_level' => $request->proficiency_level[$key] ?? null,
                            'sub_institute_id' => $request->sub_institute_id,
                            'created_by' => $request->user_id,
                            'created_at' => now(),
                        ];
                        $insert = skillJobroleMap::insert($insertArray);
                }
            }
            $i++;
        } else if ($request->formType == "tasks") {
            // Update or insert tasks
            foreach ($request->taskName as $key => $taskName) {
                $checkTaskExits = userJobroleTask::where('jobrole', $request->jobrole)->where('task', $request->taskName)->first();
                if (!$checkTaskExits && !isset($request->id)) {
                    $insertData = [
                        'sector' => $request->sector[$key] ?? null,
                        'track' => $request->track[$key] ?? null,
                        'jobrole' => $request->jobrole,
                        'critical_work_function' => $request->critical_work_function[$key] ?? null,
                        'task' => $taskName,
                        'sub_institute_id' => $request->sub_institute_id,
                        'created_by' => $request->user_id,
                        'created_at' => now(),
                    ];
                    $lastInsertedId = userJobroleTask::insertGetId($insertData);
                } else {
                    $insertData = [
                        'sector' => $request->sector[$key] ?? null,
                        'track' => $request->track[$key] ?? null,
                        'jobrole' => $request->jobrole,
                        'critical_work_function' => $request->critical_work_function[$key] ?? null,
                        'task' => $taskName,
                        'sub_institute_id' => $request->sub_institute_id,
                        'updated_by' => $request->user_id,
                        'updated_at' => now(),
                    ];
                    $lastInsertedId = userJobroleTask::where('id', $request->id)->update($insertData);
                }
            }
            $i++;
        }

        // Prepare response
        if ($i > 0) {
            $res['status_code'] = 1;
            $res['message'] = 'updated data successfully !';
        } else {
            $res['status_code'] = 0;
            $res['message'] = 'Failed to updated data';
        }
        // Return response based on device type
        return is_mobile($type, 'jobrole_library.index', $res, 'redirect');
    }

    /**
     * Remove the specified job role or related data.
     */
    public function destroy(Request $request, $id)
    {
        $type = $request->type;
        // If API, validate token and required fields
        if ($type == 'API') {
            $token = $request->input('token');  // get token from input field 'token'

            // Check if token is provided
            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            // If token is invalid
            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }

            // Validate required fields
            $validator = Validator::make($request->all(), [
                'org_type' => 'required',
                'sub_institute_id' => 'required',
                'user_id' => 'required',
                'formType' => 'required',
            ]);

            // If validation fails
            if ($validator->fails()) {
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
        }
        $i = 0;

        // If deleting a skill
        if ($request->has('formType') && $request->formType == "skills") {
            $delete = userSkills::where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $request->user_id]);
            if ($delete) {
                $i++;
            }
        }
        // If deleting a task
        if ($request->has('formType') && $request->formType == "tasks") {
            $delete = userJobroleTask::where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $request->user_id]);
            if ($delete) {
                $i++;
            }
        }
        // If deleting a user jobrole
        if ($request->has('formType') && $request->formType == "user") {
            $delete = userJobroleModel::where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $request->user_id]);
            if ($delete) {
                $i++;
            }
        }

        // Prepare response
        if ($i > 0) {
            $res['status_code'] = 1;
            $res['message'] = 'Deleted data successfully !';
        } else {
            $res['status_code'] = 0;
            $res['message'] = 'Failed to updated data';
        }
        // Return response based on device type
        return is_mobile($type, 'jobrole_library.index', $res, 'redirect');
    }
}
