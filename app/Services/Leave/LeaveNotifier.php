<?php

namespace App\Services\Leave;

use App\Services\Events\EventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * HRIT's one entry point to the platform's event store. F-128.
 *
 * NOTHING IN THIS MODULE HAS EVER TOLD ANYBODY ANYTHING. An employee applies for
 * leave and their approver finds out by opening the screen. An approver decides
 * and the employee finds out by opening the screen. A request sits for a week
 * and nobody is told at all.
 *
 * WHY THIS COULD NOT BE BUILT BEFORE SPRINT 6, and why it can be built now:
 *
 *   RecipientResolver's own docblock says it plainly - "There is no org-chart
 *   fallback because there is no org chart. Any notification whose only
 *   plausible recipient is 'the employee's manager' cannot be delivered to
 *   anyone and is deferred, not shipped."
 *
 *   That was true. It is not true any more. hrms_leave_approval_steps names the
 *   exact ROLE that must decide each request, and ResolvesLeaveAuthority
 *   resolves a role to real users in a real tenant. The chain built to enforce
 *   approvals turns out to be the thing that makes approval notifications
 *   deliverable - so this sprint gets to ship what the last one made possible.
 *
 * REUSE, NOT REBUILD. There is a complete notification stack already:
 * EventRecorder -> g2g_event -> ReactEvents -> NotificationDispatcher ->
 * RecipientResolver -> NotificationComposer -> NotificationSender. This class
 * adds three EVENT TYPES to it and nothing else. It does not write
 * g2g_notification, it does not send, and it does not know what a channel is.
 *
 * IN-APP ONLY, and that is not this class's decision to revisit.
 * NotificationSender keeps email behind G2G_NOTIFY_EMAIL with three written
 * conditions, one of which is Triz's explicit decision in the turn it happens.
 * 386 real addresses at real companies. These events go through the same sender
 * as everything else and inherit that default; nothing here touches the flag.
 *
 * EMITTING NEVER BREAKS THE WRITE. Every call is wrapped: a leave request that
 * saved must not fail because the event store was unreachable. The state change
 * is the product; the notification is a consequence of it.
 */
class LeaveNotifier
{
    public const SUBMITTED = 'leave.submitted';
    public const DECIDED   = 'leave.decided';
    public const ESCALATED = 'leave.escalated';

    public function __construct(private EventRecorder $events)
    {
    }

    /**
     * A request was raised, and the first approver does not know.
     *
     * The recipient is not named here - RecipientResolver derives it from the
     * chain's own pending step, so who gets told is decided by the same table
     * that decides who may approve. Two answers to "whose turn is it" would
     * eventually disagree.
     */
    public function submitted(
        int $leaveId,
        array $context,
        array $chain,
        string $employeeName,
        string $dates,
        int $step = 1
    ): void {
        $this->emit(self::SUBMITTED, $leaveId, $context, [
            'leave_id'      => $leaveId,
            'employee_id'   => $context['subject_id'] ?? $context['user_id'],
            'employee_name' => $employeeName,
            'dates'         => $dates,
            'chain'         => $chain,
            'step'          => $step,
            'of'            => count($chain),
        ]);
    }

    /**
     * A decision was made. The employee is told, always - including when the
     * request has only advanced a stage, because "your manager approved it, it
     * is now with the department head" is the thing they actually want to know
     * and the screen never said it.
     */
    public function decided(
        int $leaveId,
        array $context,
        int $employeeId,
        string $decision,
        bool $final,
        ?string $nextRole,
        int $step,
        int $of
    ): void {
        $this->emit(self::DECIDED, $leaveId, $context, [
            'leave_id'    => $leaveId,
            'employee_id' => $employeeId,
            'decision'    => $decision,
            'final'       => $final,
            'next_role'   => $nextRole,
            'next_label'  => $nextRole ? LeaveApprovalWorkflow::label($nextRole) : null,
            'step'        => $step,
            'of'          => $of,
        ]);
    }

    /**
     * A step breached its tenant's deadline and was escalated.
     *
     * Told to whoever escalate_to resolves to - the people who can now act on
     * it. Escalating without telling them is an entry in a table, not an
     * escalation.
     */
    public function escalated(array $step, int $waitingHours): void
    {
        $context = [
            'sub_institute_id' => $step['sub_institute_id'],
            // The sweep is a scheduled job. Nobody did this, so the actor is
            // SYSTEM - a real value in this store, not "unknown".
            'user_id'          => null,
        ];

        $this->emit(self::ESCALATED, (int) $step['leave_id'], $context, [
            'leave_id'      => (int) $step['leave_id'],
            'step_id'       => (int) $step['step_id'],
            'from_role'     => $step['from'],
            'from_label'    => LeaveApprovalWorkflow::label($step['from']),
            'to_role'       => $step['to'],
            'to_label'      => LeaveApprovalWorkflow::label($step['to']),
            'waiting_hours' => $waitingHours,
            'waiting_since' => $step['waiting_since'],
        ]);
    }

    private function emit(string $type, int $leaveId, array $context, array $payload): void
    {
        try {
            $this->events->record(
                $type,
                (int) $context['sub_institute_id'],
                'hrms_emp_leave',
                $leaveId,
                isset($context['user_id']) ? (int) $context['user_id'] : null,
                $payload,
                null,
                // IDEMPOTENCY. A retried save, a double-clicked Approve or a
                // re-run of the escalation sweep must not tell somebody the same
                // thing twice. The key is the fact, not the attempt.
                $this->idempotencyKey($type, $leaveId, $payload)
            );
        } catch (\Throwable $e) {
            // Deliberately swallowed. The leave request is saved and the
            // decision is recorded; failing the whole call because a
            // notification could not be queued would lose the work that matters
            // to protect the work that does not.
            Log::warning('Leave event not recorded', [
                'type' => $type, 'leave_id' => $leaveId, 'error' => $e->getMessage(),
            ]);
        }
    }

    private function idempotencyKey(string $type, int $leaveId, array $payload): string
    {
        return match ($type) {
            /*
             * THE STEP IS PART OF THE KEY, and leaving it out was a real bug in
             * the first version of this class.
             *
             * A two-stage chain needs to tell TWO people it is their turn: the
             * reporting manager when it is raised, and the department head when
             * it advances. Keyed on the leave alone, the second emit collided
             * with the first, EventRecorder deduplicated it, and the department
             * head was never told. The probe caught it: one leave.submitted
             * notification for a request that had passed through two approvers.
             */
            self::SUBMITTED => "leave.submitted:{$leaveId}:" . ($payload['step'] ?? 1),
            // A chain has one decision per step, so the step number is what makes
            // this unique - not the decision, which could legitimately repeat.
            self::DECIDED   => "leave.decided:{$leaveId}:" . ($payload['step'] ?? 0),
            self::ESCALATED => "leave.escalated:" . ($payload['step_id'] ?? 0),
            default         => "{$type}:{$leaveId}",
        };
    }
}
