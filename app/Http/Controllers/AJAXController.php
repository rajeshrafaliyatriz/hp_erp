<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\libraries\skillLibraryModel;
use function App\Helpers\is_mobile;
use App\Http\Controllers\user\tbluserController;
use App\Http\Controllers\settings\instituteDetailController;
use App\Models\libraries\jobroleSkillModel;
use App\Models\libraries\industryModel;
use Laravel\Sanctum\PersonalAccessToken;
use App\Models\libraries\jobroleModel;
use App\Models\libraries\userSkills;
use App\Models\libraries\userJobroleModel;
use App\Models\DynamicModel;
use App\Models\school_setup\subjectModel;
use App\Models\school_setup\standardModel;
use App\Models\school_setup\academic_sectionModel;
use App\Http\Controllers\school_setup\masterSetupController;
use App\Http\Controllers\lms\questionmasterController;
use App\Http\Controllers\lms\contentController;
use App\Http\Controllers\lms\chapterController;
use PHPMailer\PHPMailer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Dompdf\Dompdf;
use Dompdf\Options;

class AJAXController extends Controller
{
    public function GetTableData(Request $request)
    {
        if($request->has('all_tables') && $request->all_tables==1){
            // Get all tables
            $tables = DB::select('SHOW TABLES');

            $dbNameKey = 'Tables_in_' . env('DB_DATABASE'); // key name differs by MySQL version
            $result = [];

            foreach ($tables as $table) {
                $tableName = $table->$dbNameKey;

                // Get table columns
                //$columns = Schema::getColumnListing($tableName);
                $columns = array_map(
                    fn($col) => $col->Field,
                    DB::select("SHOW COLUMNS FROM `$tableName`")
                );

                $result[] = [
                    'table' => $tableName,
                    'columns'    => $columns,
                ];
            }

            return response()->json($result);
        }

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
            // // Catch database connection errors or other unexpected issues during the check
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
                    continue; // Skip this filter
                    // OR: return response()->json(['error' => 'Invalid column name format in filters.'], 400);
                }

                // 5. Manually validate if the column exists to bypass Schema::hasColumn()
                try {
                    //check table has deleted_at
                    $hasDeletedAt = DB::table('information_schema.columns')
                        ->where('table_schema', DB::raw('DATABASE()'))
                        ->where('table_name', $table)
                        ->where('column_name', 'deleted_at')
                        ->exists();

                    if ($hasDeletedAt) {
                        $query->whereNull('deleted_at');
                    }
                    // other column
                    $columnExists = DB::table('information_schema.columns')
                        ->where('table_schema', DB::raw('DATABASE()'))
                        ->where('table_name', $table)
                        ->where('column_name', $column)
                        ->exists();

                    if ($columnExists) {
                        $query->where($column, $value);
                    } else {
                        // Log or handle case where filter column doesn't exist
                        // Optionally, you might want to return an error here if a non-existent column is critical
                        // return response()->json(['error' => 'Column "' . $column . '" does not exist in table "' . $table . '".'], 400);
                    }
                } catch (\Exception $e) {
                    return response()->json(['error' => 'An internal server error occurred while validating a filter column.'], 500);
                }
            }
        }

        // Apply item_type filter if provided
        if ($request->has('item_type')) {
            // Validate item_type column exists
            try {
                $itemTypeExists = DB::table('information_schema.columns')
                    ->where('table_schema', DB::raw('DATABASE()'))
                    ->where('table_name', $table)
                    ->where('column_name', 'item_type')
                    ->exists();

                if ($itemTypeExists) {
                    $query->where('item_type', $request->item_type);
                }
            } catch (\Exception $e) {
                // Handle error if needed
            }
        }

        // get entry sort_order wise
        if ($request->has('sort_order') && $request->sort_order != '') {
            $query->orderBy($request->sort_order);
        }
        // Apply order by if provided
        if ($request->has('order_by') && is_array($request->order_by)) {
            $orderColumn = $request->order_by['column'] ?? 'id';
            $orderDirection = strtolower($request->order_by['direction'] ?? 'asc');

            // Sanitize direction
            if (!in_array($orderDirection, ['asc', 'desc'])) {
                $orderDirection = 'asc';
            }

            // if ($orderColumn && Schema::hasColumn($table, $orderColumn)) {
            //     return $orderColumn;
            $query->orderBy($orderColumn, $orderDirection);
            // }
        }

        if ($request->has('group_by') && $request->group_by != '') {
            $query->groupBy($request->group_by);
        }

        // Fetch data
        try {
            $data = $query->get();
        } catch (\Exception $e) {
            // Catch errors during data fetching (e.g., malformed queries, database down)

            return response()->json(['error' => 'An internal server error occurred while fetching data.'], 500);
        }


        // Check if data is empty
        if ($data->isEmpty()) {
            return response()->json(['message' => 'Data not found'], 404);
        }

        return response()->json($data);
    }

    public function searchSkill(Request $request)
    {
        $type = $request->type;
        if ($type == 'API') {
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
                // 'searchWord' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
        }
        if ($request->has('searchType') && $request->searchType == "jobrole") {
            // echo "here";exit;
            $res['searchData'] = jobroleModel::where('jobrole', 'like', '%' . $request->searchWord . '%')->pluck('jobrole')
                ->values();
        }
        if ($request->has('searchType') && $request->searchType == "jobrole_lists") {
            // echo "here";exit;
            $res['searchData'] = userJobroleModel::where('sub_institute_id', $request->searchWord)->pluck('jobrole')
                ->values();
        } else if ($request->has('searchType') && $request->searchType == "industries") {
            // echo "here";exit;
            $res['searchData'] = userJobroleModel::where('sub_institute_id', $request->sub_institute_id)->where('industries', '!=', '')->groupBy('industries')->pluck('industries')
                ->values();
        } else if ($request->has('searchType') && $request->searchType == "department") {
            // echo "here";exit;
            $res['searchData'] = userJobroleModel::where('sub_institute_id', $request->sub_institute_id)
                ->when($request->has('searchWord') && $request->searchWord != '' && $request->searchWord != 'departments' && $request->searchWord != null, function ($query) use ($request) {
                    // Filter by industries if provided
                    $query->where('industries', $request->searchWord);
                })
                // ->where('industries',$request->searchWord)
                ->groupBy('department')->pluck('department')
                ->values();
        } else if ($request->has('searchType') && $request->searchType == "sub_department") {
            // echo "here";exit;
            $res['searchData'] = userJobroleModel::where('sub_institute_id', $request->sub_institute_id)->where('department', $request->searchWord)->groupBy('sub_department')->pluck('sub_department')
                ->values();
        } else if ($request->has('searchType') && $request->searchType == "category") {
            // echo "here";exit;
            // DB::enableQueryLog();
            $res['searchData'] = userSkills::where('sub_institute_id', $request->sub_institute_id)->whereNotNull('category')->groupBy('category')->pluck('category')
                ->values();
            // dd(DB::getQueryLog($res['searchData']));
            // echo $res['searchData'];exit;
        } else if ($request->has('searchType') && $request->searchType == "sub_category") {
            // echo "here";exit;
            $res['searchData'] = userSkills::where('sub_institute_id', $request->sub_institute_id)->where('category', $request->searchWord)->whereNotNull('sub_category')->groupBy('sub_category')->pluck('sub_category')
                ->values();
        } else if ($request->has('searchType') && $request->searchType == "users_jobrole") {
            // echo "here";exit;
            $res['searchData'] = DB::table('tbluser as tu')
                ->join('s_user_jobrole as sus', function ($join) use ($request) {
                    $join->on('tu.allocated_standards', '=', 'sus.id')->where('sus.sub_institute_id', $request->sub_institute_id);
                })
                ->select('tu.*', 'sus.jobrole as jobrole', 'sus.jobrole as jobroleTitle')
                ->where('tu.sub_institute_id', $request->sub_institute_id)
                ->where('tu.status', 1)
                ->groupBy('tu.allocated_standards')
                ->get();
            return is_mobile($type, 'skill_library.index', $res, 'redirect');
        } else if ($request->has('searchType') && $request->searchType == "jobrole_emp") {
            // echo "here";exit;
            $res['searchData'] = DB::table('tbluser')->where('sub_institute_id', $request->sub_institute_id)->where('allocated_standards', $request->searchWord)->get();
            return is_mobile($type, 'skill_library.index', $res, 'redirect');
        }
        // added on 26-07-2025
        else if ($request->has('searchType') && $request->searchType == "skillTaxonomy") {
            $mainDepartments = DB::table('s_users_skills')
                ->select('category as name', DB::raw('COUNT(*) as total'))
                ->where('sub_institute_id', $request->sub_institute_id)
                ->whereNotNull('category')
                ->whereNull('deleted_at')
                ->groupBy('category')
                ->get();
            // Then get all subdepartments grouped by category
            $subDepartments = DB::table('s_users_skills')
                ->whereNotNull('sub_category')
                ->select(
                    'category',
                    'sub_category as name',
                    DB::raw('COUNT(*) as total')
                )
                ->where('sub_institute_id', $request->sub_institute_id)
                ->whereNull('deleted_at')
                ->groupBy('category', 'sub_category')
                ->get()
                ->groupBy('category');

            // Build the final structure
            $departments = $mainDepartments->map(function ($dept, $index) use ($subDepartments) {
                $subs = $subDepartments->get($dept->name, collect());

                return [
                    'id' => $index + 1,
                    'category_name' => $dept->name,
                    'total' => $dept->total,
                    'subcategory' => $subs->map(function ($sub, $subIndex) {
                        return [
                            'id' => ($subIndex + 1) * 10 + 1, // Generate IDs like 11, 12, etc.
                            'subCategory_name' => $sub->name,
                            'total' => $sub->total,
                        ];
                    })->toArray(),
                ];
            });

            return response()->json($departments);
        } else {
            // echo "else here";exit;
            $res['searchData'] = skillLibraryModel::where('title', 'like', '%' . $request->searchWord . '%')->get();
        }

        if ($res['searchData']->isNotEmpty()) {
            $res['status_code'] = 1;
            $res['message'] = 'Search results found';
        } else {
            $res['status_code'] = 0;
            $res['message'] = 'Search results failed to found';
        }
        return is_mobile($type, 'skill_library.index', $res, 'redirect');
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
        $path = $_SERVER['HTTP_REFERER'] ?? '';

        if ($path) {
            $parsedUrl = parse_url($path);

            if (isset($parsedUrl['path'])) {
                $pathParts = pathinfo($parsedUrl['path']);

                if (isset($pathParts['filename'])) {
                    $module_name = $pathParts['filename'];
                }
                if ($parsedUrl['path'] == '/lms/question_paper/create' || $parsedUrl['path'] == '/lms/question_paper/search') {
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
        if (session()->get('sub_institute_id') == 195) {
            $menu_ids = [80, 102];
        } else {
            // $menu_ids = [80,102,156];
            $menu_ids = [];
        }
        // added on 07-03-2025 for standalone modules end 

        $type = $request->type;
        $sub_institute_id = session()->get('sub_institute_id');
        $syear = session()->get('syear');
        $user_id = session()->get('user_id');
        if ($type == 'webForm') {
            $sub_institute_id = $request->sub_institute_id ?? 0;
            $syear = $request->syear ?? 0;
            $user_id = $request->user_id ?? 0;
        }
        // added on 07-03-2025 for standalone modules end 

        $studentData = [];

        $getClass = [];

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

                $query->whereIn('id', $classTeacherStdArr);
            }
            //END Check for class teacher assigned standards

            //START Check for subject teacher assigned
            $subjectTeacherStdArr = session()->get('subjectTeacherStdArr');
            if ($subjectTeacherStdArr != "" && ($classTeacherStdArr == "" || in_array($module_name, $module_array))) {

                $query->whereIn('id', $subjectTeacherStdArr);
            }
            //END Check for subject teacher assigned
            // for student 01-01-2025 start

            if (session()->get('user_profile_name') == "Student") {
                $query->where('id', [$studentData->standard_id ?? 0]);
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

                $query->whereIn('id', $classTeacherStdArr);
            }
            //END Check for class teacher assigned standards

            //START Check for subject teacher assigned
            $subjectTeacherStdArr = session()->get('subjectTeacherStdArr');
            if ($subjectTeacherStdArr != "" && ($classTeacherStdArr == "" || in_array($module_name, $module_array))) {

                $query->whereIn('id', $subjectTeacherStdArr);
            }

            // for student 01-01-2025 start

            if (session()->get('user_profile_name') == "Student") {
                $query->where('id', [$studentData->standard_id ?? 0]);
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
            $std_sub_map = DB::table('sub_std_map')
                // ->join('sub_std_map', 'subject.id', '=', 'sub_std_map.subject_id')
                ->whereIn("sub_std_map.standard_id", $explode)
                ->where($where)
                ->orderBy('sub_std_map.sort_order')
                ->pluck('sub_std_map.display_name', 'sub_std_map.id');
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
                $std_sub_map = DB::table('sub_std_map')
                    // ->join('sub_std_map', 'subject.id', '=', 'sub_std_map.subject_id')
                    ->where($where)
                    ->orderBy('sub_std_map.sort_order')
                    ->pluck('sub_std_map.display_name', 'sub_std_map.id');
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

    public function getUsersMappings(Request $request)
    {
        $emp_id = $request->user_id ?? $request->emp_id;
        $sub_institute_id = $request->sub_institute_id;
        $getType = $request->getType ?? 'tasks'; // skills or tasks, default to tasks
        $res['status_code'] = 0;
        $res['message'] = 'User not found';
        $getEmp = DB::table('tbluser as u')
            ->join('tbluserprofilemaster as upm', 'upm.id', '=', 'u.user_profile_id')
            ->where('u.id', $emp_id)
            ->where('u.sub_institute_id', $sub_institute_id)
            ->where('u.status', 1)
            ->whereNull('u.deleted_at')
            ->first();

        if ($getEmp && $getType == "skills") {
            $getSkills = DB::table('s_jobrole as s')->join('s_user_skill_jobrole as sus', function ($join) {
                $join->on('s.jobrole', '=', 'sus.jobrole');
            })
                ->where('s.id', $getEmp->allocated_standards)
                ->whereNull('sus.deleted_at')
                ->get()->toArray();

            if (!empty($getSkills)) {
                $res['status_code'] = 1;
                $res['message'] = 'Skills found';
                $res['data'] = $getSkills;
            } else {
                $res['status_code'] = 0;
                $res['message'] = 'No skills found for this user';
            }
        } else if ($getEmp && $getType == "tasks") {
            $getTasks = DB::table('task as t')
                ->join('tbluser as u', 'u.id', '=', 't.task_allocated_to')
                ->join('tbluserprofilemaster as upm', 'upm.id', '=', 'u.user_profile_id')
                ->where('t.task_allocated_to', $emp_id)
                ->select('t.id as task_id', 't.task_title', 't.status', 'u.first_name', 'u.last_name', 'upm.name as user_role')
                ->get();

            if ($getTasks->isNotEmpty()) {
                $res['status_code'] = 1;
                $res['message'] = 'Tasks found';
                $res['data'] = $getTasks;
            } else {
                $res['status_code'] = 0;
                $res['message'] = 'No tasks found for this user';
            }
        }

        return $res;
    }

    // deepseek chat API integrtion
    public function DeepSeekChat(Request $request)
    {
        //rp2164394@gmail.com - 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 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

        // pasi pasi - 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

        // rp  - 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 openai/gpt-oss-20b:free

        $prompt = $request->message;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer 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',
            'HTTP-Referer' => env('APP_URL'),
        ])
            ->timeout(90)
            ->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => 'openai/gpt-4o-mini',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        $resBody = $response->json();

        $res = [
            'status' => 0,
            'message' => 'No response from DeepSeek API',
            'response' => '',
        ];

        if (isset($resBody['choices'][0]['message']['content'])) {
            $res['status'] = 1;
            $res['message'] = 'Success';
            $res['response'] = $resBody['choices'][0]['message']['content'];
        } else {
            $res['response'] = $response->json();
        }

        return $res;
    }

    public function AIassignTask(Request $request)
    {
        $controller = new tbluserController;
        $response = $controller->edit($request, $request->user_id);
        $userData = json_decode($response->getContent(), true);
        $res = [
            'status' => 0,
            'message' => 'No response from DeepSeek API',
            'response' => [],
        ];
        if (isset($userData['jobroleTasks']) && !empty($userData['jobroleTasks'])) {
            $jsonTasks = $jsonSkills = [];
            foreach ($userData['jobroleTasks'] as $key => $value) {
                $jsonTasks[] = $value['task'];
            }
            foreach ($userData['jobroleSkills'] as $key => $value) {
                $jsonSkills[] = $value['title'];
            }
            $jsonTaskEncode = json_encode($jsonTasks);
            $jsonSkillEncode = json_encode($jsonSkills);
            // make prompt to pass into Deepseek API
            $prompt = $jsonTaskEncode . $jsonSkillEncode . ' For each task in the JSON, classify it as "Daily Task", "Weekly Task", "Monthly Task", or "Yearly Task" based on its nature, and also assign the most relevant skill(s) from the provided skills array to each task. Return only a PHP array in the format: ["type" => [["task" => "task1", "skills" => ["skill1", "skill2"]], ...]], with no explanation or extra content.';

            $request->merge(['message' => $prompt]);
            // pass prompt into Deepseek API as message
            $chatResponse = $this->DeepSeekChat($request);
            $chatRes = $chatResponse['response'];
            if ($chatRes != '' && $chatResponse['status'] != 0) {
                $clean = preg_replace('/^```php\s*|\s*```$/', '', trim($chatRes));
                $taskData = [];
                eval('$taskData = ' . $clean . ';');
                $insert = 0;
                // ✅ Now $taskData is a real PHP array
                $taskController = new instituteDetailController;
                $insert = 0;

                // collect tasks into arr[]
                $arr = [];

                foreach ($taskData as $frequency => $tasks) {
                    foreach ($tasks as $taskItem) {
                        $arr[] = [
                            'TASK_ALLOCATED_TO' => [$userData['data']['id'] ?? 0],
                            'TASK_TITLE' => $taskItem['task'],
                            'TASK_DESCRIPTION' => $taskItem['task'],
                            'KRA' => null,
                            'KPA' => null,
                            'selType' => $frequency,
                            'TASK_ATTACHMENT' => null,
                            'manageby' => 1,
                            'skills' => $taskItem['skills'],
                            'TASK_DATE' => now(),
                            'observation_point' => null,
                        ];
                    }
                }

                if (count($arr)) {
                    // create bulk request with arr[]
                    $newReq = new Request([
                        'formName' => 'addTask',
                        'arr' => $arr ?? [],
                        'type' => 'API',
                        'sub_institute_id' => $request->sub_institute_id,
                        'syear' => $request->syear,
                        'user_id' => $request->user_id,
                    ]);

                    $taskStoreResponse = $taskController->store($newReq);
                    $responseData = $taskStoreResponse->getData();

                    $res['status'] = 1;
                    $res['message'] = 'Tasks added successfully';
                } else {
                    $res['status'] = 0;
                    $res['message'] = 'No valid task data found';
                }

                if ($insert > 0) {
                    $res = [
                        'status' => 1,
                        'message' => 'Task Added Succefully!',
                        'response' => $taskData,
                    ];
                }
            }

            // Ensure $chatResponse is an array before looping
            // if (!is_array($chatResponse)) {
            //     $chatResponse = json_decode($chatResponse, true) ?? [];
            // }

            // // Check if $chatResponse is iterable
            // if (is_array($chatResponse) || is_object($chatResponse)) {
            //     $res['message'] = "Fail to store tasks";
            //     foreach ($chatResponse as $frequency => $taskGroup) {
            //         // Ensure taskGroup is an array
            //         if (!is_array($taskGroup)) {
            //             continue;
            //         }

            //         foreach ($taskGroup as $taskData) {
            //             // Ensure taskData has the required structure
            //             if (!isset($taskData['task'])) {
            //                 continue;
            //             }

            //             $newReq = new Request([
            //                 'formName' => "addTask",
            //                 'TASK_ALLOCATED_TO' => $userData['data']['id'] ?? 0,
            //                 'TASK_TITLE' => $taskData['task'], 
            //                 'TASK_DESCRIPTION' => $taskData['task'],
            //                 'KRA' => null,
            //                 'KPA' => null,
            //                 'selType' => $frequency,
            //                 'TASK_ATTACHMENT' => null,
            //                 'manageby' => 1,
            //                 'skills' => isset($taskData['skills']) && is_array($taskData['skills']) 
            //                     ? implode(', ', $taskData['skills']) 
            //                     : '',
            //                 'TASK_DATE' => now(),
            //                 'observation_point' => null,
            //                 'type' => 'API',
            //                 'sub_institute_id' => $sub_institute_id,
            //                 'syear' => $syear,
            //                 'user_id' => $user_id
            //             ]);

            //             $taskController = new instituteDetailController;
            //             $storeTask = $taskController->store($newReq);
            //             $res['response'][] = $storeTask; // Store all responses
            //         }
            //     }
            // } else {
            //     $res['error'] = "Invalid chat response format";
            // }
        }
        return $res;
    }

    public function getSkillCompetency(Request $request)
    {
        //$subInstituteId = $request->get('sub_institute_id', 4); // default 3
        $type = $request->input('type');
        $sub_institute_id = $request->sub_institute_id ?? 2;

        $jobRoles = DB::table('s_user_jobrole')
            ->select('jobrole')
            ->where('sub_institute_id', $sub_institute_id)
            ->distinct()
            ->inRandomOrder()
            ->limit(50)
            ->pluck('jobrole'); // Use pluck if you only need an array of jobrole names

        $data = DB::table('s_skill_knowledge_ability as s')
            ->join('s_users_skills as u', function ($join) {
                $join->on('u.id', '=', 's.skill_id')
                    ->on('u.sub_institute_id', '=', 's.sub_institute_id');
            })
            ->join('s_user_skill_jobrole as us', function ($join) {
                $join->on('us.skill', '=', 'u.title')
                    ->on('us.sub_institute_id', '=', 'u.sub_institute_id');
            })
            ->join('s_user_jobrole as uj', function ($join) {
                $join->on('uj.jobrole', '=', 'us.jobrole')
                    ->on('uj.sub_institute_id', '=', 'us.sub_institute_id');
            })
            ->where('uj.sub_institute_id', $sub_institute_id)
            //->whereIn('uj.jobrole', $jobRoles)
            ->orderBy('uj.industries')
            ->orderBy('uj.department')
            ->orderBy('uj.jobrole')
            ->orderBy('u.category')
            ->orderBy('u.title')
            ->orderBy('s.classification')
            ->select([
                'uj.industries',
                'uj.department',
                'uj.jobrole',
                'uj.jobrole_category',
                'u.category',
                'u.sub_category',
                'u.title as skill_name',
                's.classification',
                's.classification_category',
                's.classification_sub_category',
                's.classification_item'
            ])
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $data
        ]);
    }

    public function sendEmail(Request $request)
    {
        $path = "";
        $type = $request->input('type');
        $sub_institute_id = $request->sub_institute_id;

        $where_arr = [
            "sub_institute_id" => $sub_institute_id,
        ];
        $smtp_details = DB::table('smtp_details')
            ->where($where_arr)
            ->whereNull('deleted_at')
            ->get();

        if (count($smtp_details) > 0) {
            $emails = $request->all_email;
            $to_arr = explode(',', $emails);

            $subject = $request->example_subject;
            $message = $request->content;
            $attechment = $path;

            //$ip = Request::ip();
            $ip = $request->ip();

            $from = $smtp_details[0]->gmail;
            $from_pass = $smtp_details[0]->password;

            $mail = new PHPMailer\PHPMailer();
            $mail->IsSMTP();
            $mail->isHTML(true);
            $mail->SMTPDebug = 0;
            $mail->SMTPAuth = true;
            $mail->SMTPSecure = "ssl";
            $mail->Host = $smtp_details[0]->server_address;
            $mail->Port = $smtp_details[0]->port;

            foreach ($to_arr as $id => $val) {
                $mail->AddAddress($val);
            }

            $mail->Username = $from;
            $mail->Password = $from_pass;
            $mail->SetFrom($from, $from);
            $mail->AddReplyTo($from, $from);
            $mail->addAttachment($attechment);
            $mail->Subject = $subject;
            $mail->Body = $message;
            $mail->AltBody = $message;

            if (!$mail->Send()) {
                $res = [
                    "status_code" => 0,
                    "message"     => "There is some error , while sending mail",
                ];
            } else {
                $res = [
                    "status_code" => 1,
                    "message"     => "Email Sent",
                ];
            }
        } else {
            $res = [
                "status_code" => 1,
                "message"     => "You did not setup mail client.",
            ];
        }

        return response()->json($res);
    }

    public function geminiChat(Request $request)
{
    $prompt = $request->input('prompt');

    // Fallback model list - ordered from preferred → fallback (Feb 2026 reality)
    $modelsToTry = [
        'gemini-2.5-flash',         // Fast, cheap, stable GA
        'gemini-flash-latest',      // Alias to newest Flash (good longevity)
        'gemini-2.5-pro',           // Stronger reasoning when needed
        'gemini-3-flash-preview',   // Newer preview (if your project allows)
        // Add more previews/experimental if needed: 'gemini-3-pro-preview', etc.
    ];

    // Collect usable keys (with quota left)
    $availableKeys = [];

    $todayUsed = DB::table('ai_daily_used_api')
        ->where('api_name', 'gemini')
        ->where('date', date('Y-m-d'))
        ->whereNull('sub_institute_id')
        ->get()
        ->keyBy('parent_id');

    $allActiveKeys = DB::table('gemini_api')
        ->where('status', 1)
        ->whereNull('sub_institute_id')
        ->orderBy('id')
        ->get();

    foreach ($allActiveKeys as $keyRow) {
        $usage = $todayUsed->get($keyRow->id);
        $currentCount = $usage ? $usage->count : 0;

        if ($currentCount < $keyRow->limit) {
            $availableKeys[] = [
                'key'       => $keyRow->key,
                'parent_id' => $keyRow->id,
                'count'     => $currentCount,
                'usage_id'  => $usage ? $usage->id : null,
            ];
        }
    }

    if (empty($availableKeys)) {
        return response()->json([
            'error' => 'No available Gemini API keys (all at limit or none active)',
        ], 503);
    }

    $lastError = null;
    $attemptKey = 0;

    foreach ($availableKeys as $keyEntry) {
        $attemptKey++;
        $geminiKey = $keyEntry['key'];
        $attemptModel = 0;

        foreach ($modelsToTry as $model) {
            $attemptModel++;

            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $geminiKey;

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->withoutVerifying()
            ->timeout(120)
            ->post($url, [
                "contents" => [
                    [
                        "parts" => [
                            ["text" => $prompt]
                        ]
                    ]
                ]
            ]);

            $httpStatus = $response->status();
            $data = $response->json();

            $text = $data['candidates'][0]['content']['parts'][0]['text']
                ?? $data['error']['message']
                ?? 'No content returned';

            // Clean markdown if present
            $cleanText = trim($text);
            $cleanText = preg_replace('/^```json\s*|\s*```$/m', '', $cleanText);

            $jsonData = json_decode($cleanText, true);

            // Success condition
            if (json_last_error() === JSON_ERROR_NONE && !empty($jsonData)) {
                // Update usage only on real success
                if ($keyEntry['usage_id']) {
                    DB::table('ai_daily_used_api')
                        ->where('id', $keyEntry['usage_id'])
                        ->increment('count');
                } else {
                    DB::table('ai_daily_used_api')->insert([
                        'api_name'   => 'gemini',
                        'key'        => $geminiKey,
                        'parent_id'  => $keyEntry['parent_id'],
                        'date'       => date('Y-m-d'),
                        'count'      => 1,
                    ]);
                }

                // Optional: log success
                // \Log::info("Gemini success", ['key' => substr($geminiKey,0,10).'...', 'model' => $model]);

                return response()->json($jsonData);
            }

            // Handle failure
            $errorMsg = json_last_error() !== JSON_ERROR_NONE
                ? 'JSON decode failed: ' . json_last_error_msg()
                : ($data['error']['message'] ?? 'Unknown response');

            $lastError = [
                'key_attempt' => $attemptKey,
                'model_attempt' => $attemptModel,
                'model'       => $model,
                'key_prefix'  => substr($geminiKey, 0, 10) . '...',
                'http_status' => $httpStatus,
                'message'     => $errorMsg,
            ];

            // Optional logging
            // \Log::warning("Gemini attempt failed", $lastError);

            // Early exit on clear "model not found" to save time
            if ($httpStatus === 404) {
                continue; // next model
            }

            // You could break here if error is non-recoverable (e.g. 401 auth), but for now continue
        }

        // If all models failed for this key → try next key
    }

    // All keys + all models failed
    return response()->json([
        'error'      => 'All available Gemini API keys and fallback models failed',
        'last_error' => $lastError,
        'attempts_keys'   => $attemptKey,
    ], 503);
}

    public function AICourseGeneration(Request $request)
    {
        $type = 'webForm';
        $skill_department = $request->department;
   $sub_institute_id = $request->sub_institute_id;
        $token = $request->token;
        $user_id = $request->user_id;
        $user_profile_name = $request->user_profile_name;
        $syear = $request->syear;
        $industry = $request->industry;
// check grade and add grade
        $checkGrade = DB::table('academic_section')->where(['sub_institute_id' => $sub_institute_id, 'title' => $industry])->whereNull('deleted_at')->get();
        if (count($checkGrade) > 0) {
            $grade = $checkGrade[0]->id;
        } else {
            $gradeInsert = DB::table('academic_section')->insertGetId([
                'sub_institute_id' => $sub_institute_id,
                'title' => $industry,
                'short_name' => $industry,
                'sort_order' => '1',
                'created_by' => $user_id,
                'created_at' => now()
            ]);
            $grade = $gradeInsert;
        }

        $checkStandard = DB::table('standard')->where(['sub_institute_id' => $sub_institute_id, 'grade_id' => $grade, 'name' => $skill_department])->whereNull('deleted_at')->get();
        if (count($checkStandard) > 0) {
            $standard = $checkStandard[0]->id;
        } else {
            $standardInsert = DB::table('standard')->insertGetId([
                'sub_institute_id' => $sub_institute_id,
                'grade_id' => $grade,
                'name' => $skill_department,
                'short_name' => $skill_department,
                'sort_order' => '1',
                'created_by' => $user_id,
                'created_at' => now()
            ]);
            $standard = $standardInsert;
        }
        $mappingTypes = DB::table('lms_mapping_type')->where('id', 1)->whereNull('deleted_at')->get()->pluck('name', 'id');
        $mappingValues = DB::table('lms_mapping_type')->where('parent_id', 1)->whereNull('deleted_at')->get()->pluck('name', 'id');
        $lms_content_category = DB::table('lms_content_category')->where('status', 2)->whereNull('deleted_at')->get()->pluck('category_name');
        $course_category = DB::table('lms_content_category')->where('status', 1)->whereNull('deleted_at')->get()->pluck('category_name');
        
        // Get request variables
        if($request->has('formType') && $request->formType == 'critical_work_function'){
            $prompt = $request->prompt;
        }elseif($request->has('formType') && $request->formType == 'task'){
            $prompt = $request->prompt;
        }elseif($request->has('formType') && $request->formType == 'skills'){
            $prompt = $request->prompt;
        }else{

     
        $skill_category = $request->skill_category;
        $skill_sub_category = $request->skill_sub_category;
        $skill_micro_category = $request->skill_micro_category;
        $skill_name = $request->skill_name;
        $skill_description = $request->skill_description;

        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        // Find the token in the database
        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $validator = Validator::make($request->all(), [
            'sub_institute_id' => 'required',
            'user_id' => 'required',
            'user_profile_name' => 'required',
            'syear' => 'required',
            'industry' => 'required',
            'department' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
        }

        $getJobroles = DB::table('s_user_skill_jobrole')->where(['sub_institute_id' => $sub_institute_id, 'skill' => $skill_name])->whereNull('deleted_at')->groupBy('jobrole')->get();
        // return $getJobroles;
        $jobroleLists = $proficiencyLists = [];
        foreach ($getJobroles as $key => $value) {
            $jobroleLists[] = $value->jobrole;
            $proficiencyLists[] = $value->proficiency_level;
        }
        $jobroleData = json_encode($jobroleLists);
        $proficencyData = json_encode($proficiencyLists);
        // return $mappingTypes;
        $prompt = "I have Skills Name: '" . $skill_name . "' of 
                    Industry: " . $industry . "
                    I have Skills Description: " . $skill_description . "
                    I have Skills Department: " . $skill_department . "
                    I have Skills Category: " . $skill_category . "
                    I have Skills Sub Category: " . $skill_sub_category;
        if ($jobroleData == true) {
            $prompt .= "
                    I have Skills Jobrole: " . $jobroleData . "
                    I have Skills Proficiency Level: " . $proficencyData;
        }
        $prompt .= "1.Understand the depth, complexity, and learning needs of the given skill.
                    2.Break the skill into logical continuous chapters, each representing a key theme.
                    3.For each chapter, create continuous content items with:
                        title
                        description
                        content_category any 1 from (" . $lms_content_category . ")
                        mapping_type only (Depth of Knowledge (Easy, Medium, Hard))
                        mapping_value (Easy/Medium/Hard)
                    4. Create question items with:  
                        question_title
                        description
                        mapping_type only (Depth of Knowledge (Easy, Medium, Hard))
                        mapping_value (Easy/Medium/Hard)
                        reason (why above mapping_value selected)
                    5. For each question item, create 4 answer item with:
                        answer
                        correct_answer (true/false)
                        feedback
                    6.Output Expectation:
                    Generate and return a structured JSON with the following fields:
                        - course_name
                        - short_name
                        - course_category any 1 of (" . $course_category . ")
                        - course_code
                        - course_type
                        - short_name
                        - chapters: list of chapters with:
                           -- chapter_name
                           -- chapter_description
                           -- contents: list of content items with:
                                --- content_title
                                --- content_description
                                --- content_html (Make Simple html[make label's bold and bg-color proper] with content title,content description,skill, skill description,skill category, skill sub category ,jobrole, jobrole description, list of tasks realted to content)
                                --- content_category any 1 from (" . $lms_content_category . ")
                                --- mapping_type only (Depth of Knowledge (Easy, Medium, Hard))
                                --- mapping_value (easy/medium/hard)
                            -- questions: list of question items with (always MCQ question):
                                --- question_title
                                --- description
                                --- mapping_type only (Depth of Knowledge (Easy, Medium, Hard))
                                --- mapping_value (easy/medium/hard)
                                --- reason (why this mapping_value selected)
                            -- answers: list of 4 corresponding answer items with only one correct answer:
                                --- answer
                                --- correct_answer (true as a 1/false as a 0)
                                --- feedback
                    Rules:
                    Align everything tightly with the skill context fields.
                    Use concise, engaging, and adult-learner-appropriate language.
                    Do not add explanations — only output the structured JSON.
                    provide me 1 course with atleast 3 content realted this skills > course > chapter. in JSON array";
        }

        $request->merge(['prompt' => $prompt]);
        $gemeniJson = $this->geminiChat($request);
        $gemeniData = json_decode(json_encode($gemeniJson->original), true);
        // return $gemeniData;
        $i = 0;
        if (!isset($gemeniData['error'])) {
            $courseData = $gemeniData;

            // Create course
            $courseController = new masterSetupController;
            $course_request = new Request([
                'type' => 'API',
                'sub_institute_id' => $sub_institute_id,
                'user_id' => $user_id,
                'token' => $token,
                'formType' => 'course',
                'subject_id' => $request->subject_id ?? 0,
                'standard_id' => $standard,
                'allow_grades' => 'Yes',
                'allow_content' => 'Yes',
                'sort_order' => '1',
                'elective_subject' => 'No',
                'add_content' => 'chapterwise',
                'display_name' => $courseData['course_name'] ?? '-',
                'display_image' => null,
                'subject_category' => $courseData['course_category'] ?? '-',
                'subject_code' => $courseData['course_code'] ?? '-',
                'subject_type' => $courseData['course_type'] ?? '-',
                'short_name' => $courseData['short_name'] ?? '-',
                'status' => 1,
            ]);

            $courseStore = $courseController->store($course_request);

            $courseId = $courseStore->original['course_id'];

            // Process chapters if they exist
            if (isset($courseData['chapters'])) {
                foreach ($courseData['chapters'] as $chapterKey => $chapterData) {
                    // Create chapter
                    $moduleController = new chapterController;
                    $chapter_request = new Request([
                        'type' => 'API',
                        'sub_institute_id' => $sub_institute_id,
                        'user_id' => $user_id,
                        'user_profile_name' => $user_profile_name,
                        'token' => $token,
                        'syear' => $syear,
                        'grade' => $grade,
                        'standard' => $standard,
                        'subject' => $courseId,
                        'chapter_name' => [$chapterData['chapter_name'] ?? '-'],
                        'chapter_desc' => [$chapterData['chapter_description'] ?? '-'],
                        'availability' => [1],
                        'show_hide' => [1],
                        'sort_order' => [1]
                    ]);

                    $chapterStore = $moduleController->store($chapter_request);
                    $chapterId = $chapterStore->original['chapter_id'];
                    // Get related data
                    $getstandard = DB::table('standard')
                        ->where(['sub_institute_id' => $sub_institute_id, 'id' => $standard])
                        ->whereNull('deleted_at')
                        ->first();
                    $getcourse = DB::table('sub_std_map')
                        ->where(['sub_institute_id' => $sub_institute_id, 'id' => $courseId])
                        ->whereNull('deleted_at')
                        ->first();
                    $getChapter = DB::table('chapter_master')
                        ->where(['sub_institute_id' => $sub_institute_id, 'id' => $chapterId])
                        ->whereNull('deleted_at')
                        ->first();
                    // echo "<pre>";print_r($chapterData['contents']);exit;
                    // Process contents if they exist
                    if (isset($chapterData['contents'])) {
                        foreach ($chapterData['contents'] as $contentKey => $contentData) {
                            $content_html = $contentData['content_html'];
                            // Convert HTML content to PDF using DomPDF
                            $options = new Options();
                            $options->set('isHtml5ParserEnabled', true);
                            $options->set('isRemoteEnabled', true);

                            // Generate PDF
                            $pdf = new Dompdf($options);
                            $pdf->loadHtml($content_html);
                            $pdf->setPaper('A4', 'portrait');
                            $pdf->render();
                            $pdfContent = $pdf->output();
                            $newfilename = date('Ymd') . '-' . time() . '.pdf';
                            // Store directly in DigitalOcean Spaces
                            Storage::disk('digitalocean')->put(
                                "public/hp_lms_content_file/{$newfilename}",
                                $pdfContent,
                                'public' // visibility
                            );
                            // echo "<pre>";print_r($newfilename);exit;

                            $contentController = new contentController;
                            $content_request = new Request([
                                'type' => 'API',
                                'sub_institute_id' => $sub_institute_id,
                                'user_id' => $user_id,
                                'syear' => $syear,
                                'token' => $token,
                                'toggle_basic_advanced' => 'Advanced',
                                'hid_standard_name' => $getstandard->name ?? '-',
                                'hid_subject_name' => $getcourse->display_name ?? '-',
                                'hid_chapter_name' => $getChapter->chapter_name ?? '-',
                                'hid_chapter_id' => $chapterId,
                                'title' => $contentData['content_title'] ?? '-',
                                'description' => $contentData['content_description'] ?? '-',
                                'content_category' => $contentData['content_category'] ?? '-',
                                'contentType' => 'link',
                                'link' => 'https://s3-triz.fra1.cdn.digitaloceanspaces.com/public/hp_lms_content_file/' . $newfilename,
                                'cross_curriculum_grade_topic' => $newfilename,
                                'mapping_type' => isset($contentData['mapping_type']) ? $mappingTypes[$contentData['mapping_type']] ?? 0 : 0,
                                'mapping_value' => isset($contentData['mapping_value']) ? $mappingValues[$contentData['mapping_value']] ?? 0 : 0,
                                'filename' => 'content_' . time() . '.pdf',
                                'show_hide' => 1
                            ]);

                            $contentStore = $contentController->store($content_request);
                            // echo "<pre>";print_r($contentStore);exit;
                        }
                    }

                    // Process questions if they exist
                    if (isset($chapterData['questions'])) {
                        foreach ($chapterData['questions'] as $questionData) {

                            $questionController = new questionmasterController;
                            $question_request = new Request([
                                'type' => 'API',
                                'sub_institute_id' => $sub_institute_id,
                                'user_id' => $user_id,
                                'token' => $token,
                                'grade_id' => $grade,
                                'standard_id' => $standard,
                                'subject_id' => $courseId,
                                'chapter_id' => $chapterId,
                                'question_title' => $questionData['question_title'] ?? '-',
                                'description' => $questionData['description'] ?? '-',
                                'mapping_type' => isset($questionData['mapping_type']) ? array($mappingTypes[$questionData['mapping_type']] ?? 0) : array(),
                                'mapping_value' => isset($questionData['mapping_value']) ? array($mappingValues[$questionData['mapping_value']] ?? 0) : array(),
                                'reasons' => array($questionData['reason'] ?? '-'),
                                'question_type_id' => 1,
                                'points' => 1,
                                'multiple_answer' => 1,
                                'status' => 1,
                                'options' => [
                                    "NEW" => array_map(function ($answer) {
                                        return $answer['answer'];
                                    }, $questionData['answers'] ?? [])
                                ],
                                'correct_answer' => isset($questionData['answers']) ?
                                    [array_search(true, array_column($questionData['answers'], 'correct_answer')) => "1"] :
                                    [0 => "1"]
                            ]);

                            $questionStore = $questionController->store($question_request);
                            // echo "<pre>";print_r($questionStore);exit;
                        }
                    }
                }
            }
            $i = 1;
        }
        $res['status_code'] = 0;
        $res['message'] = 'Failed Find Data from AI';
        if ($i > 0) {
            $res['status_code'] = 1;
            $res['message'] = 'Successfully Find Data from AI and Added';
        }
        $res['geminiData'] = $gemeniData;
        return response()->json($res);
    }

    public function GammaContentGeneration(Request $request)
    {
        $industry   = $request->input('industry');
        $department = $request->input('department');
        $jobRole    = $request->input('jobrole');
        $skill      = $request->input('skill');
        $course     = $request->input('course');
        $title      = $request->input('content_title');

        $prompt = "Create a 10-slide professional presentation focused on upskilling employees in the {$industry} industry, within the Department: {$department}, specifically for the Job Role: {$jobRole}, emphasizing the Skill: {$skill}, under the Course: {$course}, Content Title: {$title}; use a formal, instructional tone with consistent formatting and terminology throughout, structuring each slide with 3–5 concise bullet points under 70 words each, strictly following this order: 
        Slide 1 – Title slide titled “Upskilling for Industry: {$industry}, Department: {$department}, Job Role: {$jobRole}, Skill: {$skill}” with subtitle/visual; 
        Slide 2 – Overview and objectives outlining 2–3 key goals of upskilling Skill: {$skill}; 
        Slide 3 – Importance of Skill: {$skill} in Industry: {$industry} explained in 3 clear bullets; 
        Slide 4 – Relevance of Skill: {$skill} to Job Role: {$jobRole} detailing specific daily impacts; 
        Slide 5 – {$skill}-specific challenges and learning needs listed as top 3 points; 
        Slide 6 – Expected learning outcomes stating what employees will achieve post-training; 
        Slide 7 – Stepwise skill-building framework for Skill: {$skill}; 
        Slide 8 – Tools and technologies essential for developing Skill: {$skill} briefly described; 
        Slide 9 – Assessment methods of Skill: {$skill} proposing 2–3 measurable evaluation approaches; 
        Slide 10 – Summary recapping key insights and outlining actionable next steps; 
        Verify full adherence to tone, structure, clarity, and formatting before completion.";

        $payload = [
            "inputText" => $prompt,
            "textMode"   => "generate",
            "format"     => "presentation",
            "themeName"  => "Oasis",
            "numCards"   => 10,
            "cardSplit"  => "auto",
            "additionalInstructions" => "All slides must use clear, consistent formatting. Ensure a formal instructional tone.",
            "exportAs"   => "pdf",
            "textOptions" => [
                "amount"     => "extensive",
                "tone"       => "formal, instructional",
                "audience"   => "employees, L&D managers, HR",
                "language"   => "en"
            ],
            "imageOptions" => [
                "source" => "aiGenerated",
                "model"  => "imagen-4-pro",
                "style"  => "minimal, professional"
            ],
            "cardOptions" => [
                "dimensions" => "fluid"
            ],
            "sharingOptions" => [
                "workspaceAccess" => "view",
                "externalAccess" => "noAccess"
            ]
        ];

        // Replace <correct-endpoint> with whatever Gamma’s actual endpoint is per their docs
        $endpoint = 'https://public-api.gamma.app/v0.2/generations';

        try {
            $response = Http::withHeaders([
                'X-API-KEY'     => "sk-gamma-g2Ny0homDeDYfhRIFbrQeSugMnuZUw4x9WtwNi87dA",
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json'
            ])->timeout(30)->post($endpoint, $payload);

            if ($response->status() == 404) {

                return response()->json([
                    'status' => false,
                    'error' => 'Endpoint not found. Please verify: 1) Correct API endpoint 2) API key permissions 3) Beta feature access'
                ], 404);
            }

            // Handle other error responses
            if (!$response->successful()) {
                $errorBody = $response->json() ?? $response->body();

                return response()->json([
                    'status' => false,
                    'error' => 'Gamma API error: ' . json_encode($errorBody),
                    'status_code' => $response->status()
                ], $response->status());
            }

            // Process successful response
            $result = $response->json();

            // Get generation ID from result
            $generationId = $result['generationId'];
            $newfilename = '';
            // Poll the status endpoint until complete
            $maxAttempts = 5;
            $attempt = 0;
            $timeout = 10; // Timeout in seconds

            do {
                try {
                    $statusResponse = Http::timeout($timeout)
                        ->get("https://public-api.gamma.app/v0.2/generations/" . $generationId);

                    if ($statusResponse->successful()) {
                        $status = $statusResponse->json();

                        if (isset($status['status']) && $status['status'] === 'completed') {
                            // Get file from export URL with timeout
                            $exportUrl = $status['exportUrl'];
                            $file = Http::timeout($timeout)->get($exportUrl)->body();

                            // Generate unique filename
                            $newfilename = uniqid() . '.pdf';

                            // Store file on DigitalOcean
                            Storage::disk('digitalocean')->putFileAs(
                                'public/hp_lms_content_file/',
                                $file,
                                $newfilename,
                                'public'
                            );

                            break;
                        }
                    }
                } catch (\Exception $e) {

                    $attempt++;
                    if ($attempt >= $maxAttempts) {
                        throw new \Exception('Maximum retry attempts reached');
                    }
                }

                sleep(2); // Wait 2 seconds before retrying

            } while ($attempt < $maxAttempts);

            $result['stored_filename'] = $newfilename;

            return response()->json([
                'status' => true,
                'data' => $result,
                'generationId' => $generationId,
                'newfilename' => $newfilename,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => 'Request failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
