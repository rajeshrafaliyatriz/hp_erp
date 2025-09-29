<?php

namespace App\Http\Controllers\leave\leave_report;

use App\Http\Controllers\Controller;
use App\Models\HrmsAttendance;
use App\Models\HrmsDepartment;
use App\Models\HrmsInOutTime;
use App\Models\HrmsJobTitle;
use App\Models\PayrollType;
use App\Models\user\tbluserModel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use function App\Helpers\is_mobile;
use DB;


class LeaveReportController extends Controller
{
    public function leaveReport(Request $request) 
    {
        $type = $request->input('type');
        if ($type == 'API') 
        {
            $sub_institute_id = $request->input('sub_institute_id');
        } 
        else 
        {
            $sub_institute_id = $request->session()->get('sub_institute_id');
        }

	    $employee_id = $request->get('employee_id');
        $department_id = $request->get('department_id');

        $from_date_formatted = Carbon::now()->format('Y-m-d');
        $to_date_formatted = Carbon::now()->format('Y-m-d');

        $departments = HrmsDepartment::where('status', true)->pluck('department', 'id');
        $res['leave_types'] = ["approved_lwp"=>"Approved LWP","cancelled"=>"Cancelled","rejected"=>"Rejected","pending"=>"Pending Approval","approved"=>"Approved"];
        $res['departments'] = $departments;
        $res['from_date_formatted'] = $from_date_formatted;
        $res['to_date_formatted'] = $to_date_formatted;
        $res['employee_id'] = $employee_id;
        $res['department_id'] = $department_id;
        
        // return view('leave.leave_report.index', compact('from_date_formatted', 'to_date_formatted', 'departments', 'employee_id', 'department_id'));
        return is_mobile($type, "leave/leave_report/index", $res, "view");
    }

    public function getEmpLists(Request $request)
    {
        $sub_institute_id = $request->session()->get('sub_institute_id');
        $department_id = $request->input('department_id');
	    $employee_id = $request->get('employee_id');
	
	    $employees = tbluserModel::where('sub_institute_id', $sub_institute_id)->where('department_id', $department_id)->where('status',1)->get()->toArray();   // 23-04-24 by uma

        return response()->json(['employees' => $employees, 'department_id' => $department_id, 'employee_id' =>$employee_id]);
    }

    // public function leaveReportShow(Request $request) 
    // {
    //     $type = $request->input('type');
    //     if ($type == 'API') {
    //         $sub_institute_id = $request->input('sub_institute_id');
    //     } else {
    //         $sub_institute_id = $request->session()->get('sub_institute_id');
    //     }

    //     $from_date = $request->get('from_date');
    //     $to_date = $request->get('to_date');
    //     $department_id = ($request->get('department_id')!=0) ? implode(',',$request->get('department_id')) : 0;
	//     $employee_id = ($request->input('employee_id')!=0) ? implode(',',$request->input('employee_id')) : 0;
	//     $get_leave_status = $request->input('leave_status');
        
    //     /* echo("<pre>");
    //     print_r($sub_institute_id);echo("<br>");
    //     print_r($from_date);echo("<br>");
    //     print_r($to_date);echo("<br>");
    //     print_r($department_id);echo("<br>");
    //     print_r($employee_id);echo("<br>");
    //     print_r($get_leave_status);
    //     echo("</pre>");
    //     die; */

    //     $from_date_formatted = Carbon::createFromFormat('Y-m-d', $from_date)->format('Y-m-d');
    //     $to_date_formatted = Carbon::createFromFormat('Y-m-d', $to_date)->format('Y-m-d');

    //     $from_month_formatted = Carbon::createFromFormat('Y-m-d', $from_date)->format('Y-m');
    //     $to_month_formatted = Carbon::createFromFormat('Y-m-d', $to_date)->format('Y-m');

    //     $departments = HrmsDepartment::where('status', true)->pluck('department', 'id');

    //     $employees = tbluserModel::where('sub_institute_id', $sub_institute_id)->whereRaw('department_id in ('.$department_id.')')->get()->toArray();
    //     // DB::enableQueryLog();
    //     $get_employee_leave_lists = DB::table('hrms_emp_leaves as hel')
    //     ->selectRaw("hel.*, u.*,CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name) AS employee_name, hlt.leave_type, hlt.id as leave_id, hel.status as hel_status")
    //     ->join('tbluser as u', 'u.id', '=', 'hel.user_id')
    //     ->join('hrms_leave_types as hlt', function($join) use ($sub_institute_id) {
    //         $join->on('hlt.id', '=', 'hel.leave_type_id')
    //              ->where('hlt.sub_institute_id', '=', $sub_institute_id);
    //     })
    //     ->whereBetween('hel.from_date',[$from_date_formatted,$to_date])
    //     ->where('hel.sub_institute_id', $sub_institute_id);

    //     if($employee_id!=0){
    //         $get_employee_leave_lists->whereRaw('hel.user_id IN ('.$employee_id.')')
    //         ->whereRaw('(hel.to_date between "'.$from_date_formatted.'" and "'.$to_date.'" AND hel.user_id IN ('.$employee_id.') AND hel.status IN ("'.implode('","',$get_leave_status).'")) OR (hel.from_date <="'.$to_date.'" AND hel.to_date >="'.$from_date.'" AND hel.user_id IN ('.$employee_id.') AND hel.status IN ("'.implode('","',$get_leave_status).'") )');
    //     }else if($department_id!=0){
    //         $get_employee_leave_lists->whereRaw('u.department_id IN ('.$department_id.')')
    //             ->whereRaw('(hel.to_date between "'.$from_date_formatted.'" and "'.$to_date.'" AND u.department_id IN ('.$department_id.') AND hel.status IN ("'.implode('","',$get_leave_status).'")) OR (hel.from_date <="'.$to_date.'" AND hel.to_date >="'.$from_date.'" AND u.department_id IN ('.$department_id.') AND hel.status IN ("'.implode('","',$get_leave_status).'") )');
    //     }else{
    //         $get_employee_leave_lists->whereRaw('(hel.to_date between "'.$from_date_formatted.'" and "'.$to_date.'" AND hel.sub_institute_id = '.$sub_institute_id.' AND hel.status IN ("'.implode('","',$get_leave_status).'")) OR (hel.from_date <="'.$to_date.'" AND hel.to_date >="'.$from_date.'" AND hel.sub_institute_id = '.$sub_institute_id.' AND hel.status IN ("'.implode('","',$get_leave_status).'"))');
    //     }
        
    //     // ->whereIn('hel.status', $get_leave_status)
    //     $get_employee_leave_lists = $get_employee_leave_lists->get()->toArray();
    //     // dd(DB::getQueryLog($get_employee_leave_lists));

    //     $res['leave_types'] = ["approved_lwp"=>"Approved LWP","cancelled"=>"Cancelled","rejected"=>"Rejected","pending"=>"Pending Approval","approved"=>"Approved"];
        
    //     $res['employees'] = $employees;
    //     $res['from_date_formatted'] = $from_date_formatted;
    //     $res['to_date_formatted'] = $to_date_formatted;
    //     $res['get_leave_status'] = $get_leave_status;
    //     $res['departments'] = $request->get('department_id');
    //     $res['get_employee_leave_lists'] = $get_employee_leave_lists;
    //     $res['employee_id'] = $request->input('employee_id');
    //     $res['selected_emp']=$request->employee_id;
    //     $res['department_id']=$request->department_id;

    //     //return view('leave.leave_report.index', compact('employees', 'from_date_formatted', 'to_date_formatted', 'get_leave_status', 'employee_id', 'department_id', 'departments', 'get_employee_leave_lists'));
    //     return is_mobile($type, "leave/leave_report/index", $res, "view");
    // }

    public function leaveReportShow(Request $request) 
{
    $type = $request->input('type');
    $sub_institute_id = ($type === 'API') ? $request->input('sub_institute_id') : $request->session()->get('sub_institute_id');

    // Dates
    $from_date = $request->input('from_date');
    $to_date   = $request->input('to_date');

    try {
        $from_date_formatted = Carbon::createFromFormat('Y-m-d', $from_date)->format('Y-m-d');
        $to_date_formatted   = Carbon::createFromFormat('Y-m-d', $to_date)->format('Y-m-d');
    } catch (\Exception $e) {
        // if parsing fails, fallback to raw values (or optionally return validation error)
        $from_date_formatted = $from_date;
        $to_date_formatted   = $to_date;
    }

    // Normalize inputs to arrays (so implode() is never called on a string)
    $department_ids = $request->input('department_id', []);
    if (!is_array($department_ids)) $department_ids = [$department_ids];
    // remove empty/zero values
    $department_ids = array_values(array_filter($department_ids, function($v){ return $v !== '' && $v != 0 && $v !== null; }));

    $employee_ids = $request->input('employee_id', []);
    if (!is_array($employee_ids)) $employee_ids = [$employee_ids];
    $employee_ids = array_values(array_filter($employee_ids, function($v){ return $v !== '' && $v != 0 && $v !== null; }));

    $get_leave_status = $request->input('get_leave_status');
    if (!is_array($get_leave_status)) $get_leave_status = [$get_leave_status];
    $get_leave_status = array_values(array_filter($get_leave_status, function($v){ return $v !== '' && $v !== null; }));

    // Departments for view
    $departments = HrmsDepartment::where('status', true)->pluck('department', 'id');

    // Employees: filter by department if provided
    $employeesQuery = tbluserModel::where('sub_institute_id', $sub_institute_id);
    if (!empty($department_ids)) {
        $employeesQuery->whereIn('department_id', $department_ids);
    }
    $employees = $employeesQuery->get()->toArray();

    // Build leave query safely
    $get_employee_leave_lists = DB::table('hrms_emp_leaves as hel')
        ->selectRaw("hel.*, u.*, CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name) AS employee_name, hlt.leave_type, hlt.id as leave_id, hel.status as hel_status")
        ->join('tbluser as u', 'u.id', '=', 'hel.user_id')
        ->join('hrms_leave_types as hlt', function($join) use ($sub_institute_id) {
            $join->on('hlt.id', '=', 'hel.leave_type_id')
                 ->where('hlt.sub_institute_id', '=', $sub_institute_id);
        })
        ->where('hel.sub_institute_id', $sub_institute_id)
        // date overlap logic: either hel.to_date inside range OR hel.from_date <= to_date AND hel.to_date >= from_date
        ->where(function($q) use ($from_date_formatted, $to_date_formatted) {
            $q->whereBetween('hel.to_date', [$from_date_formatted, $to_date_formatted])
              ->orWhere(function($q2) use ($from_date_formatted, $to_date_formatted) {
                  $q2->where('hel.from_date', '<=', $to_date_formatted)
                     ->where('hel.to_date', '>=', $from_date_formatted);
              });
        });

    // Apply employee / department filters
    if (!empty($employee_ids)) {
        $get_employee_leave_lists->whereIn('hel.user_id', $employee_ids);
    } elseif (!empty($department_ids)) {
        $get_employee_leave_lists->whereIn('u.department_id', $department_ids);
    }

    // Apply leave status filter if provided
    if (!empty($get_leave_status)) {
        $get_employee_leave_lists->whereIn('hel.status', $get_leave_status);
    }

    $get_employee_leave_lists = $get_employee_leave_lists->get()->toArray();

    // Prepare response
    $res['leave_types'] = [
        "approved_lwp" => "Approved LWP",
        "cancelled"    => "Cancelled",
        "rejected"     => "Rejected",
        "pending"      => "Pending Approval",
        "approved"     => "Approved"
    ];

    $res['employees'] = $employees;
    $res['from_date_formatted'] = $from_date_formatted;
    $res['to_date_formatted']   = $to_date_formatted;
    $res['get_leave_status']    = $get_leave_status;
    $res['departments']         = $request->get('department_id');
    $res['get_employee_leave_lists'] = $get_employee_leave_lists;
    $res['employee_id']        = $request->input('employee_id');
    $res['selected_emp']       = $request->employee_id;
    $res['department_id']      = $request->department_id;

    return is_mobile($type, "leave/leave_report/index", $res, "view");
}

}
