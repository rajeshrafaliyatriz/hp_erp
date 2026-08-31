<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Example: run your sync command daily at 6 PM
         $schedule->command('sync:data')->dailyAt('18:00');

        /*
         * DRAIN THE EVENT STORE INTO ITS PROJECTIONS.
         *
         * Without this, recorded events are never read: g2g_event held 44 task
         * events and g2g_event_delivery held one row, for one consumer, from one
         * hand-written call in an LMS controller. Competency evidence and the task
         * audit log were both waiting on this same missing dispatch.
         *
         * EVERY FIVE MINUTES, not daily. Evidence backs a decision somebody has
         * just been told about — an employee whose task was rejected should not
         * wait until tomorrow for the record of it to exist.
         *
         * withoutOverlapping() because catchUp() is idempotent but not free: two
         * overlapping runs would both scan the undelivered backlog against a
         * remote database for no benefit.
         */
        $schedule->command('events:project')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        // Load all commands from app/Console/Commands
        $this->load(__DIR__.'/Commands');

        // You can also define console-only routes here
        require base_path('routes/console.php');
    }
}
