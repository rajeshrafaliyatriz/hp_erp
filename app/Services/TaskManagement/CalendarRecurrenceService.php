<?php

namespace App\Services\TaskManagement;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE ONE PLACE A RECURRENCE RULE BECOMES PHYSICAL ROWS.
 *
 * Eager materialization, matching CRM's own strategy and this service's own
 * migration docblock: every occurrence is a real `task` or
 * `task_management_calendar_events` row, not a virtual expansion of a stored
 * rule at read time. An open-ended series (`until` is null) is materialized
 * on a rolling horizon — `HORIZON_DAYS` ahead of today — topped up daily by
 * the scheduled command rather than generated in full up front, since "in
 * full" has no end for a series with no `until`.
 *
 * Scope ("this occurrence" / "this and following" / "all") is answered here
 * as a list of affected entry ids; the CALLER applies whatever field change
 * the user actually made to that list, using the entity's own normal write
 * path (LegacyTaskController's update, CalendarController's update) — this
 * class does not duplicate task/event field-validation, it only resolves
 * which rows "this edit" means.
 */
class CalendarRecurrenceService
{
    private const HORIZON_DAYS = 90;

    /**
     * Create or replace the rule anchored on one task/event, and materialize
     * occurrences immediately (eager, for the user's own immediate feedback —
     * the nightly command only tops up the window afterward for open-ended
     * series).
     *
     * @param  array{frequency:string, interval?:int, until?:?string}  $rule
     * @return array{ok:bool, reason:?string, recurrence_id:?int, created_entry_ids:array<int>}
     */
    public function materialize(string $entryType, int $anchorEntryId, array $rule, int $subInstituteId, string $syear, int $actorId): array
    {
        $anchor = $this->findEntry($entryType, $anchorEntryId, $subInstituteId, $syear);
        if (!$anchor) {
            return ['ok' => false, 'reason' => 'Task or event not found.', 'recurrence_id' => null, 'created_entry_ids' => []];
        }

        $anchorDate = $this->entryDate($entryType, $anchor);
        if (!$anchorDate) {
            return ['ok' => false, 'reason' => 'This entry has no date to repeat from.', 'recurrence_id' => null, 'created_entry_ids' => []];
        }

        $interval = max(1, (int) ($rule['interval'] ?? 1));
        $until = $rule['until'] ?? null;
        $horizon = Carbon::today()->addDays(self::HORIZON_DAYS);
        $ceiling = $until ? Carbon::parse($until)->min($horizon) : $horizon;

        $recurrenceId = DB::table('task_management_calendar_recurrences')->where([
            'entry_type' => $entryType, 'entry_id' => $anchorEntryId,
        ])->value('id');

        if ($recurrenceId) {
            DB::table('task_management_calendar_recurrences')->where('id', $recurrenceId)->update([
                'frequency' => $rule['frequency'], 'interval_count' => $interval, 'until' => $until,
                'updated_at' => now(),
            ]);
        } else {
            $recurrenceId = DB::table('task_management_calendar_recurrences')->insertGetId([
                'sub_institute_id' => $subInstituteId, 'syear' => $syear,
                'entry_type' => $entryType, 'entry_id' => $anchorEntryId,
                'frequency' => $rule['frequency'], 'interval_count' => $interval, 'until' => $until,
                'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);

            // The anchor is sequence 1 of its own series — recorded even
            // though it was never "created" by this call, so scope
            // resolution (§affectedEntryIds) has one consistent list to walk
            // rather than treating the anchor as a special case forever.
            DB::table('task_management_calendar_occurrences')->insert([
                'recurrence_id' => $recurrenceId, 'entry_type' => $entryType, 'entry_id' => $anchorEntryId,
                'occurrence_date' => Carbon::parse($anchorDate)->toDateString(),
                'sequence_no' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table($this->table($entryType))->where('id', $anchorEntryId)->update(['recurrence_id' => $recurrenceId]);
        }

        $createdIds = $this->topUp($recurrenceId, $ceiling);

        return ['ok' => true, 'reason' => null, 'recurrence_id' => $recurrenceId, 'created_entry_ids' => $createdIds];
    }

    /**
     * Materialize every occurrence a series is missing, up to $ceiling
     * (clamped to the rule's own `until` if nearer). Called both right after
     * `materialize()` (eager, immediate feedback) and by the daily command
     * (topping up an open-ended series' rolling window).
     *
     * @return array<int> the newly-created entry ids
     */
    public function topUp(int $recurrenceId, Carbon $ceiling): array
    {
        $rule = DB::table('task_management_calendar_recurrences')->where('id', $recurrenceId)->first();
        if (!$rule) {
            return [];
        }

        $effectiveCeiling = $rule->until ? $ceiling->min(Carbon::parse($rule->until)) : $ceiling;

        $anchorOccurrence = DB::table('task_management_calendar_occurrences')
            ->where('recurrence_id', $recurrenceId)->orderBy('sequence_no')->first();
        if (!$anchorOccurrence) {
            return [];
        }

        $lastOccurrence = DB::table('task_management_calendar_occurrences')
            ->where('recurrence_id', $recurrenceId)->orderByDesc('occurrence_date')->first();
        $cursor = Carbon::parse($lastOccurrence->occurrence_date);
        $sequence = (int) $lastOccurrence->sequence_no;

        $anchor = $this->findEntry($rule->entry_type, $anchorOccurrence->entry_id, $rule->sub_institute_id, $rule->syear);
        if (!$anchor) {
            return [];
        }

        $created = [];
        // Bounded — a malformed rule (e.g. interval 0, caught earlier, but
        // defence in depth) must never spin this into an infinite loop.
        for ($guard = 0; $guard < 400; $guard++) {
            $cursor = $this->nextOccurrenceDate($cursor, $rule->frequency, (int) $rule->interval_count);
            if ($cursor->gt($effectiveCeiling)) {
                break;
            }
            $sequence++;

            $newId = $this->cloneEntryOnto($rule->entry_type, $anchor, $cursor, (int) $rule->sub_institute_id, (string) $rule->syear);
            DB::table($this->table($rule->entry_type))->where('id', $newId)->update(['recurrence_id' => $recurrenceId]);
            DB::table('task_management_calendar_occurrences')->insert([
                'recurrence_id' => $recurrenceId, 'entry_type' => $rule->entry_type, 'entry_id' => $newId,
                'occurrence_date' => $cursor->toDateString(), 'sequence_no' => $sequence,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $created[] = $newId;
        }

        DB::table('task_management_calendar_recurrences')->where('id', $recurrenceId)
            ->update(['materialized_through' => $effectiveCeiling->toDateString(), 'updated_at' => now()]);

        return $created;
    }

    /** Every recurrence still open-ended or not yet materialized through the horizon. */
    public function dueForTopUp(): \Illuminate\Support\Collection
    {
        $horizon = Carbon::today()->addDays(self::HORIZON_DAYS);

        return DB::table('task_management_calendar_recurrences')
            ->where(function ($query) use ($horizon) {
                $query->whereNull('until')->orWhere('until', '>', $horizon->toDateString());
            })
            ->where(function ($query) use ($horizon) {
                $query->whereNull('materialized_through')->orWhere('materialized_through', '<', $horizon->toDateString());
            })
            ->get(['id']);
    }

    /**
     * Which entry ids a scope choice affects, given the occurrence the user
     * actually clicked on. Ordered by sequence so "this and following"
     * reads naturally.
     *
     * @return array<int>
     */
    public function affectedEntryIds(string $entryType, int $fromEntryId, string $scope): array
    {
        $from = DB::table('task_management_calendar_occurrences')
            ->where('entry_type', $entryType)->where('entry_id', $fromEntryId)->first();

        if (!$from) {
            // Not part of any series — the only possible scope is "this one".
            return [$fromEntryId];
        }

        $query = DB::table('task_management_calendar_occurrences')
            ->where('recurrence_id', $from->recurrence_id)->orderBy('sequence_no');

        if ($scope === 'this_and_future') {
            $query->where('sequence_no', '>=', $from->sequence_no);
        } elseif ($scope === 'this') {
            $query->where('sequence_no', $from->sequence_no);
        }
        // 'all' — no further filter.

        return $query->pluck('entry_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Mark one occurrence hand-edited, so a later series-wide rule change leaves it alone. */
    public function markException(string $entryType, int $entryId): void
    {
        DB::table('task_management_calendar_occurrences')
            ->where('entry_type', $entryType)->where('entry_id', $entryId)
            ->update(['is_exception' => true, 'updated_at' => now()]);
    }

    /**
     * Soft-delete the entries a scope resolves to. 'all' also removes the
     * series definition itself (cascades to its occurrence rows).
     *
     * @return array{ok:bool, reason:?string, deleted_ids:array<int>}
     */
    public function deleteScoped(string $entryType, int $fromEntryId, string $scope, int $subInstituteId, string $syear, int $actorId): array
    {
        $ids = $this->affectedEntryIds($entryType, $fromEntryId, $scope);
        if (!$ids) {
            return ['ok' => false, 'reason' => 'Nothing to delete.', 'deleted_ids' => []];
        }

        DB::table($this->table($entryType))
            ->whereIn('id', $ids)
            ->where('sub_institute_id', $subInstituteId)
            ->where($entryType === 'TASK' ? 'SYEAR' : 'syear', $syear)
            ->update($entryType === 'TASK'
                ? ['deleted_by' => $actorId, 'deleted_at' => now(), 'updated_at' => now()]
                : ['updated_by' => $actorId, 'deleted_at' => now(), 'updated_at' => now()]);

        if ($scope === 'all') {
            $recurrenceId = DB::table('task_management_calendar_occurrences')
                ->where('entry_type', $entryType)->where('entry_id', $fromEntryId)->value('recurrence_id');
            if ($recurrenceId) {
                DB::table('task_management_calendar_recurrences')->where('id', $recurrenceId)->delete();
            }
        } else {
            DB::table('task_management_calendar_occurrences')
                ->where('entry_type', $entryType)->whereIn('entry_id', $ids)->delete();
        }

        return ['ok' => true, 'reason' => null, 'deleted_ids' => $ids];
    }

    private function nextOccurrenceDate(Carbon $from, string $frequency, int $interval): Carbon
    {
        $next = $from->copy();

        return match ($frequency) {
            'weekly' => $next->addWeeks($interval),
            'monthly' => $next->addMonthsNoOverflow($interval),
            default => $next->addDays($interval), // 'daily'
        };
    }

    private function cloneEntryOnto(string $entryType, object $anchor, Carbon $date, int $subInstituteId, string $syear): int
    {
        if ($entryType === 'TASK') {
            return DB::table('task')->insertGetId([
                'task_title' => $anchor->task_title,
                'task_description' => $anchor->task_description,
                'task_date' => $date->toDateString(),
                'task_type' => $anchor->task_type,
                'status' => 'PENDING', // each occurrence starts fresh, never inheriting a prior one's progress
                'task_allocated' => $anchor->task_allocated,
                'task_allocated_to' => $anchor->task_allocated_to,
                'sub_institute_id' => $subInstituteId,
                'SYEAR' => $syear,
                'created_by' => $anchor->created_by,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // EVENT — same time-of-day, on the new date, with the same duration.
        $start = Carbon::parse($anchor->start_at);
        $end = Carbon::parse($anchor->end_at);
        $durationSeconds = $start->diffInSeconds($end);
        $newStart = $date->copy()->setTimeFrom($start);
        $newEnd = $newStart->copy()->addSeconds($durationSeconds);

        return DB::table('task_management_calendar_events')->insertGetId([
            'sub_institute_id' => $subInstituteId, 'syear' => $syear,
            'title' => $anchor->title, 'description' => $anchor->description, 'location' => $anchor->location,
            'start_at' => $newStart->toDateTimeString(), 'end_at' => $newEnd->toDateTimeString(),
            'all_day' => $anchor->all_day, 'status' => 'Planned', 'visibility' => $anchor->visibility,
            'owner_id' => $anchor->owner_id,
            'created_by' => $anchor->created_by, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function findEntry(string $entryType, int $id, int $subInstituteId, string $syear): ?object
    {
        return DB::table($this->table($entryType))
            ->where('id', $id)->where('sub_institute_id', $subInstituteId)
            ->where($entryType === 'TASK' ? 'SYEAR' : 'syear', $syear)
            ->whereNull('deleted_at')->first();
    }

    private function entryDate(string $entryType, object $entry): ?string
    {
        return $entryType === 'TASK' ? $entry->task_date : $entry->start_at;
    }

    private function table(string $entryType): string
    {
        return $entryType === 'TASK' ? 'task' : 'task_management_calendar_events';
    }
}
