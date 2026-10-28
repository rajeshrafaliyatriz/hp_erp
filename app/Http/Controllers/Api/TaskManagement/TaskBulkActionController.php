<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use App\Services\TaskManagement\TaskAuditService;
use App\Support\SubjectAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Mass actions over the legacy `task` table, for the Dashboard's checkbox
 * selection. Both actions resolve/authorize/audit each task individually in
 * a loop rather than as one aggregate operation — a batch can legally mix
 * the caller's own tasks with others', so the authorization answer can
 * differ row to row, and each one gets its own audit entry rather than a
 * single "bulk" event type.
 *
 * bulkDelete is unconditionally privileged (route-gated task.permission:
 * task.delete, same as the single-task DELETE), so it carries no per-row
 * ownership check — whatever ids are posted are exactly what gets archived,
 * with no recurrence-scope resolution.
 */
class TaskBulkActionController extends Controller
{
    use ResolvesTaskContext;

    public function __construct(private readonly TaskAuditService $taskAudit)
    {
    }

    public function reassign(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'task_ids' => 'required|array|min:1',
            'task_ids.*' => 'integer|min:1',
            'assignee_id' => 'required|integer|min:1',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $assigneeId = $request->integer('assignee_id');
        $results = [];

        foreach (array_unique($request->input('task_ids')) as $taskId) {
            $taskId = (int) $taskId;
            $task = $this->findTenantTask($context, $taskId);

            if (!$task) {
                $results[] = ['task_id' => (string) $taskId, 'ok' => false, 'reason' => 'Task not found.'];
                continue;
            }

            if (!$this->canEdit($context, $task)) {
                $results[] = ['task_id' => (string) $taskId, 'ok' => false, 'reason' => 'You can only reassign tasks you own, created, or are assigned to.'];
                continue;
            }

            DB::table('task')->where('id', $taskId)->update([
                'task_allocated_to' => $assigneeId,
                'updated_by' => $context['user_id'],
                'updated_at' => now(),
            ]);

            $this->taskAudit->taskChanged(
                $taskId,
                'reassigned',
                (array) $task,
                $context['user_id'],
                ['task_allocated_to' => $assigneeId]
            );

            $results[] = ['task_id' => (string) $taskId, 'ok' => true, 'reason' => null];
        }

        return $this->ok('Reassignment complete.', [
            'results' => $results,
            'summary' => [
                'succeeded' => count(array_filter($results, fn ($r) => $r['ok'])),
                'failed' => count(array_filter($results, fn ($r) => !$r['ok'])),
            ],
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'task_ids' => 'required|array|min:1',
            'task_ids.*' => 'integer|min:1',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $results = [];

        foreach (array_unique($request->input('task_ids')) as $taskId) {
            $taskId = (int) $taskId;
            $task = $this->findTenantTask($context, $taskId);

            if (!$task) {
                $results[] = ['task_id' => (string) $taskId, 'ok' => false, 'reason' => 'Task not found.'];
                continue;
            }

            DB::table('task')->where('id', $taskId)->update([
                'deleted_by' => $context['user_id'],
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

            $this->taskAudit->taskChanged($taskId, 'archived', (array) $task, $context['user_id']);

            $results[] = ['task_id' => (string) $taskId, 'ok' => true, 'reason' => null];
        }

        return $this->ok('Bulk delete complete.', [
            'results' => $results,
            'summary' => [
                'succeeded' => count(array_filter($results, fn ($r) => $r['ok'])),
                'failed' => count(array_filter($results, fn ($r) => !$r['ok'])),
            ],
        ]);
    }

    private function findTenantTask(array $context, int $id): ?object
    {
        return DB::table('task')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->where('SYEAR', $context['syear'])
            ->whereNull('deleted_at')
            ->first();
    }

    /** Mirrors WorkspaceController::canEditTask's intent via the shared role tier. */
    private function canEdit(array $context, object $task): bool
    {
        $userId = $context['user_id'];

        if ((int) $task->task_allocated_to === $userId
            || (int) $task->task_allocated === $userId
            || (int) $task->created_by === $userId) {
            return true;
        }

        return SubjectAuthority::userSatisfies($userId, SubjectAuthority::TASK_PRIVILEGED);
    }
}
