<?php

namespace App\Console\Commands;

use App\Services\TaskManagement\CalendarRecurrenceService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * TOP UP EVERY OPEN-ENDED RECURRING SERIES' ROLLING WINDOW.
 *
 * CalendarRecurrenceService materializes eagerly out to a 90-day horizon the
 * moment a rule is created or edited — that's immediate feedback for the
 * person setting it up. A series with no `until` would otherwise run dry the
 * day after it was created: nothing advances `materialized_through` except
 * this command, so without it, a "repeat weekly, forever" task would in
 * practice repeat for 90 days and then silently stop.
 *
 * Daily, not more often: a series' occurrences are dates, not minutes, so
 * there is no value in checking more than once a day, and this keeps the
 * write load on `task`/`task_management_calendar_events` to one small batch
 * per series per day rather than one per request.
 */
class CalendarMaterializeRecurrences extends Command
{
    protected $signature = 'calendar:materialize-recurrences';

    protected $description = 'Extend every open-ended recurring task/event series out to its rolling materialization horizon';

    public function handle(CalendarRecurrenceService $recurrence): int
    {
        $due = $recurrence->dueForTopUp();
        $horizon = Carbon::today()->addDays(90);
        $created = 0;

        foreach ($due as $row) {
            $created += count($recurrence->topUp((int) $row->id, $horizon->copy()));
        }

        $this->info("Topped up {$due->count()} series, materializing {$created} new occurrence(s).");

        return self::SUCCESS;
    }
}
