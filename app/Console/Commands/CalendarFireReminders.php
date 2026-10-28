<?php

namespace App\Console\Commands;

use App\Services\TaskManagement\CalendarReminderDispatcher;
use Illuminate\Console\Command;

/**
 * FIRE EVERY REMINDER WHOSE TIME HAS COME.
 *
 * Every 5 minutes, not every minute: a reminder's precision is "minutes
 * before", so a 5-minute worst-case lateness is within the granularity the
 * feature already promises, and it keeps this off the hot path of every
 * request the way a per-minute cron would not.
 */
class CalendarFireReminders extends Command
{
    protected $signature = 'calendar:fire-reminders';

    protected $description = 'Fire every calendar reminder delivery whose time has come, writing into the notifications inbox';

    public function handle(CalendarReminderDispatcher $dispatcher): int
    {
        $fired = $dispatcher->dispatch();
        $this->info("Fired {$fired} reminder(s).");

        return self::SUCCESS;
    }
}
