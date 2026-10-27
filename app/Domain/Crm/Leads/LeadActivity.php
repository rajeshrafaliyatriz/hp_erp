<?php

namespace App\Domain\Crm\Leads;

use App\Domain\Gtm\GtmAudit;
use App\Services\Events\EventRecorder;
use App\Services\TaskManagement\CalendarEventWriter;
use Illuminate\Support\Facades\DB;

/**
 * The two things automation writes onto a CRM lead, in ONE place so the workflow actions and the follow-up cadence cannot
 * drift apart: a comment on the lead's Comments tab, and a task on its Activity tab. Both use the lead's own existing
 * storage (crm_lead_comments, task_management_calendar_events via CalendarEventWriter) - no parallel tables.
 */
final class LeadActivity
{
    /** An "[Automation]" comment. created_by stays null: a system note is never attributed to a person. */
    public static function note(int $tenant, int $leadId, string $text): int
    {
        return (int) DB::table('crm_lead_comments')->insertGetId([
            'lead_id' => $leadId, 'sub_institute_id' => $tenant, 'created_by' => null, 'created_at' => now(), 'updated_at' => now(),
            'comment' => '[Automation] '.mb_substr($text, 0, 1980),
        ]);
    }

    public const QUALIFIED = 'Qualified';

    /**
     * When a lead's status BECOMES Qualified (by a person or by a workflow rule), say so: that is the moment the
     * qualification-and-conversion workflow can start. Not recorded when it already was Qualified.
     */
    public static function announceQualified(int $tenant, object $leadBefore, ?string $newStatus, ?int $actorId): void
    {
        if ($newStatus === null || strcasecmp(trim($newStatus), self::QUALIFIED) !== 0 || strcasecmp(trim((string) $leadBefore->lead_status), self::QUALIFIED) === 0) {
            return;
        }
        GtmAudit::record('crm.lead.qualified', $tenant, 'crm_leads', (int) $leadBefore->id, $actorId, ['from' => $leadBefore->lead_status], null, 'crm.lead.qualified:'.$tenant.':'.$leadBefore->id.':'.now()->format('YmdHis'));
    }

    /**
     * When a person's edit takes a lead from "still missing details" to "complete" (a real contact name, an email, a phone, a company),
     * say so: a lead promoted from a Signal arrives without an address, and this is the moment the follow-up can begin.
     *
     * @param  object|array<string, mixed>  $before  @param  object|array<string, mixed>  $after
     */
    public static function announceReady(int $tenant, int $leadId, object|array $before, object|array $after, ?int $actorId): void
    {
        if (LeadCompleteness::missing($before) === [] || LeadCompleteness::missing($after) !== []) {
            return; // it was already complete, or it still is not
        }
        GtmAudit::record('crm.lead.ready', $tenant, 'crm_leads', $leadId, $actorId, ['was_missing' => LeadCompleteness::missing($before)], null, 'crm.lead.ready:'.$tenant.':'.$leadId.':'.now()->format('YmdHis'));
    }

    /** Change Lead Status and leave the same `crm.lead.updated` audit trail a manual edit leaves (shown on the lead's History tab). @param array<string, mixed> $meta */
    public static function setStatus(int $tenant, object $lead, string $status, array $meta): void
    {
        DB::table('crm_leads')->where('id', $lead->id)->where('sub_institute_id', $tenant)->update(['lead_status' => $status, 'updated_at' => now()]);
        app(EventRecorder::class)->record('crm.lead.updated', $tenant, 'crm_leads', (int) $lead->id, null,
            ['changes' => [['field' => 'lead_status', 'oldValue' => $lead->lead_status, 'newValue' => $status]]], $meta);
        self::announceQualified($tenant, $lead, $status, null);
    }

    /**
     * The organisation's CURRENT ACADEMIC YEAR (`syear`), which is what every calendar screen filters on. A calendar event stamped with
     * the calendar year ("2026") while the organisation's year is "2025" is listed on the lead's Activity tab (that list ignores the
     * year) but cannot be opened from it ("Event not found") and never appears in the Task Management calendar.
     * Chosen as: the academic year whose dates contain today (latest first); else the latest one; else the calendar year.
     */
    public static function currentSyear(int $tenant): string
    {
        try {
            $rows = DB::table('academic_year')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->orderByDesc('syear')->get(['syear', 'start_date', 'end_date']);
        } catch (\Throwable $e) {
            return (string) now()->year;
        }
        $today = now()->toDateString();
        foreach ($rows as $r) {
            if ($r->start_date !== null && $r->end_date !== null && $r->start_date <= $today && $today <= $r->end_date) {
                return (string) $r->syear;
            }
        }

        return $rows->isNotEmpty() ? (string) $rows->first()->syear : (string) now()->year;
    }
    /**
     * A task for the lead's current owner, due `$dueInDays` days from now at 09:00 (or the next hour when that has passed).
     * Returns the event id, or null when an open task with the same title already exists on the lead (so a retry never doubles it).
     *
     * @throws \RuntimeException when the calendar refuses the event
     */
    public static function task(int $tenant, object $lead, string $title, ?string $description, int $dueInDays, ?int $actorId): ?int
    {
        return self::taskFor($tenant, 'LEAD', (int) $lead->id, (int) $lead->assigned_to, $title, $description, $dueInDays, $actorId);
    }

    /**
     * The same task, attached to ANY CRM record that has a Schedule tab (LEAD, CONTACT, ORGANIZATION, OPPORTUNITY, TICKET).
     * Null when an open task with that title already exists on that record.
     */
    public static function taskFor(int $tenant, string $linkedType, int $linkedId, int $ownerId, string $title, ?string $description, int $dueInDays, ?int $actorId): ?int
    {
        $title = mb_substr($title, 0, 150);
        $open = DB::table('task_management_calendar_events')->where('sub_institute_id', $tenant)->where('linked_type', $linkedType)->where('linked_id', $linkedId)
            ->where('title', $title)->where('status', 'Planned')->whereNull('deleted_at')->exists();
        if ($open) {
            return null;
        }
        $start = now()->addDays($dueInDays)->setTime(9, 0);
        if ($start->lessThan(now())) {
            $start = now()->addHour()->startOfHour();
        }
        $r = app(CalendarEventWriter::class)->create([
            'title' => $title, 'description' => $description !== null ? mb_substr($description, 0, 2000) : null, 'location' => null,
            'start_at' => $start->toDateTimeString(), 'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(), 'all_day' => false,
            'owner_id' => $ownerId, 'linked_type' => $linkedType, 'linked_id' => $linkedId,
        ], $tenant, self::currentSyear($tenant), $actorId ?: $ownerId);
        if (! $r['ok']) {
            throw new \RuntimeException('The task could not be scheduled: '.($r['reason'] ?? 'unknown reason'));
        }

        return (int) $r['id'];
    }
}
