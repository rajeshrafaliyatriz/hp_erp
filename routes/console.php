<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * READINESS GATES — RECOMPUTED DAILY.
 *
 * Without this, ReadinessGateRecomputer is never called and every gate keeps
 * answering with whenever it was last computed by hand. That is exactly how
 * "My Capability" reported 0% coverage for a tenant sitting at 78.9%.
 *
 * DAILY, NOT HOURLY, and the reason is the design: gates carry
 * `sustained_periods` — a value must hold across N consecutive computations
 * before the gate opens. One run per day makes "three consecutive passes" mean
 * three days of holding, which is a meaningful claim. Running hourly would make
 * it three hours, which is not.
 *
 * withoutOverlapping() because a slow run must not have a second one start
 * behind it and advance the counter twice for the same period.
 */
Schedule::command('readiness:recompute --quiet-summary')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| MOVED HERE FROM app/Console/Kernel.php ON 2026-08-31, BECAUSE THAT FILE'S
| schedule() HAS NEVER RUN.
|--------------------------------------------------------------------------
|
| This is a Laravel 11 application: bootstrap/app.php configures
| `commands: routes/console.php`, and the framework no longer calls
| ConsoleKernel::schedule() at all. app/Console/Kernel.php is still on disk and
| still looks authoritative, so three schedule entries were written into it and
| silently never registered. `php artisan schedule:list` listed exactly one task
| — the one defined here — which is how it was caught.
|
| The cost was real and had been live since the M6 work: `events:project` was
| believed to be draining the event store every five minutes and was in fact only
| ever running when somebody typed it. Task audit rows and competency evidence
| were accumulating undelivered between manual runs.
|
| ANYTHING SCHEDULED FOR THIS APPLICATION BELONGS IN THIS FILE. A `$schedule->`
| call in app/Console/Kernel.php is dead code that reads like configuration.
*/

/*
 * DRAIN THE EVENT STORE INTO ITS PROJECTIONS.
 *
 * Without this, recorded events are never read. EVERY FIVE MINUTES, not daily:
 * evidence backs a decision somebody has just been told about — an employee whose
 * task was rejected should not wait until tomorrow for the record to exist.
 *
 * withoutOverlapping() because catchUp() is idempotent but not free; two
 * overlapping runs would both scan the backlog against a remote database.
 */
Schedule::command('events:project')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * REACTORS, ON THEIR OWN SCHEDULE AND THEIR OWN COMMAND.
 *
 * Deliberately NOT folded into events:project. A projector is pure and a rebuild
 * re-runs it harmlessly; a reactor enrols people on courses, issues certificates
 * and sends notifications, so running one twice does it twice. Separate commands
 * mean a future `--consumer` sweep or a replay cannot reach a reactor by accident.
 *
 * Ten minutes rather than five: a notification is worth a little latency to halve
 * the polling, and nothing downstream of a reactor is a live screen waiting on it.
 *
 * onOneServer() matters more here than for the projectors — two hosts running
 * this concurrently would race on the delivery ledger, and the loser's work is a
 * duplicate side effect rather than a duplicate row.
 */
Schedule::command('events:react')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * The emitter the certification renewal chain was always missing — nothing in the
 * application emitted `certification.expiring`, so RemediationRecommender and
 * NotificationDispatcher sat waiting on a signal that was never raised.
 *
 * Daily at 07:00: "your certification lapses in 30 days" changes at most once a
 * day, and should land at the start of a working day rather than overnight. The
 * emission is idempotent per (certification, window) via the store's unique
 * idempotency key, so a re-run emits nothing new.
 */
Schedule::command('certifications:scan-expiry')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * Pre-existing, carried across from the Kernel with its original timing.
 */
Schedule::command('sync:data')
    ->dailyAt('18:00')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * F-108. hrms_leave_workflow_settings has always offered "escalate after 24
 * hours", every live tenant has it switched on, and nothing has ever escalated
 * anything — there was no approval chain to escalate and no job to do it.
 *
 * Hourly, not more often: one hour is the finest granularity the configuration
 * screen offers, so a shorter interval is work that cannot change an outcome.
 * escalated_at is one-shot, so a re-run escalates nothing twice.
 */
Schedule::command('leave:escalate')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
