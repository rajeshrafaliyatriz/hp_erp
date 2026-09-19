<?php

namespace App\Http\Controllers\Api\Offboarding;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Offboarding\Concerns\ResolvesOffboardingContext;
use App\Models\talent\TalentOffboardingCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OffboardingController extends Controller
{
    use ResolvesOffboardingContext;

    private const SORTABLE = ['last_working_day', 'created_at', 'status', 'exit_reason'];

    private const DEFAULT_CLEARANCE_TASKS = [
        ['id' => 'c1', 'department' => 'IT', 'item' => 'Laptop Return', 'status' => 'Pending'],
        ['id' => 'c2', 'department' => 'IT', 'item' => 'Access Revocation', 'status' => 'Pending'],
        ['id' => 'c3', 'department' => 'IT', 'item' => 'Email Deactivation', 'status' => 'Pending'],
        ['id' => 'c4', 'department' => 'HR', 'item' => 'ID Card Return', 'status' => 'Pending'],
        ['id' => 'c5', 'department' => 'HR', 'item' => 'NDA Signoff', 'status' => 'Pending'],
        ['id' => 'c6', 'department' => 'Finance', 'item' => 'Expense Settlement', 'status' => 'Pending'],
        ['id' => 'c7', 'department' => 'Finance', 'item' => 'Final Dues Calculation', 'status' => 'Pending'],
        ['id' => 'c8', 'department' => 'Admin', 'item' => 'Desk Keys Return', 'status' => 'Pending'],
    ];

    /**
     * The exit paperwork every case starts with.
     *
     * 'Resignation Letter' used to be seeded as fileName 'resignation.pdf',
     * status 'Submitted' - a file that was never uploaded, on every case in
     * every tenant, marked received before the person had sent anything. A
     * checklist that starts one item pre-ticked teaches people to trust the
     * other three less.
     */
    private const DEFAULT_DOCUMENTS = [
        ['id' => 'd1', 'title' => 'Resignation Letter', 'fileName' => null, 'status' => 'Pending', 'isMandatory' => true],
        ['id' => 'd2', 'title' => 'Clearance Certificate', 'fileName' => null, 'status' => 'Pending', 'isMandatory' => true],
        ['id' => 'd3', 'title' => 'Exit Survey Form', 'fileName' => null, 'status' => 'Pending', 'isMandatory' => false],
        ['id' => 'd4', 'title' => 'Signed NDA', 'fileName' => null, 'status' => 'Pending', 'isMandatory' => true],
    ];

    /**
     * GET /api/offboarding/overview
     */
    public function overview(Request $request)
    {
        $context = $this->offboardingContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $departmentId = $this->activeOffbFilter($request->input('department_id'));

        $scope = fn () => DB::table('talent_offboarding_cases')
            ->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at')
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId));

        $totalExits = (clone $scope())->count();
        $resignations = (clone $scope())->where('exit_type', 'voluntary')->count();
        $noticePeriod = (clone $scope())->where('status', 'Notice Period')->count();
        $clearancePending = (clone $scope())->where('status', 'Clearance')->count();
        $exitInterviews = (clone $scope())->where('status', 'Exit Interview')->count();
        $closed = (clone $scope())->where('status', 'Closed')->count();

        $kpis = [
            [
                'id' => 'total-exits',
                'title' => 'Total Exits',
                'value' => (string) $totalExits,
                'subtitle' => 'Exits registered',
                'icon' => 'door-open',
            ],
            [
                'id' => 'resignations',
                'title' => 'Resignations',
                'value' => (string) $resignations,
                'subtitle' => 'Voluntary exits',
                'icon' => 'log-out',
            ],
            [
                'id' => 'notice-period',
                'title' => 'Notice Period',
                'value' => (string) $noticePeriod,
                'subtitle' => 'Active notice',
                'icon' => 'calendar',
            ],
            [
                'id' => 'clearance-pending',
                'title' => 'Clearance Pending',
                'value' => (string) $clearancePending,
                'subtitle' => 'In clearance stage',
                'icon' => 'shield',
            ],
            [
                'id' => 'exit-interviews',
                'title' => 'Exit Interviews',
                'value' => (string) $exitInterviews,
                'subtitle' => 'Pending schedule',
                'icon' => 'users',
            ],
            [
                'id' => 'closed',
                'title' => 'Closed',
                'value' => (string) $closed,
                'subtitle' => 'Fully completed',
                'icon' => 'check-circle',
            ],
        ];

        return $this->offboardingResponse([
            'kpis' => $kpis,
            'totals' => [
                'total_exits' => $totalExits,
                'resignations' => $resignations,
                'notice_period' => $noticePeriod,
                'clearance_pending' => $clearancePending,
                'exit_interviews' => $exitInterviews,
                'closed' => $closed,
            ]
        ]);
    }

    /**
     * GET /api/offboarding/filters
     */
    public function filters(Request $request)
    {
        $context = $this->offboardingContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];

        $departments = DB::table('hrms_departments')
            ->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at')
            ->orderBy('department')
            ->get(['id', 'department'])
            ->map(fn ($row) => ['value' => (string) $row->id, 'label' => $row->department])
            ->all();

        // Get active offboarding cases to exclude them from the dropdown
        $excludedUserIds = DB::table('talent_offboarding_cases')
            ->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at')
            ->where('status', '!=', 'Closed')
            ->pluck('employee_id')
            ->all();

        // Get active users who are candidates for offboarding
        $employees = DB::table('tbluser')
            ->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at')
            ->when(!empty($excludedUserIds), fn ($q) => $q->whereNotIn('id', $excludedUserIds))
            ->orderBy('first_name')
            ->limit(500)
            ->get(['id', 'first_name', 'last_name', 'user_name', 'employee_no', 'department_id', 'joined_date'])
            ->map(function ($user) {
                $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
                return [
                    'value' => (string) $user->id,
                    'label' => $name !== '' ? $name : ($user->user_name ?? 'Unknown'),
                    'employee_no' => $user->employee_no,
                    'department_id' => $user->department_id ? (string) $user->department_id : null,
                    'joined_date' => $user->joined_date,
                ];
            })
            ->all();

        return $this->offboardingResponse([
            'departments' => $departments,
            'employees' => $employees,
            'reasons' => [
                ['value' => 'Better Opportunity', 'label' => 'Better Opportunity'],
                ['value' => 'Career Change', 'label' => 'Career Change'],
                ['value' => 'Personal Reasons', 'label' => 'Personal Reasons'],
                ['value' => 'Relocation', 'label' => 'Relocation'],
                ['value' => 'Health Issues', 'label' => 'Health Issues'],
                ['value' => 'Involuntary/Termination', 'label' => 'Involuntary/Termination'],
            ],
            'exit_types' => [
                ['value' => 'voluntary', 'label' => 'Voluntary'],
                ['value' => 'involuntary', 'label' => 'Involuntary'],
            ],
            'owners' => array_slice($employees, 0, 15) // Simple subset for case owners
        ]);
    }

    /**
     * GET /api/offboarding/cases
     */
    public function index(Request $request)
    {
        $context = $this->offboardingContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        [$sortBy, $sortDir] = $this->offboardingSort($request, self::SORTABLE, 'last_working_day');
        $paging = $this->offboardingPaging($request);

        $departmentId = $this->activeOffbFilter($request->input('department_id'));
        $status = $this->activeOffbFilter($request->input('status'));
        $exitReason = $this->activeOffbFilter($request->input('exit_reason'));
        $exitType = $this->activeOffbFilter($request->input('exit_type'));
        $search = $request->input('search');

        $query = DB::table('talent_offboarding_cases as c')
            ->where('c.sub_institute_id', $tenant)
            ->whereNull('c.deleted_at')
            ->when($departmentId, fn ($q) => $q->where('c.department_id', $departmentId))
            ->when($status, fn ($q) => $q->where('c.status', $status))
            ->when($exitType, fn ($q) => $q->where('c.exit_type', $exitType))
            ->when($exitReason, fn ($q) => $q->where('c.exit_reason', $exitReason));

        if ($search) {
            $query->where(function ($q) use ($search, $tenant) {
                $employeeIds = DB::table('tbluser')
                    ->where('sub_institute_id', $tenant)
                    ->where(DB::raw("CONCAT(first_name, ' ', last_name)"), 'like', "%{$search}%")
                    ->orWhere('user_name', 'like', "%{$search}%")
                    ->orWhere('employee_no', 'like', "%{$search}%")
                    ->pluck('id')
                    ->all();
                
                $q->whereIn('c.employee_id', $employeeIds)
                  ->orWhere('c.exit_reason', 'like', "%{$search}%")
                  ->orWhere('c.location', 'like', "%{$search}%");
            });
        }

        $total = $query->count();

        $cases = $query
            ->orderBy($sortBy, $sortDir)
            ->orderByDesc('id')
            ->forPage($paging['page'], $paging['per_page'])
            ->get();

        $userIds = array_merge(
            $cases->pluck('employee_id')->all(),
            $cases->pluck('manager_id')->all(),
            $cases->pluck('created_by')->all()
        );

        $directory = $this->offboardingDirectory($tenant, $userIds);

        $data = $cases->map(function ($c) use ($directory) {
            $emp = $directory[(int) $c->employee_id] ?? null;
            $mgr = $c->manager_id ? ($directory[(int) $c->manager_id] ?? null) : null;
            $owner = $c->created_by ? ($directory[(int) $c->created_by] ?? null) : null;

            return [
                'id' => (string) $c->id,
                'caseId' => 'EC-' . date('Y', strtotime($c->created_at)) . '-' . str_pad($c->id, 5, '0', STR_PAD_LEFT),
                'employee' => [
                    'name' => $emp ? $emp['name'] : 'Unknown Employee',
                    'id' => $emp ? ($emp['employee_no'] ?: 'EMP' . $c->employee_id) : 'EMP' . $c->employee_id,
                    'initials' => $emp ? $emp['initials'] : '??',
                    'title' => $emp ? $emp['designation'] : 'Staff',
                    'manager' => $mgr ? $mgr['name'] : 'N/A',
                    'doj' => $emp ? date('d M Y', strtotime($emp['joined_date'])) : 'N/A',
                ],
                'department' => $emp ? $emp['department'] : 'N/A',
                'location' => $c->location,
                'exitReason' => $c->exit_reason,
                'exitType' => $c->exit_type,
                'status' => $c->status ?: 'Resignation Submitted',
                'owner' => $owner ? $owner['name'] : 'HR Specialist',
                'updatedOn' => date('d M Y', strtotime($c->updated_at)),
                'noticeDate' => $c->notice_date ? date('Y-m-d', strtotime($c->notice_date)) : null,
                'lastWorkingDay' => $c->last_working_day ? date('d M Y', strtotime($c->last_working_day)) : 'N/A',

                /*
                 * THESE THREE ARE WHY TWO TABS WERE DEAD.
                 *
                 * The Clearance Tracker computes its percentage from
                 * `clearance_tasks` on each LIST row (offboarding-center.tsx:451),
                 * and its four department columns filter the same array (:458).
                 * The Exit Interviews list reads `exit_interview_done` (:1102)
                 * and `exit_interview_date` (:1106). None of the three was in
                 * this projection - only show() returned them.
                 *
                 * The result was not an error. Every case read 0% cleared, each
                 * department column rendered a green "N/A" that looks like a
                 * pass, and every interview said "Pending" / "Not Scheduled" -
                 * including one saved a moment earlier, because the save worked
                 * and the list simply could not see it.
                 *
                 * The row already carries these columns: the query selects `*`.
                 * They were fetched, then dropped on the way out.
                 *
                 * Defaults match show() EXACTLY. If they diverged, a case would
                 * report one progress figure in the list and another in the
                 * drawer, which is worse than reporting none.
                 */
                'clearance_tasks' => $c->clearance_tasks
                    ? json_decode($c->clearance_tasks, true)
                    : self::DEFAULT_CLEARANCE_TASKS,
                'exit_interview_done' => (bool) $c->exit_interview_done,
                'exit_interview_date' => $c->exit_interview_date
                    ? date('Y-m-d', strtotime($c->exit_interview_date))
                    : null,
            ];
        })->all();

        return $this->offboardingResponse($data, 'Success', 200, [
            'pagination' => [
                'page'      => $paging['page'],
                'per_page'  => $paging['per_page'],
                'total'     => $total,
                'last_page' => max(1, (int) ceil($total / $paging['per_page'])),
            ],
        ]);
    }

    /**
     * GET /api/offboarding/cases/{id}
     */
    public function show(Request $request, $id)
    {
        $context = $this->offboardingContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $c = DB::table('talent_offboarding_cases')
            ->where('sub_institute_id', $tenant)
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$c) {
            return $this->offboardingError('Exit case not found', 404);
        }

        $directory = $this->offboardingDirectory($tenant, [$c->employee_id, $c->manager_id, $c->created_by]);
        $emp = $directory[(int) $c->employee_id] ?? null;
        $mgr = $c->manager_id ? ($directory[(int) $c->manager_id] ?? null) : null;
        $owner = $c->created_by ? ($directory[(int) $c->created_by] ?? null) : null;

        // Decode JSON columns, seeding defaults if empty
        $clearanceTasks = $c->clearance_tasks ? json_decode($c->clearance_tasks, true) : self::DEFAULT_CLEARANCE_TASKS;
        $documents = $c->documents ? json_decode($c->documents, true) : self::DEFAULT_DOCUMENTS;
        $comments = $c->comments ? json_decode($c->comments, true) : [];
        $activityLog = $c->activity_log ? json_decode($c->activity_log, true) : [
            [
                'id' => uniqid(),
                'action' => 'Resignation Registered',
                'description' => 'Exit case initialized.',
                'timestamp' => date('d M Y, h:i A', strtotime($c->created_at)),
                'actor' => $owner ? $owner['name'] : 'HR Specialist'
            ]
        ];

        // Seed if null to keep DB columns populated
        if (!$c->clearance_tasks || !$c->documents || !$c->activity_log) {
            DB::table('talent_offboarding_cases')
                ->where('id', $id)
                ->update([
                    'clearance_tasks' => json_encode($clearanceTasks),
                    'documents' => json_encode($documents),
                    'activity_log' => json_encode($activityLog),
                ]);
        }

        $data = [
            'id' => (string) $c->id,
            'caseId' => 'EC-' . date('Y', strtotime($c->created_at)) . '-' . str_pad($c->id, 5, '0', STR_PAD_LEFT),
            'employee' => [
                'name' => $emp ? $emp['name'] : 'Unknown Employee',
                'id' => $emp ? ($emp['employee_no'] ?: 'EMP' . $c->employee_id) : 'EMP' . $c->employee_id,
                'initials' => $emp ? $emp['initials'] : '??',
                'title' => $emp ? $emp['designation'] : 'Staff',
                'manager' => $mgr ? $mgr['name'] : 'N/A',
                'manager_id' => $c->manager_id ? (string) $c->manager_id : null,
                'doj' => $emp ? date('d M Y', strtotime($emp['joined_date'])) : 'N/A',
                'email' => $emp ? $emp['email'] : '',
                'location' => $emp ? $emp['location'] : $c->location,
            ],
            'department' => $emp ? $emp['department'] : 'N/A',
            'department_id' => $emp ? (string) $emp['department_id'] : null,
            'location' => $c->location,
            'exitReason' => $c->exit_reason,
            'exitType' => $c->exit_type ?: 'voluntary',
            'status' => $c->status ?: 'Resignation Submitted',
            'owner' => $owner ? $owner['name'] : 'HR Specialist',
            'updatedOn' => date('d M Y', strtotime($c->updated_at)),
            'noticeDate' => $c->notice_date ? date('Y-m-d', strtotime($c->notice_date)) : null,
            'lastWorkingDay' => $c->last_working_day ? date('d M Y', strtotime($c->last_working_day)) : 'N/A',
            'clearance_tasks' => $clearanceTasks,
            'documents' => $documents,
            'comments' => $comments,
            'activity_log' => $activityLog,
            'exit_interview_done' => (bool) $c->exit_interview_done,
            'exit_interview_date' => $c->exit_interview_date ? date('Y-m-d', strtotime($c->exit_interview_date)) : null,
            'exit_interview_notes' => $c->exit_interview_notes ?: '',
        ];

        return $this->offboardingResponse($data);
    }

    /**
     * POST /api/offboarding/cases
     */
    public function store(Request $request)
    {
        $context = $this->offboardingContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $actorId = $context['user_id'];

        $validated = $request->validate([
            'employee_id' => 'required|integer',
            'exit_type' => 'required|in:voluntary,involuntary',
            'exit_reason' => 'required|string|max:255',
            'notice_date' => 'required|date',
            'last_working_day' => 'required|date|after_or_equal:notice_date',
            'manager_id' => 'nullable|integer',
            'location' => 'nullable|string|max:191',
        ]);

        $employee = DB::table('tbluser')
            ->where('sub_institute_id', $tenant)
            ->where('id', $validated['employee_id'])
            ->first();

        if (!$employee) {
            return $this->offboardingError('Selected employee not found', 404);
        }

        $actor = $actorId ? DB::table('tbluser')->where('id', $actorId)->first() : null;
        $actorName = $actor ? trim($actor->first_name . ' ' . $actor->last_name) : 'HR Specialist';

        // Check if an active case already exists
        $existing = DB::table('talent_offboarding_cases')
            ->where('sub_institute_id', $tenant)
            ->where('employee_id', $validated['employee_id'])
            ->whereNull('deleted_at')
            ->where('status', '!=', 'Closed')
            ->first();

        if ($existing) {
            return $this->offboardingError('An active offboarding case already exists for this employee.', 400);
        }

        $activityLog = [
            [
                'id' => uniqid(),
                'action' => 'Resignation Submitted',
                'description' => 'Resignation submitted by HR on behalf of employee.',
                'timestamp' => date('d M Y, h:i A'),
                'actor' => $actorName
            ]
        ];

        $caseId = DB::table('talent_offboarding_cases')->insertGetId([
            'sub_institute_id' => $tenant,
            'employee_id' => $validated['employee_id'],
            'department_id' => $employee->department_id,
            'location' => $validated['location'] ?? $employee->city ?? 'Main Campus',
            'exit_type' => $validated['exit_type'],
            'exit_reason' => $validated['exit_reason'],
            'notice_date' => $validated['notice_date'],
            'last_working_day' => $validated['last_working_day'],
            'status' => 'Notice Period',
            /*
             * `reporting_manager_id`, not `reportmanager`.
             *
             * `reportmanager` is a column on talent_offers, not on tbluser -
             * this line was copied from the offer code. Because the query above
             * selects the whole row, the undefined property read as null
             * instead of erroring, so the fallback has never once fired.
             *
             * Correcting it changes nothing today: tbluser.reporting_manager_id
             * is populated on 0 of 299 rows on live and 8 of 2372 on the app
             * database, so the fallback still yields null for almost everyone.
             * The manager on the three existing cases came from the request,
             * which is why they have one. This is a latent bug being closed,
             * not a data repair - there is nothing to back-fill.
             */
            'manager_id' => $validated['manager_id'] ?? $employee->reporting_manager_id ?? null,
            'clearance_tasks' => json_encode(self::DEFAULT_CLEARANCE_TASKS),
            'documents' => json_encode(self::DEFAULT_DOCUMENTS),
            'comments' => json_encode([]),
            'activity_log' => json_encode($activityLog),
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->offboardingResponse(['id' => $caseId], 'Exit Case created successfully', 201);
    }

    /**
     * PUT /api/offboarding/cases/{id}
     */
    public function update(Request $request, $id)
    {
        $context = $this->offboardingContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $actorId = $context['user_id'];

        $case = TalentOffboardingCase::where('sub_institute_id', $tenant)->findOrFail($id);

        $validated = $request->validate([
            'exit_reason' => 'nullable|string|max:255',
            'exit_type' => 'nullable|in:voluntary,involuntary',
            'last_working_day' => 'nullable|date',
            'status' => 'nullable|string',
            'manager_id' => 'nullable|integer',
        ]);

        $actor = $actorId ? DB::table('tbluser')->where('id', $actorId)->first() : null;
        $actorName = $actor ? trim($actor->first_name . ' ' . $actor->last_name) : 'HR Specialist';

        $changes = [];
        $activityLog = $case->activity_log ? json_decode($case->activity_log, true) : [];

        foreach ($validated as $field => $val) {
            if ($case->$field != $val && $val !== null) {
                $oldVal = $case->$field;
                $case->$field = $val;
                $changes[] = [
                    'field' => $field,
                    'old' => $oldVal,
                    'new' => $val
                ];

                $activityLog[] = [
                    'id' => uniqid(),
                    'action' => 'Field Updated',
                    'description' => ucfirst(str_replace('_', ' ', $field)) . " changed from '{$oldVal}' to '{$val}'.",
                    'timestamp' => date('d M Y, h:i A'),
                    'actor' => $actorName
                ];
            }
        }

        if (!empty($changes)) {
            $case->activity_log = json_encode($activityLog);
            $case->updated_by = $actorId;
            $case->save();
        }

        return $this->offboardingResponse($case, 'Exit Case updated successfully');
    }

    /**
     * POST /api/offboarding/cases/{id}/status
     */
    public function updateStatus(Request $request, $id)
    {
        $context = $this->offboardingContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $actorId = $context['user_id'];

        $case = TalentOffboardingCase::where('sub_institute_id', $tenant)->findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|string|in:Resignation Submitted,Notice Period,Clearance,Exit Interview,Awaiting F&F,Closed',
        ]);

        $actor = $actorId ? DB::table('tbluser')->where('id', $actorId)->first() : null;
        $actorName = $actor ? trim($actor->first_name . ' ' . $actor->last_name) : 'HR Specialist';

        if ($case->status !== $validated['status']) {
            $oldStatus = $case->status;
            $case->status = $validated['status'];

            $activityLog = $case->activity_log ? json_decode($case->activity_log, true) : [];
            $activityLog[] = [
                'id' => uniqid(),
                'action' => 'Status Changed',
                'description' => "Status changed from '{$oldStatus}' to '{$validated['status']}'.",
                'timestamp' => date('d M Y, h:i A'),
                'actor' => $actorName
            ];
            $case->activity_log = json_encode($activityLog);
            $case->updated_by = $actorId;
            $case->save();
        }

        return $this->offboardingResponse($case, 'Status updated successfully');
    }

    /**
     * POST /api/offboarding/cases/{id}/clearance
     */
    public function updateClearance(Request $request, $id)
    {
        $context = $this->offboardingContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $actorId = $context['user_id'];

        $case = TalentOffboardingCase::where('sub_institute_id', $tenant)->findOrFail($id);

        $validated = $request->validate([
            'tasks' => 'required|array',
            'tasks.*.id' => 'required|string',
            'tasks.*.status' => 'required|in:Pending,Cleared,N/A',
        ]);

        $actor = $actorId ? DB::table('tbluser')->where('id', $actorId)->first() : null;
        $actorName = $actor ? trim($actor->first_name . ' ' . $actor->last_name) : 'HR Specialist';

        $existingTasks = $case->clearance_tasks ? json_decode($case->clearance_tasks, true) : self::DEFAULT_CLEARANCE_TASKS;

        $taskMap = [];
        foreach ($validated['tasks'] as $t) {
            $taskMap[$t['id']] = $t['status'];
        }

        $activityLog = $case->activity_log ? json_decode($case->activity_log, true) : [];
        $hasChanges = false;

        foreach ($existingTasks as &$task) {
            if (isset($taskMap[$task['id']]) && $task['status'] !== $taskMap[$task['id']]) {
                $oldStat = $task['status'];
                $task['status'] = $taskMap[$task['id']];
                $hasChanges = true;

                $activityLog[] = [
                    'id' => uniqid(),
                    'action' => 'Clearance Updated',
                    'description' => "Clearance item '{$task['item']}' ({$task['department']}) marked as {$task['status']}.",
                    'timestamp' => date('d M Y, h:i A'),
                    'actor' => $actorName
                ];
            }
        }

        if ($hasChanges) {
            $case->clearance_tasks = json_encode($existingTasks);
            $case->activity_log = json_encode($activityLog);
            $case->updated_by = $actorId;
            $case->save();
        }

        return $this->offboardingResponse($existingTasks, 'Clearance checklist updated successfully');
    }

    /**
     * POST /api/offboarding/cases/{id}/documents
     */
    /**
     * POST /api/offboarding/cases/{id}/documents/{docId}/upload
     *
     * The real thing. The screen's "Upload Exit Document" dialog had no file
     * input at all - one text box bound to state named `mockFileName` - so a
     * document was marked Submitted on the strength of a typed string.
     *
     * Same storage path, disk and limits as the candidate CV upload in
     * CareersController::apply(), so there is one way to accept a file in this
     * product rather than two that drift.
     */
    public function uploadDocument(Request $request, $id, $docId)
    {
        $context = $this->offboardingContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $actorId = $context['user_id'];

        $case = TalentOffboardingCase::where('sub_institute_id', $tenant)->findOrFail($id);

        $request->validate([
            // Matches the CV upload: the formats HR actually receives, and a cap
            // that is generous for a signed scan without inviting a 40MB TIFF.
            'file' => 'required|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
        ]);

        $documents = $case->documents ? json_decode($case->documents, true) : self::DEFAULT_DOCUMENTS;

        $index = null;
        foreach ($documents as $i => $doc) {
            if ((string) $doc['id'] === (string) $docId) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return $this->offboardingError('That document is not on this exit case.', 404);
        }

        $file = $request->file('file');
        $stored = 'exit_' . $tenant . '_' . $case->id . '_' . $docId . '_' . time()
            . '.' . $file->getClientOriginalExtension();

        try {
            Storage::disk('digitalocean')->putFileAs('public/hp_offboarding_document/', $file, $stored, 'public');
            $url = Storage::disk('digitalocean')->url('public/hp_offboarding_document/' . $stored);
        } catch (\Throwable $e) {
            /*
             * A failed upload must fail the request. Recording the document as
             * Submitted with no file behind it is the exact defect this endpoint
             * exists to remove.
             */
            Log::error('Exit document upload failed: ' . $e->getMessage());

            return $this->offboardingError('The file could not be uploaded. Please try again.', 503);
        }

        $actor = $actorId ? DB::table('tbluser')->where('id', $actorId)->first() : null;
        $actorName = $actor ? trim($actor->first_name . ' ' . $actor->last_name) : 'HR Specialist';

        // The name the person recognises, not the one on disk.
        $documents[$index]['fileName'] = $file->getClientOriginalName();
        $documents[$index]['fileUrl'] = $url;
        $documents[$index]['status'] = 'Submitted';
        $documents[$index]['uploadedAt'] = now()->toDateTimeString();
        $documents[$index]['uploadedBy'] = $actorName;

        $activityLog = $case->activity_log ? json_decode($case->activity_log, true) : [];
        $activityLog[] = [
            'id' => uniqid(),
            'action' => 'Document Uploaded',
            'description' => "'{$documents[$index]['title']}' uploaded ({$documents[$index]['fileName']}).",
            'timestamp' => date('d M Y, h:i A'),
            'actor' => $actorName,
        ];

        $case->documents = json_encode($documents);
        $case->activity_log = json_encode($activityLog);
        $case->updated_by = $actorId;
        $case->save();

        return $this->offboardingResponse($documents, 'Document uploaded.');
    }

    public function updateDocuments(Request $request, $id)
    {
        $context = $this->offboardingContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $actorId = $context['user_id'];

        $case = TalentOffboardingCase::where('sub_institute_id', $tenant)->findOrFail($id);

        $validated = $request->validate([
            'documents' => 'required|array',
            'documents.*.id' => 'required|string',
            'documents.*.status' => 'required|in:Pending,Submitted,Verified,Rejected',
            'documents.*.fileName' => 'nullable|string',
        ]);

        $actor = $actorId ? DB::table('tbluser')->where('id', $actorId)->first() : null;
        $actorName = $actor ? trim($actor->first_name . ' ' . $actor->last_name) : 'HR Specialist';

        $existingDocs = $case->documents ? json_decode($case->documents, true) : self::DEFAULT_DOCUMENTS;

        $docMap = [];
        foreach ($validated['documents'] as $d) {
            $docMap[$d['id']] = $d;
        }

        /*
         * SUBMITTED AND VERIFIED REQUIRE A FILE.
         *
         * This endpoint used to accept any status with a typed fileName, and the
         * screen sent exactly that - a text box, no file input. A whole exit
         * clearance could be marked Submitted and then Verified with nothing
         * uploaded anywhere, which is the kind of trail an audit asks to see.
         *
         * Uploading is now the only route to Submitted (see uploadDocument
         * below); this refuses the shortcut rather than trusting the caller.
         */
        $byId = [];
        foreach ($existingDocs as $doc) {
            $byId[$doc['id']] = $doc;
        }

        foreach ($validated['documents'] as $d) {
            if (!in_array($d['status'], ['Submitted', 'Verified'], true)) {
                continue;
            }
            $current = $byId[$d['id']] ?? null;
            if (!$current || empty($current['fileUrl'])) {
                return $this->offboardingError(
                    'A document cannot be marked ' . $d['status'] . ' before a file has been '
                    . 'uploaded against it. Upload the file first.',
                    422
                );
            }
        }

        $activityLog = $case->activity_log ? json_decode($case->activity_log, true) : [];
        $hasChanges = false;

        foreach ($existingDocs as &$doc) {
            if (isset($docMap[$doc['id']])) {
                $incoming = $docMap[$doc['id']];
                if ($doc['status'] !== $incoming['status'] || (isset($incoming['fileName']) && $doc['fileName'] !== $incoming['fileName'])) {
                    $oldStat = $doc['status'];
                    $doc['status'] = $incoming['status'];
                    if (isset($incoming['fileName'])) {
                        $doc['fileName'] = $incoming['fileName'];
                    }
                    $hasChanges = true;

                    $activityLog[] = [
                        'id' => uniqid(),
                        'action' => 'Document Updated',
                        'description' => "Document '{$doc['title']}' updated to {$doc['status']}.",
                        'timestamp' => date('d M Y, h:i A'),
                        'actor' => $actorName
                    ];
                }
            }
        }

        if ($hasChanges) {
            $case->documents = json_encode($existingDocs);
            $case->activity_log = json_encode($activityLog);
            $case->updated_by = $actorId;
            $case->save();
        }

        return $this->offboardingResponse($existingDocs, 'Documents updated successfully');
    }

    /**
     * POST /api/offboarding/cases/{id}/comments
     */
    public function addComment(Request $request, $id)
    {
        $context = $this->offboardingContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $actorId = $context['user_id'];

        $case = TalentOffboardingCase::where('sub_institute_id', $tenant)->findOrFail($id);

        $validated = $request->validate([
            'comment' => 'required|string',
        ]);

        $actor = $actorId ? DB::table('tbluser')->where('id', $actorId)->first() : null;
        $actorName = $actor ? trim($actor->first_name . ' ' . $actor->last_name) : 'HR Specialist';

        $comments = $case->comments ? json_decode($case->comments, true) : [];
        $newComment = [
            'id' => uniqid(),
            'comment' => $validated['comment'],
            'timestamp' => date('d M Y, h:i A'),
            'author' => $actorName,
            'initials' => $this->offbInitialsOf($actorName),
        ];

        $comments[] = $newComment;
        $case->comments = json_encode($comments);

        $activityLog = $case->activity_log ? json_decode($case->activity_log, true) : [];
        $activityLog[] = [
            'id' => uniqid(),
            'action' => 'Comment Added',
            'description' => "Comment posted by {$actorName}.",
            'timestamp' => date('d M Y, h:i A'),
            'actor' => $actorName
        ];
        $case->activity_log = json_encode($activityLog);

        $case->updated_by = $actorId;
        $case->save();

        return $this->offboardingResponse($comments, 'Comment added successfully');
    }

    /**
     * POST /api/offboarding/cases/{id}/exit-interview
     */
    public function updateExitInterview(Request $request, $id)
    {
        $context = $this->offboardingContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $actorId = $context['user_id'];

        $case = TalentOffboardingCase::where('sub_institute_id', $tenant)->findOrFail($id);

        $validated = $request->validate([
            'exit_interview_done' => 'required|boolean',
            'exit_interview_date' => 'nullable|date',
            'exit_interview_notes' => 'nullable|string',
        ]);

        $actor = $actorId ? DB::table('tbluser')->where('id', $actorId)->first() : null;
        $actorName = $actor ? trim($actor->first_name . ' ' . $actor->last_name) : 'HR Specialist';

        $case->exit_interview_done = $validated['exit_interview_done'];
        $case->exit_interview_date = $validated['exit_interview_date'];
        $case->exit_interview_notes = $validated['exit_interview_notes'];

        $activityLog = $case->activity_log ? json_decode($case->activity_log, true) : [];
        $activityLog[] = [
            'id' => uniqid(),
            'action' => 'Exit Interview Updated',
            'description' => "Exit interview details updated.",
            'timestamp' => date('d M Y, h:i A'),
            'actor' => $actorName
        ];
        $case->activity_log = json_encode($activityLog);

        $case->updated_by = $actorId;
        $case->save();

        return $this->offboardingResponse($case, 'Exit interview updated successfully');
    }

    /**
     * DELETE /api/offboarding/cases/{id}
     */
    public function destroy(Request $request, $id)
    {
        $context = $this->offboardingContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $actorId = $context['user_id'];

        $case = TalentOffboardingCase::where('sub_institute_id', $tenant)->findOrFail($id);
        $case->deleted_by = $actorId;
        $case->save();
        $case->delete();

        return $this->offboardingResponse(['id' => (int) $id], 'Exit Case deleted/withdrawn successfully');
    }
}
