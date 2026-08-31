<?php

namespace App\Http\Controllers\front_desk;

use App\Http\Controllers\Controller;
use App\Http\Requests\TaskManagement\StoreTaskRequest;
use App\Models\front_desk\taskModel;
use App\Models\user\tbluserModel;
use App\Services\TaskManagement\TaskAuditService;
use App\Services\TaskManagement\TaskDependencyResolutionService;
use App\Services\TaskManagement\TaskStatusTransitionService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Concerns\ResolvesTaskDuty;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use function App\Helpers\is_mobile;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;

class taskController extends Controller
{
    use ResolvesTaskDuty;

    /**
     * G-SEC-29. THE REQUEST IS NO LONGER A TENANT SOURCE.
     *
     * Every `$request->...sub_institute_id` became `$this->apiTenantId($request)`,
     * which resolves the tenant FROM THE TOKEN. Confirmed by execution before the
     * change: a tenant-7 caller asking for tenant 3 received tenant 3's rows.
     *
     * THE SESSION READS ARE LEFT WHERE THEY ARE, DELIBERATELY. This controller
     * reads `session() ?? $request`, and `resolveApiIdentity()` is TOKEN-ONLY - it
     * does not consult the session. Replacing the whole expression would have
     * broken every Blade/web caller, who has a session and no token.
     *
     * So the precedence is now exactly G-SEC-27's ruling: SESSION, THEN TOKEN,
     * AND THE REQUEST NEVER. The server-side source stays first; the
     * caller-controlled one is gone.
     */
    use ResolvesApiIdentity;

    public function __construct(
        private readonly TaskAuditService $taskAudit,
        private readonly TaskStatusTransitionService $statusTransitions,
        private readonly TaskDependencyResolutionService $dependencyResolution
    ) {
    }

    /**
     * Ids created by this request, in creation order.
     *
     * One submit can produce several rows - a recurring task becomes one row
     * per date, a multi-user assignment one row per person - so the caller
     * needs the whole list to link them to a project or a dependency.
     * Controllers are resolved per request, so this never leaks between them.
     *
     * @var array<int, int>
     */
    private array $createdTaskIds = [];

    /**
     * Insert a task and record its creation.
     *
     * TaskReferenceService and TaskNotificationService used to be injected
     * here but neither class exists, so every create through this controller
     * died at constructor resolution. Nothing is lost by dropping them: the
     * "reference" every reader shows is the task id itself (see
     * TaskListController::resource and the Approvals card's TSK-{id}), and
     * each caller already invokes sendTaskNotification() for the assignee.
     */
    private function insertTaskWithReference(array $data, string|int|null $syear): int
    {
        /*
         * LINK THE WORK ITEM TO THE DUTY IT CAME FROM.
         *
         * Done here because this method is the single choke point every create
         * path in this controller funnels through — one row per date for a
         * recurring task, one per person for a multi-user assignment. Resolving
         * at each call site would mean the rule drifting between them.
         *
         * Without this the task cannot reach its execution model or its ESO,
         * and the employee's "How to do this" panel has nothing to show.
         */
        $tenantId = (int) ($data['sub_institute_id'] ?? 0);

        if (array_key_exists('jobrole_task_id', $data)) {
            /*
             * THE CALLER SAID WHICH DUTY THIS IS. VERIFY IT IS THEIRS.
             *
             * store() builds $data from $request->except([...]) — an EXCLUSION
             * list — into a model with $guarded = []. So this key arrives
             * straight from the request body, and without this check a caller
             * could post any other organisation's job-role task id and file
             * their task against it. That would leak the other tenant's
             * procedure into this tenant's task detail, because the ESO lookup
             * follows this id and nothing downstream re-checks the tenant.
             *
             * An id that does not belong here is discarded rather than refused:
             * the task itself is legitimate work and should still be created.
             * It falls through to text resolution below, exactly as if the
             * caller had never named one.
             */
            $claimed = (int) ($data['jobrole_task_id'] ?? 0);
            $data['jobrole_task_id'] = null;

            if ($claimed > 0 && $tenantId > 0) {
                $ownsIt = DB::table('s_user_jobrole_task')
                    ->where('id', $claimed)
                    ->where('sub_institute_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->exists();

                if ($ownsIt) {
                    $data['jobrole_task_id'] = $claimed;
                } else {
                    \Log::warning('Task create named a job-role task it does not own', [
                        'tenant'          => $tenantId,
                        'jobrole_task_id' => $claimed,
                    ]);
                }
            }
        }

        // Only when the caller did not name one, or named one that was not
        // theirs. Text matching is the fallback now, not the mechanism.
        if (($data['jobrole_task_id'] ?? null) === null) {
            $data['jobrole_task_id'] = $this->resolveTaskDuty(
                $tenantId,
                $data['task_title'] ?? null
            );
        }

        $taskId = (int) taskModel::insertGetId($data);
        $this->createdTaskIds[] = $taskId;
        $this->taskAudit->taskCreated($taskId, isset($data['created_by']) ? (int) $data['created_by'] : null);

        return $taskId;
    }

    /**
     * Send FCM notification to user
     */
    private function sendTaskNotification($assigneeId, $taskTitle, $assignerName, $taskId)
    {
        \Log::info("FCM Notification: Starting notification process for assignee ID: $assigneeId, task ID: $taskId");

        $assignee = tbluserModel::find($assigneeId);
        if (!$assignee) {
            \Log::error("FCM Notification: Assignee user not found for ID: $assigneeId");
            return;
        }

        $fcmToken = $assignee->fcm_token;
        \Log::info("FCM Notification: Retrieved FCM token for user $assigneeId: " . (empty($fcmToken) ? 'NULL' : substr($fcmToken, 0, 10) . '...'));

        if ($fcmToken) {
            try {
                // Check if Firebase service account file exists
                $firebaseKeyPath = storage_path('app/firebase/gapstogrowth-ba988-firebase-adminsdk-fbsvc.json');
                if (!file_exists($firebaseKeyPath)) {
                    \Log::error("FCM Notification: Firebase service account file not found at: $firebaseKeyPath");
                    return;
                }
                \Log::info("FCM Notification: Firebase service account file found and loaded");

                // Initialize Firebase with service account
                $factory = (new Factory)->withServiceAccount($firebaseKeyPath);
                $messaging = $factory->createMessaging();
                \Log::info("FCM Notification: Firebase messaging initialized successfully");

                // Create the message
                $message = CloudMessage::withTarget('token', $fcmToken)
                    ->withNotification([
                        'title' => 'New Task Assigned',
                        'body' => 'You have been assigned: ' . $taskTitle
                    ])
                    ->withData([
                        'type' => 'task_assigned',
                        'task_title' => $taskTitle,
                        'assigned_by' => $assignerName,
                        'task_id' => $taskId,
                        'employee_id' => $assigneeId
                    ]);

                \Log::info("FCM Notification: Message payload prepared for user $assigneeId", [
                    'title' => 'New Task Assigned',
                    'body' => 'You have been assigned: ' . $taskTitle,
                    'data' => [
                        'type' => 'task_assigned',
                        'task_title' => $taskTitle,
                        'assigned_by' => $assignerName,
                        'task_id' => $taskId,
                        'employee_id' => $assigneeId
                    ]
                ]);

                // Send the message
                $result = $messaging->send($message);

                \Log::info("FCM notification sent successfully to user $assigneeId for task $taskId. Message ID: " . $result);

            } catch (\Kreait\Firebase\Exception\MessagingException $e) {
                \Log::error("FCM Messaging Exception for user $assigneeId, task $taskId: " . $e->getMessage(), [
                    'exception_code' => $e->getCode(),
                    'fcm_token_prefix' => substr($fcmToken, 0, 10)
                ]);
            } catch (\Kreait\Firebase\Exception\FirebaseException $e) {
                \Log::error("FCM Firebase Exception for user $assigneeId, task $taskId: " . $e->getMessage(), [
                    'exception_code' => $e->getCode()
                ]);
            } catch (\Exception $e) {
                \Log::error("Exception sending FCM notification to user $assigneeId, task $taskId: " . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
        } else {
            \Log::info("No FCM token found for user $assigneeId - user may not have logged in recently or FCM token not updated");
        }
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(Request $request)
    {
        $type = $request->input("type");
        $from_date = $request->input("from_date");
        $to_date = $request->input("to_date");
        $sub_institute_id = $request->session()->get("sub_institute_id");
        $syear = $request->session()->get("syear");
        $user_profile_name = $request->session()->get("user_profile_name");
        $user_id = $request->session()->get("user_id");
        $taskType = $request->taskType;

        // Token validation for API requests
        if ($type == "API") {
            $token = $request->bearerToken() ?: $request->input('token');
            
            // If token is provided, validate it
            if ($token) {
                $accessToken = PersonalAccessToken::findToken($token);
                if (!$accessToken) {
                    return response()->json([
                        'status_code' => 0,
                        'message' => 'Invalid token'
                    ], 401);
                }
            }
            
            $sub_institute_id = $this->apiTenantId($request);
            $syear = $request->get("syear");
            $user_profile_name = $request->get("user_profile_name");
            $user_id = $request->get("user_id");
        }

        // Validate required parameters for API
        if ($type == "API" && (empty($sub_institute_id) || empty($syear))) {
            return response()->json([
                'status_code' => 0,
                'message' => 'Missing required parameters: sub_institute_id and syear are required'
            ], 400);
        }

        // Check if data exists for given syear, if not use current year
        if ($type == "API") {
            $check_syear = DB::table('task')
                ->where('sub_institute_id', $sub_institute_id)
                ->where('SYEAR', $syear)
                ->count();
            
            // If no data for given syear, try current year
            if ($check_syear == 0) {
                $current_year = date('Y');
                $check_current = DB::table('task')
                    ->where('sub_institute_id', $sub_institute_id)
                    ->where('SYEAR', $current_year)
                    ->count();
                
                if ($check_current > 0) {
                    $syear = $current_year;
                }
            }
        }

        // Build query - use simple LEFT JOINs to get all tasks
        $data = DB::table("task as t")
            ->leftJoin('tbluser as u', 't.TASK_ALLOCATED', '=', 'u.id')
            ->leftJoin('tbluser as u1', 't.CREATED_BY', '=', 'u1.id')
            ->leftJoin('tbluser as u2', 't.TASK_ALLOCATED_TO', '=', 'u2.id')
            ->leftJoin('hrms_departments as hd', 'hd.id', '=', 'u2.department_id')
            ->leftJoin('s_user_jobrole as uj', 'uj.id', '=', 'u2.allocated_standards')
            ->leftJoin('tbluser as u3', 't.approved_by', '=', 'u3.id')
            ->selectRaw("t.*, 
                COALESCE(CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name), 'User Not Found/Inactive') AS manageby, 
                COALESCE(CONCAT_WS(' ',u1.first_name,u1.middle_name,u1.last_name), 'Creator Not Found/Inactive') AS ALLOCATOR,
                COALESCE(CONCAT_WS(' ',u2.first_name,u2.middle_name,u2.last_name), 'Assignee Not Found/Inactive') AS ALLOCATED_TO,
                COALESCE(CONCAT_WS(' ',u3.first_name,u3.middle_name,u3.last_name), 'Not Approved') AS approved_by,
                hd.department, uj.jobrole")
            ->where("t.SYEAR", "=", $syear)
            ->where("t.sub_institute_id", "=", $sub_institute_id);

        // Apply date filters
        if (isset($from_date)) {
            $data = $data->where('t.TASK_DATE', '>=', $from_date);
            $res['from_date'] = $from_date;
        }

        if (isset($to_date)) {
            $data = $data->where('t.TASK_DATE', '<=', $to_date);
            $res['to_date'] = $to_date;
        }
        
        if (isset($taskType)) {
            $data = $data->where('t.task_type', $taskType);
            $res['taskType'] = $taskType;
        }

        // Only apply user filter for non-admin users (when not using API)
        if (strtoupper($user_profile_name) != 'ADMIN') {
            $data = $data->where(function($query) use ($user_id) {
                $query->where('t.TASK_ALLOCATED_TO', $user_id)
                    ->orWhere('t.TASK_ALLOCATED', $user_id);
            });
        }

        $data = $data->orderBy('t.TASK_DATE', 'desc');
        $data = $data->get()->toArray();

        $res['checkList'] = DB::table('task')
            ->selectRaw('*,' . $user_id . ' as user_id')
            ->whereRaw("(TASK_ALLOCATED_TO = '" . $user_id . "' OR TASK_ALLOCATED = '" . $user_id . "')")
            ->where('task_type', '=', 'High')
            ->where('TASK_DATE', date('Y-m-d'))
            ->get()->toArray();

        $res['status_code'] = 1;
        $res['message'] = "Success";
        $res['data'] = $data;

        return is_mobile($type, "front_desk.show_task", $res, "view");
    }


    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create(Request $request)
    {
        $type = $request->input("type");
        $sub_institute_id = $request->session()->get("sub_institute_id");
        $syear = $request->session()->get("syear");
        $user_id = $request->session()->get("user_id");

        $users = tbluserModel::where(["sub_institute_id" => $sub_institute_id, 'status' => 1])
            ->whereRaw("id != '" . $user_id . "'")
            ->where('status', 1)
            ->get()
            ->toArray();

        $res['status_code'] = 1;
        $res['message'] = "Success";
        $res['userList'] = $users;
        $res['skillLists'] = DB::table('tblemp_skills')->whereIn('sub_institute_id', [0, $sub_institute_id])->get()->toArray();

        return is_mobile($type, "front_desk.add_task", $res, "view");
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  Request  $request
     * @return Response
     */
    // public function store(Request $request)
    // {
    //     // echo "<pre>";print_r($request->all());exit;
    //     $type = $request->input("type");
    //     if($type=="API"){
    //         $sub_institute_id = $this->apiTenantId($request);
    //         $syear = $request->syear;
    //         $term_id = 0;
    //         $user_id = $request->user_id;
    //         $manageby = $request->input("manageby");
    //     }else{
    //         $sub_institute_id = $request->session()->get("sub_institute_id");
    //         $syear = $request->session()->get("syear");
    //         $term_id = $request->session()->get("term_id");
    //         $user_id = $request->session()->get("user_id");
    //         $manageby = $request->session()->get("user_id");
    //     }

    //     $TASK_ALLOCATED_TO = $request->input("TASK_ALLOCATED_TO");
    //     $KRA = $request->input("KRA");
    //     $KPA = $request->input("KPA");
    //     $observation_point = $request->input("observation_point");
    //     $task_type = $request->input("selType");
    //     $required_skill = isset($request->skills) ? implode(',',$request->skills) : '';
    //     // store skills
    //     $dates = $this->getDatesWithoutSundays();
    //     // echo "<pre>";print_r($required_skill);exit;
    //     // if($required_skill!=''){
    //     //     $explodeSkills = explode(',',$required_skill);
    //     //     foreach ($explodeSkills as $id => $skillname) {
    //     //        $checkSkillset = DB::table('tblemp_skills')->where('skills',$skillname)->whereIn('sub_institute_id',[0,$sub_institute_id])->get()->toArray();
    //     //        if(empty($checkSkillset)){
    //     //         DB::table('tblemp_skills')->insert(['sub_institute_id'=>$sub_institute_id,'skills'=>$skillname,'created_at'=>now()]);
    //     //        }
    //     //     }
    //     // }
    //     $data = $request->except(['_method', '_token', 'submit', 'TASK_ATTACHMENT','formName','selDepartment','selSubDepartment','selType','add','type','syear','sub_institute_id','user_id','manageby','KRA','KPA','skills','observation_point','TASK_DATE']);

    //     $file_name = $ext = $file_size = "";
    //     if ($request->hasFile('TASK_ATTACHMENT')) {
    //         $file = $request->file('TASK_ATTACHMENT');
    //         $originalname = $file->getClientOriginalName();
    //         $file_size = $file->getSize();
    //         $name = "task_".date('YmdHis');
    //         $ext = File::extension($originalname);
    //         $file_name = $name.'.'.$ext;
    //         $path = $file->storeAs('public/front_desk/', $file_name);
    //     }

    //     foreach ($TASK_ALLOCATED_TO as $key => $value) {
    //         $data['KRA'] = $KRA;
    //         $data['KPA'] = $KPA;
    //         $data['observation_point'] = $observation_point;
    //         $data['task_type'] = $task_type;

    //         $data['SYEAR'] = $syear;
    //         // $data['MARKING_PERIOD_ID'] = $term_id;
    //         $data['CREATED_BY'] = $user_id;
    //         $data['TASK_ALLOCATED'] = $manageby;
    //         $data['TASK_ALLOCATED_TO'] = $value;
    //         $data['STATUS'] ='PENDING';
    //         $data['required_skills'] = $required_skill;
    //         $data['CREATED_IP_ADDRESS'] = $_SERVER['REMOTE_ADDR'];
    //         $data['created_at'] = date('Y-m-d H:i:s');
    //         $data['sub_institute_id'] = $sub_institute_id;

    //         if ($file_name != '') {
    //             $data['TASK_ATTACHMENT'] = $file_name;
    //             $data['FILE_SIZE'] = $file_size;
    //             $data['FILE_TYPE'] = $ext;
    //         }
    //         if($task_type=="High"){
    //             foreach ($dates as $k => $date) {
    //                 $data['TASK_DATE']=$date;
    //                 taskModel::insert($data);
    //             }
    //         }else{
    //             $data['TASK_DATE'] = $request->get('TASK_DATE');
    //             taskModel::insert($data);
    //         }
    //     }

    //     $res['status_code'] = "1";
    //     $res['message'] = "Added successfully";

    //     return is_mobile($type, "task.index", $res);
    // }

    public function store(StoreTaskRequest $request)
    {
        try {
            $type = $request->type;

            // if ($type == "API") {
            $sub_institute_id = $this->apiTenantId($request);
            $syear = $request->syear;
            $term_id = 0;
            $user_id = $request->user_id;
            $manageby = $request->input("manageby");
            // } else {
            //     $sub_institute_id = $request->session()->get("sub_institute_id");
            //     $syear = $request->session()->get("syear");
            //     $term_id = $request->session()->get("term_id");
            //     $user_id = $request->session()->get("user_id");
            //     $manageby = $user_id;
            // }

            // Double-submit guard. A retried create - impatient second click,
            // a browser retry after a slow response - used to produce a second
            // copy of the whole recurring series. Callers that send a key get
            // the first attempt's task back instead; callers that do not are
            // unaffected.
            $idempotencyKey = trim((string) $request->input('idempotency_key'));
            if ($idempotencyKey !== '') {
                $existing = DB::table('task_management_idempotency_keys')
                    ->where('sub_institute_id', $sub_institute_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    // status_code 1 - from the caller's point of view the task
                    // it asked for exists, which is what success means here.
                    return response()->json([
                        'status_code' => "1",
                        'message' => 'Task already created for this request.',
                        'task_id' => (string) $existing->task_id,
                        'task_ids' => [(string) $existing->task_id],
                        'replayed' => true,
                    ], 200);
                }
            }

            $dates = $this->getDatesWithoutSundays("High", null, 1, $request->repeat_until);
            $task_type = $request->input('selType', ''); // fallback if not present

            // Prepare file upload
            $file_name = $ext = $file_size = "";
            if ($request->hasFile('TASK_ATTACHMENT')) {
                $file = $request->file('TASK_ATTACHMENT');
                $originalname = $file->getClientOriginalName();
                $file_size = $file->getSize();
                $name = "task_" . date('YmdHis');
                $ext = File::extension($originalname);
                $file_name = $name . '.' . $ext;
                // $path = $file->storeAs('public/hp_task/', $file_name);
                Storage::disk('digitalocean')->putFileAs('public/hp_task/', $file, $file_name, 'public');
            }

            // Common task data
            $baseData = $request->except([
                '_method',
                '_token',
                'token',
                'org_type',
                'formType',
                'submit',
                'TASK_ATTACHMENT',
                'formName',
                'selDepartment',
                'selSubDepartment',
                'selType',
                'add',
                'type',
                'syear',
                'sub_institute_id',
                'user_id',
                'manageby',
                'skills',
                'observation_point',
                'TASK_DATE',
                'employee_id',
                'job_role',
                'repeat_until',
                // Request metadata, not a task column.
                'idempotency_key',
            ]);

            $extraData = [
                'KRA' => $request->input("KRA"),
                'KPA' => $request->input("KPA"),
                'observation_point' => $request->input("observation_point"),
                'task_type' => $task_type,
                'SYEAR' => $syear,
                'TASK_ALLOCATED' => $manageby,
                'STATUS' => 'PENDING',
                'CREATED_IP_ADDRESS' => $request->ip(),
                'created_at' => now(),
                'sub_institute_id' => $sub_institute_id,
            ];

            if ($file_name) {
                $extraData['TASK_ATTACHMENT'] = $file_name;
                $extraData['FILE_SIZE'] = $file_size;
                $extraData['FILE_TYPE'] = $ext;
            }


            if ($request->formType == "single") {
                // 'required_skills' => $request->has('skills') ? implode(',', $request->skills) : '',

                $extraData['TASK_ALLOCATED_TO'] = $request->input("TASK_ALLOCATED_TO");
                $extraData['required_skills'] = $request->input("skills");
                $extraData['skill_id'] = $request->input("skill_id");

                if ($task_type == "High") {
                    $dates = $this->getDatesWithoutSundays("High", $request->task_date, (int)$request->repeat_days, $request->repeat_until);
                    foreach ($dates as $date) {
                        $data = array_merge($baseData, $extraData, ['TASK_DATE' => $date]);
                        $data['created_by'] = $user_id;
                        $taskId = $this->insertTaskWithReference($data, $syear);
                        $this->sendTaskNotification($request->input("TASK_ALLOCATED_TO"), $data['task_title'], tbluserModel::where('id', $user_id)->value('first_name'), $taskId);
                    }
                } else if ($task_type == "Medium") {
                    $dates = $this->getDatesWithoutSundays('Medium', $request->task_date, (int)$request->repeat_days, $request->repeat_until);
                    foreach ($dates as $date) {
                        $data = array_merge($baseData, $extraData, ['TASK_DATE' => $date]);
                        $data['created_by'] = $user_id;
                        $taskId = $this->insertTaskWithReference($data, $syear);
                        $this->sendTaskNotification($request->input("TASK_ALLOCATED_TO"), $data['task_title'], tbluserModel::where('id', $user_id)->value('first_name'), $taskId);
                    }
                } else if ($task_type == "Low") {
                    $dates = $this->getDatesWithoutSundays('Low', $request->task_date, (int)$request->repeat_days, $request->repeat_until);
                    foreach ($dates as $date) {
                        $data = array_merge($baseData, $extraData, ['TASK_DATE' => $date]);
                        $data['created_by'] = $user_id;
                        $taskId = $this->insertTaskWithReference($data, $syear);
                        $this->sendTaskNotification($request->input("TASK_ALLOCATED_TO"), $data['task_title'], tbluserModel::where('id', $user_id)->value('first_name'), $taskId);
                    }
                } else {
                    $data = array_merge($baseData, $extraData, ['TASK_DATE' => $request->repeat_until ?? now()]);
                    $data['created_by'] = $user_id;
                    $taskId = $this->insertTaskWithReference($data, $syear);
                    $this->sendTaskNotification($request->input("TASK_ALLOCATED_TO"), $data['task_title'], tbluserModel::where('id', $user_id)->value('first_name'), $taskId);
                }
            } elseif ($request->formType == "multiUser" && !empty($request->TASK_ALLOCATED_TO)) {
                // Handle array of allocated users - create one task per user, no repetition
                $allocatedUsers = $request->TASK_ALLOCATED_TO ? explode(',', $request->TASK_ALLOCATED_TO) : [];

                foreach ($allocatedUsers as $key => $allocatedUser) {
                    if (!empty($allocatedUser)) {
                        $extraData['TASK_ALLOCATED_TO'] = $allocatedUser;
                        $extraData['required_skills'] = $request->input("skills");
                        $extraData['skill_id'] = $request->input("skill_id");
                        $data = array_merge($baseData, $extraData, ['TASK_DATE' => $request->repeat_until ?? now()]);
                        $data['created_by'] = $user_id;
                        $taskId = $this->insertTaskWithReference($data, $syear);
                        $this->sendTaskNotification($allocatedUser, $data['task_title'], tbluserModel::where('id', $user_id)->value('first_name'), $taskId);
                    }
                }
            }
            else if($request->formType=="BulkTask" && $request->TASK_ALLOCATED_TO && $request->task_details){
               $insertCount = 0;

                // Convert TASK_ALLOCATED_TO into array
                $allocatedUsers = $request->TASK_ALLOCATED_TO ? explode(',', $request->TASK_ALLOCATED_TO) : [];

                foreach ($allocatedUsers as $allocatedUser) {
                    if (!empty($allocatedUser)) {

                        // Decode task details from request
                        $taskDetails = $request->has('task_details') ? json_decode($request->task_details, true) : [];

                        foreach ($taskDetails as $taskValue) {

                            // Base request data except unwanted fields
                            $baseData = $request->except([
                                '_method', '_token', 'token', 'org_type', 'formType',
                                'submit', 'formName', 'repeat_until', 'task_details',
                                'TASK_ALLOCATED_TO','type','user_id'
                            ]);

                            // Task specific data
                            $taskData = [
                                'task_title'       => $taskValue['task_title'] ?? '',
                                'task_description' => $taskValue['task_description'] ?? '',
                                'repeat_days'      => $taskValue['repeat_days'] ?? 0,
                                'task_allocated'   => $taskValue['task_allocated'] ?? 0,
                                'task_type'        => $taskValue['task_type'] ?? 'Medium',
                                'TASK_DATE'        => $taskValue['TASK_DATE'] ?? date('Y-m-d'),
                                'TASK_ALLOCATED_TO'=> $allocatedUser,
                            ];

                            $task_type = $taskValue['task_type'] ?? 'Medium';
                            $task_date = $taskValue['TASK_DATE'] ?? now()->format('Y-m-d');

                            // Get dates based on task type
                            $repeat_until = $taskValue['repeat_until'] ?? null;
                            $dates = $this->getDatesWithoutSundays($task_type, $task_date, (int)$taskValue['repeat_days'] ?? 1, $repeat_until);
                          
                            // return $dates;
                            if(!empty($dates)){
                                foreach ($dates as $date) {
                                $data = array_merge($baseData, $taskData, [
                                    'TASK_DATE'  => $date,
                                    'created_by' => $user_id,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                    ]);

                                    // Insert using Eloquent create()
                                    $insert = taskModel::create($data);

                                    if ($insert) {
                                        $this->createdTaskIds[] = (int) $insert->id;
                                        $insertCount++;
                                        $this->sendTaskNotification($allocatedUser, $taskData['task_title'], tbluserModel::where('id', $user_id)->value('first_name'), $insert->id);
                                    }
                                }
                            }else{
                            $data = array_merge($baseData, $taskData, [
                                    'TASK_DATE'  => $task_date,
                                    'created_by' => $user_id,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);

                                // Insert using Eloquent create()
                                $insert = taskModel::create($data);

                                if ($insert) {
                                    $this->createdTaskIds[] = (int) $insert->id;
                                    $insertCount++;
                                    $this->sendTaskNotification($allocatedUser, $taskData['task_title'], tbluserModel::where('id', $user_id)->value('first_name'), $insert->id);
                                }
                            }
                            
                        }
                    }
                }

                // Response
                $res['status_code'] = 0;
                $res['message']     = "Failed to Add";

                if ($insertCount > 0) {
                    $res['status_code'] = 1;
                    $res['message']     = "Added successfully";
                }

            }
             else {
                foreach ($request->input("TASK_ALLOCATED_TO", []) as $value) {
                    $extraData['TASK_ALLOCATED_TO'] = $value;
                    // 'required_skills' => $request->has('skills') ? implode(',', $request->skills) : '',
                    $extraData['required_skills'] = $request->has('skills') ? implode(',', $request->skills) : '';
                    $extraData['skill_id'] = $request->input("skill_id");
                    if ($task_type == "High") {
                        $dates = $this->getDatesWithoutSundays("High", $request->task_date, (int)$request->repeat_days, $request->repeat_until);
                        foreach ($dates as $date) {
                            $data = array_merge($baseData, $extraData, ['TASK_DATE' => $date]);
                            $data['created_by'] = $user_id;
                            $taskId = $this->insertTaskWithReference($data, $syear);
                            $this->sendTaskNotification($value, $data['task_title'], tbluserModel::where('id', $user_id)->value('first_name'), $taskId);
                        }
                    } else {
                        $data = array_merge($baseData, $extraData, ['TASK_DATE' => $request->repeat_until ?? $request->get('TASK_DATE')]);
                        $data['created_by'] = $user_id;
                        $taskId = $this->insertTaskWithReference($data, $syear);
                        $this->sendTaskNotification($value, $data['task_title'], tbluserModel::where('id', $user_id)->value('first_name'), $taskId);
                    }
                }
            }

            // Remember the key so a retry replays this create instead of
            // repeating it. Best-effort: a bookkeeping failure must not turn a
            // successful create into an error.
            if ($idempotencyKey !== '') {
                try {
                    DB::table('task_management_idempotency_keys')->insert([
                        'sub_institute_id' => $sub_institute_id,
                        'idempotency_key' => $idempotencyKey,
                        'task_id' => (int) ($taskId ?? 0),
                        'created_at' => now(),
                    ]);
                } catch (\Throwable $exception) {
                    // A duplicate key here means two requests raced; the second
                    // one still created a task, which is the pre-existing
                    // behaviour, so there is nothing to undo.
                }
            }

            // The created ids, so the caller can link the new work to a
            // project, a workstream or a dependency. Without them the client
            // has no handle on what it just created. `task_id` is the first
            // one, kept for callers that only ever expect a single task.
            $res =  [
                'status_code' => "1",
                'message' => "Added successfully",
                'task_id' => $this->createdTaskIds[0] ?? null,
                'task_ids' => array_map('strval', $this->createdTaskIds),
            ];

            return response()->json($res);
        } catch (\Exception $e) {
            return response()->json([
                'status_code' => 0,
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ], 500);
        }
    }


    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return void
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return Application|Factory|View
     */
    public function edit(Request $request, $id)
    {
        $sub_institute_id = $request->session()->get("sub_institute_id");
        $user_id = $request->session()->get("user_id");
        $syear = $request->session()->get("syear");
        $user_profile_name = $request->session()->get("user_profile_name");

        $result = DB::table("task as t")
            ->join('tbluser as u', function ($join) use ($sub_institute_id) {
                $join->whereRaw("t.TASK_ALLOCATED = u.id AND u.sub_institute_id = '" . $sub_institute_id . "'")->where('u.status', 1); // 23-04-24 by uma
            })
            ->join('tbluser as u1', function ($join) use ($sub_institute_id) {
                $join->whereRaw("t.CREATED_BY = u1.id AND u1.sub_institute_id = '" . $sub_institute_id . "'")->where('u1.status', 1); // 23-04-24 by uma
            })
            ->join('tbluser as u2', function ($join) use ($sub_institute_id) {
                $join->whereRaw("t.TASK_ALLOCATED_TO = u2.id AND u2.sub_institute_id = '" . $sub_institute_id . "'")->where('u2.status', 1); // 23-04-24 by uma
            })
            ->selectRaw("t.*, CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name) AS manageby, 
            CONCAT_WS(' ',u1.first_name,u1.middle_name,u1.last_name) AS ALLOCATOR,
            CONCAT_WS(' ',u2.first_name,u2.middle_name,u2.last_name) AS ALLOCATED_TO")
            ->where("t.ID", "=", $id)
            ->get()->toArray();

        $result = array_map(function ($value) {
            return (array) $value;
        }, $result);

        $editData = $result[0];

        $dataResult = ['PENDING', 'IN PROGRESS', 'ON HOLD', 'COMPLETED']; // DB::table("complaint_status")
        // ->where("TYPE", "=", 'TASK')
        // ->get()->toarray();

        // $dataResult = array_map(function ($value) {
        //     return (array) $value;
        // }, $dataResult);

        $taskStatus = $dataResult;
        $editData['skillLists'] = []; // DB::table('tblemp_skills')->whereIn('sub_institute_id',[0,$sub_institute_id])->get()->toArray();

        return view('front_desk/edit_task', ['data' => $editData, 'taskStatus' => $taskStatus]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  Request  $request
     * @param  int  $id
     * @return Response
     */
    public function update(Request $request, $id)
    {
        $before = $this->taskAudit->taskSnapshot((int) $id);
        // return $request;
        $type = $request->input("type");
        if ($type == "API") {
            $sub_institute_id = $this->apiTenantId($request);
            $syear = $request->syear;
            $term_id = 0;
            $user_id = $request->user_id;
            $manageby = $request->input("manageby");
        } else {
            $sub_institute_id = $request->session()->get("sub_institute_id");
            $syear = $request->session()->get("syear");
            $term_id = $request->session()->get("term_id");
            $user_id = $request->session()->get("user_id");
            $manageby = $request->session()->get("user_id");
        }

        $TASK_ALLOCATED_TO = $request->input("TASK_ALLOCATED_TO");
        $KRA = $request->input("kra");
        $KPA = $request->input("kpa");
        $task_type = $request->input("selType");
        $required_skill = $request->skills ?? '';
        $observation_point = $request->observation_point;
        // store skills
        if ($request->has('formType') && $request->formType == "approveStatus") {
            $data = $request->except([
                '_method',
                '_token',
                'submit',
                'formType',
                'update',
                'token',
                'user_id',
                'add',
                'type',
                'syear',
                'sub_institute_id',
                'user_id',
                'manageby',
                'KRA',
                'KPA',
                'skills',
                'ALLOCATOR',
                'ALLOCATED_TO',
                'method_field',
                'repeat_days',
                'department',
                'jobrole'
            ]);
            foreach ($data as $key => $value) {
                if ($value === 'null' || $value === '') {
                    $data[$key] = null;
                }
            }

            $file_name = $ext = $file_size = "";
            if ($request->hasFile('TASK_ATTACHMENT')) {
                $file = $request->file('TASK_ATTACHMENT');
                $originalname = $file->getClientOriginalName();
                $file_size = $file->getSize();
                $name = "task_" . date('YmdHis');
                $ext = File::extension($originalname);
                $file_name = $name . '.' . $ext;
                $path = $file->storeAs('public/hp_task/', $file_name);

                $data['TASK_ATTACHMENT'] = $file_name;
                $data['FILE_SIZE'] = $file_size;
                $data['FILE_TYPE'] = $ext;
            }
            $data['updated_by'] = $user_id;
            $data['updated_at'] = now();

            if ($request->has('approve_status') && $request->approve_status !== '') {
                $data['approved_by'] = $user_id;
                $data['approved_on'] = now();
            } else {
                $data['approved_on'] = null;
            }
        } else {
            /*
             * `token` IS EXCLUDED NOW, AND THAT IS THE WHOLE TASK-EDIT BUG.
             *
             * This list is a BLACKLIST feeding straight into
             * `taskModel::update($data)` on a model with `$guarded = []`, so
             * anything not named here is written as a column. The API caller
             * passes its bearer in the QUERY STRING (`?type=API&token=...`) and
             * `$request->except()` covers query input as well as the body — so
             * `token` reached the UPDATE and MySQL rejected the statement:
             *
             *   SQLSTATE[42S22]: Unknown column 'token' in 'field list'
             *
             * Every edit through this route 500'd. It is the only task-edit
             * path wired anywhere in the app, which is why editing a task
             * appeared to be missing entirely rather than broken.
             *
             * ⚠ A blacklist here will leak again the next time a caller sends a
             * new parameter. The durable fix is to build `$data` from an
             * explicit allow-list of task columns; that is a wider change to a
             * method the old frontend also posts to, so it is noted rather than
             * done in the same edit as the outage fix.
             */
            $data = $request->except(['_method', '_token', 'token', 'submit', 'TASK_ATTACHMENT', 'formName', 'selDepartment', 'selSubDepartment', 'selType', 'task_date', 'add', 'type', 'syear', 'sub_institute_id', 'user_id', 'manageby', 'KRA', 'KPA', 'skills','department','jobrole']);

            $data['kra'] = $KRA;
            $data['TASK_DATE'] = Carbon::parse($request->TASK_DATE)->format('Y-m-d');

            $data['kpa'] = $KPA;
            $data['task_type'] = $task_type;
            $data['observation_point'] = $observation_point;

            $data['SYEAR'] = $syear;
            // $data['MARKING_PERIOD_ID'] = $term_id;
            $data['CREATED_IP_ADDRESS'] = $_SERVER['REMOTE_ADDR'];
            $data['updated_at'] = date('Y-m-d H:i:s');
            $data['approved_by'] = $user_id;
            $data['approved_on'] = date('Y-m-d H:i:s');

            $TASK_ALLOCATED_TO = $request->input("TASK_ALLOCATED_TO");

            $file_name = $ext = $file_size = "";
            if ($request->hasFile('TASK_ATTACHMENT')) {
                $file = $request->file('TASK_ATTACHMENT');
                $originalname = $file->getClientOriginalName();
                $file_size = $file->getSize();
                $name = "task_" . date('YmdHis');
                $ext = File::extension($originalname);
                $file_name = $name . '.' . $ext;
                // $path = $file->storeAs('public/hp_task/', $file_name);
                Storage::disk('digitalocean')->putFileAs('public/hp_task/', $file, $file_name, 'public');
            }

            if ($file_name != '') {
                $data['TASK_ATTACHMENT'] = $file_name;
                $data['FILE_SIZE'] = $file_size;
                $data['FILE_TYPE'] = $ext;
            }
        }

        $requestedStatus = $data['status'] ?? $data['STATUS'] ?? null;
        if ($requestedStatus !== null && !$this->statusTransitions->allows($before['status'] ?? null, $requestedStatus)) {
            return response()->json([
                'status_code' => 0,
                'message' => $this->statusTransitions->message($before['status'] ?? null, $requestedStatus),
            ], 422);
        }

        /*
         * TENANT-SCOPED. This was `where(['id' => $id])` — id alone.
         *
         * `taskModel` declares no global scope and `protected $guarded = []`,
         * so any authenticated caller could update ANY task in ANY organisation
         * by passing its id, and `$data` (built from `$request->except([...])`)
         * could set arbitrary columns on it — including `sub_institute_id`.
         *
         * The tenant comes from the session or, for `type=API`, from the token
         * via `apiTenantId()` — never from a request field — so adding it here
         * is safe for both callers and changes nothing for a legitimate one:
         * their own tasks still match. A task belonging to someone else now
         * simply updates no rows.
         */
        $updateStatus = taskModel::where('id', $id)
            ->where('sub_institute_id', $sub_institute_id)
            ->update($data);
        if ($updateStatus) {
            $this->taskAudit->taskChanged((int) $id, 'updated', $before, $user_id ? (int) $user_id : null);
            if ($requestedStatus !== null && $this->statusTransitions->normalise($requestedStatus) === 'COMPLETED') {
                $this->dependencyResolution->resolveAfterCompletion((int) $id, $user_id ? (int) $user_id : null);
            }
        }

        // Webhook Trigger
        try {
            $updatedTask = taskModel::find($id);
            if ($updatedTask) {
                $webhookUrl = 'https://dev.triz.co.in/webhook/449cab91-32ac-4c27-ab77-b6a9389185c4';
                
                $webhookPayload = $updatedTask->toArray();
                $webhookPayload['updated_at'] = now()->toIso8601String();
                $webhookPayload['updated_by'] = $user_id;
                $webhookPayload['sub_institute_id'] = $sub_institute_id;
                
                Http::timeout(5)->post($webhookUrl, $webhookPayload);
            }
        } catch (\Exception $e) {
            // Silently fail or log if needed, ensuring main flow isn't interrupted
            // \Log::error('Webhook error: ' . $e->getMessage());
        }

        $res['status_code'] = "1";
        $res['message'] = "Updated successfully";

        return is_mobile($type, "task.index", $res);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy(Request $request, $id)
    {
        $before = $this->taskAudit->taskSnapshot((int) $id);
        $type = $request->input('type');
        $user_id = session()->get('user_id');

        if ($type == "API") {
            $user_id = $request->user_id;
        }

        $delete = taskModel::where(["id" => $id])->update([
            'deleted_by' => $user_id,
            'deleted_at' => now(),
        ]);
        if ($delete) {
            $this->taskAudit->taskChanged((int) $id, 'archived', $before, $user_id ? (int) $user_id : null);
        }

        if ($delete) {
            $res['status_code'] = "1";
            $res['message'] = "Deleted successfully";
        } else {
            $res['status_code'] = "1";
            $res['message'] = "Deleted successfully";
        }

        return is_mobile($type, "task.index", $res);
    }

    public function taskReportIndex(Request $request)
    {
        $type = $request->input('type');

        $res['status_code'] = 1;
        $res['message'] = "Success";

        return is_mobile($type, "front_desk.task_report", $res, "view");
    }

    // function getDatesWithoutSundays($type="",$task_date='',$repeat_days) {
    //     $startDate = Carbon::now();
    //     $endDate = Carbon::create($startDate->year, $startDate->month)->endOfMonth();  

    //     $dates = [];

    //     if($type=="Medium"){
    //         for ($i = 0; $i < 7; $i++) {
    //             $dates[] = Carbon::today()->addDays($i)->format('Y-m-d');
    //         }
    //     }else if($type=="Low"){

    //     }else{
    //         $period = CarbonPeriod::create($startDate, $endDate);
    //         foreach ($period as $date) {
    //             if ($date->isSunday()) {
    //                 continue;
    //             }
    //             $dates[] = $date->format('Y-m-d');
    //         }
    //     }

    //     return $dates;
    // }

    function getDatesWithoutSundays($type = "", $task_date = '', $repeat_days = 1, $repeat_until = null)
    {
        $startDate = $task_date ? Carbon::parse($task_date) : Carbon::now();
        $endDate = $repeat_until ? Carbon::parse($repeat_until) : ($task_date ? Carbon::parse($task_date)->addDay() : Carbon::create($startDate->year, $startDate->month)->endOfMonth());
        $dates = [];
        // return $task_date;
        if ($type == "High" || $type == "Medium" || $type == "Low") {
            for ($date = $startDate->copy(); $date->lte($endDate); $date->addDays((int)$repeat_days)) {
                if (!$date->isSunday()) {
                    $dates[] = $date->format('Y-m-d');
                }
            }
        } else {

            $period = CarbonPeriod::create($startDate, $endDate);
            foreach ($period as $date) {
                if ($date->isSunday()) continue;
                $dates[] = $date->format('Y-m-d');
            }
        }

        return $dates;
    }


    // added on 19-08-2025 by uma for task analysis report
    public function taskAnalysisReport(Request $request)
    {
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
        ]);

        // If validation fails
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 400);
        }
        $sub_institute_id = $this->apiTenantId($request);
        $syear = $request->input('syear');

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

        $res['status'] = 1;
        $res['message'] = "Success";
        $res['taskData'] = $taskData;
        return response()->json($res);
    }
}
