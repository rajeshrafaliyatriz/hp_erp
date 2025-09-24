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
