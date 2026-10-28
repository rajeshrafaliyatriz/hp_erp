<?php

namespace App\Domain\Crm\Sales;

use App\Domain\Gtm\GtmAudit;
use Illuminate\Support\Facades\DB;

/**
 * The clock-driven half of Opportunity management: find OPEN Opportunities that have gone quiet, or whose closing date has
 * passed, and say so ONCE A WEEK per Opportunity - `crm.opportunity.inactive` / `crm.opportunity.overdue`. Both are
 * workflow triggers and both notify the Opportunity's owner (NotificationDispatcher), so nothing waits for someone to open a report.
 *
 * "Activity" is anything a person or the system really recorded against the Opportunity: an edit, a stage change, a comment,
 * an email, a held meeting or call. Nothing is invented; an Opportunity with no recorded activity counts from the day it was created.
 * The weekly idempotency key (ISO year-week) is what stops a daily or five-minute scheduler from repeating the alert.
 */
final class OpportunitySweep
{
    /**
     * @param  list<int>|null  $onlyIds  restrict the pass to these Opportunities (tests and one-off checks); null = every tenant's open ones
     * @param  int|null  $tenant  restrict the pass to ONE tenant (the tenant-scoped automation command); null = every tenant
     * @return array{checked: int, inactive: int, overdue: int}
     */
    public static function run(int $limit = 500, ?array $onlyIds = null, ?int $tenant = null): array
    {
        $days = max(1, (int) config('crm.opportunity.inactive_days', 14));
        $out = ['checked' => 0, 'inactive' => 0, 'overdue' => 0];
        $week = now()->format('o-\WW');
        $today = now()->toDateString();

        $rows = DB::table('crm_opportunities')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('sales_stage')->orWhereNotIn('sales_stage', ['Closed Won', 'Closed Lost']))
            ->when($onlyIds !== null, fn ($q) => $q->whereIn('id', $onlyIds))->when($tenant !== null, fn ($q) => $q->where('sub_institute_id', $tenant))
            ->orderBy('id')->limit($limit)->get(['id', 'opportunity_no', 'name', 'sub_institute_id', 'assigned_to', 'sales_stage', 'closing_date', 'created_at', 'updated_at']);

        foreach ($rows as $o) {
            $out['checked']++;
            $tenant = (int) $o->sub_institute_id;
            $last = self::lastActivity($tenant, $o);
            $quietDays = (int) $last->diffInDays(now());
            if ($quietDays >= $days) {
                $id = GtmAudit::record('crm.opportunity.inactive', $tenant, 'crm_opportunities', (int) $o->id, null,
                    ['opportunity_no' => $o->opportunity_no, 'name' => $o->name, 'days_inactive' => $quietDays, 'assigned_to' => (int) $o->assigned_to], null, "crm.opportunity.inactive:{$o->id}:{$week}");
                $out['inactive'] += $id ? 1 : 0;
            }
            if ($o->closing_date !== null && $o->closing_date < $today) {
                $late = (int) \Illuminate\Support\Carbon::parse($o->closing_date)->diffInDays(now());
                $id = GtmAudit::record('crm.opportunity.overdue', $tenant, 'crm_opportunities', (int) $o->id, null,
                    ['opportunity_no' => $o->opportunity_no, 'name' => $o->name, 'closing_date' => $o->closing_date, 'days_overdue' => $late, 'assigned_to' => (int) $o->assigned_to], null, "crm.opportunity.overdue:{$o->id}:{$o->closing_date}:{$week}");
                $out['overdue'] += $id ? 1 : 0;
            }
        }

        return $out;
    }

    /** The latest real activity on one Opportunity (never in the future), or its creation time. */
    public static function lastActivity(int $tenant, object $o): \Illuminate\Support\Carbon
    {
        $times = [$o->updated_at, $o->created_at];
        $times[] = DB::table('crm_opportunity_stage_history')->where('opportunity_id', $o->id)->max('created_at');
        $times[] = DB::table('crm_opportunity_comments')->where('opportunity_id', $o->id)->where('sub_institute_id', $tenant)->max('created_at');
        $times[] = DB::table('crm_email_links')->where('linked_type', 'OPPORTUNITY')->where('linked_id', $o->id)->where('sub_institute_id', $tenant)->max('created_at');
        $times[] = DB::table('task_management_calendar_events')->where('linked_type', 'OPPORTUNITY')->where('linked_id', $o->id)->where('sub_institute_id', $tenant)->where('status', 'Held')->whereNull('deleted_at')->max('updated_at');
        $latest = null;
        foreach (array_filter($times) as $t) {
            $c = \Illuminate\Support\Carbon::parse($t);
            if ($c->lessThanOrEqualTo(now()) && ($latest === null || $c->greaterThan($latest))) {
                $latest = $c;
            }
        }

        return $latest ?? \Illuminate\Support\Carbon::parse($o->created_at);
    }
}
