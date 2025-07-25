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
use Illuminate\Support\Facades\Schema; // Import the Schema facade
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Validator;
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
        if ($request->has('sort_order') && $request->sort_order != '') {
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
        $path = $_SERVER['HTTP_REFERER'] ?? URL::current();

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
                if (in_array(session()->get('right_menu_id'), $menu_ids) && session()->get('user_profile_name') == "Teacher") {
                    $query->where('id', $getClass->standard_id);
                } else {
                    $query->whereIn('id', $classTeacherStdArr);
                }
            }
            //END Check for class teacher assigned standards

            //START Check for subject teacher assigned
            $subjectTeacherStdArr = session()->get('subjectTeacherStdArr');
            if ($subjectTeacherStdArr != "" && ($classTeacherStdArr == "" || in_array($module_name, $module_array))) {
                if (in_array(session()->get('right_menu_id'), $menu_ids) && session()->get('user_profile_name') == "Teacher") {
                    $query->where('id', $getClass->standard_id);
                } else {
                    $query->whereIn('id', $subjectTeacherStdArr);
                }
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
                if (in_array(session()->get('right_menu_id'), $menu_ids) && session()->get('user_profile_name') == "Teacher") {
                    $query->where('id', $getClass->standard_id);
                } else {
                    $query->whereIn('id', $classTeacherStdArr);
                }
            }
            //END Check for class teacher assigned standards

            //START Check for subject teacher assigned
            $subjectTeacherStdArr = session()->get('subjectTeacherStdArr');
            if ($subjectTeacherStdArr != "" && ($classTeacherStdArr == "" || in_array($module_name, $module_array))) {
                if (in_array(session()->get('right_menu_id'), $menu_ids) && session()->get('user_profile_name') == "Teacher" && isset($getClass->standard_id)) {
                    $query->where('id', $getClass->standard_id);
                } else {
                    $query->whereIn('id', $subjectTeacherStdArr);
                }
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

    public function getUsersMappings(Request $request)
    {
        $emp_id = $request->emp_id;
        $getType = $request->getType; // skills or tasks
        $res['status_code'] = 0;
        $res['message'] = 'User not found';
        $getEmp = DB::table('tbluser as u')
            ->join('tbluserprofilemaster as upm', 'upm.id', '=', 'u.user_profile_id')
            ->where('u.id', $emp_id)
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
                ->join('tbluser as u', 'u.id', '=', 't.user_id')
                ->join('tbluserprofilemaster as upm', 'upm.id', '=', 'u.user_profile_id')
                ->where('t.user_id', $emp_id)
                ->select('t.id as task_id', 't.task_name', 't.status', 'u.first_name', 'u.last_name', 'upm.name as user_role')
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

        $prompt = $request->message;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer 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',
            'HTTP-Referer' => env('APP_URL'),
        ])
            ->timeout(90)
            ->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => 'deepseek/deepseek-chat-v3-0324:free',
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
}
