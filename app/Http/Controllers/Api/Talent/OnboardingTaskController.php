<?php

namespace App\Http\Controllers\Api\Talent;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Talent\Concerns\ResolvesTalentContext;
use App\Models\talent\OnboardingTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The individual checklist items on an onboarding journey - the Preboarding
 * Tasks table and the Integrations panel, and the source of the Talent
 * Dashboard's "Onboarding Tasks Pending" action item.
 */
class OnboardingTaskController extends Controller
{
    use ResolvesTalentContext;

    private const SORTABLE = ['due_date', 'status', 'category', 'sort_order', 'created_at'];

    /** GET /api/talent/onboarding/tasks */
    public function index(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        ['page' => $page, 'per_page' => $perPage] = $this->talentPaging($request, 50);
        [$sortBy, $sortDir] = $this->talentSort($request, self::SORTABLE, 'sort_order', 'asc');

        $query = $this->baseQuery($tenant, $request);
        $total = (clone $query)->count('t.id');

        $rows = $query
            ->orderBy('t.' . $sortBy, $sortDir)
            ->orderByDesc('t.id')
            ->forPage($page, $perPage)
            ->get();

        $directory = $this->talentEmployeeDirectory($tenant, $rows->pluck('owner_id')->all());

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
                'summary' => [
                    'pending'     => (clone $this->baseQuery($tenant, $request))->where('t.status', 'pending')->count(),
                    'in_progress' => (clone $this->baseQuery($tenant, $request))->where('t.status', 'in-progress')->count(),
                    'completed'   => (clone $this->baseQuery($tenant, $request))->where('t.status', 'completed')->count(),
                    'overdue'     => (clone $this->baseQuery($tenant, $request))
                        ->where('t.status', '!=', 'completed')
                        ->whereNotNull('t.due_date')
                        ->whereDate('t.due_date', '<', now()->toDateString())
                        ->count(),
                ],
            ]
        );
    }

    /** POST /api/talent/onboarding/tasks */
    public function store(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];

        $validated = $request->validate([
            'journey_id'  => 'required|integer',
            'title'       => 'required|string|max:191',
            'description' => 'nullable|string',
            'category'    => 'nullable|in:' . implode(',', OnboardingTask::CATEGORIES),
            'owner_id'    => 'nullable|integer',
            'owner_label' => 'nullable|string|max:100',
            'due_date'    => 'nullable|date',
            'status'      => 'nullable|in:' . implode(',', OnboardingTask::STATUSES),
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        if (!$this->journeyExists($tenant, (int) $validated['journey_id'])) {
            return $this->talentError('Onboarding journey not found', 404);
        }

        $task = OnboardingTask::create(array_merge($validated, [
            'sub_institute_id' => $tenant,
            'category'         => $validated['category'] ?? 'other',
            'status'           => $validated['status'] ?? 'pending',
            'sort_order'       => $validated['sort_order'] ?? 0,
            'created_by'       => $context['user_id'],
            'updated_by'       => $context['user_id'],
        ]));

        $this->syncJourneyProgress($tenant, (int) $task->journey_id, $context['user_id']);

        return $this->talentResponse($this->hydrate($tenant, $task->id), 'Task created', 201);
    }

    /** PUT /api/talent/onboarding/tasks/{id} */
    public function update(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $task = OnboardingTask::where('sub_institute_id', $tenant)->find($id);

        if (!$task) {
            return $this->talentError('Task not found', 404);
        }

        $validated = $request->validate([
            'title'       => 'sometimes|required|string|max:191',
            'description' => 'nullable|string',
            'category'    => 'nullable|in:' . implode(',', OnboardingTask::CATEGORIES),
            'owner_id'    => 'nullable|integer',
            'owner_label' => 'nullable|string|max:100',
            'due_date'    => 'nullable|date',
            'status'      => 'nullable|in:' . implode(',', OnboardingTask::STATUSES),
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $task->fill($validated);

        // completed_at tracks the transition, so it is set once and cleared if
        // the task is reopened - never left stale on a re-pended row.
        if (($validated['status'] ?? null) === 'completed') {
            $task->completed_at = $task->completed_at ?: now();
        } elseif (array_key_exists('status', $validated)) {
            $task->completed_at = null;
        }

        $task->updated_by = $context['user_id'];
        $task->save();

        $this->syncJourneyProgress($tenant, (int) $task->journey_id, $context['user_id']);

        return $this->talentResponse($this->hydrate($tenant, $task->id), 'Task updated');
    }

    /** POST /api/talent/onboarding/tasks/{id}/complete */
    public function complete(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $task = OnboardingTask::where('sub_institute_id', $tenant)->find($id);

        if (!$task) {
            return $this->talentError('Task not found', 404);
        }

        $task->status = 'completed';
        $task->completed_at = now();
        $task->updated_by = $context['user_id'];
        $task->save();

        $this->syncJourneyProgress($tenant, (int) $task->journey_id, $context['user_id']);

        return $this->talentResponse($this->hydrate($tenant, $task->id), 'Task completed');
    }

    /** DELETE /api/talent/onboarding/tasks/{id} */
    public function destroy(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $task = OnboardingTask::where('sub_institute_id', $tenant)->find($id);

        if (!$task) {
            return $this->talentError('Task not found', 404);
        }

        $journeyId = (int) $task->journey_id;

        $task->deleted_by = $context['user_id'];
        $task->save();
        $task->delete();

        $this->syncJourneyProgress($tenant, $journeyId, $context['user_id']);

        return $this->talentResponse(null, 'Task deleted');
    }

    /* -- internals -------------------------------------------------------- */

    private function baseQuery(int $tenant, Request $request)
    {
        $journeyId = $this->activeTalentFilter($request->input('journey_id'));
        $status = $this->activeTalentFilter($request->input('status'));
        $category = $this->activeTalentFilter($request->input('category'));
        $ownerId = $this->activeTalentFilter($request->input('owner_id'));
        $search = $this->activeTalentFilter($request->input('search'));

        return DB::table('talent_onboarding_tasks as t')
            ->join('talent_onboarding_journeys as j', 'j.id', '=', 't.journey_id')
            ->select('t.*', 'j.employee_id', 'j.position')
            ->where('t.sub_institute_id', $tenant)
            ->whereNull('t.deleted_at')
            ->whereNull('j.deleted_at')
            ->when($journeyId, fn ($q) => $q->where('t.journey_id', $journeyId))
            ->when($status, fn ($q) => $q->where('t.status', $status))
            ->when($category, fn ($q) => $q->where('t.category', $category))
            ->when($ownerId, fn ($q) => $q->where('t.owner_id', $ownerId))
            ->when($search, fn ($q) => $q->where('t.title', 'like', "%{$search}%"));
    }

    private function hydrate(int $tenant, $id): ?array
    {
        $row = DB::table('talent_onboarding_tasks as t')
            ->join('talent_onboarding_journeys as j', 'j.id', '=', 't.journey_id')
            ->select('t.*', 'j.employee_id', 'j.position')
            ->where('t.sub_institute_id', $tenant)
            ->where('t.id', $id)
            ->first();

        if (!$row) {
            return null;
        }

        return $this->present($row, $this->talentEmployeeDirectory($tenant, [$row->owner_id]));
    }

    private function present($row, array $directory): array
    {
        return [
            'id'             => (int) $row->id,
            'journey_id'     => (int) $row->journey_id,
            'employee_id'    => isset($row->employee_id) ? (int) $row->employee_id : null,
            'title'          => $row->title,
            'description'    => $row->description,
            'category'       => $row->category,
            'category_label' => $this->talentLabel($row->category),
            'owner_id'       => $row->owner_id ? (int) $row->owner_id : null,
            'owner'          => $row->owner_id ? ($directory[(int) $row->owner_id] ?? null) : null,
            'owner_label'    => $row->owner_label ?: ($row->owner_id ? ($directory[(int) $row->owner_id]['name'] ?? null) : null),
            'due_date'       => $row->due_date,
            'due_date_label' => $this->talentDateLabel($row->due_date),
            'status'         => $row->status,
            'status_label'   => $this->talentLabel($row->status),
            'completed_at'   => $row->completed_at,
            'sort_order'     => (int) $row->sort_order,
        ];
    }

    private function journeyExists(int $tenant, int $journeyId): bool
    {
        return DB::table('talent_onboarding_journeys')
            ->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at')
            ->where('id', $journeyId)
            ->exists();
    }

    /**
     * Keep the journey's status in step with its tasks.
     *
     * Without this the Onboarding Progress donut and the task list can
     * disagree - a journey sitting at 'not-started' while every task under it
     * is done. The journey is never auto-completed: confirming a hire is a
     * deliberate act, handled by OnboardingJourneyController::complete.
     */
    private function syncJourneyProgress(int $tenant, int $journeyId, ?int $actorId): void
    {
        $counts = DB::table('talent_onboarding_tasks')
            ->where('journey_id', $journeyId)
            ->whereNull('deleted_at')
            ->selectRaw("COUNT(*) as total, SUM(status = 'completed') as done")
            ->first();

        if (!$counts || (int) $counts->total === 0) {
            return;
        }

        $journey = DB::table('talent_onboarding_journeys')
            ->where('sub_institute_id', $tenant)
            ->where('id', $journeyId)
            ->whereNull('deleted_at')
            ->first(['status']);

        if (!$journey || $journey->status === 'completed') {
            return;
        }

        $status = (int) $counts->done > 0 ? 'in-progress' : 'not-started';

        if ($journey->status !== $status) {
            DB::table('talent_onboarding_journeys')
                ->where('id', $journeyId)
                ->update(['status' => $status, 'updated_by' => $actorId, 'updated_at' => now()]);
        }
    }
}
