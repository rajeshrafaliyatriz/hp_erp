<?php

namespace App\Http\Controllers\Api\Talent;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Talent\Concerns\ResolvesTalentContext;
use App\Models\talent\OnboardingJourney;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Employee onboarding journeys - the Onboarding Center's register, and the
 * source of the Talent Dashboard's Onboarding KPI card and progress donut.
 *
 * `user_id` on the request is the CONTEXT ACTOR. The new hire travels as
 * `employee_id`, never as `user_id`.
 */
class OnboardingJourneyController extends Controller
{
    use ResolvesTalentContext;

    private const SORTABLE = ['joining_date', 'status', 'stage', 'probation_end', 'created_at'];

    /** GET /api/talent/onboarding/journeys */
    public function index(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        ['page' => $page, 'per_page' => $perPage] = $this->talentPaging($request);
        [$sortBy, $sortDir] = $this->talentSort($request, self::SORTABLE, 'joining_date', 'asc');

        $query = $this->baseQuery($tenant, $request);
        $total = (clone $query)->count('j.id');

        $rows = $query
            ->orderBy('j.' . $sortBy, $sortDir)
            ->orderByDesc('j.id')
            ->forPage($page, $perPage)
            ->get();

        $directory = $this->talentEmployeeDirectory(
            $tenant,
            $rows->pluck('employee_id')->merge($rows->pluck('buddy_id'))->merge($rows->pluck('manager_id'))->all()
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

    /** GET /api/talent/onboarding/journeys/{id} - with its tasks and documents. */
    public function show(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];

        $row = $this->baseQuery($tenant, $request)->where('j.id', $id)->first();

        if (!$row) {
            return $this->talentError('Onboarding journey not found', 404);
        }

        $directory = $this->talentEmployeeDirectory(
            $tenant,
            array_filter([$row->employee_id, $row->buddy_id, $row->manager_id])
        );

        $tasks = DB::table('talent_onboarding_tasks')
            ->where('journey_id', $id)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('due_date')
            ->get();

        $documents = DB::table('talent_onboarding_documents')
            ->where('journey_id', $id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        return $this->talentResponse(array_merge($this->present($row, $directory), [
            'tasks'     => $tasks->map(fn ($task) => $this->presentTask($task))->all(),
            'documents' => $documents->all(),
        ]));
    }

    /** POST /api/talent/onboarding/journeys */
    public function store(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];

        $validated = $request->validate([
            'employee_id'         => 'required|integer',
            'offer_id'            => 'nullable|integer',
            'application_id'      => 'nullable|integer',
            'department_id'       => 'nullable|integer',
            'location'            => 'nullable|string|max:191',
            'position'            => 'nullable|string|max:191',
            'joining_date'        => 'nullable|date',
            'stage'               => 'nullable|in:' . implode(',', OnboardingJourney::STAGES),
            'status'              => 'nullable|in:' . implode(',', OnboardingJourney::STATUSES),
            'probation_start'     => 'nullable|date',
            'probation_end'       => 'nullable|date|after_or_equal:probation_start',
            'confirmation_status' => 'nullable|in:' . implode(',', OnboardingJourney::CONFIRMATION_STATUSES),
            'buddy_id'            => 'nullable|integer',
            'manager_id'          => 'nullable|integer',
            'notes'               => 'nullable|string',
        ]);

        // One live journey per hire - a second would double-count the donut.
        $existing = OnboardingJourney::where('sub_institute_id', $tenant)
            ->where('employee_id', $validated['employee_id'])
            ->where('status', '!=', 'completed')
            ->first();

        if ($existing) {
            return $this->talentError('This employee already has an onboarding journey in progress', 422);
        }

        $journey = OnboardingJourney::create(array_merge($validated, [
            'sub_institute_id'    => $tenant,
            'journey_code'        => $this->nextTalentCode('talent_onboarding_journeys', 'journey_code', 'OHB', $tenant),
            'department_id'       => $validated['department_id'] ?? $this->departmentOf($tenant, (int) $validated['employee_id']),
            'stage'               => $validated['stage'] ?? 'preboarding',
            'status'              => $validated['status'] ?? 'not-started',
            'confirmation_status' => $validated['confirmation_status'] ?? 'pending',
            'created_by'          => $context['user_id'],
            'updated_by'          => $context['user_id'],
        ]));

        return $this->talentResponse($this->find($tenant, $journey->id), 'Onboarding journey created', 201);
    }

    /** PUT /api/talent/onboarding/journeys/{id} */
    public function update(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $journey = OnboardingJourney::where('sub_institute_id', $tenant)->find($id);

        if (!$journey) {
            return $this->talentError('Onboarding journey not found', 404);
        }

        $validated = $request->validate([
            'department_id'       => 'nullable|integer',
            'location'            => 'nullable|string|max:191',
            'position'            => 'nullable|string|max:191',
            'joining_date'        => 'nullable|date',
            'stage'               => 'nullable|in:' . implode(',', OnboardingJourney::STAGES),
            'status'              => 'nullable|in:' . implode(',', OnboardingJourney::STATUSES),
            'probation_start'     => 'nullable|date',
            'probation_end'       => 'nullable|date',
            'confirmation_status' => 'nullable|in:' . implode(',', OnboardingJourney::CONFIRMATION_STATUSES),
            'confirmation_notes'  => 'nullable|string',
            'buddy_id'            => 'nullable|integer',
            'manager_id'          => 'nullable|integer',
            'notes'               => 'nullable|string',
        ]);

        // employee_id is deliberately not accepted: reassigning a journey to a
        // different hire would silently rewrite history rather than create one.
        $journey->fill($validated);

        if (($validated['status'] ?? null) === 'completed' && !$journey->completed_at) {
            $journey->completed_at = now()->toDateString();
        }

        $journey->updated_by = $context['user_id'];
        $journey->save();

        return $this->talentResponse($this->find($tenant, $journey->id), 'Onboarding journey updated');
    }

    /**
     * POST /api/talent/onboarding/journeys/{id}/complete
     *
     * Closing the journey also closes any task still open, so the dashboard's
     * "Onboarding Tasks Pending" count cannot keep counting a finished hire.
     */
    public function complete(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $journey = OnboardingJourney::where('sub_institute_id', $tenant)->find($id);

        if (!$journey) {
            return $this->talentError('Onboarding journey not found', 404);
        }

        DB::transaction(function () use ($journey, $context) {
            $journey->status = 'completed';
            $journey->stage = 'confirmed';
            $journey->completed_at = now()->toDateString();
            // Reaching 'confirmed' IS the probation outcome, so the two fields
            // are written together and cannot drift apart.
            $journey->confirmation_status = 'confirmed';
            $journey->confirmed_on = now()->toDateString();
            $journey->confirmed_by = $context['user_id'];
            $journey->updated_by = $context['user_id'];
            $journey->save();

            DB::table('talent_onboarding_tasks')
                ->where('journey_id', $journey->id)
                ->whereNull('deleted_at')
                ->where('status', '!=', 'completed')
                ->update([
                    'status'       => 'completed',
                    'completed_at' => now(),
                    'updated_by'   => $context['user_id'],
                    'updated_at'   => now(),
                ]);
        });

        return $this->talentResponse($this->find($tenant, $journey->id), 'Onboarding completed');
    }

    /** DELETE /api/talent/onboarding/journeys/{id} */
    public function destroy(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $journey = OnboardingJourney::where('sub_institute_id', $tenant)->find($id);

        if (!$journey) {
            return $this->talentError('Onboarding journey not found', 404);
        }

        DB::transaction(function () use ($journey, $context) {
            $journey->deleted_by = $context['user_id'];
            $journey->save();
            $journey->delete();

            // Children go with the parent, otherwise their rows keep feeding the
            // dashboard's pending-task count for a journey that no longer exists.
            foreach (['talent_onboarding_tasks', 'talent_onboarding_documents'] as $table) {
                DB::table($table)
                    ->where('journey_id', $journey->id)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => now(), 'deleted_by' => $context['user_id']]);
            }
        });

        return $this->talentResponse(null, 'Onboarding journey deleted');
    }

    /* -- internals -------------------------------------------------------- */

    private function baseQuery(int $tenant, Request $request)
    {
        $status = $this->activeTalentFilter($request->input('status'));
        $stage = $this->activeTalentFilter($request->input('stage'));
        $departmentId = $this->activeTalentFilter($request->input('department_id'));
        $location = $this->activeTalentFilter($request->input('location'));
        $search = $this->activeTalentFilter($request->input('search'));

        return DB::table('talent_onboarding_journeys as j')
            ->leftJoin('hrms_departments as d', 'd.id', '=', 'j.department_id')
            ->leftJoin('tbluser as u', 'u.id', '=', 'j.employee_id')
            ->select('j.*', 'd.department as department_name')
            ->where('j.sub_institute_id', $tenant)
            ->whereNull('j.deleted_at')
            ->when($status, fn ($q) => $q->where('j.status', $status))
            ->when($stage, fn ($q) => $q->where('j.stage', $stage))
            ->when($departmentId, fn ($q) => $q->where('j.department_id', $departmentId))
            ->when($location, fn ($q) => $q->where('j.location', $location))
            ->when($search, fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('j.position', 'like', "%{$search}%")
                    ->orWhere('j.journey_code', 'like', "%{$search}%")
                    // candidate_name covers hires who have no tbluser row yet.
                    ->orWhere('j.candidate_name', 'like', "%{$search}%")
                    ->orWhere('u.first_name', 'like', "%{$search}%")
                    ->orWhere('u.last_name', 'like', "%{$search}%")
                    ->orWhere('u.employee_no', 'like', "%{$search}%");
            }));
    }

    /** Re-read a row through the presenter, unfiltered, after a write. */
    private function find(int $tenant, $id): array
    {
        $row = $this->baseQuery($tenant, new Request())->where('j.id', $id)->first();
        $directory = $this->talentEmployeeDirectory(
            $tenant,
            array_filter([$row->employee_id ?? null, $row->buddy_id ?? null, $row->manager_id ?? null])
        );

        return $this->present($row, $directory);
    }

    private function present($row, array $directory): array
    {
        $employee = $directory[(int) $row->employee_id] ?? null;

        return [
            'id'                        => (int) $row->id,
            'journey_code'              => $row->journey_code,
            'employee_id'               => (int) $row->employee_id,
            'employee'                  => $employee,
            // candidate_* carry the hire's details before a tbluser row exists.
            'employee_name'             => $employee['name'] ?? $row->candidate_name,
            'candidate_email'           => $row->candidate_email,
            'candidate_phone'           => $row->candidate_phone,
            'offer_id'                  => $row->offer_id ? (int) $row->offer_id : null,
            'application_id'            => $row->application_id ? (int) $row->application_id : null,
            'department_id'             => $row->department_id ? (int) $row->department_id : null,
            'department'                => $row->department_name,
            'location'                  => $row->location,
            'position'                  => $row->position,
            'joining_date'              => $row->joining_date,
            'joining_date_label'        => $this->talentDateLabel($row->joining_date),
            'stage'                     => $row->stage,
            'stage_label'               => $this->talentLabel($row->stage),
            'status'                    => $row->status,
            'status_label'              => $this->talentLabel($row->status),
            'probation_start'           => $row->probation_start,
            'probation_end'             => $row->probation_end,
            'probation_end_label'       => $this->talentDateLabel($row->probation_end),
            'extension_end'             => $row->extension_end,
            'confirmation_status'       => $row->confirmation_status,
            'confirmation_status_label' => $this->talentLabel($row->confirmation_status),
            'confirmed_on'              => $row->confirmed_on,
            'confirmation_notes'        => $row->confirmation_notes,
            'buddy'                     => $row->buddy_id ? ($directory[(int) $row->buddy_id] ?? null) : null,
            'manager'                   => $row->manager_id ? ($directory[(int) $row->manager_id] ?? null) : null,
            'completed_at'              => $row->completed_at,
            'notes'                     => $row->notes,
            'created_at'                => $row->created_at,
            'updated_at'                => $row->updated_at,
        ];
    }

    private function presentTask($task): array
    {
        return [
            'id'             => (int) $task->id,
            'journey_id'     => (int) $task->journey_id,
            'title'          => $task->title,
            'description'    => $task->description,
            'category'       => $task->category,
            'category_label' => $this->talentLabel($task->category),
            'owner_id'       => $task->owner_id ? (int) $task->owner_id : null,
            'owner_label'    => $task->owner_label,
            'due_date'       => $task->due_date,
            'due_date_label' => $this->talentDateLabel($task->due_date),
            'status'         => $task->status,
            'status_label'   => $this->talentLabel($task->status),
            'completed_at'   => $task->completed_at,
            'sort_order'     => (int) $task->sort_order,
        ];
    }

    /** The Onboarding Center's five KPI cards. */
    private function summary(int $tenant, Request $request): array
    {
        $scope = fn () => $this->baseQuery($tenant, $request);
        $today = now()->toDateString();

        $completed = (clone $scope())->where('j.status', 'completed')->count();
        $total = (clone $scope())->count();

        return [
            'preboarding_pending' => (clone $scope())->where('j.stage', 'preboarding')->count(),
            'active_onboarding'   => (clone $scope())->where('j.status', '!=', 'completed')->count(),
            'first_day_this_week' => (clone $scope())
                ->whereBetween('j.joining_date', [$today, now()->addDays(7)->toDateString()])
                ->count(),
            'completion_pct'      => $total > 0 ? (int) round($completed / $total * 100) : 0,
            'probation_due'       => (clone $scope())
                ->whereNotNull('j.probation_end')
                ->whereBetween('j.probation_end', [$today, now()->addDays(30)->toDateString()])
                ->count(),
            'total'               => $total,
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
