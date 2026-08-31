<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use App\Models\user\tbluserModel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Laravel\Sanctum\PersonalAccessToken;
use function App\Helpers\is_mobile;
use Illuminate\Support\Facades\Storage;
use App\Models\libraries\skillJobroleMap;
use App\Models\skill\matrix;
use Validator;

class userReportController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @param  Request  $request
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @return Response
     */
    public function index(Request $request)
    {
        $type = $request->input('type');
        $tblcustom_fields = $this->customFields($request);

        $tblProfiles = DB::table("tbluserprofilemaster")
            ->where(["sub_institute_id" => session()->get('sub_institute_id')])
            ->orderBy('sort_order', 'asc')
            ->pluck("name", "id");

        $res['status_code'] = 1;
        $res['message'] = "Success";
        $res['data'] = $tblcustom_fields;
        $res['profiles'] = $tblProfiles;

        return is_mobile($type, "user/show_user_report", $res, "view");
    }

    public function customFields(Request $request)
    {
        $sub_institute_id = session()->get('sub_institute_id');
        $tblcustoms = DB::table("tblcustom_fields")
        ->whereRaw("status=1 AND (common_to_all= 1 or sub_institute_id=$sub_institute_id) AND is_deleted != 'Y'")
        ->where('user_type','staff')
        ->orderByRaw('tab_sort_order,sort_order')
        ->get()->toArray();    
        
        $headerType =[];
        foreach ($tblcustoms as $key => $value) {
            $headerType[$value->column_header][]=$value;
        }
        return $headerType;

    }

    public function searchUser(Request $request)
    {
        $profile = $request->input("profile");
        $status = $request->input("status");
        $type = $request->input('type');
        $sub_institute_id = $request->session()->get('sub_institute_id');
        $syear = $request->session()->get('syear');

        $tblProfiles = DB::table("tbluserprofilemaster")
            ->where(["sub_institute_id" => session()->get('sub_institute_id')])
            ->orderBy('sort_order', 'asc')
            ->pluck("name", "id");

        $header = $array =[];
        $searchArr = ['_'];
        $replaceArr = [' '];
        if ($request->input('dynamicFields') == '') {
            $res['status_code'] = 0;
            $res['message'] = "Please select one checkbox atlease to view report";

            return is_mobile($type, "user_report.index", $res);
        }
        foreach ($request->input('dynamicFields') as $key => $fieldValue) {
            $seprateVal = explode('/',$fieldValue);
            $value = $seprateVal[0];
            $fieldId = $seprateVal[1];
            $value1 = str_replace($searchArr, $replaceArr, $value);
            if($value=="user_name"){
                $array[] = 'CONCAT_WS(" ", tbluser.first_name, tbluser.middle_name, tbluser.last_name) AS user_name';
                $header[$value] = ucfirst($value1);
            }else{
                $customDetails = DB::table("tblcustom_fields")
                ->whereRaw("status=1 AND (common_to_all= 1 or sub_institute_id=$sub_institute_id) AND is_deleted != 'Y'")
                ->where('id',$fieldId)
                ->where('user_type','staff')
                ->first();
                if(!empty($customDetails) && !in_array($value,["user_name"])){
                    $array[] = $customDetails->table_name.".".$value." as ".str_ireplace(" ","_",$customDetails->field_label);
                    $makeKey = strtolower(str_replace(" ","_",$customDetails->field_label));
                    $header[$makeKey] = ucfirst(str_replace(['_'], [' '], str_replace($searchArr, $replaceArr, $customDetails->field_label)));
                }else{
                    $header[$value] = ucfirst($value1);
                }
            }
        }
        $extraSearchArray = [];
        $extraSearchArray['tbluser.sub_institute_id'] = $sub_institute_id;
        $extraSearchArray['tbluser.status'] = $status;
        $extraSearchArray['tbluser.user_profile_id'] = $profile;

        $user_data = tbluserModel::select(DB::raw(strtolower(implode(',', $array))))
            ->join('tbluserprofilemaster', 'tbluser.user_profile_id', '=', 'tbluserprofilemaster.id')
            ->leftJoin('hrms_departments','hrms_departments.id','=','tbluser.department_id')
            ->leftJoin('tbluser_past_education','tbluser_past_education.user_id','=','tbluser.id')
            ->where($extraSearchArray)
            ->get();
            // echo "<pre>";print_r($header);exit;
        
        $res['status_code'] = 1;
        $res['message'] = "Student List";
        $res['user_data'] = $user_data;
        $res['data'] = $this->customFields($request);
        $res['headers'] = $header;
        $res['profiles'] = $tblProfiles;
        $res['profile'] = $profile;
        $res['status'] = $status;
        $res['dynamicFields']= $request->input('dynamicFields');

        return is_mobile($type, "user/show_user_report", $res, "view");

    }

    public function employeeReport(Request $request){
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
            'employee_id' =>'required',
        ]);

        // If validation fails
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 400);
        }

        $sub_institute_id = $request->input('sub_institute_id');
        $syear = $request->input('syear');
        $employee_id = $request->input('employee_id');

        $employeeData = tbluserModel::selectRaw('tbluser.*,tbluserprofilemaster.name as profile_name,s_user_jobrole.jobrole')
        ->join('tbluserprofilemaster', 'tbluser.user_profile_id', '=', 'tbluserprofilemaster.id')
        ->leftJoin('s_user_jobrole','s_user_jobrole.id','=','tbluser.allocated_standards')
        ->where(['tbluser.sub_institute_id'=>$sub_institute_id,'tbluser.id'=>$employee_id])
        ->orderBy('tbluser.id','desc')
        ->first();

        if ($employeeData && $employeeData->image) {
            $employeeData->image = Storage::disk('digitalocean')->url('public/hp_user/' . $employeeData->image);
        }
        if ($employeeData && $employeeData->status) {
            $employeeData->status = ($employeeData->status==1) ? 'Active' : 'In-active';
        }

        $alreadyRated = matrix::where('user_id', $employee_id)->get()->toArray();
        $ratedIds = [];
        foreach ($alreadyRated as $rated) {
            $ratedIds[] = $rated['skill_id'] ?? 0;
        }
        $skillData = skillJobroleMap::with([
                'userSkills'=> function($query) use($ratedIds) {
                    $query->whereNotIn('id', $ratedIds);
                }
            ])
            ->where('jobrole', $employeeData->jobrole)
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

        $certificateData = DB::table('staff_document')->select('staff_document.*', 'd.document_type')
            ->join('student_document_type as d', 'd.id', 'staff_document.document_type_id')
            ->where(['sub_institute_id' => $sub_institute_id, 'user_id' => $employee_id])
            ->get()
            ->toArray();

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
            ->where("t.TASK_ALLOCATED_TO", $employee_id)
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

        /*
         * APPROVED AND REJECTED WERE REPORTED THE WRONG WAY ROUND.
         *
         * This selected `approve_status = 'approved' AS rejected_count` and
         * `= 'rejected' AS approved_count` — so every employee report showed the
         * two figures swapped. Not a rounding error or an edge case: an employee
         * with one rejection was reported as having one approval, and vice versa.
         *
         * LOWER() because the column has drifted. Live holds 'approved',
         * 'rejected' and 'PENDING' — lowercase words beside an uppercase one — so
         * the old `= 'pending'` comparison silently missed every uppercase row.
         * A count that quietly omits rows is the same class of wrong as a count
         * that labels them backwards.
         */
        $taskDetail = DB::table("task as t")
        ->selectRaw("
            COUNT(t.id) AS total_task,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(t.approve_status,''))) = 'pending'  THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(t.approve_status,''))) = 'approved' THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(t.approve_status,''))) = 'rejected' THEN 1 ELSE 0 END) AS rejected_count
        ")
        ->where("t.SYEAR", $syear)
        ->where("t.TASK_ALLOCATED_TO", $employee_id)
        ->where("t.sub_institute_id", $sub_institute_id)
        ->whereNull('t.deleted_at')
        ->groupBy('t.TASK_ALLOCATED_TO')
        ->get();

        $res['status'] = 1;
        $res['message'] = "Success";
        $res['employeeData'] = $employeeData;
        $res['skillData'] = $skillData;
        $res['certificateData'] = $certificateData;
        $res['taskDetail'] = $taskDetail;
        $res['taskData'] = $taskData;

        return response()->json($res);
    }
}
