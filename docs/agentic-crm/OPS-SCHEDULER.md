# Ops: the server clock (needed before any scheduled agent can run)

Written 3 Nov 2026 after the owner asked "what is the scheduler, does it mean agents?". Facts below were read from the code and, where marked, from the live database (read-only).

## What a scheduler is, in this system

It is **not an agent**. It is the server's clock: something that starts a job at a set time without a person pressing a button. Laravel has one clock for the whole platform (`routes/console.php`): the server must run `php artisan schedule:run` **every minute** (a cron entry on Linux, Task Scheduler on Windows, or `php artisan schedule:work` kept alive). Each minute Laravel starts whatever is due: GTM's `gtm:tick` (every 5 minutes: recover stuck sends, daily forecast and customer-health snapshots, deal and invoice sweeps), report sending, calendar reminders, leave and approval escalations, and `signals:research`.

An **agent** is something that does work (find signals, draft an email, score a deal). Every agent has a **trigger**, the thing that starts it: a person pressing a button, the clock, or an event (a lead was created). The clock is one kind of trigger.

## Where the GTM agents stand today (read in source)

The five GTM agents are **button-only**: `GtmAgentService::run` hard-codes `'trigger' => 'manual'`. They do **not** yet use the Comp AI style architecture (agent definitions with a trigger, a gateway that checks rights and budget, an autonomy level per action, run history). That architecture is slices S7 (gateway, autonomy, budget) and S12 (agent builder and triggers, reusing the existing `gtm_workflows` engine with a `run_agent` action).

## What was decided (3 Nov 2026)

Signal research becomes the **first scheduled agent** on that architecture (S12), not a one-off timer: the schedule (days, time, product, B2B/B2C/B2G) is a property of the agent per organisation, `run_research` has an autonomy level (approval by default, because it spends search and AI quota; an administrator may raise it), a monthly credit cap stops it, a kill switch turns every agent off, and each run is logged with its cost in rupees. Building a separate on/off timer now would create a second scheduling system that S12 would have to replace. Until then `SIGNALS_SCHEDULED_RESEARCH` stays off and signal research is started by a person ("Generate signals").

## What is true on the live server today (verified, read-only, 3 Nov 2026)

- `g2g_platform_task_runs` (every scheduled tick is recorded here) holds **2 rows in total**, both from **28 Sep 2026** (`leave.escalate`, `readiness.recompute`): the clock was run by hand once and never since. **No signals task and no GTM task has ever run.**
- `gtm_state`, the table GTM's clock writes its heartbeat to, **does not exist** on live. GTM's own tables are not all on live.
- So **nothing runs on a clock on the live server**, for any module. This is a server task, not a code task: it needs access to the server.

## Steps for whoever administers the production server (before any scheduled agent)

1. **Run the clock every minute.** Linux: `* * * * * cd <path to the backend> && php artisan schedule:run >> /dev/null 2>&1`. Windows: a Task Scheduler entry that runs `php artisan schedule:run` every minute. Or keep `php artisan schedule:work` alive under a process supervisor.
2. **Run a queue worker** (`php artisan queue:work`, supervised) and make sure `QUEUE_CONNECTION` is not `sync` on the server. Manual research runs are queued jobs; the controller checks for a worker (`ensureQueueWorkerRunning`).
3. **Check it works:** `php artisan schedule:list` shows the tasks; five minutes later `g2g_platform_task_runs` has new rows (`gtm.tick` every 5 minutes); the GTM Monitoring screen stops saying the clock is not running.
4. **Only then, and only on the owner's say-so,** turn on any scheduled research: `SIGNALS_SCHEDULED_RESEARCH=true`, `SIGNALS_TENANTS=<organisation ids>`, `SIGNALS_RESEARCH_TIME` (default 10:00) and `SIGNALS_TIMEZONE` (default Asia/Kolkata). Each scheduled run spends search and AI quota. Today these are the only controls; S7 and S12 replace them with per-organisation settings, a cap and a kill switch.

## Not verified

Whether any other host (an application server separate from the database host) runs the clock and records elsewhere; the production queue driver; how `signals:research` behaves under `QUEUE_CONNECTION=sync`.
