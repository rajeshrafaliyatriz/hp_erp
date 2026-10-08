<?php

use App\Services\Platform\TaskRunLedger;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * EVERY ENTRY BELOW IS WRAPPED IN TaskRunLedger::track().
 *
 * `g2g_platform_task_runs` has existed since Round 1 and `ScheduleReader` has always been
 * able to read it — the Scheduler console has been saying "Last-run times are not recorded
 * yet" because nothing ever wrote to it. `track()` is the writer: a `before()` hook stamps
 * a start row, an `after()` hook closes it with the real exit code and the first 2000
 * characters of whatever the command printed. See `TaskRunLedger`'s class docblock for why
 * the two hooks correlate through the row itself rather than a shared variable.
 */

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
TaskRunLedger::track(
    Schedule::command('readiness:recompute --quiet-summary')
        ->dailyAt('02:00')
        ->withoutOverlapping()
        ->onOneServer(),
    'readiness.recompute'
);

/*
 * TASK/EVENT RECURRENCE — ROLLING 90-DAY MATERIALIZATION WINDOW.
 *
 * An open-ended series ("repeat weekly, forever") only has its first 90 days
 * written at the moment it is created — without this, nothing ever advances
 * `materialized_through`, so the series would quietly stop appearing on the
 * calendar after 90 days despite never having been told to end.
 */
TaskRunLedger::track(
    Schedule::command('calendar:materialize-recurrences')
        ->dailyAt('02:15')
        ->withoutOverlapping()
        ->onOneServer(),
    'calendar.materialize_recurrences'
);

/*
 * REMINDERS — fired every 5 minutes, not continuously. A reminder's own
 * precision is "minutes before", so this cadence is within the granularity
 * the feature already promises.
 */
TaskRunLedger::track(
    Schedule::command('calendar:fire-reminders')
        ->everyFiveMinutes()
        ->withoutOverlapping()
        ->onOneServer()
        ->runInBackground(),
    'calendar.fire_reminders'
);

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
TaskRunLedger::track(
    Schedule::command('events:project')
        ->everyFiveMinutes()
        ->withoutOverlapping()
        ->onOneServer()
        ->runInBackground(),
    'events.project'
);

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
TaskRunLedger::track(
    Schedule::command('events:react')
        ->everyTenMinutes()
        ->withoutOverlapping()
        ->onOneServer()
        ->runInBackground(),
    'events.react'
);

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
TaskRunLedger::track(
    Schedule::command('certifications:scan-expiry')
        ->dailyAt('07:00')
        ->withoutOverlapping()
        ->onOneServer()
        ->runInBackground(),
    'certifications.scan_expiry'
);

/*
 * Walks every in-flight Department Process run forward past any `wait_delay`
 * step whose due_at has elapsed, and flags task/approval/milestone/decision
 * steps closing in on their own due_at. Every 15 minutes rather than daily
 * (unlike the certification sweep above) - a two-hour wait step in a run
 * should not sit until the next morning's cycle to advance. Idempotent: a
 * wait step is only ever `in_progress` once, and a due-soon flag checks for
 * its own prior emission before writing a second one.
 */
TaskRunLedger::track(
    Schedule::command('process-runs:scan-due')
        ->everyFifteenMinutes()
        ->withoutOverlapping()
        ->onOneServer()
        ->runInBackground(),
    'department_process_runs.scan_due'
);

/*
 * Pre-existing, carried across from the Kernel with its original timing.
 */
TaskRunLedger::track(
    Schedule::command('sync:data')
        ->dailyAt('18:00')
        ->withoutOverlapping()
        ->onOneServer(),
    'sync.data'
);

/*
 * F-108. hrms_leave_workflow_settings has always offered "escalate after 24
 * hours", every live tenant has it switched on, and nothing has ever escalated
 * anything — there was no approval chain to escalate and no job to do it.
 *
 * Hourly, not more often: one hour is the finest granularity the configuration
 * screen offers, so a shorter interval is work that cannot change an outcome.
 * escalated_at is one-shot, so a re-run escalates nothing twice.
 */
TaskRunLedger::track(
    Schedule::command('leave:escalate')
        ->hourly()
        ->withoutOverlapping()
        ->onOneServer()
        ->runInBackground(),
    'leave.escalate'
);

/*
 * ROUND 4. The same sweep as leave:escalate, for the other six declared
 * workflow points ApprovalEngine enforces — one command across all of them,
 * not one per domain, since escalateOverdue() already sweeps
 * g2g_platform_approval_steps in a single pass regardless of which point or
 * tenant a step belongs to.
 *
 * Hourly, same reasoning as leave:escalate — an SLA is configured in whole
 * hours, so nothing finer-grained could change an outcome.
 */
TaskRunLedger::track(
    Schedule::command('approvals:escalate')
        ->hourly()
        ->withoutOverlapping()
        ->onOneServer()
        ->runInBackground(),
    'approvals.escalate'
);

/*
 * AI SIGNALS ENGINE — DAILY.
 *
 * Time and timezone come from config/signals.php (SIGNALS_SCHEDULE_TIME,
 * SIGNALS_TIMEZONE; default 10:00 Asia/Kolkata), so changing the schedule needs
 * an .env edit, not a code change. SIGNALS_SCHEDULE_ENABLED=false stops it.
 *
 * withoutOverlapping() + onOneServer() so two scheduler ticks never generate the
 * same day twice; SignalRunner also holds a per-organisation lock and de-duplicates
 * by fingerprint, so a retry is safe. runInBackground() keeps a slow AI call from
 * delaying the other scheduled tasks.
 *
 * Like every entry here, this only fires while `php artisan schedule:work` (or a
 * per-minute cron / Windows Task Scheduler entry) is running.
 */
if (config('signals.schedule_enabled')) {
    $signalsTime = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) config('signals.schedule_time'))
        ? config('signals.schedule_time')
        : '10:00';

    TaskRunLedger::track(
        Schedule::command('signals:generate')
            ->dailyAt($signalsTime)
            ->timezone(config('signals.timezone', 'Asia/Kolkata'))
            ->withoutOverlapping(60)
            ->onOneServer()
            ->runInBackground(),
        'signals.generate'
    );
}

/*
 * COMPANY OPPORTUNITY RESEARCH — CHECKED EVERY 15 MINUTES, RUNS ONCE PER DAY.
 *
 * The run time is a per-organisation setting (Product Profile -> schedule time,
 * default SIGNALS_RESEARCH_TIME=10:00 in SIGNALS_TIMEZONE=Asia/Kolkata), so it can be
 * changed from the UI with no code or .env edit. The tick itself is cheap: the command
 * runs an organisation only when its time has passed today and no scheduled run exists
 * for today - which also means a machine that was off at 10:00 catches up on its next
 * tick instead of skipping the day.
 *
 * Like every entry here it only fires while `php artisan schedule:work` (or a per-minute
 * cron / Windows Task Scheduler entry) is running.
 */
TaskRunLedger::track(
    Schedule::command('signals:research')
        ->everyFifteenMinutes()
        ->withoutOverlapping(120)
        ->onOneServer()
        ->runInBackground(),
    'signals.research'
);

/*
 * DOCUMENT TRASH — PURGED DAILY.
 *
 * `destroy()`/`destroyForEmployee()` only ever soft-delete, so a trash view
 * can offer restore. Without this, nothing ever turns that into a real
 * deletion and trash grows forever. `--execute` is required because the
 * command is dry-run by default (see its own docblock) - omitting it here
 * would schedule a no-op that never actually purges anything.
 *
 * Runs against BOTH mysql and live by default (the command's own
 * --database= loop) - one scheduled entry, not two.
 */
TaskRunLedger::track(
    Schedule::command('documents:purge-trash --execute')
        ->dailyAt('03:00')
        ->withoutOverlapping()
        ->onOneServer()
        ->runInBackground(),
    'documents.purge_trash'
);

/*
 * IDMS TRASH - PURGED DAILY (30-day retention, config/idms.php). Only runs if
 * the server's `schedule:run` cron is active.
 */
TaskRunLedger::track(
    Schedule::command('idms:purge-trash')
        ->dailyAt('03:30')
        ->withoutOverlapping()
        ->onOneServer()
        ->runInBackground(),
    'idms.purge_trash'
);
