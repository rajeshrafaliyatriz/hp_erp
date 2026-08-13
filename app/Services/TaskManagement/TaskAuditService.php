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
    public function __construct(private \App\Services\Events\EventRecorder $events)
    {
    }

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

        // AMENDMENT A1 (05-data-flow-contracts.md §1): this service used to
        // INSERT into task_management_audit_logs directly. It now EMITS AN EVENT,
        // and g2g_audit_log is a projection built from the store. A projection
        // alongside a surviving direct writer is the defect §1 exists to prevent.
        //
        // $actorId reaches here from the caller's RESOLVED identity - never from
        // a request field. G-SEC-12 is what makes that true, and it is why the
        // event store was sequenced after it.
        try {
            $this->events->record(
                type: 'task.' . mb_substr($event, 0, 50),
                subInstituteId: (int) ($before['sub_institute_id'] ?? 0),
                entityType: 'task',
                entityId: $taskId,
                actorId: $actorId,
                payload: ['event' => $event, 'before' => $snapshot],
            );
        } catch (\Throwable $exception) {
            Log::warning('task.audit_event_failed', ['task_id' => $taskId, 'error' => $exception->getMessage()]);
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
        // A1: emitted, not inserted. entity_type 'task_config' rather than a
        // task_id of 0 - a sentinel id was a workaround for a table that had no
        // way to say "this is configuration, not a task".
        try {
            $this->events->record(
                type: 'task_config.' . mb_substr($event, 0, 50),
                subInstituteId: $subInstituteId,
                entityType: 'task_config',
                entityId: null,
                actorId: $actorId,
                payload: ['event' => $event, 'subject' => $subject],
            );
        } catch (\Throwable $exception) {
            Log::warning('task.audit_event_failed', ['event' => $event, 'error' => $exception->getMessage()]);
        }

        Log::channel('single')->info('task.audit', [
            'task_id' => 0,
            'event' => $event,
            'actor_id' => $actorId,
            'before' => ['subject' => $subject],
        ]);
    }
}
