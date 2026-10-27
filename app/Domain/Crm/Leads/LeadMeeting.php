<?php

namespace App\Domain\Crm\Leads;

use App\Domain\Gtm\GtmAudit;
use App\Services\TaskManagement\CalendarEventWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Meeting scheduling for a lead whose reply asked for a call ("meeting_requested").
 *
 * TWO steps, deliberately apart, because the second commits a person's time:
 *
 *  1. propose()  - SAFE, no approval. Reads the lead owner's REAL calendar (task_management_calendar_events: the events they own and the
 *     ones they are an attendee of), keeps only times inside business hours that nothing else occupies, and writes a note, a task and a
 *     DRAFT email for the owner to review and send. It never invents a time: with no free time in the look-ahead it says so and proposes none.
 *  2. book()     - CONSEQUENTIAL, so the workflow engine holds the run for an administrator's approval first. It re-checks that the first
 *     proposed time is still free and then puts a [Tentative] event on the OWNER's calendar. It sends NO invitation and adds no attendee:
 *     the platform has no outbound calendar-invitation channel and the Google Calendar integration is not configured, so a real invitation
 *     is a person's job. The customer's address is written in the event description for that purpose.
 *
 * IDEMPOTENT: a proposal is keyed by the inbound reply (event idempotency key + a per-reply lock); a booking is keyed by lead + start time.
 * Times are stored in the application time zone like every other calendar row; business hours are read in the configured meeting time zone.
 * LIMITS (also stated to the user in the note): only the internal calendar is consulted; recurring events that are not yet materialised
 * and "Tasks" (date-only) are not visible to it.
 */
final class LeadMeeting
{
    public const MARKER = '[Meeting proposal]';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'duration_minutes' => (int) config('crm.meeting.duration_minutes', 30), 'days_ahead' => (int) config('crm.meeting.days_ahead', 7), 'slots' => (int) config('crm.meeting.slots', 3),
            'window_start' => (string) config('crm.meeting.window_start', '09:00'), 'window_end' => (string) config('crm.meeting.window_end', '17:00'),
            'timezone' => (string) (config('crm.meeting.timezone') ?: config('app.timezone')), 'weekdays_only' => (bool) config('crm.meeting.weekdays_only', true),
            'min_notice_hours' => (int) config('crm.meeting.min_notice_hours', 4), 'buffer_minutes' => (int) config('crm.meeting.buffer_minutes', 0),
        ];
    }

    /**
     * Free slots for one calendar owner, from real calendar rows. Pure with respect to the database (reads only).
     *
     * @param  array<string, mixed>  $cfg  see defaults()
     * @return array{slots: list<array{start: string, end: string}>, busy_checked: int, notes: list<string>}
     */
    public static function freeSlots(int $tenant, int $ownerId, array $cfg, ?CarbonImmutable $from = null): array
    {
        $cfg += self::defaults();
        $tz = new \DateTimeZone($cfg['timezone']);
        $app = new \DateTimeZone((string) config('app.timezone'));
        $now = ($from ?? CarbonImmutable::now())->setTimezone($tz);
        $earliest = $now->addHours(max(0, (int) $cfg['min_notice_hours']));
        $dur = max(5, (int) $cfg['duration_minutes']);
        $buffer = max(0, (int) $cfg['buffer_minutes']);
        $horizonEnd = $now->startOfDay()->addDays(max(1, (int) $cfg['days_ahead']) + 1);

        // everything the owner has booked in the look-ahead, as [start,end) in UTC-comparable timestamps
        $rangeStart = $now->startOfDay()->setTimezone($app)->format('Y-m-d H:i:s');
        $rangeEnd = $horizonEnd->setTimezone($app)->format('Y-m-d H:i:s');
        $attendeeOf = DB::table('task_management_calendar_attendees')->where('user_id', $ownerId)->pluck('event_id');
        $rows = DB::table('task_management_calendar_events')->where('sub_institute_id', $tenant)->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('owner_id', $ownerId)->orWhereIn('id', $attendeeOf))
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['Cancelled', 'Canceled']))
            ->where('start_at', '<', $rangeEnd)->where('end_at', '>', $rangeStart)->get(['start_at', 'end_at', 'all_day']);
        $busy = [];
        foreach ($rows as $r) {
            $s = CarbonImmutable::parse($r->start_at, $app);
            $e = CarbonImmutable::parse($r->end_at, $app);
            if ($r->all_day) {
                $s = $s->setTimezone($tz)->startOfDay();
                $e = $s->addDay();
            }
            $busy[] = [$s->subMinutes($buffer)->getTimestamp(), $e->addMinutes($buffer)->getTimestamp()];
        }

        $notes = [];
        $unmaterialised = DB::table('task_management_calendar_recurrences')->where('sub_institute_id', $tenant)->where('entry_type', 'EVENT')
            ->whereIn('entry_id', DB::table('task_management_calendar_events')->where('owner_id', $ownerId)->where('sub_institute_id', $tenant)->select('id'))
            ->where(fn ($q) => $q->whereNull('materialized_through')->orWhere('materialized_through', '<', $horizonEnd->toDateString()))->exists();
        if ($unmaterialised) {
            $notes[] = 'Some of the owner\'s repeating events are not yet expanded for these dates, so they could not be checked.';
        }

        [$wsH, $wsM] = array_map('intval', explode(':', (string) $cfg['window_start']));
        [$weH, $weM] = array_map('intval', explode(':', (string) $cfg['window_end']));
        $slots = [];
        $want = max(1, (int) $cfg['slots']);
        for ($d = $now->startOfDay(); $d < $horizonEnd && count($slots) < $want; $d = $d->addDay()) {
            if ($cfg['weekdays_only'] && $d->isWeekend()) {
                continue;
            }
            $open = $d->setTime($wsH, $wsM);
            $close = $d->setTime($weH, $weM);
            // earliest start on this day: window start, or the first half-hour boundary after the notice period
            $cursor = $open;
            if ($earliest > $cursor) {
                $m = (int) $earliest->format('i');
                $cursor = $earliest->setTime((int) $earliest->format('H'), 0)->addMinutes($m === 0 ? 0 : ($m <= 30 ? 30 : 60));
            }
            for (; $cursor->addMinutes($dur) <= $close; $cursor = $cursor->addMinutes(30)) {
                $s = $cursor->getTimestamp();
                $e = $cursor->addMinutes($dur)->getTimestamp();
                $clash = false;
                foreach ($busy as [$bs, $be]) {
                    if ($s < $be && $e > $bs) {
                        $clash = true;
                        break;
                    }
                }
                if (! $clash) {
                    $slots[] = ['start' => $cursor->setTimezone($app)->format('Y-m-d H:i:s'), 'end' => $cursor->addMinutes($dur)->setTimezone($app)->format('Y-m-d H:i:s')];
                    break; // one per day: three different days are more useful than three times on one day
                }
            }
        }

        return ['slots' => $slots, 'busy_checked' => count($rows), 'notes' => $notes];
    }

    /** "Tue 27 Oct 2026, 10:00-10:30 (Asia/Kolkata)" for a slot stored in the application time zone. */
    public static function label(array $slot, array $cfg): string
    {
        $app = new \DateTimeZone((string) config('app.timezone'));
        $tz = new \DateTimeZone($cfg['timezone']);
        $s = CarbonImmutable::parse($slot['start'], $app)->setTimezone($tz);
        $e = CarbonImmutable::parse($slot['end'], $app)->setTimezone($tz);

        return $s->format('D j M Y, H:i').'-'.$e->format('H:i').' ('.$cfg['timezone'].')';
    }

    /**
     * Propose times for a lead's meeting request. Safe to repeat for the same reply: the second call changes nothing.
     *
     * @param  array<string, mixed>  $params
     * @return array{already: bool, slots: int, task_id: ?int, draft_email_id: ?int, event_id: ?int}
     *
     * @throws \DomainException when the proposal cannot be made at all (the caller records why)
     */
    public static function propose(int $tenant, int $leadId, int $inboundId, array $params): array
    {
        $cfg = $params + self::defaults();
        $lead = DB::table('crm_leads')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($leadId);
        $inbound = DB::table('crm_inbound_events')->where('sub_institute_id', $tenant)->where('type', 'reply')->find($inboundId);
        if (! $lead || ! $inbound || (int) $inbound->lead_id !== $leadId) {
            throw new \DomainException('That reply or its lead is no longer available.');
        }
        if ($lead->converted) {
            throw new \DomainException('The lead has already been converted, so no meeting was proposed.');
        }
        if (! (int) $lead->assigned_to || ! DB::table('tbluser')->where('id', $lead->assigned_to)->where('sub_institute_id', $tenant)->where('status', 1)->exists()) {
            LeadActivity::note($tenant, $leadId, 'The customer asked for a meeting but the lead has no active owner, so no time could be proposed. Assign an owner first.');
            throw new \DomainException('The lead has no active owner whose calendar can be checked.');
        }
        if ((int) ($lead->email_opt_out ?? 0) === 1) {
            throw new \DomainException('The lead has opted out of email, so no proposal was prepared.');
        }

        $key = "crm.lead.meeting_proposed:{$inboundId}";
        $lock = Cache::lock("crm-meeting:{$tenant}:{$inboundId}", 60);
        if (! $lock->get()) {
            return ['already' => true, 'slots' => 0, 'task_id' => null, 'draft_email_id' => null, 'event_id' => null];
        }
        try {
            if (DB::table('g2g_event')->where('sub_institute_id', $tenant)->where('idempotency_key', $key)->exists()) {
                return ['already' => true, 'slots' => 0, 'task_id' => null, 'draft_email_id' => null, 'event_id' => null];
            }
            $found = self::freeSlots($tenant, (int) $lead->assigned_to, $cfg);
            $slots = $found['slots'];

            return DB::transaction(function () use ($tenant, $lead, $inboundId, $cfg, $found, $slots, $key) {
                $caveat = $found['notes'] === [] ? '' : ' Note: '.implode(' ', $found['notes']);
                $draftId = null;
                if ($slots === []) {
                    LeadActivity::note($tenant, (int) $lead->id, "The customer asked for a meeting, but the owner's calendar has no free {$cfg['duration_minutes']}-minute time in the next {$cfg['days_ahead']} days within {$cfg['window_start']}-{$cfg['window_end']} ({$cfg['timezone']}). No time was proposed - please arrange one with the customer.{$caveat}");
                    $taskId = LeadActivity::task($tenant, $lead, "Meeting requested - no free time found: {$lead->company} ({$lead->lead_no})", 'Look at the owner\'s calendar and agree a time with the customer.', 0, null);
                } else {
                    $lines = array_map(fn ($s) => '- '.self::label($s, $cfg), $slots);
                    LeadActivity::note($tenant, (int) $lead->id, "The customer asked for a meeting. Times that are free on the owner's calendar ({$found['busy_checked']} calendar entr".($found['busy_checked'] === 1 ? 'y' : 'ies')." checked):\n".implode("\n", $lines)."\nA draft email offering them is on the Emails tab. Nothing has been booked or sent.{$caveat}");
                    $taskId = LeadActivity::task($tenant, $lead, "Meeting requested: {$lead->company} ({$lead->lead_no})", "Review the proposed times and the draft email on the Emails tab, then send it:\n".implode("\n", $lines), 0, null);
                    $draftId = self::draftEmail($tenant, $lead, $inboundId, $lines);
                }
                $eventId = GtmAudit::record('crm.lead.meeting_proposed', $tenant, 'crm_leads', (int) $lead->id, null, [
                    'inbound_id' => $inboundId, 'slot_count' => count($slots), 'slot_start' => $slots[0]['start'] ?? null, 'slot_end' => $slots[0]['end'] ?? null,
                    'slots' => $slots, 'timezone' => $cfg['timezone'], 'duration_minutes' => (int) $cfg['duration_minutes'],
                ], null, $key);

                return ['already' => false, 'slots' => count($slots), 'task_id' => $taskId, 'draft_email_id' => $draftId, 'event_id' => $eventId];
            });
        } finally {
            $lock->release();
        }
    }

    /** @param list<string> $lines @return int the Draft email's crm_emails id (never sent by anything in this class) */
    private static function draftEmail(int $tenant, object $lead, int $inboundId, array $lines): int
    {
        $existing = DB::table('crm_emails as e')->join('crm_email_links as l', 'l.email_id', '=', 'e.id')->where('l.linked_type', 'LEAD')->where('l.linked_id', $lead->id)
            ->where('e.status', 'Draft')->where('e.in_reply_to_inbound_id', $inboundId)->where('e.body', 'like', '%'.self::MARKER.'%')->value('e.id');
        if ($existing) {
            return (int) $existing;
        }
        $body = "Hello,\n\nThank you for your reply. I would be glad to arrange a call. These times are open on my calendar:\n\n".implode("\n", $lines)."\n\nPlease tell me which suits you, or suggest another time.\n\nBest regards,\n[Your name]\n\n".self::MARKER.' Review before sending - nothing has been sent.';
        $id = (int) DB::table('crm_emails')->insertGetId([
            'subject' => mb_substr('Re: Meeting with '.($lead->company ?: 'your team').' - proposed times', 0, 255), 'body' => $body, 'to_address' => trim((string) $lead->email) !== '' ? trim((string) $lead->email) : 'unknown@invalid',
            'status' => 'Draft', 'direction' => 'outbound', 'in_reply_to_inbound_id' => $inboundId, 'assigned_to' => (int) $lead->assigned_to, 'created_by' => null,
            'sub_institute_id' => $tenant, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('crm_email_links')->insert(['email_id' => $id, 'linked_type' => 'LEAD', 'linked_id' => (int) $lead->id, 'sub_institute_id' => $tenant, 'created_by' => null, 'created_at' => now()]);

        return $id;
    }

    /**
     * Put a [Tentative] event on the OWNER's calendar for the first proposed time. Runs only after an administrator approved the run.
     * Re-checks the time is still free; sends nothing; adds no attendee. Returns the calendar event id, or throws {@see \DomainException}.
     *
     * @return array{event_id: int, start: string, end: string}
     */
    public static function book(int $tenant, int $leadId, string $start, string $end, string $timezone, ?int $actorId): array
    {
        $lead = DB::table('crm_leads')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($leadId);
        if (! $lead) {
            throw new \DomainException('The lead no longer exists.');
        }
        if ($lead->converted) {
            throw new \DomainException('The lead has already been converted, so no meeting was booked.');
        }
        if (! (int) $lead->assigned_to) {
            throw new \DomainException('The lead has no owner whose calendar the meeting could go on.');
        }
        if ($start === '' || $end === '' || strtotime($end) <= strtotime($start)) {
            throw new \DomainException('The proposal had no usable time (it found no free slot), so nothing was booked.');
        }
        $app = new \DateTimeZone((string) config('app.timezone'));
        if (CarbonImmutable::parse($start, $app)->isPast()) {
            throw new \DomainException('The proposed time has already passed, so nothing was booked.');
        }
        $title = mb_substr("[Tentative] Meeting with {$lead->company} ({$lead->lead_no})", 0, 150);
        $dupe = DB::table('task_management_calendar_events')->where('sub_institute_id', $tenant)->where('linked_type', 'LEAD')->where('linked_id', $leadId)->where('start_at', $start)->where('title', $title)->whereNull('deleted_at')->value('id');
        if ($dupe) {
            throw new \DomainException('That meeting is already on the calendar.');
        }
        $attendeeOf = DB::table('task_management_calendar_attendees')->where('user_id', (int) $lead->assigned_to)->pluck('event_id');
        $clash = DB::table('task_management_calendar_events')->where('sub_institute_id', $tenant)->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('owner_id', $lead->assigned_to)->orWhereIn('id', $attendeeOf))
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['Cancelled', 'Canceled']))->where('start_at', '<', $end)->where('end_at', '>', $start)->exists();
        if ($clash) {
            throw new \DomainException('The owner\'s calendar now has something at that time, so nothing was booked.');
        }
        $r = app(CalendarEventWriter::class)->create([
            'title' => $title, 'description' => "Held after approval for the customer's meeting request. Customer: {$lead->email}. NO invitation was sent - send one yourself once the customer confirms a time.",
            'location' => null, 'start_at' => $start, 'end_at' => $end, 'all_day' => false, 'owner_id' => (int) $lead->assigned_to, 'linked_type' => 'LEAD', 'linked_id' => $leadId,
        ], $tenant, LeadActivity::currentSyear($tenant), $actorId ?: (int) $lead->assigned_to);
        if (! $r['ok']) {
            throw new \RuntimeException('The calendar refused the event: '.($r['reason'] ?? 'unknown reason'));
        }
        LeadActivity::note($tenant, $leadId, 'A tentative meeting was put on the owner\'s calendar for '.self::label(['start' => $start, 'end' => $end], ['timezone' => $timezone]).'. No invitation was sent to the customer.');
        GtmAudit::record('crm.lead.meeting_booked', $tenant, 'crm_leads', $leadId, $actorId, ['calendar_event_id' => (int) $r['id'], 'start' => $start, 'end' => $end], null, "crm.lead.meeting_booked:{$leadId}:{$start}");

        return ['event_id' => (int) $r['id'], 'start' => $start, 'end' => $end];
    }
}
