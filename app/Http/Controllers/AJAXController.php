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
use Illuminate\Support\Facades\Schema; // Import the Schema facade
use Illuminate\Database\Schema\Blueprint; 
use DB;

class AJAXController extends Controller
{
    public function GetTableData(Request $request)
    {
        // 1. Basic validation for table name presence
        if (!$request->has('table')) {
            return response()->json(['error' => 'Table name is required'], 400);
        }

        // Get the table name from the request
        $table = $request->table;

        // 2. IMPORTANT: Validate table name format to prevent SQL Injection
        // Only allow alphanumeric characters and underscores
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return response()->json(['error' => 'Invalid table name format.'], 400);
        }

        // 3. Manually validate if the table exists to bypass Schema::hasTable()
        try {
            $tableExists = DB::table('information_schema.tables')
                             ->where('table_schema', DB::raw('DATABASE()')) // Current database
                             ->where('table_name', $table)
                             ->exists();

            if (!$tableExists) {
                return response()->json(['error' => 'Table "' . $table . '" does not exist.'], 404);
            }
        } catch (\Exception $e) {
            // Catch database connection errors or other unexpected issues during the check
            \Log::error('Database error checking table existence: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['error' => 'An internal server error occurred while validating the table.'], 500);
        }

        // Start query using the validated table name
        $query = DB::table($table);

        // Apply filters if provided
        if ($request->has('filters') && is_array($request->filters)) {
            foreach ($request->filters as $column => $value) {
                // 4. IMPORTANT: Validate column name format for security
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
                    // Skip invalid column names or return an error
                    \Log::warning('Attempted to filter by invalid column name: ' . $column);
                    continue; // Skip this filter
                    // OR: return response()->json(['error' => 'Invalid column name format in filters.'], 400);
                }

                // 5. Manually validate if the column exists to bypass Schema::hasColumn()
                try {
                    $columnExists = DB::table('information_schema.columns')
                                      ->where('table_schema', DB::raw('DATABASE()'))
                                      ->where('table_name', $table)
                                      ->where('column_name', $column)
                                      ->exists();

                    if ($columnExists) {
                        $query->where($column, $value);
                    } else {
                        // Log or handle case where filter column doesn't exist
                        \Log::warning('Attempted to filter by non-existent column: ' . $column . ' on table ' . $table);
                        // Optionally, you might want to return an error here if a non-existent column is critical
                        // return response()->json(['error' => 'Column "' . $column . '" does not exist in table "' . $table . '".'], 400);
                    }
                } catch (\Exception $e) {
                    \Log::error('Database error checking column existence: ' . $e->getMessage(), ['exception' => $e]);
                    return response()->json(['error' => 'An internal server error occurred while validating a filter column.'], 500);
                }
            }
        }
        // get entry sort_order wise
        if($request->has('sort_order') && $request->sort_order!=''){
            $query->orderBy($request->sort_order);
        }

        // Fetch data
        try {
            $data = $query->get();
        } catch (\Exception $e) {
            // Catch errors during data fetching (e.g., malformed queries, database down)
            \Log::error('Database error fetching data for table ' . $table . ': ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['error' => 'An internal server error occurred while fetching data.'], 500);
        }


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
            $res['searchData'] = jobroleModel::where('jobrole', 'like', '%'.$request->searchWord.'%')->pluck('jobrole')
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

     public function getSubjectList(Request $request)
    {
        $standard_id = $request->standard_id;
        $explode = explode(',', $request->standard_id);

        $arr = $request->server;
        $HTTP_REFERER = "";
        foreach ($arr as $id => $val) {
            if ($id == 'HTTP_REFERER') {
                $HTTP_REFERER = $val;
            }
        }
        $refer_arr = explode('/', $HTTP_REFERER);
        $requestUri = $request->server->get('REQUEST_URI');

        // echo "<pre>";print_r($standard_id);exit;
        if (strpos($requestUri, 'lms/pal') !== false || (isset($refer_arr[count($refer_arr) - 2]) || $refer_arr[count($refer_arr) - 2] == 'exam_creation') || in_array('marks_entry', $refer_arr)) {
            $where = array(
                "sub_std_map.sub_institute_id" => session()->get('sub_institute_id'),
                "sub_std_map.allow_grades" => "Yes",
            );
        } else {
            $where = array(
                "sub_std_map.sub_institute_id" => session()->get('sub_institute_id'),
            );
        }
        if (count($explode) > 1) {
            $std_sub_map = DB::table('subject')
                ->join('sub_std_map', 'subject.id', '=', 'sub_std_map.subject_id')
                ->whereIn("sub_std_map.standard_id", $explode)
                ->where($where)
                ->orderBy('sub_std_map.sort_order')
                ->pluck('sub_std_map.display_name', 'subject.id');
        } else {
            if (session()->get('user_profile_name') == 'Teacher') {
                # Get subjects by teacher, standard and division
                $std_sub_map = DB::table('subject as sub')
                    ->whereIn('sub.id', function ($sub_query) use ($request) {
                        $sub_query->select('subject_id')
                            ->from('timetable')
                            ->where('teacher_id', session()->get('user_id'))
                            ->where('standard_id', $request->standard_id)
                            ->where('division_id', $request->division_id);
                    })
                    ->pluck('sub.subject_name as display_name', 'sub.id');
            } else {
                $where['sub_std_map.standard_id'] = $request->standard_id;
                $std_sub_map = DB::table('subject')
                    ->join('sub_std_map', 'subject.id', '=', 'sub_std_map.subject_id')
                    ->where($where)
                    ->orderBy('sub_std_map.sort_order')
                    ->pluck('sub_std_map.display_name', 'subject.id');
            }
        }

        return response()->json($std_sub_map);
    }

    public function ajax_checkEmailExist(Request $request)
	{
		// $email = $request->input("email");
		
        // $check_user_sql =DB::table('tbluser')
        //         ->select('id', 'email', DB::raw("'user' as user_type"))
        //         ->where('email', $email)
        //         ->get();

		// if (count($check_user_sql) == 0) {
		// 	return 0;
		// } else {
		// 	return 1;
		// }
	}
}
