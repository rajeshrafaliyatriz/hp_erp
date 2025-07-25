<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
// use App\Models\HrmsJobTitle;
use App\Models\school_setup\subjectModel;
use App\Models\settings\tblcustomfieldsModel;
use App\Models\settings\tblfields_dataModel;
use App\Models\user\tbluserModel;
use App\Models\user\tbluserprofilemasterModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use function App\Helpers\is_mobile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use App\Models\libraries\userJobroleModel;
use App\Models\libraries\skillJobroleMap;
use App\Models\libraries\SLevelResponsibility;
use App\Models\libraries\userKnowledgeAbility;
use App\Models\libraries\jobroleSkillModel;
use App\Models\libraries\userJobroleTask;
use Illuminate\Support\Str;
use App\Models\skill\skill;
use App\Models\skill\matrix;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Laravel\Sanctum\PersonalAccessToken;

class tbluserController extends Controller
{

    public function index(Request $request)
    {
        // echo "<pre>";print_r(session()->all());exit;
        $sub_institute_id = session()->get('sub_institute_id');
        $user_id = session()->get('user_id');
        $user_profile = session()->get('user_profile_name');
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
            $sub_institute_id = $request->get('sub_institute_id');
            $user_id = $request->get('user_id');
            $user_profile = $request->get('user_profile_name');
        }
        $user_data = tbluserModel::select(
            'tbluser.*',
            'tbluserprofilemaster.name as profile_name',
            DB::raw('if(tbluser.status = 1,"Active","Inactive") as status')
        )
            ->join('tbluserprofilemaster', 'tbluser.user_profile_id', '=', 'tbluserprofilemaster.id')
            ->where(['tbluser.sub_institute_id' => $sub_institute_id]) //, 'tbluser.status' => "1"
            ->when(!in_array($user_profile, ["Admin", "Super Admin"]), function ($q) use ($user_id) {
                $q->where('tbluser.id', $user_id);
            })
            ->get();

        $res['status_code'] = 1;
        $res['message'] = "Success";
        $res['data'] = $user_data;


        return is_mobile($type, "user/show_user", $res, "view");
    }

    public function create(Request $request)
    {
        $sub_institute_id = $request->session()->get('sub_institute_id');
        $data = tbluserprofilemasterModel::where(['sub_institute_id' => $sub_institute_id, 'status' => '1'])->get()->toArray();

        $dataCustomFields = tblcustomfieldsModel::where(['sub_institute_id' => $sub_institute_id, 'status' => "1", 'table_name' => "tbluser", "user_type" => ""])->get();


        $subject_data = subjectModel::where(['sub_institute_id' => $sub_institute_id])->get();
        $employees = tbluserModel::where('sub_institute_id', $sub_institute_id)->where('status', 1)->get();
        $job_titles = []; // HrmsJobTitle::where('sub_institute_id',$sub_institute_id)->get();
        $departments = DB::table('hrms_departments')->where('sub_institute_id', $sub_institute_id)->where('status', 1)->get()->toArray();
        $fieldsData = tblfields_dataModel::get()->toArray();
        $i = 0;
        $finalfieldsData = [];
        foreach ($fieldsData as $key => $value) {
            $finalfieldsData[$value['field_id']][$i]['display_text'] = $value['display_text'];
            $finalfieldsData[$value['field_id']][$i]['display_value'] = $value['display_value'];
            $i++;
        }

        if (count($finalfieldsData) > 0) {
            view()->share('data_fields', $finalfieldsData);
        }

        // auto increament 20-04-24
        $maxEmpCode = DB::table('tbluser')->selectRaw("MAX(CAST(employee_no AS INT)) AS new_emp_code")
            ->where('sub_institute_id', $sub_institute_id)->whereRaw('employee_no is not null')->limit(1)->orderBy('id')->get()->toArray();

        $maxEmpCode = array_map(function ($value) {
            return (array) $value;
        }, $maxEmpCode);

        $new_emp_code = ($maxEmpCode['0']['new_emp_code'] + 1) ?? 1;

        $qualificationList = tbluserModel::where('sub_institute_id', $sub_institute_id)->where('status', 1)->whereNotNull('qualification')->groupBy('qualification')->pluck('qualification');

        $occupationList = tbluserModel::where('sub_institute_id', $sub_institute_id)->where('status', 1)->whereNotNull('occupation')->groupBy('occupation')->pluck('occupation');

        // start 30-07-2024
        $masterSetups = []; //DB::table('master_setup_select')->select('type','fieldname',DB::raw('GROUP_CONCAT(fieldValue SEPARATOR "||") as selOptions'))->where('sub_institute_id',$sub_institute_id)->groupBy('type')->get()->toArray();
        $pluckedData = [];
        foreach ($masterSetups as $setup) {
            if (!isset($pluckedData[$setup->type])) {
                $pluckedData[$setup->type] = [];
            }
            $pluckedData[$setup->type]['fieldname'] = $setup->fieldname;
            $pluckedData[$setup->type]['fieldvalue'] = $setup->selOptions; // array ['skills']['select skill']=skill1 || skill 2 || skill 3
        }
        // end 30-07-2024

        view()->share('qualificationList', $qualificationList);
        view()->share('occupationList', $occupationList);

        view()->share('new_emp_code', $new_emp_code);
        // end 20-04-24
        view()->share('custom_fields', $dataCustomFields);
        view()->share('subject_data', $subject_data);
        view()->share('user_profiles', $data);
        view()->share('job_titles', $job_titles);
        view()->share('employees', $employees);
        view()->share('departments', $departments);
        view()->share('masterSetups', $pluckedData);

        return view('user/add_user');
    }

    public function store(Request $request)
    {
        //return $request->all();
        $sub_institute_id = $request->session()->get('sub_institute_id');
        $type = $request->input('type');

        $file_name = "";
        if ($request->hasFile('user_image')) {
            $file = $request->file('user_image');
            $originalname = $file->getClientOriginalName();
            $name = $request->get('user_name') . date('YmdHis');
            $ext = File::extension($originalname);
            $file_name = $name . '.' . $ext;
            // $path = $file->storeAs('public/user/', $file_name);
            $path = Storage::disk('digitalocean')->putFileAs(
                'hp_user',
                $file,
                $filename,
                'public'
            );

            $publicUrl = Storage::disk('digitalocean')->url($path);
        }

        $request->request->add(['image' => $file_name]); //add request
        $data = $this->saveData($request);

        $data = tbluserModel::where(['sub_institute_id' => $sub_institute_id])->get();

        $res['status_code'] = "1";
        $res['message'] = "User created successfully";
        $res['data'] = $data;

        return is_mobile($type, "add_user.index", $res);
    }

    public function saveData(Request $request)
    {
        $newRequest = $request->all();
        $sub_institute_id = $request->session()->get('sub_institute_id');
        $finalArray['sub_institute_id'] = $sub_institute_id;
        $finalArray['status'] = 1;
        unset($newRequest['user_image']);
        foreach ($newRequest as $key => $value) {
            if ($key != '_method' && $key != '_token' && $key != 'submit') {
                if (is_array($value)) {
                    $value = implode(",", $value);
                }
                $finalArray[$key] = $value;
            }

            if ($key == "password") {
                $finalArray[$key] = Hash::make($value);
                $finalArray['plain_password'] = $value;
            }

            if ($key == "birthdate") {
                $finalArray[$key] = carbon::parse($value)->format('Y-m-d');
            }
        }
        $finalArray['created_at'] = now();
        $finalArray['created_by'] = session()->get('user_id');
        tbluserModel::insert($finalArray);
        $id = DB::getPdo()->lastInsertId();

        $client_data = DB::table("school_setup as s")
            ->join('tblclient as c', function ($join) {
                $join->whereRaw("c.id = s.client_id");
            })
            ->selectRaw('*,if(db_hrms is null,0,1) as rights')
            ->where("s.Id", "=", $sub_institute_id)
            ->get()->toArray();

        $hrms_db_host = $client_data[0]->db_host;
        $hrms_db_user = $client_data[0]->db_user;
        $hrms_db_password = $client_data[0]->db_password;
        $hrms_db_hrms = $client_data[0]->db_hrms;
        $hrms_rights = $client_data[0]->rights;

        if ($hrms_rights == 1 && $id != "") {
            $fields = [
                'db_host'     => $hrms_db_host,
                'db_user'     => $hrms_db_user,
                'db_password' => $hrms_db_password,
                'db_hrms'     => $hrms_db_hrms,
            ];
            $fields = array_merge($fields, $finalArray);

            //url-ify the data for the POST
            $fields_string = "";
            foreach ($fields as $key => $value) {
                $fields_string .= $key . '=' . $value . '&';
            }
            rtrim($fields_string, '&');
            //open connection
            $ch = curl_init();

            $url = "http://" . $_SERVER['HTTP_HOST'] . "/add_user_hrms.php";

            //set the url, number of POST vars, POST data
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, count($fields));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);

            //execute post
            $result = curl_exec($ch);

            //close connection
            curl_close($ch);
        }

        return $id;
    }

    public function updateData(Request $request,$id)
    {
        // return $request;exit;
        $sub_institute_id = $request->session()->get('sub_institute_id');
        $user_id = session()->get('user_id');
        if($request->type=="API"){
            $sub_institute_id = $request->input('sub_institute_id');
            $user_id = $request->input('user_id');
        }
        $newRequest = $request->all();
        // $user_id = $newRequest['id'];
        $finalArray['sub_institute_id'] = $sub_institute_id;
        $finalArray['status'] = 1;
        unset($newRequest['user_image']);
        foreach ($newRequest as $key => $value) {
            if ($key != 'type' && $key != 'user_id' && $key != '_method' && $key != '_token' && $key != 'submit' && $key != 'id') {
                if (is_array($value)) {
                    $value = implode(",", $value);
                }

                // Convert time fields to HH:MM:SS
                if (Str::endsWith($key, '_in_date') || Str::endsWith($key, '_out_date')) {
                    if (!empty($value)) {
                        $value = date('H:i:s', strtotime($value));
                    } else {
                        $value = null;
                    }
                }

                $finalArray[$key] = $value;
            }

            if ($key == "password") {
                $finalArray[$key] = Hash::make($value);
                $finalArray['plain_password'] = $value;
            }

            if ($key == "birthdate") {
                $finalArray[$key] = carbon::parse($value)->format('Y-m-d');
            }
        }

        $finalArray['updated_at'] = now();
        $finalArray['updated_by'] = $user_id;
        return tbluserModel::where(['id' => $id])->update($finalArray);
    }

    public function edit(Request $request, $id)
    {
        $type = $request->input('type');
        $userLevelOfResponsibility = array();

        if ($type == "API") {
            $validator = Validator::make($request->all(), [
                'sub_institute_id' => 'required|numeric',
                'syear' => 'required|numeric',
                'type' => 'required',
            ]);

            if ($validator->fails()) {
                $res['status'] = '0';
                $res['message'] = $validator->messages()->first();
                return is_mobile($type, "add_user.index", $res);
            }
            $sub_institute_id = $request->input('sub_institute_id');
            $syear = $request->input('syear');
        } else {
            $sub_institute_id = $request->session()->get('sub_institute_id');
            $syear = session()->get('syear');
        }

        $editData = tbluserModel::find($id)->toArray();
        $data = tbluserprofilemasterModel::where(['sub_institute_id' => $sub_institute_id])->get()->toArray();
        $subject_data = subjectModel::where(['sub_institute_id' => $sub_institute_id])->get()->toArray();
        // $userLevelOfResponsibility = $editData['subject_ids'];
        // if (isset($subject_data_selected)) {
        //     $userLevelOfResponsibility = explode(",", $subject_data_selected);
        // }

        $dataCustomFields = tblcustomfieldsModel::where([
            'sub_institute_id' => $sub_institute_id,
            'status' => "1",
            'table_name' => "tbluser",
            "user_type" => ""
        ])->get();


        $fieldsData = tblfields_dataModel::get()->toArray();
        $i = 0;
        $finalfieldsData = array();
        foreach ($fieldsData as $key => $value) {
            $finalfieldsData[$value['field_id']][$i]['display_text'] = $value['display_text'];
            $finalfieldsData[$value['field_id']][$i]['display_value'] = $value['display_value'];
            $i++;
        }

        if (count($finalfieldsData) > 0) {
            $res['data_fields'] = $finalfieldsData ?? [];
        }

        // auto increament 20-04-24
        $empCode = DB::table('tbluser')->where('id', $id)->first();
        /* //Hide By Rajesh 19-11-2024 : Edit time not max+1 in emp_no (provide Add time only) 
        if(!isset($empCode->employee_no) || $empCode->employee_no=='' || $empCode->employee_no==null){
            $maxEmpCode = DB::table('tbluser')->selectRaw("MAX(CAST(employee_no AS INT)) AS new_emp_code")
            ->where('sub_institute_id', $sub_institute_id)->whereRaw('employee_no is not null')->limit(1)->orderBy('id')->get()->toArray();

            $maxEmpCode = array_map(function ($value) {
                    return (array) $value;
                }, $maxEmpCode);

            $new_emp_code = ($maxEmpCode['0']['new_emp_code'] + 1) ?? 1;
        }else{
            $new_emp_code = $empCode->employee_no ? $empCode->employee_no : 1;
        }
        */
        $new_emp_code = $empCode->employee_no;

        $res['qualificationList'] = tbluserModel::where('sub_institute_id', $sub_institute_id)->where('status', 1)->whereNotNull('qualification')->groupBy('qualification')->pluck('qualification');

        $res['occupationList'] = tbluserModel::where('sub_institute_id', $sub_institute_id)->where('status', 1)->whereNotNull('occupation')->groupBy('occupation')->pluck('occupation');

        $res['documentTypeLists'] = DB::table('student_document_type')->where('status', 1)->where('user_type', 'staff')->get()->toArray();
        $res['documentLists'] = DB::table('staff_document')->select('staff_document.*', 'd.document_type')
            ->join('student_document_type as d', 'd.id', 'staff_document.document_type_id')
            ->where(['sub_institute_id' => $sub_institute_id, 'user_id' => $id])
            ->get()
            ->toArray();
        // end  20-04-24

        $departments = DB::table('hrms_departments')->where('sub_institute_id', $sub_institute_id)->where('status', 1)->get()->toArray();
        if (isset($editData['id'])) {
            $editData['userDepartment'] = $editData['userJobrole'] = '';
            if (isset($editData['department_id'])) {
                $editData['userDepartment'] = DB::table('hrms_departments')->where('sub_institute_id', $sub_institute_id)->where('status', 1)->where('id', $editData['department_id'])->value('department');
            }
            if (isset($editData['allocated_standards'])) {
                $editData['userJobrole'] = skillJobroleMap::where('sub_institute_id', $sub_institute_id)->where('id', $editData['allocated_standards'])->value('jobrole');
            }
        }
        // echo "<pre>";print_r($editData->id);exit;
        // start 29-07-2024
        $masterSetups = []; //DB::table('master_setup_select')->select('type','fieldname',DB::raw('GROUP_CONCAT(fieldValue SEPARATOR "||") as selOptions'))->where('sub_institute_id',$sub_institute_id)->groupBy('type')->get()->toArray();
        $pluckedData = [];
        foreach ($masterSetups as $setup) {
            if (!isset($pluckedData[$setup->type])) {
                $pluckedData[$setup->type] = [];
            }
            $pluckedData[$setup->type]['fieldname'] = $setup->fieldname;
            $pluckedData[$setup->type]['fieldvalue'] = $setup->selOptions; // array ['skills']['select skill']=skill1 || skill 2 || skill 3
        }
        // end 29-07-2024

        // 29-10-2024 salary data
        $payrollTypes = []; //DB::table('payroll_types')->where(['sub_institute_id'=>$sub_institute_id,'status'=>1])->get()->toArray();
        // get type id of salary deposite
        $SalaryDeposit = [];
        $getSalaryDeposit = []; //DB::table('payroll_types')->where(['sub_institute_id'=>$sub_institute_id,'payroll_name'=>'Salary Deposit'])->first();
        if (!empty($getSalaryDeposit)) {
            // get employee salary structure to get amount
            $depositData = DB::table('hrms_emp_payroll_deduction')
                ->where(['sub_institute_id' => $sub_institute_id, 'employee_id' => $id, 'deduction_type' => $getSalaryDeposit->id])
                ->where('deduction_amount', '>', 0)
                ->orderByRaw("FIELD(month, 'Apr','May','Jun', 'Jul', 'Aug','Sep','Oct','Nov','Dec','Jan','Feb','Mar')")
                ->get()
                ->toArray();

            foreach ($depositData as $key => $value) {
                $depositArr = [
                    'year' => $value->year,
                    'month' => $value->month,
                    'amount' => $value->deduction_amount,
                ];
                $SalaryDeposit[] = $depositArr;
            }
            // echo "<pre>";print_r($SalaryDeposit);exit;
        }
        // get year wise salary data
        $SalaryStructure = []; //DB::table('employee_salary_structures')->where(['sub_institute_id'=>$sub_institute_id,'employee_id'=>$id])->orderBy('id','DESC')->get()->toArray();

        $res['payroll_types'] = $payrollTypes;
        $res['salary_deposit'] = $SalaryDeposit;
        $res['salary_structure'] = $SalaryStructure;
        // 29-10-2024 end
        $res['masterSetups'] = $pluckedData;
        $res['departments'] = $departments;
        $res['employees'] = tbluserModel::where('sub_institute_id', $sub_institute_id)->get();
        $res['job_titles'] = []; //HrmsJobTitle::where('sub_institute_id',$sub_institute_id)->get();
        $res['custom_fields'] = $dataCustomFields;
        $res['subject_data'] = $subject_data;
        $res['userLevelOfResponsibility'] = $userLevelOfResponsibility;
        $res['user_profiles'] = $data;
        $res['new_emp_code'] = $new_emp_code;
        // db::enableQueryLog();
        $res['contactDetails'] =  [];
        // dd(db::getQueryLog($res['contactDetails']));
        $res['data'] = $editData;
        // 10-01-2025 start supervisor rights
        $res['jobroleList'] = userJobroleModel::where('sub_institute_id', $sub_institute_id)->whereNull('deleted_at')->get()->toArray();
        $user_id = $id;
        $profileDetails = DB::table('tbluserprofilemaster')->where('id', $editData['user_profile_id'])->first();
        $user_profile_name = $profileDetails->name ?? '';
        // echo "<pre>";print_r($profileDetails);exit;

        $res['skills'] = $skills = []; //skillJobroleMap::join('s_users_skills', 's_user_skill_jobrole.skill', '=', 's_users_skills.title')->whereNull('s_user_skill_jobrole.deleted_at')
        //     ->select('*', 's_users_skills.id as skill_id', 's_user_skill_jobrole.proficiency_level as proficiency_level')
        //     ->groupBy('s_user_skill_jobrole.id')
        //     ->get()->map(function ($item) {
        //         // Load knowledge and ability from the classification table
        //         $classificationItems = DB::table('s_skill_knowledge_ability')
        //             ->where('skill_id', $item->skill_id)
        //             ->where('proficiency_level', $item->proficiency_level) // or dynamic if needed
        //             ->get()
        //             ->groupBy('classification');

        //         $item->knowledge = $classificationItems->has('knowledge')
        //             ? $classificationItems['knowledge']->pluck('classification_item')->toArray()
        //             : [];

        //         $item->ability = $classificationItems->has('ability')
        //             ? $classificationItems['ability']->pluck('classification_item')->toArray()
        //             : [];

        //         return $item;
        //     });
        
        // echo "<pre>";print_r($res['skills']);exit;
        $res['completedCount'] = $completedCount = 0;// matrix::where('user_id', $user_id)->count();
        $res['totalSkills'] = $totalSkills = 0;//$skills->count();
        $progress = 0;//$totalSkills > 0 ? round(($completedCount / $totalSkills) * 100) : 0;
        $res['progress'] = $progress;
        $res['userRatedSkills'] = matrix::join('s_users_skills', 's_users_skills.id', '=', 's_skill_matrix.skill_id')
            ->where('s_skill_matrix.user_id', $id)
            ->get()->toArray();
        // echo "<pre>";print_r($res['userRatedSkills']);exit;
        $res['jobroleSkills'] = $res['jobroleTasks'] = [];
        // if (!in_array($user_profile_name, ['Admin', 'Supervisor'])) {

        $assignedJobrole = userJobroleModel::where('sub_institute_id', $sub_institute_id)->where('id', $editData['allocated_standards'])->whereNull('deleted_at')->first();
        // echo "<pre>";print_r($assignedJobrole);exit;

        if (isset($assignedJobrole)) {
            $alreadyRated = matrix::where('user_id', $user_id)->get()->toArray();
            $ratedIds = [];
            foreach ($alreadyRated as $rated) {
                $ratedIds[] = $rated['skill_id'] ?? 0;
            }
            $res['skills'] = skillJobroleMap::with([
                    'userSkills'=> function($query) use($ratedIds) {
                        $query->whereNotIn('id', $ratedIds);
                    }
                ])
                ->where('jobrole', $assignedJobrole->jobrole)
                ->whereNull('deleted_at')
                // ->whereNotIn('skill_id', $ratedIds)
                ->groupBy('id')
                ->get()
                ->map(function ($item) {
                    $classificationItems = DB::table('s_skill_knowledge_ability')
                                ->where('skill_id', $item->userSkills->id ?? null)
                                ->where('proficiency_level', $item->proficiency_level) // or dynamic if needed
                                ->get()
                                ->groupBy('classification');
                    return [
                        'jobrole_skill_id' => $item->id,
                        'jobrole' => $item->jobrole,
                        'skill' => $item->skill,
                        'skill_id' => $item->userSkills->id ?? null,
                        'title' => $item->userSkills->title ?? null,
                        'category' => $item->userSkills->category ?? null,
                        'sub_category' => $item->userSkills->sub_category ?? null,
                        'description' => $item->userSkills->description ?? null,
                        'proficiency_level' => $item->proficiency_level,
                        'knowledge' => $classificationItems->has('knowledge')
                                ? $classificationItems['knowledge']->pluck('classification_item')->toArray()
                                : [],
                        'ability' => $classificationItems->has('ability')
                                ? $classificationItems['ability']->pluck('classification_item')->toArray()
                                : [],
                    ];
                });

            // $res['jobroleSkills'] = skillJobroleMap::join('s_users_skills', 's_user_skill_jobrole.skill', '=', 's_users_skills.title')
            //     ->where('s_user_skill_jobrole.jobrole', $assignedJobrole->jobrole)
            //     ->whereNull('s_user_skill_jobrole.deleted_at')
            //     ->select(
            //         's_user_skill_jobrole.id as jobrole_skill_id',
            //         's_user_skill_jobrole.jobrole',
            //         's_user_skill_jobrole.skill',
            //         's_users_skills.id as skill_id',
            //         's_user_skill_jobrole.proficiency_level as proficiency_level',
            //         's_users_skills.title',
            //         's_users_skills.category',
            //         's_users_skills.sub_category',
            //         's_users_skills.description'
            //     )
            //     ->groupBy(['s_user_skill_jobrole.id', 's_users_skills.proficiency_level'])
            //     ->get()
            //     ->map(function ($item) {
            //         // Load knowledge and ability from the classification table
            //         $classificationItems = DB::table('s_skill_knowledge_ability')
            //             ->where('skill_id', $item->skill_id)
            //             ->where('proficiency_level', $item->proficiency_level) // or dynamic if needed
            //             ->get()
            //             ->groupBy('classification');

            //         $item->knowledge = $classificationItems->has('knowledge')
            //             ? $classificationItems['knowledge']->pluck('classification_item')->toArray()
            //             : [];

            //         $item->ability = $classificationItems->has('ability')
            //             ? $classificationItems['ability']->pluck('classification_item')->toArray()
            //             : [];

            //         return $item;
            //     });

            $res['jobroleSkills'] = skillJobroleMap::with('userSkills')
                ->where('jobrole', $assignedJobrole->jobrole)
                ->whereNull('deleted_at')
                ->groupBy('id')
                ->get()
                ->map(function ($item) {
                    // Initialize a new object/array to hold the mapped data
                    $mappedItem = new \stdClass(); // or use an array: $mappedItem = [];
                    
                    $classificationItems = DB::table('s_skill_knowledge_ability')
                        ->where('skill_id', $item->userSkills->id ?? null)
                        ->where('proficiency_level', $item->proficiency_level)
                        ->get()
                        ->groupBy('classification');
                    
                    // Assign properties to the new object
                    $mappedItem->jobrole_skill_id = $item->id;
                    $mappedItem->jobrole = $item->jobrole;
                    $mappedItem->skill = $item->skill;
                    $mappedItem->skill_id = $item->userSkills->id ?? null;
                    $mappedItem->title = $item->userSkills->title ?? null;
                    $mappedItem->category = $item->userSkills->category ?? null;
                    $mappedItem->sub_category = $item->userSkills->sub_category ?? null;
                    $mappedItem->description = $item->userSkills->description ?? null;
                    $mappedItem->proficiency_level = $item->proficiency_level;
                    $mappedItem->knowledge = $classificationItems->has('knowledge')
                        ? $classificationItems['knowledge']->pluck('classification_item')->toArray()
                        : [];
                    $mappedItem->ability = $classificationItems->has('ability')
                        ? $classificationItems['ability']->pluck('classification_item')->toArray()
                        : [];
                    
                    return $mappedItem;
                });


            $res['totalSkills'] = skillJobroleMap::where('jobrole', $assignedJobrole->jobrole)->count();
                // DB::enableQueryLog();
            // $res['jobroleTasks'] = DB::table('s_user_jobrole_task as a')
            //     ->join('s_user_skill_jobrole as b', 'b.jobrole', '=', 'a.jobrole')
            //     ->where('a.jobrole', $assignedJobrole->jobrole)
            //     ->whereNull('a.deleted_at')
            //     ->groupBy('task')
            //     ->get();
            $res['jobroleTasks'] = userJobroleTask::with('jobroleSkillModel')
             ->where('jobrole', $assignedJobrole->jobrole)
                ->whereNull('deleted_at')
                ->groupBy('task')
                ->get();
                // dd(DB::getQueryLog($res['jobroleTasks']));
        }


        // }
        $detailsLevel = SLevelResponsibility::where('level', $editData['subject_ids'])->get()->toArray();
        $allLevels = $attrData = [];
        foreach ($detailsLevel as $key => $value) {
            $allLevels[$value['level']] = $value;
            if ($value['attribute_type'] != 'Business skills/Behavioural factors') {
                $attrData[$value['level']][$value['attribute_type']][$value['attribute_name']] = $value;
            } else {
                $attrData[$value['level']]['Business_skills'][$value['attribute_name']] = $value;
            }
        }
        $res['usersLevelData']['levelsData'] = array_values($allLevels);
        $res['usersLevelData']['attrData'] = $attrData;
        $res['usersLevelData']['allData'] = $detailsLevel;
        $res['levelOfResponsbility'] = SLevelResponsibility::groupBy('level')->get()->toArray();
        // echo "<pre>";print_r($res['skills']);exit;
        return is_mobile($type, "user/edit_user", $res, "view");
    }

    public function update(Request $request, $id)
    {
        // return $request;exit;

        if (!$request->monday) {
            $request->request->add(['monday' => 0]);
        }
        if (!$request->tuesday) {
            $request->request->add(['tuesday' => 0]);
        }
        if (!$request->wednesday) {
            $request->request->add(['wednesday' => 0]);
        }
        if (!$request->thursday) {
            $request->request->add(['thursday' => 0]);
        }
        if (!$request->friday) {
            $request->request->add(['friday' => 0]);
        }
        if (!$request->saturday) {
            $request->request->add(['saturday' => 0]);
        }
        if (!$request->sunday) {
            $request->request->add(['sunday' => 0]);
        }
        $sub_institute_id = $request->session()->get('sub_institute_id');
        $type = $request->input('type');
        // echo "<pre>";print_r($request->all());exit;
        $file_name = "";
        if ($request->hasFile('user_image')) {
            $file = $request->file('user_image');
            $originalname = $file->getClientOriginalName();
            $name = $request->get('user_name') . date('YmdHis');
            $ext = File::extension($originalname);
            $file_name = $name . '.' . $ext;
            // $path = $file->storeAs('public/user/', $file_name);
            Storage::disk('digitalocean')->putFileAs('public/hp_user/', $file, $file_name, 'public');
        }
        if ($file_name != "") {
            $request->request->add(['image' => $file_name]); //add request
            $request->session()->put('image', $file_name);
        }

        $request->request->add(['id' => $id]); //add request
        $user_id = $id;

        $data = $this->updateData($request,$id);

        $res['status_code'] = "1";
        $res['message'] = "User updated successfully";
        $res['data'] = $data;

        return is_mobile($type, "add_user.index", $res);
    }

    public function destroy(Request $request, $id)
    {
        $user = [
            'status' => "0",
            'deleted_by' => session()->get('user_id'),
            'deleted_at' => now(),
        ];
        $type = $request->input('type');
        tbluserModel::where(["id" => $id])->update($user);

        $res['status_code'] = "1";
        $res['message'] = "User deleted successfully";

        return is_mobile($type, "add_user.index", $res);
    }

    public function deactiveUser(Request $request, $id)
    {
        $user = [
            'status' => "0",
            'deleted_by' => session()->get('user_id'),
            'deleted_at' => now(),
        ];
        $type = $request->input('type');
        tbluserModel::where(["id" => $id])->update($user);
        $res['status_code'] = "1";
        $res['message'] = "User deleted successfully";

        return is_mobile($type, "add_user.index", $res);
    }


    public function teacherListAPI(Request $request)
    {

        // try {
        //           if (!$this->jwtToken()->validate()) {
        //               $response = array('status' => '2', 'message' => 'Token Auth Failed', 'data' => array());
        //               return response()->json($response, 401);
        //           }
        //       } catch (\Exception $e) {
        //           $response = array('status' => '2', 'message' => $e->getMessage(), 'data' => array());
        //           return response()->json($response, 401);
        //       }

        $type = $request->input("type");
        $sub_institute_id = $request->input("sub_institute_id");


        if ($sub_institute_id != "") {
            $data = DB::table("tbluser as u")
                ->join('tbluserprofilemaster as up', function ($join) {
                    $join->whereRaw("up.id = u.user_profile_id AND up.name = 'Teacher'");
                })
                ->selectRaw("u.id,concat_ws(' ',u.first_name,u.middle_name,u.last_name) as teacher_name,
					    u.email,u.mobile,u.user_profile_id,up.name as user_group")
                ->where("u.sub_institute_id", "=", $sub_institute_id)
                ->orderBy('u.id')
                ->get()->toArray();

            $res['status_code'] = 1;
            $res['message'] = "Success";
            $res['data'] = $data;
        } else {
            $res['status_code'] = 0;
            $res['message'] = "Parameter Missing";
        }

        return json_encode($res);
    }

    function addUserDocument(Request $request, $id)
    {
        $type = $request->type;
        $document = $request->document;
        $doc_type = $request->document_type_id;
        $document_title = $request->document_title;
        $sub_institute_id = session()->get('sub_institute_id');
        if ($type == "API") {
            $sub_institute_id = $request->sub_institute_id;
        }
        $filename = '';
        if ($request->hasFile('document')) {
            $file = $request->file('document');
            $originalname = $file->getClientOriginalName();
            $name = $id . date('YmdHis');
            $ext = File::extension($originalname);
            $file_name = $name . '.' . $ext;
            // $path = $file->storeAs('public/student_document/', $file_name);
            Storage::disk('digitalocean')->putFileAs('public/hp_staff_document/', $file, $file_name, 'public');
        }

        $data = [
            'user_id'          => $id,
            'document_title'   => $request->get('document_title'),
            'document_type_id' => $request->get('document_type_id'),
            'file_name'        => $file_name,
            'sub_institute_id' => $sub_institute_id,
            'created_at'       => now(),
        ];

        $insert = DB::table('staff_document')->insert($data);

        if ($insert) {
            $res['success'] = 1;
            $res['message'] = "Document Added successfully";
        } else {
            $res['fail'] = 0;
            $res['message'] = "Failed to Add Document";
        }

        return is_mobile($type, "add_user.index", $res);
    }

    // show employee dtails for user profile
    public function show(Request $request, $id)
    {
        $type = $request->input('type');
        $userLevelOfResponsibility = array();

        if ($type == "API") {
            $validator = Validator::make($request->all(), [
                'sub_institute_id' => 'required|numeric',
                'syear' => 'required|numeric',
                'type' => 'required',
            ]);

            if ($validator->fails()) {
                $res['status'] = '0';
                $res['message'] = $validator->messages()->first();
                return is_mobile($type, "add_user.index", $res);
            }
            $sub_institute_id = $request->input('sub_institute_id');
            $syear = $request->input('syear');
        } else {
            $sub_institute_id = $request->session()->get('sub_institute_id');
            $syear = session()->get('syear');
        }

        $editData = tbluserModel::find($id)->toArray();
        $data = tbluserprofilemasterModel::where(['sub_institute_id' => $sub_institute_id])->get()->toArray();
        $dataCustomFields = tblcustomfieldsModel::where([
            'sub_institute_id' => $sub_institute_id,
            'status' => "1",
            'table_name' => "tbluser",
            "user_type" => ""
        ])->get();


        $fieldsData = tblfields_dataModel::get()->toArray();
        $i = 0;
        $finalfieldsData = array();
        foreach ($fieldsData as $key => $value) {
            $finalfieldsData[$value['field_id']][$i]['display_text'] = $value['display_text'];
            $finalfieldsData[$value['field_id']][$i]['display_value'] = $value['display_value'];
            $i++;
        }

        if (count($finalfieldsData) > 0) {
            $res['data_fields'] = $finalfieldsData ?? [];
        }
        $res['documentTypeLists'] = DB::table('student_document_type')->where('status', 1)->where('user_type', 'staff')->get()->toArray();
        $res['documentLists'] = DB::table('staff_document')->select('staff_document.*', 'd.document_type')
            ->join('student_document_type as d', 'd.id', 'staff_document.document_type_id')
            ->where(['sub_institute_id' => $sub_institute_id, 'user_id' => $id])
            ->get()
            ->toArray();
        // end  20-04-24

        $departments = DB::table('hrms_departments')->where('sub_institute_id', $sub_institute_id)->where('status', 1)->get()->toArray();
        if (isset($editData['id'])) {
            $editData['userDepartment'] = $editData['userJobrole'] = '';
            if (isset($editData['department_id'])) {
                $editData['userDepartment'] = DB::table('hrms_departments')->where('sub_institute_id', $sub_institute_id)->where('status', 1)->where('id', $editData['department_id'])->value('department');
            }
            if (isset($editData['allocated_standards'])) {
                $editData['userJobrole'] = skillJobroleMap::where('sub_institute_id', $sub_institute_id)->where('id', $editData['allocated_standards'])->value('jobrole');
            }
        }
        // 29-10-2024 salary data
        $payrollTypes = []; //DB::table('payroll_types')->where(['sub_institute_id'=>$sub_institute_id,'status'=>1])->get()->toArray();
        // get type id of salary deposite
        $SalaryDeposit = [];
   
        // get year wise salary data
        $SalaryStructure = []; //DB::table('employee_salary_structures')->where(['sub_institute_id'=>$sub_institute_id,'employee_id'=>$id])->orderBy('id','DESC')->get()->toArray();

  
        // 29-10-2024 end
        $res['departments'] = $departments;
        $res['job_titles'] = []; //HrmsJobTitle::where('sub_institute_id',$sub_institute_id)->get();
        $res['custom_fields'] = $dataCustomFields;
        $res['userLevelOfResponsibility'] = $userLevelOfResponsibility;
        $res['user_profiles'] = $data;
        // db::enableQueryLog();
        $res['contactDetails'] =  [];
        // dd(db::getQueryLog($res['contactDetails']));
        $res['data'] = $editData;
        // 10-01-2025 start supervisor rights
        $res['jobroleList'] = userJobroleModel::where('sub_institute_id', $sub_institute_id)->whereNull('deleted_at')->get()->toArray();
        $user_id = $id;
        $profileDetails = DB::table('tbluserprofilemaster')->where('id', $editData['user_profile_id'])->first();
        $user_profile_name = $profileDetails->name ?? '';
        // echo "<pre>";print_r($profileDetails);exit;

        $res['skills'] = $skills = skillJobroleMap::join('s_users_skills', 's_user_skill_jobrole.skill', '=', 's_users_skills.title')->whereNull('s_user_skill_jobrole.deleted_at')
            ->select('*', 's_users_skills.id as skill_id', 's_user_skill_jobrole.proficiency_level as proficiency_level')
            ->groupBy('s_user_skill_jobrole.id')
            ->get()->map(function ($item) {
                // Load knowledge and ability from the classification table
                $classificationItems = DB::table('s_skill_knowledge_ability')
                    ->where('skill_id', $item->skill_id)
                    ->where('proficiency_level', $item->proficiency_level) // or dynamic if needed
                    ->get()
                    ->groupBy('classification');

                $item->knowledge = $classificationItems->has('knowledge')
                    ? $classificationItems['knowledge']->pluck('classification_item')->toArray()
                    : [];

                $item->ability = $classificationItems->has('ability')
                    ? $classificationItems['ability']->pluck('classification_item')->toArray()
                    : [];

                return $item;
            });
        // echo "<pre>";print_r($res['skills']);exit;
        $res['completedCount'] = $completedCount = matrix::where('user_id', $user_id)->count();
        $res['totalSkills'] = $totalSkills = $skills->count();
        $progress = $totalSkills > 0 ? round(($completedCount / $totalSkills) * 100) : 0;
        $res['progress'] = $progress;
        $res['userRatedSkills'] = matrix::join('s_users_skills', 's_users_skills.id', '=', 's_skill_matrix.skill_id')
            ->where('s_skill_matrix.user_id', $id)
            ->get()->toArray();
        // echo "<pre>";print_r($res['userRatedSkills']);exit;
        $res['jobroleSkills'] = $res['jobroleTasks'] = [];
        // if (!in_array($user_profile_name, ['Admin', 'Supervisor'])) {

        $assignedJobrole = userJobroleModel::where('sub_institute_id', $sub_institute_id)->where('id', $editData['allocated_standards'])->whereNull('deleted_at')->first();
        // echo "<pre>";print_r($assignedJobrole);exit;

        if (isset($assignedJobrole)) {
            $alreadyRated = matrix::where('user_id', $user_id)->get()->toArray();
            $ratedIds = [];
            foreach ($alreadyRated as $rated) {
                $ratedIds[] = $rated['skill_id'] ?? 0;
            }
            $res['skills'] = skillJobroleMap::join('s_users_skills', 's_user_skill_jobrole.skill', '=', 's_users_skills.title')
                ->where('s_user_skill_jobrole.jobrole', $assignedJobrole->jobrole)
                ->whereNull('s_user_skill_jobrole.deleted_at')
                ->whereNotIn('s_users_skills.id', $ratedIds)
                ->select(
                    's_user_skill_jobrole.id as jobrole_skill_id',
                    's_user_skill_jobrole.jobrole',
                    's_user_skill_jobrole.skill',
                    's_users_skills.id as skill_id',
                    's_users_skills.title',
                    's_users_skills.category',
                    's_users_skills.sub_category',
                    's_users_skills.description',
                    's_user_skill_jobrole.proficiency_level as proficiency_level',
                )
                ->groupBy('s_user_skill_jobrole.id')
                ->get()->map(function ($item) {
                    // Load knowledge and ability from the classification table
                    $classificationItems = DB::table('s_skill_knowledge_ability')
                        ->where('skill_id', $item->skill_id)
                        ->where('proficiency_level', $item->proficiency_level) // or dynamic if needed
                        ->get()
                        ->groupBy('classification');

                    $item->knowledge = $classificationItems->has('knowledge')
                        ? $classificationItems['knowledge']->pluck('classification_item')->toArray()
                        : [];

                    $item->ability = $classificationItems->has('ability')
                        ? $classificationItems['ability']->pluck('classification_item')->toArray()
                        : [];

                    return $item;
                });

            $res['jobroleSkills'] = skillJobroleMap::join('s_users_skills', 's_user_skill_jobrole.skill', '=', 's_users_skills.title')
                ->where('s_user_skill_jobrole.jobrole', $assignedJobrole->jobrole)
                ->whereNull('s_user_skill_jobrole.deleted_at')
                ->select(
                    's_user_skill_jobrole.id as jobrole_skill_id',
                    's_user_skill_jobrole.jobrole',
                    's_user_skill_jobrole.skill',
                    's_users_skills.id as skill_id',
                    's_user_skill_jobrole.proficiency_level as proficiency_level',
                    's_users_skills.title',
                    's_users_skills.category',
                    's_users_skills.sub_category',
                    's_users_skills.description'
                )
                ->groupBy(['s_user_skill_jobrole.id', 's_users_skills.proficiency_level'])
                ->get()
                ->map(function ($item) {
                    // Load knowledge and ability from the classification table
                    $classificationItems = DB::table('s_skill_knowledge_ability')
                        ->where('skill_id', $item->skill_id)
                        ->where('proficiency_level', $item->proficiency_level) // or dynamic if needed
                        ->get()
                        ->groupBy('classification');

                    $item->knowledge = $classificationItems->has('knowledge')
                        ? $classificationItems['knowledge']->pluck('classification_item')->toArray()
                        : [];

                    $item->ability = $classificationItems->has('ability')
                        ? $classificationItems['ability']->pluck('classification_item')->toArray()
                        : [];

                    return $item;
                });


            $res['totalSkills'] = skillJobroleMap::where('jobrole', $assignedJobrole->jobrole)->count();
                // DB::enableQueryLog();
            // $res['jobroleTasks'] = DB::table('s_user_jobrole_task as a')
            //     ->join('s_user_skill_jobrole as b', 'b.jobrole', '=', 'a.jobrole')
            //     ->where('a.jobrole', $assignedJobrole->jobrole)
            //     ->whereNull('a.deleted_at')
            //     ->groupBy('task')
            //     ->get();

          $res['jobroleTasks'] = userJobroleTask::with('jobroleSkillModel')
             ->where('jobrole', $assignedJobrole->jobrole)
                ->whereNull('deleted_at')
                ->groupBy('task')
                ->get();
                // dd(DB::getQueryLog($res['jobroleTasks']));
        }


        // }
        $detailsLevel = SLevelResponsibility::where('level', $editData['subject_ids'])->get()->toArray();
        $allLevels = $attrData = [];
        foreach ($detailsLevel as $key => $value) {
            $allLevels[$value['level']] = $value;
            if ($value['attribute_type'] != 'Business skills/Behavioural factors') {
                $attrData[$value['level']][$value['attribute_type']][$value['attribute_name']] = $value;
            } else {
                $attrData[$value['level']]['Business_skills'][$value['attribute_name']] = $value;
            }
        }
        $res['usersLevelData']['levelsData'] = array_values($allLevels);
        $res['usersLevelData']['attrData'] = $attrData;
        $res['usersLevelData']['allData'] = $detailsLevel;
        $res['levelOfResponsbility'] = SLevelResponsibility::groupBy('level')->get()->toArray();
        // echo "<pre>";print_r($res['skills']);exit;
        return is_mobile($type, "user/edit_user", $res, "view");
    }
}
