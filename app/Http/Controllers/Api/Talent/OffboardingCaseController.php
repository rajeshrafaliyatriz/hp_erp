<?php

namespace App\Http\Controllers\Api\Talent;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Talent\Concerns\ResolvesTalentContext;
use App\Models\talent\OffboardingCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Exit cases - one per departing employee, from resignation to full & final.
 *
 * Backs the Offboarding Center's exit register and the Talent Dashboard's
 * Offboarding KPI card and "Initiate Exit" quick link.
 *
 * `user_id` on the request is the CONTEXT ACTOR. The departing employee travels
 * as `employee_id`, and the HR owner as `owner_id`.
 */
class OffboardingCaseController extends Controller
{
    use ResolvesTalentContext;

    private const SORTABLE = ['last_working_day', 'resignation_date', 'status', 'exit_type', 'created_at'];

    /**
     * The clearance checklist created with every new case.
     *
     * Seeded here rather than left to the caller so no case can reach the
     * clearance stage with nothing to clear - which would make the dashboard's
     * "Clearances Pending" count silently under-report.
     */
    private const DEFAULT_CLEARANCES = [
        ['clearance_type' => 'manager', 'title' => 'Manager handover sign-off'],
        ['clearance_type' => 'it',      'title' => 'IT assets and access revocation'],
        ['clearance_type' => 'finance', 'title' => 'Finance dues and advances'],
        ['clearance_type' => 'admin',   'title' => 'Admin assets and ID card'],
        ['clearance_type' => 'hr',      'title' => 'HR documentation and exit formalities'],
    ];

    /** GET /api/talent/offboarding/cases */
    public function index(Request $request)
    {
        \Log::info('Offboarding cases requested', $request->all());
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        ['page' => $page, 'per_page' => $perPage] = $this->talentPaging($request);
        [$sortBy, $sortDir] = $this->talentSort($request, self::SORTABLE, 'last_working_day', 'asc');

        $query = $this->baseQuery($tenant, $request);
        $total = (clone $query)->count('c.id');

        $rows = $query
            ->orderBy('c.' . $sortBy, $sortDir)
            ->orderByDesc('c.id')
            ->forPage($page, $perPage)
            ->get();

        $directory = $this->talentEmployeeDirectory(
            $tenant,
            $rows->pluck('employee_id')->merge($rows->pluck('manager_id'))->merge($rows->pluck('owner_id'))->all()
        );

        return $this->talentResponse(
            $rows->map(fn ($row) => $this->present($row, $directory))->all(),
            'Success',
            200,
            [
                'pagination' => [
                    'page'      => $page,
                    'per_page'  => $perPage,
                    'total'     => $total,
                    'last_page' => (int) max(1, ceil($total / $perPage)),
                ],
                'summary' => $this->summary($tenant, $request),
            ]
        );
    }

    /** GET /api/talent/offboarding/cases/{id} - with clearances and interview. */
    public function show(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $row = $this->baseQuery($tenant, new Request())->where('c.id', $id)->first();

        if (!$row) {
            return $this->talentError('Exit case not found', 404);
        }

        $clearances = DB::table('talent_offboarding_clearances')
            ->where('case_id', $id)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $interview = DB::table('talent_exit_interviews')
            ->where('case_id', $id)
            ->whereNull('deleted_at')
            ->first();

        $directory = $this->talentEmployeeDirectory(
            $tenant,
            array_filter([$row->employee_id, $row->manager_id, $row->owner_id])
        );

        return $this->talentResponse(array_merge($this->present($row, $directory), [
            'clearances'     => $clearances->all(),
            'exit_interview' => $interview,
        ]));
    }

    /** POST /api/talent/offboarding/cases */
    public function store(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];

        $validated = $request->validate([
            'employee_id'        => 'required|integer',
            'department_id'      => 'nullable|integer',
            'designation'        => 'nullable|string|max:191',
            'manager_id'         => 'nullable|integer',
            'owner_id'           => 'nullable|integer',
            'exit_type'          => 'nullable|in:' . implode(',', OffboardingCase::EXIT_TYPES),
            'exit_reason'        => 'nullable|string|max:191',
            'resignation_date'   => 'nullable|date',
            'last_working_day'   => 'nullable|date|after_or_equal:resignation_date',
            'notice_period_days' => 'nullable|integer|min:0|max:365',
            'notes'              => 'nullable|string',
            'skip_default_clearances' => 'nullable|boolean',
        ]);

        // One live case per employee - a second would double-count every exit KPI.
        $existing = OffboardingCase::where('sub_institute_id', $tenant)
            ->where('employee_id', $validated['employee_id'])
            ->whereIn('status', OffboardingCase::ACTIVE_STATUSES)
            ->exists();

        if ($existing) {
            return $this->talentError('This employee already has an exit case in progress', 422);
        }

        $skipDefaults = (bool) ($validated['skip_default_clearances'] ?? false);
        unset($validated['skip_default_clearances']);

        $case = DB::transaction(function () use ($validated, $tenant, $context, $skipDefaults) {
            $case = OffboardingCase::create(array_merge($validated, [
                'sub_institute_id' => $tenant,
                'case_code'        => $this->nextTalentCode('talent_offboarding_cases', 'case_code', 'EC', $tenant),
                'department_id'    => $validated['department_id'] ?? $this->departmentOf($tenant, (int) $validated['employee_id']),
                'exit_type'        => $validated['exit_type'] ?? 'resignation',
                'resignation_date' => $validated['resignation_date'] ?? now()->toDateString(),
                'status'           => 'resignation-submitted',
                'fnf_status'       => 'pending',
                'created_by'       => $context['user_id'],
                'updated_by'       => $context['user_id'],
            ]));

            if (!$skipDefaults) {
                $now = now();
                DB::table('talent_offboarding_clearances')->insert(
                    array_map(fn ($clearance, $index) => array_merge($clearance, [
                        'case_id'          => $case->id,
                        'sub_institute_id' => $tenant,
                        'status'           => 'pending',
                        'due_date'         => $case->last_working_day,
                        'sort_order'       => $index,
                        'created_by'       => $context['user_id'],
                        'updated_by'       => $context['user_id'],
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ]), self::DEFAULT_CLEARANCES, array_keys(self::DEFAULT_CLEARANCES))
                );
            }

            return $case;
        });

        return $this->talentResponse($this->hydrate($tenant, $case->id), 'Exit case created', 201);
    }

    /** PUT /api/talent/offboarding/cases/{id} */
    public function update(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $case = OffboardingCase::where('sub_institute_id', $tenant)->find($id);

        if (!$case) {
            return $this->talentError('Exit case not found', 404);
        }

        $validated = $request->validate([
            'department_id'            => 'nullable|integer',
            'designation'              => 'nullable|string|max:191',
            'manager_id'               => 'nullable|integer',
            'owner_id'                 => 'nullable|integer',
            'exit_type'                => 'nullable|in:' . implode(',', OffboardingCase::EXIT_TYPES),
            'exit_reason'              => 'nullable|string|max:191',
            'resignation_date'         => 'nullable|date',
            'last_working_day'         => 'nullable|date',
            'revised_last_working_day' => 'nullable|date',
            'notice_period_days'       => 'nullable|integer|min:0|max:365',
            'fnf_status'               => 'nullable|in:' . implode(',', OffboardingCase::FNF_STATUSES),
            'fnf_settled_on'           => 'nullable|date',
            'notes'                    => 'nullable|string',
        ]);

        // employee_id and status are not accepted here: the first would reassign
        // the exit, the second bypasses the stage guards in advance().
        $case->fill($validated);
        $case->updated_by = $context['user_id'];
        $case->save();

        return $this->talentResponse($this->hydrate($tenant, $case->id), 'Exit case updated');
    }

    /**
     * POST /api/talent/offboarding/cases/{id}/advance
     *
     * Move the case to its next stage. Separate from update() because the
     * transitions are guarded: a case cannot skip clearance while sign-offs are
     * still open, and closing writes closed_at.
     */
    public function advance(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $case = OffboardingCase::where('sub_institute_id', $tenant)->find($id);

        if (!$case) {
            return $this->talentError('Exit case not found', 404);
        }

        $validated = $request->validate([
            'status' => 'required|in:' . implode(',', OffboardingCase::STATUSES),
            'notes'  => 'nullable|string',
        ]);

        $target = $validated['status'];

        if (in_array($case->status, ['closed', 'cancelled'], true)) {
            return $this->talentError('This case is already closed', 422);
        }

        // Closing with an unsigned clearance is the failure the register exists
        // to prevent, so it is refused rather than warned about.
        if (in_array($target, ['awaiting-fnf', 'closed'], true)) {
            $openClearances = DB::table('talent_offboarding_clearances')
                ->where('case_id', $case->id)
                ->whereNull('deleted_at')
                ->whereIn('status', ['pending', 'in-progress'])
                ->count();

            if ($openClearances > 0) {
                return $this->talentError(
                    "This case still has {$openClearances} clearance(s) outstanding",
                    422,
                    ['pending_clearances' => $openClearances]
                );
            }
        }

        $case->status = $target;

        if (!empty($validated['notes'])) {
            $case->notes = $validated['notes'];
        }

        if ($target === 'closed') {
            $case->closed_at = now();
            $case->fnf_status = $case->fnf_status === 'settled' ? 'settled' : 'processing';
        }

        $case->updated_by = $context['user_id'];
        $case->save();

        return $this->talentResponse($this->hydrate($tenant, $case->id), 'Exit case moved to ' . $this->talentLabel($target));
    }

    /** DELETE /api/talent/offboarding/cases/{id} */
    public function destroy(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $case = OffboardingCase::where('sub_institute_id', $tenant)->find($id);

        if (!$case) {
            return $this->talentError('Exit case not found', 404);
        }

        DB::transaction(function () use ($case, $context) {
            $case->deleted_by = $context['user_id'];
            $case->save();
            $case->delete();

            // Children go with the parent, or their rows keep feeding the
            // dashboard's clearance count for a case that no longer exists.
            foreach (['talent_offboarding_clearances', 'talent_exit_interviews'] as $table) {
                DB::table($table)
                    ->where('case_id', $case->id)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => now(), 'deleted_by' => $context['user_id']]);
            }
        });

        return $this->talentResponse(null, 'Exit case deleted');
    }

    /* -- internals -------------------------------------------------------- */

    private function baseQuery(int $tenant, Request $request)
    {
        $status = $this->activeTalentFilter($request->input('status'));
        $exitType = $this->activeTalentFilter($request->input('exit_type'));
        $departmentId = $this->activeTalentFilter($request->input('department_id'));
        $ownerId = $this->activeTalentFilter($request->input('owner_id'));
        $from = $this->activeTalentFilter($request->input('from'));
        $to = $this->activeTalentFilter($request->input('to'));
        $search = $this->activeTalentFilter($request->input('search'));

        return DB::table('talent_offboarding_cases as c')
            ->leftJoin('hrms_departments as d', 'd.id', '=', 'c.department_id')
            ->leftJoin('tbluser as u', 'u.id', '=', 'c.employee_id')
            ->select('c.*', 'd.department as department_name')
            ->where('c.sub_institute_id', $tenant)
            ->whereNull('c.deleted_at')
            ->when($status, fn ($q) => $q->where('c.status', $status))
            ->when($exitType, fn ($q) => $q->where('c.exit_type', $exitType))
            ->when($departmentId, fn ($q) => $q->where('c.department_id', $departmentId))
            ->when($ownerId, fn ($q) => $q->where('c.owner_id', $ownerId))
            ->when($from, fn ($q) => $q->whereDate('c.last_working_day', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('c.last_working_day', '<=', $to))
            ->when($search, fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('c.case_code', 'like', "%{$search}%")
                    ->orWhere('u.first_name', 'like', "%{$search}%")
                    ->orWhere('u.last_name', 'like', "%{$search}%")
                    ->orWhere('u.employee_no', 'like', "%{$search}%");
            }));
    }

    private function hydrate(int $tenant, $id): ?array
    {
        $row = $this->baseQuery($tenant, new Request())->where('c.id', $id)->first();

        if (!$row) {
            return null;
        }

        $directory = $this->talentEmployeeDirectory(
            $tenant,
            array_filter([$row->employee_id, $row->manager_id, $row->owner_id])
        );

        return $this->present($row, $directory);
    }

    private function present($row, array $directory): array
    {
        $employee = $directory[(int) $row->employee_id] ?? null;

        return [
            'id'                       => (int) $row->id,
            'case_code'                => $row->case_code,
            'employee_id'              => (int) $row->employee_id,
            'employee'                 => $employee,
            'employee_name'            => $employee['name'] ?? null,
            'department_id'            => $row->department_id ? (int) $row->department_id : null,
            'department'               => $row->department_name,
            'designation'              => $row->designation ?: ($employee['designation'] ?? null),
            'manager'                  => $row->manager_id ? ($directory[(int) $row->manager_id] ?? null) : null,
            'owner'                    => $row->owner_id ? ($directory[(int) $row->owner_id] ?? null) : null,
            'exit_type'                => $row->exit_type,
            'exit_type_label'          => $this->talentLabel($row->exit_type),
            'exit_reason'              => $row->exit_reason,
            'resignation_date'         => $row->resignation_date,
            'last_working_day'         => $row->last_working_day,
            'last_working_day_label'   => $this->talentDateLabel($row->last_working_day),
            'revised_last_working_day' => $row->revised_last_working_day,
            'notice_period_days'       => $row->notice_period_days ? (int) $row->notice_period_days : null,
            'status'                   => $row->status,
            'status_label'             => $this->talentLabel($row->status),
            'fnf_status'               => $row->fnf_status,
            'fnf_settled_on'           => $row->fnf_settled_on,
            'notes'                    => $row->notes,
            'closed_at'                => $row->closed_at,
            'created_at'               => $row->created_at,
            'updated_at'               => $row->updated_at,
        ];
    }

    /** The Offboarding Center's six KPI cards. */
    private function summary(int $tenant, Request $request): array
    {
        $scope = fn () => $this->baseQuery($tenant, $request);

        return [
            'total_exits'       => (clone $scope())->count(),
            'resignations'      => (clone $scope())->where('c.exit_type', 'resignation')->count(),
            'notice_period'     => (clone $scope())->where('c.status', 'notice-period')->count(),
            'clearance_pending' => DB::table('talent_offboarding_clearances as cl')
                ->join('talent_offboarding_cases as c', 'c.id', '=', 'cl.case_id')
                ->where('cl.sub_institute_id', $tenant)
                ->whereNull('cl.deleted_at')
                ->whereNull('c.deleted_at')
                ->whereIn('cl.status', ['pending', 'in-progress'])
                ->count(),
            'exit_interviews'   => (clone $scope())->where('c.status', 'exit-interview')->count(),
            'closed'            => (clone $scope())->where('c.status', 'closed')->count(),
        ];
    }

    private function departmentOf(int $tenant, int $userId): ?int
    {
        $departmentId = DB::table('tbluser')
            ->where('sub_institute_id', $tenant)
            ->where('id', $userId)
            ->value('department_id');

        return $departmentId ? (int) $departmentId : null;
    }
}
