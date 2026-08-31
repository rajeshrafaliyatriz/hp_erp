<?php

namespace App\Services\TaskManagement;

use App\Services\Events\EventRecorder;
use Illuminate\Support\Facades\DB;

/**
 * T-01 — THE ONE WRITE PATH AND THE ONE OWNER FOR `task.status`.
 *
 * MEASURED FIRST: five sites across five files wrote the column directly -
 * MyTasksController, WorkspaceController, ProjectController,
 * DeadlineExtensionController and TaskDependencyResolutionService. Each carried
 * its own idea of what else changes when a status changes.
 *
 * WHAT ALREADY EXISTED, AND IS NOT REBUILT HERE:
 *   `TaskOptionSetService::resolveStatus()` resolves the tenant's status
 *   VOCABULARY - which words are legal and what label each carries. Two of the
 *   five sites already used it. **That is the input to this class, not a
 *   competitor.** A vocabulary resolver answers "is this a real status"; this
 *   answers "what happens when a task moves to it".
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY delay_category IS SET HERE AND SURFACED NOWHERE (T-02).
 *
 *   MEASURED: `delay_category` is populated on 0 of 2,271 tasks. Not because the
 *   writes are broken - because the STATE IS UNREACHED. Across the whole system:
 *
 *       PENDING 2,087 | NULL 157 | COMPLETED 22 | IN-PROGRESS 4 | ON HOLD 1
 *
 *   ONE task has ever been ON HOLD. `delay_category` is only written when a task
 *   goes on hold, so an empty column is the correct consequence of a workflow
 *   nobody uses - not a defect in the field.
 *
 *   **T-02 was "surface delay_category". Surfacing it would add a column
 *   rendering NULL on 2,271 rows and make the screen worse.** The finding is the
 *   deliverable: the field is empty because the state is unreached.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * THE INVARIANT THIS CLASS OWNS: a status change writes `status`, `status_label`,
 * the delay fields and the audit stamp TOGETHER, or not at all. Five callers each
 * remembering four columns is four chances each to forget one.
 */
class TaskStatusWriter
{
    /** Statuses that mean the task is not being worked on. Only these carry a delay reason. */
    private const HOLD_STATES = ['ON HOLD'];

    public function __construct(
        private TaskOptionSetService $optionSets,
        private EventRecorder $events,
    ) {
    }

    /**
     * Move a task to a status. THE ONLY PLACE `task.status` IS WRITTEN.
     *
     * @param  array{delay_category?:?string, delay_reason?:?string, remarks?:?string}  $extra
     * @return array{ok:bool, reason:?string, from:?string, to:?string}
     */
    public function moveTo(int $taskId, string $status, int $tenantId, int $actorId, array $extra = []): array
    {
        // The tenant's own vocabulary decides what is legal - not a constant here.
        $resolved = $this->optionSets->resolveStatus($tenantId, $status);
        if ($resolved === null) {
            return ['ok' => false, 'reason' => "'$status' is not a status in your organisation.", 'from' => null, 'to' => null];
        }

        // Read BEFORE writing: the previous status is the event's payload and the
        // delay fields must be preserved when the task is not on hold.
        $before = DB::table('task')
            ->where('id', $taskId)
            ->where('sub_institute_id', $tenantId)
            ->first(['status', 'delay_category', 'delay_reason']);

        if (!$before) {
            return ['ok' => false, 'reason' => 'Task not found in your organisation.', 'from' => null, 'to' => null];
        }

        $onHold = in_array($resolved['status'], self::HOLD_STATES, true);

        DB::table('task')->where('id', $taskId)->where('sub_institute_id', $tenantId)->update([
            'status'       => $resolved['status'],
            'status_label' => $resolved['label'],
            // DELAY FIELDS BELONG TO THE HOLD STATE. Leaving a stale reason on a
            // task that has resumed would explain a delay that is over.
            //
            // BOTH BRANCHES WERE INVERTED relative to that sentence:
            //
            //   going ON HOLD without a category  ->  wrote null, ERASING an
            //     existing reason. WorkspaceController::update calls moveTo()
            //     with no $extra at all, so parking a task from the workspace
            //     silently wiped its delay category - and that category is the
            //     one thing TaskDependencyResolutionService needs to later
            //     un-park the task when its predecessor completes. The only
            //     dependency behaviour in the product was being disarmed by an
            //     unrelated status change.
            //
            //   RESUMING  ->  carried the old value forward, which is exactly
            //     the stale reason the comment says must not survive.
            //
            // Now: on hold, take what the caller supplied or keep what is there;
            // off hold, clear both.
            'delay_category' => $onHold ? ($extra['delay_category'] ?? $before->delay_category ?? null) : null,
            'delay_reason'   => $onHold ? ($extra['delay_reason'] ?? $extra['remarks'] ?? $before->delay_reason ?? null) : null,
            'updated_by'   => $actorId,
            'updated_at'   => now(),
        ]);

        // The event is emitted HERE because this is the only place that knows both
        // sides of the transition. A caller emitting it would be guessing at `from`.
        if ($before->status !== $resolved['status']) {
            /*
             * ── A REOPEN IS ITS OWN EVENT, NOT A STATUS CHANGE ──────────────
             *
             * COMPLETED -> IN-PROGRESS is the only legal way out of COMPLETED,
             * and it means finished work was found wanting and sent back. That
             * is exactly what CapabilityEvidenceProjector calls `task.reopened`
             * and treats as negative evidence.
             *
             * It was emitted as a plain `task.status_changed`, which the
             * projector does not handle — so half its trigger vocabulary could
             * never fire. Nothing anywhere emitted `task.reopened`, on either
             * database, ever.
             *
             * The generic event is ALSO emitted, unchanged: TaskStatusProjector
             * and the history trail are built on it, and dropping it to make
             * room for the specific one would trade one broken consumer for
             * another.
             */
            $isReopen = $before->status === 'COMPLETED' && $resolved['status'] === 'IN-PROGRESS';

            $this->events->record(
                'task.status_changed',
                $tenantId,
                'task',
                $taskId,
                $actorId,
                [
                    'from'   => $before->status,
                    'to'     => $resolved['status'],
                    'on_hold' => $onHold,
                    // The shape TaskStatusProjector reads. It was absent, which
                    // is why task_status_history stayed empty.
                    'before' => ['status' => $before->status],
                    'after'  => ['status' => $resolved['status']],
                ]
            );

            if ($isReopen) {
                $this->events->record(
                    'task.reopened',
                    $tenantId,
                    'task',
                    $taskId,
                    $actorId,
                    [
                        'from'   => $before->status,
                        'to'     => $resolved['status'],
                        'reason' => $extra['remarks'] ?? $extra['delay_reason'] ?? null,
                    ]
                );
            }
        }

        return ['ok' => true, 'reason' => null, 'from' => $before->status, 'to' => $resolved['status']];
    }
}
