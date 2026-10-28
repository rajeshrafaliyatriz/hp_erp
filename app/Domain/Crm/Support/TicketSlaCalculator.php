<?php

namespace App\Domain\Crm\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sales & Marketing gap-closure, Wave 4: computes a ticket's SLA due-by time
 * and status on every read - never stored, see the migration's own docblock
 * for why. `policiesFor()` fetches every configured policy for a tenant
 * ONCE per request (a plain `keyBy('priority')` map) so `index()` listing 50
 * tickets does one query, not fifty.
 */
final class TicketSlaCalculator
{
    /** @return array<string, object> priority => policy row */
    public static function policiesFor(int $tenantId): array
    {
        return DB::table('crm_sla_policies')->where('sub_institute_id', $tenantId)->get()->keyBy('priority')->all();
    }

    /**
     * @param  array<string, object>  $policies  From policiesFor() - passed in, not refetched per ticket.
     * @return array{dueAt: ?string, status: 'no_policy'|'within_sla'|'breached'|'met', responseHours: ?float}
     */
    public static function forTicket(object $ticket, array $policies): array
    {
        $policy = $ticket->priority !== null ? ($policies[$ticket->priority] ?? null) : null;

        if (! $policy) {
            return ['dueAt' => null, 'status' => 'no_policy', 'responseHours' => null];
        }

        $dueAt = Carbon::parse($ticket->created_at)->addHours((float) $policy->response_hours);

        if ($ticket->first_response_at !== null) {
            $status = Carbon::parse($ticket->first_response_at)->lte($dueAt) ? 'met' : 'breached';
        } else {
            $status = now()->gt($dueAt) ? 'breached' : 'within_sla';
        }

        return ['dueAt' => $dueAt->toDateTimeString(), 'status' => $status, 'responseHours' => (float) $policy->response_hours];
    }
}
