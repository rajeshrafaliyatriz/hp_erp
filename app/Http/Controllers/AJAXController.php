<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\libraries\skillLibraryModel;
use function App\Helpers\is_mobile;
use App\Models\libraries\jobroleSkillModel;
use App\Models\libraries\industryModel;
use Laravel\Sanctum\PersonalAccessToken;
use App\Models\libraries\jobroleModel;
use App\Models\libraries\userSkills;
use App\Models\DynamicModel;
use App\Models\school_setup\subjectModel;
use App\Models\school_setup\standardModel;
use App\Models\school_setup\academic_sectionModel;
use Validator;
use Schema;
use DB;

class AJAXController extends Controller
{
    public function GetTableData(Request $request){
    	
        if (!$request->has('table')) {
            return response()->json(['error' => 'Table name is required'], 400);
        }

        // Get the table name from the request
        $table = $request->table;

        // Validate if the table exists
        if (!Schema::hasTable($table)) {
            return response()->json(['error' => 'Invalid table name'], 400);
        }

        // Start query
        $query = DB::table($table);

        // Apply filters if provided
        if ($request->has('filters') && is_array($request->filters)) {
            foreach ($request->filters as $column => $value) {
                if (Schema::hasColumn($table, $column)) {
                    $query->where($column, $value);
                }
            }
        }

        // Fetch data
        $data = $query->get();

        // Check if data is empty
	    if ($data->isEmpty()) {
	        return response()->json(['message' => 'Data not found'], 404);
	    }

        return response()->json($data);
    }

    public function searchSkill(Request $request){
        $type=$request->type;
        if($type=='API'){
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
                'searchWord' => 'required',
            ]);

            if($validator->fails()){
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
        }
        if($request->has('searchType') && $request->searchType=="jobrole"){
            // echo "here";exit;
            $res['searchData'] = jobroleModel::where('jobrole', 'like', '%'.$request->searchWord.'%')->where('industries','like','%'.$request->org_type.'%') ->pluck('jobrole')
            ->values();
        }else{
            // echo "else here";exit;
            $res['searchData'] = skillLibraryModel::where('title', 'like', '%'.$request->searchWord.'%')->get();
        }
        if($res['searchData']->isNotEmpty()){
            $res['status_code'] = 1;
            $res['message'] = 'Search results found';
        }else{
            $res['status_code'] = 0;
            $res['message'] = 'Search results filed to found';
        }
        return is_mobile($type, 'skill_library.index', $res,'redirect');
    }

    public function collectsct(Request $req)
    {
        $option = '<option value="">Select</option>';
        if ($req->sectionId == 1) {
            $academy = academic_sectionModel::where('sub_institute_id', $req->session()->get('sub_institute_id'))->get(['id', 'title', 'short_name', 'sort_order', 'shift', 'medium']);
            foreach ($academy as $row) {
                $option .= '<option value=' . $row['id'] . '>' . $row['title'] . '</option>';
            }
        } else if ($req->sectionId == 2) {
            $std = standardModel::where('sub_institute_id', $req->session()->get('sub_institute_id'))->get(['id', 'short_name']);
            foreach ($std as $row) {
                $option .= '<option value=' . $row['id'] . '>' . $row['short_name'] . '</option>';
            }
        } else if ($req->sectionId == 3) {
            
        } else if ($req->sectionId == 5) {
            $std = standardModel::where(['sub_institute_id' => $req->session()->get('sub_institute_id'), 'grade_id' => $req->grade])->get(['id', 'short_name']);
            foreach ($std as $row) {
                $option .= '<option value=' . $row['id'] . '>' . $row['short_name'] . '</option>';
            }
        }
        return $option;
    }
    public function getStandardList(Request $request)
    {
        $path = $_SERVER['HTTP_REFERER'] ?? URL::current();

        if ($path) {
            $parsedUrl = parse_url($path);
            
            if (isset($parsedUrl['path'])) {
                $pathParts = pathinfo($parsedUrl['path']);
                
                if (isset($pathParts['filename'])) {
                    $module_name = $pathParts['filename'];
                }
                if($parsedUrl['path'] == '/lms/question_paper/create' || $parsedUrl['path'] == '/lms/question_paper/search'){
                    $module_name = 'question_paper';
                }
               
                $path2 = "/student/student_homework/create";
                $keyword2 = "create";
              
                if (strpos($path2, $keyword2) !== false) {
                    $module_name = "student_homework";
                }
            }
        }

        $module_array = [
            '1' => 'student_homework',
            '2' => 'marks_entry',
            '3' => 'dicipline',
            '4' => 'lmsExamwise_progress_report',
            '5' => 'questionReport',
            '6' => 'parent_communication',
            '7' => 'question_paper',
            '8' => 'co_scholastic_marks_entry',                        
        ];

        $explode = explode(',', $request->grade_id);
        // menu_ids to get class teacher class only
        // menu_ids to get class teacher class only
        if(session()->get('sub_institute_id')==195){
            $menu_ids = [80,102];
        }else{
            // $menu_ids = [80,102,156];
            $menu_ids=[];

        }
        // added on 07-03-2025 for standalone modules end 

        $type = $request->type;
        $sub_institute_id = session()->get('sub_institute_id');
        $syear = session()->get('syear');
        $user_id = session()->get('user_id');
        if($type=='webForm'){
            $sub_institute_id = $request->sub_institute_id ?? 0;
            $syear = $request->syear ?? 0;
            $user_id = $request->user_id ?? 0;
        }
        // added on 07-03-2025 for standalone modules end 
        
        $studentData = [];
        
        $getClass=[];

        $query = DB::table('standard');
        // $query->where("grade_id", $request->grade_id);

        if (count($explode) > 1) {
            $query->whereIn("grade_id", $explode);
            //START Check for class teacher assigned standards
            $classTeacherStdArr = session()->get('classTeacherStdArr');

            if (is_array($classTeacherStdArr)) {
                $checkstd = count($classTeacherStdArr) > 0;
            } else {
                $checkstd = '1=1';
            }
            if ($checkstd && $classTeacherStdArr != "" && !in_array($module_name, $module_array)) {
                if(in_array(session()->get('right_menu_id'),$menu_ids) && session()->get('user_profile_name')=="Teacher"){
                    $query->where('id', $getClass->standard_id);
                }else{
                    $query->whereIn('id', $classTeacherStdArr);
                }
            }
            //END Check for class teacher assigned standards

            //START Check for subject teacher assigned
            $subjectTeacherStdArr = session()->get('subjectTeacherStdArr');
            if ($subjectTeacherStdArr != "" && ($classTeacherStdArr == "" || in_array($module_name, $module_array))) {
                if(in_array(session()->get('right_menu_id'),$menu_ids) && session()->get('user_profile_name')=="Teacher"){
                    $query->where('id', $getClass->standard_id);
                }else{
                $query->whereIn('id', $subjectTeacherStdArr);
                }
            }
            //END Check for subject teacher assigned
               // for student 01-01-2025 start
              
                if(session()->get('user_profile_name')=="Student"){
                    $query->where('id', [$studentData->standard_id ?? 0 ]);
                }
                // for student 01-01-2025 end

        } else {

            $query->where("grade_id", $request->grade_id);
            //START Check for class teacher assigned standards
            $classTeacherStdArr = session()->get('classTeacherStdArr');
            if (is_array($classTeacherStdArr)) {
                $checkstd = count($classTeacherStdArr) > 0;
            } else {
                $checkstd = '1=1';
            }
            if ($checkstd && $classTeacherStdArr != "" && !in_array($module_name, $module_array)) {
                if(in_array(session()->get('right_menu_id'),$menu_ids) && session()->get('user_profile_name')=="Teacher"){
                    $query->where('id', $getClass->standard_id);
                }else{
                $query->whereIn('id', $classTeacherStdArr);
                }
            }
            //END Check for class teacher assigned standards

            //START Check for subject teacher assigned
            $subjectTeacherStdArr = session()->get('subjectTeacherStdArr');
            if ($subjectTeacherStdArr != "" && ($classTeacherStdArr == "" || in_array($module_name, $module_array))) {
                if(in_array(session()->get('right_menu_id'),$menu_ids) && session()->get('user_profile_name')=="Teacher" && isset($getClass->standard_id)){
                    $query->where('id', $getClass->standard_id);
                }else{
                $query->whereIn('id', $subjectTeacherStdArr);
                }
            }

            // for student 01-01-2025 start
              
            if(session()->get('user_profile_name')=="Student"){
                $query->where('id', [$studentData->standard_id ?? 0 ]);
            }
            // for student 01-01-2025 end
            //END Check for subject teacher assigned
        }
        $standard = $query->pluck("name", "id");

        // echo session()->get('right_menu_id')
        return response()->json($standard);
        // return $classTeacherStdArr;
    }
}
