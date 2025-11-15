<?php

namespace App\Http\Controllers\front_desk;

use App\Http\Controllers\Controller;
use App\Models\front_desk\taskModel;
use App\Models\user\tbluserModel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use function App\Helpers\is_mobile;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Validator;

class taskController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(Request $request)
    {
        // return $request;
        $type = $request->input("type");
        $from_date = $request->input("from_date");
        $to_date = $request->input("to_date");
        $sub_institute_id = $request->session()->get("sub_institute_id");
        $syear = $request->session()->get("syear");
        $user_profile_name = $request->session()->get("user_profile_name");
        $user_id = $request->session()->get("user_id");
        $taskType = $request->taskType;

        if ($type == "API") {
            $sub_institute_id = $request->get("sub_institute_id");
            $syear = $request->get("syear");
            $user_profile_name = $request->get("user_profile_name");
            $user_id = $request->get("user_id");
        }

        // DB::enableQueryLog();
        $data = DB::table("task as t")
            ->join('tbluser as u', function ($join) use ($sub_institute_id) {
                $join->whereRaw("t.TASK_ALLOCATED = u.id AND u.sub_institute_id = '" . $sub_institute_id . "'")->where('u.status', 1); // 23-04-24 by uma
            })
            ->join('tbluser as u1', function ($join) use ($sub_institute_id) {
                $join->whereRaw("t.CREATED_BY = u1.id AND u1.sub_institute_id = '" . $sub_institute_id . "'")->where('u1.status', 1); // 23-04-24 by uma
            })
            ->join('tbluser as u2', function ($join) use ($sub_institute_id) {
                $join->whereRaw("t.TASK_ALLOCATED_TO = u2.id AND u2.sub_institute_id = '" . $sub_institute_id . "'")->where('u2.status', 1); // 23-04-24 by uma
            })
            ->leftJoin('hrms_departments as hd','hd.id','=','u2.department_id')
            ->leftJoin('s_user_jobrole as uj','uj.id','=','u2.allocated_standards')
            ->leftJoin('tbluser as u3', function ($join) use ($sub_institute_id) {
                $join->whereRaw("t.approved_by = u3.id AND u3.sub_institute_id = '" . $sub_institute_id . "'")->where('u3.status', 1); // 23-04-24 by uma
            })
            ->selectRaw("t.*, CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name) AS manageby, 
            CONCAT_WS(' ',u1.first_name,u1.middle_name,u1.last_name) AS ALLOCATOR,
            CONCAT_WS(' ',u2.first_name,u2.middle_name,u2.last_name) AS ALLOCATED_TO,
            CONCAT_WS(' ',u3.first_name,u3.middle_name,u3.last_name) AS approved_by,
            hd.department,uj.jobrole")
            ->where("t.SYEAR", "=", $syear)->where("t.sub_institute_id", "=", $sub_institute_id)
            ->whereNull('t.deleted_at');

        if (isset($from_date)) {
            $data = $data->where('t.TASK_DATE', '>=', $from_date);
            $res['from_date'] = $from_date;
        }

        if (isset($to_date)) {
            $data = $data->where('t.TASK_DATE', '<=', $from_date);
            $res['to_date'] = $to_date;
        }
        if (isset($taskType)) {
            $data = $data->where('t.task_type', $taskType);
            $res['taskType'] = $taskType;
        }

        if (strtoupper($user_profile_name) != 'ADMIN') {
            $data = $data->whereRaw("(t.TASK_ALLOCATED_TO = '" . $user_id . "' OR t.TASK_ALLOCATED = '" . $user_id . "')");
        }
        $data = $data->orderBy('t.TASK_DATE', 'desc');
        $data = $data->get()->toArray();
        // dd(DB::getQueryLog($data));

        $res['checkList'] = DB::table('task')->selectRaw('*,' . $user_id . ' as user_id')->whereRaw("(TASK_ALLOCATED_TO = '" . $user_id . "' OR TASK_ALLOCATED = '" . $user_id . "')")->where('task_type', '=', 'High')->where('TASK_DATE', date('Y-m-d'))->get()->toArray();

        $res['status_code'] = 1;
        $res['message'] = "Success";
        $res['data'] = $data;

        return is_mobile($type, "front_desk.show_task", $res, "view");
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create(Request $request)
    {
        $type = $request->input("type");
        $sub_institute_id = $request->session()->get("sub_institute_id");
        $syear = $request->session()->get("syear");
        $user_id = $request->session()->get("user_id");

        $users = tbluserModel::where(["sub_institute_id" => $sub_institute_id, 'status' => 1])
            ->whereRaw("id != '" . $user_id . "'")
            ->where('status', 1)
            ->get()
            ->toArray();

        $res['status_code'] = 1;
        $res['message'] = "Success";
        $res['userList'] = $users;
        $res['skillLists'] = DB::table('tblemp_skills')->whereIn('sub_institute_id', [0, $sub_institute_id])->get()->toArray();

        return is_mobile($type, "front_desk.add_task", $res, "view");
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  Request  $request
     * @return Response
     */
    // public function store(Request $request)
    // {
    //     // echo "<pre>";print_r($request->all());exit;
    //     $type = $request->input("type");
    //     if($type=="API"){
    //         $sub_institute_id = $request->sub_institute_id;
    //         $syear = $request->syear;
    //         $term_id = 0;
    //         $user_id = $request->user_id;
    //         $manageby = $request->input("manageby");
    //     }else{
    //         $sub_institute_id = $request->session()->get("sub_institute_id");
    //         $syear = $request->session()->get("syear");
    //         $term_id = $request->session()->get("term_id");
    //         $user_id = $request->session()->get("user_id");
    //         $manageby = $request->session()->get("user_id");
    //     }

    //     $TASK_ALLOCATED_TO = $request->input("TASK_ALLOCATED_TO");
    //     $KRA = $request->input("KRA");
    //     $KPA = $request->input("KPA");
    //     $observation_point = $request->input("observation_point");
    //     $task_type = $request->input("selType");
    //     $required_skill = isset($request->skills) ? implode(',',$request->skills) : '';
    //     // store skills
    //     $dates = $this->getDatesWithoutSundays();
    //     // echo "<pre>";print_r($required_skill);exit;
    //     // if($required_skill!=''){
    //     //     $explodeSkills = explode(',',$required_skill);
    //     //     foreach ($explodeSkills as $id => $skillname) {
    //     //        $checkSkillset = DB::table('tblemp_skills')->where('skills',$skillname)->whereIn('sub_institute_id',[0,$sub_institute_id])->get()->toArray();
    //     //        if(empty($checkSkillset)){
    //     //         DB::table('tblemp_skills')->insert(['sub_institute_id'=>$sub_institute_id,'skills'=>$skillname,'created_at'=>now()]);
    //     //        }
    //     //     }
    //     // }
    //     $data = $request->except(['_method', '_token', 'submit', 'TASK_ATTACHMENT','formName','selDepartment','selSubDepartment','selType','add','type','syear','sub_institute_id','user_id','manageby','KRA','KPA','skills','observation_point','TASK_DATE']);

    //     $file_name = $ext = $file_size = "";
    //     if ($request->hasFile('TASK_ATTACHMENT')) {
    //         $file = $request->file('TASK_ATTACHMENT');
    //         $originalname = $file->getClientOriginalName();
    //         $file_size = $file->getSize();
    //         $name = "task_".date('YmdHis');
    //         $ext = File::extension($originalname);
    //         $file_name = $name.'.'.$ext;
    //         $path = $file->storeAs('public/front_desk/', $file_name);
    //     }

    //     foreach ($TASK_ALLOCATED_TO as $key => $value) {
    //         $data['KRA'] = $KRA;
    //         $data['KPA'] = $KPA;
    //         $data['observation_point'] = $observation_point;
    //         $data['task_type'] = $task_type;

    //         $data['SYEAR'] = $syear;
    //         // $data['MARKING_PERIOD_ID'] = $term_id;
    //         $data['CREATED_BY'] = $user_id;
    //         $data['TASK_ALLOCATED'] = $manageby;
    //         $data['TASK_ALLOCATED_TO'] = $value;
    //         $data['STATUS'] ='PENDING';
    //         $data['required_skills'] = $required_skill;
    //         $data['CREATED_IP_ADDRESS'] = $_SERVER['REMOTE_ADDR'];
    //         $data['created_at'] = date('Y-m-d H:i:s');
    //         $data['sub_institute_id'] = $sub_institute_id;

    //         if ($file_name != '') {
    //             $data['TASK_ATTACHMENT'] = $file_name;
    //             $data['FILE_SIZE'] = $file_size;
    //             $data['FILE_TYPE'] = $ext;
    //         }
    //         if($task_type=="High"){
    //             foreach ($dates as $k => $date) {
    //                 $data['TASK_DATE']=$date;
    //                 taskModel::insert($data);
    //             }
    //         }else{
    //             $data['TASK_DATE'] = $request->get('TASK_DATE');
    //             taskModel::insert($data);
    //         }
    //     }

    //     $res['status_code'] = "1";
    //     $res['message'] = "Added successfully";

    //     return is_mobile($type, "task.index", $res);
    // }

    public function store(Request $request)
    {
        try {
            $type = $request->type;

            // if ($type == "API") {
            $sub_institute_id = $request->sub_institute_id;
            $syear = $request->syear;
            $term_id = 0;
            $user_id = $request->user_id;
            $manageby = $request->input("manageby");
            // } else {
            //     $sub_institute_id = $request->session()->get("sub_institute_id");
            //     $syear = $request->session()->get("syear");
            //     $term_id = $request->session()->get("term_id");
            //     $user_id = $request->session()->get("user_id");
            //     $manageby = $user_id;
            // }

            $dates = $this->getDatesWithoutSundays("High");
            $task_type = $request->input('selType', ''); // fallback if not present

            // Prepare file upload
            $file_name = $ext = $file_size = "";
            if ($request->hasFile('TASK_ATTACHMENT')) {
                $file = $request->file('TASK_ATTACHMENT');
                $originalname = $file->getClientOriginalName();
                $file_size = $file->getSize();
                $name = "task_" . date('YmdHis');
                $ext = File::extension($originalname);
                $file_name = $name . '.' . $ext;
                // $path = $file->storeAs('public/hp_task/', $file_name);
                Storage::disk('digitalocean')->putFileAs('public/hp_task/', $file, $file_name, 'public');
            }

            // Common task data
            $baseData = $request->except([
                '_method',
                '_token',
                'token',
                'org_type',
                'formType',
                'submit',
                'TASK_ATTACHMENT',
                'formName',
                'selDepartment',
                'selSubDepartment',
                'selType',
                'add',
                'type',
                'syear',
                'sub_institute_id',
                'user_id',
                'manageby',
                'skills',
                'observation_point',
                'TASK_DATE',
                'employee_id',
                'job_role',
                'repeat_until',
            ]);

            $extraData = [
                'KRA' => $request->input("KRA"),
                'KPA' => $request->input("KPA"),
                'observation_point' => $request->input("observation_point"),
                'task_type' => $task_type,
                'SYEAR' => $syear,
                'TASK_ALLOCATED' => $manageby,
                'STATUS' => 'PENDING',
                'CREATED_IP_ADDRESS' => $request->ip(),
                'created_at' => now(),
                'sub_institute_id' => $sub_institute_id,
            ];

            if ($file_name) {
                $extraData['TASK_ATTACHMENT'] = $file_name;
                $extraData['FILE_SIZE'] = $file_size;
                $extraData['FILE_TYPE'] = $ext;
            }


            if ($request->formType == "single") {
                // 'required_skills' => $request->has('skills') ? implode(',', $request->skills) : '',

                $extraData['TASK_ALLOCATED_TO'] = $request->input("TASK_ALLOCATED_TO");
                $extraData['required_skills'] = $request->input("skills");

                if ($task_type == "High") {
                    $dates = $this->getDatesWithoutSundays("High", $request->task_date, (int)$request->repeat_days);
                    foreach ($dates as $date) {
                        $data = array_merge($baseData, $extraData, ['TASK_DATE' => $date]);
                        $data['created_by'] = $user_id;
                        taskModel::insert($data);
                    }
                } else if ($task_type == "Medium") {
                    $dates = $this->getDatesWithoutSundays('Medium', $request->task_date, (int)$request->repeat_days);
                    foreach ($dates as $date) {
                        $data = array_merge($baseData, $extraData, ['TASK_DATE' => $date]);
                        $data['created_by'] = $user_id;
                        taskModel::insert($data);
                    }
                } else if ($task_type == "Low") {
                    $dates = $this->getDatesWithoutSundays('Low', $request->task_date, (int)$request->repeat_days);
                    foreach ($dates as $date) {
                        $data = array_merge($baseData, $extraData, ['TASK_DATE' => $date]);
                        $data['created_by'] = $user_id;
                        taskModel::insert($data);
                    }
                } else {
                    $data = array_merge($baseData, $extraData, ['TASK_DATE' => now()]);
                    $data['created_by'] = $user_id;
                    taskModel::insert($data);
                }
            } elseif ($request->formType == "multiUser" && !empty($request->TASK_ALLOCATED_TO)) {
                // Handle array of allocated users
                $allocatedUsers = $request->TASK_ALLOCATED_TO ? explode(',', $request->TASK_ALLOCATED_TO) : [];

                foreach ($allocatedUsers as $key => $allocatedUser) {
                    if (!empty($allocatedUser)) {
                        $extraData['TASK_ALLOCATED_TO'] = $allocatedUser;
                        $extraData['required_skills'] = $request->input("skills");

                        if ($task_type == "High") {
                            $dates = $this->getDatesWithoutSundays("High", $request->task_date, (int)$request->repeat_days);
                            foreach ($dates as $date) {
                                $data = array_merge($baseData, $extraData, ['TASK_DATE' => $date]);
                                $data['created_by'] = $user_id;
                                taskModel::insert($data);
                            }
                        } else if ($task_type == "Medium") {
                            $dates = $this->getDatesWithoutSundays('Medium', $request->task_date, (int)$request->repeat_days);
                            foreach ($dates as $date) {
                                $data = array_merge($baseData, $extraData, ['TASK_DATE' => $date]);
                                $data['created_by'] = $user_id;
                                taskModel::insert($data);
                            }
                        } else if ($task_type == "Low") {
                            $dates = $this->getDatesWithoutSundays('Low', $request->task_date, (int)$request->repeat_days);
                            foreach ($dates as $date) {
                                $data = array_merge($baseData, $extraData, ['TASK_DATE' => $date]);
                                $data['created_by'] = $user_id;
                                taskModel::insert($data);
                            }
                        } else {
                            $data = array_merge($baseData, $extraData, ['TASK_DATE' => now()]);
                            $data['created_by'] = $user_id;
                            taskModel::insert($data);
                        }
                    }
                }
            }
            else if($request->formType=="BulkTask" && $request->TASK_ALLOCATED_TO && $request->task_details){
               $insertCount = 0;

                // Convert TASK_ALLOCATED_TO into array
                $allocatedUsers = $request->TASK_ALLOCATED_TO ? explode(',', $request->TASK_ALLOCATED_TO) : [];

                foreach ($allocatedUsers as $allocatedUser) {
                    if (!empty($allocatedUser)) {

                        // Decode task details from request
                        $taskDetails = $request->has('task_details') ? json_decode($request->task_details, true) : [];

                        foreach ($taskDetails as $taskValue) {

                            // Base request data except unwanted fields
                            $baseData = $request->except([
                                '_method', '_token', 'token', 'org_type', 'formType',
                                'submit', 'formName', 'repeat_until', 'task_details',
                                'TASK_ALLOCATED_TO','type','user_id'
                            ]);

                            // Task specific data
                            $taskData = [
                                'task_title'       => $taskValue['task_title'] ?? '',
                                'task_description' => $taskValue['task_description'] ?? '',
                                'repeat_days'      => $taskValue['repeat_days'] ?? 0,
                                'task_allocated'   => $taskValue['task_allocated'] ?? 0,
                                'task_type'        => $taskValue['task_type'] ?? 'Medium',
                                'TASK_DATE'        => $taskValue['TASK_DATE'] ?? date('Y-m-d'),
                                'TASK_ALLOCATED_TO'=> $allocatedUser,
                            ];

                            $task_type = $taskValue['task_type'] ?? 'Medium';
                            $task_date = $taskValue['TASK_DATE'] ?? now()->format('Y-m-d');

                            // Get dates based on task type
                                $dates = $this->getDatesWithoutSundays($task_type, $task_date, (int)$taskValue['repeat_days'] ?? 1);
                          
                            // return $dates;
                            if(!empty($dates)){
                                foreach ($dates as $date) {
                                $data = array_merge($baseData, $taskData, [
                                    'TASK_DATE'  => $date,
                                    'created_by' => $user_id,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                    ]);

                                    // Insert using Eloquent create()
                                    $insert = taskModel::create($data);

                                    if ($insert) {
                                        $insertCount++;
                                    }
                                }
                            }else{
                            $data = array_merge($baseData, $taskData, [
                                    'TASK_DATE'  => $task_date,
                                    'created_by' => $user_id,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);

                                // Insert using Eloquent create()
                                $insert = taskModel::create($data);

                                if ($insert) {
                                    $insertCount++;
                                }
                            }
                            
                        }
                    }
                }

                // Response
                $res['status_code'] = 0;
                $res['message']     = "Failed to Add";

                if ($insertCount > 0) {
                    $res['status_code'] = 1;
                    $res['message']     = "Added successfully";
                }

            } 
             else {
                foreach ($request->input("TASK_ALLOCATED_TO", []) as $value) {
                    $extraData['TASK_ALLOCATED_TO'] = $value;
                    // 'required_skills' => $request->has('skills') ? implode(',', $request->skills) : '',
                    $extraData['required_skills'] = $request->has('skills') ? implode(',', $request->skills) : '';
                    if ($task_type == "High") {
                        foreach ($dates as $date) {
                            $data = array_merge($baseData, $extraData, ['TASK_DATE' => $date]);
                            $data['created_by'] = $user_id;
                            taskModel::insert($data);
                        }
                    } else {
                        $data = array_merge($baseData, $extraData, ['TASK_DATE' => $request->get('TASK_DATE')]);
                        $data['created_by'] = $user_id;
                        taskModel::insert($data);
                    }
                }
            }

            $res =  [
                'status_code' => "1",
                'message' => "Added successfully"
            ];

            return response()->json($res);
        } catch (\Exception $e) {
            return response()->json([
                'status_code' => 0,
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ], 500);
        }
    }


    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return void
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return Application|Factory|View
     */
    public function edit(Request $request, $id)
    {
        $sub_institute_id = $request->session()->get("sub_institute_id");
        $user_id = $request->session()->get("user_id");
        $syear = $request->session()->get("syear");
        $user_profile_name = $request->session()->get("user_profile_name");

        $result = DB::table("task as t")
            ->join('tbluser as u', function ($join) use ($sub_institute_id) {
                $join->whereRaw("t.TASK_ALLOCATED = u.id AND u.sub_institute_id = '" . $sub_institute_id . "'")->where('u.status', 1); // 23-04-24 by uma
            })
            ->join('tbluser as u1', function ($join) use ($sub_institute_id) {
                $join->whereRaw("t.CREATED_BY = u1.id AND u1.sub_institute_id = '" . $sub_institute_id . "'")->where('u1.status', 1); // 23-04-24 by uma
            })
            ->join('tbluser as u2', function ($join) use ($sub_institute_id) {
                $join->whereRaw("t.TASK_ALLOCATED_TO = u2.id AND u2.sub_institute_id = '" . $sub_institute_id . "'")->where('u2.status', 1); // 23-04-24 by uma
            })
            ->selectRaw("t.*, CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name) AS manageby, 
            CONCAT_WS(' ',u1.first_name,u1.middle_name,u1.last_name) AS ALLOCATOR,
            CONCAT_WS(' ',u2.first_name,u2.middle_name,u2.last_name) AS ALLOCATED_TO")
            ->where("t.ID", "=", $id)
            ->get()->toArray();

        $result = array_map(function ($value) {
            return (array) $value;
        }, $result);

        $editData = $result[0];

        $dataResult = ['PENDING', 'IN PROGRESS', 'ON HOLD', 'COMPLETED']; // DB::table("complaint_status")
        // ->where("TYPE", "=", 'TASK')
        // ->get()->toarray();

        // $dataResult = array_map(function ($value) {
        //     return (array) $value;
        // }, $dataResult);

        $taskStatus = $dataResult;
        $editData['skillLists'] = []; // DB::table('tblemp_skills')->whereIn('sub_institute_id',[0,$sub_institute_id])->get()->toArray();

        return view('front_desk/edit_task', ['data' => $editData, 'taskStatus' => $taskStatus]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  Request  $request
     * @param  int  $id
     * @return Response
     */
    public function update(Request $request, $id)
    {
        // return $request;
        $type = $request->input("type");
        if ($type == "API") {
            $sub_institute_id = $request->sub_institute_id;
            $syear = $request->syear;
            $term_id = 0;
            $user_id = $request->user_id;
            $manageby = $request->input("manageby");
        } else {
            $sub_institute_id = $request->session()->get("sub_institute_id");
            $syear = $request->session()->get("syear");
            $term_id = $request->session()->get("term_id");
            $user_id = $request->session()->get("user_id");
            $manageby = $request->session()->get("user_id");
        }

        $TASK_ALLOCATED_TO = $request->input("TASK_ALLOCATED_TO");
        $KRA = $request->input("kra");
        $KPA = $request->input("kpa");
        $task_type = $request->input("selType");
        $required_skill = $request->skills ?? '';
        $observation_point = $request->observation_point;
        // store skills
        if ($request->has('formType') && $request->formType == "approveStatus") {
            $data = $request->except([
                '_method',
                '_token',
                'submit',
                'formType',
                'update',
                'token',
                'user_id',
                'add',
                'type',
                'syear',
                'sub_institute_id',
                'user_id',
                'manageby',
                'KRA',
                'KPA',
                'skills',
                'ALLOCATOR',
                'ALLOCATED_TO',
                'method_field',
                'repeat_days',
                'department',
                'jobrole'
            ]);
            foreach ($data as $key => $value) {
                if ($value === 'null' || $value === '') {
                    $data[$key] = null;
                }
            }

            $file_name = $ext = $file_size = "";
            if ($request->hasFile('TASK_ATTACHMENT')) {
                $file = $request->file('TASK_ATTACHMENT');
                $originalname = $file->getClientOriginalName();
                $file_size = $file->getSize();
                $name = "task_" . date('YmdHis');
                $ext = File::extension($originalname);
                $file_name = $name . '.' . $ext;
                $path = $file->storeAs('public/hp_task/', $file_name);

                $data['TASK_ATTACHMENT'] = $file_name;
                $data['FILE_SIZE'] = $file_size;
                $data['FILE_TYPE'] = $ext;
            }
            $data['updated_by'] = $user_id;
            $data['updated_at'] = now();

            if ($request->has('approve_status') && $request->approve_status !== '') {
                $data['approved_by'] = $user_id;
                $data['approved_on'] = now();
            } else {
                $data['approved_on'] = null;
            }
        } else {
            $data = $request->except(['_method', '_token', 'submit', 'TASK_ATTACHMENT', 'formName', 'selDepartment', 'selSubDepartment', 'selType', 'task_date', 'add', 'type', 'syear', 'sub_institute_id', 'user_id', 'manageby', 'KRA', 'KPA', 'skills','department','jobrole']);

            $data['kra'] = $KRA;
            $data['TASK_DATE'] = Carbon::parse($request->TASK_DATE)->format('Y-m-d');

            $data['kpa'] = $KPA;
            $data['task_type'] = $task_type;
            $data['observation_point'] = $observation_point;

            $data['SYEAR'] = $syear;
            // $data['MARKING_PERIOD_ID'] = $term_id;
            $data['CREATED_IP_ADDRESS'] = $_SERVER['REMOTE_ADDR'];
            $data['updated_at'] = date('Y-m-d H:i:s');
            $data['approved_by'] = $user_id;
            $data['approved_on'] = date('Y-m-d H:i:s');

            $TASK_ALLOCATED_TO = $request->input("TASK_ALLOCATED_TO");

            $file_name = $ext = $file_size = "";
            if ($request->hasFile('TASK_ATTACHMENT')) {
                $file = $request->file('TASK_ATTACHMENT');
                $originalname = $file->getClientOriginalName();
                $file_size = $file->getSize();
                $name = "task_" . date('YmdHis');
                $ext = File::extension($originalname);
                $file_name = $name . '.' . $ext;
                // $path = $file->storeAs('public/hp_task/', $file_name);
                Storage::disk('digitalocean')->putFileAs('public/hp_task/', $file, $file_name, 'public');
            }

            if ($file_name != '') {
                $data['TASK_ATTACHMENT'] = $file_name;
                $data['FILE_SIZE'] = $file_size;
                $data['FILE_TYPE'] = $ext;
            }
        }

        $data = taskModel::where(['id' => $id])->update($data);

        $res['status_code'] = "1";
        $res['message'] = "Updated successfully";

        return is_mobile($type, "task.index", $res);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy(Request $request, $id)
    {
        $type = $request->input('type');
        $user_id = session()->get('user_id');

        if ($type == "API") {
            $user_id = $request->user_id;
        }

        $delete = taskModel::where(["id" => $id])->update([
            'deleted_by' => $user_id,
            'deleted_at' => now(),
        ]);

        if ($delete) {
            $res['status_code'] = "1";
            $res['message'] = "Deleted successfully";
        } else {
            $res['status_code'] = "1";
            $res['message'] = "Deleted successfully";
        }

        return is_mobile($type, "task.index", $res);
    }

    public function taskReportIndex(Request $request)
    {
        $type = $request->input('type');

        $res['status_code'] = 1;
        $res['message'] = "Success";

        return is_mobile($type, "front_desk.task_report", $res, "view");
    }

    // function getDatesWithoutSundays($type="",$task_date='',$repeat_days) {
    //     $startDate = Carbon::now();
    //     $endDate = Carbon::create($startDate->year, $startDate->month)->endOfMonth();  

    //     $dates = [];

    //     if($type=="Medium"){
    //         for ($i = 0; $i < 7; $i++) {
    //             $dates[] = Carbon::today()->addDays($i)->format('Y-m-d');
    //         }
    //     }else if($type=="Low"){

    //     }else{
    //         $period = CarbonPeriod::create($startDate, $endDate);
    //         foreach ($period as $date) {
    //             if ($date->isSunday()) {
    //                 continue;
    //             }
    //             $dates[] = $date->format('Y-m-d');
    //         }
    //     }

    //     return $dates;
    // }

    function getDatesWithoutSundays($type = "", $task_date = '', $repeat_days = 1)
    {
        $startDate = Carbon::now();
        $endDate = $task_date ? Carbon::parse($task_date) : Carbon::create($startDate->year, $startDate->month)->endOfMonth();
        $dates = [];
        // return $task_date;
        if ($type == "High" || $type == "Medium" || $type == "Low") {
            for ($date = $startDate->copy(); $date->lte($endDate); $date->addDays((int)$repeat_days)) {
                if (!$date->isSunday()) {
                    $dates[] = $date->format('Y-m-d');
                }
            }
        } else {

            $period = CarbonPeriod::create($startDate, $endDate);
            foreach ($period as $date) {
                if ($date->isSunday()) continue;
                $dates[] = $date->format('Y-m-d');
            }
        }

        return $dates;
    }


    // added on 19-08-2025 by uma for task analysis report
    public function taskAnalysisReport(Request $request)
    {
        $type = $request->input('type');
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
            'sub_institute_id' => 'required',
            'syear' => 'required',
        ]);

        // If validation fails
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 400);
        }
        $sub_institute_id = $request->input('sub_institute_id');
        $syear = $request->input('syear');

        $taskData = DB::table("task as t")
            ->join('tbluser as u', function ($join) use ($sub_institute_id) {
                $join->whereRaw("t.TASK_ALLOCATED = u.id AND u.sub_institute_id = ?", [$sub_institute_id])
                    ->where('u.status', 1);
            })
            ->join('tbluser as u1', function ($join) use ($sub_institute_id) {
                $join->whereRaw("t.CREATED_BY = u1.id AND u1.sub_institute_id = ?", [$sub_institute_id])
                    ->where('u1.status', 1);
            })
            ->join('tbluser as u2', function ($join) use ($sub_institute_id) {
                $join->whereRaw("t.TASK_ALLOCATED_TO = u2.id AND u2.sub_institute_id = ?", [$sub_institute_id])
                    ->where('u2.status', 1);
            })
            ->leftJoin('tbluser as u3', function ($join) use ($sub_institute_id) {
                $join->whereRaw("t.approved_by = u3.id AND u3.sub_institute_id = ?", [$sub_institute_id])
                    ->where('u3.status', 1);
            })
            ->selectRaw("
            t.*,
            CONCAT_WS(' ', u1.first_name, u1.middle_name, u1.last_name) AS ALLOCATOR,
            CONCAT_WS(' ', u2.first_name, u2.middle_name, u2.last_name) AS ALLOCATED_TO,
            u2.image AS employee_image,
            CONCAT_WS(' ', u3.first_name, u3.middle_name, u3.last_name) AS approved_by
        ")
            ->where("t.SYEAR", $syear)
            ->where("t.sub_institute_id", $sub_institute_id)
            ->whereNull('t.deleted_at')
            ->orderBy('t.TASK_DATE', 'DESC')
            ->get();

        $taskData->map(function ($item) {
            $item->employee_image = $item->employee_image
                ? Storage::disk('digitalocean')->url('public/hp_user/' . $item->employee_image)
                : null;
            return $item;
        });

        $res['status'] = 1;
        $res['message'] = "Success";
        $res['taskData'] = $taskData;
        return response()->json($res);
    }
}
