<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Concerns\ResolvesEmployeeJobRole;
use App\Http\Controllers\Controller;
// use App\Models\HrmsJobTitle;
use App\Models\libraries\skillJobroleMap;
use App\Models\libraries\SLevelResponsibility;
use App\Models\libraries\userJobroleModel;
use App\Models\libraries\userJobroleTask;
use App\Models\school_setup\subjectModel;
use App\Models\settings\tblcustomfieldsModel;
use App\Models\settings\tblfields_dataModel;
use App\Models\skill\matrix;
use App\Models\skill\skill;
use App\Models\user\tbluserModel;
use App\Models\user\tbluserprofilemasterModel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

use function App\Helpers\is_mobile;

class tbluserController extends Controller
{
    use ResolvesEmployeeJobRole;

    // Used by updateFcmToken(), and by the jwtToken() guard that used to come
    // from GenTux\Jwt\GetsJwtToken - a package absent from composer.json and
    // never installed, so any class using it could not be loaded at all.
    // The rest of this controller serves session-authenticated web routes and
    // is deliberately left alone.
    use \App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;

    /**
     * Columns an API caller may see for someone else.
     *
     * Deliberately an allow-list, not a deny-list: a column added to tbluser
     * later should have to be named here before it reaches a browser, rather
     * than leaking by default. Nothing secret belongs in it - no password,
     * plain_password, otp, remember_token, fcm_token, pan_no, aadhar_no,
     * account_no or ifsc_code.
     */
    private const API_LIST_COLUMNS = [
        'tbluser.id',
        'tbluser.first_name',
        'tbluser.middle_name',
        'tbluser.last_name',
        // NO 'tbluser.full_name' HERE - IT IS NOT A COLUMN, IT IS AN ACCESSOR.
        // tbluser has 99 columns and full_name is not among them on either
        // database; tbluserModel supplies it through getFullNameAttribute()
        // and $appends. Listing it here made MySQL reject the whole SELECT, so
        // this endpoint answered 500 for EVERY API caller - the Employee
        // Directory and the profile page's Job Role Skills tab among them.
        // Nothing needs to replace it: the accessor was always doing the work.
        'tbluser.email',
        'tbluser.mobile',
        'tbluser.image',
        'tbluser.employee_no',
        'tbluser.employee_id',
        'tbluser.department_id',
        'tbluser.allocated_standards',
        'tbluser.jobtitle_id',
        'tbluser.user_profile_id',
        'tbluser.joined_date',
        'tbluser.city',
        'tbluser.state',
        'tbluser.supervisor_opt',
    ];

    /**
     * The single-employee shape, for the drawer. Wider than the list because
     * the drawer edits these fields - but bank account numbers, identity
     * documents and credentials are still not among them.
     */
    private const API_DETAIL_COLUMNS = [
        'id', 'name_suffix', 'first_name', 'middle_name', 'last_name', 'full_name',
        'email', 'mobile', 'gender', 'birthdate', 'image',
        'employee_no', 'employee_id', 'joined_date',
        'department_id', 'allocated_standards', 'jobtitle_id', 'user_profile_id',
        'subject_ids', 'status', 'supervisor_opt', 'reporting_method', 'reporting_manager_id',
        'address', 'address_2', 'city', 'state', 'pincode',
        'bank_name', 'branch_name', 'qualification',
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
        'monday_in_date', 'monday_out_date', 'tuesday_in_date', 'tuesday_out_date',
        'wednesday_in_date', 'wednesday_out_date', 'thursday_in_date', 'thursday_out_date',
        'friday_in_date', 'friday_out_date', 'saturday_in_date', 'saturday_out_date',
        'sunday_in_date', 'sunday_out_date',
    ];

    /**
     * Columns no request may ever write, whatever it posts.
     *
     * saveData()/updateData() copy every request key into the write, so
     * without this a caller could post is_admin=1 or user_profile_id=<admin>
     * and escalate. Mirrors UserImportController's list, which exists for the
     * same reason on the import path.
     */
    private const NEVER_WRITABLE = [
        'id', 'sub_institute_id', 'client_id', 'is_admin', 'portal_user',
        'password', 'plain_password', 'otp', 'remember_token', 'fcm_token',
        'created_by', 'created_at', 'deleted_by', 'deleted_at', 'last_login',
    ];

    public function index(Request $request)
    {
        // echo "<pre>";print_r(session()->all());exit;
        $sub_institute_id = session()->get('sub_institute_id');
        $user_id = session()->get('user_id');
        $user_profile = session()->get('user_profile_name');
        $type = $request->type;
        // If the request is from API, validate token and required fields
        if ($type == 'API') {
            $token = $request->input('token');  // get token from input field 'token'

            // Check if token is provided
            if (! $token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            // If token is invalid
            if (! $accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }
            // Validate required fields
            $validator = Validator::make($request->all(), [
                'org_type' => 'required',
                'sub_institute_id' => 'required',
            ]);

            // If validation fails
            if ($validator->fails()) {
                return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
            }
            $sub_institute_id = $request->get('sub_institute_id');
            $user_id = $request->get('user_id');
            $user_profile = $request->get('user_profile_name');
        }
        /*
         * `tbluser.*` for an API caller is a credential leak.
         *
         * The model declares no $hidden, so selecting every column serialised
         * all 99 of them per employee to the browser: the bcrypt `password`,
         * the cleartext `plain_password` (non-empty on 296 of 298 live rows),
         * plus `account_no`, `ifsc_code`, `pan_no`, `aadhar_no` and
         * `fcm_token`. The Employee Directory fetched that on every load.
         *
         * The Blade branch keeps `tbluser.*` because show_user.blade.php and
         * edit_user.blade.php read columns straight off the row - including
         * plain_password, which edit_user.blade.php:255 renders into its
         * password input. Narrowing that path would break those screens, so
         * the two callers get the two shapes they actually need.
         */
        $user_data = tbluserModel::select(
            array_merge(
                $type == 'API' ? self::API_LIST_COLUMNS : ['tbluser.*'],
                [
                    'tbluserprofilemaster.name as profile_name',
                    DB::raw('if(tbluser.status = 1,"Active","Inactive") as status'),
                    DB::raw('IFNULL(hrms_departments.department,"-") as department_name'),
                    DB::raw('IFNULL(s_user_jobrole.jobrole,"-") as jobrole'),
                    DB::raw('IFNULL(org_designation.designation,"-") as designation'),
                ]
            )
        )
            ->join('tbluserprofilemaster', 'tbluser.user_profile_id', '=', 'tbluserprofilemaster.id')
            ->leftJoin('hrms_departments', 'tbluser.department_id', '=', 'hrms_departments.id')
            ->leftJoin('s_user_jobrole', 'tbluser.allocated_standards', '=', 's_user_jobrole.id')
            ->leftJoin('org_designation', function ($join) use ($sub_institute_id) {
                $join->on('tbluser.id', '=', 'org_designation.user_id')
                    ->where('org_designation.sub_institute_id', '=', $sub_institute_id)
                    ->whereNull('org_designation.deleted_at');
            })
            ->where(['tbluser.sub_institute_id' => $sub_institute_id]) // , 'tbluser.status' => "1"
            ->when((! in_array(strtoupper($user_profile), ['ADMIN', 'SUPER ADMIN']) && ! $request->has('menu_type')), function ($q) use ($user_id) {
                $q->where('tbluser.id', $user_id);
            })
            ->when($request->has('active_status'), function ($q) use ($request) {
                $q->where('tbluser.status', $request->active_status);
            })
            ->get();

        $res['status_code'] = 1;
        $res['message'] = 'Success';
        // whereNull('deleted_at') matters now that Department Management soft
        // deletes: without it a retired department stays selectable in the
        // employee's Department picker and reassigns people back into it.
        $res['departments'] = DB::table('hrms_departments')->where('sub_institute_id', $sub_institute_id)->where('status', 1)->whereNull('deleted_at')->get()->toArray();
        $res['jobroleList'] = userJobroleModel::where('sub_institute_id', $sub_institute_id)->whereNull('deleted_at')->get()->toArray();
        $res['levelOfResponsbility'] = SLevelResponsibility::groupBy('level')->get()->toArray();
        $res['user_profiles'] = tbluserprofilemasterModel::where(['sub_institute_id' => $sub_institute_id])->get()->toArray();
        $res['data'] = $user_data;

        // THE PROFILE PAGE'S "Job Role Skills" TAB READS THIS KEY.
        // It was never set here, so the tab rendered empty even on a 200 - and
        // before that it never got the chance, because this endpoint answered
        // 500 for every API caller (see API_LIST_COLUMNS above).
        //
        // Only the API branch: Blade's show_user.blade.php does not read it.
        if ($type == 'API' && $user_id) {
            $res['skills'] = $this->jobRoleSkills((int) $sub_institute_id, (int) $user_id);
        }

        return is_mobile($type, 'user/show_user', $res, 'view');
    }

    /**
     * The skills the caller's JOB ROLE requires, for the profile page's
     * "Job Role Skills" tab.
     *
     * ═══════════════════════════════════════════════════════════════════
     * THE LIST NEVER DEPENDS ON A JOIN, AND THAT IS MEASURED
     * ═══════════════════════════════════════════════════════════════════
     *
     * s_user_skill_jobrole already carries everything the panel needs - the
     * role, the skill, its proficiency level and descriptor. The catalogue
     * lookup only ADDS category and description, so it is done separately and
     * is allowed to miss. Joining would be wrong twice over:
     *
     *   join on skill_id   dev: 16 of 16 rows resolve.  LIVE: 0 of 26 -
     *                      skill_id is empty on every live row for this role,
     *                      so an inner join returns an EMPTY skills tab.
     *   join on title      fans out: 26 rows become 115, because
     *                      s_users_skills.title is not unique. edit() joins
     *                      this way and needs a groupBy to undo the damage.
     *
     * So: read the rows, then enrich by skill_id where it resolves and by
     * title within the tenant otherwise (14 of 26 on live). A skill whose
     * catalogue row is missing still appears, carrying its own descriptor.
     *
     * Knowledge/ability/behaviour/attitude come from s_skill_knowledge_ability
     * in ONE query rather than one per skill. That table holds 168k rows and
     * NONE for tenant 6, so those lists are legitimately empty there - the
     * panel omits an empty list rather than showing a heading with nothing
     * under it.
     */
    private function jobRoleSkills(int $subInstituteId, int $userId): array
    {
        $user = DB::table('tbluser')
            ->where('id', $userId)
            ->where('sub_institute_id', $subInstituteId)
            ->first(['id', 'jobtitle_id', 'allocated_standards']);

        // Resolved through BOTH columns by the shared trait. jobtitle_id is 0
        // for most employees because the employee form writes the role to
        // allocated_standards; reading either alone loses most people.
        $jobroleId = $user ? $this->resolveJobRoleId($user) : null;

        if (! $jobroleId) {
            return [];
        }

        $rows = DB::table('s_user_skill_jobrole')
            ->where('jobrole_id', $jobroleId)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->orderBy('skill')
            ->get([
                'id', 'jobrole', 'jobrole_id', 'skill', 'skill_id', 'type',
                'proficiency_level', 'proficiency_description', 'skill_code',
            ]);

        if ($rows->isEmpty()) {
            return [];
        }

        // -- enrichment, allowed to miss --------------------------------
        $ids = $rows->pluck('skill_id')->filter()->unique()->values();
        $titles = $rows->pluck('skill')->filter()->unique()->values();

        $catalogue = DB::table('s_users_skills')
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($ids, $titles) {
                if ($ids->isNotEmpty()) {
                    $q->orWhereIn('id', $ids);
                }
                if ($titles->isNotEmpty()) {
                    $q->orWhereIn('title', $titles);
                }
            })
            ->get(['id', 'title', 'category', 'sub_category', 'description']);

        $byId = $catalogue->keyBy('id');
        // FIRST MATCH WINS on title, deliberately. Duplicate titles are what
        // makes the join fan out; picking one is what stops it.
        $byTitle = $catalogue->groupBy('title')->map(fn ($g) => $g->first());

        // -- knowledge / ability / behaviour / attitude, in ONE query ----
        $kabaBySkill = collect();

        if ($ids->isNotEmpty()) {
            $kabaBySkill = DB::table('s_skill_knowledge_ability')
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->whereIn('skill_id', $ids)
                ->get(['skill_id', 'proficiency_level', 'classification', 'classification_item'])
                ->groupBy(fn ($r) => $r->skill_id . '|' . $r->proficiency_level);
        }

        return $rows->map(function ($r) use ($byId, $byTitle, $kabaBySkill) {
            $cat = ($r->skill_id && $byId->has($r->skill_id))
                ? $byId->get($r->skill_id)
                : $byTitle->get($r->skill);

            $kaba = $kabaBySkill->get($r->skill_id . '|' . $r->proficiency_level, collect())
                ->groupBy('classification');

            $items = fn (string $k) => $kaba->has($k)
                ? $kaba->get($k)->pluck('classification_item')->values()->all()
                : [];

            return [
                'jobrole_skill_id' => (int) $r->id,
                'jobrole' => $r->jobrole,
                'skill' => $r->skill,
                'skill_id' => $r->skill_id ? (int) $r->skill_id : 0,
                'title' => $cat->title ?? $r->skill,
                'category' => $cat->category ?? null,
                'sub_category' => $cat->sub_category ?? null,
                // The role's own descriptor is the more specific of the two -
                // it says what this level means FOR THIS ROLE - so it wins.
                'description' => $r->proficiency_description ?: ($cat->description ?? null),
                'proficiency_level' => $r->proficiency_level === null ? null : (string) $r->proficiency_level,
                'skill_type' => $r->type,
                'skill_code' => $r->skill_code,
                'knowledge' => $items('knowledge'),
                'ability' => $items('ability'),
                'behaviour' => $items('behaviour'),
                'attitude' => $items('attitude'),
            ];
        })->values()->all();
    }

    public function create(Request $request)
    {
        $sub_institute_id = $request->session()->get('sub_institute_id');
        $data = tbluserprofilemasterModel::where(['sub_institute_id' => $sub_institute_id, 'status' => '1'])->get()->toArray();

        $dataCustomFields = tblcustomfieldsModel::where(['sub_institute_id' => $sub_institute_id, 'status' => '1', 'table_name' => 'tbluser', 'user_type' => ''])->get();

        $subject_data = subjectModel::where(['sub_institute_id' => $sub_institute_id])->get();
        $employees = tbluserModel::where('sub_institute_id', $sub_institute_id)->where('status', 1)->get();
        $job_titles = []; // HrmsJobTitle::where('sub_institute_id',$sub_institute_id)->get();
        $departments = DB::table('hrms_departments')->where('sub_institute_id', $sub_institute_id)->where('status', 1)->get()->toArray();
        $fieldsData = tblfields_dataModel::get()->toArray();
        $i = 0;
        $finalfieldsData = [];
        foreach ($fieldsData as $key => $value) {
            $finalfieldsData[$value['field_id']][$i]['display_text'] = $value['display_text'];
            $finalfieldsData[$value['field_id']][$i]['display_value'] = $value['display_value'];
            $i++;
        }

        if (count($finalfieldsData) > 0) {
            view()->share('data_fields', $finalfieldsData);
        }

        // auto increament 20-04-24
        $maxEmpCode = DB::table('tbluser')->selectRaw('MAX(CAST(employee_no AS INT)) AS new_emp_code')
            ->where('sub_institute_id', $sub_institute_id)->whereRaw('employee_no is not null')->limit(1)->orderBy('id')->get()->toArray();

        $maxEmpCode = array_map(function ($value) {
            return (array) $value;
        }, $maxEmpCode);

        $new_emp_code = ($maxEmpCode['0']['new_emp_code'] + 1) ?? 1;

        $qualificationList = tbluserModel::where('sub_institute_id', $sub_institute_id)->where('status', 1)->whereNotNull('qualification')->groupBy('qualification')->pluck('qualification');

        $occupationList = tbluserModel::where('sub_institute_id', $sub_institute_id)->where('status', 1)->whereNotNull('occupation')->groupBy('occupation')->pluck('occupation');

        // start 30-07-2024
        $masterSetups = []; // DB::table('master_setup_select')->select('type','fieldname',DB::raw('GROUP_CONCAT(fieldValue SEPARATOR "||") as selOptions'))->where('sub_institute_id',$sub_institute_id)->groupBy('type')->get()->toArray();
        $pluckedData = [];
        foreach ($masterSetups as $setup) {
            if (! isset($pluckedData[$setup->type])) {
                $pluckedData[$setup->type] = [];
            }
            $pluckedData[$setup->type]['fieldname'] = $setup->fieldname;
            $pluckedData[$setup->type]['fieldvalue'] = $setup->selOptions; // array ['skills']['select skill']=skill1 || skill 2 || skill 3
        }
        // end 30-07-2024

        view()->share('qualificationList', $qualificationList);
        view()->share('occupationList', $occupationList);

        view()->share('new_emp_code', $new_emp_code);
        // end 20-04-24
        view()->share('custom_fields', $dataCustomFields);
        view()->share('subject_data', $subject_data);
        view()->share('user_profiles', $data);
        view()->share('job_titles', $job_titles);
        view()->share('employees', $employees);
        view()->share('departments', $departments);
        view()->share('masterSetups', $pluckedData);

        return view('user/add_user');
    }

    public function store(Request $request)
    {
        // return $request->all();
        $sub_institute_id = $request->session()->get('sub_institute_id');
        $type = $request->input('type');

        // Validate email format
        $email = $request->input('email');
        if ($email) {
            // Check for valid email format
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $res['status_code'] = '0';
                $res['message'] = 'Invalid email address format';
                $res['data'] = null;

                return is_mobile($type, 'add_user.index', $res);
            }

            // Check for duplicate email (globally unique across the system)
            $existingUser = tbluserModel::where('email', $email)
                ->first();

            if ($existingUser) {
                $res['status_code'] = '0';
                $res['message'] = 'Email address already exists';
                $res['data'] = null;

                return is_mobile($type, 'add_user.index', $res);
            }
        }

        $file_name = '';
        if ($request->hasFile('user_image')) {
            $file = $request->file('user_image');
            $originalname = $file->getClientOriginalName();
            $name = $request->get('user_name').date('YmdHis');
            $ext = File::extension($originalname);
            $file_name = $name.'.'.$ext;
            // $path = $file->storeAs('public/user/', $file_name);
            $path = Storage::disk('digitalocean')->putFileAs(
                'hp_user',
                $file,
                $file_name,
                'public'
            );

            $publicUrl = Storage::disk('digitalocean')->url($path);
        }

        $request->request->add(['image' => $file_name]); // add request
        $data = $this->saveData($request);

        $data = tbluserModel::where(['sub_institute_id' => $sub_institute_id])->get();

        $res['status_code'] = '1';
        $res['message'] = 'User created successfully';
        $res['data'] = $data;

        return is_mobile($type, 'add_user.index', $res);
    }

    public function saveData(Request $request)
    {
        $newRequest = $request->all();
        $sub_institute_id = $request->session()->get('sub_institute_id');
        $finalArray['sub_institute_id'] = $sub_institute_id;
        $finalArray['status'] = 1;
        unset($newRequest['user_image']);

        // Same allow-list as updateData(): tbluserModel::insert() below is the
        // query builder, so $fillable is never consulted and every request key
        // would otherwise be written - including is_admin.
        $writable = array_diff($this->userColumns(), self::NEVER_WRITABLE);

        foreach ($newRequest as $key => $value) {
            if (in_array($key, $writable, true)) {
                if (is_array($value)) {
                    $value = implode(',', $value);
                }
                $finalArray[$key] = $value;
            }

            if ($key == 'password' && ! empty($value)) {
                $finalArray[$key] = Hash::make($value);
            }

            if ($key == 'birthdate' && ! empty($value)) {
                $finalArray[$key] = carbon::parse($value)->format('Y-m-d');
            }
        }
        $finalArray['created_at'] = now();
        $finalArray['created_by'] = session()->get('user_id');
        tbluserModel::insert($finalArray);
        $id = DB::getPdo()->lastInsertId();

        // $client_data = DB::table("school_setup as s")
        //     ->join('tblclient as c', function ($join) {
        //         $join->whereRaw("c.id = s.client_id");
        //     })
        //     ->selectRaw('*,if(db_hrms is null,0,1) as rights')
        //     ->where("s.Id", "=", $sub_institute_id)
        //     ->get()->toArray();

        // $hrms_db_host = $client_data[0]->db_host;
        // $hrms_db_user = $client_data[0]->db_user;
        // $hrms_db_password = $client_data[0]->db_password;
        // $hrms_db_hrms = $client_data[0]->db_hrms;
        // $hrms_rights = $client_data[0]->rights;

        // if ($hrms_rights == 1 && $id != "") {
        //     $fields = [
        //         'db_host'     => $hrms_db_host,
        //         'db_user'     => $hrms_db_user,
        //         'db_password' => $hrms_db_password,
        //         'db_hrms'     => $hrms_db_hrms,
        //     ];
        //     $fields = array_merge($fields, $finalArray);

        //     //url-ify the data for the POST
        //     $fields_string = "";
        //     foreach ($fields as $key => $value) {
        //         $fields_string .= $key . '=' . $value . '&';
        //     }
        //     rtrim($fields_string, '&');
        //     //open connection
        //     $ch = curl_init();

        //     $url = "http://" . $_SERVER['HTTP_HOST'] . "/add_user_hrms.php";

        //     //set the url, number of POST vars, POST data
        //     curl_setopt($ch, CURLOPT_URL, $url);
        //     curl_setopt($ch, CURLOPT_POST, count($fields));
        //     curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);

        //     //execute post
        //     $result = curl_exec($ch);

        //     //close connection
        //     curl_close($ch);
        // }

        return $id;
    }

    public function updateData(Request $request, $id)
    {
        // return $request;exit;
        $sub_institute_id = $request->session()->get('sub_institute_id');
        $user_id = session()->get('user_id');
        if ($request->type == 'API') {
            $sub_institute_id = $request->input('sub_institute_id');
            $user_id = $request->input('user_id');
        }
        $newRequest = $request->all();
        // $user_id = $newRequest['id'];
        $finalArray['sub_institute_id'] = $sub_institute_id;
        unset($newRequest['user_image']);

        /*
         * `status` used to be forced to 1 on every update, so saving any field
         * on a suspended employee silently reactivated them - deactivating
         * anyone was impossible while this ran. It is now only written when
         * the caller actually asked, and only as 0 or 1.
         */
        if ($request->has('status')) {
            $finalArray['status'] = (int) $request->input('status') === 0 ? 0 : 1;
        }
        unset($newRequest['status']);

        /*
         * Only real columns, and never the ones in NEVER_WRITABLE.
         *
         * Every request key used to be copied straight into the update, so a
         * caller could post is_admin=1 or user_profile_id=<admin> and escalate,
         * and a key that was not a column threw a SQL error instead. The
         * column list is intersected rather than enumerated because the Blade
         * edit form posts most of the table and an allow-list would silently
         * stop saving whatever it forgot.
         */
        $writable = array_diff($this->userColumns(), self::NEVER_WRITABLE);

        foreach ($newRequest as $key => $value) {
            if (in_array($key, $writable, true)) {
                if (is_array($value)) {
                    $value = implode(',', $value);
                }

                // Convert time fields to HH:MM:SS
                if (Str::endsWith($key, '_in_date') || Str::endsWith($key, '_out_date')) {
                    if (! empty($value)) {
                        $value = date('H:i:s', strtotime($value));
                    } else {
                        $value = null;
                    }
                }

                $finalArray[$key] = $value;
            }

            // password is in NEVER_WRITABLE so it is not in $writable, but the
            // Blade edit form does legitimately change it. Hash it here rather
            // than letting a raw value through the loop above.
            if ($key == 'password' && ! empty($value)) {
                $finalArray[$key] = Hash::make($value);
            }

            if ($key == 'birthdate' && ! empty($value)) {
                $finalArray[$key] = carbon::parse($value)->format('Y-m-d');
            }
        }

        $finalArray['updated_at'] = now();
        $finalArray['updated_by'] = $user_id;

        // Tenant scope: without it any signed-in caller could overwrite any
        // employee in any tenant by id.
        return tbluserModel::where('id', $id)
            ->where('sub_institute_id', $sub_institute_id)
            ->update($finalArray);
    }

    /**
     * tbluser's real column list, resolved once per request.
     *
     * Schema::getColumnListing is safe on the live MariaDB 10.1 box - it is
     * hasColumn()/hasTable() that throw there, because Laravel 11 selects
     * `generation_expression`, which 10.1 does not have.
     */
    /**
     * One classification group as {id, item} pairs.
     *
     * The id is s_skill_knowledge_ability.id, which is what the Jobrole Skill
     * tab stores when someone confirms an item, and what
     * EmployeeCompetencyProfileController::upsertSkillBySkillId validates
     * against. The label rides along for display.
     */
    private function classificationItems($grouped, string $classification): array
    {
        if (! $grouped->has($classification)) {
            return [];
        }

        return $grouped[$classification]
            ->map(fn ($row) => ['id' => (int) $row->id, 'item' => $row->classification_item])
            ->values()
            ->all();
    }

    private function userColumns(): array
    {
        static $columns = null;

        if ($columns === null) {
            $columns = \Illuminate\Support\Facades\Schema::getColumnListing('tbluser');
        }

        return $columns;
    }

    public function edit(Request $request, $id)
    {
        $type = $request->input('type');
        $userLevelOfResponsibility = [];

        if ($type == 'API') {
            $validator = Validator::make($request->all(), [
                'sub_institute_id' => 'required|numeric',
                'syear' => 'required|numeric',
                'type' => 'required',
            ]);

            if ($validator->fails()) {
                $res['status'] = '0';
                $res['message'] = $validator->messages()->first();

                return is_mobile($type, 'add_user.index', $res);
            }
            $sub_institute_id = $request->input('sub_institute_id');
            $syear = $request->input('syear');
        } else {
            $sub_institute_id = $request->session()->get('sub_institute_id');
            $syear = session()->get('syear');
        }

        /*
         * find($id) was unscoped, so any signed-in caller could read any
         * employee in any tenant by guessing an id - and ->toArray() on the
         * null it returns for a missing id was a fatal, not a 404.
         */
        $editRow = tbluserModel::where('id', $id)
            ->where('sub_institute_id', $sub_institute_id)
            ->first();

        if (! $editRow) {
            $res = ['status' => '0', 'message' => 'Employee not found'];

            return is_mobile($type, 'add_user.index', $res);
        }

        $editData = $editRow->toArray();
        $data = tbluserprofilemasterModel::where(['sub_institute_id' => $sub_institute_id])->get()->toArray();
        $subject_data = subjectModel::where(['sub_institute_id' => $sub_institute_id])->get()->toArray();
        $userLevels = DB::table('s_level_responsibility')->where('id', $editData['subject_ids'])
                // ->groupBy('level')
            ->first();
        $userLevelsArr = DB::table('s_level_responsibility')->where('level', $userLevels->level ?? 0)
                // ->groupBy('level')
            ->get();

        $allLevels = $userLevelOfResponsibility = [];
        foreach ($userLevelsArr as $key => $value) {
            $userLevelOfResponsibility['level'] = $value->level;
            $userLevelOfResponsibility['guiding_phrase'] = $value->guiding_phrase;
            $userLevelOfResponsibility['essence_level'] = $value->essence_level;
            $userLevelOfResponsibility['guidance_note'] = $value->attribute_guidance_notes;
            if ($value->attribute_type != 'Business skills/Behavioural factors') {
                $userLevelOfResponsibility[$value->attribute_type][$value->attribute_name] = $value;
            } else {
                $userLevelOfResponsibility['Business_skills'][str_replace(' ', '_', $value->attribute_name)] = $value;

            }
        }
        // $userLevelOfResponsibility = array_values($allLevels)
        // if (isset($subject_data_selected)) {
        //     $userLevelOfResponsibility =DB::table('s_level_responsibility')->where('level', $editData['subject_ids'])
        //         ->where('sub_institute_id', $sub_institute_id)
        //         ->toArray();
        // }

        $dataCustomFields = tblcustomfieldsModel::where([
            'sub_institute_id' => $sub_institute_id,
            'status' => '1',
            'table_name' => 'tbluser',
            'user_type' => '',
        ])->get();

        $fieldsData = tblfields_dataModel::get()->toArray();
        $i = 0;
        $finalfieldsData = [];
        foreach ($fieldsData as $key => $value) {
            $finalfieldsData[$value['field_id']][$i]['display_text'] = $value['display_text'];
            $finalfieldsData[$value['field_id']][$i]['display_value'] = $value['display_value'];
            $i++;
        }

        if (count($finalfieldsData) > 0) {
            $res['data_fields'] = $finalfieldsData ?? [];
        }

        // auto increament 20-04-24
        $empCode = DB::table('tbluser')->where('id', $id)->first();
        /* //Hide By Rajesh 19-11-2024 : Edit time not max+1 in emp_no (provide Add time only)
        if(!isset($empCode->employee_no) || $empCode->employee_no=='' || $empCode->employee_no==null){
            $maxEmpCode = DB::table('tbluser')->selectRaw("MAX(CAST(employee_no AS INT)) AS new_emp_code")
            ->where('sub_institute_id', $sub_institute_id)->whereRaw('employee_no is not null')->limit(1)->orderBy('id')->get()->toArray();

            $maxEmpCode = array_map(function ($value) {
                    return (array) $value;
                }, $maxEmpCode);

            $new_emp_code = ($maxEmpCode['0']['new_emp_code'] + 1) ?? 1;
        }else{
            $new_emp_code = $empCode->employee_no ? $empCode->employee_no : 1;
        }
        */
        $new_emp_code = $empCode->employee_no;

        $res['qualificationList'] = tbluserModel::where('sub_institute_id', $sub_institute_id)->where('status', 1)->whereNotNull('qualification')->groupBy('qualification')->pluck('qualification');

        $res['occupationList'] = tbluserModel::where('sub_institute_id', $sub_institute_id)->where('status', 1)->whereNotNull('occupation')->groupBy('occupation')->pluck('occupation');

        $res['documentTypeLists'] = DB::table('student_document_type')->where('status', 1)->where('user_type', 'staff')->get()->toArray();
        $res['documentLists'] = DB::table('staff_document')->select('staff_document.*', 'd.document_type')
            ->join('student_document_type as d', 'd.id', 'staff_document.document_type_id')
            ->where(['sub_institute_id' => $sub_institute_id, 'user_id' => $id])
            ->get()
            ->toArray();
        // end  20-04-24

        $departments = DB::table('hrms_departments')->where('sub_institute_id', $sub_institute_id)->where('status', 1)->get()->toArray();
        if (isset($editData['id'])) {
            $editData['userDepartment'] = $editData['userJobrole'] = '';
            if (isset($editData['department_id'])) {
                $editData['userDepartment'] = DB::table('hrms_departments')->where('sub_institute_id', $sub_institute_id)->where('status', 1)->where('id', $editData['department_id'])->value('department');
            }
            if (isset($editData['allocated_standards'])) {
                $editData['userJobrole'] = userJobroleModel::where('sub_institute_id', $sub_institute_id)->where('id', $editData['allocated_standards'])->value('jobrole');
            }
        }
        // echo "<pre>";print_r($editData->id);exit;
        // start 29-07-2024
        $masterSetups = []; // DB::table('master_setup_select')->select('type','fieldname',DB::raw('GROUP_CONCAT(fieldValue SEPARATOR "||") as selOptions'))->where('sub_institute_id',$sub_institute_id)->groupBy('type')->get()->toArray();
        $pluckedData = [];
        foreach ($masterSetups as $setup) {
            if (! isset($pluckedData[$setup->type])) {
                $pluckedData[$setup->type] = [];
            }
            $pluckedData[$setup->type]['fieldname'] = $setup->fieldname;
            $pluckedData[$setup->type]['fieldvalue'] = $setup->selOptions; // array ['skills']['select skill']=skill1 || skill 2 || skill 3
        }
        // end 29-07-2024

        // 29-10-2024 salary data
        $payrollTypes = []; // DB::table('payroll_types')->where(['sub_institute_id'=>$sub_institute_id,'status'=>1])->get()->toArray();
        // get type id of salary deposite
        $SalaryDeposit = [];
        $getSalaryDeposit = []; // DB::table('payroll_types')->where(['sub_institute_id'=>$sub_institute_id,'payroll_name'=>'Salary Deposit'])->first();
        if (! empty($getSalaryDeposit)) {
            // get employee salary structure to get amount
            $depositData = DB::table('hrms_emp_payroll_deduction')
                ->where(['sub_institute_id' => $sub_institute_id, 'employee_id' => $id, 'deduction_type' => $getSalaryDeposit->id])
                ->where('deduction_amount', '>', 0)
                ->orderByRaw("FIELD(month, 'Apr','May','Jun', 'Jul', 'Aug','Sep','Oct','Nov','Dec','Jan','Feb','Mar')")
                ->get()
                ->toArray();

            foreach ($depositData as $key => $value) {
                $depositArr = [
                    'year' => $value->year,
                    'month' => $value->month,
                    'amount' => $value->deduction_amount,
                ];
                $SalaryDeposit[] = $depositArr;
            }
            // echo "<pre>";print_r($SalaryDeposit);exit;
        }
        // get year wise salary data
        $SalaryStructure = []; // DB::table('employee_salary_structures')->where(['sub_institute_id'=>$sub_institute_id,'employee_id'=>$id])->orderBy('id','DESC')->get()->toArray();

        $res['payroll_types'] = $payrollTypes;
        $res['salary_deposit'] = $SalaryDeposit;
        $res['salary_structure'] = $SalaryStructure;
        // 29-10-2024 end
        $res['masterSetups'] = $pluckedData;
        $res['departments'] = $departments;
        /*
         * This list exists to fill one "Reporting Manager" dropdown, and it
         * used to return every column of every employee in the tenant to do
         * it - 298 full rows including plain_password. The consumer
         * (PersonalInfoTab) reads a name and an id.
         */
        $res['employees'] = tbluserModel::where('sub_institute_id', $sub_institute_id)
            ->whereNull('deleted_at')
            ->when($type == 'API', fn ($q) => $q->select('id', 'first_name', 'last_name', 'employee_no'))
            ->get();
        $res['job_titles'] = []; // HrmsJobTitle::where('sub_institute_id',$sub_institute_id)->get();
        $res['custom_fields'] = $dataCustomFields;
        $res['subject_data'] = $subject_data;
        $res['userLevelOfResponsibility'] = $userLevelOfResponsibility;
        $res['user_profiles'] = $data;
        $res['new_emp_code'] = $new_emp_code;
        // db::enableQueryLog();
        $res['contactDetails'] = [];
        // dd(db::getQueryLog($res['contactDetails']));
        // API callers get the editable subset; Blade keeps the whole row,
        // which edit_user.blade.php depends on (it prefills plain_password).
        $res['data'] = $type == 'API'
            ? array_intersect_key($editData, array_flip(self::API_DETAIL_COLUMNS))
            : $editData;
        // 10-01-2025 start supervisor rights
        $res['jobroleList'] = userJobroleModel::where('sub_institute_id', $sub_institute_id)->whereNull('deleted_at')->get()->toArray();
        $user_id = $id;
        $profileDetails = DB::table('tbluserprofilemaster')->where('id', $editData['user_profile_id'])->first();
        $user_profile_name = $profileDetails->name ?? '';
        // echo "<pre>";print_r($profileDetails);exit;

        $res['skills'] = $skills = []; // skillJobroleMap::join('s_users_skills', 's_user_skill_jobrole.skill', '=', 's_users_skills.title')->whereNull('s_user_skill_jobrole.deleted_at')
        //     ->select('*', 's_users_skills.id as skill_id', 's_user_skill_jobrole.proficiency_level as proficiency_level')
        //     ->groupBy('s_user_skill_jobrole.id')
        //     ->get()->map(function ($item) {
        //         // Load knowledge and ability from the classification table
        //         $classificationItems = DB::table('s_skill_knowledge_ability')
        //             ->where('skill_id', $item->skill_id)
        //             ->where('proficiency_level', $item->proficiency_level) // or dynamic if needed
        //             ->get()
        //             ->groupBy('classification');

        //         $item->knowledge = $classificationItems->has('knowledge')
        //             ? $classificationItems['knowledge']->pluck('classification_item')->toArray()
        //             : [];

        //         $item->ability = $classificationItems->has('ability')
        //             ? $classificationItems['ability']->pluck('classification_item')->toArray()
        //             : [];

        //         return $item;
        //     });

        // echo "<pre>";print_r($res['skills']);exit;
        $res['completedCount'] = $completedCount = 0; // matrix::where('user_id', $user_id)->count();
        $res['totalSkills'] = $totalSkills = 0; // $skills->count();
        $progress = 0; // $totalSkills > 0 ? round(($completedCount / $totalSkills) * 100) : 0;
        $res['progress'] = $progress;
        $res['userRatedSkills'] = matrix::join('s_users_skills', 's_users_skills.id', '=', 's_skill_matrix.skill_id')
            ->where('s_skill_matrix.user_id', $id)
            ->where('s_users_skills.sub_institute_id', $sub_institute_id)
            ->whereNull('s_users_skills.deleted_at')
            ->select([
                's_skill_matrix.*',
                's_users_skills.title',
                's_users_skills.category',
                's_users_skills.sub_category',
                's_users_skills.description',
            ])
            ->get()->toArray();
        // echo "<pre>";print_r($res['userRatedSkills']);exit;
        $res['jobroleSkills'] = $res['jobroleTasks'] = [];
        // if (!in_array($user_profile_name, ['Admin', 'Supervisor'])) {

        $assignedJobrole = userJobroleModel::where('sub_institute_id', $sub_institute_id)->where('id', $editData['allocated_standards'])->whereNull('deleted_at')->first();
        // echo "<pre>";print_r($assignedJobrole);exit;

        if (isset($assignedJobrole)) {
            $alreadyRated = matrix::where('user_id', $user_id)->get()->toArray();
            $ratedIds = [];
            foreach ($alreadyRated as $rated) {
                $ratedIds[] = $rated['skill_id'] ?? 0;
            }
            $res['skills'] = skillJobroleMap::with([
                'userSkills' => function ($query) use ($ratedIds) {
                    $query->whereNotIn('id', $ratedIds)
                        ->select(['id', 'title',
                            'category', 'sub_category',
                            'description']); // Add required fields
                },
            ])
                ->where('jobrole', $assignedJobrole->jobrole)
                ->whereNull('deleted_at')
                ->where('sub_institute_id', $sub_institute_id)
                ->groupBy('id')
                ->get()
                // filter out items where userSkills is null (skill_id would be null)
                ->filter(function ($item) {
                    return ! is_null($item->userSkills);
                })
                ->map(function ($item) {
                    $classificationItems = DB::table('s_skill_knowledge_ability')
                        ->where('skill_id', $item->userSkills->id)
                        ->where('proficiency_level', $item->proficiency_level)
                        ->where('sub_institute_id', $item->sub_institute_id)
                        ->whereNull('deleted_at')
                        ->get()
                        ->groupBy('classification');

                    $classificationItems2 = DB::table('s_skill_knowledge_ability')
                        ->where('skill_id', $item->userSkills->id)
                        ->where('sub_institute_id', $item->sub_institute_id)
                        ->whereNull('deleted_at')
                        ->get()
                        ->groupBy('classification');

                    return [
                        'jobrole_skill_id' => $item->id,
                        'jobrole' => $item->jobrole,
                        'skill' => $item->skill,
                        'skill_id' => $item->userSkills->id,
                        'title' => $item->userSkills->title,
                        'category' => $item->userSkills->category,
                        'sub_category' => $item->userSkills->sub_category,
                        'description' => $item->userSkills->description,
                        'proficiency_level' => $item->proficiency_level,
                        /*
                         * id AND label, not just the label.
                         *
                         * These used to be pluck('classification_item') - the
                         * prose only, with s_skill_knowledge_ability.id thrown
                         * away even though the query had already fetched it.
                         * That is why the Jobrole Skill tab could not persist
                         * its confirmations: it had nothing stable to store.
                         * A label is not an identity; renaming a library item
                         * would silently orphan every tick against it.
                         */
                        'knowledge' => $this->classificationItems($classificationItems, 'knowledge'),
                        'ability' => $this->classificationItems($classificationItems, 'ability'),
                        'behaviour' => $this->classificationItems($classificationItems2, 'behaviour'),
                        'attitude' => $this->classificationItems($classificationItems2, 'attitude'),
                    ];
                })
                ->values(); // reset array keys

            // $res['jobroleSkills'] = skillJobroleMap::join('s_users_skills', 's_user_skill_jobrole.skill', '=', 's_users_skills.title')
            //     ->where('s_user_skill_jobrole.jobrole', $assignedJobrole->jobrole)
            //     ->whereNull('s_user_skill_jobrole.deleted_at')
            //     ->select(
            //         's_user_skill_jobrole.id as jobrole_skill_id',
            //         's_user_skill_jobrole.jobrole',
            //         's_user_skill_jobrole.skill',
            //         's_users_skills.id as skill_id',
            //         's_user_skill_jobrole.proficiency_level as proficiency_level',
            //         's_users_skills.title',
            //         's_users_skills.category',
            //         's_users_skills.sub_category',
            //         's_users_skills.description'
            //     )
            //     ->groupBy(['s_user_skill_jobrole.id', 's_users_skills.proficiency_level'])
            //     ->get()
            //     ->map(function ($item) {
            //         // Load knowledge and ability from the classification table
            //         $classificationItems = DB::table('s_skill_knowledge_ability')
            //             ->where('skill_id', $item->skill_id)
            //             ->where('proficiency_level', $item->proficiency_level) // or dynamic if needed
            //             ->get()
            //             ->groupBy('classification');

            //         $item->knowledge = $classificationItems->has('knowledge')
            //             ? $classificationItems['knowledge']->pluck('classification_item')->toArray()
            //             : [];

            //         $item->ability = $classificationItems->has('ability')
            //             ? $classificationItems['ability']->pluck('classification_item')->toArray()
            //             : [];

            //         return $item;
            //     });

            $res['jobroleSkills'] = skillJobroleMap::with('userSkills')
                ->where('jobrole', $assignedJobrole->jobrole)
                ->where('sub_institute_id', $sub_institute_id)
                ->whereNull('deleted_at')
                ->groupBy('id')
                ->get()
                ->map(function ($item) {
                    // Initialize a new object/array to hold the mapped data
                    $mappedItem = new \stdClass; // or use an array: $mappedItem = [];

                    $classificationItems = DB::table('s_skill_knowledge_ability')
                        ->where('skill_id', $item->userSkills->id ?? null)
                        ->where('proficiency_level', $item->proficiency_level)
                        ->where('sub_institute_id', $item->sub_institute_id)
                        ->whereNull('deleted_at')
                        ->get()
                        ->groupBy('classification');

                    $classificationItems2 = DB::table('s_skill_knowledge_ability')
                        ->where('skill_id', $item->userSkills->id ?? null)
                        ->where('sub_institute_id', $item->sub_institute_id)
                        ->whereNull('deleted_at')
                        // ->where('proficiency_level', $item->proficiency_level)
                        ->get()
                        ->groupBy('classification');

                    // Assign properties to the new object
                    $mappedItem->jobrole_skill_id = $item->id;
                    $mappedItem->jobrole = $item->jobrole;
                    $mappedItem->skill = $item->skill;
                    $mappedItem->skill_id = $item->userSkills->id ?? null;
                    $mappedItem->title = $item->userSkills->title ?? null;
                    $mappedItem->category = $item->userSkills->category ?? null;
                    $mappedItem->sub_category = $item->userSkills->sub_category ?? null;
                    $mappedItem->description = $item->userSkills->description ?? null;
                    $mappedItem->proficiency_level = $item->proficiency_level;
                    $mappedItem->knowledge = $classificationItems->has('knowledge')
                        ? $classificationItems['knowledge']->pluck('classification_item')->toArray()
                        : [];
                    $mappedItem->ability = $classificationItems->has('ability')
                        ? $classificationItems['ability']->pluck('classification_item')->toArray()
                        : [];
                    $mappedItem->behaviour = $classificationItems2->has('behaviour')
                        ? $classificationItems2['behaviour']->pluck('classification_item')->toArray()
                        : [];
                    $mappedItem->attitude = $classificationItems2->has('attitude')
                        ? $classificationItems2['attitude']->pluck('classification_item')->toArray()
                        : [];

                    return $mappedItem;
                });

            $res['totalSkills'] = skillJobroleMap::where('jobrole', $assignedJobrole->jobrole)->where('sub_institute_id', $sub_institute_id)->count();
            // DB::enableQueryLog();
            // $res['jobroleTasks'] = DB::table('s_user_jobrole_task as a')
            //     ->join('s_user_skill_jobrole as b', 'b.jobrole', '=', 'a.jobrole')
            //     ->where('a.jobrole', $assignedJobrole->jobrole)
            //     ->whereNull('a.deleted_at')
            //     ->groupBy('task')
            //     ->get();
            $res['jobroleTasks'] = userJobroleTask::with('jobroleSkillModel')
                ->where('jobrole', $assignedJobrole->jobrole)->where('sub_institute_id', $sub_institute_id)
                ->whereNull('deleted_at')
                ->groupBy('task')
                ->get();
            // dd(DB::getQueryLog($res['jobroleTasks']));
        }

        // }
        // subject_ids holds ROW IDs, not level numbers - see
        // responsibilityLevelsFor(). Comparing it to `level` matched nothing for
        // 150 of the 187 employees who have a level set.
        $detailsLevel = SLevelResponsibility::whereIn('level', $this->responsibilityLevelsFor($editData['subject_ids'] ?? null))
            ->get()->toArray();
        $allLevels = $attrData = [];
        foreach ($detailsLevel as $key => $value) {
            $allLevels[$value['level']] = $value;
            if ($value['attribute_type'] != 'Business skills/Behavioural factors') {
                $attrData[$value['level']][$value['attribute_type']][$value['attribute_name']] = $value;
            } else {
                $attrData[$value['level']]['Business_skills'][$value['attribute_name']] = $value;
            }
        }
        $res['usersLevelData']['levelsData'] = array_values($allLevels);
        $res['usersLevelData']['attrData'] = $attrData;
        // attrData is keyed by LEVEL, and subject_ids holds an ID, so the view
        // cannot index one with the other. Hand it the resolved level.
        $res['usersLevelData']['selectedLevel'] =
            $this->responsibilityLevelsFor($editData['subject_ids'] ?? null)[0] ?? null;
        $res['usersLevelData']['allData'] = $detailsLevel;
        $res['usersJobroleComponent'] = DB::table('s_user_jobrole')->where('jobrole', $assignedJobrole->jobrole)->where('sub_institute_id', $sub_institute_id)->whereNull('deleted_at')->first();
        $res['levelOfResponsbility'] = SLevelResponsibility::groupBy('level')->get()->toArray();

        // echo "<pre>";print_r($res['skills']);exit;
        return is_mobile($type, 'user/edit_user', $res, 'view');
    }

    public function update(Request $request, $id)
    {
        // return $request;exit;

        if (! $request->monday) {
            $request->request->add(['monday' => 0]);
        }
        if (! $request->tuesday) {
            $request->request->add(['tuesday' => 0]);
        }
        if (! $request->wednesday) {
            $request->request->add(['wednesday' => 0]);
        }
        if (! $request->thursday) {
            $request->request->add(['thursday' => 0]);
        }
        if (! $request->friday) {
            $request->request->add(['friday' => 0]);
        }
        if (! $request->saturday) {
            $request->request->add(['saturday' => 0]);
        }
        if (! $request->sunday) {
            $request->request->add(['sunday' => 0]);
        }
        $sub_institute_id = $request->session()->get('sub_institute_id');
        $type = $request->input('type');

        // Validate email format
        $email = $request->input('email');
        if ($email) {
            // Check for valid email format
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $res['status_code'] = '0';
                $res['message'] = 'Invalid email address format';
                $res['data'] = null;

                return is_mobile($type, 'add_user.index', $res);
            }

            // Check for duplicate email (globally unique across the system) - exclude current user
            $existingUser = tbluserModel::where('email', $email)
                ->where('id', '!=', $id)
                ->first();

            if ($existingUser) {
                $res['status_code'] = '0';
                $res['message'] = 'Email address already exists';
                $res['data'] = null;

                return is_mobile($type, 'add_user.index', $res);
            }
        }
        // echo "<pre>";print_r($request->all());exit;
        $file_name = '';
        if ($request->hasFile('user_image')) {
            $file = $request->file('user_image');
            $originalname = $file->getClientOriginalName();
            $name = $request->get('user_name').date('YmdHis');
            $ext = File::extension($originalname);
            $file_name = $name.'.'.$ext;
            // $path = $file->storeAs('public/user/', $file_name);
            Storage::disk('digitalocean')->putFileAs('public/hp_user/', $file, $file_name, 'public');
        }
        if ($file_name != '') {
            $request->request->add(['image' => $file_name]); // add request
            $request->session()->put('image', $file_name);
        }

        $request->request->add(['id' => $id]); // add request
        $user_id = $id;

        $data = $this->updateData($request, $id);

        $res['status_code'] = '1';
        $res['message'] = 'User updated successfully';
        $res['data'] = $data;

        return is_mobile($type, 'add_user.index', $res);
    }

    public function destroy(Request $request, $id)
    {
        $user = [
            'status' => '0',
            'deleted_by' => session()->get('user_id'),
            'deleted_at' => now(),
        ];
        $type = $request->input('type');
        tbluserModel::where(['id' => $id])->update($user);

        $res['status_code'] = '1';
        $res['message'] = 'User deleted successfully';

        return is_mobile($type, 'add_user.index', $res);
    }

    public function deactiveUser(Request $request, $id)
    {
        $user = [
            'status' => '0',
            'deleted_by' => session()->get('user_id'),
            'deleted_at' => now(),
        ];
        $type = $request->input('type');
        tbluserModel::where(['id' => $id])->update($user);
        $res['status_code'] = '1';
        $res['message'] = 'User deleted successfully';

        return is_mobile($type, 'add_user.index', $res);
    }

    public function updateFcmToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'fcm_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status_code' => 0,
                'message' => $validator->errors()->first()
            ], 400);
        }

        // The device token decides which phone receives this user's push
        // notifications. Taken from the request, anyone could point any
        // colleague's notifications at their own handset - or silence them by
        // writing a dead token. It comes from the caller's own token now, and
        // the route requires one (see routes/api.php).
        $userId = $this->apiUserId($request);
        $fcmToken = $request->fcm_token;

        if (!$userId) {
            return response()->json([
                'status_code' => 0,
                'message' => 'Unable to identify the caller.'
            ], 401);
        }

        $updated = tbluserModel::where('id', $userId)->update([
            'fcm_token' => $fcmToken,
            'updated_at' => now()
        ]);

        if ($updated) {
            return response()->json([
                'status_code' => 1,
                'message' => 'FCM token updated successfully'
            ]);
        } else {
            return response()->json([
                'status_code' => 0,
                'message' => 'Failed to update FCM token'
            ], 500);
        }
    }

    public function teacherListAPI(Request $request)

    {

        // try {
        //           if (!$this->apiTokenIsValid()) {
        //               $response = array('status' => '2', 'message' => 'Token Auth Failed', 'data' => array());
        //               return response()->json($response, 401);
        //           }
        //       } catch (\Exception $e) {
        //           $response = array('status' => '2', 'message' => $e->getMessage(), 'data' => array());
        //           return response()->json($response, 401);
        //       }

        $type = $request->input('type');
        $sub_institute_id = $request->input('sub_institute_id');

        if ($sub_institute_id != '') {
            $data = DB::table('tbluser as u')
                ->join('tbluserprofilemaster as up', function ($join) {
                    $join->whereRaw("up.id = u.user_profile_id AND up.name = 'Teacher'");
                })
                ->selectRaw("u.id,concat_ws(' ',u.first_name,u.middle_name,u.last_name) as teacher_name,
					    u.email,u.mobile,u.user_profile_id,up.name as user_group")
                ->where('u.sub_institute_id', '=', $sub_institute_id)
                ->orderBy('u.id')
                ->get()->toArray();

            $res['status_code'] = 1;
            $res['message'] = 'Success';
            $res['data'] = $data;
        } else {
            $res['status_code'] = 0;
            $res['message'] = 'Parameter Missing';
        }

        return json_encode($res);
    }

    public function addUserDocument(Request $request, $id)
    {
        $type = $request->type;
        $document = $request->document;
        $doc_type = $request->document_type_id;
        $document_title = $request->document_title;
        $sub_institute_id = session()->get('sub_institute_id');
        if ($type == 'API') {
            $sub_institute_id = $request->sub_institute_id;
        }
        $filename = '';
        if ($request->hasFile('document')) {
            $file = $request->file('document');
            $originalname = $file->getClientOriginalName();
            $name = $id.date('YmdHis');
            $ext = File::extension($originalname);
            $file_name = $name.'.'.$ext;
            // $path = $file->storeAs('public/student_document/', $file_name);
            Storage::disk('digitalocean')->putFileAs('public/hp_staff_document/', $file, $file_name, 'public');
        }

        $data = [
            'user_id' => $id,
            'document_title' => $request->get('document_title'),
            'document_type_id' => $request->get('document_type_id'),
            'file_name' => $file_name,
            'sub_institute_id' => $sub_institute_id,
            'created_at' => now(),
        ];

        $insert = DB::table('staff_document')->insert($data);

        if ($insert) {
            $res['success'] = 1;
            $res['message'] = 'Document Added successfully';
        } else {
            $res['fail'] = 0;
            $res['message'] = 'Failed to Add Document';
        }

        return is_mobile($type, 'add_user.index', $res);
    }

    // show employee dtails for user profile
    public function show(Request $request, $id)
    {
        $type = $request->input('type');
        $userLevelOfResponsibility = [];

        if ($type == 'API') {
            $validator = Validator::make($request->all(), [
                'sub_institute_id' => 'required|numeric',
                'syear' => 'required|numeric',
                'type' => 'required',
            ]);

            if ($validator->fails()) {
                $res['status'] = '0';
                $res['message'] = $validator->messages()->first();

                return is_mobile($type, 'add_user.index', $res);
            }
            $sub_institute_id = $request->input('sub_institute_id');
            $syear = $request->input('syear');
        } else {
            $sub_institute_id = $request->session()->get('sub_institute_id');
            $syear = session()->get('syear');
        }

        /*
         * find($id) was unscoped, so any signed-in caller could read any
         * employee in any tenant by guessing an id - and ->toArray() on the
         * null it returns for a missing id was a fatal, not a 404.
         */
        $editRow = tbluserModel::where('id', $id)
            ->where('sub_institute_id', $sub_institute_id)
            ->first();

        if (! $editRow) {
            $res = ['status' => '0', 'message' => 'Employee not found'];

            return is_mobile($type, 'add_user.index', $res);
        }

        $editData = $editRow->toArray();
        $data = tbluserprofilemasterModel::where(['sub_institute_id' => $sub_institute_id])->get()->toArray();
        $dataCustomFields = tblcustomfieldsModel::where([
            'sub_institute_id' => $sub_institute_id,
            'status' => '1',
            'table_name' => 'tbluser',
            'user_type' => '',
        ])->get();

        $fieldsData = tblfields_dataModel::get()->toArray();
        $i = 0;
        $finalfieldsData = [];
        foreach ($fieldsData as $key => $value) {
            $finalfieldsData[$value['field_id']][$i]['display_text'] = $value['display_text'];
            $finalfieldsData[$value['field_id']][$i]['display_value'] = $value['display_value'];
            $i++;
        }

        if (count($finalfieldsData) > 0) {
            $res['data_fields'] = $finalfieldsData ?? [];
        }
        $res['documentTypeLists'] = DB::table('student_document_type')->where('status', 1)->where('user_type', 'staff')->get()->toArray();
        $res['documentLists'] = DB::table('staff_document')->select('staff_document.*', 'd.document_type')
            ->join('student_document_type as d', 'd.id', 'staff_document.document_type_id')
            ->where(['sub_institute_id' => $sub_institute_id, 'user_id' => $id])
            ->get()
            ->toArray();
        // end  20-04-24

        $departments = DB::table('hrms_departments')->where('sub_institute_id', $sub_institute_id)->where('status', 1)->get()->toArray();
        if (isset($editData['id'])) {
            $editData['userDepartment'] = $editData['userJobrole'] = '';
            if (isset($editData['department_id'])) {
                $editData['userDepartment'] = DB::table('hrms_departments')->where('sub_institute_id', $sub_institute_id)->where('status', 1)->where('id', $editData['department_id'])->value('department');
            }
            if (isset($editData['allocated_standards'])) {
                $editData['userJobrole'] = userJobroleModel::where('sub_institute_id', $sub_institute_id)->where('id', $editData['allocated_standards'])->value('jobrole');
            }
        }
        // 29-10-2024 salary data
        $payrollTypes = []; // DB::table('payroll_types')->where(['sub_institute_id'=>$sub_institute_id,'status'=>1])->get()->toArray();
        // get type id of salary deposite
        $SalaryDeposit = [];

        // get year wise salary data
        $SalaryStructure = []; // DB::table('employee_salary_structures')->where(['sub_institute_id'=>$sub_institute_id,'employee_id'=>$id])->orderBy('id','DESC')->get()->toArray();

        // 29-10-2024 end
        $res['departments'] = $departments;
        $res['job_titles'] = []; // HrmsJobTitle::where('sub_institute_id',$sub_institute_id)->get();
        $res['custom_fields'] = $dataCustomFields;
        $res['userLevelOfResponsibility'] = $userLevelOfResponsibility;
        $res['user_profiles'] = $data;
        // db::enableQueryLog();
        $res['contactDetails'] = [];
        // dd(db::getQueryLog($res['contactDetails']));
        // API callers get the editable subset; Blade keeps the whole row,
        // which edit_user.blade.php depends on (it prefills plain_password).
        $res['data'] = $type == 'API'
            ? array_intersect_key($editData, array_flip(self::API_DETAIL_COLUMNS))
            : $editData;
        // 10-01-2025 start supervisor rights
        $res['jobroleList'] = userJobroleModel::where('sub_institute_id', $sub_institute_id)->whereNull('deleted_at')->get()->toArray();
        $user_id = $id;
        $profileDetails = DB::table('tbluserprofilemaster')->where('id', $editData['user_profile_id'])->first();
        $user_profile_name = $profileDetails->name ?? '';
        // echo "<pre>";print_r($profileDetails);exit;

        $res['skills'] = $skills = skillJobroleMap::join('s_users_skills', 's_user_skill_jobrole.skill', '=', 's_users_skills.title')->whereNull('s_user_skill_jobrole.deleted_at')
            ->select('*', 's_users_skills.id as skill_id', 's_user_skill_jobrole.proficiency_level as proficiency_level')
            ->groupBy('s_user_skill_jobrole.id')
            ->get()->map(function ($item) {
                // Load knowledge and ability from the classification table
                $classificationItems = DB::table('s_skill_knowledge_ability')
                    ->where('skill_id', $item->skill_id)
                    ->where('proficiency_level', $item->proficiency_level) // or dynamic if needed
                    ->where('sub_institute_id', $item->sub_institute_id)
                    ->whereNull('deleted_at')
                    ->get()
                    ->groupBy('classification');

                $item->knowledge = $classificationItems->has('knowledge')
                    ? $classificationItems['knowledge']->pluck('classification_item')->toArray()
                    : [];

                $item->ability = $classificationItems->has('ability')
                    ? $classificationItems['ability']->pluck('classification_item')->toArray()
                    : [];

                return $item;
            });
        // echo "<pre>";print_r($res['skills']);exit;
        $res['completedCount'] = $completedCount = matrix::where('user_id', $user_id)->count();
        $res['totalSkills'] = $totalSkills = skillJobroleMap::where('jobrole', $assignedJobrole->jobrole)->whereNull('deleted_at')->
        where('sub_institute_id', $sub_institute_id)->count();
        $progress = $totalSkills > 0 ? round(($completedCount / $totalSkills) * 100) : 0;
        $res['progress'] = $totalSkills > 0 ? round(($completedCount / $totalSkills) * 100) : 0;
        $res['userRatedSkills'] = matrix::join('s_users_skills', 's_users_skills.id', '=', 's_skill_matrix.skill_id')
            ->where('s_skill_matrix.user_id', $id)
            ->get()->toArray();
        // echo "<pre>";print_r($res['userRatedSkills']);exit;
        $res['jobroleSkills'] = $res['jobroleTasks'] = [];
        // if (!in_array($user_profile_name, ['Admin', 'Supervisor'])) {

        $assignedJobrole = userJobroleModel::where('sub_institute_id', $sub_institute_id)->where('id', $editData['allocated_standards'])->whereNull('deleted_at')->first();
        // echo "<pre>";print_r($assignedJobrole);exit;

        if (isset($assignedJobrole)) {
            $alreadyRated = matrix::where('user_id', $user_id)->get()->toArray();
            $ratedIds = [];
            foreach ($alreadyRated as $rated) {
                $ratedIds[] = $rated['skill_id'] ?? 0;
            }
            $res['skills'] = skillJobroleMap::join('s_users_skills', 's_user_skill_jobrole.skill', '=', 's_users_skills.title')
                ->where('s_user_skill_jobrole.jobrole', $assignedJobrole->jobrole)
                ->whereNull('s_user_skill_jobrole.deleted_at')
                ->whereNotIn('s_users_skills.id', $ratedIds)
                ->select(
                    's_user_skill_jobrole.id as jobrole_skill_id',
                    's_user_skill_jobrole.jobrole',
                    's_user_skill_jobrole.skill',
                    's_users_skills.id as skill_id',
                    's_users_skills.title',
                    's_users_skills.category',
                    's_users_skills.sub_category',
                    's_users_skills.description',
                    's_user_skill_jobrole.proficiency_level as proficiency_level',
                )
                ->groupBy('s_user_skill_jobrole.id')
                ->get()->map(function ($item) {
                    // Load knowledge and ability from the classification table
                    $classificationItems = DB::table('s_skill_knowledge_ability')
                        ->where('skill_id', $item->skill_id)
                        ->where('proficiency_level', $item->proficiency_level) // or dynamic if needed
                        ->where('sub_institute_id', $item->sub_institute_id)
                        ->whereNull('deleted_at')
                        ->get()
                        ->groupBy('classification');

                    $item->knowledge = $classificationItems->has('knowledge')
                        ? $classificationItems['knowledge']->pluck('classification_item')->toArray()
                        : [];

                    $item->ability = $classificationItems->has('ability')
                        ? $classificationItems['ability']->pluck('classification_item')->toArray()
                        : [];

                    return $item;
                });

            $res['jobroleSkills'] = skillJobroleMap::join('s_users_skills', 's_user_skill_jobrole.skill', '=', 's_users_skills.title')
                ->where('s_user_skill_jobrole.jobrole', $assignedJobrole->jobrole)
                ->whereNull('s_user_skill_jobrole.deleted_at')
                ->select(
                    's_user_skill_jobrole.id as jobrole_skill_id',
                    's_user_skill_jobrole.jobrole',
                    's_user_skill_jobrole.skill',
                    's_users_skills.id as skill_id',
                    's_user_skill_jobrole.proficiency_level as proficiency_level',
                    's_users_skills.title',
                    's_users_skills.category',
                    's_users_skills.sub_category',
                    's_users_skills.description'
                )
                ->groupBy(['s_user_skill_jobrole.id', 's_users_skills.proficiency_level'])
                ->get()
                ->map(function ($item) {
                    // Load knowledge and ability from the classification table
                    $classificationItems = DB::table('s_skill_knowledge_ability')
                        ->where('skill_id', $item->skill_id)
                        ->where('proficiency_level', $item->proficiency_level) // or dynamic if needed
                        ->where('sub_institute_id', $item->sub_institute_id)
                        ->whereNull('deleted_at')
                        ->get()
                        ->groupBy('classification');

                    $item->knowledge = $classificationItems->has('knowledge')
                        ? $classificationItems['knowledge']->pluck('classification_item')->toArray()
                        : [];

                    $item->ability = $classificationItems->has('ability')
                        ? $classificationItems['ability']->pluck('classification_item')->toArray()
                        : [];

                    return $item;
                });

            $res['totalSkills'] = skillJobroleMap::where('jobrole', $assignedJobrole->jobrole)->count();
            // DB::enableQueryLog();
            // $res['jobroleTasks'] = DB::table('s_user_jobrole_task as a')
            //     ->join('s_user_skill_jobrole as b', 'b.jobrole', '=', 'a.jobrole')
            //     ->where('a.jobrole', $assignedJobrole->jobrole)
            //     ->whereNull('a.deleted_at')
            //     ->groupBy('task')
            //     ->get();

            $res['jobroleTasks'] = userJobroleTask::with('jobroleSkillModel')
                ->where('jobrole', $assignedJobrole->jobrole)
                ->whereNull('deleted_at')
                ->groupBy('task')
                ->get();
            // dd(DB::getQueryLog($res['jobroleTasks']));
        }

        // }
        // subject_ids holds ROW IDs, not level numbers - see
        // responsibilityLevelsFor(). Comparing it to `level` matched nothing for
        // 150 of the 187 employees who have a level set.
        $detailsLevel = SLevelResponsibility::whereIn('level', $this->responsibilityLevelsFor($editData['subject_ids'] ?? null))
            ->get()->toArray();
        $allLevels = $attrData = [];
        foreach ($detailsLevel as $key => $value) {
            $allLevels[$value['level']] = $value;
            if ($value['attribute_type'] != 'Business skills/Behavioural factors') {
                $attrData[$value['level']][$value['attribute_type']][$value['attribute_name']] = $value;
            } else {
                $attrData[$value['level']]['Business_skills'][$value['attribute_name']] = $value;
            }
        }
        $res['usersLevelData']['levelsData'] = array_values($allLevels);
        $res['usersLevelData']['attrData'] = $attrData;
        // attrData is keyed by LEVEL, and subject_ids holds an ID, so the view
        // cannot index one with the other. Hand it the resolved level.
        $res['usersLevelData']['selectedLevel'] =
            $this->responsibilityLevelsFor($editData['subject_ids'] ?? null)[0] ?? null;
        $res['usersLevelData']['allData'] = $detailsLevel;
        $res['levelOfResponsbility'] = SLevelResponsibility::groupBy('level')->get()->toArray();

        // echo "<pre>";print_r($res['skills']);exit;
        return is_mobile($type, 'user/edit_user', $res, 'view');
    }

    /**
     * `tbluser.subject_ids` -> the SFIA responsibility level(s) it refers to.
     *
     * ── WHAT THE COLUMN ACTUALLY HOLDS ──────────────────────────────────────
     *
     * ROW IDS from `s_level_responsibility`, not level numbers. Measured across
     * live, every non-empty value is one of:
     *
     *     1, 17, 33, 49, 65, 81, 97
     *
     * which are exactly MIN(id) per level - the ids that
     * `SLevelResponsibility::groupBy('level')` hands the dropdown. 150 of the
     * 187 employees who have a value are above 7, so they cannot be levels.
     *
     * ── THE BUG ────────────────────────────────────────────────────────────
     *
     * Two call sites queried `where('level', $subject_ids)`, comparing an id to
     * a level. For every employee above level 1 that matched NOTHING, so the
     * legacy Level of Responsibility page and the edit dropdown came up blank -
     * silently, because an empty result looks like "not set yet". A third site
     * (:566) already used `where('id', …)` and was right, which is how the two
     * readings sat side by side without anyone noticing.
     *
     * ── WHY PLURAL HANDLING ────────────────────────────────────────────────
     *
     * The column is TEXT named `subject_ids` and the add form posts
     * `subject_ids[]` as a MULTIPLE select, so a comma list is expressible.
     * Nothing on live actually stores one, but splitting costs nothing and
     * means a multi-valued row degrades to "all of them" rather than to
     * silence.
     *
     * @return list<int> the levels, possibly empty - never null, so whereIn()
     *                   stays a safe call.
     */
    private function responsibilityLevelsFor($subjectIds): array
    {
        $ids = array_values(array_filter(
            array_map('intval', preg_split('/\s*,\s*/', (string) $subjectIds, -1, PREG_SPLIT_NO_EMPTY) ?: []),
            static fn ($id) => $id > 0
        ));

        if ($ids === []) {
            return [];
        }

        return SLevelResponsibility::whereIn('id', $ids)
            ->orderBy('level')
            ->pluck('level')
            ->unique()
            ->values()
            ->map(static fn ($level) => (int) $level)
            ->all();
    }
}
