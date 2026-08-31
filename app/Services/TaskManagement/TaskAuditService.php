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
 * EMITS AN EVENT (amendment A1) plus a log line as belt-and-braces. It has NOT
 * written task_management_audit_logs since A1 — this docblock claimed it did,
 * twelve lines above the code that stopped. That contradiction is how the audit
 * screen came to be read from a table nothing writes.
 *
 * The emit is best-effort: an audit failure must never turn the change it
 * describes into an error.
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
     * @param array<string, mixed> $after  The values after it, where the caller knows them.
     */
    public function taskChanged(int $taskId, string $event, array $before, ?int $actorId, array $after = []): void
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

        /*
         * ── THE `after` HALF, WITHOUT WHICH TWO CONSUMERS CANNOT WORK ────────
         *
         * This emitted only `before`. TaskStatusProjector reads
         * $payload['after'] to fill to_status / to_approve_status, so both landed
         * NULL, isActive() returned false, and reopen detection was structurally
         * dead — task_status_history held ZERO rows on both databases despite 44
         * task events sitting in the store.
         *
         * NotificationDispatcher has the same problem from the other end: the
         * rejection template reads {payload.task_title} and
         * {payload.approve_remarks}, and a missing key composes to "—", so an
         * employee whose task was sent back would receive
         * "— was not approved … Reason given: —".
         *
         * Both are one omission. `after` is optional so every existing caller
         * keeps working unchanged; a caller that knows the new values passes them
         * and the consumers downstream start functioning.
         */
        $afterSnapshot = $after === [] ? null : [
            'status'         => $after['status'] ?? null,
            'approve_status' => $after['approve_status'] ?? null,
            'task_date'      => $after['task_date'] ?? null,
        ];

        $payload = ['event' => $event, 'before' => $snapshot];

        if ($afterSnapshot !== null) {
            $payload['after'] = $afterSnapshot;
        }

        // Read by the notification templates, which name them directly. Kept at
        // the top level of the payload because that is where {payload.x} looks.
        foreach (['task_title', 'approve_remarks'] as $key) {
            if (array_key_exists($key, $after) && $after[$key] !== null && $after[$key] !== '') {
                $payload[$key] = $after[$key];
            } elseif (array_key_exists($key, $before) && $before[$key] !== null && $before[$key] !== '') {
                $payload[$key] = $before[$key];
            }
        }

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
                payload: $payload,
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
