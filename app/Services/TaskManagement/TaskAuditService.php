<?php

namespace App\Services\TaskManagement;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Records task changes for the audit trail.
 *
 * Three controllers (MyTasks, front_desk task, TaskUpdate) were written
 * against this class but it was never committed, which made every request
 * through them fail at constructor injection.
 *
 * Writes task_management_audit_logs (what AuditLogController and the task
 * activity feed read) plus a log line as belt-and-braces. The table write is
 * best-effort: an audit failure must never turn the change it describes into
 * an error.
 */
class TaskAuditService
{
    /** The task row as it stands now, for callers that need a before-snapshot. */
    public function taskSnapshot(int $taskId): array
    {
        $task = DB::table('task')->where('id', $taskId)->first();

        return $task ? (array) $task : [];
    }

    /** First entry in a task's trail: it exists, and who made it. */
    public function taskCreated(int $taskId, ?int $actorId): void
    {
        $this->taskChanged($taskId, 'created', $this->taskSnapshot($taskId), $actorId);
    }

    /**
     * @param array<string, mixed> $before The task row before the change.
     */
    public function taskChanged(int $taskId, string $event, array $before, ?int $actorId): void
    {
        // The pre-change values that matter for "who changed what".
        $snapshot = [
            'status' => $before['status'] ?? null,
            'task_allocated_to' => $before['task_allocated_to'] ?? null,
            'task_date' => $before['task_date'] ?? null,
            'approve_status' => $before['approve_status'] ?? null,
            // Free-text description for events that are not a column diff -
            // a subtask being ticked, a timer stopping, a due date moving.
            'detail' => $before['detail'] ?? null,
        ];

        try {
            DB::table('task_management_audit_logs')->insert([
                'sub_institute_id' => (int) ($before['sub_institute_id'] ?? 0),
                'task_id' => $taskId,
                'event' => mb_substr($event, 0, 50),
                'actor_id' => $actorId,
                'before' => json_encode($snapshot),
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('task.audit_insert_failed', ['task_id' => $taskId, 'error' => $exception->getMessage()]);
        }

        Log::channel('single')->info('task.audit', [
            'task_id' => $taskId,
            'event' => $event,
            'actor_id' => $actorId,
            'before' => $snapshot,
        ]);
    }

    /**
     * Record a tenant-configuration change that is not attached to any task.
     *
     * Custom statuses and priorities are org-wide settings; deleting one
     * silently re-labels every task using it, so it belongs in the same trail
     * as task edits. task_id 0 marks the row as configuration - the reader
     * falls back to $subject for the display title.
     */
    public function configChanged(int $subInstituteId, string $event, string $subject, ?int $actorId): void
    {
        try {
            DB::table('task_management_audit_logs')->insert([
                'sub_institute_id' => $subInstituteId,
                'task_id' => 0,
                'event' => mb_substr($event, 0, 50),
                'actor_id' => $actorId,
                'before' => json_encode(['subject' => $subject]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('task.audit_insert_failed', ['event' => $event, 'error' => $exception->getMessage()]);
        }

        Log::channel('single')->info('task.audit', [
            'task_id' => 0,
            'event' => $event,
            'actor_id' => $actorId,
            'before' => ['subject' => $subject],
        ]);
    }
}
