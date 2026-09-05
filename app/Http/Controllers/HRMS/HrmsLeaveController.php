<?php

namespace App\Http\Controllers\hrms;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use function App\Helpers\is_mobile;
use App\Traits\Helpers;
//use GenTux\Jwt\GetsJwtToken;
use Laravel\Sanctum\PersonalAccessToken;
use DB;

class HrmsLeaveController extends Controller
{
    use \App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;

    //
    //use GetsJwtToken;

    public function index(Request $request){
        $type = $request->type;
        $sub_institute_id = $request->sub_institute_id;
        $syear = $request->year ?? $request->syear; // support both "year" and "syear"

    
        if($type=="API"){
            $sub_institute_id = $request->sub_institute_id;
         $syear = $request->year ?? $request->syear; // support both "year" and "syear"

        }

        // $res['departmentData']=DB::table('hrms_leave_allocation as hla')
        //                 ->join('hrms_departments as hd',function($join) use($sub_institute_id){
        //                     $join->on('hd.id','=','hla.department_id')->where('hd.sub_institute_id',$sub_institute_id)->where('status',1)->whereNull('hd.deleted_at');
        //                 })
        //                 ->join('hrms_leave_types as hlt','hlt.id','=','hla.leave_type_id')
        //                 ->selectRaw('hla.*,hlt.*,hd.*,hla.id as id')
        //                 ->where('hla.sub_institute_id',$sub_institute_id)
        //                 ->whereNull('hla.employee_id')
        //                 ->where('hla.year',$syear)->get()->toArray();

        $res['departmentData'] = DB::table('hrms_leave_allocation as hla')
    ->leftJoin('hrms_departments as hd', function($join) use($sub_institute_id) {
        $join->on('hd.id','=','hla.department_id')
             ->where('hd.sub_institute_id', $sub_institute_id)
             ->where('hd.status',1)
             ->whereNull('hd.deleted_at');
                    })
                    ->join('hrms_leave_types as hlt','hlt.id','=','hla.leave_type_id')
                    ->selectRaw('hla.*, hlt.*, hd.*, hla.id as id')
                    ->where('hla.sub_institute_id',$sub_institute_id)
                    ->where(function($q){
                        $q->whereNull('hla.employee_id')->orWhere('hla.employee_id',0);
                    })
                    ->where('hla.year',$syear)
                    ->get()
                    ->toArray();


        $res['employeeData']=DB::table('hrms_leave_allocation as hla')
                        ->join('hrms_departments as hd',function($join) use($sub_institute_id){
                            $join->on('hd.id','=','hla.department_id')->where('hd.sub_institute_id',$sub_institute_id)->where('status',1)->whereNull('hd.deleted_at');
                        })
                        ->join('tbluser as tu','tu.id','=','hla.employee_id')
                        ->join('hrms_leave_types as hlt','hlt.id','=','hla.leave_type_id')
                        ->selectRaw('hla.*,hlt.*,hd.*,hla.id as id,concat_ws(" ",COALESCE(tu.first_name,"-"),COALESCE(tu.middle_name,"-"),COALESCE(tu.last_name,"-")) as employee_name,tu.employee_no')
                        ->where('hla.sub_institute_id',$sub_institute_id)
                        ->whereNotNull('hla.employee_id')
                       ->where('hla.year',$syear)
                        ->get()->toArray();

        return is_mobile($type, "HRMS.hrms_leave.hrms_leave_allocation.show", $res, "view");
    }

    public function create(Request $request){
        $type = $request->type;
        $sub_institute_id = $request->sub_institute_id;
        $syear = $request->syear;
    
        if($type=="API"){
            $sub_institute_id = $request->sub_institute_id;
            $syear = $request->syear;
        }

        $res['departments'] = DB::table('hrms_departments')->where('sub_institute_id',$sub_institute_id)->where('status',1)->whereNull('deleted_at')->pluck('department','id');
        $res['leave_types'] = DB::table('hrms_leave_types')->where('sub_institute_id',$sub_institute_id)->orderBy('sort_order')->pluck('leave_type','id');
        $res['years']= Helpers::getPairYears();
        $res['selYear'] = date('Y');
        $res['view'] = $request->view;

        return is_mobile($type, "HRMS.hrms_leave.hrms_leave_allocation.add", $res, "view");
    }

    public function store(Request $request){
        // echo "<pre>";print_r($request->all());exit;
        $type = $request->type;
        $syear = $request->input('syear');
        $res['formType'] =  $formType = $request->formType;

        // store() is reachable two ways: POST /api/designation_leave (token
        // authenticated, see routes/api.php) and the session-authenticated
        // resource route in routes/hrms.php. It cannot simply take the tenant
        // from the token, because the web path has no token - and it must not
        // take it from the request, because the API path let one organisation
        // write leave allocations into another's departments.
        //
        // So: the token decides when there is one, the session decides
        // otherwise, and a caller who has neither is refused.
        $sub_institute_id = $this->apiTenantId($request) ?: session('sub_institute_id');

        if (!$sub_institute_id) {
            return response()->json([
                'status'  => 0,
                'message' => 'Unable to identify your organisation.',
            ], 401);
        }
        $insert = 0;
        // for department wise starts
        if($formType=="department"){
            $res['department_id'] = $department_ids = $request->department_id;
            $res['leave_type_ids'] = $leave_type_ids = $request->leave_type_ids;
            $res['year'] = $year = $request->year;
            $res['days'] = $days = $request->days;

            if($department_ids=="All"){
                $departmentAll = DB::table('hrms_leave_allocation')->where('sub_institute_id',$sub_institute_id)->where('status',1)->whereNull('deleted_at')->get()->toArray();
                foreach($departmentAll as $key=>$value){
                    $insert = $this->insertData($value->id,$leave_type_ids,$year,$days,$sub_institute_id);
                }
                // echo "<pre>";print_r($insert);exit;
            }else{
                $insert = $this->insertData($department_ids,$leave_type_ids,$year,$days,$sub_institute_id);
                // echo "<pre>";print_r($insert);exit;

            }
        }
        // for department wise end
        // for employee wise starts
        elseif($formType=="employee"){
            $res['department_id'] = $department_ids = $request->department_id;
            $res['employee_id'] = $employee_id = $request->employee_id;
            $res['leave_type_ids'] = $leave_type_ids = $request->leave_type_ids;
            $res['year'] = $year = $request->year;
            $res['days'] = $days = $request->days;

            $insert = $this->insertData($department_ids,$leave_type_ids,$year,$days,$sub_institute_id,$employee_id);
        }
        // for employee wise end

        if($insert==0){
            $res['status_code'] = 0;
            $res['message']="Failed to Add";
        }else{
            $res['status_code'] = 1;
            $res['message']="Added Successfully";
        }
        return is_mobile($type, "designation_leave.index", $res);
    }

    public function insertData($department_ids,$leave_type_ids,$year,$days,$sub_institute_id,$emp_id='')
    {
        $i=0;
        if($emp_id==''){
            foreach($leave_type_ids as $key=>$value){
                // check alread exists or not 
                $check = DB::table('hrms_leave_allocation')->where('sub_institute_id',$sub_institute_id)->where(['department_id'=>$department_ids,'year'=>$year,'leave_type_id'=>$value])->whereNull('employee_id')->first(); // added where null condition on 25-07-2025
                // return $check;
                if(empty($check)){
                    $i++;
                    $insert = DB::table('hrms_leave_allocation')->insert([
                        'department_id'=>$department_ids,
                        'leave_type_id'=>$value,
                        'year'=>$year,
                        'value'=>$days,
                        'sub_institute_id'=>$sub_institute_id,
                        'created_at'=>now()
                    ]);
                }
            }
        }
        else{
            foreach($leave_type_ids as $key=>$value){
                // check alread exists or not 
                $check = DB::table('hrms_leave_allocation')->where('sub_institute_id',$sub_institute_id)->where(['department_id'=>$department_ids,'employee_id'=>$emp_id,'year'=>$year,'leave_type_id'=>$value])->first();
                if(empty($check)){
                    $i++;
                    $insert = DB::table('hrms_leave_allocation')->insert([
                        'department_id'=>$department_ids,
                        'employee_id'=>$emp_id,
                        'leave_type_id'=>$value,
                        'year'=>$year,
                        'value'=>$days,
                        'sub_institute_id'=>$sub_institute_id,
                        'created_at'=>now()
                    ]);
                }
            }
        }
        
        return $i;
    }

    public function edit(Request $request,$id){
        $type = $request->type;
        $sub_institute_id = session()->get('sub_institute_id');
        $syear = session()->get('syear');
    
        if($type=="API"){
            $sub_institute_id = $request->sub_institute_id;
            $syear = $request->syear;
        }
        $res['editData'] = DB::table('hrms_leave_allocation')->where('id',$id)->first();
        $res['departments'] = DB::table('hrms_departments')->where('sub_institute_id',$sub_institute_id)->where('status',1)->whereNull('deleted_at')->pluck('department','id');
        $res['leave_types'] = DB::table('hrms_leave_types')->where('sub_institute_id',$sub_institute_id)->orderBy('sort_order')->pluck('leave_type','id');
        $res['years']= Helpers::getPairYears();
        $res['view'] = $request->view;
        return is_mobile($type, "HRMS.hrms_leave.hrms_leave_allocation.edit", $res, "view");
    }

    public function update(Request $request,$id){
        $type = $request->type;
        $sub_institute_id = $request->sub_institute_id;
         $syear = $request->year ?? $request->syear;
        $formType = $request->formType;
    
       if($type=="API"){
            $sub_institute_id = $request->sub_institute_id;
            $syear = $request->year ?? $request->syear;           
        }
            else
        {
            $user_id = session()->get('user_id');
        }
        $res['department_ids'] = $department_ids = $request->department_id;
        // $res['leave_type_ids'] = $leave_type_ids = $request->leave_type_ids;
        $leave_type_ids = (int) (is_array($request->leave_type_ids) 
                ? $request->leave_type_ids[0]   
                : $request->leave_type_ids);


        $res['year'] = $year = $request->year;
        $res['days'] = $days = $request->days;
          $update = false;
        
        if($formType=="department"){
            $update = DB::table('hrms_leave_allocation')->where('id',$id)->update([
                'department_id'=>$department_ids,
                'leave_type_id'=>$leave_type_ids,
                'year'=>$year,
                'value'=>$days,
                'sub_institute_id'=>$sub_institute_id,
                'updated_at'=>now(),
                'updated_by' => $user_id,
            ]);
        }
        else if($formType=="employee"){
            $update = DB::table('hrms_leave_allocation')->where('id',$id)->update([
                'department_id'=>$department_ids,
                'employee_id'=>$request->employee_id ?? 0,
                'leave_type_id'=>$leave_type_ids,
                'year'=>$year,
                'value'=>$days,
                'sub_institute_id'=>$sub_institute_id,
                'updated_at'=>now()
            ]);
        }

        if($update){
            $res['status_code'] = 1;
            $res['message'] = "Updated SuccessFully";
        }else{
            $res['status_code'] = 0;
            $res['message'] = "Failed to Update";
            
        }
        return is_mobile($type, "designation_leave.index", $res);
    }

    public function destroy(Request $request,$id){
        $type=$request->type;

        if($type=="API"){
            $sub_institute_id = $request->get('sub_institute_id');
            $syear = $request->get('syear');            
        }

        $delete = DB::table('hrms_leave_allocation')->where('id',$id)->delete();
        $res['status_code'] = 1;
        $res['message'] = "Deleted SuccessFully";
        
        
        return is_mobile($type, "designation_leave.index", $res);
    }

    // 1. MY LEAVE SUMMARY API
    /*
     * getLeaveDashboard() and getLeaveHistory() were DELETED here on 2026-09-05
     * (HRIT Sprint 1, F-100). Both returned a `// Sample data` block - the same
     * invented leave balances for every employee in every tenant - from live,
     * authenticated routes, and both trusted {employeeId} from the URL without
     * comparing it to the caller.
     *
     * The routes that reached them are removed in routes/hrms.php, where the
     * full reasoning and the endpoints that replace them are recorded.
     */
}
