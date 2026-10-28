<?php

namespace App\Domain\Crm\Support;

use App\Services\Events\EventRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Sales & Marketing gap-closure, Wave 6: finds tickets that have breached
 * their SLA with no first response yet, and have not been escalated before,
 * and does exactly two things about each one - notify (always) and reassign
 * (only if the tenant opted in for that priority).
 *
 * `sla_escalated_at` is claimed ATOMICALLY via `whereNull(...)->update(...)`
 * before anything else happens for that ticket - the same one-shot idiom
 * `LeaveApprovalWorkflow::escalateOverdue()` already uses, so two concurrent
 * sweep runs (or a retry) can never escalate the same breach twice.
 *
 * Reuses `TicketSlaCalculator::forTicket()` for the breach math itself -
 * never a second implementation of "what counts as breached" that could
 * drift from the one the Tickets list/detail page already shows.
 */
final class TicketSlaEscalator
{
    /**
     * @return array<int, array{ticket_id:int, ticket_no:string, tenant:int, priority:string, due_at:string, assigned_to:?int, escalate_to:?int, reassigned:bool}>
     */
    public static function escalateOverdue(?int $onlyTenant = null, bool $dryRun = false): array
    {
        $escalated = [];

        $tenants = DB::table('crm_sla_policies')
            ->whereNotNull('escalate_to_user_id')
            ->when($onlyTenant !== null, fn ($q) => $q->where('sub_institute_id', $onlyTenant))
            ->distinct()
            ->pluck('sub_institute_id');

        foreach ($tenants as $tenantId) {
            $policies = TicketSlaCalculator::policiesFor((int) $tenantId);

            $candidates = DB::table('crm_tickets')
                ->where('sub_institute_id', $tenantId)
                ->whereNull('deleted_at')
                ->where('status', '!=', 'Closed')
                ->whereNull('first_response_at')
                ->whereNull('sla_escalated_at')
                ->whereIn('priority', array_keys($policies))
                ->get();

            foreach ($candidates as $ticket) {
                $policy = $policies[$ticket->priority] ?? null;

                if (! $policy || empty($policy->escalate_to_user_id)) {
                    continue;
                }

                $sla = TicketSlaCalculator::forTicket($ticket, $policies);

                if ($sla['status'] !== 'breached') {
                    continue;
                }

                $row = [
                    'ticket_id' => (int) $ticket->id,
                    'ticket_no' => $ticket->ticket_no,
                    'tenant' => (int) $tenantId,
                    'priority' => $ticket->priority,
                    'due_at' => $sla['dueAt'],
                    'assigned_to' => $ticket->assigned_to ? (int) $ticket->assigned_to : null,
                    'escalate_to' => (int) $policy->escalate_to_user_id,
                    'reassigned' => false,
                ];

                if ($dryRun) {
                    $escalated[] = $row;
                    continue;
                }

                // THE ATOMIC CLAIM. If this affects zero rows, somebody else (a
                // concurrent run, a retry) already claimed this ticket's breach -
                // skip it rather than escalate it a second time.
                $claimed = DB::table('crm_tickets')
                    ->where('id', $ticket->id)
                    ->whereNull('sla_escalated_at')
                    ->update(['sla_escalated_at' => now()]);

                if ($claimed === 0) {
                    continue;
                }

                $reassigned = false;

                if ($policy->auto_reassign_on_breach && (int) $policy->escalate_to_user_id !== (int) ($ticket->assigned_to ?? 0)) {
                    DB::table('crm_tickets')->where('id', $ticket->id)->update([
                        'assigned_to' => $policy->escalate_to_user_id,
                        'updated_at' => now(),
                    ]);
                    $reassigned = true;
                }

                $row['reassigned'] = $reassigned;

                app(EventRecorder::class)->record(
                    type: 'ticket.sla_breached',
                    subInstituteId: (int) $tenantId,
                    entityType: 'crm_tickets',
                    entityId: (int) $ticket->id,
                    actorId: null,
                    payload: [
                        'ticket_id' => (int) $ticket->id,
                        'ticket_no' => $ticket->ticket_no,
                        'title' => $ticket->title,
                        'priority' => $ticket->priority,
                        'due_at' => $sla['dueAt'],
                        'assigned_to' => $ticket->assigned_to ? (int) $ticket->assigned_to : null,
                        'escalate_to_user_id' => (int) $policy->escalate_to_user_id,
                        'reassigned' => $reassigned,
                    ],
                    idempotencyKey: "ticket.sla_breached:{$ticket->id}",
                );

                $escalated[] = $row;
            }
        }

        return $escalated;
    }
}
