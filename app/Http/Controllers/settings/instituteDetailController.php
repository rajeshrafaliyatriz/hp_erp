<?php

namespace App\Http\Controllers\settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Models\school_setupModel;
use App\Models\settings\instituteDetailModel;
use Illuminate\Http\Request;
use function App\Helpers\is_mobile;
use function App\Helpers\employeeDetails;
use App\Http\Controllers\HRMS\departmentController;
use App\Http\Controllers\front_desk\taskController;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class instituteDetailController extends Controller
{
    /**
     * G-SEC-29. THE TENANT COMES FROM THE TOKEN.
     *
     * ── WHAT THIS CONTROLLER WAS DOING ──────────────────────────────────────
     *
     * Every API branch read `$request->get('sub_institute_id')`, so the caller
     * chose whose data they received. DEMONSTRATED before the change, with a
     * read and never a write: user #1 of tenant 1, holding a valid tenant-1
     * token, asked for `sub_institute_id=3` and got HTTP 200 with all three of
     * tenant 3's compliance records AND 122 of tenant 3's employees, usernames
     * included - because index() also passes the same value to
     * employeeDetails().
     *
     * `update()` and `destroy()` were worse in a quieter way: they never read a
     * tenant at all and filtered `master_compliance` on `id` alone, so any
     * authenticated user could edit or soft-delete any organisation's
     * compliance record by guessing an id.
     *
     * This is the `session() ?? $request` shape the sibling controller's note
     * describes: it leaks only when there is no session - which is every API
     * call, i.e. every call the product's own frontend makes.
     *
     * The session path is left alone. Blade still serves the old admin UI with a
     * real session, and there the tenant is already the signed-in user's.
     *
     * ONE IMPLEMENTATION, NOT THIRTEEN - apiTenantId() is called directly from
     * ResolvesApiIdentity rather than wrapped per controller. See
     * organizationDetailsController, hardened the same way.
     */
    use ResolvesApiIdentity;


    public function index(Request $request)
    {
        $type = $request->input('type');
        $sub_institute_id = session()->get('sub_institute_id');
        $syear = session()->get('syear');
        $formName = $request->get('formName');

        if(in_array($type,["API","JSON"])){
            $validator = Validator::make($request->all(), [
                'sub_institute_id' => 'required|numeric',
                'syear'    => 'required|numeric',
                'user_id'   => 'required|numeric',
                'formName' => 'required',
            ]);

            // The caller's own organisation, from their token. A different
            // sub_institute_id in the request is ignored rather than refused:
            // a stale value in localStorage is common and must not lock a
            // legitimate user out, and ignoring it is equally safe because it
            // never reaches a query.
            $sub_institute_id = $this->apiTenantId($request);
            $syear = $request->get('syear');
            $user_id = $request->get('user_id');

            if ($validator->fails()) {
                $response['status'] = '0';
                $response['message'] = $validator->messages();
                return response()->json($response);
            } 
        }
        $res = instituteDetailModel::where('sub_institute_id', $sub_institute_id)->first();
        $res['complainceData'] = DB::table('master_compliance as mc')
                                ->select('mc.*',DB::Raw('(SELECT CONCAT_WS(" ",COALESCE(first_name,"-"),COALESCE(middle_name,"-"),COALESCE(last_name,"-")) FROM tbluser WHERE id=mc.assigned_to) as assigned_user'))
                                ->where('mc.sub_institute_id',$sub_institute_id)
                                ->whereNull('mc.deleted_at')->get()->toArray();
        $res['userDetails'] = employeeDetails($sub_institute_id,"",1);

        if($request->has('formName') && $formName=="complaince_library" && in_array($type,["API","JSON"])){
            $response['complainceData'] = $res['complainceData'];
            $response['userDetails'] = $res['userDetails'];
            return is_mobile($type, "settings/add_institute_detail", $response, "view");
        }

        $res['data'] = $this->getData($sub_institute_id);
        // to get datats drom another controllers add type API
        $request->merge(['type'=>'API','sub_institute_id'=>$sub_institute_id,'syear'=>$syear]);
        // get data from department controller
        $departmentController = new departmentController;
        $departmentData = $departmentController->create($request);
        $res['departmentData'] =  $departmentData->getData();
        // echo "<pre>";print_r($res['departmentData']->SubDepartmentList);exit;

        $res['taskManagerLists'] = DB::table('tbluser') ->selectRaw('id,CONCAT_WS(" ",COALESCE(first_name,"-"),COALESCE(middle_name,"-"),COALESCE(last_name,"-")) as name,mobile')->where('sub_institute_id',$sub_institute_id)->where('status',1)->get()->toArray();
        $res['skillLists'] = []; // DB::table('o_net_occupation_detail_skill_summeries')->groupBy('name')->get()->toArray();

        // echo "<pre>";print_r($res['complainceData']);exit;
        return is_mobile($type, "settings/add_institute_detail", $res, "view");
    }

    public function getData($sub_institute_id)
    {
        $data = school_setupModel::select("*")
            ->leftjoin("institute_detail as i", 'school_setup.Id', 'i.sub_institute_id')
            ->where(['school_setup.Id' => $sub_institute_id])
            ->get()->toArray();

        return isset($data[0]) ? $data[0] : [];
    }


    public function store(Request $request)
    {
    //    return  $request; 
        $type = $request->input('type');

        $sub_institute_id = session()->get('sub_institute_id');
        $syear = session()->get('syear');
        $user_id = session()->get('user_id');

        $res['status_code'] = 0;
        $res['message'] = "Something went wrong!!";

        if(in_array($type,["API","JSON"])){
            try {
                // The caller's own organisation, from their token. See the
                // class docblock.
                $sub_institute_id = $this->apiTenantId($request);
                $syear = $request->get('syear');
                $user_id = $request->get('user_id');

                $validator = Validator::make($request->all(), [
                    'sub_institute_id' => 'required|numeric',
                    'syear'    => 'required|numeric',
                    'user_id'   => 'required|numeric',
                    'formName' => 'required',
                ]);
        
                if ($validator->fails()) {
                    $response['status'] = '0';
                    $response['message'] = $validator->messages();
                    return response()->json($response);
                } 
    
            } catch (\Exception $e) {
                $response = ['status' => '2', 'message' => $e->getMessage(), 'data' => []];
    
                return response()->json($response, 401);
            }    
        }
    
        if($request->has('formName') && $request->formName!="organization_details"){ 

             // get data from department controller
             $request1 = $request->merge(['type'=>'API','sub_institute_id'=>$sub_institute_id,'syear'=>$syear]);
             $departmentController = new departmentController;
             $taskController = new taskController;

             if($request->formName=="addDepartment"){
                $departmentData = $departmentController->store($request1);
                $add = $departmentData->getData();
                $res['status_code'] = 1;
                $res['message'] = "Added Successfully!!";
             }
             else if($request->formName=="addTask"){
                // echo "<pre>";print_r($request->all());exit;
                $i=0;
                foreach($request->arr as $k => $val){
                    $attchment = $val['TASK_ATTACHMENT'] ?? '';
                    
                    $user_id = session()->get("user_id");
                    // make new request to send in taskcontroller
                    $newReq = new Request(['TASK_ALLOCATED_TO'=>$val['TASK_ALLOCATED_TO'] ?? [0],'TASK_TITLE'=>$val['TASK_TITLE'],'TASK_DESCRIPTION'=>$val['TASK_DESCRIPTION'],'KRA'=>$val['KRA'],'KPA'=>$val['KPA'],'selType'=>$val['selType'],'TASK_ATTACHMENT'=>$attchment,'manageby'=>$val['manageby'],'skills'=>$val['skills'] ?? [],'TASK_DATE'=>now(),'observation_point'=>$val['observation_point'],'type'=>'API','sub_institute_id'=>$sub_institute_id,'syear'=>$syear,'user_id'=>$user_id]);

                    if ($attchment) {
                        $newReq->files->set('TASK_ATTACHMENT', $attchment);
                    }
                    // add task
                    if(isset($val['TASK_ALLOCATED_TO'])){
                        $taskData = $taskController->store($newReq);
                        // echo "<pre>";print_r($taskData);
                        $add = $taskData->getData();
                        $i++;
                    }
                } 
                
                // exit;
               if($i > 0 ){
                //  exit;
                 $res['status_code'] = 1;
                 $res['message'] = "Added Successfully!!";
               }else{
                 // exit;
                 $res['status_code'] = 0;
                 $res['message'] = "Please Select Atleast one Employees";
               }
               if($type!='API'){
                return redirect('/settings/institute_detail?module=add_task')->with(['data'=>$res]);
               }

             }
             //compliance library start
             elseif($request->formName=="complaince_library"){
                $name = $request->name;
                $description = $request->description;
                $standard_name = $request->standard_name;
                $assigned_to = $request->assigned_to;
                $duedate = $request->duedate;
                $frequency = $request->frequency;
                $custom_frequency_details = $request->custom_frequency_details ?? null;
                $attachment= null;

                if($request->hasFile('attachment')){
                    $img = $request->file('attachment');
                    $filename = $img->getClientOriginalName();
                    $attachment = time().'_'.$filename;
                    Storage::disk('digitalocean')->putFileAs('public/compliance_library/', $img, $attachment, 'public');
                }

                $complainceData = [
                    'name'=>$name,
                    'description'=>$description,
                    'standard_name'=>$standard_name,
                    'assigned_to'=>$assigned_to,
                    'duedate'=> date('Y-m-d',strtotime($duedate)),
                    'attachment'=>$attachment,
                    'frequency'=>$frequency,
                    'custom_frequency_details'=>$custom_frequency_details,
                    'sub_institute_id'=>$sub_institute_id,
                    'created_by'=>$user_id,
                    'created_at'=>now()
                ];
                // echo "<pre>";print_r($complainceData);exit; 

                $insert = DB::table('master_compliance')->insert($complainceData);

                $res['status_code'] = 0;
                $res['message'] = "Failed to Add Details";

                if($insert){
                    $res['status_code'] = 1;
                    $res['message'] = "Details Added Successfully";
                }
                
             }
             //compliance library end
             else{
                $res['status_code'] = 0;
                $res['message'] = "Failed To Add Data";
             }
            //  echo "<pre>";print_r($request->all());exit;
            
        }else{
              $insertData = [
            /*
             * The resolved tenant, not a form field. This is the web branch, so
             * $sub_institute_id came from the session at the top of store() -
             * but it took the value from the request body instead, which a
             * posted form can carry whatever the session says.
             */
            'sub_institute_id' => $sub_institute_id,
            'organization_name' => $request->organization_name,
            'organization_code' => $request->organization_code,
            'organization_type' => $request->organization_type,
            'organization_email' => $request->organization_email,
            'organization_ph_no' => $request->organization_ph_no,
            'organization_website' => $request->organization_website,
            'address' => $request->address,
            'industry_type' => $request->industry_type,
            'registration_number' => $request->registration_number,
            'handler_name' => $request->handler_name,
            'handler_mobile' => $request->handler_mobile,
            'handler_email' => $request->handler_email,
            'total_emp' => $request->total_emp,
            'total_department' => $request->total_department,
            'working_days' => $request->working_days,
            'working_hours' => $request->working_hours,
        ];
            // check Data exists or not 
            $check = instituteDetailModel::where('sub_institute_id', $sub_institute_id)->first();
            
            if(!empty($check) && isset($check->id)){
                $insertData['updated_at']=now();
                $insertData['updated_by']=$request->user_id;
                instituteDetailModel::where('id',$check->id)->update($insertData);
            }else{
                $insertData['created_at']=now();
                $insertData['created_by']=$request->user_id;
                instituteDetailModel::insert($insertData);
            }
            
            $res['status_code'] = 1;
            $res['message'] = "Detail Added Successfully";
            $res['data'] = $this->getData($sub_institute_id);
        }
        
        return is_mobile($type, "institute_detail.index", $res);
    }

    public function edit(Request $request, $id)
    {
        //
    }

    public function update(Request $request, $id)
    {
        //
        $type = $request->input('type');

        $sub_institute_id = session()->get('sub_institute_id');
        $syear = session()->get('syear');
        $user_id = session()->get('user_id');

        if(in_array($type,["API","JSON"])){
            try {
                // The caller's own organisation, from their token. See the
                // class docblock.
                $sub_institute_id = $this->apiTenantId($request);
                $syear = $request->get('syear');
                $user_id = $request->get('user_id');

                $validator = Validator::make($request->all(), [
                    'sub_institute_id' => 'required|numeric',
                    'syear'    => 'required|numeric',
                    'user_id'   => 'required|numeric',
                    'formName' => 'required',
                ]);
        
                if ($validator->fails()) {
                    $response['status'] = '0';
                    $response['message'] = $validator->messages();
                    return response()->json($response);
                } 
    
            } catch (\Exception $e) {
                $response = ['status' => '2', 'message' => $e->getMessage(), 'data' => []];
    
                return response()->json($response, 401);
            }    
        }

        $i=0;
        if($request->has('formName')){
             // get data from department controller
             if($request->formName=="addDepartment"){
                $request1 = $request->merge(['type'=>'API','sub_institute_id'=>$sub_institute_id,'syear'=>$syear]);
                $departmentController = new departmentController;
                $departmentData = $departmentController->update($request1,$id);
                $res = $departmentData->getData();
                $i=1;
             }
             elseif($request->formName=="complaince_library"){
                    $name = $request->name;
                    $description = $request->description;
                    $standard_name = $request->standard_name;
                    $assigned_to = $request->assigned_to;
                    $duedate = $request->duedate;
                    $attachment= ($request->oldAttachment) ? $request->oldAttachment : null;
                    $frequency = $request->frequency;
                    $custom_frequency_details = $request->custom_frequency_details ?? null;

                    $complainceData = [
                        'name'=>$name,
                        'description'=>$description,
                        'standard_name'=>$standard_name,
                        'assigned_to'=>$assigned_to,
                        'duedate'=> date('Y-m-d',strtotime($duedate)),
                        'sub_institute_id'=>$sub_institute_id,
                        'frequency'=>$frequency,
                        'custom_frequency_details'=>$custom_frequency_details,
                        'updated_by'=>$user_id,
                        'updated_at'=>now()
                    ];
                    if($request->hasFile('attachment')){
                        // delete old file
                        $oldAttachment=$request->oldAttachment;
                        $file_path = 'public/compliance_library/' . $oldAttachment;
                        if (isset($request->oldAttachment) && Storage::disk('digitalocean')->exists($file_path)) {
                            Storage::disk('digitalocean')->delete($file_path);
                        } 

                        $img = $request->file('attachment');
                        $filename = $img->getClientOriginalName();
                        $attachment = time().'_'.$filename;
                        $complainceData['attachment'] = $attachment;
                        Storage::disk('digitalocean')->putFileAs('public/compliance_library/', $img, $attachment, 'public');
                    }
                    
                    // echo "<pre>";print_r($complainceData);exit; 

                    /*
                     * SCOPED BY TENANT. This filtered on `id` alone, so any
                     * authenticated caller could rewrite another organisation's
                     * compliance record by guessing an id. A row belonging to
                     * somebody else now matches nothing and $i stays 0, which
                     * the caller sees as "Failed to Update" - the same answer a
                     * non-existent id gives, so the response cannot be used to
                     * discover whether another tenant's record exists.
                     */
                    $i = DB::table('master_compliance')
                        ->where('id', $id)
                        ->where('sub_institute_id', $sub_institute_id)
                        ->update($complainceData);
                }
        }

        if($i==0){
            $res['status_code']=0;
            $res['message']="Failed to Update";
        }else{
            $res['status_code']=1;
            $res['message'] = "Updated SuccessFully !!";
        }
        return is_mobile($type, "institute_detail.index", $res);

    }

    public function destroy(Request $request, $id)
    {
        //
        $type = $request->input('type');

        $sub_institute_id = session()->get('sub_institute_id');
        $syear = session()->get('syear'); 
        $i=0;

        /*
         * This method read the SESSION only, which is null on every API call -
         * and then deleted from master_compliance by id alone. So the delete
         * was both unscoped and, once scoped, would have matched nothing.
         *
         * Resolved from the token for API callers, session for Blade. If
         * neither yields a tenant the queries below match no rows and the
         * caller is told the delete failed, which is the correct outcome for a
         * request that could not be attributed to an organisation.
         */
        if (in_array($type, ["API", "JSON"])) {
            $sub_institute_id = $this->apiTenantId($request) ?? $sub_institute_id;
        }

        if($request->has('formName')){
             // get data from department controller
             if($request->formName=="addDepartment"){
                $request1 = $request->merge(['type'=>'API','sub_institute_id'=>$sub_institute_id,'syear'=>$syear]);
                $departmentController = new departmentController;
                $departmentData = $departmentController->destroy($request1,$id);
                $res = $departmentData->getData();
                $i=1;
             }
             if($request->formName=="complaince_library"){
                // Scoped by tenant - see update(). This deleted by id alone.
                $i = DB::table('master_compliance')
                    ->where('id', $id)
                    ->where('sub_institute_id', $sub_institute_id)
                    ->update(['deleted_at' => now()]);
             }
            
        }
        if($i==0){
            $res['status_code']=0;
            $res['message']="Failed to Delete";
        }else{
            $res['status_code']=1;
            $res['message'] = "Deleted SuccessFully !!";
        }
        return is_mobile($type, "institute_detail.index", $res);

    }


}
