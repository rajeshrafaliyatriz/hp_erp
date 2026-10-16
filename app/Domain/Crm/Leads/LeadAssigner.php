<?php

namespace App\Domain\Crm\Leads;

use Illuminate\Support\Facades\DB;

/**
 * Sales & Marketing gap-closure, Wave 2: round-robin lead assignment. Every
 * lead previously required a human to pick an owner on every single create
 * (`assignedTo` was `required|integer`) - once a tenant turns this on and
 * adds people to the rotation, `next()` hands back who gets the next one.
 *
 * `null` means "not configured, or nothing to assign to" - the caller
 * (CrmLeadController::store()/import()) falls back to its existing,
 * unchanged behaviour (require a human to pick one) exactly as before this
 * feature existed. This is deliberately opt-in: a tenant that never touches
 * the new admin screen sees no behaviour change at all.
 *
 * `last_assigned_user_id` is the only state the rotation needs - `next()`
 * locks that one settings row for the duration of the transaction, so two
 * leads created at the same moment (store() and a concurrent import() row,
 * say) can never both be handed the same next person - confirmed necessary
 * by this same effort's own audit history, which found exactly this class of
 * unlocked-read-then-write race in the GTM deal / Signals promotion bridges.
 */
final class LeadAssigner
{
    public static function next(int $tenantId): ?int
    {
        return DB::transaction(function () use ($tenantId) {
            $settings = DB::table('crm_lead_assignment_settings')
                ->where('sub_institute_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if (! $settings || ! $settings->is_enabled) {
                return null;
            }

            $members = DB::table('crm_lead_assignment_rotation_members')
                ->where('sub_institute_id', $tenantId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->pluck('user_id')
                ->all();

            if ($members === []) {
                return null;
            }

            $lastIndex = $settings->last_assigned_user_id !== null
                ? array_search((int) $settings->last_assigned_user_id, $members, true)
                : false;
            $nextIndex = $lastIndex === false ? 0 : ($lastIndex + 1) % count($members);
            $next = (int) $members[$nextIndex];

            DB::table('crm_lead_assignment_settings')
                ->where('sub_institute_id', $tenantId)
                ->update(['last_assigned_user_id' => $next, 'updated_at' => now()]);

            return $next;
        });
    }
}
