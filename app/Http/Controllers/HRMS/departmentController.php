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

    public function departmentJobRoles(Request $request)
    {
        $depId = $request->input('depId');

        if (empty($depId)) {
            return response()->json([]);
        }

        $jobRoles = DB::table('s_jobrole')
            ->select('id', 'jobrole')
            ->where('track', $depId)
            ->orderBy('jobrole')
            ->get();

        return response()->json($jobRoles);
    }

    public function jobRoleTasks(Request $request)
    {
        $jobrole = $request->input('jobrole');

        if (empty($jobrole)) {
            return response()->json([]);
        }

        $jobroleName = $jobrole;
        if (is_numeric($jobrole)) {
            $jobroleName = DB::table('s_jobrole')->where('id', $jobrole)->value('jobrole') ?? $jobrole;
        }

        $tasks = DB::table('s_jobrole_task')
            ->select('id', 'task', 'critical_work_function')
            ->where('jobrole', $jobroleName)
            ->orderBy('task')
            ->get()
            ->map(function ($task) {
                return [
                    'id' => $task->id,
                    'task_title' => $task->task,
                    'task_description' => $task->critical_work_function,
                ];
            })
            ->values();

        return response()->json($tasks);
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
        // Bound, not interpolated. $sub_institute_id reaches this query from
        // the session, so it is not attacker-controlled today - but a value
        // pasted into SQL is one refactor away from being unsafe, and there is
        // no reason for it to be a string here rather than a parameter.
        DB::raw("IFNULL((select count(DISTINCT id) from tbluser where department_id = sub.id and sub_institute_id = ? and status = 1), '-') as total_emp"),
        DB::raw("IFNULL((select group_concat(DISTINCT id) from tbluser where department_id = sub.id and sub_institute_id = ? and status = 1), '-') as emp_ids")
    )
    ->addBinding([$sub_institute_id, $sub_institute_id], 'select')
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
            DB::Raw('IFNULL((select count(DISTINCT id) from tbluser where department_id = sub.id and sub_institute_id = ? and status=1),"-") as total_emp'),
            DB::Raw('IFNULL((select group_concat(DISTINCT id) from tbluser where department_id = sub.id and sub_institute_id = ? and status=1),"-") as emp_ids')
        )
        ->addBinding([$sub_institute_id, $sub_institute_id], 'select')
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

                DB::table('hrms_departments')->where(['sub_institute_id'=>$sub_institute_id,'department'=>$request->old_department, 'parent_id'=>0])->update(['department'=>$request->department, 'updated_at'=>now(), 'updated_by'=>$user_id]);

                if(empty($checkSubDepartment) && !isset($checkSubDepartment->id)){
                    $updateArray['sub_department'] = $sub_department;
                    $updateArray['created_at'] = now();
                    $updateArray['created_by'] = $user_id;
                }
            }
            else if($request->has('sub_department')){
                $updateArray = [
                    'sub_institute_id'=>$sub_institute_id,
                    'department'=>$request->department,
                    'updated_at'=>now(),
                    'updated_by'=>$user_id
                ];

                DB::table('hrms_departments')->where(['sub_institute_id'=>$sub_institute_id,'department'=>$request->old_department, 'parent_id'=>0])->update(['department'=>$request->department, 'updated_at'=>now(), 'updated_by'=>$user_id]);

                if(empty($checkSubDepartment) && !isset($checkSubDepartment->id)){
                    $updateArray['sub_department'] = $sub_department;
                    $updateArray['created_at'] = now();
                    $updateArray['created_by'] = $user_id;

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
                }
            }
        }
        else if($formType=="edit sub_department"){
            $department = $request->department;
            $old_sub_department = $request->old_sub_department;
            $sub_department = $request->sub_department;

            /*
             * The parent lookup was a DB::raw() subquery with $department -
             * a raw request field - interpolated into it inside single quotes.
             * Of all the interpolation in this controller that was the one
             * that mattered: the others spliced a session integer, this one
             * spliced whatever the client typed into the department box,
             * straight into a WHERE clause.
             *
             * The very next line already resolved the same parent id with a
             * bound query. So this is not a new lookup - it is that one, moved
             * up to where it was needed, and the raw subquery deleted.
             */
            $parentId = DB::table('hrms_departments')
                ->where(['sub_institute_id'=>$sub_institute_id,'department'=>$department, 'parent_id'=>0])
                ->value('id');

            $checkSubDepartment = DB::table('hrms_departments')
                ->where(['sub_institute_id'=>$sub_institute_id,'department'=>$old_sub_department, 'parent_id'=>$parentId])
                ->first();

            if(!empty($checkSubDepartment) && isset($checkSubDepartment->id)){
                DB::table('hrms_departments')->where(['sub_institute_id'=>$sub_institute_id,'department'=>$old_sub_department, 'parent_id'=>$parentId])->update(['department'=>$sub_department, 'updated_at'=>now(), 'updated_by'=>$user_id]);
                $i=1;
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

        /*
         * THE TENANT FILTER BELONGS IN THE WHERE, NOT THE SET.
         *
         * This wrote `sub_institute_id` as a value while matching on `id`
         * alone. Two consequences, both reachable by any logged-in user
         * changing one number in the URL:
         *
         *   - it edited another organisation's department, and
         *   - it then MOVED that department into the caller's organisation,
         *     because the tenant column was part of the update payload.
         *
         * The row's tenant is not something an edit form gets to change, so it
         * is gone from the SET and is now the thing that scopes the WHERE.
         *
         * whereNull('deleted_at') stops an edit resurrecting a deleted
         * department: `status => 1` below would otherwise set a soft-deleted
         * row back to active while deleted_at stayed put, which is exactly the
         * inconsistent state the API's index() used to leak.
         */
        $update = DB::table('hrms_departments')
            ->where('id',$id)
            ->where('sub_institute_id',$sub_institute_id)
            ->whereNull('deleted_at')
            ->Update([
                'department'=>$department_name,
                'parent_id'=>$parent_id,
                'tasks'=>$task,
                'roles_responsibility'=>$roles_responsibility,
                'status'=>1,
                'is_calculated'=>$is_calculated,
                'updated_at'=>now()
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

    /**
     * Retire a department.
     *
     * WAS A HARD DELETE, ACROSS TENANTS, WITH NO REFERENCE CHECK. Three
     * separate problems in four lines:
     *
     *   1. `where('id',$id)` and nothing else - any logged-in user could
     *      destroy any organisation's department by its id.
     *   2. `->delete()` on a table the rest of the application soft-deletes.
     *      The row was gone, and so was any chance of undoing it.
     *   3. The LMS reuses hrms_departments as its "standard" table through
     *      seven foreign keys holding hundreds of rows of question banks,
     *      chapters and content. A hard delete either failed on the constraint
     *      or stranded that content. Nothing checked.
     *
     * It also hard-deleted the department's s_user_jobrole rows - employees'
     * job-role assignments - as a side effect of removing a department.
     */
    public function destroy(Request $request,$id){

        $type = $request->input('type');
        $sub_institute_id = session()->get('sub_institute_id');

        $department = DB::table('hrms_departments')
            ->where('id',$id)
            ->where('sub_institute_id',$sub_institute_id)
            ->whereNull('deleted_at')
            ->first();

        if(!$department){
            $res['status_code']=0;
            $res['message']="Department not found!!";
            return is_mobile($type, "add_department.create", $res);
        }

        $blocking = $this->lmsReferenceCounts($id);

        if($blocking !== []){
            $res['status_code']=0;
            $res['message']="This department is used as a standard by LMS content and cannot be deleted.";
            $res['references']=$blocking;
            return is_mobile($type, "add_department.create", $res);
        }

        DB::transaction(function () use ($id, $sub_institute_id) {
            DB::table('hrms_departments')
                ->where('id',$id)
                ->where('sub_institute_id',$sub_institute_id)
                ->update([
                    'status'=>0,
                    'deleted_at'=>now(),
                    'updated_at'=>now(),
                ]);

            // Soft, to match the department itself. s_user_jobrole already
            // uses SoftDeletes on its model, so this is the delete that model
            // would have performed.
            DB::table('s_user_jobrole')
                ->where('department_id',$id)
                ->whereNull('deleted_at')
                ->update(['deleted_at'=>now()]);
        });

        $res['status_code']=1;
        $res['message']="Deleted Successfully!!";

        return is_mobile($type, "add_department.create", $res);
    }

    /**
     * Turn a comma-separated request parameter into a list of integer ids.
     *
     * Three endpoints here took "1,2,3" style parameters and pasted them into
     * whereRaw(), so anything the caller sent became SQL. This is what makes
     * whereIn() usable instead: non-numeric entries are dropped rather than
     * escaped, because an id that is not a number is not an id.
     *
     * Returns [0] rather than [] when nothing survives - an empty whereIn()
     * matches no rows in Laravel, but relying on that leaves the caller's
     * intent ("no valid ids") indistinguishable from a bug.
     */
    private function idList($value): array
    {
        $ids = array_values(array_filter(
            array_map('intval', array_filter(explode(',', (string) $value), 'is_numeric')),
            fn ($id) => $id > 0
        ));

        return $ids === [] ? [0] : $ids;
    }

    /**
     * LMS rows pointing at this department through `standard_id`, per table.
     * Empty array means nothing references it.
     *
     * Mirrors DepartmentManagementController::lmsReferenceCounts(). The two
     * delete paths are separate controllers with separate auth models, and a
     * guard that only one of them honours is not a guard.
     */
    private function lmsReferenceCounts($departmentId): array
    {
        $tables = [
            'lms_question_master',
            'chapter_master',
            'content_master',
            'sub_std_map',
            'lms_curriculum',
            'lms_lesson_plan',
            'lms_flashcard',
        ];

        $counts = [];

        foreach($tables as $table){
            try {
                $count = DB::table($table)->where('standard_id',$departmentId)->count();
            } catch (\Throwable $e) {
                continue;
            }

            if($count > 0){
                $counts[$table] = $count;
            }
        }

        return $counts;
    }

    public function departmentEmpLists(Request $request){
        $sub_institute_id = session()->get('sub_institute_id');
        $emp_ids = $this->idList($request->emp_ids);

         return DB::table('tbluser')
         ->selectRaw('CONCAT_WS(" ",COALESCE(first_name,"-"),COALESCE(middle_name,"-"),COALESCE(last_name,"-")) as name,mobile')
        ->whereIn('id',$emp_ids)
        // This listed employees by id with no tenant filter at all, so an id
        // from any organisation returned that person's name and mobile number.
        ->where('sub_institute_id',$sub_institute_id)
        ->get()
        ->toArray();
    }

    public function subDepartmentList(Request $request){
        $sub_institute_id = session()->get('sub_institute_id');
        // Was: whereRaw('parent_id in ('.$depIds.')') - a request parameter
        // spliced straight into SQL. whereIn over a validated integer list
        // binds every value instead.
        $depIds = $this->idList($request->depId);

         return DB::table('hrms_departments')
        ->whereIn('parent_id',$depIds)
        ->where('sub_institute_id',$sub_institute_id)
        ->whereNull('deleted_at')
        ->groupBy('id')
        ->get()
        ->toArray();
    }

    public function departmentEmployeeList(Request $request){
        $sub_institute_id = session()->get('sub_institute_id');

        // Was two request parameters concatenated into a whereRaw() string.
        $depIds = $this->idList($request->depId);

        if($request->has('subDepId')){
            $depIds = array_values(array_unique(array_merge($depIds, $this->idList($request->subDepId))));
        }

         return DB::table('tbluser')
         ->selectRaw('id,CONCAT_WS(" ",COALESCE(first_name,"-"),COALESCE(middle_name,"-"),COALESCE(last_name,"-")) as name,mobile')
        ->whereIn('department_id',$depIds)
        ->where('sub_institute_id',$sub_institute_id)
        ->where('status',1)
        ->groupBy('id')
        ->get()
        ->toArray();
    }
}

