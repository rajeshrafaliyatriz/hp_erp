<?php

namespace App\Http\Controllers\HRMS;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use function App\Helpers\is_mobile;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class departmentController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->input('type');
        $sub_institute_id = session()->get('sub_institute_id');

        $departmentData = DB::table('hrms_departments as hdm')
        ->LeftJoin('tbluser as u',function($query) use($sub_institute_id){
            $query->on('u.department_id','=','hdm.id')->where('u.sub_institute_id',$sub_institute_id);
        })
        ->select('hdm.*',DB::raw('(CASE WHEN hdm.parent_id=0 THEN "parent" ELSE "child" END) as depType'),
        DB::raw('COUNT(u.id) as total_emp'))
        ->where('hdm.status',1)
        ->where('hdm.sub_institute_id',$sub_institute_id)
        ->orderBy('hdm.sub_institute_id','DESC')
        ->orderBy('hdm.id','DESC')
        ->groupBy('hdm.id')
        ->get()->toArray();

        $parentData=$childData=[];
        foreach ($departmentData as $key => $value) {
            if($value->parent_id !=0){
                $childData[$value->parent_id][] = $value;
            }else{
                $parentData[] = $value;
            }
        }
        // echo "<pre>";print_r($childData);exit;
        $res['departmentData'] = $parentData;
        $res['subDepartmentData'] = $childData;
        return is_mobile($type, "HRMS.department.index", $res, "view");
    }

    public function create(Request $request)
    {
        $type = $request->input('type');
        $sub_institute_id = session()->get('sub_institute_id') ?? 0;
        $res = session()->get('data');

        $res['departmentList'] = DB::table('hrms_departments')->where('status',1)->where('parent_id',0)->where('sub_institute_id',$sub_institute_id)->get()->toArray();

        $res['userDepartmentList'] = DB::table('hrms_departments as sub')
    ->select(
        'sub.*',
        DB::raw('IFNULL((select count(DISTINCT id) from hrms_departments where parent_id = sub.id),"-") as sub_dep'),
        DB::raw("IFNULL((select count(DISTINCT id) from tbluser where department_id = sub.id and sub_institute_id = {$sub_institute_id} and status = 1), '-') as total_emp"),
        DB::raw("IFNULL((select group_concat(DISTINCT id) from tbluser where department_id = sub.id and sub_institute_id = {$sub_institute_id} and status = 1), '-') as emp_ids")
    )
    ->where('sub.status', 1)
    ->where('sub.parent_id', 0)
    ->where(function($query) use ($sub_institute_id) {
        if ($sub_institute_id !== null) {
            $query->where('sub.sub_institute_id', $sub_institute_id);
        } else {
            $query->whereNull('sub.sub_institute_id');
        }
    })
    ->groupBy('sub.id')
    ->get()
    ->toArray();

        // echo "<pre>";print_r($res['userDepartmentList']);exit;
        $res['SubDepartmentList'] = DB::table('hrms_departments as sub')
        ->select(
            'sub.*',
            DB::raw('(CASE WHEN sub.parent_id!=0 THEN (SELECT department FROM hrms_departments WHERE id = sub.parent_id) ELSE "-" END) as mainDepartment'),
            DB::raw('(CASE WHEN sub.parent_id=0 THEN (SELECT count(id) FROM hrms_departments WHERE parent_id = sub.id group by parent_id) ELSE "0" END) total_subDep'),
            DB::Raw('IFNULL((select count(DISTINCT id) from tbluser where department_id = sub.id and sub_institute_id='.$sub_institute_id.' and status=1),"-") as total_emp'),
            DB::Raw('IFNULL((select group_concat(DISTINCT id) from tbluser where department_id = sub.id and sub_institute_id='.$sub_institute_id.' and status=1),"-") as emp_ids')
        )
        ->where('sub.status', 1)
        // ->where('sub.parent_id', '!=', 0)
        ->where('sub.sub_institute_id', $sub_institute_id)
        ->groupBy('sub.id')
        ->get()
        ->toArray();

        $res['employeesList'] =DB::table('tbluser as u')
        ->join('tbluserprofilemaster as upm','upm.id','=','u.user_profile_id')
        ->leftJoin('hrms_departments as dep','u.department_id', '=', 'dep.id')
        ->select(
            'u.id as emp_id','u.employee_no','u.gender','u.image',DB::Raw('CONCAT_WS(" ",COALESCE(u.first_name),COALESCE(u.middle_name),COALESCE(u.last_name)) as emp_name'),
            'upm.name as user_role',DB::Raw('IFNULL(dep.department,"-") as emp_department')
        )
        ->where('u.status', 1)
        ->where('u.sub_institute_id', $sub_institute_id)
        ->groupBy('u.id')
        ->get()
        ->toArray();
    
        // echo "<pre>";print_r($res['SubDepartmentList']);exit;
        return is_mobile($type, "HRMS.department.add", $res, "view");
    }

    public function store(Request $request)
    {
        // $type = $request->input('type');
        // $sub_institute_id = session()->get('sub_institute_id');

        // $department_name = $request->department_name;
        // $roles_responsibility = $request->roles_responsibility;
        // $is_calculated = $request->is_calculated;
        // $task = $request->tasks;
        // $i=$parent_id=0;

        // if($request->has('parentDiv') && $request->parentDiv!=''){
        //     $parent_id = $request->parentDiv;
        //     $check = DB::table('hrms_departments')->where(['department'=>$department_name,'parent_id'=>$parent_id])->get()->toArray();
        // }else{
        //     $check = DB::table('hrms_departments')->where(['department'=>$department_name,'parent_id'=>$parent_id])->get()->toArray();
        // }

        // if(empty($check)){
        //     $i=1;
        //     $insert = DB::table('hrms_departments')->insert([
        //         'department'=>$department_name,
        //         'parent_id'=>$parent_id,
        //         'tasks'=>$task,
        //         'roles_responsibility'=>$roles_responsibility,
        //         'status'=>1,
        //         'is_calculated'=>$is_calculated,
        //         'sub_institute_id'=>$sub_institute_id
        //     ]);
        // }
        // return $request;
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

        $i=0;
        $sub_institute_id = $request->sub_institute_id;
        $user_id = $request->user_id;
        $formType = $request->formType;

        if($formType=="add department"){
            $department = $request->department;
            $checkDepartment = DB::table('hrms_departments')->where(['sub_institute_id'=>$sub_institute_id,'department'=>$request->department, 'parent_id'=>0])->first();

            if(empty($checkDepartment)){
                $departmentId = DB::table('hrms_departments')->insertGetId([
                    'department'=>$request->department,
                    'parent_id'=>0,
                    'tasks'=>null,
                    'roles_responsibility'=>$request->department,
                    'status'=>1,
                    'is_calculated'=>0,
                    'sub_institute_id'=>$sub_institute_id,
                    'created_by'=>$user_id,
                    'created_at'=>now(),
                ]);

                $i = DB::table('s_user_jobrole')->insert([
                    'sub_institute_id'=>$sub_institute_id,
                    'department'=>$request->department,
                    'department_id'=>$departmentId,
                    'created_by'=>$user_id,
                    'created_at'=>now(),
                ]);
            }
        }
        else if($formType=="edit department"){
            $department = $request->department;
            $old_department = $request->old_department;
            $sub_department = $request->sub_department;

            $checkDepartment = DB::table('hrms_departments')->where(['sub_institute_id'=>$sub_institute_id,'department'=>$request->old_department, 'parent_id'=>0])->first();

            if(!empty($checkDepartment) && $sub_department==''){

                $updateArray = [
                    'sub_institute_id'=>$sub_institute_id,
                    'department'=>$request->department,
                    'updated_at'=>now(),
                    'updated_by'=>$user_id
                ];

                $i = DB::table('s_user_jobrole')->where(['sub_institute_id'=>$sub_institute_id,'department'=>$request->old_department])->update($updateArray);

                DB::table('hrms_departments')->where(['sub_institute_id'=>$sub_institute_id,'department'=>$request->old_department, 'parent_id'=>0])->update(['department'=>$request->department, 'updated_at'=>now(), 'updated_by'=>$user_id]);

                $checkSubDepartment = DB::table('s_user_jobrole')->where(['sub_institute_id'=>$sub_institute_id,'department'=>$request->department,'sub_department'=>$sub_department])->whereNull('deleted_at')->first();

                if(empty($checkSubDepartment) && !isset($checkSubDepartment->id)){
                    $updateArray['sub_department'] = $sub_department;
                    $updateArray['created_at'] = now();
                    $updateArray['created_by'] = $user_id;

                     $i = DB::table('s_user_jobrole')->insert($updateArray);
                }
            }
            else if($request->has('sub_department')){
                $updateArray = [
                    'sub_institute_id'=>$sub_institute_id,
                    'department'=>$request->department,
                    'updated_at'=>now(),
                    'updated_by'=>$user_id
                ];

                $i = DB::table('s_user_jobrole')->where(['sub_institute_id'=>$sub_institute_id,'department'=>$request->old_department])->update($updateArray);

                DB::table('hrms_departments')->where(['sub_institute_id'=>$sub_institute_id,'department'=>$request->old_department, 'parent_id'=>0])->update(['department'=>$request->department, 'updated_at'=>now(), 'updated_by'=>$user_id]);

                $checkSubDepartment = DB::table('s_user_jobrole')->where(['sub_institute_id'=>$sub_institute_id,'department'=>$request->department,'sub_department'=>$sub_department])->whereNull('deleted_at')->first();

                if(empty($checkSubDepartment) && !isset($checkSubDepartment->id)){
                    $updateArray['sub_department'] = $sub_department;
                    $updateArray['created_at'] = now();
                    $updateArray['created_by'] = $user_id;

                     $i = DB::table('s_user_jobrole')->insert($updateArray);

                     $parentId = DB::table('hrms_departments')->where(['sub_institute_id'=>$sub_institute_id,'department'=>$request->department, 'parent_id'=>0])->value('id');
                     $subDepartmentId = DB::table('hrms_departments')->insertGetId([
                         'department'=>$sub_department,
                         'parent_id'=>$parentId,
                         'tasks'=>null,
                         'roles_responsibility'=>$sub_department,
                         'status'=>1,
                         'is_calculated'=>0,
                         'sub_institute_id'=>$sub_institute_id,
                         'created_by'=>$user_id,
                         'created_at'=>now(),
                     ]);
                     DB::table('s_user_jobrole')->where(['sub_institute_id'=>$sub_institute_id,'department'=>$request->department,'sub_department'=>$sub_department])->update(['department_id'=>$parentId]);
                }
            }
        }
        else if($formType=="edit sub_department"){
            $department = $request->department;
            $old_sub_department = $request->old_sub_department;
            $sub_department = $request->sub_department;

            $checkSubDepartment = DB::table('s_user_jobrole')->where(['sub_institute_id'=>$sub_institute_id,'department'=>$request->department,'sub_department'=>$old_sub_department])->whereNull('deleted_at')->first();

                if(!empty($checkSubDepartment) && isset($checkSubDepartment->id)){
                    $parentId = DB::table('hrms_departments')->where(['sub_institute_id'=>$sub_institute_id,'department'=>$request->department, 'parent_id'=>0])->value('id');
                    $updateArray['sub_department'] = $sub_department;
                    $updateArray['department_id'] = $parentId;
                    $updateArray['updated_at'] = now();
                    $updateArray['updated_by'] = $user_id;

                    $i = DB::table('s_user_jobrole')->where(['sub_institute_id'=>$sub_institute_id,'department'=>$request->department,'sub_department'=>$old_sub_department])->update($updateArray);

                    DB::table('hrms_departments')->where(['sub_institute_id'=>$sub_institute_id,'department'=>$old_sub_department, 'parent_id'=>$parentId])->update(['department'=>$sub_department, 'updated_at'=>now(), 'updated_by'=>$user_id]);
                }
        }
        elseif($formType=="import"){
            $departments = $request->department;
            $sub_departments= $request->sub_department;
            foreach ($departments as $deptKey => $deptName) {

            // 1) check if department exists
            $department = DB::table('hrms_departments')
                ->where('department', $deptName)
                ->where('sub_institute_id',$sub_institute_id)
                ->where('parent_id', 0)
                ->first();

            if ($department) {
                $departmentId = $department->id;
            } else {
                // insert new department
                $departmentId = DB::table('hrms_departments')->insertGetId([
                    'department'           => $deptName,
                    'parent_id'            => 0,
                    'tasks'                => null,
                    'roles_responsibility' => $deptName,
                    'status'               => 1,
                    'is_calculated'        => 0,
                    'sub_institute_id'     => $sub_institute_id,
                    'created_by'           => $user_id,
                    'created_at'           => now(),
                ]);
                $i++;
            }

            // 2) insert sub-departments if not already exists
            if (isset($sub_departments[$deptKey])) {
                foreach ($sub_departments[$deptKey] as $subDeptName) {

                    $subDept = DB::table('hrms_departments')
                        ->where('department', $subDeptName)
                        ->where('parent_id', $departmentId)
                        ->where('sub_institute_id',$sub_institute_id)
                        ->first();

                    if (!$subDept) {
                        DB::table('hrms_departments')->insert([
                            'department'           => $subDeptName,
                            'parent_id'            => $departmentId,
                            'tasks'                => null,
                            'roles_responsibility' => $subDeptName,
                            'status'               => 1,
                            'is_calculated'        => 0,
                            'sub_institute_id'     => $sub_institute_id,
                            'created_by'           => $user_id,
                            'created_at'           => now(),
                        ]);
                    }
                }
                $i++;
            }
             $standard = DB::table('standard')
                ->where('name', $deptName)
                ->where('sub_institute_id', $sub_institute_id)
                ->first();
                if(empty($standard) && !isset($standard->id)){
                    $checkGrade = DB::table('academic_section')->where(['sub_institute_id'=>$sub_institute_id,'title'=>$request->org_type])->first();
                    if(isset($checkGrade->id)){
                        $gradeId = $checkGrade->id;
                    }
                    else{
                          $gradeId = DB::table('academic_section')->insertGetId([
                            'title'           => $request->org_type,
                            'short_name'            =>  $request->org_type,
                            'sort_order'                => 1,
                            'sub_institute_id'     => $sub_institute_id,
                            'created_by'           => $user_id,
                            'created_at'           => now(),
                        ]);
                    }
                    DB::table('standard')->insert([
                                'name'              => $deptName,
                                'grade_id'          => $gradeId,
                                'short_name'        => $deptName,
                                'sort_order'        => 1,
                                'sub_institute_id'  => $sub_institute_id,
                                'created_by'        => $user_id,
                                'created_at'        => now(),
                        ]);
                }
        }
        }

        if($i!=0){
            $res['status_code']=1;
            $res['message']="Data Add Successfully!";
        }else{
            $res['status_code']=0;
            $res['message']="Failed to Add, May be already Exists!";
        }
        return is_mobile($type, "add_department.create", $res);
    }

    public function Update(Request $request,$id)
    {
        // echo "<pre>";print_r($request->all());exit;
        $type = $request->input('type');
        $sub_institute_id = session()->get('sub_institute_id');

        $department_name = $request->department_name;
        $roles_responsibility = $request->roles_responsibility;
        $is_calculated = $request->is_calculated;
        $task = $request->tasks;
        $parent_id=0;

        if($request->has('parentDiv') && $request->parentDiv!=''){
            $parent_id = $request->parentDiv;
        }

        $update = DB::table('hrms_departments')->where('id',$id)->Update([
                'department'=>$department_name,
                'parent_id'=>$parent_id,
                'tasks'=>$task,
                'roles_responsibility'=>$roles_responsibility,
                'status'=>1,
                'is_calculated'=>$is_calculated,
                'sub_institute_id'=>$sub_institute_id
            ]);

        if($update){
            $res['status_code']=1;
            $res['message']="Updated Successfully!!";
        }else{
            $res['status_code']=0;
            $res['message']="Failed to Update!!";
        }
        return is_mobile($type, "add_department.create", $res);
    }

    public function destroy(Request $request,$id){

        $type = $request->input('type');
        $sub_institute_id = session()->get('sub_institute_id');

        $delete = DB::table('hrms_departments')->where('id',$id)->delete();
        DB::table('s_user_jobrole')->where('department_id',$id)->delete();

        if($delete){
            $res['status_code']=1;
            $res['message']="Deleted Successfully!!";
        }else{
            $res['status_code']=0;
            $res['message']="Failed to Delete!!";
        }
    }

    public function departmentEmpLists(Request $request){
        $sub_institute_id = session()->get('sub_institute_id');
        $emp_ids = explode(',',$request->emp_ids);
         return DB::table('tbluser')
         ->selectRaw('CONCAT_WS(" ",COALESCE(first_name,"-"),COALESCE(middle_name,"-"),COALESCE(last_name,"-")) as name,mobile')
        ->whereIn('id',$emp_ids)
        ->get()
        ->toArray();
    }

    public function subDepartmentList(Request $request){
        $sub_institute_id = session()->get('sub_institute_id');
        $depIds = $request->depId;

         return DB::table('hrms_departments')
        ->whereRaw('parent_id in ('.$depIds.')')
        ->where('sub_institute_id',$sub_institute_id)
        ->groupBy('id')
        ->get()
        ->toArray();
    }

    public function departmentEmployeeList(Request $request){
        $sub_institute_id = session()->get('sub_institute_id');
        $depIds = $request->depId;
        $where = "(department_id in ($depIds)";
        
        if($request->has('subDepId')){
            $subDepIds = $request->subDepId;
            $where .= " OR department_id in ($subDepIds))";
        }else{
            $where .= " AND 1=1)";
        }
         return DB::table('tbluser')
         ->selectRaw('id,CONCAT_WS(" ",COALESCE(first_name,"-"),COALESCE(middle_name,"-"),COALESCE(last_name,"-")) as name,mobile')
        ->whereRaw($where)
        ->where('sub_institute_id',$sub_institute_id)
        ->where('status',1)
        ->groupBy('id')
        ->get()
        ->toArray();
    }
}
