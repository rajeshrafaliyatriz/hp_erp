<?php

namespace App\Http\Controllers\libraries;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\libraries\skillLibraryModel;
use App\Models\libraries\jobroleSkillModel;
use App\Models\libraries\industryModel;
use function App\Helpers\is_mobile;
use Laravel\Sanctum\PersonalAccessToken;
use App\Models\libraries\jobroleModel;
use App\Models\libraries\userSkills;
use App\Models\libraries\skillJobroleMap;
use App\Models\libraries\userProfeceincyLevel;
use App\Models\libraries\userKnowledgeAbility;
use App\Models\libraries\userApplication;
use App\Models\DynamicModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;

class skillLibraryController extends Controller
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

        // $skillData = skillLibraryModel::where('industries',$request->org_type)
        //  ->when($request->has('department'), function ($q) use ($request) {
        //         $q->where('category', $request->department);
        //     })
        //     ->when($request->has('sub_department'), function ($q) use ($request) {
        //         $q->whereIn('sub_category', explode(',',$request->sub_department));
        //     })  
        // ->orderby('category')
        // ->groupBy('master_skills.id')
        // ->get()
        // ->toArray();

        // Build base query with eager loading and index optimization
      $cacheKey = 'skillData_' . md5(json_encode($request->all()));

        $AllskillData = industryModel::from('s_industries as a')
                // ->select('c.*')
                ->join('s_jobrole_skills as b', function ($join) {
                    $join->on('b.sector', '=', 'a.department')
                        ->on('a.sub_department', '=', 'b.track')
                        ->useIndex('idx_sector_track');
                })
                // ->join('master_skills as c', 'c.title', '=', 'b.skill')
                ->where('a.industries', $request->org_type)
                ->when($request->has('department'), function ($q) use ($request) {
                    $q->where('a.department', $request->department);
                })
                ->when($request->has('sub_department'), function ($q) use ($request) {
                    $q->whereIn('a.sub_department', explode(',',$request->sub_department));
                })  
                ->groupBy('b.skill')
                ->get();

        $skillData = [];
        foreach($AllskillData as $key=>$values){
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

        $userSkills = userSkills::where('sub_institute_id', $request->sub_institute_id)
            ->where('approve_status', 'Approved')
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

        $proficiency_level = userProfeceincyLevel::where('skill_id', $request->skill_id)
            ->where('sub_institute_id', $request->sub_institute_id)
            ->whereNull('deleted_at')
            ->get();

        if ($proficiency_level->isEmpty()) {
            $proficiency_level = userProfeceincyLevel::whereNull('skill_id')
                ->whereNull('sub_institute_id')
                ->whereNull('deleted_at')
                ->get();
        }
        $res['proficiency_levels'] = $proficiency_level;
        if ($request->has('formType') && $request->formType == "jobrole") {

            $res['userJobroleData'] = skillJobroleMap::with([
                'userSkills' => fn($q) => $q->select($skillFields),
                'createdUser' => fn($q) => $q->select($createdUser),
            ])
                ->where('skill_id', $request->skill_id)
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

                    unset($data['user_skills'], $data['created_user']);

                    return $data;
                });
        } else if ($request->has('formType') && $request->formType == "proficiency_level") {
            $res['userproficiency_levelData'] = userProfeceincyLevel::with([
                'userSkills' => fn($q) => $q->select($skillFields),
                'createdUser' => fn($q) => $q->select($createdUser),
            ])
                ->where('skill_id', $request->skill_id)
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

                    unset($data['user_skills'], $data['created_user']);

                    return $data;
                });
        } else if ($request->has('formType') && $request->formType == "knowledge") {
            $res['userKnowledgeData'] = userKnowledgeAbility::with([
                'userSkills' => fn($q) => $q->select($skillFields),
                'createdUser' => fn($q) => $q->select($createdUser),
            ])
                ->where('skill_id', $request->skill_id)
                ->where('classification', 'knowledge')
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

                    unset($data['user_skills'], $data['created_user']);

                    return $data;
                });
        } else if ($request->has('formType') && $request->formType == "ability") {
            $res['userabilityData'] = userKnowledgeAbility::with([
                'userSkills' => fn($q) => $q->select($skillFields),
                'createdUser' => fn($q) => $q->select($createdUser),
            ])
                ->where('skill_id', $request->skill_id)
                ->where('classification', 'ability')
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

                    unset($data['user_skills'], $data['created_user']);

                    return $data;
                });
        }
        // userApplication
        else if ($request->has('formType') && $request->formType == "application") {
            $res['userApplicationData'] = userApplication::with([
                'userSkills' => fn($q) => $q->select($skillFields),
                'createdUser' => fn($q) => $q->select($createdUser),
            ])
                ->where('skill_id', $request->skill_id)
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

                    unset($data['user_skills'], $data['created_user']);

                    return $data;
                });
        } else {

           $AllskillData = industryModel::from('s_industries as a')
                // ->select('c.*')
                ->join('s_jobrole_skills as b', function ($join) {
                    $join->on('b.sector', '=', 'a.department')
                        ->on('a.sub_department', '=', 'b.track')
                        ->useIndex('idx_sector_track');
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
            foreach($AllskillData as $key=>$values){
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
                    $join->on('b.sector', '=', 'a.department')
                        ->on('a.sub_department', '=', 'b.track');
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
                                    'skill_id' => $lastInsertedId,
                                    'jobrole' => $jv->jobrole,
                                    'description' => null,
                                    'sub_institute_id' => $request->sub_institute_id,
                                    'created_by' => $request->user_id,
                                    'created_at' => now(),
                                ];
                                $insert = skillJobroleMap::insert($insertArray);
                            }

                            $proficiencyLevelArr = DB::table('s_skill_map_k_a')->where('tsc_ccs_title', $skillName)->groupBy('proficiency_level')->get()->toArray();
                            if (!empty($proficiencyLevelArr)) {
                                foreach ($proficiencyLevelArr as $jk => $jv) {
                                    $insertArray = [
                                        'skill_id' => $lastInsertedId,
                                        'proficiency_level' => $jv->proficiency_level,
                                        'description' => $jv->proficiency_description,
                                        'sub_institute_id' => $request->sub_institute_id,
                                        'created_by' => $request->user_id,
                                        'created_at' => now(),
                                    ];
                                    $insert = userProfeceincyLevel::insert($insertArray);
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
                                        'created_by' => $request->user_id,
                                        'created_at' => now(),
                                    ];
                                    $insert = userKnowledgeAbility::insert($insertArray);
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
                                        'created_by' => $request->user_id,
                                        'created_at' => now(),
                                    ];
                                    $insert = userKnowledgeAbility::insert($insertArray);
                                }
                            }
                        }
                    }
                }

                $i++;
            }
        } else {
            // return [$request,'type'=>$request->formType];
            $getIndustries = industryModel::where('department', $request->category)->first();
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
        $res['editData'] = skillLibraryModel::find($id);
        if ($request->formType == "user") {
            $res['editData'] = userSkills::find($id);
        }
        $res['userJobroleData'] = skillJobroleMap::with([
            'userSkills' => fn($q) => $q->select($skillFields),
            'createdUser' => fn($q) => $q->select($createdUser),
        ])
            ->where('skill_id', $id)
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

                unset($data['user_skills'], $data['created_user']);

                return $data;
            });
        $res['userproficiency_levelData'] = userProfeceincyLevel::with([
            'userSkills' => fn($q) => $q->select($skillFields),
            'createdUser' => fn($q) => $q->select($createdUser),
        ])
            ->where('skill_id', $id)
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

                unset($data['user_skills'], $data['created_user']);

                return $data;
            });
        $res['userKnowledgeData'] = userKnowledgeAbility::with([
            'userSkills' => fn($q) => $q->select($skillFields),
            'createdUser' => fn($q) => $q->select($createdUser),
        ])
            ->where('skill_id', $id)
            ->where('classification', 'knowledge')
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

                unset($data['user_skills'], $data['created_user']);

                return $data;
            });
        $res['userabilityData'] = userKnowledgeAbility::with([
            'userSkills' => fn($q) => $q->select($skillFields),
            'createdUser' => fn($q) => $q->select($createdUser),
        ])
            ->where('skill_id', $id)
            ->where('classification', 'ability')
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

                unset($data['user_skills'], $data['created_user']);

                return $data;
            });
        $res['userApplicationData'] = userApplication::with([
            'userSkills' => fn($q) => $q->select($skillFields),
            'createdUser' => fn($q) => $q->select($createdUser),
        ])
            ->where('skill_id', $id)
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

                unset($data['user_skills'], $data['created_user']);

                return $data;
            });
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
                $checkExists = skillJobroleMap::where('jobrole', $value)->where('skill_id', $id)->where('sub_institute_id', $request->sub_institute_id)->whereNull('deleted_at')->first();
                if (!$checkExists) {
                    $insertArray = [
                        'skill_id' => $id,
                        'jobrole' => $value,
                        'description' => $request->description[$key] ?? null,
                        'sub_institute_id' => $request->sub_institute_id,
                        'created_by' => $request->user_id,
                        'created_at' => now(),
                    ];
                    $insert = skillJobroleMap::insert($insertArray);
                    $i++;
                } elseif (isset($checkExists->id)) {
                    $insertArray = [
                        'skill_id' => $id,
                        'jobrole' => $value,
                        'description' => $request->description[$key] ?? null,
                        'sub_institute_id' => $request->sub_institute_id,
                        'updated_by' => $request->user_id,
                        'updated_at' => now(),
                    ];
                    $insert = skillJobroleMap::where('id', $checkExists->id)->update($insertArray);
                    $i++;
                }

                //    $res['userJobroleData'] = skillJobroleMap::where('skill_id',$id)->where('sub_institute_id',$request->sub_institute_id)->whereNull('deleted_at')->get();

                $res['userJobroleData'] = skillJobroleMap::with([
                    'userSkills' => fn($q) => $q->select($skillFields),
                    'createdUser' => fn($q) => $q->select($createdUser),
                ])
                    ->where('skill_id', $request->skill_id)
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

                        unset($data['user_skills'], $data['created_user']);

                        return $data;
                    });
            }
        }

        if ($request->formType == 'proficiency_level') {
            foreach ($request->proficiency_level as $key => $value) {
                $checkExists = userProfeceincyLevel::where('proficiency_level', $value)->where('skill_id', $id)->where('sub_institute_id', $request->sub_institute_id)->whereNull('deleted_at')->first();
                if (!$checkExists) {
                    $insertArray = [
                        'skill_id' => $id,
                        'proficiency_level' => $value,
                        'description' => $request->description[$key] ?? null,
                        'sub_institute_id' => $request->sub_institute_id,
                        'created_by' => $request->user_id,
                        'created_at' => now(),
                    ];
                    $insert = userProfeceincyLevel::insert($insertArray);
                    $i++;
                } elseif (isset($checkExists->id)) {
                    $insertArray = [
                        'skill_id' => $id,
                        'proficiency_level' => $value,
                        'description' => $request->description[$key] ?? null,
                        'sub_institute_id' => $request->sub_institute_id,
                        'updated_by' => $request->user_id,
                        'updated_at' => now(),
                    ];
                    $insert = userProfeceincyLevel::where('id', $checkExists->id)->update($insertArray);
                    $i++;
                }

                $res['userJobroleData'] = userProfeceincyLevel::with([
                    'userSkills' => fn($q) => $q->select($skillFields),
                    'createdUser' => fn($q) => $q->select($createdUser),
                ])
                    ->where('skill_id', $request->skill_id)
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

                        unset($data['user_skills'], $data['created_user']);

                        return $data;
                    });
            }
        }

        if ($request->formType == 'knowledge') {
            foreach (json_decode($request->knowledge_ability_data) as $key => $value) {
                $checkExists = userKnowledgeAbility::where('classification', 'knowledge')->where('classification_item', $value->classification_item)->where('skill_id', $id)->where('sub_institute_id', $request->sub_institute_id)->whereNull('deleted_at')->first();
                if (!$checkExists) {
                    $insertArray = [
                        'skill_id' => $id,
                        'proficiency_level' => $value->proficiency_level,
                        'classification_item' => $value->classification_item,
                        'classification' => 'knowledge',
                        'sub_institute_id' => $request->sub_institute_id,
                        'created_by' => $request->user_id,
                        'created_at' => now(),
                    ];
                    $insert = userKnowledgeAbility::insert($insertArray);
                    $i++;
                } elseif (isset($checkExists->id)) {
                    $insertArray = [
                        'skill_id' => $id,
                        'proficiency_level' => $value->proficiency_level,
                        'classification_item' => $value->classification_item,
                        'classification' => 'knowledge',
                        'sub_institute_id' => $request->sub_institute_id,
                        'updated_by' => $request->user_id,
                        'updated_at' => now(),
                    ];
                    $insert = userKnowledgeAbility::where('id', $checkExists->id)->update($insertArray);
                    $i++;
                }
            }
            $res['userKnowledgeData'] = userKnowledgeAbility::with([
                'userSkills' => fn($q) => $q->select($skillFields),
                'createdUser' => fn($q) => $q->select($createdUser),
            ])
                ->where('skill_id', $id)
                ->where('classification', 'knowledge')
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

                    unset($data['user_skills'], $data['created_user']);

                    return $data;
                });
        }

        if ($request->formType == 'ability') {
            foreach (json_decode($request->ability_data) as $key => $value) {
                $checkExists = userKnowledgeAbility::where('classification', 'ability')->where('classification_item', $value->classification_item)->where('skill_id', $id)->where('sub_institute_id', $request->sub_institute_id)->whereNull('deleted_at')->first();
                if (!$checkExists) {
                    $insertArray = [
                        'skill_id' => $id,
                        'proficiency_level' => $value->proficiency_level,
                        'classification_item' => $value->classification_item,
                        'classification' => 'ability',
                        'sub_institute_id' => $request->sub_institute_id,
                        'created_by' => $request->user_id,
                        'created_at' => now(),
                    ];
                    $insert = userKnowledgeAbility::insert($insertArray);
                    $i++;
                } elseif (isset($checkExists->id)) {
                    $insertArray = [
                        'skill_id' => $id,
                        'proficiency_level' => $value->proficiency_level,
                        'classification_item' => $value->classification_item,
                        'classification' => 'ability',
                        'sub_institute_id' => $request->sub_institute_id,
                        'updated_by' => $request->user_id,
                        'updated_at' => now(),
                    ];
                    $insert = userKnowledgeAbility::where('id', $checkExists->id)->update($insertArray);
                    $i++;
                }
            }
            $res['userabilityData'] = userKnowledgeAbility::with([
                'userSkills' => fn($q) => $q->select($skillFields),
                'createdUser' => fn($q) => $q->select($createdUser),
            ])
                ->where('skill_id', $id)
                ->where('classification', 'ability')
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

                    unset($data['user_skills'], $data['created_user']);

                    return $data;
                });
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
                        'created_by' => $request->user_id,
                        'created_at' => now(),
                    ];
                    $insert = userApplication::insert($insertArray);
                    $i++;
                } elseif (isset($checkExists->id)) {
                    $insertArray = [
                        'skill_id' => $id,
                        'proficiency_level' => $value->proficiency_level,
                        'application' => $value->application,
                        'sub_institute_id' => $request->sub_institute_id,
                        'updated_by' => $request->user_id,
                        'updated_at' => now(),
                    ];
                    $insert = userApplication::where('id', $checkExists->id)->update($insertArray);
                    $i++;
                }
            }

            $res['userApplicationData'] = userApplication::with([
                'userSkills' => fn($q) => $q->select($skillFields),
                'createdUser' => fn($q) => $q->select($createdUser),
            ])
                ->where('skill_id', $request->skill_id)
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

                    unset($data['user_skills'], $data['created_user']);

                    return $data;
                });
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
                'formType' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
        }
        $i = 0;
        if ($request->formType == "jobrole") {
            $delete = skillJobroleMap::where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $request->user_id]);
            if ($delete) {
                $i++;
            }
        }
        if ($request->formType == "proficiency_level") {
            $delete = userProfeceincyLevel::where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $request->user_id]);
            if ($delete) {
                $i++;
            }
        }
        if ($request->has('formType') && $request->formType == "jobrole") {

            $delete = skillJobroleMap::where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $request->user_id]);
            if ($delete) {
                $i++;
            }
        }

        if ($request->has('formType') && $request->formType == "knowledge") {
            $delete = userKnowledgeAbility::where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $request->user_id]);
            if ($delete) {
                $i++;
            }
        }
        if ($request->has('formType') && $request->formType == "ability") {
            $delete = userKnowledgeAbility::where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $request->user_id]);
            if ($delete) {
                $i++;
            }
        }
        // userApplication
        if ($request->has('formType') && $request->formType == "application") {
            $delete = userApplication::where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $request->user_id]);
            if ($delete) {
                $i++;
            }
        }

        if ($request->has('formType') && $request->formType == "user") {
            $delete = userSkills::where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $request->user_id]);
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
