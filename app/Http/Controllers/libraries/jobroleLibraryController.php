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
        if ($type == 'API') {
            $token = $request->input('token');  // get token from input field 'token'

            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }
            $validator = Validator::make($request->all(), [
                'org_type' => 'required',
                'sub_institute_id' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
        }
        $jobroleData = industryModel::from('s_industries as a')
            // ->join('s_jobrole_skills as b', function($join) {
            //     $join->on('b.sector', '=', 'a.department')
            //         ->on('a.sub_department', '=', 'b.track');
            // })
            ->when($request->has('department'), function ($q) use ($request) {
                $q->where('a.department', $request->department);
            })
            ->when($request->has('sub_department'), function ($q) use ($request) {
                $q->whereIn('a.sub_department', explode(',', $request->sub_department));
            })
            ->join('s_jobrole as c', 'c.track', '=', 'a.sub_department')
            ->where('a.industries', 'like', '%' . $request->org_type . '%')
            ->select('c.*')
            ->groupBy('c.id')
            ->get();

        // echo "<pre>";print_r($jobroleData);exit;
        // $skills = DB::table('s_jobrole')->get();

        $treeData = [];
        foreach ($jobroleData as $key => $value) {
            if (isset($value['sub_department']) && $value['sub_department'] != null && $value['sub_department'] != '') {
                $treeData[$value['department']][$value['sub_department']][] = $value;
            } else {
                $treeData[$value['department']]['no_sub_category'][] = $value;
            }
        }

        $getSectore = industryModel::where('industries', $request->org_type)
            ->when($request->has('department'), function ($q) use ($request) {
                $q->where('department', $request->department);
                $q->where('sub_department', '!=', '');
                $q->groupBy('sub_department');
            }, function ($q) {
                $q->groupBy('department');
            });

        $usersJobroles = userJobroleModel::where('sub_institute_id', $request->sub_institute_id)
            ->whereNull('deleted_at')->get();

        $res['jobroleData'] = $jobroleData;
        $res['alljobroleData'] = $treeData;
        $res['tableData'] = $usersJobroles;
        $res['usersJobroles'] = $usersJobroles;
        $res['userTree'] = $userTree ?? [];
        return is_mobile($type, 'jobrole_library.index', $res, 'redirect');
    }

    public function create(Request $request)
    {
        $type = $request->type;
        if ($type == 'API') {
            $token = $request->input('token');  // get token from input field 'token'

            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }
            $validator = Validator::make($request->all(), [
                'org_type' => 'required',
                'sub_institute_id' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
        }

        $skillFields = ['id', 'category', 'sub_category', 'title', 'description'];
        $jobroleFields = ['id', 'jobrole', 'description'];
        $createdUser = ['id', 'first_name', 'middle_name', 'last_name'];


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

                    if ($item->userSkills) {
                        $data['category'] = $item->userSkills->category;
                        $data['sub_category'] = $item->userSkills->sub_category;
                        $data['skillTitle'] = $item->userSkills->title;
                        $data['skillDescription'] = $item->userSkills->description;
                    }
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
        } elseif ($request->has('formType') && $request->formType == "tasks") {

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

            $usersJobroles = userJobroleModel::where('sub_institute_id', $request->sub_institute_id)
                ->whereNull('deleted_at')->get();
            $res['tableData'] = $usersJobroles;

            // $proficiency_level = DynamicModel::readRecords('z_master_select')->where('select_name','Proficiency Level');
            // $res['proficiency_levels'] = $proficiency_level;
        }
        return is_mobile($type, 'skill_library.index', $res, 'redirect');
    }

    public function store(Request $request)
    {
        // return $request;exit;
        $type = $request->type;
        if ($type == 'API') {
            $token = $request->input('token');  // get token from input field 'token'

            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }

            $validator = Validator::make($request->all(), [
                'org_type' => 'required',
                'sub_institute_id' => 'required',
                'user_profile_name' => 'required',
                'user_id' => 'required',
                'formType' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
        }

        $i = 0;
        if ($request->formType == "master") {

            // Check if the job role already exists for this institute
            $jobData = jobroleModel::where('id', $request->jobroleId)->first();
            $jobExists = UserJobroleModel::where('jobrole', $request->jobrole)
                ->where('sub_institute_id', $request->sub_institute_id)
                ->whereNull('deleted_at')
                ->exists();
            if ($jobData && !$jobExists) {
                $insertData = [
                    'jobrole' => $jobData->jobrole,
                    'description' => $jobData->description,
                    'sub_institute_id' => $request->sub_institute_id,
                    'created_by' => $request->user_id,
                    'created_at' => now(),
                ];

                $lastInsertedId  = userJobroleModel::insert($insertData);
                if ($lastInsertedId && $lastInsertedId != 0) {
                    $getSkillsExists = skillJobroleMap::where('jobrole', $request->jobrole)
                        ->where('sub_institute_id', $request->sub_institute_id)
                        ->whereNull('deleted_at')
                        ->exists();
                    if (!$getSkillsExists) {
                        $getAllJobrolesSkill = DB::table('s_jobrole_skills as a')
                            ->join('master_skills as b', 'b.title', '=', 'a.skill')
                            ->where('a.jobrole', $request->jobrole)
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

                            $lastSkillId  = userSkills::insertGetId($skilArr);
                            if ($lastSkillId && $lastSkillId != 0) {
                                $skillName = userSkills::where('id', $lastSkillId)->value('title');
                                $getAllJobrolesSkill = DB::table('s_jobrole_skills')->where('skill', $skillName)->get()->toArray();
                                if (!empty($getAllJobrolesSkill)) {
                                    foreach ($getAllJobrolesSkill as $jk => $jv) {
                                        $insertArray = [
                                            'skill_id' => $lastSkillId,
                                            'jobrole' => $jv->jobrole,
                                            'description' => null,
                                            'sub_institute_id' => $request->sub_institute_id,
                                            'created_by' => $request->user_id,
                                            'created_at' => now(),
                                        ];
                                        $insert = skillJobroleMap::insert($insertArray);
                                    }

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

                                    // userJobroleTask
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
            $i++;
        } else {
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

            if (!$jobExists) {
                // Insert the new job role
                userJobroleModel::insert($insertData);
            }

            $i++;
        }

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
        // return $usersJobroles
        $userTree = [];
        foreach ($usersJobroles as $key => $value) {
            if (isset($value['sub_department']) && $value['sub_department'] != null && $value['sub_department'] != '') {
                $userTree[$value['department']][$value['sub_department']][] = $value;
            } else {
                $userTree[$value['department']]['no_sub_department'][] = $value;
            }
        }

        if ($i > 0) {
            $res['status_code'] = 1;
            $res['message'] = 'Added data successfully !';
            $res['usersJobroles'] = $usersJobroles;
            $res['userTree'] = $userTree;
        } else {
            $res['status_code'] = 0;
            $res['message'] = 'Failed to Add data';
        }
        return is_mobile($type, 'skill_library.index', $res, 'redirect');
    }

    public function edit(Request $request, $id)
    {
        $type = $request->type;
        if ($type == 'API') {
            $token = $request->input('token');  // get token from input field 'token'

            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }

            $validator = Validator::make($request->all(), [
                'org_type' => 'required',
                'sub_institute_id' => 'required',
                'formType' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
        }
        $skillFields = ['id', 'category', 'sub_category', 'title'];
        $createdUser = ['id', 'first_name', 'middle_name', 'last_name'];
        $res['editData'] = jobroleModel::find($id);
        if ($request->formType == "user") {
            $res['editData'] = userJobroleModel::find($id);
        }

        return is_mobile($type, 'skill_library.index', $res, 'redirect');
    }
    public function update(Request $request, $id)
    {
        $type = $request->type;
        if ($type == 'API') {
            $token = $request->input('token');  // get token from input field 'token'

            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }

            $validator = Validator::make($request->all(), [
                'org_type' => 'required',
                'sub_institute_id' => 'required',
                'user_profile_name' => 'required',
                'user_id' => 'required',
                'formType' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
        }

        $skillFields = ['id', 'category', 'sub_category', 'title'];
        $createdUser = ['id', 'first_name', 'middle_name', 'last_name'];
        $i = 0;
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

            if ($jobExists && isset($jobExists->id)) {
                // Insert the new job role
                userJobroleModel::where('id', $jobExists->id)->update($updateData);
            }

            $i++;
        } else if ($request->formType == "skills") {
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
                            'skill_id' => $lastInsertedId,
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
                    //  return $checkSkillExits;
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

        if ($i > 0) {
            $res['status_code'] = 1;
            $res['message'] = 'updated data successfully !';
        } else {
            $res['status_code'] = 0;
            $res['message'] = 'Failed to updated data';
        }
        return is_mobile($type, 'skill_library.index', $res, 'redirect');
    }
    public function destroy(Request $request, $id)
    {
        $type = $request->type;
        if ($type == 'API') {
            $token = $request->input('token');  // get token from input field 'token'

            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }

            $validator = Validator::make($request->all(), [
                'org_type' => 'required',
                'sub_institute_id' => 'required',
                'user_id' => 'required',
                'formType' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
        }
        $i = 0;

        if ($request->has('formType') && $request->formType == "skills") {
            $delete = userSkills::where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $request->user_id]);
            if ($delete) {
                $i++;
            }
        }
        if ($request->has('formType') && $request->formType == "tasks") {
            $delete = userJobroleTask::where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $request->user_id]);
            if ($delete) {
                $i++;
            }
        }
        if ($request->has('formType') && $request->formType == "user") {
            $delete = userJobroleModel::where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $request->user_id]);
            if ($delete) {
                $i++;
            }
        }

        if ($i > 0) {
            $res['status_code'] = 1;
            $res['message'] = 'Deleted data successfully !';
        } else {
            $res['status_code'] = 0;
            $res['message'] = 'Failed to updated data';
        }
        return is_mobile($type, 'skill_library.index', $res, 'redirect');
    }
}
