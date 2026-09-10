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
        /*
         * `reporting_manager_id` joins `supervisor_opt` here rather than
         * replacing it: the picker now WRITES the former, but the latter is
         * still on 71 live rows and the directory should not stop returning a
         * column it has always returned.
         *
         * Without this the edit form could not show who somebody currently
         * reports to - it would render an empty picker over a set value and
         * blank it on the next save.
         */
        'u.supervisor_opt', 'u.reporting_manager_id',
    ];

    /**
     * full_name is not a column on either database - it is an Eloquent
     * accessor appended by tbluserModel, so a query-builder select cannot
     * produce it. The frontend reads it, so it is built in SQL here.
     *
     * CONCAT_WS with NULLIF rather than the accessor's plain concatenation,
     * which yields a double space whenever middle_name is empty.
     */
    private const FULL_NAME_EXPR = "TRIM(CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.middle_name, ''), NULLIF(u.last_name, '')))";

    private const FULL_NAME_SQL = self::FULL_NAME_EXPR . ' as full_name';

    /**
     * The most rows index() will return in one uncapped response (F-145).
     *
     * 2000 clears every organisation on the platform today - the largest has
     * 1001 - so no existing caller sees a behaviour change. It exists so the
     * response size is bounded by the CODE rather than by the customer's
     * headcount.
     */
    private const MAX_ROWS = 2000;

    /** Fields a caller may set on create or update. Nothing else is written. */
    private const WRITABLE = [
        'name_suffix', 'first_name', 'middle_name', 'last_name',
        'email', 'mobile', 'gender', 'birthdate',
        'employee_no', 'joined_date', 'qualification',
        'department_id', 'allocated_standards', 'user_profile_id', 'subject_ids',
        /*
         * `reporting_manager_id` REPLACES `supervisor_opt` HERE.
         *
         * The Reporting Manager picker in both the add sheet and the edit tab
         * has always sent a real user id - and it landed in `supervisor_opt`,
         * a VARCHAR(191) whose actual data is the string "Subordinate" on 61
         * rows here and 71 on live. It is a relationship TYPE column that the
         * API had been treating as a person.
         *
         * Meanwhile everything that asks "who is this person's manager" reads
         * `reporting_manager_id`: the leave Team scope, approval routing, and
         * the reporting_coverage readiness gate. That column was written by
         * nothing, which is why it is set on 0 of 299 people on live and why
         * that gate reads 0%.
         *
         * `supervisor_opt` is left in the database untouched - it is somebody's
         * legacy data - but it is no longer written from here, and no longer
         * validated as a user id it never held.
         */
        'reporting_manager_id', 'reporting_method',
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
                /*
                 * THE EXPRESSION, NOT THE ALIAS.
                 *
                 * `u.full_name` was searched here as though it were a column.
                 * It is not - the docblock above says so - it is a SELECT alias,
                 * and MySQL evaluates WHERE before the select list, so it never
                 * resolves: every search in the Employee Directory failed with
                 * "Unknown column 'u.full_name' in 'where clause'".
                 *
                 * Repeating the expression is what makes a search for
                 * "milan baldaniya" work at all: first_name and last_name each
                 * hold half of it, so neither LIKE can match the pair on its own.
                 */
                $w->where('u.first_name', 'like', $like)
                    ->orWhere('u.last_name', 'like', $like)
                    ->orWhereRaw(self::FULL_NAME_EXPR . ' like ?', [$like])
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

        /*
         * F-145. This returned EVERY employee in the organisation - no
         * pagination, no LIMIT, no cap. At the largest organisation on the
         * platform (1001 employees) that measured 28.9 ms and a large payload:
         * not a failure, but a cost set by the customer's headcount rather than
         * by anything in the code. The first organisation to arrive with 10,000
         * staff would have found out in production.
         *
         * Paginated, but OPT-IN and backward compatible, because the frontend
         * reads `data` as a plain array and changing that shape unasked would
         * break the screen:
         *
         *   - `per_page` given -> that page is returned, meta carries the rest.
         *   - absent           -> previous behaviour, EXCEPT that the result is
         *                         capped at MAX_ROWS and meta.truncated says so.
         *
         * A silent cap would be worse than none, so the cap always announces
         * itself: `truncated` is a fact the caller can act on, not a number
         * quietly missing from a list.
         */
        $total = (clone $query)->count();

        $perPage = $request->filled('per_page') ? (int) $request->input('per_page') : null;
        $page    = max(1, (int) $request->input('page', 1));

        if ($perPage !== null && $perPage > 0) {
            $perPage = min($perPage, self::MAX_ROWS);
            $query->forPage($page, $perPage);
        } else {
            $query->limit(self::MAX_ROWS + 1);   // +1 so truncation is detectable
        }

        $rows = $query->orderBy('u.first_name')->orderBy('u.last_name')->get();

        $truncated = false;

        if ($perPage === null && $rows->count() > self::MAX_ROWS) {
            $rows      = $rows->take(self::MAX_ROWS)->values();
            $truncated = true;
        }

        return response()->json([
            'status'  => 1,
            'message' => 'Success',
            'data'    => $rows,
            'meta'    => [
                // `total` still means what it always did: how many employees
                // match, NOT how many were returned. Changing that silently
                // would make every existing caller's count wrong.
                'total'             => $total,
                'returned'          => $rows->count(),
                'per_page'          => $perPage,
                'page'              => $perPage !== null ? $page : 1,
                'max_rows'          => self::MAX_ROWS,
                'truncated'         => $truncated,
                'truncated_reason'  => $truncated
                    ? 'This organisation has ' . $total . ' employees; the first ' . self::MAX_ROWS
                        . ' are shown. Use per_page and page, or filter with q, department_id, '
                        . 'jobrole_id or status.'
                    : null,
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

        $invite = $this->employees->issueInvite($request->input('email'), $tenantId);

        /*
         * ── THREE OUTCOMES, NOT A BOOLEAN ───────────────────────────────────
         *
         * This used to answer "Employee created and invited." whenever a token
         * row inserted - which was always, because nothing was ever sent. The
         * new employee was told an email was coming and given a password made
         * of `random_bytes(12)` that nobody knows.
         *
         * `link` is returned when mail is not available for this organisation,
         * which is eleven of the twelve on live. The caller shows it and the
         * administrator hands it over. That is what makes the feature work
         * TODAY rather than after somebody configures SMTP.
         */
        return response()->json([
            'status'  => 1,
            'message' => match ($invite['delivered']) {
                'email' => 'Employee created, and a set-password link was emailed to them.',
                'link'  => 'Employee created. Email is not set up for your organisation, so copy the link below and send it to them.',
                default => 'Employee created, but no set-password link could be made. They will not be able to sign in until you resend the invite.',
            },
            'data'    => [
                'id'            => $newId,
                'email'         => $request->input('email'),
                'invite'        => $invite['delivered'],
                'invite_link'   => $invite['link'],
                'invite_expires_hours' => $invite['expires_hours'],
                'invite_error'  => $invite['error'],
                // Kept for anything still reading the old key - and it is true
                // only when something really was sent.
                'invite_sent'   => $invite['sent'],
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

        // The employee id goes in so the self/cycle rules have somebody to
        // reason about. On create there is no id yet - a person who does not
        // exist cannot be their own manager, and cannot be in a loop.
        if ($error = $this->checkReferences($request, $tenantId, (int) $id)) {
            return $error;
        }

        /*
         * ═══════════════════════════════════════════════════════════════════
         * A ROLE CHANGE IS NOT AN ORDINARY FIELD EDIT
         * ═══════════════════════════════════════════════════════════════════
         *
         * `user_profile_id` is in WRITABLE, and until now it was applied with
         * nothing but an exists-and-same-tenant check. The route is guarded
         * `profile:admin,hr`, which `RoleKey::ALIASES` expands to administrator,
         * hr_manager AND hr_executive - so any of those three could set their
         * OWN profile to the tenant's administrator profile and self-promote,
         * or promote a colleague past themselves.
         *
         * `RequireProfile` cannot catch this: it compares the caller's role to
         * the route's list and never looks at what is being written.
         *
         * Two rules, mirroring invite():
         *   - Nobody changes their own role here. That is not an edit, it is a
         *     promotion, and it needs somebody else.
         *   - Nobody grants a role at or above their own.
         */
        if ($request->has('user_profile_id')
            && (int) $request->input('user_profile_id') !== (int) $existing->user_profile_id) {
            if ((int) $id === (int) $actorId) {
                return response()->json([
                    'status' => 0,
                    'message' => 'You cannot change your own role. Ask another administrator.',
                ], 403);
            }

            $callerRank = self::rankOf(\App\Support\RoleKey::forUserId((int) $actorId));
            $grantRank = self::rankOf(\App\Support\RoleKey::forProfileId((int) $request->input('user_profile_id')));

            if ($grantRank >= $callerRank) {
                return response()->json([
                    'status' => 0,
                    'message' => 'You cannot give somebody a role at or above your own.',
                ], 403);
            }
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

        /*
         * ═══════════════════════════════════════════════════════════════════
         * SUSPENDING ACCESS NOW ACTUALLY SUSPENDS ACCESS
         * ═══════════════════════════════════════════════════════════════════
         *
         * This wrote `status = 0`, answered "Access suspended.", and left every
         * one of that person's Sanctum tokens alive. Nothing else closed the
         * gap: `sanctum.expiration` is null so tokens never lapse on their own,
         * and neither `RequireApiToken` nor `ResolvesApiIdentity` consults
         * `status`. So somebody who was suspended kept working - in a tab they
         * already had open, or from any device holding a token, indefinitely.
         *
         * On live there are 5,338 live tokens across 53 people, one account
         * holding 2,140, so "they will be logged out eventually" was never true.
         *
         * The same three lines PasswordController::setPassword already uses.
         */
        if ((int) $request->input('status') === 0) {
            $ended = DB::table('personal_access_tokens')
                ->where('tokenable_type', \App\Models\auth\tbluserModel::class)
                ->where('tokenable_id', $id)
                ->delete();

            return response()->json([
                'status'  => 1,
                'message' => $ended > 0
                    ? 'Access suspended, and they have been signed out of ' . $ended . ' device(s).'
                    : 'Access suspended.',
            ]);
        }

        return response()->json([
            'status'  => 1,
            'message' => 'Access restored.',
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

        /*
         * ═══════════════════════════════════════════════════════════════════
         * AN INVITE IS NOT A PASSWORD RESET FOR SOMEBODY ELSE'S ACCOUNT
         * ═══════════════════════════════════════════════════════════════════
         *
         * This checked only that the target was in the caller's tenant. That is
         * a privilege escalation, because of what an invite now IS: when the
         * organisation has no SMTP - ten of eleven do not - `InviteService`
         * returns the live set-password link TO THE CALLER, and the People &
         * access screen renders it in a copyable field.
         *
         * So `POST /employees-management/<administrator id>/invite` handed any
         * hr_executive a working link to the administrator's account. Opening it
         * sets that account's password, nulls its OTP and deletes every one of
         * its sessions. The route's `profile:admin,hr` guard cannot catch this:
         * RequireProfile compares the CALLER's role to the route's list and
         * never looks at the target at all.
         *
         * Two rules, both necessary:
         *
         *   1. ONLY SOMEBODY WHO HAS NEVER SIGNED IN. An account already in use
         *      is re-credentialed through `POST /api/auth/forgot-password`,
         *      which mails the owner and never returns the link to anybody.
         *   2. NEVER UPWARDS. An hr_executive may not invite an administrator.
         */
        if ($employee->last_login !== null && $employee->last_login !== '') {
            return response()->json([
                'status' => 0,
                'message' => 'That person has already signed in. Ask them to use "Forgot password?" '
                    . 'on the sign-in screen - it sends a link to them, not to you.',
            ], 422);
        }

        if (!$this->mayCredential((int) $identity['user_id'], (int) $employee->id)) {
            return response()->json([
                'status' => 0,
                'message' => 'You cannot send a sign-in link for an account with more access than your own.',
            ], 403);
        }

        $invite = $this->employees->issueInvite($employee->email, $identity['sub_institute_id']);

        /*
         * A LINK IS NOT A FAILURE.
         *
         * This used to answer 502 for anything that was not an email - so on the
         * eleven organisations with no SMTP configured, resending an invite
         * would have looked like a server fault even once the link worked.
         *
         * Only 'failed' is an error now: no address, no configured application
         * URL, or the token could not be written.
         */
        $ok = $invite['delivered'] !== 'failed';

        return response()->json([
            'status'  => $ok ? 1 : 0,
            'message' => match ($invite['delivered']) {
                'email' => 'A set-password link was emailed to ' . $employee->email
                    . '. The same link is below if it does not arrive.',
                'link'  => 'Email is not set up for your organisation, so copy this link and send it to them.',
                default => 'The invite could not be created: ' . $invite['error'],
            },
            'data' => [
                'invite'  => $invite['delivered'],
                'link'    => $invite['link'],
                'expires_hours' => $invite['expires_hours'],
                'error'   => $invite['error'],
            ],
        ], $ok ? 200 : 422);
    }

    /**
     * GET /api/employees-management/pending-access
     *
     * ═══════════════════════════════════════════════════════════════════════
     * WHO CANNOT GET IN, AND WHY
     * ═══════════════════════════════════════════════════════════════════════
     *
     * Until the invite was made real, EVERY account created through this screen
     * was unreachable: `EmployeeFactory::create()` sets a random password nobody
     * will ever know, and `issueInvite()` wrote a token and returned
     * `['sent' => true]` WITHOUT SENDING ANYTHING. The frontend then told the
     * administrator "An invite was sent to {email}".
     *
     * So there is a population of people, on every tenant, holding an account
     * they have never been able to open — and nothing in this product would tell
     * anybody who they are. This answers that, from facts already stored:
     *
     *   `tbluser.last_login IS NULL`     they have never once signed in
     *   `password_reset_tokens`          an invite exists, and when it was made
     *
     * ── ONLY THIS TENANT, AND THE JOIN IS THE REASON THIS IS CAREFUL ────────
     *
     * `password_reset_tokens` is keyed on EMAIL and has no tenant column, so the
     * join is on an address. That is safe only because `tbluser.email` is unique
     * globally — one address cannot belong to two organisations — and the
     * employee side of the join is filtered to the caller's tenant first.
     */
    public function pendingAccess(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];
        $expiresAfter = now()->subHours(\App\Services\Auth\InviteService::TOKEN_HOURS);

        $rows = DB::table('tbluser as u')
            ->leftJoin('password_reset_tokens as t', 't.email', '=', 'u.email')
            ->where('u.sub_institute_id', $tenantId)
            ->whereNull('u.deleted_at')
            ->whereNull('u.last_login')
            ->where('u.status', 1)
            ->orderByDesc('u.created_at')
            ->limit(200)
            ->get([
                'u.id',
                'u.email',
                'u.employee_no',
                'u.created_at',
                't.created_at as invited_at',
                DB::raw(self::FULL_NAME_SQL),
            ]);

        $people = $rows->map(function ($row) use ($expiresAfter) {
            $invitedAt = $row->invited_at ? \Illuminate\Support\Carbon::parse($row->invited_at) : null;

            return [
                'id' => (int) $row->id,
                'name' => $row->full_name,
                'email' => $row->email,
                'employee_no' => $row->employee_no,
                'created_at' => $row->created_at,
                'invited_at' => $row->invited_at,
                /*
                 * Three states, because they need three different actions:
                 * never invited (send one), invited and still valid (wait or
                 * resend), invited and expired (resend - the link they hold is
                 * already dead, which nothing has ever told them).
                 */
                'state' => $invitedAt === null
                    ? 'never_invited'
                    : ($invitedAt->greaterThanOrEqualTo($expiresAfter) ? 'invited' : 'expired'),
            ];
        });

        /*
         * ═══════════════════════════════════════════════════════════════════
         * THE COUNTS ARE COUNTED SEPARATELY, NOT FROM THE TRUNCATED LIST
         * ═══════════════════════════════════════════════════════════════════
         *
         * They used to be derived from `$people`, which is capped at 200 rows.
         * On any organisation with more than 200 stranded accounts, the screen
         * would report "200 people cannot sign in" as an absolute figure, and
         * the number would stop moving no matter how many invites were sent -
         * an administrator working the list would have no way to tell whether
         * they were making progress.
         *
         * `$listed` versus `$total` also lets the screen say the list is capped
         * instead of implying it is complete.
         */
        $total = (int) DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereNull('last_login')
            ->where('status', 1)
            ->count();

        return response()->json([
            'status' => 1,
            'data' => [
                'people' => $people->values(),
                'listed' => $people->count(),
                'truncated' => $total > $people->count(),
                'counts' => [
                    'total' => $total,
                    'never_invited' => $people->where('state', 'never_invited')->count(),
                    'invited' => $people->where('state', 'invited')->count(),
                    'expired' => $people->where('state', 'expired')->count(),
                ],
                'expires_hours' => \App\Services\Auth\InviteService::TOKEN_HOURS,
            ],
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Privilege ordering, for deciding who may act ON whom.
     *
     * `RoleKey::ALL` is already documented as being in privilege order, so the
     * INDEX in it is the rank. Anything unrecognised ranks lowest, which is the
     * safe direction: an unknown role is treated as least privileged, so it can
     * neither be acted on from below nor act on anybody above it.
     *
     * This is not a general permission system. It answers one narrow question -
     * "may this caller mint a credential for that account?" - and it exists
     * because `RequireProfile` cannot: that middleware compares the CALLER's
     * role to a route's list and never looks at the target.
     */
    private static function rankOf(?string $roleKey): int
    {
        $index = array_search($roleKey, \App\Support\RoleKey::ALL, true);

        return $index === false ? -1 : (int) $index;
    }

    /**
     * May the caller mint a sign-in credential for this account?
     *
     * Only downwards, and never for themselves. Acting on your own account
     * through the directory is not credentialing - it is Settings, where the
     * current password is required.
     */
    private function mayCredential(int $callerId, int $targetId): bool
    {
        if ($callerId === $targetId) {
            return false;
        }

        $caller = self::rankOf(\App\Support\RoleKey::forUserId($callerId));
        $target = self::rankOf(\App\Support\RoleKey::forUserId($targetId));

        return $caller > $target;
    }

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
                    /*
                     * `last_login` is needed by invite(), which refuses to mint
                     * a sign-in link for an account somebody is already using.
                     * Absent from the select it would read as null and the guard
                     * would pass for everybody - a check that silently never
                     * fires is worse than no check, because it reads as one.
                     */
                    'last_login',
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
            'reporting_manager_id' => 'nullable|integer|min:1',
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
    private function checkReferences(Request $request, int $tenantId, ?int $employeeId = null)
    {
        $checks = [
            'user_profile_id' => ['tbluserprofilemaster', 'User profile'],
            'department_id'   => ['hrms_departments', 'Department'],
            'reporting_manager_id' => ['tbluser', 'Reporting manager'],
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

        /*
         * ── AN EMPLOYEE NUMBER IS A NAME, SO IT HAS TO BE ONE PERSON'S ──────
         *
         * Nothing has ever checked this - not a database index, not a
         * validation rule, not a line of code - which is why `EMP001` sits on
         * eleven live rows across five organisations, three of them in tenant 1.
         *
         * Only a CHANGED value is checked. Validating unconditionally would
         * freeze those eleven records: saving any other field on one of them
         * would be refused because a sibling still holds the same number. This
         * stops new duplicates while leaving the existing ones editable, which
         * is what lets somebody actually fix them.
         */
        if ($request->filled('employee_no')) {
            $employeeNo = trim((string) $request->input('employee_no'));

            $current = $employeeId !== null
                ? trim((string) DB::table('tbluser')->where('id', $employeeId)->value('employee_no'))
                : '';

            if ($employeeNo !== $current && $this->employeeNoTaken($tenantId, $employeeNo, $employeeId)) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Employee number ' . $employeeNo . ' already belongs to somebody else in this organisation.',
                ], 422);
            }
        }

        /*
         * ── A REPORTING LINE IS A TREE, NOT A LIST ──────────────────────────
         *
         * Belonging to the same tenant is necessary and nowhere near
         * sufficient. Two shapes have to be refused or the hierarchy stops
         * being one:
         *
         *   SELF     "reports to themselves" is not a sentence about anybody,
         *            and it makes every upward walk of the chain infinite.
         *   CYCLE    A -> B -> A. Each assignment looks perfectly reasonable on
         *            its own; only the pair is wrong. DepartmentManagement
         *            already prevents exactly this for departments, and a
         *            reporting line that allows it will hang the first feature
         *            that walks it - leave approval routing being the obvious
         *            one.
         *
         * The walk is bounded independently of the cycle check, because the
         * data may ALREADY contain a loop from before this rule existed and a
         * validator that hangs on bad data is worse than the bad data.
         */
        if ($request->filled('reporting_manager_id') && $employeeId !== null) {
            $managerId = (int) $request->input('reporting_manager_id');

            if ($managerId === $employeeId) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Somebody cannot report to themselves.',
                ], 422);
            }

            $seen = [$employeeId => true];
            $cursor = $managerId;

            for ($hops = 0; $hops < 64 && $cursor > 0; $hops++) {
                if (isset($seen[$cursor])) {
                    return response()->json([
                        'status'  => 0,
                        'message' => 'That would create a loop in the reporting line - this person already appears above the chosen manager.',
                    ], 422);
                }

                $seen[$cursor] = true;

                $cursor = (int) DB::table('tbluser')
                    ->where('id', $cursor)
                    ->where('sub_institute_id', $tenantId)
                    ->value('reporting_manager_id');
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
        /*
         * ── THE OLD ONE ONLY SAW PURE DIGITS ────────────────────────────────
         *
         *     ->whereRaw("employee_no REGEXP '^[0-9]+$'")
         *     ->max(DB::raw('CAST(employee_no AS UNSIGNED)'));
         *
         * Real data does not look like that. On live, tenant 1 holds
         * `EMP001, EMP001, 1, EMP001` and tenants 2, 3 and 7 hold nothing but
         * `EMP001`. The regex excluded every prefixed value, so an organisation
         * whose whole workforce is EMP001..EMP050 was offered "1" - forever,
         * every time - which is precisely how EMP001 came to be duplicated
         * eleven times across five tenants.
         *
         * This reads the shape the organisation actually uses: the most common
         * prefix, the widest zero-padding in that group, and one past its
         * highest number. EMP001 -> EMP002. 7 -> 8. Nothing at all -> 1.
         */
        $existing = DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->whereNotNull('employee_no')
            ->where('employee_no', '!=', '')
            ->pluck('employee_no');

        $groups = [];

        foreach ($existing as $value) {
            // "EMP001" -> ["EMP", "001"];  "7" -> ["", "7"];  "ABC" -> no match
            if (!preg_match('/^(.*?)(\d+)$/', trim((string) $value), $m)) {
                continue;
            }

            $prefix = $m[1];
            $digits = $m[2];

            $groups[$prefix] ??= ['count' => 0, 'max' => 0, 'width' => 1];
            $groups[$prefix]['count']++;
            $groups[$prefix]['max'] = max($groups[$prefix]['max'], (int) $digits);
            $groups[$prefix]['width'] = max($groups[$prefix]['width'], strlen($digits));
        }

        if ($groups === []) {
            return '1';
        }

        /*
         * Most common wins, and the plain-number group breaks a tie - a tenant
         * with three EMP001s and one "1" is an organisation that uses EMP, not
         * one that uses bare numbers.
         */
        uksort($groups, function ($a, $b) use ($groups) {
            return [$groups[$b]['count'], $a === '' ? 1 : 0]
                <=> [$groups[$a]['count'], $b === '' ? 1 : 0];
        });

        $prefix = array_key_first($groups);
        $chosen = $groups[$prefix];

        return $prefix . str_pad((string) ($chosen['max'] + 1), $chosen['width'], '0', STR_PAD_LEFT);
    }

    /**
     * Is this employee number already taken inside this organisation?
     *
     * ── WHY THIS IS NOT A UNIQUE INDEX ──────────────────────────────────────
     *
     * Because the data would not survive one. Live carries `EMP001` on eleven
     * rows across five tenants, with a three-way collision in tenant 1, and
     * 282 of 299 people have no number at all. A unique constraint would fail
     * to apply, and inventing numbers for 282 people is a business decision
     * rather than a migration.
     *
     * ── AND WHY IT ONLY CHECKS A CHANGED VALUE ──────────────────────────────
     *
     * Validating unconditionally would make those eleven existing rows
     * uneditable: saving any other field on one of them would be refused
     * because a sibling still holds the same number. So the rule stops NEW
     * duplicates without freezing the records that already have one - which is
     * what lets an administrator fix them at their own pace.
     */
    private function employeeNoTaken(int $tenantId, ?string $employeeNo, ?int $ignoreId = null): bool
    {
        $employeeNo = trim((string) $employeeNo);

        if ($employeeNo === '') {
            return false;
        }

        return DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->where('employee_no', $employeeNo)
            ->whereNull('deleted_at')
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }


}
