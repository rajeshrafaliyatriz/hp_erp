<?php

namespace App\Http\Controllers\HRMS;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Concerns\ResolvesEmployeeJobRole;
use App\Services\Events\EventRecorder;
use App\Services\HRMS\EmployeeFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The Employee Directory's own API.
 *
 * WHY THIS EXISTS RATHER THAN A PATCH TO tbluserController.
 *
 * That controller serves the legacy Blade screens and reads its tenant from
 * the session. A token caller has no session, so `store()` would have written
 * `sub_institute_id = NULL` - a row invisible to every tenant-scoped query in
 * the application. It also mass-assigns every request key through the query
 * builder (bypassing $fillable entirely), and returns the whole tenant user
 * table as its create response. Giving it a `type=API` branch would mean one
 * method serving two identity models and two response contracts, which is
 * exactly how `edit()` came to return plain_password to a browser.
 *
 * So the Blade path keeps its controller, and the directory gets this one:
 * tenant and actor from the token, an explicit column list in, an explicit
 * column list out. Mirrors DepartmentManagementController, its sibling under
 * /api/departments-management.
 */
class EmployeeDirectoryController extends Controller
{
    use ResolvesApiIdentity;
    use ResolvesEmployeeJobRole;

    public function __construct(
        private EventRecorder $events,
        private EmployeeFactory $employees,
    ) {
    }

    /**
     * Columns safe to return for somebody else.
     *
     * An allow-list, so a column added to tbluser later has to be named here
     * before it can reach a browser. tbluser carries password, plain_password,
     * pan_no, aadhar_no, account_no, ifsc_code and fcm_token; none of them
     * appear below and none ever should.
     */
    private const LIST_COLUMNS = [
        'u.id', 'u.first_name', 'u.middle_name', 'u.last_name',
        'u.email', 'u.mobile', 'u.image', 'u.employee_no', 'u.employee_id',
        'u.department_id', 'u.allocated_standards', 'u.jobtitle_id',
        'u.user_profile_id', 'u.status', 'u.joined_date', 'u.city', 'u.state',
        'u.supervisor_opt',
    ];

    /**
     * full_name is not a column on either database - it is an Eloquent
     * accessor appended by tbluserModel, so a query-builder select cannot
     * produce it. The frontend reads it, so it is built in SQL here.
     *
     * CONCAT_WS with NULLIF rather than the accessor's plain concatenation,
     * which yields a double space whenever middle_name is empty.
     */
    private const FULL_NAME_SQL = "TRIM(CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.middle_name, ''), NULLIF(u.last_name, ''))) as full_name";

    /** Fields a caller may set on create or update. Nothing else is written. */
    private const WRITABLE = [
        'name_suffix', 'first_name', 'middle_name', 'last_name',
        'email', 'mobile', 'gender', 'birthdate',
        'employee_no', 'joined_date', 'qualification',
        'department_id', 'allocated_standards', 'user_profile_id', 'subject_ids',
        'supervisor_opt', 'reporting_method',
        'address', 'address_2', 'city', 'state', 'pincode',
        'bank_name', 'branch_name',
    ];

    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * The employee's job role id.
     *
     * jobtitle_id is set on 23 of 98 employees; allocated_standards on 95. A
     * join on jobtitle_id alone leaves three quarters of the directory showing
     * "-" for their role, which is what the old screen did. This is the SQL
     * form of candidateJobRoleIds() from ResolvesEmployeeJobRole.
     */
    private const ROLE_ID_SQL = "COALESCE(NULLIF(u.jobtitle_id, 0), NULLIF(SUBSTRING_INDEX(u.allocated_standards, ',', 1), '') + 0)";

    /** GET /api/employees-management */
    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $query = DB::table('tbluser as u')
            ->leftJoin('tbluserprofilemaster as p', 'u.user_profile_id', '=', 'p.id')
            ->leftJoin('hrms_departments as d', function ($join) use ($tenantId) {
                $join->on('u.department_id', '=', 'd.id')
                    ->where('d.sub_institute_id', '=', $tenantId)
                    ->whereNull('d.deleted_at');
            })
            ->leftJoin('s_user_jobrole as j', function ($join) use ($tenantId) {
                $join->on(DB::raw(self::ROLE_ID_SQL), '=', DB::raw('j.id'))
                    ->where('j.sub_institute_id', '=', $tenantId)
                    ->whereNull('j.deleted_at');
            })
            ->where('u.sub_institute_id', $tenantId)
            ->whereNull('u.deleted_at')
            ->select(array_merge(self::LIST_COLUMNS, [
                DB::raw(self::FULL_NAME_SQL),
                DB::raw('p.name as profile_name'),
                DB::raw('d.department as department_name'),
                DB::raw('j.jobrole as jobrole'),
                DB::raw('j.id as jobrole_id'),
            ]));

        if ($q = trim((string) $request->input('q'))) {
            $like = '%' . $q . '%';
            $query->where(function ($w) use ($like) {
                $w->where('u.first_name', 'like', $like)
                    ->orWhere('u.last_name', 'like', $like)
                    ->orWhere('u.full_name', 'like', $like)
                    ->orWhere('u.email', 'like', $like)
                    ->orWhere('u.employee_no', 'like', $like);
            });
        }

        if ($request->filled('department_id')) {
            $query->where('u.department_id', (int) $request->input('department_id'));
        }

        if ($request->filled('jobrole_id')) {
            $query->where(DB::raw(self::ROLE_ID_SQL), (int) $request->input('jobrole_id'));
        }

        // Absent means "all"; 0 and 1 are both meaningful, so filled() rather
        // than a truthiness test - `status=0` must not be read as unset.
        if ($request->filled('status') || $request->input('status') === '0') {
            $query->where('u.status', (int) $request->input('status'));
        }

        $rows = $query->orderBy('u.first_name')->orderBy('u.last_name')->get();

        return response()->json([
            'status'  => 1,
            'message' => 'Success',
            'data'    => $rows,
            'meta'    => [
                'total'             => $rows->count(),
                'empty_is_expected' => $rows->isEmpty() && !$request->hasAny(['q', 'department_id', 'jobrole_id', 'status']),
                'empty_reason'      => $rows->isEmpty()
                    ? ($request->hasAny(['q', 'department_id', 'jobrole_id', 'status'])
                        ? 'No employees match these filters.'
                        : 'No employees have been added to this organisation yet.')
                    : null,
            ],
        ]);
    }

    /**
     * GET /api/employees-management/reference-data
     *
     * Everything the Add wizard and the drawer's pickers need, in one call.
     * The old screen hardcoded these - Engineering/Product, "Alex Mercer
     * (CTO)", and three of the seven levels of responsibility.
     */
    public function referenceData(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $departments = DB::table('hrms_departments')
            ->where('sub_institute_id', $tenantId)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->orderBy('department')
            ->get(['id', 'department as name', 'parent_id']);

        $jobRoles = DB::table('s_user_jobrole')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->orderBy('jobrole')
            ->get(['id', 'jobrole as name', 'department_id', 'jobrole_category as category']);

        $userProfiles = DB::table('tbluserprofilemaster')
            ->where('sub_institute_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name']);

        // All seven levels. s_level_responsibility holds 112 rows - one per
        // attribute - so this collapses to the distinct levels a person can be
        // assigned, keeping the lowest id per level as the value to store.
        $levels = DB::table('s_level_responsibility')
            ->select(DB::raw('MIN(id) as id'), 'level', DB::raw('MIN(guiding_phrase) as guiding_phrase'))
            ->groupBy('level')
            ->orderBy('level')
            ->get();

        $managers = DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'employee_no']);

        return response()->json([
            'status'  => 1,
            'message' => 'Success',
            'data'    => [
                'departments'              => $departments,
                'job_roles'                => $jobRoles,
                'user_profiles'            => $userProfiles,
                'levels_of_responsibility' => $levels,
                'managers'                 => $managers,
                'next_employee_no'         => $this->nextEmployeeNo($tenantId),
                'default_schedule'         => $this->defaultSchedule($tenantId),
            ],
        ]);
    }

    /** GET /api/employees-management/{id} */
    public function show(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $row = $this->findForTenant((int) $id, $identity['sub_institute_id']);

        if (!$row) {
            return response()->json(['status' => 0, 'message' => 'Employee not found'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Success', 'data' => $row]);
    }

    /** POST /api/employees-management */
    public function store(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $validator = Validator::make($request->all(), $this->rules(null));

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        if ($error = $this->checkReferences($request, $tenantId)) {
            return $error;
        }

        // The credential, user_name, tenancy, dual role write, department arrival
        // row and employee.hired event all live in EmployeeFactory, so that this
        // form and TalentOfferController::accept() create an employee the same way.
        $payload = array_merge(
            $this->writableFrom($request),
            $this->scheduleColumns($request->input('schedule'))
        );

        try {
            $newId = $this->employees->create($tenantId, $actorId, $payload, [
                'department_id'  => $request->input('department_id'),
                'effective_date' => $request->input('joined_date'),
                'remarks'        => 'Employee created in Employee Directory',
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // tbluser_email_unique is global, not per tenant. The validator
            // already checks it, but a concurrent create can still collide.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'That email address is already in use.',
                ], 422);
            }
            throw $e;
        }

        $invite = $this->employees->issueInvite($request->input('email'));

        return response()->json([
            'status'  => 1,
            'message' => $invite['sent']
                ? 'Employee created and invited.'
                : 'Employee created. The invite could not be sent.',
            'data'    => [
                'id'           => $newId,
                'email'        => $request->input('email'),
                'invite_sent'  => $invite['sent'],
                'invite_error' => $invite['error'],
            ],
        ], 201);
    }

    /** PUT /api/employees-management/{id} */
    public function update(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $existing = $this->findForTenant((int) $id, $tenantId);
        if (!$existing) {
            return response()->json(['status' => 0, 'message' => 'Employee not found'], 404);
        }

        $validator = Validator::make($request->all(), $this->rules((int) $id));

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        if ($error = $this->checkReferences($request, $tenantId)) {
            return $error;
        }

        $payload = $this->writableFrom($request);
        $payload['updated_by'] = $actorId;
        $payload['updated_at'] = now();

        if ($request->has('allocated_standards')) {
            $roleId = $request->input('allocated_standards');
            $payload['allocated_standards'] = $roleId ? (string) $roleId : null;
            $payload['jobtitle_id']         = $roleId ? (int) $roleId : null;
        }

        // Only when asked. The legacy path forced status = 1 on every save, so
        // saving any field on a suspended employee reactivated them.
        if ($request->has('status')) {
            $payload['status'] = (int) $request->input('status') === 0 ? 0 : 1;
        }

        if ($request->has('schedule')) {
            $payload = array_merge($payload, $this->scheduleColumns($request->input('schedule')));
        }

        $movedDepartment = $request->has('department_id')
            && (int) $request->input('department_id') !== (int) $existing->department_id;

        DB::transaction(function () use ($id, $tenantId, $actorId, $payload, $movedDepartment, $existing, $request) {
            DB::table('tbluser')
                ->where('id', $id)
                ->where('sub_institute_id', $tenantId)
                ->update($payload);

            if ($movedDepartment) {
                DB::table('s_mobility_transfers')->insert([
                    'sub_institute_id'   => $tenantId,
                    'user_id'            => (int) $id,
                    'from_department_id' => $existing->department_id ?: null,
                    'to_department_id'   => $request->input('department_id') ?: null,
                    'effective_date'     => now()->toDateString(),
                    'status'             => 'Completed',
                    'remarks'            => 'Department changed from Employee Directory',
                    'created_by'         => $actorId,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);

                $this->events->record(
                    'employee.role_assigned',
                    $tenantId,
                    'employee',
                    (int) $id,
                    $actorId,
                    [
                        'from_department_id' => $existing->department_id,
                        'to_department_id'   => $request->input('department_id'),
                        'jobrole_id'         => $request->input('allocated_standards'),
                    ]
                );
            }
        });

        return response()->json([
            'status'  => 1,
            'message' => 'Employee updated.',
            'data'    => $this->findForTenant((int) $id, $tenantId),
        ]);
    }

    /**
     * PATCH /api/employees-management/{id}/status
     *
     * Backs "Suspend Access". Deliberately not destroy(): the legacy
     * destroy()/deactiveUser() pair both set deleted_at, which removes the
     * employee from the directory rather than suspending them.
     */
    public function setStatus(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:0,1',
            'reason' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        if (!$this->findForTenant((int) $id, $tenantId)) {
            return response()->json(['status' => 0, 'message' => 'Employee not found'], 404);
        }

        if ((int) $id === $identity['user_id'] && (int) $request->input('status') === 0) {
            return response()->json([
                'status'  => 0,
                'message' => 'You cannot suspend your own account.',
            ], 422);
        }

        DB::table('tbluser')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->update([
                'status'     => (int) $request->input('status'),
                'updated_by' => $identity['user_id'],
                'updated_at' => now(),
            ]);

        return response()->json([
            'status'  => 1,
            'message' => (int) $request->input('status') === 1 ? 'Access restored.' : 'Access suspended.',
        ]);
    }

    /** POST /api/employees-management/{id}/invite */
    public function invite(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $employee = $this->findForTenant((int) $id, $identity['sub_institute_id']);

        if (!$employee) {
            return response()->json(['status' => 0, 'message' => 'Employee not found'], 404);
        }

        $invite = $this->employees->issueInvite($employee->email);

        return response()->json([
            'status'  => $invite['sent'] ? 1 : 0,
            'message' => $invite['sent'] ? 'Invite sent.' : ('The invite could not be sent: ' . $invite['error']),
        ], $invite['sent'] ? 200 : 502);
    }

    // ---------------------------------------------------------------- helpers

    private function findForTenant(int $id, int $tenantId)
    {
        // Aliased to `u` so FULL_NAME_SQL, which is written against that alias,
        // works here as well as in index().
        return DB::table('tbluser as u')
            ->where('u.id', $id)
            ->where('u.sub_institute_id', $tenantId)
            ->whereNull('u.deleted_at')
            ->first(array_merge(
                self::LIST_COLUMNS,
                [DB::raw(self::FULL_NAME_SQL)],
                array_map(fn ($c) => 'u.' . $c, [
                    'name_suffix', 'gender', 'birthdate', 'subject_ids',
                    'address', 'address_2', 'pincode', 'reporting_method',
                    'bank_name', 'branch_name', 'qualification',
                    ...self::DAYS,
                    ...array_map(fn ($d) => $d . '_in_date', self::DAYS),
                    ...array_map(fn ($d) => $d . '_out_date', self::DAYS),
                ])
            ));
    }

    /**
     * Validation rules. $ignoreId is the employee being updated, so their own
     * email does not collide with itself.
     */
    private function rules(?int $ignoreId): array
    {
        $required = $ignoreId === null ? 'required' : 'sometimes|required';
        $emailUnique = 'unique:tbluser,email' . ($ignoreId ? ',' . $ignoreId : '');

        return [
            'first_name'  => $required . '|string|max:100',
            'last_name'   => $required . '|string|max:100',
            // Global, not per tenant: tbluser_email_unique has no tenant in it,
            // so a per-tenant rule would pass and then throw a 1062 on insert.
            'email'       => $required . '|email:filter|max:191|' . $emailUnique,
            'name_suffix' => 'nullable|string|max:20',
            'middle_name' => 'nullable|string|max:100',
            'mobile'      => 'nullable|string|max:20',
            'gender'      => 'nullable|in:M,F,O',
            'birthdate'   => 'nullable|date|before:today',
            'employee_no' => 'nullable|string|max:50',
            'joined_date' => 'nullable|date',
            'qualification' => 'nullable|string|max:191',

            'user_profile_id'     => $required . '|integer|min:1',
            'department_id'       => 'nullable|integer|min:1',
            'allocated_standards' => 'nullable|integer|min:1',
            'subject_ids'         => 'nullable|integer|min:1',
            'supervisor_opt'      => 'nullable|integer|min:1',
            'reporting_method'    => 'nullable|string|max:100',

            'address'     => 'nullable|string|max:191',
            'address_2'   => 'nullable|string|max:191',
            'city'        => 'nullable|string|max:100',
            'state'       => 'nullable|string|max:100',
            'pincode'     => 'nullable|string|max:20',
            'bank_name'   => 'nullable|string|max:191',
            'branch_name' => 'nullable|string|max:191',

            'status'      => 'nullable|in:0,1',

            'schedule'              => 'nullable|array|max:7',
            'schedule.*.day'        => 'required_with:schedule|in:' . implode(',', self::DAYS),
            'schedule.*.working'    => 'required_with:schedule|boolean',
            'schedule.*.in_time'    => 'nullable|date_format:H:i',
            'schedule.*.out_time'   => 'nullable|date_format:H:i',
        ];
    }

    /**
     * Every id the caller supplied must belong to the caller's own tenant, and
     * a job role must belong to the department it is being paired with - the
     * same rule DepartmentManagementController enforces, so the two screens
     * cannot disagree about who holds what.
     */
    private function checkReferences(Request $request, int $tenantId)
    {
        $checks = [
            'user_profile_id' => ['tbluserprofilemaster', 'User profile'],
            'department_id'   => ['hrms_departments', 'Department'],
            'supervisor_opt'  => ['tbluser', 'Reporting manager'],
        ];

        foreach ($checks as $field => [$table, $label]) {
            if (!$request->filled($field)) {
                continue;
            }

            $exists = DB::table($table)
                ->where('id', (int) $request->input($field))
                ->where('sub_institute_id', $tenantId)
                ->exists();

            if (!$exists) {
                return response()->json([
                    'status'  => 0,
                    'message' => "{$label} does not belong to this organisation.",
                ], 422);
            }
        }

        if ($request->filled('allocated_standards')) {
            $role = DB::table('s_user_jobrole')
                ->where('id', (int) $request->input('allocated_standards'))
                ->where('sub_institute_id', $tenantId)
                ->whereNull('deleted_at')
                ->first(['id', 'department_id']);

            if (!$role) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Job role does not belong to this organisation.',
                ], 422);
            }

            if ($request->filled('department_id')
                && (int) $role->department_id !== (int) $request->input('department_id')) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'That job role belongs to a different department. Choose a role from the selected department, or change the department.',
                ], 422);
            }
        }

        return null;
    }

    /** Only the fields in WRITABLE, and only those the caller actually sent. */
    private function writableFrom(Request $request): array
    {
        $payload = [];

        foreach (self::WRITABLE as $field) {
            if (!$request->has($field)) {
                continue;
            }

            $value = $request->input($field);
            $payload[$field] = ($value === '' ? null : $value);
        }

        if (isset($payload['birthdate']) && $payload['birthdate']) {
            $payload['birthdate'] = date('Y-m-d', strtotime($payload['birthdate']));
        }

        // allocated_standards is written by the caller as a role id but stored
        // as a string; store() and update() set it alongside jobtitle_id.
        unset($payload['allocated_standards']);

        return $payload;
    }

    /**
     * Turn the wizard's per-day schedule into the flat tbluser columns.
     *
     * The schema is per day - monday..sunday plus <day>_in_date/_out_date -
     * and that is not incidental: Saturday's out-time differs from Monday's
     * for 202 of the 216 employees who have one, because half-day Saturdays
     * are the norm here. A single shift applied to every day would erase that.
     *
     * monday_in_date is also the late-arrival threshold in
     * AttendanceDashboardApiController and AttendanceApiController, so a wrong
     * value here produces wrong lateness reports.
     */
    private function scheduleColumns($schedule): array
    {
        if (!is_array($schedule)) {
            return [];
        }

        $columns = [];

        foreach ($schedule as $entry) {
            $day = $entry['day'] ?? null;

            if (!in_array($day, self::DAYS, true)) {
                continue;
            }

            $working = filter_var($entry['working'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $columns[$day] = $working ? 1 : 0;
            $columns[$day . '_in_date']  = $working && !empty($entry['in_time'])
                ? date('H:i:s', strtotime($entry['in_time']))
                : null;
            $columns[$day . '_out_date'] = $working && !empty($entry['out_time'])
                ? date('H:i:s', strtotime($entry['out_time']))
                : null;
        }

        return $columns;
    }

    /**
     * The organisation's usual working week, so the Add form opens on
     * something true rather than a hardcoded Mon-Fri 09:00-18:00.
     */
    private function defaultSchedule(int $tenantId): array
    {
        $modal = [];

        foreach (self::DAYS as $day) {
            $row = DB::table('tbluser')
                ->where('sub_institute_id', $tenantId)
                ->whereNull('deleted_at')
                ->where($day, 1)
                ->whereNotNull($day . '_in_date')
                ->select($day . '_in_date as in_time', $day . '_out_date as out_time', DB::raw('COUNT(*) as total'))
                ->groupBy($day . '_in_date', $day . '_out_date')
                ->orderByDesc('total')
                ->first();

            $modal[] = [
                'day'      => $day,
                'working'  => (bool) $row,
                'in_time'  => $row?->in_time ? substr($row->in_time, 0, 5) : null,
                'out_time' => $row?->out_time ? substr($row->out_time, 0, 5) : null,
            ];
        }

        return $modal;
    }

    private function nextEmployeeNo(int $tenantId): string
    {
        $highest = DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->whereRaw("employee_no REGEXP '^[0-9]+$'")
            ->max(DB::raw('CAST(employee_no AS UNSIGNED)'));

        return (string) (((int) $highest) + 1);
    }


}
