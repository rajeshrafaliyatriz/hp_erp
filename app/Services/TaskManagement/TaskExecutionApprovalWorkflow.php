<?php

namespace App\Services\TaskManagement;

use App\Services\Platform\ApprovalEngine;
use Illuminate\Support\Facades\DB;

/**
 * `task.execution.approval` enforcement. See `ApprovalEngine`'s own class note
 * for what it does and does not do, and
 * `App\Services\Attendance\AttendanceRegularisationApprovalWorkflow` for the
 * first domain this pattern was proven against.
 *
 * ── NO PRE-EXISTING AUTHORIZATION TO NARROW, UNLIKE ATTENDANCE ─────────────
 *
 * `WorkspaceController::approve()` has no role/manager check today — any
 * authenticated tenant member may approve or reject any completed task. So for
 * a tenant with an active chain here, this is the FIRST real gate this
 * endpoint has ever had, not a narrowing of an existing one. For every other
 * tenant, `openFor()` opens nothing and `approve()`'s existing behaviour is
 * completely unchanged.
 */
class TaskExecutionApprovalWorkflow
{
    private const FLOW_KEY = 'task.execution.approval';
    private const SUBJECT_TYPE = 'task_submission';

    public function __construct(
        private readonly ApprovalEngine $engine = new ApprovalEngine(),
    ) {
    }

    /**
     * Freeze a chain onto a task the moment it reaches COMPLETED. A no-op when
     * steps already exist for this task — a redundant re-entry into COMPLETED
     * (e.g. a caller retrying) must not collide with
     * `g2g_platform_approval_steps`'s own `(subject_type, subject_id,
     * step_order)` uniqueness, and re-freezing an already-decided chain would
     * silently discard its decision.
     *
     * @return array<int, string>
     */
    public function openFor(int $taskId, int $tenantId): array
    {
        if ($this->engine->stepsFor(self::SUBJECT_TYPE, $taskId) !== []) {
            return [];
        }

        return $this->engine->openFor(
            $tenantId,
            self::FLOW_KEY,
            self::SUBJECT_TYPE,
            $taskId,
            $this->conditionContextFor($taskId, $tenantId)
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function stepsFor(int $taskId): array
    {
        return $this->engine->stepsFor(self::SUBJECT_TYPE, $taskId);
    }

    public function currentStep(int $taskId): ?array
    {
        return $this->engine->currentStep(self::SUBJECT_TYPE, $taskId);
    }

    public function roleMayDecide(array $step, ?string $roleKey, ?int $userId = null): bool
    {
        return $this->engine->roleMayDecide($step, $roleKey, $userId);
    }

    /** @return array<string, mixed> */
    public function recordDecision(
        int $taskId,
        array $step,
        string $decision,
        array $context,
        ?string $comment = null
    ): array {
        return $this->engine->recordDecision(self::SUBJECT_TYPE, $taskId, $step, $decision, $context, $comment);
    }

    public function closeOpenSteps(int $taskId): int
    {
        return $this->engine->closeOpenSteps(self::SUBJECT_TYPE, $taskId);
    }

    /**
     * `department_id` of the task's ASSIGNEE (`task_allocated_to`) — the
     * person whose work is being approved, not whoever created the task.
     * Fails closed to `[]` when the task or its assignee cannot be found.
     *
     * @return array<string, float>
     */
    private function conditionContextFor(int $taskId, int $tenantId): array
    {
        $task = DB::table('task')
            ->where('id', $taskId)
            ->where('sub_institute_id', $tenantId)
            ->first(['task_allocated_to']);

        if ($task === null || $task->task_allocated_to === null) {
            return [];
        }

        $departmentId = DB::table('tbluser')->where('id', $task->task_allocated_to)->value('department_id');

        return ($departmentId !== null && is_numeric($departmentId))
            ? ['department_id' => (float) $departmentId]
            : [];
    }
}
