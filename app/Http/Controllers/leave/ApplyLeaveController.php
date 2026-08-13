<?php

namespace App\Http\Controllers\leave;

use App\Http\Controllers\Controller;
use App\Imports\LeaveImport;
use App\Models\HRMS\hrmsDepartmentModel;
use App\Models\HrmsEmpLeave;
use App\Models\HrmsLeaveType;
use App\Models\user\tbluserModel;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Maatwebsite\Excel\Facades\Excel;
use Yajra\DataTables\Facades\DataTables;
use function App\Helpers\is_mobile;
use Laravel\Sanctum\PersonalAccessToken;
use DB;
use Carbon\Carbon;
// use GenTux\Jwt\GetsJwtToken;
use App\Traits\Helpers;

class ApplyLeaveController extends Controller
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

    // use GetsJwtToken; 

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $sub_institute_id = session()->get('sub_institute_id');
        $syear = session()->get('syear');
        $user_id= session()->get('user_id');

        $type = $request->type;
        if($type=="API"){
            $sub_institute_id=$this->apiTenantId($request);
            $syear = $request->syear;
            $user_id= $request->user_id;
        }

        try {
            $res = session()->get('data');
            $res['departments'] = hrmsDepartmentModel::where('sub_institute_id',$sub_institute_id)->where('status', 1)->pluck('department', 'id');
            // $res['users'] = tbluserModel::where('sub_institute_id', $sub_institute_id)->where('status',1)->get();   // 23-04-24 by uma
            // echo("<pre>");print_r(session()->all());exit;
            $res['leave_types'] = HrmsLeaveType::where('sub_institute_id', $sub_institute_id)->where('status',1)->orderBy('sort_order')->get();
            
            $res['leaveHistory'] = DB::table('hrms_emp_leaves as hel')->selectRaw("hel.*, hlt.leave_type as leave_type_name")
            ->join('hrms_leave_types as hlt', function($join) use ($sub_institute_id) {
                $join->on('hlt.id', '=', 'hel.leave_type_id')
                     ->where('hlt.sub_institute_id', '=', $sub_institute_id);
            })
            ->where('hel.user_id', $user_id)
            // ->whereYear('hel.from_date', $syear)
            ->where('hel.from_date','>=',$syear.'-04-01')
            ->where('hel.to_date','<=',($syear+1).'-03-31')
            ->orderBy('hel.from_date')
            ->get()->toArray();

            $res['sandwichLeave'] = DB::table('general_data')->where('sub_institute_id',$sub_institute_id)->where('fieldname', 'sandwich_leave')->first();
            $res['causualLeave'] =DB::table('general_data')->where('sub_institute_id',$sub_institute_id)->where('fieldname', 'casual_leave_apply')->first();
            $res['earnedLeave'] = DB::table('general_data')->where('sub_institute_id',$sub_institute_id)->where('fieldname', 'earned_leave_apply')->first();
            $res['half_days_allowed'] = DB::table('general_data')->where('sub_institute_id',$sub_institute_id)->where('fieldname', 'half_days_allowed')->first();
            // echo "<pre>";print_r($res['earnedLeave']);exit;
            // return view('leave.apply_leave', compact('departments', 'users', 'leave_types'));
            return is_mobile($type, "leave.apply_leave", $res, "view");
        } catch (Exception $e) {
            return response()->json($e->getMessage(), 500);
        }
    }

    public function getEmployees(Request $request)
    {

		$sub_institute_id = session()->get('sub_institute_id');
        $departmentId = $request->get('department_id');

        $employees = tbluserModel::where('department_id', $departmentId)->where('sub_institute_id', $sub_institute_id)->where('status',1)->get();
	
        return response()->json(['employees' => $employees]);
    }


    public function importLeave()
    {
        $sub_institute_id = session()->get('sub_institute_id');

        try {
            $departments = hrmsDepartmentModel::where('status', true)->pluck('department', 'id');
            $users = tbluserModel::where('sub_institute_id', $sub_institute_id)->where('status',1)->get();  // 23-04-24 by uma
            $leave_types = HrmsLeaveType::where('sub_institute_id', $sub_institute_id)->where('status',1)->orderBy('sort_order')->get();

            return view('leave.import_leave', compact('departments', 'users', 'leave_types'));
        } catch (Exception $e) {
            return response()->json($e->getMessage(), 500);
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    public function getHolidays(Request $request)
    {
        $sub_institute_id = session()->get('sub_institute_id');
        $fromDate = trim($request->get('fromDate'));
        $toDate = trim($request->get('toDate'));

        // Parse the date strings into the correct format
        $from_date = date('Y-m-d', strtotime($fromDate));
        $to_date = date('Y-m-d', strtotime($toDate));

        $holidays = DB::table('hrms_holidays')
            ->where('sub_institute_id', $sub_institute_id)
            ->where('from_date', '>=', $from_date)
            ->where('to_date', '<=', $to_date)
            ->get()
            ->toArray();
            
        return response()->json($holidays);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $type = $request->input('type');
        // return $type;
        $subInstituteId = $request->session()->get('sub_institute_id');
        $total_days = $request->get('total_days');
        $day_type= ($request->day_type=="full") ? 1 : "0.5";
        $user_id = session()->get('user_id');
      
        if($type=="API"){
            $token = $request->input('token');  // get token from input field 'token'

            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }

            $subInstituteId=$this->apiTenantId($request);
            $syear = $request->syear;
            $user_id = $request->get('user_id');
        }
        $type="API";
        // $request->validate([
        //     'type_leave' => 'required',
        //     'leave_type' => 'required|exists:hrms_leave_types,id',
        //     'day_type' => 'required|in:full,half',
        //     'from_date' => 'required|date',
        //     'to_date' => 'required_if:day_type,full|date|nullable|after_or_equal:from_date',
        //     'slot' => 'required_if:day_type,half',
        //     'comment' => 'required',
        // ]);
        //  if ($validator->fails()) {
        //         $res['status'] = 0;
        //         $res['message'] = $validator->messages()->first();
        //         return is_mobile($type, "hrms_inout_time.index", $res, "redirect");
        //     }

        // HrmsEmpLeave::updateOrCreate([
        //         'user_id' => ($request->emp_id!=0) ? $request->emp_id : $user_id,
        //         'from_date' => $request->from_date,
        //     ],
        //     [
        //         'sub_institute_id' => $subInstituteId,
        //         'department_id' => $request->department_id,
        //         'leave_type_id' => $request->leave_type,
        //         'day_type' => $day_type,
        //         'from_date' => $request->from_date,
        //         'to_date' => $request->to_date,
        //         'slot' => $request->slot ?? 'NULL',
        //         'comment' => $request->comment,
        //     ]);

            // 16-10-2024 start for cancelled leave updated for same date and same user
            $inData = [
                'sub_institute_id' => $subInstituteId,
                'department_id' => $request->department_id,
                'leave_type_id' => $request->leave_type,
                'day_type' => $day_type,
                'from_date' => $request->from_date,
                'to_date' => $request->to_date,
                'slot' => $request->slot,
                'comment' => $request->comment,
                'user_id' => ($request->emp_id!=0) ? $request->emp_id : $user_id,
                'from_date' => $request->from_date,
            ];
            // return $inData;

            $where = [
                'user_id' => ($request->employee_id!=0) ? $request->employee_id : $user_id,
                'from_date' => $request->from_date,
            ];

            // check Data Exists 
            $checkExists = HrmsEmpLeave::where($where)->where('status','pending')->first();
            // echo "<pre>";print_r($checkExists);exit;
            if(!empty($checkExists)){
                  $inData['updated_at']=now();
                $inData['upadated_by']=$user_id;
                $update = HrmsEmpLeave::where($where)->where('id',$checkExists->id)->update($inData);
            }else{
                $inData['created_at']=now();
                $inData['created_by']=$user_id;

                $insert = HrmsEmpLeave::insert($inData);
            }
            //16-10-2024 end

        $res['status_code']=1;
        $res['message']="Leave Applied successfully";
        // exit;
        return is_mobile($type, "leave-apply.index", $res);
        // return response()->json(['message' => 'Holiday saved successfully !!'], 200);
    }

    public function importOldLeave(Request $request)
    {
        $request->validate([
            'upload_file' => 'required',
        ]);
        try {
            Excel::import(new LeaveImport, $request->upload_file);

            return response()->json(['message' => 'Leave Imported successfully !!'], 200);
        } catch (Exception $e) {
            return response()->json($e->getMessage(), 500);
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

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }

    public function myLeave(Request $request)
    {
        $type = $request->input('type');
    
        /* try {
            if ($request->ajax()) {
                $data = HrmsEmpLeave::where('user_id', session()->get('user_id'))
                    ->with('leave_type')->get();
                return DataTables::of($data)
                    ->addIndexColumn()
                    ->addColumn('days', function ($row) {
                        if ($row->day_type == 'full') {
                            $fdate = $row->from_date;
                            $tdate = $row->to_date;
                            $datetime1 = new DateTime($fdate);
                            $datetime2 = new DateTime($tdate);
                            $interval = $datetime1->diff($datetime2);
                            $days = $interval->format('%a');
                            return $days;
                        } else {
                            return 0.5;
                        }
                    })
                    ->addColumn('leave_type', function ($row) {
                        return $row->leave_type->leave_type ?? '-';
                    })
                    ->rawColumns(['days', 'leave_type'])
                    ->make(true);
            }
            return view('leave.leave_list');
        } catch (Exception $e) {
            return response()->json($e->getMessage(), 500);
        } */
        $res['allyears'] = Helpers::getPairYears();
        return is_mobile($type, "leave/leave_list", $res, "view");
    }

    public function getYearwiseleave(Request $request)
    {
        $type = $request->type;
        $user_id = session()->get('user_id');
        $sub_institute_id = session()->get('sub_institute_id');
        $selectedYear = $request->year ?? $request->syear ?? session()->get('syear');

        if($type=="API"){
            $user_id=$request->user_id;
            $sub_institute_id=$this->apiTenantId($request);
        }

        $query = DB::table('hrms_emp_leaves as hel')->selectRaw("hel.*, hlt.leave_type as leave_type_name")
            ->join('hrms_leave_types as hlt', function($join) use ($sub_institute_id) {
                $join->on('hlt.id', '=', 'hel.leave_type_id')
                     ->where('hlt.sub_institute_id', '=', $sub_institute_id);
            })
            ->where('hel.user_id', $user_id)
            ->where('hel.sub_institute_id', $sub_institute_id);

        // Keep year-based filtering for non-API requests, but return all leaves for API calls.
        if ($type !== "API" && !empty($selectedYear)) {
            $nextYear = ((int) $selectedYear) + 1;
            $query->whereDate('hel.from_date', '>=', $selectedYear . '-04-01')
                ->whereDate('hel.to_date', '<=', $nextYear . '-03-31');
        }

        $data = $query->orderBy('hel.from_date', 'desc')->get()->toArray();
        
        return response()->json($data);
    }

    public function updateLeave(Request $request){
        $type = $request->type;
        $sub_institute_id = session()->get('sub_institute_id');
        $user_name = session()->get('user_name');

        if($type=="API"){
            $sub_institute_id  = $this->apiTenantId($request);
            $user_name  = $request->user_name;
        }
        $LeaveUpdate = $request->LeaveUpdate;
        $i=0;
        foreach ($LeaveUpdate as $id => $value) {
            $comment = $value['comment'];
            $hod_comment = $value['hod_comment'];
            $hr_remarks = $value['hr_remarks'];
            $status = $value['status'];
            if($status=='cancelled'){
                $i++;
                $update = DB::table('hrms_emp_leaves')->where(['sub_institute_id'=>$sub_institute_id,'id'=>$id])->update([
                    'comment' => $value['comment'],
                    'hod_comment' => $value['hod_comment'],
                    'hod_comment_date' => now(),
                    'hr_remarks' => $value['hr_remarks'],
                    'hr_remark_date' => now(),
                    'approved_by'=>$user_name,
                    'status' => $value['status'],
                ]);
            }
        }
        if($i!=0){
            $res['status_code']=1;
            $res['message']="Leave Cancelled Successfully!";
        }else{
            $res['status_code']=0;
            $res['message']="No Leave Cancelled!";     
        }
        return is_mobile($type, "my-leave", $res);
    }
}
