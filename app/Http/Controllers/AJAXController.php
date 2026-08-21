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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Dompdf\Dompdf;
use Dompdf\Options;

class AJAXController extends Controller
{
    use \App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;

    /** Default and hard ceiling for getSkillCompetency's page size (G-SEC-15). */
    private const SKILL_COMPETENCY_PAGE = 500;
    private const SKILL_COMPETENCY_MAX  = 2000;

    /**
     * Columns table_data must never return, whatever table is asked for.
     *
     * Credentials and government/financial identifiers. No screen renders any
     * of these - the old frontend reads tbluser for names, emails and profile
     * ids - so stripping them breaks nothing while closing the exposure.
     */
    private const TABLE_DATA_DENIED_COLUMNS = [
        'password', 'plain_password', 'otp', 'remember_token', 'fcm_token',
        'aadhar_no', 'pan_no', 'account_no', 'ifsc_code', 'bank_name',
        'esic_no', 'uan_no', 'pf_no',
    ];

    /**
     * Presence of any of these marks a table as sensitive: it holds credentials
     * or government/financial identity, so it is readable only by an
     * authenticated caller.
     *
     * Derived from the schema rather than a hand-kept table list so that a
     * payroll or KYC table added next month is covered the day it is created,
     * without anyone remembering to update this file.
     */
    private const TABLE_DATA_SENSITIVE_COLUMNS = [
        'password', 'plain_password', 'otp', 'remember_token',
        'aadhar_no', 'pan_no', 'account_no', 'ifsc_code',
        'esic_no', 'uan_no', 'pf_no',
    ];

    /** Per-request memo for information_schema lookups, keyed "table.column". */
    private array $tableDataColumnCache = [];

    /** True when $table has $column. Each pair is looked up at most once. */
    private function tableDataHasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;

        if (!array_key_exists($key, $this->tableDataColumnCache)) {
            $this->tableDataColumnCache[$key] = DB::table('information_schema.columns')
                ->where('table_schema', DB::raw('DATABASE()'))
                ->where('table_name', $table)
                ->where('column_name', $column)
                ->exists();
        }

        return $this->tableDataColumnCache[$key];
    }

    /** True when $table carries credentials or identity documents. */
    private function tableDataIsSensitive(string $table): bool
    {
        foreach (self::TABLE_DATA_SENSITIVE_COLUMNS as $column) {
            if ($this->tableDataHasColumn($table, $column)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Treats 0 and '' as "no tenant".
     *
     * Multi-institute admins are stored with sub_institute_id = 0 (see
     * authController::index), and a falsy check that conflates 0 with null is
     * what locked every admin out of this endpoint.
     */
    private function tableDataNormaliseTenant($value)
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return ($value === '' || $value === '0') ? null : $value;
    }

    /**
     * The tenant proven by the caller's own identity, or null.
     *
     * Deliberately ignores any sub_institute_id in the query string: that is
     * caller-controlled, and trusting it is what let one tenant read another's
     * rows. The value is derived from the session or the bearer token instead.
     */
    private function tableDataTenant(Request $request)
    {
        if (session()->has('user_id')) {
            $sessionTenant = $this->tableDataNormaliseTenant(session()->get('sub_institute_id'));
            if ($sessionTenant !== null) {
                return $sessionTenant;
            }
        }

        $token = $request->input('token') ?: $request->bearerToken();
        if ($token && ($accessToken = PersonalAccessToken::findToken($token))) {
            return $this->tableDataNormaliseTenant(optional($accessToken->tokenable)->sub_institute_id);
        }

        return null;
    }

    /**
     * The tenant the caller asked for, from either shape the frontends send:
     * filters[sub_institute_id] (most screens) or a bare sub_institute_id
     * (the onboarding-tour calls).
     *
     * Only consulted when identity does not settle the question - see
     * GetTableData for the precedence.
     */
    private function tableDataRequestedTenant(Request $request)
    {
        $filters = $request->input('filters');
        if (is_array($filters) && isset($filters['sub_institute_id'])) {
            $requested = $this->tableDataNormaliseTenant($filters['sub_institute_id']);
            if ($requested !== null) {
                return $requested;
            }
        }

        return $this->tableDataNormaliseTenant($request->input('sub_institute_id'));
    }

    /** True when the caller has a session or a valid personal access token. */
    private function tableDataAuthenticated(Request $request): bool
    {
        if (session()->has('user_id') && session()->get('user_id')) {
            return true;
        }

        $token = $request->input('token') ?: $request->bearerToken();

        return $token && PersonalAccessToken::findToken($token) !== null;
    }

    /**
     * Generic table reader.
     *
     * Previously this answered any request, from anyone, for any table: no
     * authentication, no tenant filter, and every column returned verbatim.
     * `?table=tbluser` alone returned every user in every tenant along with
     * their password hash, plain_password, Aadhaar and bank details.
     *
     * Four guards now apply:
     *   1. the schema listing (all_tables=1) requires authentication;
     *   2. tables holding credentials or identity documents require
     *      authentication - detected from their columns, not a fixed list;
     *   3. every tenant-scoped table is pinned to exactly one tenant. An
     *      authenticated caller is pinned to their own and cannot widen it;
     *      an anonymous caller must name one, so no request can ever sweep
     *      every tenant at once;
     *   4. credential and identity columns are stripped from every response.
     *
     * Guard 3 still honours filters[sub_institute_id] for anonymous callers.
     * That is a deliberate compatibility window: roughly a hundred call sites
     * in the production frontend send no token at all, and blocking them
     * outright took working screens down. Anonymous reads are logged (see
     * below) so those call sites can be found and migrated; once the log is
     * quiet, the anonymous branch can be deleted and this becomes token-only.
     */
    public function GetTableData(Request $request)
    {
        $authenticated = $this->tableDataAuthenticated($request);

        // The schema dump names every table and column in the database. It is
        // a mapping tool for an attacker and no screen needs it anonymously.
        if($request->has('all_tables') && $request->all_tables==1){
            if (!$authenticated) {
                return response()->json([
                    'error' => 'Authentication is required to list tables.',
                ], 401);
            }

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

        // Credentials and identity documents are never readable anonymously,
        // whatever tenant is named. tbluser and the payroll tables land here.
        if (!$authenticated && $this->tableDataIsSensitive($table)) {
            return response()->json([
                'error' => 'Authentication is required to read "' . $table . '".',
            ], 401);
        }

        // Start query using the validated table name
        $query = DB::table($table);

        // Pin the query to exactly one tenant whenever the table is
        // tenant-scoped. Applied here, before any caller-supplied filter.
        $tenantColumnExists = $this->tableDataHasColumn($table, 'sub_institute_id');

        if ($tenantColumnExists) {
            // Precedence matters. A proven identity wins outright, so a
            // logged-in caller cannot read another tenant by passing
            // filters[sub_institute_id]. The request is consulted only when
            // identity leaves the question open: an anonymous legacy caller,
            // or a multi-institute admin whose own sub_institute_id is 0.
            $tenantId = $this->tableDataTenant($request)
                ?? $this->tableDataRequestedTenant($request);

            if ($tenantId === null) {
                return response()->json([
                    'error' => 'sub_institute_id is required to read "' . $table . '".',
                ], 400);
            }

            if (!$authenticated) {
                // The migration worklist: every legacy call site that still
                // reads without a token, with enough context to find it.
                Log::info('table_data anonymous read', [
                    'table'   => $table,
                    'tenant'  => $tenantId,
                    'referer' => $request->headers->get('referer'),
                    'ip'      => $request->ip(),
                ]);
            }

            // Two conventions live in this schema: most tables store a single
            // id, but shared-catalogue tables such as tblmenumaster store a
            // comma-separated list of the tenants a row applies to
            // ("1,2,3,...,11"). Matching only on equality would hide every row
            // of the second kind, so both forms are accepted.
            $query->where(function ($scope) use ($table, $tenantId) {
                $scope->where($table . '.sub_institute_id', $tenantId)
                      ->orWhereRaw('FIND_IN_SET(?, ' . $table . '.sub_institute_id)', [$tenantId]);
            });
        }

        // Apply filters if provided
        if ($request->has('filters') && is_array($request->filters)) {
            foreach ($request->filters as $column => $value) {
                // 4. IMPORTANT: Validate column name format for security
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
                    // Skip invalid column names or return an error
                    continue; // Skip this filter
                    // OR: return response()->json(['error' => 'Invalid column name format in filters.'], 400);
                }

                // Already pinned by the tenant scope above. Re-applying it here
                // as plain equality would break the comma-separated tables
                // (tblmenumaster stores "1,2,3,...,11"), where the scope
                // matched via FIND_IN_SET and equality never can.
                if ($column === 'sub_institute_id' && $tenantColumnExists) {
                    continue;
                }

                // 5. Manually validate if the column exists to bypass Schema::hasColumn()
                try {
                    //check table has deleted_at
                    $hasDeletedAt = $this->tableDataHasColumn($table, 'deleted_at');

                    if ($hasDeletedAt) {
                        $query->whereNull('deleted_at');
                    }
                    // other column
                    $columnExists = $this->tableDataHasColumn($table, $column);

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
                $itemTypeExists = $this->tableDataHasColumn($table, 'item_type');

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

        // Strip credential and identity columns from every row before it leaves.
        // Done on the result rather than as a select list so it holds for any
        // table, including ones added later.
        $data = $data->map(function ($row) {
            foreach (self::TABLE_DATA_DENIED_COLUMNS as $denied) {
                unset($row->$denied);
            }
            return $row;
        });

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

        // `is_active` is not a column on hrms_departments - the flag is
        // `status`. This threw "Unknown column 'is_active' in 'where clause'"
        // on every single call, so whatever screen depends on it has never
        // worked.
        $query = DB::table('hrms_departments')
            ->where(['sub_institute_id' => $sub_institute_id, 'status' => 1])
            ->whereNull('deleted_at');
        // $query->where("grade_id", $request->grade_id);

        if (count($explode) > 1) {
            //$query->whereIn("grade_id", $explode);
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

           // $query->where("grade_id", $request->grade_id);
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

    /**
     * GET /api/get-employee-tasks - the tasks or skills mapped to one employee.
     *
     * `user_id` is genuinely the SUBJECT here (a manager looking at somebody
     * else's record), so unlike the other fixes in this pass it is left coming
     * from the request. What was wrong is that the route carried no
     * authentication at all and took the tenant from the request too, so
     * anyone could read any employee in any organisation.
     *
     * The route now requires a token (see routes/api.php), and the tenant is
     * taken from that token. The existing `where u.sub_institute_id` filter
     * then does the rest: a subject outside the caller's organisation simply
     * does not match.
     */
    public function getUsersMappings(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $emp_id = $request->user_id ?? $request->emp_id;
        $sub_institute_id = $identity['sub_institute_id'];
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
        // G-SEC-25. AN OPEN DOOR THAT SPENDS MONEY.
        //
        // This proxied to a paid AI API with NO AUTHENTICATION - anyone could
        // call it and bill the account. Not disclosure: cost and abuse. It also
        // returned the upstream provider's error body to the caller.
        //
        // ⚠ THE API KEYS WERE HARDCODED IN THIS FILE, four of them, and they are
        // in git history. Removing them from source does NOT un-leak them:
        // THEY MUST BE ROTATED. Flagged in the register as an action for Triz.
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $apiKey = (string) env('OPENROUTER_API_KEY', '');
        if ($apiKey === '') {
            // Refused rather than falling back to a key in source.
            return response()->json([
                'status'  => 0,
                'message' => 'AI chat is not configured.',
            ], 503);
        }

        $prompt = $request->message;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
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
        // G-SEC-15. This endpoint had THREE defects at once and all three are
        // closed here, because any one of them alone leaves it exploitable:
        //
        //   1. NO AUTHENTICATION. Declared in routes/web.php ("Rajesh for only
        //      API temporary created for data fetch") with no middleware, so it
        //      answered anonymous callers.
        //   2. UNBOUNDED RESULT SET. A four-way join over the largest tables
        //      ending in ->get() with no limit. An unauthenticated GET
        //      exhausted a 512MB memory limit inside Connection::execute().
        //      One URL, repeated from a browser, is a denial of service needing
        //      no credential.
        //   3. TENANT FROM THE REQUEST, defaulting to a hardcoded `?? 2` - so an
        //      absent parameter silently served tenant 2's data.
        //
        // Auth alone would still let an authenticated user exhaust memory; a
        // bound alone would leave the door open. Both, not either.
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $sub_institute_id = $identity['sub_institute_id'];

        // Bounded, and paginated so the data stays reachable in pages rather
        // than being truncated silently.
        $limit  = min(max((int) $request->input('limit', self::SKILL_COMPETENCY_PAGE), 1), self::SKILL_COMPETENCY_MAX);
        $offset = max((int) $request->input('offset', 0), 0);

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
            ->offset($offset)
            ->limit($limit)
            ->get();

        return response()->json([
            'status' => 'success',
            'limit'  => $limit,
            'offset' => $offset,
            'count'  => $data->count(),
            'data'   => $data
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
               // Newer preview (if your project allows)
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
        try {
            $type = 'webForm';
            $skill_department = $request->department;
            $sub_institute_id = $request->sub_institute_id;
            $token = $request->token;
            $user_id = $request->user_id;
            $user_profile_name = $request->user_profile_name;
            $syear = $request->syear;
            $industry = $request->industry;
            $skill_id = $request->subject_id; // Get skill_id from request
            $subject_id = $request->subject_id; // Get subject_id from request

            // Validate required fields
            $validator = Validator::make($request->all(), [
                'sub_institute_id' => 'required',
                'user_id' => 'required',
                'user_profile_name' => 'required',
                'syear' => 'required',
                'industry' => 'required',
                'department' => 'required',
                'subject_id' => 'required', // Add subject_id validation
            ]);

            if ($validator->fails()) {
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }

            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);
            if (!$accessToken) {
                
                return response()->json(['message' => 'Invalid token'], 401);
            }

            // First, check if a course already exists for this skill and subject in sub_std_map
            $existingCourse = DB::table('sub_std_map')
                ->where([
                    'sub_institute_id' => $sub_institute_id,
                    'subject_id' => $subject_id,
                    //'subject_id' => $skill_id
                ])
                ->whereNull('deleted_at')
                ->first();

            if ($existingCourse) {
                
                // Return existing course info
                return response()->json([
                    'status_code' => 2,
                    'message' => 'Course already exists for this skill and subject',
                    'course_id' => $existingCourse->id,
                    'subject_id' => $subject_id,
                    //'skill_id' => $skill_id
                ]);
            }

            // Check and create grade if needed
            $checkGrade = DB::table('academic_section')
                ->where(['sub_institute_id' => $sub_institute_id, 'title' => $industry])
                ->whereNull('deleted_at')
                ->first();
            
            if ($checkGrade) {
                $grade = $checkGrade->id;
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

            // Check and create standard if needed
            $checkStandard = DB::table('hrms_departments')
                ->where(['sub_institute_id' => $sub_institute_id, 'department' => $skill_department])
                ->whereNull('deleted_at')
                ->first();
            
            if ($checkStandard) {
                $standard = $checkStandard->id;
            } else {
                // `name` and `short_name` are not columns on hrms_departments -
                // they were copied from the academic_section insert above. The
                // real column is `department`, and it is NOT NULL, so this
                // statement could never execute.
                $standardInsert = DB::table('hrms_departments')->insertGetId([
                    'sub_institute_id' => $sub_institute_id,
                    'department' => $skill_department,
                    'parent_id' => 0,
                    'status' => 1,
                    'is_calculated' => 0,
                    'sort_order' => 1,
                    'created_by' => $user_id,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                $standard = $standardInsert;
            }

            // Get mapping types and values
            $mappingTypes = DB::table('lms_mapping_type')
                ->where('id', 1)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id');
            
            $mappingValues = DB::table('lms_mapping_type')
                ->where('parent_id', 1)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id');
            
            $lms_content_category = DB::table('lms_content_category')
                ->where('status', 2)
                ->whereNull('deleted_at')
                ->pluck('category_name', 'id');
            
            $course_category = DB::table('lms_content_category')
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->pluck('category_name', 'id');

            // Prepare prompt based on form type
            $prompt = '';
            if ($request->has('formType')) {
                $prompt = $request->prompt;
            } else {
                $skill_category = $request->skill_category;
                $skill_sub_category = $request->skill_sub_category;
                $skill_micro_category = $request->skill_micro_category;
                $skill_name = $request->skill_name;
                $skill_description = $request->skill_description;

                // Get job roles
                $getJobroles = DB::table('s_user_skill_jobrole')
                    ->where(['sub_institute_id' => $sub_institute_id, 'skill' => $skill_name])
                    ->whereNull('deleted_at')
                    ->groupBy('jobrole')
                    ->get();
                
                $jobroleLists = $proficiencyLists = [];
                foreach ($getJobroles as $value) {
                    $jobroleLists[] = $value->jobrole;
                    $proficiencyLists[] = $value->proficiency_level;
                }
                
                $jobroleData = json_encode($jobroleLists);
                $proficencyData = json_encode($proficiencyLists);

                $prompt = "I have Skills Name: '" . $skill_name . "' of 
                        Industry: " . $industry . "
                        I have Skills Description: " . $skill_description . "
                        I have Skills Department: " . $skill_department . "
                        I have Skills Category: " . $skill_category . "
                        I have Skills Sub Category: " . $skill_sub_category;
                
                if (!empty($jobroleLists)) {
                    $prompt .= "
                        I have Skills Jobrole: " . $jobroleData . "
                        I have Skills Proficiency Level: " . $proficencyData;
                }
                
                $prompt .= "1.Understand the depth, complexity, and learning needs of the given skill.
                        2.Break the skill into logical continuous chapters, each representing a key theme.
                        3.For each chapter, create continuous content items with:
                            title
                            description
                            content_category any 1 from (" . $lms_content_category->implode(', ') . ")
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
                            - course_category any 1 of (" . $course_category->implode(', ') . ")
                            - course_code
                            - course_type
                            - chapters: list of chapters with:
                            -- chapter_name
                            -- chapter_description
                            -- contents: list of content items with:
                                    --- content_title
                                    --- content_description
                                    --- content_html (Make Simple html[make label's bold and bg-color proper] with content title,content description,skill, skill description,skill category, skill sub category ,jobrole, jobrole description, list of tasks realted to content)
                                    --- content_category any 1 from (" . $lms_content_category->implode(', ') . ")
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

            // Get AI response
            $gemeniJson = $this->geminiChat($request);
            $gemeniData = json_decode(json_encode($gemeniJson->original), true);

            if (isset($gemeniData['error'])) {
                return response()->json([
                    'status_code' => 0,
                    'message' => 'AI API Error: ' . ($gemeniData['error']['message'] ?? 'Unknown error'),
                    'geminiData' => $gemeniData
                ]);
            }

            // Check if response is an array (not single object)
            if (isset($gemeniData[0])) {
                $courseData = $gemeniData[0];
            } else {
                $courseData = $gemeniData;
            }

            $successCount = 0;

            // Create course - Use the subject_id from request
            $courseController = new masterSetupController;
            $course_request = new Request([
                'type' => 'API',
                'sub_institute_id' => $sub_institute_id,
                'user_id' => $user_id,
                'token' => $token,
                'formType' => 'course',
                'subject_id' => $subject_id, // Use subject_id from request
                'standard_id' => $standard,
                'allow_grades' => 'Yes',
                'allow_content' => 'Yes',
                'sort_order' => '1',
                'elective_subject' => 'No',
                'add_content' => 'chapterwise',
                'display_name' => $courseData['course_name'] ?? 'AI Generated Course - ' . $skill_name,
                'display_image' => null,
                'subject_category' => $courseData['course_category'] ?? 'Functional',
                'subject_code' => $courseData['course_code'] ?? 'AI-GEN-' . time() . '-' . $skill_id,
                'subject_type' => $courseData['course_type'] ?? 'Skill Development',
                'short_name' => $courseData['short_name'] ?? substr($courseData['course_name'] ?? 'AI Course', 0, 20),
                'status' => 1,
                //'subject_id' => $skill_id // Add skill_id to the request
            ]);
            
            $courseStore = $courseController->store($course_request);

            if (!isset($courseStore->original['course_id'])) {
                return response()->json([
                    'status_code' => 0,
                    'message' => 'Failed to create course in masterSetupController',
                    'geminiData' => $gemeniData
                ]);
            }

            $courseId = $courseStore->original['course_id'];
            
            // After creating course, update the sub_std_map with skill_id if not already set
            DB::table('sub_std_map')
                ->where('id', $courseId)
                ->update([
                    'subject_id' => $skill_id,
                    'updated_at' => now(),
                    'updated_by' => $user_id
                ]);
            
            $successCount++;

            // Process chapters if they exist
            if (isset($courseData['chapters']) && is_array($courseData['chapters'])) {                
                foreach ($courseData['chapters'] as $chapterIndex => $chapterData) {
                    try {
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
                            'chapter_name' => [$chapterData['chapter_name'] ?? 'Chapter ' . ($chapterIndex + 1)],
                            'chapter_desc' => [$chapterData['chapter_description'] ?? 'AI Generated Chapter'],
                            'availability' => [1],
                            'show_hide' => [1],
                            'sort_order' => [$chapterIndex + 1]
                        ]);

                        
                        $chapterStore = $moduleController->store($chapter_request);

                        if (!isset($chapterStore->original['chapter_id'])) {
                            continue;
                        }

                        $chapterId = $chapterStore->original['chapter_id'];
                        $successCount++;

                        // Get related data
                        $getstandard = DB::table('hrms_departments')
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

                        // Process contents
                        if (isset($chapterData['contents']) && is_array($chapterData['contents'])) {
                            foreach ($chapterData['contents'] as $contentIndex => $contentData) {
                                try {
                                    $content_html = $contentData['content_html'] ?? '<div>No content provided</div>';
                                    
                                    // Convert HTML content to PDF using DomPDF
                                    $options = new Options();
                                    $options->set('isHtml5ParserEnabled', true);
                                    $options->set('isRemoteEnabled', true);
                                    $options->set('defaultFont', 'Arial');

                                    // Generate PDF
                                    $pdf = new Dompdf($options);
                                    $pdf->loadHtml($content_html);
                                    $pdf->setPaper('A4', 'portrait');
                                    $pdf->render();
                                    
                                    $pdfContent = $pdf->output();
                                    $newfilename = 'content_' . $courseId . '_' . $chapterId . '_' . date('Ymd') . '_' . time() . '_' . $contentIndex . '.pdf';
                                    
                                    // Store in DigitalOcean Spaces
                                    $storagePath = "public/hp_lms_content_file/{$newfilename}";
                                    Storage::disk('digitalocean')->put(
                                        $storagePath,
                                        $pdfContent,
                                        'public'
                                    );

                                    // Find mapping type and value IDs
                                    $mappingTypeId = null;
                                    $mappingValueId = null;
                                    
                                    if (isset($contentData['mapping_type']) && isset($contentData['mapping_value'])) {
                                        // Try to find mapping type
                                        $mappingType = $mappingTypes->first(function ($item) use ($contentData) {
                                            return strpos(strtolower($item->name), strtolower($contentData['mapping_type'])) !== false;
                                        });
                                        
                                        if ($mappingType) {
                                            $mappingTypeId = $mappingType->id;
                                            
                                            // Find mapping value under this type
                                            $mappingValue = $mappingValues->first(function ($item) use ($contentData, $mappingTypeId) {
                                                return $item->parent_id == $mappingTypeId && 
                                                    strpos(strtolower($item->name), strtolower($contentData['mapping_value'])) !== false;
                                            });
                                            
                                            if ($mappingValue) {
                                                $mappingValueId = $mappingValue->id;
                                            }
                                        }
                                    }

                                    // Find content category ID
                                    $contentCategoryId = null;
                                    if (isset($contentData['content_category'])) {
                                        $category = $lms_content_category->first(function ($name, $id) use ($contentData) {
                                            return strpos(strtolower($name), strtolower($contentData['content_category'])) !== false;
                                        });
                                        
                                        if ($category) {
                                            $contentCategoryId = $lms_content_category->search($category);
                                        }
                                    }

                                    // If not found, use defaults
                                    if (!$contentCategoryId) {
                                        $contentCategoryId = $lms_content_category->keys()->first();
                                    }

                                    $contentController = new contentController;
                                    $content_request = new Request([
                                        'type' => 'API',
                                        'sub_institute_id' => $sub_institute_id,
                                        'user_id' => $user_id,
                                        'syear' => $syear,
                                        'token' => $token,
                                        'toggle_basic_advanced' => 'Advanced',
                                        'hid_standard_name' => $getstandard->name ?? $skill_department,
                                        'hid_subject_name' => $getcourse->display_name ?? $courseData['course_name'] ?? 'AI Course',
                                        'hid_chapter_name' => $getChapter->chapter_name ?? ($chapterData['chapter_name'] ?? 'Chapter'),
                                        'hid_chapter_id' => $chapterId,
                                        'title' => $contentData['content_title'] ?? 'Content ' . ($contentIndex + 1),
                                        'description' => $contentData['content_description'] ?? 'AI Generated Content',
                                        'content_category' => $contentCategoryId,
                                        'contentType' => 'link',
                                        'link' => 'https://s3-triz.fra1.cdn.digitaloceanspaces.com/public/hp_lms_content_file/' . $newfilename,
                                        'cross_curriculum_grade_topic' => $newfilename,
                                        'mapping_type' => $mappingTypeId ?? 0,
                                        'mapping_value' => $mappingValueId ?? 0,
                                        'filename' => $newfilename,
                                        'show_hide' => 1
                                    ]);

                                                                       
                                    $contentStore = $contentController->store($content_request);
                                    
                                    if (isset($contentStore->original['content_id'])) {
                                        $successCount++;
                                    } else {
                                    }
                                } catch (\Exception $e) {
                                }
                            }
                        }

                        // Process questions
                        if (isset($chapterData['questions']) && is_array($chapterData['questions'])) {
                            
                            foreach ($chapterData['questions'] as $questionIndex => $questionData) {
                                try {
                                    // Prepare answers
                                    $answers = isset($chapterData['answers']) ? $chapterData['answers'] : [];
                                    $questionAnswers = array_slice($answers, $questionIndex * 4, 4);
                                    
                                    if (empty($questionAnswers)) {
                                        // Create default answers if not provided
                                        $questionAnswers = [
                                            ['answer' => 'True', 'correct_answer' => 1, 'feedback' => 'Correct answer'],
                                            ['answer' => 'False', 'correct_answer' => 0, 'feedback' => 'Incorrect answer'],
                                            ['answer' => 'Maybe', 'correct_answer' => 0, 'feedback' => 'Incorrect answer'],
                                            ['answer' => 'Not sure', 'correct_answer' => 0, 'feedback' => 'Incorrect answer']
                                        ];
                                    }

                                    // Find correct answer index
                                    $correctAnswerIndex = null;
                                    foreach ($questionAnswers as $index => $answer) {
                                        if (isset($answer['correct_answer']) && $answer['correct_answer']) {
                                            $correctAnswerIndex = $index;
                                            break;
                                        }
                                    }

                                    // If no correct answer found, set first as correct
                                    if ($correctAnswerIndex === null) {
                                        $correctAnswerIndex = 0;
                                        $questionAnswers[0]['correct_answer'] = 1;
                                    }

                                    $questionController = new questionmasterController;
                                    $question_request = new Request([
                                        'type' => 'API',
                                        'sub_institute_id' => $sub_institute_id,
                                        'user_id' => $user_id,
                                        'token' => $token,
                                        'standard_id' => $standard,
                                        'subject_id' => $courseId,
                                        'chapter_id' => $chapterId,
                                        'question_title' => $questionData['question_title'] ?? 'Question ' . ($questionIndex + 1),
                                        'description' => $questionData['description'] ?? 'AI Generated Question',
                                        'mapping_type' => isset($questionData['mapping_type']) ? [1] : [],
                                        'mapping_value' => isset($questionData['mapping_value']) ? [1] : [],
                                        'reasons' => isset($questionData['reason']) ? [$questionData['reason']] : ['AI Generated'],
                                        'question_type_id' => 1,
                                        'points' => 1,
                                        'multiple_answer' => 0,
                                        'status' => 1,
                                        'options' => [
                                            "NEW" => array_column($questionAnswers, 'answer')
                                        ],
                                        'correct_answer' => [$correctAnswerIndex => "1"]
                                    ]);

                                    $questionStore = $questionController->store($question_request);
                                    
                                    if (isset($questionStore->original['question_id'])) {
                                        $successCount++;
                                    } else {
                                        \Log::error('Failed to create question', ['response' => $questionStore, 'question_index' => $questionIndex]);
                                    }
                                } catch (\Exception $e) {
                                    \Log::error('Error creating question', [
                                        'error' => $e->getMessage(),
                                        'trace' => $e->getTraceAsString(),
                                        'question_index' => $questionIndex,
                                        'chapter_id' => $chapterId
                                    ]);
                                }
                            }
                        }

                    } catch (\Exception $e) {
                        \Log::error('Error processing chapter', [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                            'chapter_index' => $chapterIndex,
                            'course_id' => $courseId
                        ]);
                    }
                }
            }

           
            $response = [
                'status_code' => 1,
                'message' => 'Successfully generated AI course and stored all data',
                'total_items_created' => $successCount,
                'course_id' => $courseId,
                'subject_id' => $subject_id,
                'skill_id' => $skill_id,
                'course_name' => $courseData['course_name'] ?? 'AI Generated Course',
                'geminiData' => $gemeniData
            ];

            return response()->json($response);

        } catch (\Exception $e) {
            \Log::error('AICourseGeneration fatal error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status_code' => 0,
                'message' => 'Internal server error: ' . $e->getMessage(),
                'error_details' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    public function GammaContentGeneration(Request $request)
{
    // Validate required fields including slide_count
    $validator = Validator::make($request->all(), [
        'industry' => 'required|string',
        'department' => 'required|string',
        'jobrole' => 'required|string',
        'skill' => 'required|string',
        // 'course' => 'required|string', // Removed - will be auto-generated
        'content_title' => 'required|string',
        'slide_count' => 'required|integer|min:1|max:50',
        'sub_institute_id' => 'required',
        'user_id' => 'required',
        'user_profile_name' => 'required',
        'token' => 'required',
        'syear' => 'required',
        // Optional - if linking to existing course/chapter
        'course_id' => 'sometimes|required',
        'chapter_id' => 'sometimes|required'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    $industry   = $request->input('industry');
    $department = $request->input('department');
    $jobRole    = $request->input('jobrole');
    $skill      = $request->input('skill');
    // $course  = $request->input('course'); // Removed
    $title      = $request->input('content_title');
    $slideCount = (int) $request->input('slide_count');
    $sub_institute_id = $request->input('sub_institute_id');
    $user_id = $request->input('user_id');
    $user_profile_name = $request->input('user_profile_name');
    $token = $request->input('token');
    $syear = $request->input('syear');
    $course_id = $request->input('course_id');
    $chapter_id = $request->input('chapter_id');

    // Verify token
    $accessToken = PersonalAccessToken::findToken($token);
    if (!$accessToken) {
        return response()->json(['message' => 'Invalid token'], 401);
    }

    // ===== FETCH SKILL_ID FROM SKILL NAME =====
    $skillRecord = DB::table('s_user_skill_jobrole')
        ->where('skill', $skill)
        ->whereNull('deleted_at')
        ->first();

    if (!$skillRecord) {
        return response()->json([
            'status' => false,
            'error' => 'Skill not found in database',
            'skill_name' => $skill
        ], 404);
    }

    $skill_id = $skillRecord->id;
    
    \Log::info('Skill found', [
        'skill_name' => $skill,
        'skill_id' => $skill_id
    ]);
    // ===== END FETCH SKILL_ID =====

    // ===== AUTO-GENERATE COURSE NAME USING GEMINI =====
    $coursePrompt = "Generate a professional, concise course name for a training program with the following details:
    - Industry: {$industry}
    - Department: {$department}
    - Job Role: {$jobRole}
    - Skill: {$skill}
    - Content Title: {$title}
    
    The course name should be:
    - Professional and instructional in tone
    - Maximum 100 characters
    - Relevant to upskilling employees in this context
    - Include the key skill or topic
    
    Return ONLY the course name as a plain string, no additional text, no JSON, no explanations.";

    // Create a new request for geminiChat
    $geminiRequest = new Request([
        'prompt' => $coursePrompt
    ]);
    
    $geminiResponse = $this->geminiChat($geminiRequest);
    $geminiData = json_decode(json_encode($geminiResponse->original), true);

    // Check if geminiChat returned an error
    if (isset($geminiData['error'])) {
        // Fallback course name if Gemini fails
        $course = "Upskilling in {$skill} for {$jobRole} - {$industry}";
        \Log::warning('Gemini course generation failed, using fallback', [
            'error' => $geminiData['error'],
            'fallback_course' => $course
        ]);
    } else {
        // Extract course name from Gemini response
        if (is_array($geminiData) && isset($geminiData[0])) {
            $course = trim($geminiData[0]);
        } elseif (is_string($geminiData)) {
            $course = trim($geminiData);
        } else {
            // Try to extract string from response
            $course = trim(json_encode($geminiData));
        }
        
        // Clean up the course name
        $course = str_replace(['"', "'", "\n", "\r"], '', $course);
        $course = substr($course, 0, 100); // Limit to 100 characters
        
        // If empty, use fallback
        if (empty($course)) {
            $course = "Upskilling in {$skill} for {$jobRole} - {$industry}";
        }
    }
    
    \Log::info('Generated course name', [
        'course' => $course,
        'from_gemini' => !isset($geminiData['error'])
    ]);
    // ===== END AUTO-GENERATE COURSE NAME =====

    // Get mapping types and values
    $mappingTypes = DB::table('lms_mapping_type')
        ->where('id', 1)
        ->whereNull('deleted_at')
        ->get()
        ->keyBy('id');
    
    $mappingValues = DB::table('lms_mapping_type')
        ->where('parent_id', 1)
        ->whereNull('deleted_at')
        ->get()
        ->keyBy('id');
    
    $lms_content_category = DB::table('lms_content_category')
        ->where('status', 2)
        ->whereNull('deleted_at')
        ->pluck('category_name', 'id');

    // Generate slides dynamically based on count
    $slideStructure = $this->generateSlideStructure($slideCount, $industry, $department, $jobRole, $skill, $course, $title);

    $prompt = "Create a {$slideCount}-slide professional presentation focused on upskilling employees in the {$industry} industry, within the Department: {$department}, specifically for the Job Role: {$jobRole}, emphasizing the Skill: {$skill}, under the Course: {$course}, Content Title: {$title}; use a formal, instructional tone with consistent formatting and terminology throughout, structuring each slide with 3–5 concise bullet points under 70 words each, strictly following this slide structure: {$slideStructure}";

    // Payload structure for Gamma API
    $payload = [
        "inputText" => $prompt,
        "textMode"   => "generate",
        "format"     => "presentation",
        "numCards"   => $slideCount,
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

    // Remove any null or empty values from the payload
    $payload = array_filter($payload, function($value) {
        return $value !== null && $value !== '';
    });

    $endpoint = 'https://public-api.gamma.app/v1.0/generations';

    try {
        \Log::info('Sending request to Gamma API', [
            'endpoint' => $endpoint,
            'slide_count' => $slideCount,
            'course_name' => $course,
            'payload' => json_encode($payload)
        ]);

        $response = Http::withHeaders([
            'X-API-KEY' => "sk-gamma-g2Ny0homDeDYfhRIFbrQeSugMnuZUw4x9WtwNi87dA",
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ])->timeout(60)->post($endpoint, $payload);

        // Log response for debugging
        \Log::info('Gamma API Response', [
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body()
        ]);

        // Handle 410 Gone specifically
        if ($response->status() == 410) {
            return response()->json([
                'status' => false,
                'error' => 'API version deprecated. Please update to latest API version.',
                'details' => $response->json()
            ], 410);
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
        $generationId = $result['generationId'] ?? $result['id'] ?? $result['generation_id'] ?? null;
        
        if (!$generationId) {
            return response()->json([
                'status' => false,
                'error' => 'Invalid response from Gamma API: No generation ID found',
                'response' => $result
            ], 500);
        }

        $newfilename = '';
        $filePath = '';
        
        // Poll the status endpoint until complete
        $maxAttempts = 60;
        $attempt = 0;
        $timeout = 15;

        do {
            try {
                $statusResponse = Http::timeout($timeout)
                    ->withHeaders([
                        'X-API-KEY' => "sk-gamma-g2Ny0homDeDYfhRIFbrQeSugMnuZUw4x9WtwNi87dA",
                        'Accept' => 'application/json'
                    ])
                    ->get("https://public-api.gamma.app/v1.0/generations/" . $generationId);

                if ($statusResponse->successful()) {
                    $status = $statusResponse->json();

                    // Log status for debugging
                    \Log::info('Generation status check', [
                        'attempt' => $attempt + 1,
                        'status' => $status
                    ]);

                    // Check status
                    $currentStatus = $status['status'] ?? $status['state'] ?? $status['generationStatus'] ?? '';
                    
                    if (in_array(strtolower($currentStatus), ['completed', 'done', 'succeeded', 'complete'])) {
                        // Get file from export URL
                        $exportUrl = $status['exportUrl'] ?? $status['url'] ?? $status['file_url'] ?? $status['downloadUrl'] ?? null;
                        
                        if ($exportUrl) {
                            \Log::info('Downloading file from: ' . $exportUrl);
                            
                            // Download the file
                            $fileResponse = Http::timeout($timeout)->get($exportUrl);
                            
                            if ($fileResponse->successful()) {
                                $fileContent = $fileResponse->body();

                                // Generate unique filename
                                $newfilename = 'presentation_' . uniqid() . '.pdf';
                                $filePath = 'public/hp_lms_content_file/' . $newfilename;

                                // Store file on DigitalOcean using put() method
                                Storage::disk('digitalocean')->put(
                                    $filePath,
                                    $fileContent,
                                    'public'
                                );
                                
                                \Log::info('File stored successfully: ' . $newfilename);
                                
                                break;
                            } else {
                                \Log::error('Failed to download file from export URL', [
                                    'url' => $exportUrl,
                                    'status' => $fileResponse->status()
                                ]);
                            }
                        } else {
                            \Log::warning('No export URL found in response', ['status' => $status]);
                        }
                    } elseif (in_array(strtolower($currentStatus), ['failed', 'error'])) {
                        throw new \Exception('Generation failed: ' . ($status['error'] ?? 'Unknown error'));
                    }
                }
            } catch (\Exception $e) {
                \Log::error('Status check error', [
                    'attempt' => $attempt + 1,
                    'error' => $e->getMessage()
                ]);
                
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    throw new \Exception('Maximum retry attempts reached: ' . $e->getMessage());
                }
                continue;
            }

            $attempt++;
            
            // Exponential backoff
            $sleepTime = min(30, pow(2, $attempt));
            sleep($sleepTime);

        } while ($attempt < $maxAttempts);

        // If we have a stored filename, save to database using existing tables
        if (!empty($newfilename)) {
            
            // ===== STORE IN EXISTING TABLES =====
            
            // 1. Get or create grade (academic_section)
            $checkGrade = DB::table('academic_section')
                ->where(['sub_institute_id' => $sub_institute_id, 'title' => $industry])
                ->whereNull('deleted_at')
                ->first();
            
            if ($checkGrade) {
                $grade = $checkGrade->id;
            } else {
                $grade = DB::table('academic_section')->insertGetId([
                    'sub_institute_id' => $sub_institute_id,
                    'title' => $industry,
                    'short_name' => $industry,
                    'sort_order' => '1',
                    'created_by' => $user_id,
                    'created_at' => now()
                ]);
            }

            // 2. Get or create department (hrms_departments) - THIS IS THE STANDARD
            $checkDepartment = DB::table('hrms_departments')
                ->where(['sub_institute_id' => $sub_institute_id, 'department' => $department])
                ->whereNull('deleted_at')
                ->first();
            
            if ($checkDepartment) {
                $standard_id = $checkDepartment->id;
            } else {
                $standard_id = DB::table('hrms_departments')->insertGetId([
                    'sub_institute_id' => $sub_institute_id,
                    'department' => $department,
                    'status' => '1',
                    'created_by' => $user_id,
                    'created_at' => now()
                ]);
            }

            // 3. Get or create course (sub_std_map) with subject_id = skill_id
            $checkCourse = DB::table('sub_std_map')
                ->where([
                    'sub_institute_id' => $sub_institute_id,
                    'display_name' => $course
                ])
                ->whereNull('deleted_at')
                ->first();

            if (!$checkCourse && $course_id) {
                // Use provided course_id
                $checkCourse = DB::table('sub_std_map')
                    ->where(['sub_institute_id' => $sub_institute_id, 'id' => $course_id])
                    ->whereNull('deleted_at')
                    ->first();
            }

            if ($checkCourse) {
                $final_course_id = $checkCourse->id;
                
                // Update the course to set subject_id = skill_id if not already set
                DB::table('sub_std_map')
                    ->where('id', $final_course_id)
                    ->update([
                        'subject_id' => $skill_id,
                        'standard_id' => $standard_id,
                        'updated_at' => now(),
                        'updated_by' => $user_id
                    ]);
                    
                \Log::info('Updated existing course with skill_id and standard_id', [
                    'course_id' => $final_course_id,
                    'skill_id' => $skill_id,
                    'standard_id' => $standard_id
                ]);
                
            } else {
                // Create new course with subject_id = skill_id and standard_id
                $final_course_id = DB::table('sub_std_map')->insertGetId([
                    'sub_institute_id' => $sub_institute_id,
                    'standard_id' => $standard_id,
                    'display_name' => $course,
                    'short_name' => substr($course, 0, 20),
                    'subject_code' => 'AI-GEN-' . time(),
                    'subject_category' => 'Functional',
                    'subject_type' => 'Skill Development',
                    'allow_grades' => 'Yes',
                    'allow_content' => 'Yes',
                    'sort_order' => '1',
                    'elective_subject' => 'No',
                    'add_content' => 'chapterwise',
                    'status' => 1,
                    'subject_id' => $skill_id,
                    'created_by' => $user_id,
                    'created_at' => now()
                ]);
                
                \Log::info('Created new course with skill_id and standard_id', [
                    'course_id' => $final_course_id,
                    'skill_id' => $skill_id,
                    'standard_id' => $standard_id
                ]);
            }

            // 4. Get or create chapter (chapter_master) with standard_id
            $chapterName = $title;
            $checkChapter = DB::table('chapter_master')
                ->where([
                    'sub_institute_id' => $sub_institute_id,
                    'subject_id' => $final_course_id,
                    'chapter_name' => $chapterName
                ])
                ->whereNull('deleted_at')
                ->first();

            if (!$checkChapter && $chapter_id) {
                $checkChapter = DB::table('chapter_master')
                    ->where(['sub_institute_id' => $sub_institute_id, 'id' => $chapter_id])
                    ->whereNull('deleted_at')
                    ->first();
            }

            if ($checkChapter) {
                $final_chapter_id = $checkChapter->id;
                
                DB::table('chapter_master')
                    ->where('id', $final_chapter_id)
                    ->update([
                        'standard_id' => $standard_id,
                        'updated_at' => now(),
                        'updated_by' => $user_id
                    ]);
                    
            } else {
                $final_chapter_id = DB::table('chapter_master')->insertGetId([
                    'sub_institute_id' => $sub_institute_id,
                    'standard_id' => $standard_id,
                    'subject_id' => $final_course_id,
                    'chapter_name' => $chapterName,
                    'chapter_desc' => "AI Generated presentation for {$skill} skill",
                    'availability' => 1,
                    'show_hide' => 1,
                    'sort_order' => 1,
                    'created_by' => $user_id,
                    'created_at' => now()
                ]);
            }

            // 5. Get course and chapter details for content creation
            $getstandard = DB::table('hrms_departments')
                ->where(['sub_institute_id' => $sub_institute_id, 'id' => $standard_id])
                ->whereNull('deleted_at')
                ->first();
            
            $getcourse = DB::table('sub_std_map')
                ->where(['sub_institute_id' => $sub_institute_id, 'id' => $final_course_id])
                ->whereNull('deleted_at')
                ->first();
            
            $getChapter = DB::table('chapter_master')
                ->where(['sub_institute_id' => $sub_institute_id, 'id' => $final_chapter_id])
                ->whereNull('deleted_at')
                ->first();

            // 6. Store in content_details table (via contentController) with standard_id
            $contentCategoryId = $lms_content_category->keys()->first();

            $contentController = new contentController;
            $content_request = new Request([
                'type' => 'API',
                'sub_institute_id' => $sub_institute_id,
                'user_id' => $user_id,
                'syear' => $syear,
                'token' => $token,
                'toggle_basic_advanced' => 'Advanced',
                'hid_standard_name' => $getstandard->name ?? $department,
                'hid_subject_name' => $getcourse->display_name ?? $course,
                'hid_chapter_name' => $getChapter->chapter_name ?? $title,
                'hid_chapter_id' => $final_chapter_id,
                'title' => $title,
                'description' => "AI Generated presentation for {$skill} skill in {$industry} industry",
                'content_category' => $contentCategoryId,
                'contentType' => 'link',
                'link' => Storage::disk('digitalocean')->url($filePath),
                'cross_curriculum_grade_topic' => $newfilename,
                'mapping_type' => 0,
                'mapping_value' => 0,
                'filename' => $newfilename,
                'show_hide' => 1,
                'standard_id' => $standard_id
            ]);

            $contentStore = $contentController->store($content_request);
            
            $content_id = null;
            if (isset($contentStore->original['content_id'])) {
                $content_id = $contentStore->original['content_id'];
                
                DB::table('content_details')
                    ->where('id', $content_id)
                    ->update([
                        'standard_id' => $standard_id,
                        'updated_at' => now()
                    ]);
            }

            return response()->json([
                'status' => true,
                'message' => 'Presentation generated and stored successfully',
                'data' => [
                    'course_id' => $final_course_id,
                    'course_name' => $course, // Return generated course name
                    'chapter_id' => $final_chapter_id,
                    'content_id' => $content_id,
                    'file_name' => $newfilename,
                    'file_url' => Storage::disk('digitalocean')->url($filePath),
                    'slide_count' => $slideCount,
                    'skill_id' => $skill_id,
                    'standard_id' => $standard_id
                ],
                'generationId' => $generationId,
                'gemini_generated_course' => !isset($geminiData['error']) // Indicate if Gemini was used
            ]);
        } else {
            return response()->json([
                'status' => false,
                'error' => 'File generation completed but file could not be downloaded',
                'generationId' => $generationId
            ], 500);
        }
        
    } catch (\Exception $e) {
        \Log::error('Gamma content generation failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'status' => false,
            'error' => 'Request failed: ' . $e->getMessage(),
        ], 500);
    }
}

    // Alternative simplified version if the above still doesn't work
    public function GammaContentGenerationSimplified(Request $request)
    {
        // Validate required fields
        $validator = Validator::make($request->all(), [
            'industry' => 'required|string',
            'department' => 'required|string',
            'jobrole' => 'required|string',
            'skill' => 'required|string',
            'course' => 'required|string',
            'content_title' => 'required|string',
            'slide_count' => 'required|integer|min:1|max:50'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $industry   = $request->input('industry');
        $department = $request->input('department');
        $jobRole    = $request->input('jobrole');
        $skill      = $request->input('skill');
        $course     = $request->input('course');
        $title      = $request->input('content_title');
        $slideCount = (int) $request->input('slide_count');

        // Generate slide structure
        $slideStructure = $this->generateSlideStructure($slideCount, $industry, $department, $jobRole, $skill, $course, $title);

        $prompt = "Create a {$slideCount}-slide professional presentation focused on upskilling employees in the {$industry} industry, within the Department: {$department}, specifically for the Job Role: {$jobRole}, emphasizing the Skill: {$skill}, under the Course: {$course}, Content Title: {$title}; use a formal, instructional tone with consistent formatting and terminology throughout, structuring each slide with 3–5 concise bullet points under 70 words each, strictly following this slide structure: {$slideStructure}";

        // Minimal payload structure for v1.0 API
        $payload = [
            "inputText" => $prompt,
            "textMode" => "generate",
            "format" => "presentation",
            "numCards" => $slideCount
        ];

        $endpoint = 'https://public-api.gamma.app/v1.0/generations';

        try {
            $response = Http::withHeaders([
                'X-API-KEY' => "sk-gamma-g2Ny0homDeDYfhRIFbrQeSugMnuZUw4x9WtwNi87dA",
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ])->timeout(60)->post($endpoint, $payload);

            if (!$response->successful()) {
                $errorBody = $response->json() ?? $response->body();
                return response()->json([
                    'status' => false,
                    'error' => 'Gamma API error: ' . json_encode($errorBody),
                    'status_code' => $response->status()
                ], $response->status());
            }

            return response()->json([
                'status' => true,
                'data' => $response->json()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => 'Request failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate dynamic slide structure based on slide count
     */
    private function generateSlideStructure($slideCount, $industry, $department, $jobRole, $skill, $course, $title)
    {
        // Base slides that are always included (10 essential slides)
        $baseSlides = [
            1 => "Title slide titled \"Upskilling for Industry: {$industry}, Department: {$department}, Job Role: {$jobRole}, Skill: {$skill}\" with subtitle/visual",
            2 => "Overview and objectives outlining 2–3 key goals of upskilling Skill: {$skill}",
            3 => "Importance of Skill: {$skill} in Industry: {$industry} explained in 3 clear bullets",
            4 => "Relevance of Skill: {$skill} to Job Role: {$jobRole} detailing specific daily impacts",
            5 => "Skill-specific challenges and learning needs listed as top 3 points",
            6 => "Expected learning outcomes stating what employees will achieve post-training",
            7 => "Stepwise skill-building framework for Skill: {$skill}",
            8 => "Tools and technologies essential for developing Skill: {$skill} briefly described",
            9 => "Assessment methods of Skill: {$skill} proposing 2–3 measurable evaluation approaches",
            10 => "Summary recapping key insights and outlining actionable next steps"
        ];

        // Additional slides for longer presentations
        $additionalSlides = [
            "Industry trends related to {$skill} in {$industry}",
            "Case studies of successful skill implementation in {$jobRole}",
            "ROI analysis of upskilling in {$skill}",
            "Best practices for {$skill} development",
            "Common pitfalls and how to avoid them when learning {$skill}",
            "Integration of {$skill} with existing workflows",
            "Future scope and evolution of {$skill} in {$industry}",
            "Success metrics and KPIs for {$skill} mastery",
            "Resource recommendations for continuous learning in {$skill}",
            "Peer learning and collaboration strategies for {$skill}",
            "Time management tips for {$skill} development",
            "Mentorship opportunities in {$skill} domain",
            "Certification paths for {$skill}",
            "Industry recognition and standards for {$skill}",
            "Cross-functional applications of {$skill}"
        ];

        $slideStructure = "";
        
        if ($slideCount == 1) {
            // Single slide - just title and key points
            $slideStructure = "Slide 1 – Title slide titled \"Upskilling for Industry: {$industry}, Department: {$department}, Job Role: {$jobRole}, Skill: {$skill}\" with 3-5 key bullet points summarizing the upskilling program";
        } elseif ($slideCount <= 10) {
            // Use only first N base slides
            for ($i = 1; $i <= $slideCount; $i++) {
                if (isset($baseSlides[$i])) {
                    $slideStructure .= "Slide {$i} – {$baseSlides[$i]}; ";
                }
            }
        } else {
            // Include all base slides plus additional slides
            $extraSlidesNeeded = $slideCount - 10;
            
            // Add all base slides
            for ($i = 1; $i <= 10; $i++) {
                $slideStructure .= "Slide {$i} – {$baseSlides[$i]}; ";
            }
            
            // Add additional slides
            for ($i = 0; $i < $extraSlidesNeeded; $i++) {
                $slideNumber = 11 + $i;
                $slideContent = $additionalSlides[$i % count($additionalSlides)];
                $slideStructure .= "Slide {$slideNumber} – {$slideContent}; ";
            }
        }
        
        return rtrim($slideStructure, '; ');
    }

    public function getSupervisor(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'sub_institute_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status_code' => 0,
                'message' => $validator->errors()->first()
            ], 400);
        }

        $user_id = $request->user_id;
        $sub_institute_id = $request->sub_institute_id;

        // 🔹 Step 1: Get user
        $user = DB::table('tbluser')
            ->where('id', $user_id)
            ->where('sub_institute_id', $sub_institute_id)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->first();

        if (!$user) {
            return response()->json([
                'status_code' => 0,
                'message' => 'User not found'
            ], 404);
        }

        // 🔹 Step 2: no supervisor recorded.
        //
        // THIS IS 200, NOT 404. The user WAS found - they simply have nobody
        // recorded as their manager. 404 means "this resource does not exist",
        // so the caller treats a normal, common state as a failure: the assign
        // form logs a red error every time an employee is picked.
        //
        // MEASURED, because "common" is a claim: 17 of 19 tenant-6 users and 352
        // of 1,400 overall have no employee_id. This is the majority case for
        // some organisations, not an edge case.
        //
        // ABSENT AND BROKEN ARE DIFFERENT ANSWERS. The caller can now tell them
        // apart: supervisor === null with a reason, versus a real error status.
        if (empty($user->employee_id)) {
            return response()->json([
                'status_code' => 1,
                'message' => 'No supervisor is recorded for this employee.',
                'supervisor' => null,
                'data' => null,
                'empty_is_expected' => true,
                'empty_reason' => 'This employee has no reporting manager set. Add one in the Employee Directory to have observers suggested automatically.',
            ]);
        }

        // 🔥 Step 3: Correct Query (id wise search)
        $supervisor = DB::table('tbluser')
            ->where('id', $user->employee_id) // ✅ FIX
            ->where('sub_institute_id', $sub_institute_id)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->first();

        if (!$supervisor) {
            return response()->json([
                'status_code' => 0,
                'message' => 'Supervisor not found',
            ], 404);
        }

        // 🔹 Step 4: Full name
        $fullName = trim(
            ($supervisor->name_suffix ?? '') . ' ' .
            ($supervisor->first_name ?? '') . ' ' .
            ($supervisor->middle_name ?? '') . ' ' .
            ($supervisor->last_name ?? '')
        );

        // 🔹 Step 5: Response
        return response()->json([
            'status_code' => 1,
            'message' => 'Supervisor found',
            'data' => [
                'id' => $supervisor->id,
                'name' => preg_replace('/\s+/', ' ', $fullName),
                'email' => $supervisor->email,
                'mobile' => $supervisor->mobile,
            ]
        ]);
    }
}
