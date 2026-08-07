<?php

namespace App\Http\Controllers\libraries;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\libraries\skillLibraryModel;
use App\Models\libraries\industryModel;
use function App\Helpers\is_mobile;
use Laravel\Sanctum\PersonalAccessToken;
use App\Models\libraries\userSkills;
use App\Models\libraries\skillJobroleMap;
use App\Models\libraries\userProfeceincyLevel;
use App\Models\libraries\userKnowledgeAbility;
use App\Models\libraries\userApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;

class skillLibraryController extends Controller
{
    use ResolvesApiIdentity;

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
    private function g2gActorId(\Illuminate\Http\Request $request): ?int
    {
        $fromToken = $this->apiUserId($request);
        if ($fromToken) {
            return $fromToken;
        }
        $fromSession = $request->session()->get('user_id');

        return is_numeric($fromSession) ? (int) $fromSession : null;
    }


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

        $AllskillData = industryModel::from('s_industries as a')
            // ->select('c.*')
            ->join('s_jobrole_skills as b', function ($join) {
                $join->on('a.sub_department', '=', 'b.track')
                    ->useIndex('idx_sector_track');
                // ->on('b.sector', '=', 'a.department')
            })
            // ->join('master_skills as c', 'c.title', '=', 'b.skill')
            ->where('a.industries', $request->org_type)
            ->when($request->has('department'), function ($q) use ($request) {
                $q->where('a.department', $request->department);
            })
            ->when($request->has('sub_department'), function ($q) use ($request) {
                $q->whereIn('a.sub_department', explode(',', $request->sub_department));
            })
            ->groupBy('b.skill')
            ->get();

        $skillData = [];
        foreach ($AllskillData as $key => $values) {
            $skill = DB::table('master_skills')
                ->where('title', $values->skill)
                ->select('id', 'category', 'sub_category', 'title', 'description', 'status', 'related_skills', 'bussiness_links', 'custom_tags', 'proficiency_level', 'job_titles', 'learning_resources', 'assesment_method', 'certification_qualifications', 'experience_project', 'skill_maps')
                ->first();

            if ($skill) {
                $skillData[] = (array) $skill + [
                    'department' => $values->department,
                    'sub_department' => $values->sub_department,
                ];
            }
        }
        // echo "<pre>";print_r($skillData);exit;
        // $skills = DB::table('s_jobrole')->get();

        $treeData = [];
        foreach ($skillData as $key => $value) {
            if (isset($value['sub_category']) && $value['sub_category'] != null && $value['sub_category'] != '') {
                $treeData[$value['category']][$value['sub_category']][] = $value;
            } else {
                $treeData[$value['category']]['no_sub_category'][] = $value;
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
        // echo "<pre>";print_r($request->all());exit;
        $userSkills = userSkills::where('sub_institute_id', $request->sub_institute_id)
            ->where('approve_status', 'Approved')
            ->when($request->has('category') && $request->category != '', function ($q) use ($request) {
                $q->where('category', $request->category);
            })
            ->when($request->has('sub_category') && $request->sub_category != '', function ($q) use ($request) {
                $q->whereIn('sub_category', explode(',', $request->sub_category));
            })
            // ->where('status', 'Active')
            ->get();
        // return $userSkills
        $userTree = [];
        foreach ($userSkills as $key => $value) {
            if (isset($value['sub_category']) && $value['sub_category'] != null && $value['sub_category'] != '') {
                $userTree[$value['category']][$value['sub_category']][] = $value;
            } else {
                $userTree[$value['category']]['no_sub_category'][] = $value;
            }
        }

        $res['jobroleSkill'] = $getSectore->get()->toArray();
        $res['allSkillData'] = $treeData;
        $res['tableData'] = $userSkills;
        $res['userSkills'] = $userSkills;
        $res['userTree'] = $userTree;
        // echo "<pre>";print_r($userSkills);exit;
        return is_mobile($type, 'skill_library.index', $res, 'redirect');
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

        $skillFields = ['id', 'category', 'sub_category', 'title'];
        $createdUser = ['id', 'first_name', 'middle_name', 'last_name'];
        $jobroleFields = ['id', 'jobrole', 'description'];
        $res['proficiency_levels'] = $this->getProficiencyLevels($request, 'usersProficiencyLevel', $request->skill_id);

        $res['grouped_proficiency_levels'] = $this->getProficiencyLevels($request, 'groupedProficiencyLevels', $request->skill_id);

        // getskill name first 
        $skillName = userSkills::where('id', $request->skill_id)
            ->where('sub_institute_id', $request->sub_institute_id)
            ->whereNull('deleted_at')
            ->value('title');
        if ($request->has('formType') && $request->formType == "jobrole") {


            $res['userJobroleData'] = $this->getJobroleData($request, $skillName, 'usersJobrole');
            // echo "<pre>";print_r($res['userJobroleData']);exit;
        } else if ($request->has('formType') && $request->formType == "proficiency_level") {
            $res['userproficiency_levelData'] = $this->getProficiencyLevels($request, '', $request->skill_id);
            if (empty($res['userproficiency_levelData'])) {
                $res['userproficiency_levelData'] = userProfeceincyLevel::whereNull('skill_id')
                    ->whereNull('sub_institute_id')
                    ->whereNull('deleted_at')
                    ->get();
            }
        } else if ($request->has('formType') && $request->formType == "knowledge") {
            $res['userKnowledgeData'] = $this->getKnowledgeAbilityData($request, $request->skill_id, 'knowledge');
        } else if ($request->has('formType') && $request->formType == "ability") {
            $res['userabilityData'] = $this->getKnowledgeAbilityData($request, $request->skill_id, 'ability');
        }
        else if ($request->has('formType') && $request->formType == "attitude") {
            $res['userAttitudeData'] = $this->getKnowledgeAbilityData($request, $request->skill_id, 'attitude');
        }
        else if ($request->has('formType') && $request->formType == "behaviour") {
            $res['userBehaviourData'] = $this->getKnowledgeAbilityData($request, $request->skill_id, 'behaviour');
        }
        // userApplication
        else if ($request->has('formType') && $request->formType == "application") {
            $res['userApplicationData'] = $this->getApplicationData($request, $skillName);
            // echo "<pre>";print_r($res['userApplicationData']);exit;
        } else {

            $AllskillData = industryModel::from('s_industries as a')
                // ->select('c.*')
                ->join('s_jobrole_skills as b', function ($join) {
                    $join->on('a.sub_department', '=', 'b.track')
                        ->useIndex('idx_sector_track');
                    // ->on('b.sector', '=', 'a.department')
                })
                // ->join('master_skills as c', 'c.title', '=', 'b.skill')
                ->where('a.industries', $request->org_type)
                ->when($request->filled(['department', 'sub_department']), function ($query) use ($request) {
                    return $query->where('a.department', $request->department)
                        ->whereIn('a.sub_department', explode(',', $request->sub_department));
                }, function ($query) use ($request) {
                    return $query->when($request->has('department'), function ($q) use ($request) {
                        return $q->where('a.department', $request->department);
                    });
                })
                ->groupBy('b.skill')
                ->get();

            $skillData = [];
            foreach ($AllskillData as $key => $values) {
                $skill = DB::table('master_skills')
                    ->where('title', $values->skill)
                    ->select('id', 'category', 'sub_category', 'title', 'description', 'status', 'related_skills', 'bussiness_links', 'custom_tags', 'proficiency_level', 'job_titles', 'learning_resources', 'assesment_method', 'certification_qualifications', 'experience_project', 'skill_maps')
                    ->first();

                if ($skill) {
                    $skillData[] = (array) $skill + [
                        'department' => $values->department,
                        'sub_department' => $values->sub_department,
                    ];
                }
            }

            $res['skillData'] = $skillData;

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
        $appStatus = 'Pending';
        if ($request->user_profile_name == "Admin") {
            $appStatus = 'Approved';
        }
        $i = 0;
        if ($request->formType == "master") {

            $skillData = industryModel::from('s_industries as a')
                ->join('s_jobrole_skills as b', function ($join) {
                    $join->on('a.sub_department', '=', 'b.track');
                    // ->on('b.sector', '=', 'a.department')
                })
                ->when($request->has('department'), function ($q) use ($request) {
                    $q->where('a.department', $request->department);
                })
                ->when($request->has('sub_department'), function ($q) use ($request) {
                    $q->whereIn('a.sub_department', $request->sub_department);
                })
                ->when($request->has('skill_name'), function ($q) use ($request) {
                    $q->where('c.title', $request->skill_name);
                })
                ->join('master_skills as c', 'c.title', '=', 'b.skill')
                ->where('a.industries', 'like', '%' . $request->org_type . '%')
                ->selectRaw('c.*,a.department,a.sub_department')
                ->get();
            // return $skillData;exit;
            // return $request;
            foreach ($skillData as $key => $value) {
                $industries = $request->org_type;
                $category = $value['category'];
                $sub_category = $value['sub_category'];
                $skillName = $value['title'];
                $description = $value['description'];
                $status = $value['status'];
                $related_skills = $value['related_skills'];
                $bussiness_links = $value['bussiness_links'];
                $custom_tags = $value['custom_tags'];
                $proficiency_level = $value['proficiency_level'];
                $job_titles = $value['job_titles'];
                $learning_resources = $value['learning_resources'];
                $assesment_method = $value['assesment_method'];
                $certification_qualifications = $value['certification_qualifications'];
                $experience_project = $value['experience_project'];
                $skill_maps = $value['skill_maps'];
                $sub_institute_id = $request->sub_institute_id;
                $user_id = $request->user_id;

                $insertArray = [
                    "category" => $category,
                    "sub_category" => $sub_category,
                    "title" => $skillName,
                    "description" => $description,
                    "sub_institute_id" => $sub_institute_id,
                    // "user_id"=>$user_id,
                ];
                $check = userSkills::where($insertArray)->first();
                if (!$check) {
                    $insertArray['department_id'] = $value['id'];
                    $insertArray['department'] = $value['department'];
                    $insertArray['sub_department'] = $value['sub_department'];
                    $insertArray['created_by'] = $user_id;
                    $insertArray['created_at'] = now();
                    $insertArray['status'] = $status;
                    $insertArray['approve_status'] = $appStatus;
                    $insertArray['related_skills'] = $related_skills;
                    $insertArray['bussiness_links'] = $bussiness_links;
                    $insertArray['custom_tags'] = $custom_tags;
                    $insertArray['proficiency_level'] = $value['proficiency_level'];
                    $insertArray['job_titles'] = $value['job_titles'];
                    $insertArray['learning_resources'] = $value['learning_resources'];
                    $insertArray['assesment_method'] = $value['assesment_method'];
                    $insertArray['certification_qualifications'] = $value['certification_qualifications'];
                    $insertArray['experience_project'] = $value['experience_project'];
                    $insertArray['skill_maps'] = $value['skill_maps'];

                    $lastInsertedId  = userSkills::insertGetId($insertArray);

                    if ($lastInsertedId && $lastInsertedId != 0) {
                        $getAllJobrolesSkill = DB::table('s_jobrole_skills')->where('skill', $skillName)->get()->toArray();
                        if (!empty($getAllJobrolesSkill)) {
                            foreach ($getAllJobrolesSkill as $jk => $jv) {
                                $insertArray = [
                                    'skill' => $skillName,
                                    'jobrole' => $jv->jobrole,
                                    // 'description' => null,
                                    'sub_institute_id' => $request->sub_institute_id,
                                    'created_by' => $this->g2gActorId($request),
                                    'created_at' => now(),
                                ];
                                $check = DB::table('s_user_skill_jobrole')->where([
                                    'skill' => $skillName,
                                    'jobrole' => $jv->jobrole,
                                    'sub_institute_id' => $request->sub_institute_id
                                ])->first();
                                if (!$check) {
                                    $insert = skillJobroleMap::insert($insertArray);
                                }
                            }

                            $proficiencyLevelArr = DB::table('s_skill_map_k_a')->where('tsc_ccs_title', $skillName)->groupBy('proficiency_level')->get()->toArray();
                            if (!empty($proficiencyLevelArr)) {
                                foreach ($proficiencyLevelArr as $jk => $jv) {
                                    $insertArray = [
                                        'skill_id' => $lastInsertedId,
                                        'proficiency_level' => $jv->proficiency_level,
                                        'description' => $jv->proficiency_description,
                                        'sub_institute_id' => $request->sub_institute_id,
                                        'created_by' => $this->g2gActorId($request),
                                        'created_at' => now(),
                                    ];
                                    $check = userProfeceincyLevel::where([
                                        'skill_id' => $lastInsertedId,
                                        'proficiency_level' => $jv->proficiency_level,
                                        'sub_institute_id' => $request->sub_institute_id,
                                    ])->first();
                                    if (!$check) {
                                        $insert = userProfeceincyLevel::insert($insertArray);
                                    }
                                }
                            }

                            $knowledgeArr = DB::table('s_skill_map_k_a')
                                ->where('tsc_ccs_title', $skillName)
                                ->where('knowledge_ability_classification', 'knowledge')
                                ->groupBy('knowledge_ability_items')
                                ->get()
                                ->toArray();
                            if (!empty($knowledgeArr)) {
                                foreach ($knowledgeArr as $jk => $jv) {
                                    $insertArray = [
                                        'skill_id' => $lastInsertedId,
                                        'proficiency_level' => $jv->proficiency_level,
                                        'classification' => 'knowledge',
                                        'classification_item' => $jv->knowledge_ability_items,
                                        'sub_institute_id' => $request->sub_institute_id,
                                        'created_by' => $this->g2gActorId($request),
                                        'created_at' => now(),
                                    ];
                                    $check = userKnowledgeAbility::where([
                                        'skill_id' => $lastInsertedId,
                                        'proficiency_level' => $jv->proficiency_level,
                                        'classification' => 'knowledge',
                                        'classification_item' => $jv->knowledge_ability_items,
                                    ])->first();
                                    if (!$check) {
                                        $insert = userKnowledgeAbility::insert($insertArray);
                                    }
                                }
                            }

                            $abilityArr = DB::table('s_skill_map_k_a')->where('tsc_ccs_title', $skillName)->where('knowledge_ability_classification', 'ability')->groupBy('knowledge_ability_items')->get()->toArray();
                            if (!empty($abilityArr)) {
                                foreach ($abilityArr as $jk => $jv) {
                                    $insertArray = [
                                        'skill_id' => $lastInsertedId,
                                        'proficiency_level' => $jv->proficiency_level,
                                        'classification' => 'ability',
                                        'classification_item' => $jv->knowledge_ability_items,
                                        'sub_institute_id' => $request->sub_institute_id,
                                        'created_by' => $this->g2gActorId($request),
                                        'created_at' => now(),
                                    ];
                                    $check = userKnowledgeAbility::where([
                                        'skill_id' => $lastInsertedId,
                                        'proficiency_level' => $jv->proficiency_level,
                                        'classification' => 'ability',
                                        'classification_item' => $jv->knowledge_ability_items,
                                    ])->first();
                                    if (!$check) {
                                        $insert = userKnowledgeAbility::insert($insertArray);
                                    }
                                }
                            }

                            // applicaion
                            $applicationArr = DB::table('s_skill_application')->where('skill', $skillName)->groupBy('id')->get()->toArray();
                            if (!empty($applicationArr)) {
                                foreach ($applicationArr as $jk => $jv) {
                                    $applicationInsert = [
                                        'skill_id' => $lastInsertedId,
                                        'proficiency_level' => $jv->proficiency_level,
                                        'application' => $jv->range_application,
                                        'sub_institute_id' => $request->sub_institute_id,
                                        'created_by' => $this->g2gActorId($request),
                                        'created_at' => now(),
                                    ];
                                    $insert = userApplication::insert($applicationInsert);
                                }
                            }
                        }
                    }
                }

                $i++;
            }
        } else {
            // return [$request,'type'=>$request->formType];
            $getIndustries = $request->has('department_id') ? \App\Models\HRMS\hrmsDepartmentModel::find($request->department_id) : industryModel::where('department', $request->category)->first();
            $insertArray = [
                "category" => $request->category,
                "sub_category" => $request->sub_category,
                "title" => $request->skill_name,
                "description" => $request->description,
                "sub_institute_id" => $request->sub_institute_id,
                // "user_id"=>$user_id,
            ];

            $check = userSkills::where($insertArray)->first();
            if (!$check) {
                $insertArray['department_id'] = $request->department_id ?? $getIndustries->id ?? null;
                $insertArray['department'] = $getIndustries->department ?? null;
                $insertArray['sub_department'] = $getIndustries->sub_department ?? null;
                $insertArray['created_by'] = $request->user_id;
                $insertArray['created_at'] = now();
                $insertArray['status'] = 'Active';
                $insertArray['approve_status'] = $appStatus;
                $insertArray['related_skills'] = json_encode($request->related_skills);
                $insertArray['bussiness_links'] = $request->bussiness_links;
                $insertArray['custom_tags'] =  json_encode($request->custom_tags);
                $insertArray['proficiency_level'] = $request->proficiency_level;
                $insertArray['job_titles'] = $request->job_titles;
                $insertArray['learning_resources'] = $request->learning_resources;
                $insertArray['assesment_method'] = $request->assesment_method;
                $insertArray['certification_qualifications'] = $request->certification_qualifications;
                $insertArray['experience_project'] = $request->experience_project;
                $insertArray['skill_maps'] = $request->skill_maps;

                $insert = userSkills::insert($insertArray);
            }

            $i++;
        }

        $userSkills = userSkills::where('status', 'Active')
            ->where('sub_institute_id', $request->sub_institute_id)
            ->where('approve_status', 'Approved')
            ->get();
        // return $userSkills
        $userTree = [];
        foreach ($userSkills as $key => $value) {
            if (isset($value['sub_category']) && $value['sub_category'] != null && $value['sub_category'] != '') {
                $userTree[$value['category']][$value['sub_category']][] = $value;
            } else {
                $userTree[$value['category']]['no_sub_category'][] = $value;
            }
        }

        if ($i > 0) {
            $res['status_code'] = 1;
            $res['message'] = 'Added data successfully !';
            $res['usersSkills'] = $userSkills;
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
        $jobroleFields = ['id', 'jobrole', 'description'];

        $res['editData'] = skillLibraryModel::find($id);
        if ($request->formType == "user") {
            $res['editData'] = userSkills::find($id);
        }
        $skillName = userSkills::where('id', $id)
            ->where('sub_institute_id', $request->sub_institute_id)
            ->whereNull('deleted_at')
            ->value('title');

        $res['userJobroleData'] = $this->getJobroleData($request, $skillName, 'usersJobrole');
        // $res['proficiency_levels'] = $proficiency_level;
        $res['userproficiency_levelData'] = $this->getProficiencyLevels($request, 'usersProficiencyLevel', $id);
        // echo "<pre>";print_r($res['userproficiency_levelData']);exit;

        if (empty($res['userproficiency_levelData'])) {
            $res['userproficiency_levelData'] = userProfeceincyLevel::whereNull('skill_id')
                ->whereNull('sub_institute_id')
                ->whereNull('deleted_at')
                ->get();
        }
        $res['userAttitudeData'] = $this->getKnowledgeAbilityData($request, $id, 'attitude');
        $res['userBehaviourData'] = $this->getKnowledgeAbilityData($request, $id, 'behaviour');
        $res['userKnowledgeData'] = $this->getKnowledgeAbilityData($request, $id, 'knowledge');
        $viewKnowledge = [];

        foreach ($res['userKnowledgeData'] as $value) {
            $viewKnowledge[$value['proficiency_level']][] = $value;
        }

        $res['userViewKnowledge'] = [];

        foreach ($viewKnowledge as $level => $items) {
            $res['userViewKnowledge'][] = [
                'proficiency_level' => $level,
                'items' => $items
            ];
        }

        $res['userabilityData'] = $this->getKnowledgeAbilityData($request, $id, 'ability');

        $viewAbility = [];

        foreach ($res['userabilityData'] as $value) {
            $viewAbility[$value['proficiency_level']][] = $value;
        }

        $res['userViewAbility'] = [];

        foreach ($viewAbility as $level => $items) {
            $res['userViewAbility'][] = [
                'proficiency_level' => $level,
                'items' => $items
            ];
        }
        $res['userApplicationData'] = $this->getApplicationData($request, $skillName);
        $res['skillName'] = $skillName;

        $viewApplication = [];

        foreach ($res['userApplicationData'] as $value) {
            $viewApplication[$value['proficiency_level']][] = $value;
        }

        $res['userViewApplication'] = [];

        foreach ($viewApplication as $level => $items) {
            $res['userViewApplication'][] = [
                'proficiency_level' => $level,
                'items' => $items
            ];
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
        // return $request;exit;
        $i = 0;
        if ($request->formType == 'details') {
            $insertArray = [
                "category" => $request->category,
                "sub_category" => $request->sub_category,
                "title" => $request->skill_name,
                "description" => $request->description,
                "sub_institute_id" => $request->sub_institute_id,
            ];
            $insertArray['department_id'] = $getIndustries->id ?? null;
            $insertArray['department'] = $getIndustries->department ?? null;
            $insertArray['sub_department'] = $getIndustries->sub_department ?? null;
            $insertArray['updated_by'] = $request->user_id;
            $insertArray['updated_at'] = now();
            $insertArray['status'] = 'Active';
            $insertArray['related_skills'] = json_encode($request->related_skills);
            $insertArray['bussiness_links'] = $request->bussiness_links;
            $insertArray['custom_tags'] =  json_encode($request->custom_tags);
            $insertArray['proficiency_level'] = $request->proficiency_level;
            $insertArray['job_titles'] = $request->job_titles;
            $insertArray['learning_resources'] = $request->learning_resources;
            $insertArray['assesment_method'] = $request->assesment_method;
            $insertArray['certification_qualifications'] = $request->certification_qualifications;
            $insertArray['experience_project'] = $request->experience_project;
            $insertArray['skill_maps'] = $request->skill_maps;

            $insert = userSkills::where('id', $id)->update($insertArray);


            $i++;
        }
        if ($request->formType == 'jobrole') {
            foreach ($request->job_role as $key => $value) {
                $checkExists = skillJobroleMap::where('jobrole', $value)->where('skill', $id)->where('sub_institute_id', $request->sub_institute_id)->whereNull('deleted_at')->first();
                if (!$checkExists) {
                    $insertArray = [
                        'skill' => $id,
                        'jobrole' => $value,
                        // 'description' => $request->description[$key] ?? null,
                        'sub_institute_id' => $request->sub_institute_id,
                        'created_by' => $this->g2gActorId($request),
                        'created_at' => now(),
                    ];
                    $check = skillJobroleMap::where([
                        'skill' => $id,
                        'jobrole' => $value,
                        'sub_institute_id' => $request->sub_institute_id
                    ])->first();
                    if (!$check) {
                        $insert = skillJobroleMap::insert($insertArray);
                    }
                    $i++;
                } elseif (isset($checkExists->id)) {
                    $insertArray = [
                        'skill' => $id,
                        'jobrole' => $value,
                        // 'description' => $request->description[$key] ?? null,
                        'sub_institute_id' => $request->sub_institute_id,
                        'updated_by' => $this->g2gActorId($request),
                        'updated_at' => now(),
                    ];
                    $insert = skillJobroleMap::where('id', $checkExists->id)->update($insertArray);
                    $i++;
                }

                //    $res['userJobroleData'] = skillJobroleMap::where('skill',$id)->where('sub_institute_id',$request->sub_institute_id)->whereNull('deleted_at')->get();
                $skillName = userSkills::where('id', $request->skill_id)
                    ->where('sub_institute_id', $request->sub_institute_id)
                    ->whereNull('deleted_at')
                    ->value('title');
                $res['userJobroleData'] = $this->getJobroleData($request, $skillName, 'usersJobrole');
            }
        }
        $skillName = userSkills::where('id', $request->skill_id)
            ->where('sub_institute_id', $request->sub_institute_id)
            ->whereNull('deleted_at')
            ->value('title');

        if ($request->formType == 'proficiency_level') {
            // foreach ($request->proficiency_level as $key => $value) {
            //     $checkExists = userProfeceincyLevel::where('proficiency_level', $value)->where('skill_id', $id)->where('sub_institute_id', $request->sub_institute_id)->whereNull('deleted_at')->first();
            //     if (!$checkExists) {
            //         $insertArray = [
            //             'skill_id' => $id,
            //             'proficiency_level' => $value,
            //             'description' => $request->description[$key] ?? null,
            //             'proficiency_type' => $request->proficiency_type[$key] ?? null,
            //             'type_description' => $request->type_description[$key] ?? null,
            //             'sub_institute_id' => $request->sub_institute_id,
            //             'created_by' => $this->g2gActorId($request),
            //             'created_at' => now(),
            //         ];
            //         $insert = userProfeceincyLevel::insert($insertArray);
            //         $i++;
            //     } elseif (isset($checkExists->id)) {
            //         $insertArray = [
            //             'skill' => $id,
            //             'proficiency_level' => $value,
            //             'description' => $request->description[$key] ?? null,
            //             'proficiency_type' => $request->proficiency_type[$key] ?? null,
            //             'type_description' => $request->type_description[$key] ?? null,
            //             'sub_institute_id' => $request->sub_institute_id,
            //             'updated_by' => $this->g2gActorId($request),
            //             'updated_at' => now(),
            //         ];
            //         $insert = userProfeceincyLevel::where('id', $checkExists->id)->update($insertArray);
            //         $i++;
            //     }

               
            // }
            foreach (json_decode($request->proficiency_level) as $key => $value) {
                $checkExists = userKnowledgeAbility::where('proficiency_level', $value->proficiency_level)->where('skill_id', $id)->where('sub_institute_id', $request->sub_institute_id)->whereNull('deleted_at')->first();
                if (!$checkExists) {
                    $insertArray = [
                        'skill_id' => $id,
                        'proficiency_level' => $value->proficiency_level,
                        'proficiency_description'=>$value->proficiency_description,
                        'sub_institute_id' => $request->sub_institute_id,
                        'created_by' => $this->g2gActorId($request),
                        'created_at' => now(),
                    ];
                    $insert = userKnowledgeAbility::insert($insertArray);
                    $i++;
                } elseif (isset($checkExists->id)) {
                    $insertArray = [
                        'skill_id' => $id,
                        'proficiency_level' => $value->proficiency_level,
                        'proficiency_description'=>$value->proficiency_description,
                        'sub_institute_id' => $request->sub_institute_id,
                        'updated_by' => $this->g2gActorId($request),
                        'updated_at' => now(),
                    ];
                    $insert = userKnowledgeAbility::where('id', $checkExists->id)->update($insertArray);
                    $i++;
                }
            }
             $res['userproficiency_levelData'] = $this->getProficiencyLevels($request, '', $request->skill_id);
                if (empty($res['userproficiency_levelData'])) {
                    $res['userproficiency_levelData'] = userProfeceincyLevel::whereNull('skill_id')
                        ->whereNull('sub_institute_id')
                        ->whereNull('deleted_at')
                        ->get();
                }
        }

        if ($request->formType == 'knowledge') {
            foreach (json_decode($request->knowledge_ability_data) as $key => $value) {
                $checkExists = userKnowledgeAbility::where('classification', 'knowledge')->where('classification_item', $value->classification_item)->where('skill_id', $id)->where('sub_institute_id', $request->sub_institute_id)->whereNull('deleted_at')->first();
                if (!$checkExists) {
                    $insertArray = [
                        'skill_id' => $id,
                        'proficiency_level' => $value->proficiency_level,
                        'classification_category'=>$value->classification_category,
                        'classification_sub_category'=>$value->classification_sub_category,
                        'classification_item' => $value->classification_item,
                        'classification' => 'knowledge',
                        'sub_institute_id' => $request->sub_institute_id,
                        'created_by' => $this->g2gActorId($request),
                        'created_at' => now(),
                    ];
                    $insert = userKnowledgeAbility::insert($insertArray);
                    $i++;
                } elseif (isset($checkExists->id)) {
                    $insertArray = [
                        'skill_id' => $id,
                        'proficiency_level' => $value->proficiency_level,
                        'classification_category'=>$value->classification_category,
                        'classification_sub_category'=>$value->classification_sub_category,
                        'classification_item' => $value->classification_item,
                        'classification' => 'knowledge',
                        'sub_institute_id' => $request->sub_institute_id,
                        'updated_by' => $this->g2gActorId($request),
                        'updated_at' => now(),
                    ];
                    $insert = userKnowledgeAbility::where('id', $checkExists->id)->update($insertArray);
                    $i++;
                }
            }
            $res['userKnowledgeData'] = $this->getKnowledgeAbilityData($request, $id, 'knowledge');
        }

        if ($request->formType == 'ability') {
            foreach (json_decode($request->ability_data) as $key => $value) {
                $checkExists = userKnowledgeAbility::where('classification', 'ability')->where('classification_item', $value->classification_item)->where('skill_id', $id)->where('sub_institute_id', $request->sub_institute_id)->whereNull('deleted_at')->first();
                if (!$checkExists) {
                    $insertArray = [
                        'skill_id' => $id,
                        'proficiency_level' => $value->proficiency_level,
                        'classification_item' => $value->classification_item,
                        'classification_category'=>$value->classification_category,
                        'classification_sub_category'=>$value->classification_sub_category,
                        'classification' => 'ability',
                        'sub_institute_id' => $request->sub_institute_id,
                        'created_by' => $this->g2gActorId($request),
                        'created_at' => now(),
                    ];
                    $insert = userKnowledgeAbility::insert($insertArray);
                    $i++;
                } elseif (isset($checkExists->id)) {
                    $insertArray = [
                        'skill' => $request->skill_name,
                        'proficiency_level' => $value->proficiency_level,
                        'classification_category'=>$value->classification_category,
                        'classification_sub_category'=>$value->classification_sub_category,
                        'classification_item' => $value->classification_item,
                        'classification' => 'ability',
                        'sub_institute_id' => $request->sub_institute_id,
                        'updated_by' => $this->g2gActorId($request),
                        'updated_at' => now(),
                    ];
                    $insert = userKnowledgeAbility::where('id', $checkExists->id)->update($insertArray);
                    $i++;
                }
            }
            $res['userabilityData'] = $this->getKnowledgeAbilityData($request, $id, 'ability');
        }

        if ($request->formType == 'attitude') {
            foreach (json_decode($request->ability_data) as $key => $value) {
                $checkExists = userKnowledgeAbility::where('classification', 'attitude')->where('classification_item', $value->classification_item)->where('skill_id', $id)->where('sub_institute_id', $request->sub_institute_id)->whereNull('deleted_at')->first();
                if (!$checkExists) {
                    $insertArray = [
                        'skill_id' => $id,
                        'proficiency_level' => $value->proficiency_level,
                        'classification_item' => $value->classification_item,
                        'classification_category'=>$value->classification_category,
                        'classification_sub_category'=>$value->classification_sub_category,
                        'classification' => 'attitude',
                        'sub_institute_id' => $request->sub_institute_id,
                        'created_by' => $this->g2gActorId($request),
                        'created_at' => now(),
                    ];
                    $insert = userKnowledgeAbility::insert($insertArray);
                    $i++;
                } elseif (isset($checkExists->id)) {
                    $insertArray = [
                        'skill' => $request->skill_name,
                        'proficiency_level' => $value->proficiency_level,
                        'classification_category'=>$value->classification_category,
                        'classification_sub_category'=>$value->classification_sub_category,
                        'classification_item' => $value->classification_item,
                        'classification' => 'attitude',
                        'sub_institute_id' => $request->sub_institute_id,
                        'updated_by' => $this->g2gActorId($request),
                        'updated_at' => now(),
                    ];
                    $insert = userKnowledgeAbility::where('id', $checkExists->id)->update($insertArray);
                    $i++;
                }
            }
            $res['userAttitudeData'] = $this->getKnowledgeAbilityData($request, $id, 'attitude');
        }

        if ($request->formType == 'behaviour') {
            foreach (json_decode($request->ability_data) as $key => $value) {
                $checkExists = userKnowledgeAbility::where('classification', 'behaviour')->where('classification_item', $value->classification_item)->where('skill_id', $id)->where('sub_institute_id', $request->sub_institute_id)->whereNull('deleted_at')->first();
                if (!$checkExists) {
                    $insertArray = [
                        'skill_id' => $id,
                        'proficiency_level' => $value->proficiency_level,
                        'classification_item' => $value->classification_item,
                        'classification_category'=>$value->classification_category,
                        'classification_sub_category'=>$value->classification_sub_category,
                        'classification' => 'behaviour',
                        'sub_institute_id' => $request->sub_institute_id,
                        'created_by' => $this->g2gActorId($request),
                        'created_at' => now(),
                    ];
                    $insert = userKnowledgeAbility::insert($insertArray);
                    $i++;
                } elseif (isset($checkExists->id)) {
                    $insertArray = [
                        'skill' => $request->skill_name,
                        'proficiency_level' => $value->proficiency_level,
                        'classification_category'=>$value->classification_category,
                        'classification_sub_category'=>$value->classification_sub_category,
                        'classification_item' => $value->classification_item,
                        'classification' => 'behaviour',
                        'sub_institute_id' => $request->sub_institute_id,
                        'updated_by' => $this->g2gActorId($request),
                        'updated_at' => now(),
                    ];
                    $insert = userKnowledgeAbility::where('id', $checkExists->id)->update($insertArray);
                    $i++;
                }
            }
            $res['userBehaviourData'] = $this->getKnowledgeAbilityData($request, $id, 'behaviour');
        }

        if ($request->has('formType') && $request->formType == "application") {
            foreach (json_decode($request->aplication_data) as $key => $value) {
                $checkExists = userApplication::where('application', $value->application)->where('skill_id', $id)->where('sub_institute_id', $request->sub_institute_id)->whereNull('deleted_at')->first();
                if (!$checkExists) {
                    $insertArray = [
                        'skill_id' => $id,
                        'proficiency_level' => $value->proficiency_level,
                        'application' => $value->application,
                        'sub_institute_id' => $request->sub_institute_id,
                        'created_by' => $this->g2gActorId($request),
                        'created_at' => now(),
                    ];
                    $insert = userApplication::insert($insertArray);
                    $i++;
                } elseif (isset($checkExists->id)) {
                    $insertArray = [
                        'skill' => $skillName,
                        'proficiency_level' => $value->proficiency_level,
                        'application' => $value->application,
                        'sub_institute_id' => $request->sub_institute_id,
                        'updated_by' => $this->g2gActorId($request),
                        'updated_at' => now(),
                    ];
                    $insert = userApplication::where('id', $checkExists->id)->update($insertArray);
                    $i++;
                }
            }

            $res['userApplicationData'] = $this->getApplicationData($request, $skillName);
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

            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'org_type' => 'required',
                'sub_institute_id' => 'required',
                'user_id' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
        }
        $i = 0;
        if ($request->formType == "jobrole") {
            $delete = skillJobroleMap::where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $this->g2gActorId($request)]);
            if ($delete) {
                $i++;
            }
        }
        if ($request->formType == "proficiency_level") {
            $delete = userProfeceincyLevel::where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $this->g2gActorId($request)]);
            if ($delete) {
                $i++;
            }
        }
        if ($request->has('formType') && $request->formType == "jobrole") {

            $delete = skillJobroleMap::where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $this->g2gActorId($request)]);
            if ($delete) {
                $i++;
            }
        }

        if ($request->has('formType') && $request->formType == "knowledge") {
            $delete = userKnowledgeAbility::where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $this->g2gActorId($request)]);
            if ($delete) {
                $i++;
            }
        }
        if ($request->has('formType') && $request->formType == "ability") {
            $delete = userKnowledgeAbility::where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $this->g2gActorId($request)]);
            if ($delete) {
                $i++;
            }
        }
        // userApplication
        if ($request->has('formType') && $request->formType == "application") {
            $delete = userApplication::where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $this->g2gActorId($request)]);
            if ($delete) {
                $i++;
            }
        }

        if ($request->has('formType') && $request->formType == "user") {
            $delete = userSkills::where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $this->g2gActorId($request)]);
            if ($delete) {
                $i++;
            }
        } elseif ($request->has('formType') && $request->formType == "skills") {
            $delete = skillLibraryModel::where('id', $id)->delete();
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

    public function getProficiencyLevels(Request $request, $getType = '', $skillId = null)
    {
     $data = [];
        $skillFields = ['id', 'category', 'sub_category', 'title'];
        $createdUser = ['id', 'first_name', 'middle_name', 'last_name'];
        $jobroleFields = ['id', 'jobrole', 'description'];
        $data =    userKnowledgeAbility::with([
            'userSkills' => fn($q) => $q->select($skillFields),
            'createdUser' => fn($q) => $q->select($createdUser),
        ])
            ->where('skill_id', $skillId)
            // ->where('classification', $getType)
            ->where('sub_institute_id', $request->sub_institute_id)
            ->whereNull('deleted_at')
            ->orderBy('proficiency_level', 'ASC')
            ->groupBy('proficiency_level')
            ->get()
            ->map(function ($item) {
                $data = $item->toArray();

                if ($item->userSkills) {
                    $data['category'] = $item->userSkills->category;
                    $data['sub_category'] = $item->userSkills->sub_category;
                    $data['skillTitle'] = $item->userSkills->title;
                }

                if ($item->createdUser) {
                    $data['first_name'] = $item->createdUser->first_name;
                    $data['middle_name'] = $item->createdUser->middle_name;
                    $data['last_name'] = $item->createdUser->last_name;
                }

                unset($data['user_skills'], $data['created_user']);

                return $data;
            });
        return $data;
    }

    public function getJobroleData($request, $skillName, $getType = '')
    {
        // return $request->all();exit;
        $jobroles = [];
        $skillFields = ['id', 'category', 'sub_category', 'title'];
        $createdUser = ['id', 'first_name', 'middle_name', 'last_name'];
        $jobroleFields = ['id', 'jobrole', 'description','jobrole_category'];

        if ($getType == "usersJobrole") {
            $jobroles = skillJobroleMap::with([
                'userSkills' => fn($q) => $q->select($skillFields),
                'createdUser' => fn($q) => $q->select($createdUser),
                'userJobrole' => fn($q) => $q->select($jobroleFields),
            ])
                ->where('skill', $skillName)
                ->where('sub_institute_id', $request->sub_institute_id)
                ->whereNull('deleted_at')
                ->get()
                ->map(function ($item) {
                    $data = $item->toArray();

                    if ($item->userSkills) {
                        $data['category'] = $item->userSkills->category;
                        $data['sub_category'] = $item->userSkills->sub_category;
                        $data['skillTitle'] = $item->userSkills->title;
                    }

                    if ($item->createdUser) {
                        $data['first_name'] = $item->createdUser->first_name;
                        $data['middle_name'] = $item->createdUser->middle_name;
                        $data['last_name'] = $item->createdUser->last_name;
                    }
                    if ($item->userJobrole) {
                        $data['description'] = $item->userJobrole->description;
                    }
                    if ($item->userJobrole) {
                        $data['jobrole_category'] = $item->userJobrole->jobrole_category;
                    }
                    unset($data['user_skills'], $data['created_user'], $data['userJobrole']);

                    return $data;
                });
        }

        return $jobroles;
    }
    public function getKnowledgeAbilityData($request, $skillId, $getType = '')
    {
        $data = [];
        $skillFields = ['id', 'category', 'sub_category', 'title'];
        $createdUser = ['id', 'first_name', 'middle_name', 'last_name'];
        $jobroleFields = ['id', 'jobrole', 'description'];
        $data =    userKnowledgeAbility::with([
            'userSkills' => fn($q) => $q->select($skillFields),
            'createdUser' => fn($q) => $q->select($createdUser),
        ])
            ->where('skill_id', $skillId)
            ->where('classification', $getType)
            ->where('sub_institute_id', $request->sub_institute_id)
            ->whereNull('deleted_at')
            ->orderBy('proficiency_level', 'ASC')
            ->get()
            ->map(function ($item) {
                $data = $item->toArray();

                if ($item->userSkills) {
                    $data['category'] = $item->userSkills->category;
                    $data['sub_category'] = $item->userSkills->sub_category;
                    $data['skillTitle'] = $item->userSkills->title;
                }

                if ($item->createdUser) {
                    $data['first_name'] = $item->createdUser->first_name;
                    $data['middle_name'] = $item->createdUser->middle_name;
                    $data['last_name'] = $item->createdUser->last_name;
                }

                unset($data['user_skills'], $data['created_user']);

                return $data;
            });
        return $data;
    }

    public function getApplicationData($request, $skillName, $getType = '')
    {
        // return $request->all();
        $data = [];
        $skillFields = ['id', 'category', 'sub_category', 'title'];
        $createdUser = ['id', 'first_name', 'middle_name', 'last_name'];
        $jobroleFields = ['id', 'jobrole', 'description'];

        $data = userApplication::with([
            'userSkills' => fn($q) => $q->select($skillFields),
            'createdUser' => fn($q) => $q->select($createdUser),
        ])
            ->where('skill', $skillName)
            ->where('sub_institute_id', $request->sub_institute_id)
            ->whereNull('deleted_at')
            ->orderBy('proficiency_level', 'ASC')
            ->get()
            ->map(function ($item) {
                $data = $item->toArray();

                if ($item->userSkills) {
                    $data['category'] = $item->userSkills->category;
                    $data['sub_category'] = $item->userSkills->sub_category;
                    $data['skillTitle'] = $item->userSkills->title;
                }

                if ($item->createdUser) {
                    $data['first_name'] = $item->createdUser->first_name;
                    $data['middle_name'] = $item->createdUser->middle_name;
                    $data['last_name'] = $item->createdUser->last_name;
                }

                unset($data['user_skills'], $data['created_user']);

                return $data;
            });

        return $data;
    }

    public function AddCategory(Request $request)
    {
        $token = $request->input('token');

        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $type = $request->input('type');

        $validator = Validator::make($request->all(), [
            'sub_institute_id' => 'required|numeric',
            'user_id'          => 'required|numeric',
            'formType'         => 'required',
            'variableType'     => 'required|in:Knowledge,Ability,Behaviour,Attitude'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->messages()->first()
            ]);
        }

        $i=0;
        $sub_institute_id = $request->sub_institute_id;
        $user_id = $request->user_id;
        $formType = $request->formType;

        if($formType=="add category"){
            $category = $request->category;
            $checkCategory = DB::table('s_users_skills')->where(['sub_institute_id'=>$sub_institute_id,'category'=>$request->category])->whereNull('deleted_at')->first();

            if(empty($checkCategory) && !isset($checkCategory->id)){
                $i = DB::table('s_users_skills')->insert([
                    'sub_institute_id'=>$sub_institute_id,
                    'category'=>$request->category,
                    'created_by'=>$user_id,
                    'created_at'=>now(),
                ]);
            }
        }
        else if($formType=="edit category"){
            $category = $request->category;
            $old_category = $request->old_category;
            $sub_category = $request->sub_category;

            $checkCategory = DB::table('s_users_skills')->where(['sub_institute_id'=>$sub_institute_id,'category'=>$request->old_category])->whereNull('deleted_at')->first();

            if(!empty($checkCategory) && isset($checkCategory->id) && $sub_category==''){
                
                $updateArray = [
                    'sub_institute_id'=>$sub_institute_id,
                    'category'=>$request->category,
                    'updated_at'=>now(),
                    'updated_by'=>$user_id
                ];

                $i = DB::table('s_users_skills')->where(['sub_institute_id'=>$sub_institute_id,'category'=>$request->old_category])->update($updateArray);

                $checkSubCategory = DB::table('s_users_skills')->where(['sub_institute_id'=>$sub_institute_id,'category'=>$request->category,'sub_category'=>$sub_category])->whereNull('deleted_at')->first();

                if(empty($checkCategory) && !isset($checkCategory->id)){
                    $updateArray['sub_category'] = $sub_category;
                    $updateArray['created_at'] = now();
                    $updateArray['created_by'] = $user_id;

                     $i = DB::table('s_users_skills')->insert($updateArray);
                }
            }
            else if($request->has('sub_category')){
                $updateArray = [
                    'sub_institute_id'=>$sub_institute_id,
                    'category'=>$request->category,
                    'updated_at'=>now(),
                    'updated_by'=>$user_id
                ];

                $i = DB::table('s_users_skills')->where(['sub_institute_id'=>$sub_institute_id,'category'=>$request->old_category])->update($updateArray);

                $checkSubCategory = DB::table('s_users_skills')->where(['sub_institute_id'=>$sub_institute_id,'category'=>$request->category,'sub_category'=>$sub_category])->whereNull('deleted_at')->first();

                if(empty($checkSubCategory) && !isset($checkSubCategory->id)){
                    $updateArray['sub_category'] = $sub_category;
                    $updateArray['created_at'] = now();
                    $updateArray['created_by'] = $user_id;

                     $i = DB::table('s_users_skills')->insert($updateArray);
                }
            }
        }
        else if ($formType == "edit sub_category") {
            $category = $request->category;
            $old_sub_category = $request->old_sub_category;
            $sub_category = $request->sub_category;

            $checkSubCategory = DB::table('s_users_skills')
                ->where([
                    'sub_institute_id' => $sub_institute_id,
                    'category'         => $category,
                    'sub_category'     => $old_sub_category
                ])
                ->whereNull('deleted_at')
                ->first();

            if (!empty($checkSubCategory) && isset($checkSubCategory->id)) {
                $updateArray['sub_category'] = $sub_category;
                $updateArray['updated_at'] = now();
                $updateArray['updated_by'] = $user_id;

                $i = DB::table('s_users_skills')
                    ->where([
                        'sub_institute_id' => $sub_institute_id,
                        'category'         => $category,
                        'sub_category'     => $old_sub_category
                    ])
                    ->update($updateArray);
            }
        }


        if($i!=0){
            $res['status_code']=1;
            $res['message']="Data Add Successfully!";
        }else{
            $res['status_code']=0;
            $res['message']="Failed to Add, May be already Exists!";
        }

        return response()->json($res);
    }

    public function AddAttributeTaxonomy(Request $request)
    {
        $token = $request->input('token');

        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $type = $request->input('type');

        $validator = Validator::make($request->all(), [
            'sub_institute_id' => 'required|numeric',
            'user_id'          => 'required|numeric',
            'formType'         => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->messages()->first()
            ]);
        }

        $i = 0;
        $sub_institute_id = $request->sub_institute_id;
        $user_id = $request->user_id;
        $formType = $request->formType;
        $attribute = $request->attribute;
        $variableType = $request->input('variableType');

        $tables = [
            'Knowledge' => 's_user_knowledge',
            'Ability' => 's_user_ability',
            'Behaviour' => 's_user_behaviour',
            'Attitude' => 's_user_attitude',
        ];

        $table = $tables[$variableType];

        if ($formType == "add category") {
            $checkCategory = DB::table($table)
                ->where([
                    'sub_institute_id' => $sub_institute_id,
                    'category' => $request->classification_category,
                ])
                ->whereNull('deleted_at')
                ->first();

            if (empty($checkCategory) && !isset($checkCategory->id)) {
                $i = DB::table($table)->insert([
                    'sub_institute_id' => $sub_institute_id,
                    'category' => $request->classification_category,
                    'created_by' => $user_id,
                    'created_at' => now(),
                ]);
            }
        }
        else if ($formType == "edit category") {
            $classification_category = $request->classification_category;
            $old_classification_category = $request->old_classification_category;
            $classification_sub_category = $request->classification_sub_category;

            $checkCategory = DB::table($table)
                ->where([
                    'sub_institute_id' => $sub_institute_id,
                    'category' => $request->old_classification_category,
                ])
                ->whereNull('deleted_at')
                ->first();

            if (!empty($checkCategory) && isset($checkCategory->id) && $classification_sub_category == '') {
                $updateArray = [
                    'category' => $request->classification_category,
                    'updated_at' => now(),
                    'updated_by' => $user_id
                ];

                $i = DB::table($table)
                    ->where([
                        'sub_institute_id' => $sub_institute_id,
                        'category' => $request->old_classification_category,
                    ])
                    ->update($updateArray);

                $checkSubCategory = DB::table($table)
                    ->where([
                        'sub_institute_id' => $sub_institute_id,
                        'category' => $request->classification_category,
                        'sub_category' => $classification_sub_category,
                    ])
                    ->whereNull('deleted_at')
                    ->first();

                if (empty($checkSubCategory) && !isset($checkSubCategory->id)) {
                    $insertArray = [
                        'sub_institute_id' => $sub_institute_id,
                        'category' => $request->classification_category,
                        'sub_category' => $classification_sub_category,
                        'created_at' => now(),
                        'created_by' => $user_id,
                    ];

                    $i = DB::table($table)->insert($insertArray);
                }
            }
            else if ($request->has('classification_sub_category')) {
                $updateArray = [
                    'category' => $request->classification_category,
                    'updated_at' => now(),
                    'updated_by' => $user_id
                ];

                $i = DB::table($table)
                    ->where([
                        'sub_institute_id' => $sub_institute_id,
                        'category' => $request->old_classification_category,
                    ])
                    ->update($updateArray);

                $checkSubCategory = DB::table($table)
                    ->where([
                        'sub_institute_id' => $sub_institute_id,
                        'category' => $request->classification_category,
                        'sub_category' => $classification_sub_category,
                    ])
                    ->whereNull('deleted_at')
                    ->first();

                if (empty($checkSubCategory) && !isset($checkSubCategory->id)) {
                    $insertArray = [
                        'sub_institute_id' => $sub_institute_id,
                        'category' => $request->classification_category,
                        'sub_category' => $classification_sub_category,
                        'created_at' => now(),
                        'created_by' => $user_id,
                    ];

                    $i = DB::table($table)->insert($insertArray);
                }
            }
        }
        else if ($formType == "edit sub_category") {
            $classification_category = $request->classification_category;
            $old_classification_sub_category = $request->old_classification_sub_category;
            $classification_sub_category = $request->classification_sub_category;

            $checkSubCategory = DB::table($table)
                ->where([
                    'sub_institute_id' => $sub_institute_id,
                    'category' => $classification_category,
                    'sub_category' => $old_classification_sub_category,
                ])
                ->whereNull('deleted_at')
                ->first();

            if (!empty($checkSubCategory) && isset($checkSubCategory->id)) {
                $updateArray = [
                    'sub_category' => $classification_sub_category,
                    'updated_at' => now(),
                    'updated_by' => $user_id
                ];

                $i = DB::table($table)
                    ->where([
                        'sub_institute_id' => $sub_institute_id,
                        'category' => $classification_category,
                        'sub_category' => $old_classification_sub_category,
                    ])
                    ->update($updateArray);
            }
        }

        if ($i != 0) {
            $res['status_code'] = 1;
            $res['message'] = "Data Added Successfully!";
        } else {
            $res['status_code'] = 0;
            $res['message'] = "Failed to Add, May Already Exist!";
        }

        return response()->json($res);
    }

    public function delete(Request $request)
    {
        $type = $request->type;
        if ($type == 'API') {
            $token = $request->input('token');

            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            $accessToken = PersonalAccessToken::findToken($token);

            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }

            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'org_type' => 'required',
                'sub_institute_id' => 'required',
                'user_id' => 'required',
                'formType' => 'required',
                'ids' => 'required|array',
            ]);

            if ($validator->fails()) {
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
        }
        $i = 0;
        $ids = json_decode($request->ids, true);
        foreach ($ids as $id) {
            if ($request->has('formType') && $request->formType == "user") {
                $delete = userSkills::where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $this->g2gActorId($request)]);
                if ($delete) {
                    $i++;
                }
            }
        }

        if ($i > 0) {
            $res['status_code'] = 1;
            $res['message'] = 'Deleted data successfully !';
        } else {
            $res['status_code'] = 0;
            $res['message'] = 'Failed to delete data';
        }
        return is_mobile($type, 'skill_library.index', $res, 'redirect');
    }

    /* ================================================================
     | Competency Library JSON API (additive)
     |
     | Backs the new Next.js "Competency Management -> Competency Library"
     | screen. A competency IS an approved skill in this ERP, so these
     | actions read/write the same s_users_skills catalog this controller
     | already manages, but expose a clean, paginated JSON contract
     | (no Blade view, no formType branching) that the new frontend's
     | apiClient/withLaravelParams pattern consumes. They are wired to
     | dedicated /api/skill_library/competency* routes registered BEFORE
     | the resource route, so the existing resource endpoints and their
     | consumers (old frontend, chatbot) are left completely untouched.
     ================================================================ */

    /**
     * Token + tenant resolution shared by the competency-library actions.
     * Same Sanctum personal-access-token check the rest of this controller
     * uses, but always returns JSON (never a Blade redirect).
     *
     * @return array{sub_institute_id:int, user_id:?int}|\Illuminate\Http\JsonResponse
     */
    private function competencyLibraryContext(Request $request)
    {
        // G-SEC-09. This method used to validate that a token EXISTED and then
        // discard its owner, taking sub_institute_id and user_id from the
        // request body with only an is_numeric() check. Any valid token from
        // any tenant could therefore read and write any other tenant's
        // competency library by changing one number - confirmed by execution,
        // not inference (C23 guard: /api/skill_library/competency-list and
        // /competency-export both returned a different tenant's data).
        //
        // ResolvesApiIdentity resolves the caller from $accessToken->tokenable
        // and derives the tenant from that user, ignoring whatever the request
        // claims. Its return is a superset of the shape this method used to
        // produce - ['user', 'user_id', 'sub_institute_id'] - so all eleven
        // call sites, which check `is_array($context)` and read those two keys,
        // are unaffected.
        return $this->resolveApiIdentity($request);
    }

    /**
     * Append a row to the competency activity feed (s_competency_activity_log).
     *
     * This controller is not part of Api\Competency so it cannot use the shared
     * ResolvesCompetencyContext trait, but the Competency Library screen it
     * serves is a competency module screen: without this, creating, editing or
     * deleting a competency here left no trace in the Audit & Activity Center
     * while the same operations through Api\Competency\CompetencyController did.
     * Same columns, same shape as the trait writes.
     *
     * @param array<int, array{field:string, label:string, old:mixed, new:mixed}>|null $changes
     */
    private function logCompetencyLibraryActivity(
        int $subInstituteId,
        ?int $userId,
        string $action,
        string $description,
        ?int $subjectId,
        ?string $subjectName,
        ?array $changes = null
    ): void {
        $actorName = null;

        if ($userId) {
            $user = DB::table('tbluser')->where('id', $userId)->first();
            if ($user) {
                $actorName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
                $actorName = $actorName !== '' ? $actorName : ($user->user_name ?? null);
            }
        }

        DB::table('s_competency_activity_log')->insert([
            'sub_institute_id' => $subInstituteId,
            'user_id'          => $userId,
            'actor_name'       => $actorName,
            'action'           => $action,
            'description'      => $description,
            'subject_type'     => 'competency',
            'subject_id'       => $subjectId,
            'subject_name'     => $subjectName !== null ? mb_substr($subjectName, 0, 191) : null,
            'changes'          => ($changes !== null && $changes !== []) ? json_encode(array_values($changes)) : null,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    /**
     * Field-level diff for the Audit Center's Change Summary: only the columns
     * in $labels that are present in $after and actually differ.
     *
     * @param  object|array<string, mixed> $before
     * @param  array<string, mixed>        $after
     * @param  array<string, string>       $labels
     * @return array<int, array{field:string, label:string, old:mixed, new:mixed}>
     */
    private function competencyLibraryDiff($before, array $after, array $labels): array
    {
        $before = is_object($before) ? (array) $before : $before;
        $changes = [];

        foreach ($after as $column => $newValue) {
            if (!array_key_exists($column, $labels)) {
                continue;
            }

            $oldValue = $before[$column] ?? null;

            if ((string) ($oldValue ?? '') === (string) ($newValue ?? '')) {
                continue;
            }

            $changes[] = [
                'field' => $column,
                'label' => $labels[$column],
                'old'   => $oldValue,
                'new'   => $newValue,
            ];
        }

        return $changes;
    }

    /**
     * The shared filter chain behind the competency list and its export, so the
     * "Export Library" button always exports exactly what the table is showing.
     * Aliased `s` because callers join tbluser as `u` for the owner name.
     */
    private function competencyLibraryQuery(Request $request, int $sid)
    {
        $query = DB::table('s_users_skills as s')
            ->where('s.sub_institute_id', $sid)
            ->whereNull('s.deleted_at')
            // Rows carrying only a category are taxonomy placeholders written by
            // Libraries & Taxonomy so an empty branch can exist before its first
            // entry. They are not competencies and must not list as nameless ones.
            ->whereNotNull('s.title')
            ->where('s.title', '!=', '');

        if ($search = $this->competencyLibraryFilter($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('s.title', 'like', "%{$search}%")
                    ->orWhere('s.description', 'like', "%{$search}%")
                    ->orWhere('s.category', 'like', "%{$search}%");
            });
        }
        if ($category = $this->competencyLibraryFilter($request->input('category'))) {
            $query->where('s.category', $category);
        }
        if ($type = $this->competencyLibraryFilter($request->input('competency_type'))) {
            $query->where('s.competency_type', $type);
        }
        if ($status = $this->competencyLibraryFilter($request->input('status'))) {
            $query->where('s.approve_status', $status);
        }

        return $query;
    }

    /**
     * Row shape shared by the competency list and export. A method rather than
     * a const because the owner name is a DB::raw expression.
     *
     * @return array<int, mixed>
     */
    private function competencyLibraryColumns(): array
    {
        return [
            's.id',
            's.title as name',
            's.description',
            's.category',
            's.sub_category',
            's.competency_type',
            's.proficiency_level',
            's.department',
            's.department_id',
            's.status',
            's.approve_status',
            's.created_at',
            's.updated_at',
            's.created_by',
            DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as owner"),
        ];
    }

    /** Column labels the Change Summary shows for a competency edit. */
    private const COMPETENCY_CHANGE_LABELS = [
        'title'             => 'Competency Name',
        'description'       => 'Description',
        'category'          => 'Category',
        'sub_category'      => 'Sub Category',
        'competency_type'   => 'Competency Type',
        'proficiency_level' => 'Proficiency Level',
        'department'        => 'Department',
        'department_id'     => 'Department',
        'approve_status'    => 'Status',
        'bussiness_links'   => 'Business Link',
        'learning_resources' => 'Learning Resources',
        'assesment_method'  => 'Assessment Method',
        'certification_qualifications' => 'Certifications / Qualifications',
        'experience_project' => 'Experience / Projects',
        'sop_practice_link' => 'SOP / Practice Link',
        'related_skills'    => 'Related Skills',
        'custom_tags'       => 'Tags',
    ];

    /**
     * The detail columns the Competency Library form owns beyond the core six.
     *
     * These used to be editable only from the separate skill library screen,
     * which left the drawer's Attachments tab permanently empty for anything
     * created here - it is built from learning_resources, certification_
     * qualifications, sop_practice_link, experience_project and assesment_method.
     */
    private const COMPETENCY_DETAIL_FIELDS = [
        'department',
        'bussiness_links',
        'learning_resources',
        'assesment_method',
        'certification_qualifications',
        'experience_project',
        'sop_practice_link',
        'related_skills',
        'custom_tags',
    ];

    /** Validation rules shared by competency create and update. */
    private function competencyLibraryRules(): array
    {
        return [
            'name'              => 'required|string|max:191',
            'description'       => 'nullable|string',
            'category'          => 'nullable|string|max:191',
            'sub_category'      => 'nullable|string|max:191',
            'competency_type'   => 'nullable|string|max:50',
            'proficiency_level' => 'nullable|string|max:191',
            'department'        => 'nullable|string|max:191',
            'department_id'     => 'nullable|integer',
            'status'            => 'nullable|in:Approved,Pending,Cancelled',
            'bussiness_links'              => 'nullable|string',
            'learning_resources'           => 'nullable|string',
            'assesment_method'             => 'nullable|string',
            'certification_qualifications' => 'nullable|string',
            'experience_project'           => 'nullable|string',
            'sop_practice_link'            => 'nullable|string',
            'related_skills'               => 'nullable|string',
            'custom_tags'                  => 'nullable|string',
        ];
    }

    /**
     * Pull the detail columns the caller actually sent.
     *
     * Only present keys are returned so a partial edit cannot blank a column the
     * form did not show.
     *
     * @return array<string, mixed>
     */
    private function competencyLibraryDetailPayload(Request $request): array
    {
        $data = [];

        foreach (self::COMPETENCY_DETAIL_FIELDS as $field) {
            if (!$request->has($field)) {
                continue;
            }
            $value = $request->input($field);
            if (is_array($value)) {
                $value = implode(',', array_filter(array_map('strval', $value), fn ($item) => trim($item) !== ''));
            }
            $value = is_string($value) ? trim($value) : $value;
            $data[$field] = ($value === '' || $value === null) ? null : $value;
        }

        return $data;
    }

    /** Treat '', '0' and 'all' (any case) as "no filter". */
    private function competencyLibraryFilter($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return ($value === '' || $value === '0' || strtolower($value) === 'all') ? null : $value;
    }

    /**
     * Paginated competency list for the Competency Library table.
     * Filters: search (title/description/category), category, competency_type,
     * status (approve_status). Sortable + paginated. Joins tbluser for owner.
     */
    public function competencyLibraryIndex(Request $request)
    {
        $context = $this->competencyLibraryContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $perPage = min(max((int) $request->input('per_page', 10), 1), 200);
        $page = max((int) $request->input('page', 1), 1);

        $allowedSorts = ['title', 'category', 'competency_type', 'approve_status', 'updated_at', 'created_at'];
        $sort = in_array($request->input('sort'), $allowedSorts, true) ? $request->input('sort') : 'id';
        $direction = strtolower((string) $request->input('direction')) === 'asc' ? 'asc' : 'desc';

        $query = $this->competencyLibraryQuery($request, $context['sub_institute_id']);

        $total = (clone $query)->count();

        $rows = $query
            ->leftJoin('tbluser as u', 'u.id', '=', 's.created_by')
            ->orderBy('s.' . $sort, $direction)
            ->forPage($page, $perPage)
            ->get($this->competencyLibraryColumns());

        return response()->json([
            'status'     => 1,
            'message'    => 'Competencies fetched successfully',
            'data'       => $rows,
            'pagination' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => (int) max(ceil($total / max($perPage, 1)), 1),
            ],
        ]);
    }

    /**
     * GET /skill_library/competency-export
     *
     * Every row matching the current filters, uncapped by the table's page size,
     * for the "Export Library" action. The CSV itself is assembled client side so
     * no new dependency is needed. Hard limit keeps a runaway export bounded.
     */
    public function competencyLibraryExport(Request $request)
    {
        $context = $this->competencyLibraryContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $rows = $this->competencyLibraryQuery($request, $context['sub_institute_id'])
            ->leftJoin('tbluser as u', 'u.id', '=', 's.created_by')
            ->orderBy('s.title')
            ->limit(5000)
            ->get($this->competencyLibraryColumns());

        $this->logCompetencyLibraryActivity(
            $context['sub_institute_id'],
            $context['user_id'],
            'exported_competencies',
            'Exported ' . $rows->count() . ' ' . ($rows->count() === 1 ? 'competency' : 'competencies') . ' from the library',
            null,
            'Competency Library'
        );

        return response()->json([
            'status'  => 1,
            'message' => 'Competencies exported successfully',
            'data'    => $rows,
        ]);
    }

    /**
     * POST /skill_library/competency-import
     *
     * Bulk-create competencies from a parsed spreadsheet ("Import Competencies").
     * The file is parsed in the browser and posted as a plain rows array, so this
     * needs no spreadsheet library on the server.
     *
     * A row whose title already exists for the tenant is SKIPPED rather than
     * updated or duplicated - an import must never silently overwrite a curated
     * competency. Per-row problems are reported back with their row number
     * instead of failing the whole batch.
     */
    public function competencyLibraryImport(Request $request)
    {
        $context = $this->competencyLibraryContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        // Structural validation only. A missing/blank name is a PER-ROW problem
        // reported back in `details` - making it `required` here would fail the
        // whole batch on one bad line, which is exactly what an import must not do.
        $validator = Validator::make($request->all(), [
            'rows'                     => 'required|array|min:1|max:2000',
            'rows.*.name'              => 'nullable|string|max:191',
            'rows.*.description'       => 'nullable|string',
            'rows.*.category'          => 'nullable|string|max:191',
            'rows.*.sub_category'      => 'nullable|string|max:191',
            'rows.*.competency_type'   => 'nullable|string|max:50',
            'rows.*.proficiency_level' => 'nullable|string|max:191',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        // One lookup for the whole batch rather than a query per row.
        $existing = DB::table('s_users_skills')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->pluck('title')
            ->map(fn ($title) => mb_strtolower(trim((string) $title)))
            ->flip();

        $insert = [];
        $skipped = [];
        $seen = [];
        $now = now();

        foreach ($request->input('rows') as $index => $row) {
            $line = $index + 1;
            $name = trim((string) ($row['name'] ?? ''));
            $key = mb_strtolower($name);

            if ($name === '') {
                $skipped[] = ['row' => $line, 'name' => '', 'reason' => 'Missing competency name'];
                continue;
            }
            if (isset($existing[$key])) {
                $skipped[] = ['row' => $line, 'name' => $name, 'reason' => 'Already in the library'];
                continue;
            }
            if (isset($seen[$key])) {
                $skipped[] = ['row' => $line, 'name' => $name, 'reason' => 'Duplicated within the file'];
                continue;
            }
            $seen[$key] = true;

            $insert[] = [
                'sub_institute_id'  => $sid,
                'title'             => $name,
                'description'       => $this->competencyLibraryFilter($row['description'] ?? null),
                'category'          => $this->competencyLibraryFilter($row['category'] ?? null),
                'sub_category'      => $this->competencyLibraryFilter($row['sub_category'] ?? null),
                'competency_type'   => $this->competencyLibraryFilter($row['competency_type'] ?? null) ?: 'Skill',
                'proficiency_level' => $this->competencyLibraryFilter($row['proficiency_level'] ?? null),
                'status'            => 'Active',
                'approve_status'    => 'Approved',
                'created_by'        => $context['user_id'],
                'updated_by'        => $context['user_id'],
                'created_at'        => $now,
                'updated_at'        => $now,
            ];
        }

        if ($insert) {
            foreach (array_chunk($insert, 500) as $chunk) {
                DB::table('s_users_skills')->insert($chunk);
            }

            $this->logCompetencyLibraryActivity(
                $sid,
                $context['user_id'],
                'imported_competencies',
                'Imported ' . count($insert) . ' ' . (count($insert) === 1 ? 'competency' : 'competencies')
                    . ($skipped ? ' (' . count($skipped) . ' skipped)' : ''),
                null,
                'Competency Library Import'
            );
        }

        return response()->json([
            'status'  => 1,
            'message' => count($insert) . ' ' . (count($insert) === 1 ? 'competency' : 'competencies') . ' imported'
                . ($skipped ? ', ' . count($skipped) . ' skipped' : ''),
            'data'    => [
                'imported' => count($insert),
                'skipped'  => count($skipped),
                'details'  => array_slice($skipped, 0, 100),
            ],
        ], 201);
    }

    /**
     * POST /skill_library/competency/{id}/clone
     *
     * Duplicate a competency as a new library entry ("Clone Competency"). The
     * copy is created as Pending so it has to be reviewed before it counts as an
     * approved competency, and its name is made unique so the list never shows
     * two identical titles.
     *
     * Associations are deliberately NOT copied: role mappings and framework
     * membership are curation decisions about the original, and silently
     * duplicating 50k mapping rows would be a destructive surprise.
     */
    public function competencyLibraryClone(Request $request, $id)
    {
        $context = $this->competencyLibraryContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $source = DB::table('s_users_skills')
            ->where('id', $id)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->first();

        if (!$source) {
            return response()->json(['status' => 0, 'message' => 'Competency not found'], 404);
        }

        $requested = trim((string) $request->input('name', ''));
        $base = $requested !== '' ? $requested : $source->title . ' (Copy)';
        $name = $base;

        // Only bump a suffix when the chosen name is genuinely taken.
        for ($attempt = 2; $attempt <= 50; $attempt++) {
            $taken = DB::table('s_users_skills')
                ->where('sub_institute_id', $sid)
                ->whereNull('deleted_at')
                ->where('title', $name)
                ->exists();
            if (!$taken) {
                break;
            }
            $name = $base . ' ' . $attempt;
        }

        $newId = DB::table('s_users_skills')->insertGetId([
            'sub_institute_id'  => $sid,
            'title'             => $name,
            'description'       => $source->description,
            'category'          => $source->category,
            'sub_category'      => $source->sub_category,
            'competency_type'   => $source->competency_type,
            'proficiency_level' => $source->proficiency_level,
            'department_id'     => $source->department_id,
            'department'        => $source->department,
            'status'            => 'Active',
            'approve_status'    => 'Pending',
            'created_by'        => $context['user_id'],
            'updated_by'        => $context['user_id'],
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $this->logCompetencyLibraryActivity(
            $sid,
            $context['user_id'],
            'cloned_competency',
            'Cloned competency "' . $source->title . '" as "' . $name . '"',
            $newId,
            $name
        );

        return response()->json([
            'status'  => 1,
            'message' => 'Competency cloned as "' . $name . '"',
            'data'    => ['id' => $newId, 'name' => $name],
        ], 201);
    }

    /**
     * PUT /skill_library/competency/{id}/archive
     *
     * Archive (approve_status = Cancelled) or restore (Approved) a competency.
     *
     * Deliberately NOT a delete: a competency is referenced by role mappings,
     * framework items, assessments, development plans and certifications, so
     * removing the row would orphan all of them. Archiving takes it out of
     * circulation while keeping every reference intact, and is reversible.
     * The soft-delete endpoint is untouched and still available.
     */
    public function competencyLibraryArchive(Request $request, $id)
    {
        $context = $this->competencyLibraryContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $existing = DB::table('s_users_skills')
            ->where('id', $id)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->first();

        if (!$existing) {
            return response()->json(['status' => 0, 'message' => 'Competency not found'], 404);
        }

        $restore = filter_var($request->input('restore', false), FILTER_VALIDATE_BOOLEAN);
        // G-COMP-01: restore used to return the row to 'Approved' unconditionally,
        // so archiving a Pending competency and restoring it laundered it into
        // Approved with no reviewer recorded. Restore now returns it to Pending;
        // housekeeping must never grant approval.
        //
        // The deeper flaw is that approve_status carries TWO concerns - lifecycle
        // (active/archived) and review state. Separating them is a schema change
        // and belongs with the Gate D migration, not with this fix.
        $status = $restore ? 'Pending' : 'Cancelled';

        DB::table('s_users_skills')->where('id', $id)->update([
            'approve_status' => $status,
            'updated_by'     => $context['user_id'],
            'updated_at'     => now(),
        ]);

        $this->logCompetencyLibraryActivity(
            $sid,
            $context['user_id'],
            $restore ? 'restored_competency' : 'archived_competency',
            ($restore ? 'Restored' : 'Archived') . ' competency "' . $existing->title . '"',
            (int) $id,
            $existing->title,
            $this->competencyLibraryDiff(
                $existing,
                ['approve_status' => $status],
                self::COMPETENCY_CHANGE_LABELS
            )
        );

        return response()->json([
            'status'  => 1,
            'message' => $restore ? 'Competency restored successfully' : 'Competency archived successfully',
            'data'    => ['id' => (int) $id, 'approve_status' => $status],
        ]);
    }

    /** Single competency (for the details side panel / edit prefill). */
    public function competencyLibraryShow(Request $request, $id)
    {
        $context = $this->competencyLibraryContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $row = DB::table('s_users_skills as s')
            ->leftJoin('tbluser as u', 'u.id', '=', 's.created_by')
            ->where('s.id', $id)
            ->where('s.sub_institute_id', $context['sub_institute_id'])
            ->whereNull('s.deleted_at')
            ->first([
                's.id',
                's.title as name',
                's.description',
                's.category',
                's.sub_category',
                's.competency_type',
                's.proficiency_level',
                's.department',
                's.department_id',
                's.job_titles',
                's.status',
                's.approve_status',
                's.created_at',
                's.updated_at',
                // The detail columns the edit form owns. Returned here rather
                // than on the list so the table payload stays small.
                's.related_skills',
                's.learning_resources',
                's.bussiness_links',
                's.assesment_method',
                's.certification_qualifications',
                's.experience_project',
                's.sop_practice_link',
                's.custom_tags',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as owner"),
            ]);

        if (!$row) {
            return response()->json(['status' => 0, 'message' => 'Competency not found'], 404);
        }

        return response()->json([
            'status'  => 1,
            'message' => 'Competency fetched successfully',
            'data'    => $row,
        ]);
    }

    /**
     * Detail payload for the Competency Library side panel's Proficiency Levels,
     * Associations, Attachments and History tabs. All read from existing tables:
     *  - proficiency  -> s_proficiency_levels (per-skill if defined, else global)
     *  - associations -> s_user_skill_jobrole (roles) + s_competency_framework_items (frameworks)
     *  - attachments  -> the resource text fields on s_users_skills
     *  - history      -> the skill's audit columns + s_competency_activity_log
     */
    public function competencyLibraryDetail(Request $request, $id)
    {
        $context = $this->competencyLibraryContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $skill = DB::table('s_users_skills')
            ->where('id', $id)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->first();

        if (!$skill) {
            return response()->json(['status' => 0, 'message' => 'Competency not found'], 404);
        }

        // --- Proficiency levels: per-skill overrides, else the tenant scale ---
        $levels = DB::table('s_proficiency_levels')
            ->where('sub_institute_id', $sid)->whereNull('deleted_at')->where('skill_id', $id)
            ->orderByRaw('CAST(proficiency_type AS UNSIGNED)')->get();
        $scope = 'competency';
        if ($levels->isEmpty()) {
            $levels = DB::table('s_proficiency_levels')
                ->where('sub_institute_id', $sid)->whereNull('deleted_at')->whereNull('skill_id')
                ->orderByRaw('CAST(proficiency_type AS UNSIGNED)')->get();
            $scope = 'global';
        }
        $proficiency = [
            'scale_label' => $skill->proficiency_level,
            'scope'       => $scope,
            'levels'      => $levels->map(fn ($l) => [
                'level'       => (int) $l->proficiency_type,
                'label'       => $l->proficiency_level,
                'name'        => $l->type_description,
                'description' => $l->description,
            ])->values()->all(),
        ];

        // --- Associations: roles that require it + frameworks that include it ---
        $roles = DB::table('s_user_skill_jobrole')
            ->where('sub_institute_id', $sid)->whereNull('deleted_at')->where('skill', $skill->title)
            ->select('jobrole', DB::raw('MAX(proficiency_level) as proficiency_level'))
            ->groupBy('jobrole')->orderBy('jobrole')->limit(300)->get()
            ->map(fn ($r) => ['jobrole' => $r->jobrole, 'proficiency_level' => $r->proficiency_level])->all();

        $frameworks = DB::table('s_competency_framework_items as fi')
            ->join('s_competency_frameworks as f', 'f.id', '=', 'fi.framework_id')
            ->where('fi.competency_id', $id)->where('fi.sub_institute_id', $sid)
            ->whereNull('fi.deleted_at')->whereNull('f.deleted_at')
            ->select('f.id', 'f.name', 'f.status', 'fi.required_proficiency')->orderBy('f.name')->get()
            ->map(fn ($r) => [
                'id'                   => (int) $r->id,
                'name'                 => $r->name,
                'status'               => $r->status,
                'required_proficiency' => $r->required_proficiency,
            ])->all();

        $associations = [
            'roles'           => $roles,
            'frameworks'      => $frameworks,
            'role_count'      => count($roles),
            'framework_count' => count($frameworks),
        ];

        // --- Top associated roles: the roles that demand it most -------------
        // Ranked by required proficiency, because headcount per role is not
        // derivable here - tbluser.jobtitle_id is 0 for every user on this
        // tenant, so there is no employee-to-role edge to count.
        $departments = DB::table('s_user_jobrole')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereIn('jobrole', array_column($roles, 'jobrole'))
            ->pluck('department', 'jobrole');

        $topRoles = $roles;
        usort($topRoles, function ($a, $b) {
            $levelA = is_numeric($a['proficiency_level']) ? (int) $a['proficiency_level'] : 0;
            $levelB = is_numeric($b['proficiency_level']) ? (int) $b['proficiency_level'] : 0;

            return $levelB <=> $levelA ?: strcmp((string) $a['jobrole'], (string) $b['jobrole']);
        });

        $topRoles = array_map(fn ($role) => [
            'jobrole'           => $role['jobrole'],
            'proficiency_level' => $role['proficiency_level'],
            'department'        => $departments[$role['jobrole']] ?? null,
        ], array_slice($topRoles, 0, 5));

        // --- Summary: where this competency is actually in use ---------------
        $countFor = fn (string $table) => DB::table($table)
            ->where('sub_institute_id', $sid)
            ->where('competency_id', $id)
            ->whereNull('deleted_at')
            ->count();

        $summary = [
            'description'      => $skill->description,
            'category'         => $skill->category,
            'sub_category'     => $skill->sub_category,
            'competency_type'  => $skill->competency_type,
            'status'           => $skill->approve_status,
            'role_count'       => count($roles),
            'framework_count'  => count($frameworks),
            // Employees carrying a rating for this competency (s_skill_matrix
            // has no tenant column, so it is scoped through the skill id).
            'rated_employees'  => DB::table('s_skill_matrix')
                ->where('skill_id', $id)
                ->whereNull('deleted_at')
                ->distinct()
                ->count('user_id'),
            'plan_count'       => $countFor('s_competency_development_plans'),
            'certification_count' => $countFor('s_competency_certifications'),
            // s_competency_assessments has no competency_id - it links to a
            // framework - so this is assessments run against the frameworks
            // that actually contain this competency. Zero when it belongs to
            // no framework, which is the honest answer.
            'assessment_count' => empty($frameworks) ? 0 : DB::table('s_competency_assessments')
                ->where('sub_institute_id', $sid)
                ->whereNull('deleted_at')
                ->whereIn('framework_id', array_column($frameworks, 'id'))
                ->count(),
            'learning_count'   => DB::table('lms_assignments')
                ->where('sub_institute_id', $sid)
                ->where('source', 'competency')
                ->where('competency_id', $id)
                ->whereNull('deleted_at')
                ->count(),
            'evidence_count'   => $countFor('s_competency_evidence'),
        ];

        // --- Attachments: the skill's resource text fields ---
        $attachments = [];
        foreach ([
            'Learning Resources'            => $skill->learning_resources ?? null,
            'Certification / Qualifications' => $skill->certification_qualifications ?? null,
            'SOP / Practice Link'           => $skill->sop_practice_link ?? null,
            'Experience / Projects'         => $skill->experience_project ?? null,
            'Assessment Method'             => $skill->assesment_method ?? null,
        ] as $label => $value) {
            if ($value !== null && trim((string) $value) !== '') {
                $attachments[] = ['type' => $label, 'value' => trim((string) $value)];
            }
        }

        // --- History: audit columns + activity-log entries for this competency ---
        $resolveName = function ($uid) {
            if (!$uid) {
                return 'System';
            }
            $u = DB::table('tbluser')->where('id', $uid)->first();
            if (!$u) {
                return 'System';
            }
            $n = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
            return $n !== '' ? $n : ($u->user_name ?? 'System');
        };

        $history = [];
        if ($skill->created_at) {
            $history[] = ['action' => 'Competency created', 'by' => $resolveName($skill->created_by), 'date' => date('d M Y', strtotime($skill->created_at))];
        }
        $history[] = ['action' => 'Status: ' . $skill->approve_status, 'by' => $resolveName($skill->updated_by ?: $skill->created_by), 'date' => $skill->updated_at ? date('d M Y', strtotime($skill->updated_at)) : ''];
        if ($skill->updated_at && $skill->updated_at !== $skill->created_at) {
            $history[] = ['action' => 'Last updated', 'by' => $resolveName($skill->updated_by), 'date' => date('d M Y', strtotime($skill->updated_at))];
        }
        $acts = DB::table('s_competency_activity_log')
            ->where('sub_institute_id', $sid)->where('subject_type', 'competency')->where('subject_id', $id)
            ->orderByDesc('created_at')->limit(20)->get();
        foreach ($acts as $a) {
            $history[] = ['action' => $a->description ?: $a->action, 'by' => $a->actor_name ?: 'System', 'date' => $a->created_at ? date('d M Y', strtotime($a->created_at)) : ''];
        }

        return response()->json([
            'status'  => 1,
            'message' => 'Competency detail fetched successfully',
            'data'    => [
                'summary'      => $summary,
                'top_roles'    => $topRoles,
                'proficiency'  => $proficiency,
                'associations' => $associations,
                'attachments'  => $attachments,
                'history'      => $history,
            ],
        ]);
    }

    /** Create a competency (an approved skill row on s_users_skills). */
    public function competencyLibraryStore(Request $request)
    {
        $context = $this->competencyLibraryContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), $this->competencyLibraryRules());
        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $id = DB::table('s_users_skills')->insertGetId(array_merge([
            'sub_institute_id'  => $context['sub_institute_id'],
            'title'             => $request->input('name'),
            'description'       => $request->input('description'),
            'category'          => $request->input('category'),
            'sub_category'      => $request->input('sub_category'),
            'competency_type'   => $request->input('competency_type') ?: 'Skill',
            'proficiency_level' => $request->input('proficiency_level'),
            'department_id'     => $request->input('department_id'),
            'status'            => 'Active',
            // G-COMP-01: approval state is SERVER-OWNED. It was previously taken
            // from the request and defaulted to 'Approved', so a caller could
            // decide their own approval state and every new competency was born
            // approved with no review. It now always starts Pending; only
            // ApprovalController may move it.
            'approve_status'    => 'Pending',
            'created_by'        => $context['user_id'],
            'updated_by'        => $context['user_id'],
            'created_at'        => now(),
            'updated_at'        => now(),
        ], $this->competencyLibraryDetailPayload($request)));

        $this->logCompetencyLibraryActivity(
            $context['sub_institute_id'],
            $context['user_id'],
            'created_competency',
            'Created competency "' . $request->input('name') . '"',
            $id,
            $request->input('name')
        );

        return response()->json([
            'status'  => 1,
            'message' => 'Competency created successfully',
            'data'    => ['id' => $id],
        ], 201);
    }

    /** Update a competency's core fields. */
    public function competencyLibraryUpdate(Request $request, $id)
    {
        $context = $this->competencyLibraryContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $existing = DB::table('s_users_skills')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();
        if (!$existing) {
            return response()->json(['status' => 0, 'message' => 'Competency not found'], 404);
        }

        $validator = Validator::make($request->all(), $this->competencyLibraryRules());
        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $update = array_merge([
            'title'             => $request->input('name'),
            'description'       => $request->input('description'),
            'category'          => $request->input('category'),
            'sub_category'      => $request->input('sub_category'),
            'competency_type'   => $request->input('competency_type') ?: 'Skill',
            'proficiency_level' => $request->input('proficiency_level'),
            'department_id'     => $request->input('department_id'),
            'updated_by'        => $context['user_id'],
            'updated_at'        => now(),
        ], $this->competencyLibraryDetailPayload($request));
        // G-COMP-01: `status` is deliberately NOT copied to approve_status here.
        // Editing a competency must not change its approval state - that was a
        // one-dropdown bypass of the entire review workflow. Approval moves only
        // through ApprovalController.
        DB::table('s_users_skills')->where('id', $id)->update($update);

        $this->logCompetencyLibraryActivity(
            $context['sub_institute_id'],
            $context['user_id'],
            'updated_competency',
            'Updated competency "' . $request->input('name') . '"',
            (int) $id,
            $request->input('name'),
            $this->competencyLibraryDiff($existing, $update, self::COMPETENCY_CHANGE_LABELS)
        );

        return response()->json([
            'status'  => 1,
            'message' => 'Competency updated successfully',
            'data'    => ['id' => (int) $id],
        ]);
    }

    /** Soft-delete a competency. */
    public function competencyLibraryDestroy(Request $request, $id)
    {
        $context = $this->competencyLibraryContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $existing = DB::table('s_users_skills')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();
        if (!$existing) {
            return response()->json(['status' => 0, 'message' => 'Competency not found'], 404);
        }

        DB::table('s_users_skills')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $context['user_id'],
        ]);

        $this->logCompetencyLibraryActivity(
            $context['sub_institute_id'],
            $context['user_id'],
            'deleted_competency',
            'Deleted competency "' . $existing->title . '"',
            (int) $id,
            $existing->title
        );

        return response()->json(['status' => 1, 'message' => 'Competency deleted successfully']);
    }
}
