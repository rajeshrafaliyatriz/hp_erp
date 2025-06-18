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
    /**
     * Display a listing of the job roles.
     */
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
            // ->join('s_jobrole_skills as b', function($join) {
            //     $join->on('b.sector', '=', 'a.department')
            //         ->on('a.sub_department', '=', 'b.track');
            // })
            ->when($request->has('department'), function ($q) use ($request) {
                // Filter by department if provided
                $q->where('a.department', $request->department);
            })
            ->when($request->has('sub_department'), function ($q) use ($request) {
                // Filter by sub_department if provided
                $q->whereIn('a.sub_department', explode(',', $request->sub_department));
            })
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
            ->whereNull('deleted_at')->get();

        // Prepare response data
        $res['jobroleData'] = $jobroleData;
        $res['alljobroleData'] = $treeData;
        $res['tableData'] = $usersJobroles;
        $res['usersJobroles'] = $usersJobroles;
        $res['userTree'] = $userTree ?? [];
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
                ->get()
                ->map(function ($item) {
                    $data = $item->toArray();

                    // Add skill fields if available
                    if ($item->userSkills) {
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
        } elseif ($request->has('formType') && $request->formType == "tasks") {
            // If formType is 'tasks', fetch user jobrole tasks
            $res['usertaskData'] = userJobroleTask::with([
                'userJobrole' => fn($q) => $q->select($jobroleFields),
                'createdUser' => fn($q) => $q->select($createdUser),
            ])
                ->where('jobrole', $request->jobrole)
                ->where('sub_institute_id', $request->sub_institute_id)
                ->whereNull('deleted_at')
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
                ->whereNull('deleted_at')->get();
            $res['tableData'] = $usersJobroles;
        }
        // Return response based on device type
        return is_mobile($type, 'skill_library.index', $res, 'redirect');
    }

    /**
     * Store a newly created job role or related data.
     */
    public function store(Request $request)
    {
        // return $request;exit;
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

        $i = 0;
        // If formType is 'master', add jobrole and related skills/tasks/knowledge/ability
        if ($request->formType == "master") {

            // Check if the job role already exists for this institute
            $jobData = jobroleModel::where('id', $request->jobroleId)->first();
            // return $jobData;exit;
            $jobExists = UserJobroleModel::where('jobrole', $request->jobrole)
                ->where('sub_institute_id', $request->sub_institute_id)
                ->whereNull('deleted_at')
                ->exists();
            // If jobrole exists in master and not in user jobrole
            if ($jobData && !$jobExists) {
                $insertData = [
                    'jobrole' => $jobData->jobrole,
                    'description' => $jobData->description,
                    'sub_institute_id' => $request->sub_institute_id,
                    'created_by' => $request->user_id,
                    'created_at' => now(),
                ];

                $lastInsertedId  = userJobroleModel::insert($insertData);
                // If jobrole inserted successfully
                if ($lastInsertedId && $lastInsertedId != 0) {
                     $jobroleLastInserted = userJobroleModel::where('id', $lastInsertedId)->first();
                    // add skill related jobrole
                    $getSkillsExists = skillJobroleMap::where('jobrole', $request->jobrole)
                        ->where('sub_institute_id', $request->sub_institute_id)
                        ->whereNull('deleted_at')
                        ->exists();
                    // If skills for this jobrole do not exist, insert them
                    if (!$getSkillsExists) {
                        $getAllJobrolesSkill = DB::table('s_jobrole_skills as a')
                            ->join('master_skills as b', 'b.title', '=', 'a.skill')
                            ->where('a.jobrole', $jobData->jobrole)
                            ->where('a.track', $jobData->track)
                            ->get()
                            ->toArray();
                        foreach ($getAllJobrolesSkill as $key => $value) {
                            $skilArr = [
                                "category" => $value->category,
                                "sub_category" => $value->sub_category,
                                "title" => $value->title,
                                "description" => $value->description,
                                "sub_institute_id" => $request->sub_institute_id,
                                // "user_id"=>$user_id,
                            ];
                            $skilArr['created_by'] = $request->user_id;
                            $skilArr['created_at'] = now();
                            $skilArr['status'] = 'Active';
                            $skilArr['approve_status'] = "approved";
                            // check not exists
                            $checkSkillExits = userSkills::where([
                                "category" => $value->category,
                                "sub_category" => $value->sub_category,
                                "title" => $value->title
                            ]);

                            if (!$checkSkillExits->exists()) {
                                $lastSkillId  = userSkills::insertGetId($skilArr);
                                // If skill inserted successfully
                                if ($lastSkillId && $lastSkillId != 0) {
                                    $skillName = userSkills::where('id', $lastSkillId)->value('title');
                                    $getAllJobrolesSkill = DB::table('s_jobrole_skills')->where('jobrole', $request->jobrole)->where('skill', $skillName)->get()->toArray();
                                    if (!empty($getAllJobrolesSkill)) {
                                        foreach ($getAllJobrolesSkill as $jk => $jv) {
                                            $insertArray = [
                                                'skill' => $skillName,
                                                'jobrole' => $jv->jobrole,
                                                'proficiency_level' => $jv->proficiency_level,
                                                'sub_institute_id' => $request->sub_institute_id,
                                                'created_by' => $request->user_id,
                                                'created_at' => now(),
                                            ];
                                            $insert = skillJobroleMap::insert($insertArray);
                                        }

                                        // Insert knowledge abilities
                                        $knowledgeArr = DB::table('s_skill_map_k_a')->where('tsc_ccs_title', $skillName)->where('knowledge_ability_classification', 'knowledge')->groupBy('knowledge_ability_items')->get()->toArray();
                                        if (!empty($knowledgeArr)) {
                                            foreach ($knowledgeArr as $jk => $jv) {
                                                $knowledgeInsert = [
                                                    'skill_id' => $lastSkillId,
                                                    'proficiency_level' => $jv->proficiency_level,
                                                    'classification' => 'knowledge',
                                                    'classification_item' => $jv->knowledge_ability_items,
                                                    'sub_institute_id' => $request->sub_institute_id,
                                                    'created_by' => $request->user_id,
                                                    'created_at' => now(),
                                                ];
                                                $insert = userKnowledgeAbility::insert($knowledgeInsert);
                                            }
                                        }

                                        // Insert ability abilities
                                        $abilityArr = DB::table('s_skill_map_k_a')->where('tsc_ccs_title', $skillName)->where('knowledge_ability_classification', 'ability')->groupBy('knowledge_ability_items')->get()->toArray();
                                        if (!empty($abilityArr)) {
                                            foreach ($abilityArr as $jk => $jv) {
                                                $abilityInsert = [
                                                    'skill_id' => $lastSkillId,
                                                    'proficiency_level' => $jv->proficiency_level,
                                                    'classification' => 'ability',
                                                    'classification_item' => $jv->knowledge_ability_items,
                                                    'sub_institute_id' => $request->sub_institute_id,
                                                    'created_by' => $request->user_id,
                                                    'created_at' => now(),
                                                ];
                                                $insert = userKnowledgeAbility::insert($abilityInsert);
                                            }
                                        }

                                        // Insert jobrole tasks
                                        $jobroleTask = DB::table('s_jobrole_task')->where('jobrole', $request->jobrole)->get()->toArray();
                                        if (!empty($jobroleTask)) {
                                            foreach ($jobroleTask as $jk => $jv) {
                                                $taskInsert = [
                                                    'sector' => $jv->sector,
                                                    'track' => $jv->track,
                                                    'jobrole' => $jv->jobrole,
                                                    'critical_work_function' => $jv->critical_work_function,
                                                    'task' => $jv->task,
                                                    'sub_institute_id' => $request->sub_institute_id,
                                                    'created_by' => $request->user_id,
                                                    'created_at' => now(),
                                                ];
                                                $insert = userJobroleTask::insert($taskInsert);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }

                }
            }
            $i++;
        } else {
            // If not master, insert user jobrole directly
            $insertData = [
                'jobrole' => $request->jobrole,
                'description' => $request->description,
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
            if (!$jobExists) {
                userJobroleModel::insert($insertData);
            }

            $i++;
        }

        // Fetch all user jobroles for the sub_institute and build userTree
        $usersJobroles = userJobroleModel::join('s_jobrole as c', 'c.jobrole', '=', 's_user_jobrole.jobrole')
            ->join('s_industries as a', function ($join) use ($request) {
                $join->on('a.sub_department', '=', 'c.track')
                    ->where('a.industries', 'like', '%' . $request->org_type . '%');

                if ($request->has('department')) {
                    $join->where('a.department', $request->department);
                }
                if ($request->has('sub_department')) {
                    $join->whereIn('a.sub_department', explode(',', $request->sub_department));
                }
            })
            ->where('s_user_jobrole.sub_institute_id', $request->sub_institute_id)
            ->whereNull('s_user_jobrole.deleted_at')
            ->select('s_user_jobrole.*', 'a.*')
            ->groupBy('s_user_jobrole.jobrole')
            ->get();
        // Build userTree structure
        $userTree = [];
        foreach ($usersJobroles as $key => $value) {
            if (isset($value['sub_department']) && $value['sub_department'] != null && $value['sub_department'] != '') {
                $userTree[$value['department']][$value['sub_department']][] = $value;
            } else {
                $userTree[$value['department']]['no_sub_department'][] = $value;
            }
        }

        // Prepare response
        if ($i > 0) {
            $res['status_code'] = 1;
            $res['message'] = 'Added data successfully !';
            $res['usersJobroles'] = $usersJobroles;
            $res['userTree'] = $userTree;
        } else {
            $res['status_code'] = 0;
            $res['message'] = 'Failed to Add data';
        }
        // Return response based on device type
        return is_mobile($type, 'skill_library.index', $res, 'redirect');
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
        return is_mobile($type, 'skill_library.index', $res, 'redirect');
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
                'jobrole' => $request->jobrole,
                'description' => $request->description,
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
                        $insertArray = [
                            'skill' => $skillName,
                            'jobrole' => $request->jobrole,
                            'description' => null,
                            'sub_institute_id' => $request->sub_institute_id,
                            'created_by' => $request->user_id,
                            'created_at' => now(),
                        ];
                        $insert = skillJobroleMap::insert($insertArray);
                    }
                } else {
                    $checkSkillExits = skillJobroleMap::where('id', $request->id)->first();
                    // If skill-jobrole mapping exists, update skill
                    if (isset($checkSkillExits->skill_id)) {

                        $updateData = [
                            'title' => $skillName,
                            'description' => $skillDescription,
                            'sub_institute_id' => $request->sub_institute_id,
                            'updated_by' => $request->user_id,
                            'updated_at' => now(),
                            'status' => 'Active',
                            'approve_status' => 'approved'
                        ];

                        $lastInsertedId = userSkills::where('id', $checkSkillExits->skill_id)->update($updateData);
                    }
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
        return is_mobile($type, 'skill_library.index', $res, 'redirect');
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
        return is_mobile($type, 'skill_library.index', $res, 'redirect');
    }
}
