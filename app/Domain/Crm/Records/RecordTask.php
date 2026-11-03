<?php

namespace App\Domain\Crm\Records;

use App\Domain\Crm\Leads\LeadActivity;
use App\Services\TaskManagement\CalendarEventWriter;
use Illuminate\Support\Facades\DB;

/**
 * Adds a task to a CRM record: a calendar event linked to it, the way the record's Schedule tab does (slice 7 of the agentic CRM,
 * Docs/agentic-crm/slices/S07-agent-gateway-thin.md). The generalisation of LeadActivity::taskFor, which stays as it is for its callers (the workflow
 * actions and the follow-up cadence).
 *
 * What it adds to taskFor: the record must exist, be live and belong to the caller's organisation (RecordNotFound otherwise, the same answer for
 * "never existed" and "somebody else's"); the due DATE is the caller's, not "N days from now"; and the person the task is for and the person it is
 * made for are both explicit. Tasks are `task_management_calendar_events` rows: `linked_type` is the UPPERCASE name, `syear` is the organisation's CURRENT
 * academic year (LeadActivity::currentSyear: a task stamped with another year is listed on the record but never opens), and `created_by` is the actor.
 * That table has no `created_via` column and gets none; the agent's tasks are traced through g2g_agent_proposals.result_ref.
 *
 * Like RecordNote it does not check the actor's rights: its caller has.
 */
final class RecordTask
{
    /** @var array<string, array{0: string, 1: string}> entity type => [linked_type, the record's own table] */
    private const ENTITIES = [
        'lead' => ['LEAD', 'crm_leads'],
        'contact' => ['CONTACT', 'crm_contacts'],
        'organization' => ['ORGANIZATION', 'crm_organizations'],
        'opportunity' => ['OPPORTUNITY', 'crm_opportunities'],
    ];

    public const MAX_TITLE = 150;

    public const MAX_DESCRIPTION = 2000;

    /**
     * @param  string  $dueDate  Y-m-d. The task starts at 09:00 that day, or at the next whole hour when 09:00 has passed.
     * @return int|null the event id; null when an open task with the same title already exists on the record (so a retry never doubles it)
     *
     * @throws RecordNotFound
     * @throws RecordRefused when the calendar refuses the event
     */
    public function record(int $tenantId, string $entityType, int $entityId, int $ownerId, ?int $actorId, string $title, ?string $description, string $dueDate): ?int
    {
        [$linkedType, $recordTable] = self::ENTITIES[$entityType] ?? throw new \InvalidArgumentException("'{$entityType}' cannot have tasks.");

        $title = mb_substr(trim($title), 0, self::MAX_TITLE);
        if ($title === '') {
            throw new \InvalidArgumentException('A task needs a title.');
        }
        $due = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
        if ($due === false || $due->format('Y-m-d') !== $dueDate) {
            throw new \InvalidArgumentException('A due date is written Y-m-d.');
        }

        $exists = DB::table($recordTable)->where('id', $entityId)->where('sub_institute_id', $tenantId)->whereNull('deleted_at')->exists();
        if (! $exists) {
            throw new RecordNotFound("The {$entityType} was not found.");
        }

        $open = DB::table('task_management_calendar_events')->where('sub_institute_id', $tenantId)->where('linked_type', $linkedType)->where('linked_id', $entityId)
            ->where('title', $title)->where('status', 'Planned')->whereNull('deleted_at')->exists();
        if ($open) {
            return null;
        }

        $start = now()->setDate((int) $due->format('Y'), (int) $due->format('n'), (int) $due->format('j'))->setTime(9, 0);
        if ($start->lessThan(now())) {
            $start = now()->addHour()->startOfHour();
        }

        $result = app(CalendarEventWriter::class)->create([
            'title' => $title, 'description' => $description !== null && trim($description) !== '' ? mb_substr(trim($description), 0, self::MAX_DESCRIPTION) : null, 'location' => null,
            'start_at' => $start->toDateTimeString(), 'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(), 'all_day' => false,
            'owner_id' => $ownerId, 'linked_type' => $linkedType, 'linked_id' => $entityId,
        ], $tenantId, LeadActivity::currentSyear($tenantId), $actorId ?: $ownerId);
        if (! $result['ok']) {
            throw new RecordRefused('The task could not be scheduled: ' . ($result['reason'] ?? 'unknown reason'));
        }

        return (int) $result['id'];
    }
}
