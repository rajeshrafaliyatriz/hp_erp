<?php

namespace App\Services\TaskManagement;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * RFC 5545 (iCalendar) export/import — hand-written, not a library. This
 * codebase has no iCalendar package installed and this session has no
 * network access to add one; CRM itself made the same call
 * (generateIcsAttachment() is custom code there too). Scope is deliberately
 * narrow: single VEVENT blocks, no VALARM/VTIMEZONE/RRULE encoding — this
 * product already materializes recurrence as physical rows (see
 * CalendarRecurrenceService), so there is no rule to round-trip through an
 * RRULE line, only plain dated events.
 */
class CalendarIcsService
{
    private const DOMAIN = 'hp-erp.triz.co.in';

    /** One event, as an email-invite attachment. */
    public function exportEvent(int $eventId, int $subInstituteId, string $syear): ?string
    {
        $event = DB::table('task_management_calendar_events')
            ->where('id', $eventId)->where('sub_institute_id', $subInstituteId)->where('syear', $syear)
            ->whereNull('deleted_at')->first();

        if (!$event) {
            return null;
        }

        return $this->wrap([$this->vevent($event)]);
    }

    /**
     * A date range, tasks and events both - the same two sources
     * CalendarFeedService merges for the on-screen grid, so what a user
     * exports matches what they were just looking at. Milestones/checkpoints
     * are left out: they belong to a project, not a person's calendar, and
     * CRM's own export was per-user to begin with.
     *
     * @param array<int, array<string, mixed>> $entries from CalendarFeedService::range()
     */
    public function exportEntries(array $entries): string
    {
        $blocks = [];
        foreach ($entries as $entry) {
            $blocks[] = $this->veventFromEntry($entry);
        }

        return $this->wrap($blocks);
    }

    /**
     * Parse an uploaded .ics file into calendar_events, deduping on
     * `ical_uid` within this tenant - importing the same file twice (or a
     * calendar re-exported after a round trip) must not create duplicates.
     *
     * @return array{imported:int, skipped:int, details:array<int, array{uid:?string, summary:string, reason:string}>}
     */
    public function import(string $icsText, int $subInstituteId, string $syear, int $ownerId, int $actorId): array
    {
        $blocks = $this->splitVevents($icsText);
        $imported = 0;
        $details = [];

        foreach ($blocks as $raw) {
            $fields = $this->parseVevent($raw);
            $summary = $fields['SUMMARY'] ?? '(untitled)';
            $uid = $fields['UID'] ?? null;

            if ($uid && DB::table('task_management_calendar_events')
                ->where('sub_institute_id', $subInstituteId)->where('ical_uid', $uid)->whereNull('deleted_at')->exists()) {
                $details[] = ['uid' => $uid, 'summary' => $summary, 'reason' => 'Already imported (matching UID).'];
                continue;
            }

            $start = $this->parseIcsDateTime($fields['DTSTART'] ?? null);
            $end = $this->parseIcsDateTime($fields['DTEND'] ?? null) ?? $start;
            if (!$start) {
                $details[] = ['uid' => $uid, 'summary' => $summary, 'reason' => 'No DTSTART - skipped.'];
                continue;
            }

            DB::table('task_management_calendar_events')->insert([
                'sub_institute_id' => $subInstituteId, 'syear' => $syear,
                'title' => mb_substr($summary, 0, 191),
                'description' => $fields['DESCRIPTION'] ?? null,
                'location' => isset($fields['LOCATION']) ? mb_substr($fields['LOCATION'], 0, 191) : null,
                'start_at' => $start, 'end_at' => $end,
                'all_day' => isset($fields['DTSTART']) && !str_contains($fields['DTSTART'], 'T'),
                'status' => 'Planned', 'visibility' => 'PUBLIC',
                'owner_id' => $ownerId, 'ical_uid' => $uid,
                'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $imported++;
        }

        return ['imported' => $imported, 'skipped' => count($details), 'details' => $details];
    }

    /** @return array{ok:bool, reason:?string, attendee_id:?int, accept_token:?string} */
    public function invite(int $eventId, ?int $userId, ?string $externalEmail, int $subInstituteId, string $syear): array
    {
        $exists = DB::table('task_management_calendar_events')
            ->where('id', $eventId)->where('sub_institute_id', $subInstituteId)->where('syear', $syear)
            ->whereNull('deleted_at')->exists();
        if (!$exists) {
            return ['ok' => false, 'reason' => 'Event not found.', 'attendee_id' => null, 'accept_token' => null];
        }
        if (!$userId && !$externalEmail) {
            return ['ok' => false, 'reason' => 'An attendee needs a user or an email.', 'attendee_id' => null, 'accept_token' => null];
        }

        $token = Str::random(48);
        $id = DB::table('task_management_calendar_attendees')->insertGetId([
            'event_id' => $eventId, 'user_id' => $userId, 'external_email' => $externalEmail,
            'status' => 'invited', 'accept_token' => $token, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['ok' => true, 'reason' => null, 'attendee_id' => $id, 'accept_token' => $token];
    }

    public function attendees(int $eventId): \Illuminate\Support\Collection
    {
        return DB::table('task_management_calendar_attendees as a')
            ->leftJoin('tbluser as u', 'u.id', '=', 'a.user_id')
            ->where('a.event_id', $eventId)
            ->get([
                'a.id', 'a.status', 'a.external_email',
                DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) as user_name"),
            ]);
    }

    /**
     * The attendees table carries no sub_institute_id of its own, so
     * tenancy is only provable by joining through the event it belongs to -
     * the same reason invite()/attendees() both key off $eventId rather
     * than trusting the attendee id alone. Checked then deleted as two
     * plain, tenant-scoped statements rather than one join-delete, matching
     * this module's established idiom elsewhere.
     */
    public function removeAttendee(int $attendeeId, int $eventId, int $subInstituteId): bool
    {
        $belongs = DB::table('task_management_calendar_attendees as a')
            ->join('task_management_calendar_events as e', 'e.id', '=', 'a.event_id')
            ->where('a.id', $attendeeId)
            ->where('a.event_id', $eventId)
            ->where('e.sub_institute_id', $subInstituteId)
            ->whereNull('e.deleted_at')
            ->exists();

        if (!$belongs) {
            return false;
        }

        return DB::table('task_management_calendar_attendees')->where('id', $attendeeId)->delete() > 0;
    }

    /**
     * Accept-only, token-based, no auth - a public link, exactly matching
     * CRM's own one-way "Accept" short-link (no decline/tentative there
     * either).
     */
    public function accept(string $token): array
    {
        $updated = DB::table('task_management_calendar_attendees')
            ->where('accept_token', $token)->where('status', 'invited')
            ->update(['status' => 'accepted', 'responded_at' => now()]);

        return $updated
            ? ['ok' => true, 'reason' => null]
            : ['ok' => false, 'reason' => 'This invitation is invalid or was already accepted.'];
    }

    /* ── RFC 5545 encoding ────────────────────────────────────────────── */

    /** @param array<int, string> $veventBlocks already-joined "BEGIN:VEVENT...END:VEVENT" strings */
    private function wrap(array $veventBlocks): string
    {
        $header = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//hp-erp//Task Management Calendar//EN\r\nCALSCALE:GREGORIAN";

        return $header . ($veventBlocks ? "\r\n" . implode("\r\n", $veventBlocks) : '') . "\r\nEND:VCALENDAR";
    }

    private function vevent(object $event): string
    {
        $uid = $event->ical_uid ?: ($event->id . '@' . self::DOMAIN);

        return $this->veventLines(
            $uid, $event->title, $event->description, $event->location,
            $event->start_at, $event->end_at, (bool) $event->all_day
        );
    }

    /** @param array<string, mixed> $entry one row from CalendarFeedService::range() */
    private function veventFromEntry(array $entry): string
    {
        $uid = $entry['kind'] . '-' . $entry['id'] . '@' . self::DOMAIN;

        return $this->veventLines($uid, $entry['title'], null, null, $entry['start'], $entry['end'], $entry['all_day']);
    }

    private function veventLines(string $uid, string $summary, ?string $description, ?string $location, string $start, string $end, bool $allDay): string
    {
        $lines = [
            'BEGIN:VEVENT',
            'UID:' . $this->escape($uid),
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            $allDay ? ('DTSTART;VALUE=DATE:' . $this->icsDate($start)) : ('DTSTART:' . $this->icsDateTime($start)),
            $allDay ? ('DTEND;VALUE=DATE:' . $this->icsDate($end, true)) : ('DTEND:' . $this->icsDateTime($end)),
            'SUMMARY:' . $this->escape($summary),
        ];
        if ($description) {
            $lines[] = 'DESCRIPTION:' . $this->escape($description);
        }
        if ($location) {
            $lines[] = 'LOCATION:' . $this->escape($location);
        }
        $lines[] = 'END:VEVENT';

        return implode("\r\n", $lines);
    }

    /** Commas/semicolons/newlines are syntax in iCalendar text values and must be escaped. */
    private function escape(string $value): string
    {
        return str_replace(["\\", ',', ';', "\n"], ["\\\\", '\,', '\;', '\n'], $value);
    }

    private function icsDate(string $date, bool $exclusiveEnd = false): string
    {
        $carbon = \Carbon\Carbon::parse(substr($date, 0, 10));
        // DTEND on an all-day VEVENT is EXCLUSIVE per RFC 5545 - a one-day
        // event's end is the NEXT day, or every calendar app renders it as
        // spanning zero days.
        if ($exclusiveEnd) {
            $carbon->addDay();
        }

        return $carbon->format('Ymd');
    }

    private function icsDateTime(string $datetime): string
    {
        return \Carbon\Carbon::parse($datetime)->format('Ymd\THis');
    }

    /* ── RFC 5545 parsing (minimal, matching what we write) ──────────── */

    /** @return array<int, string> raw VEVENT...END:VEVENT blocks, unparsed */
    private function splitVevents(string $ics): array
    {
        $normalised = str_replace("\r\n", "\n", $ics);
        preg_match_all('/BEGIN:VEVENT\n(.*?)\nEND:VEVENT/s', $normalised, $matches);

        return $matches[1] ?? [];
    }

    /** @return array<string, string> property name => value, unescaped */
    private function parseVevent(string $block): array
    {
        $fields = [];
        foreach (explode("\n", trim($block)) as $line) {
            // A `;` introduces parameters (e.g. DTSTART;VALUE=DATE) - the
            // property name is everything before either delimiter.
            if (!str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $name = strtoupper(explode(';', $key)[0]);
            $fields[$name] = str_replace(['\\,', '\\;', '\\n', '\\\\'], [',', ';', "\n", '\\'], trim($value));
        }

        return $fields;
    }

    /** Accepts both `VALUE=DATE` (Ymd) and full datetime (Ymd\THis[Z]) forms. */
    private function parseIcsDateTime(?string $value): ?string
    {
        if (!$value) {
            return null;
        }
        $value = rtrim($value, 'Z');

        if (preg_match('/^\d{8}$/', $value)) {
            return \Carbon\Carbon::createFromFormat('Ymd', $value)->toDateString() . ' 00:00:00';
        }
        if (preg_match('/^\d{8}T\d{6}$/', $value)) {
            return \Carbon\Carbon::createFromFormat('Ymd\THis', $value)->toDateTimeString();
        }

        return null;
    }
}
