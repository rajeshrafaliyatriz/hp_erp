<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * DELIBERATELY EMPTY — THIS METHOD IS NEVER CALLED.
     *
     * Laravel 11 removed Kernel-based scheduling. bootstrap/app.php configures
     * `commands: routes/console.php`, and the framework does not invoke
     * ConsoleKernel::schedule() at all. This file survives only because
     * commands() below still loads app/Console/Commands.
     *
     * That is a trap, and it caught this codebase: three tasks were written here
     * — including `events:project`, believed to be draining the event store every
     * five minutes since the M6 work — and not one of them was ever registered.
     * `php artisan schedule:list` showed a single task, the one defined in
     * routes/console.php, which is what exposed it.
     *
     * ALL SCHEDULING NOW LIVES IN routes/console.php. Adding a `$schedule->`
     * call here would compile, read like configuration, and do nothing.
     */
    protected function schedule(Schedule $schedule): void
    {
        //
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
