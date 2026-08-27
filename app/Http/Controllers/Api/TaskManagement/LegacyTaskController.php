<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use App\Services\TaskManagement\TaskAuditService;
use App\Services\TaskManagement\TaskOptionSetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * JSON CRUD over the legacy `task` table for the new frontend.
 *
 * "Legacy" refers to the table, not the API: the old frontend wrote tasks
 * through the session-authenticated web /task route with form posts; this is
 * the token-authenticated equivalent, writing the same columns so both
 * frontends see the same data.
 *
 * On create it also does the two side effects the old frontend fired from the
 * browser: notify the assignee, and (when configured) call the n8n
 * task-assigned webhook - server-side now, so they cannot be skipped by a
 * closed tab.
 */
class LegacyTaskController extends Controller
{
    use \App\Http\Controllers\Concerns\ResolvesTaskDuty;

    use ResolvesTaskContext;

    public function __construct(
        private readonly TaskAuditService $taskAudit,
        protected readonly TaskOptionSetService $optionSets
    ) {
    }

    public function store(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), $this->rules());
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $id = DB::table('task')->insertGetId(
            $this->payload($request, $context) + [
                'sub_institute_id' => $context['sub_institute_id'],
                'SYEAR' => $context['syear'],
                'status' => 'PENDING',
                'created_by' => $context['user_id'],
                // Resolved here rather than in the form: the assign screen sends
                // task TITLES, and the rule for turning one into a duty already
                // lives on this side. See resolveDuty().
                'jobrole_task_id' => $this->resolveTaskDuty(
                    (int) $context['sub_institute_id'],
                    (string) $request->input('title')
                ),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->notifyAssignee($request, $context, $id);
        $this->fireWebhook($request, $context, $id);

        return $this->ok('Task created successfully.', ['id' => (string) $id], 201);
    }

    public function update(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), $this->rules());
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $task = $this->find($context, $id);
        if (!$task) {
            return $this->fail('Task not found.', 404);
        }

        DB::table('task')->where('id', $id)->update(
            $this->payload($request, $context) + [
                'updated_by' => $context['user_id'],
                'updated_at' => now(),
            ]
        );

        $this->taskAudit->taskChanged($id, 'legacy_updated', (array) $task, $context['user_id']);

        return $this->ok('Task updated successfully.', ['id' => (string) $id]);
    }

    public function destroy(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $task = $this->find($context, $id);
        if (!$task) {
            return $this->fail('Task not found.', 404);
        }

        DB::table('task')->where('id', $id)->update([
            'deleted_by' => $context['user_id'],
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);

        $this->taskAudit->taskChanged($id, 'legacy_deleted', (array) $task, $context['user_id']);

        return $this->ok('Task deleted successfully.');
    }

    /* ------------------------------------------------------------------ */

    protected function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:10000',
            'due_date' => 'nullable|date',
            // System values plus the tenant's custom priorities, resolved
            // in payload(); unknowns fall back to Medium.
            'priority' => 'nullable|string|max:100',
            'assignee_id' => 'required|integer',
            'owner_id' => 'nullable|integer',
            'kra' => 'nullable|string|max:1000',
            'kpa' => 'nullable|string|max:1000',
            'required_skills' => 'nullable|string|max:2000',
            // The ids behind those names. Create writes both (taskController's
            // store sets skill_id alongside required_skills); update wrote only
            // the names, so a task edited here kept its skill TEXT and lost the
            // ids the capability chain resolves against.
            'skill_id' => 'nullable|string|max:2000',
            'observation_point' => 'nullable|string|max:2000',
        ];
    }

    protected function payload(Request $request, array $context): array
    {
        return [
            'task_title' => $request->input('title'),
            'task_description' => $request->input('description'),
            'task_date' => $request->input('due_date'),
            'task_type' => $this->optionSets->resolvePriority($context['sub_institute_id'], (string) $request->input('priority', 'Medium')) ?? 'Medium',
            'task_allocated_to' => $request->integer('assignee_id'),
            'task_allocated' => $request->input('owner_id') ? $request->integer('owner_id') : $context['user_id'],
            'kra' => $request->input('kra'),
            'kpa' => $request->input('kpa'),
            'required_skills' => $request->input('required_skills'),
            'skill_id' => $request->input('skill_id'),
            'observation_point' => $request->input('observation_point'),
        ];
    }

    protected function find(array $context, int $id): ?object
    {
        return DB::table('task')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();
    }

    private function notifyAssignee(Request $request, array $context, int $taskId): void
    {
        NotificationController::notify(
            $context['sub_institute_id'],
            $request->integer('assignee_id'),
            'task_assigned',
            'New task: ' . $request->input('title'),
            $request->input('description'),
            $taskId
        );
    }

    /**
     * The old frontend posted the new assignment to an n8n webhook from the
     * browser. Server-side and config-driven now: no URL configured, no call,
     * and a webhook failure never fails the create.
     */
    private function fireWebhook(Request $request, array $context, int $taskId): void
    {
        $url = (string) config('services.n8n.task_webhook', '');
        if ($url === '') {
            return;
        }

        try {
            Http::timeout(5)->post($url, [
                'task_id' => $taskId,
                'title' => $request->input('title'),
                'assignee_id' => $request->integer('assignee_id'),
                'due_date' => $request->input('due_date'),
                'sub_institute_id' => $context['sub_institute_id'],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('task.n8n_webhook_failed', ['task_id' => $taskId, 'error' => $exception->getMessage()]);
        }
    }
}
