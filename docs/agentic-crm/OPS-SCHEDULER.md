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
2. **Run a queue worker for the `signals` queue** (`php artisan queue:work --queue=signals --tries=4 --timeout=900`, supervised) and make sure `QUEUE_CONNECTION` is not `sync` on the server. A bare `php artisan queue:work` listens to the `default` queue only and would never run Signals jobs (research runs, document analysis, data connector syncs). Manual research runs are queued jobs; the controller checks for a worker (`ensureQueueWorkerRunning`). See "The `signals` queue worker, and data connector syncs" below.
3. **Check it works:** `php artisan schedule:list` shows the tasks; five minutes later `g2g_platform_task_runs` has new rows (`gtm.tick` every 5 minutes); the GTM Monitoring screen stops saying the clock is not running.
4. **Only then, and only on the owner's say-so,** turn on any scheduled research: `SIGNALS_SCHEDULED_RESEARCH=true`, `SIGNALS_TENANTS=<organisation ids>`, `SIGNALS_RESEARCH_TIME` (default 10:00) and `SIGNALS_TIMEZONE` (default Asia/Kolkata). Each scheduled run spends search and AI quota. Today these are the only controls; S7 and S12 replace them with per-organisation settings, a cap and a kill switch.

## The `signals` queue worker, and data connector syncs

Everything Signals queues goes to the queue named by `SIGNALS_QUEUE` (default **`signals`**, `config/signals.php`): research runs, document analysis and **data connector syncs** (`SyncDataConnectorJob`). A bare `php artisan queue:work` listens to the `default` queue only and **never picks these up**. The worker must name the queue.

Commands (from the backend directory):

```bash
# the clock, every minute (cron / Task Scheduler), or kept alive with `php artisan schedule:work`
php artisan schedule:run

# the connector worker, kept alive by a process supervisor
php artisan queue:work --queue=signals --tries=4 --timeout=900
```

`--queue=signals,default` is also fine when the same worker should serve other queues. The numbers matter: the connector job allows 4 tries and 900 seconds, and the worker's `--timeout` must not be below the job's.

What must be true (all of this is server work; none of it can be checked from the repository):

| Requirement | Why |
|---|---|
| `schedule:run` is invoked **every minute** | the scheduler entry `signals:connectors:sync-scheduled` (`routes/console.php`, every minute, `withoutOverlapping(2)`, `onOneServer()`) queues due scheduled syncs **and recovers lost ones**. Without it, no scheduled sync is queued and the clock recovers nothing (recovery also happens when the Data Connectors list is opened or Sync Now is pressed) |
| A supervisor keeps the `signals` worker alive (systemd, Supervisor, a Windows service wrapper such as NSSM, or the platform's equivalent) | `queue:work` is a long-lived process and stops on any fatal error; nothing else restarts it |
| `QUEUE_CONNECTION` is **not** `sync` | with `sync` the "queue" runs inside the web request: a slow provider call blocks the request and a timeout kills it |
| The **active** queue connection's `retry_after` is **greater than** the job timeout (**900**). Supported: `QUEUE_CONNECTION=database` with `DB_QUEUE_RETRY_AFTER` (default **960**). | otherwise the queue hands a still-running sync to a second worker. The recovery threshold is read from the connection actually in use (`queue.default`), never assumed to be the database one. `redis` and `beanstalkd` are read from their own connection, but their shipped `retry_after` is **90**, which is unsafe here: set it above 900 (the app logs `signals.connector_queue_retry_after_unsafe` at most once an hour when it is not). `sync` has no `retry_after` and must not be used in production. For any other driver (e.g. `sqs`) the app cannot read the visibility timeout, so only the job timeout is used: an administrator must set it above 900 s. Keep this relationship if either number is changed |
| The cache store supports atomic locks (`database`, `redis`, `memcached`; `file` only on a single server) | `onOneServer`, `withoutOverlapping`, and the per-connector sync and token-refresh locks use `Cache::lock` |
| After every deployment the worker is restarted (`php artisan queue:restart`, or restart the supervised process) | a running worker keeps the old code in memory; it does not see a new release |

### What each state means, and where to see it

A connector sync is one row in `g2g_data_connector_syncs`, shown in the connector card's sync history (and in the API as `history[].status`).

| Status | Meaning |
|---|---|
| `queued` | accepted and waiting for a worker (or waiting to retry after a rate limit or outage). Sync Now answers **202 queued**, never "done" |
| `running` | a worker claimed it and is writing progress |
| `completed` / `partial` / `skipped` / `failed` | finished; `error_message` says why for the last three |
| `failed` with the message *"queued but no queue worker started it"* | **no worker**: it sat in `queued` past the grace period. Start the worker, then press Sync Now again |
| `failed`/`partial` with the message *"stopped responding ... closed so the connector is not blocked"* | **recovered**: the worker died during the sync (see below) |

Read-only SQL for an administrator: `select id, connector_id, status, records_fetched, records_processed, error_message, updated_at from g2g_data_connector_syncs order by id desc limit 20;` and `select count(*) from jobs where queue = 'signals';` for the backlog (database queue). Failed jobs go to `failed_jobs`.

### When no worker is available

- **Sync Now:** the web request queues the job and, where the host allows it, asks for a short-lived worker that stops when the queue is empty (the same helper Generate Signals uses, `App\Support\QueueWorkerLauncher`). That is a convenience, not a guarantee: where the host forbids starting processes the sync simply stays `queued`. At most one such worker is requested per queue per 5 seconds, and each request is logged as `signals.queue_worker_launch` (`requested`, `skipped_recent`, `not_allowed` or `failed`); `requested` does not prove the worker booted (the process is detached; on Windows this has not been verified at all). A short-lived worker also exits before a delayed retry (rate limit back-off) comes due, so retries rely on the standing worker.
- **Scheduled syncs** start no worker. They need the standing worker above.
- A sync still `queued` after **10 minutes** (first attempt) is closed as failed with the worker command in its message, so the connector stops being blocked. A sync waiting to retry gets its retry delay (up to 15 minutes) on top first. When a worker finally starts it finds the sync already closed and does nothing, so nothing runs twice.

### When a worker dies during a sync

A running sync records that it is alive by writing to its own row (`updated_at`). The **checkpoints that exist** are: the claim (`queued -> running`); right after the provider fetch (social connectors) or the website fetch; before each social item is stored (at most one write per 10 s); before each item's analysis; and, inside the analysis, before each AI chunk, each provider attempt, the news lookup and the lead extraction (at most one write per 10 s). There is **no** heartbeat inside a single provider request, a single website request or a single AI call; those are bounded by their own timeouts, so a sync can legitimately be silent for as long as the slowest of them. Each heartbeat is a conditional write on `status = running`: it can never reopen a closed sync, and it tells a job whose sync was closed under it to stop. If a worker is killed, the queue hands the job out again after `retry_after` (960 s on the database queue), but the job sees a sync that is not `queued` and exits; it cannot resume. The sweep (`DataConnectorSyncReaper`) closes a `running` sync that has written nothing for `max(job timeout, retry_after of the active queue connection) + 60` = **1,020 seconds** (17 minutes) on the shipped database queue, and:

- marks it `failed` (or `partial` if some items were already processed), with the reason above, keeping all history and anything already saved;
- sets the connector's `last_error` and pushes its next scheduled time out (the connection status is left alone: a dead worker is not a broken account);
- releases the dead worker's per-connector lock, **only that sync's own lock** (otherwise the next sync would be skipped for up to 30 minutes). The running job records its lock's owner token under its sync id, and the sweep restores that token for an owner-checked release, so a lock already taken by a newer sync is never freed. If no token is on record the lock is left to expire (30 minutes);
- never starts, retries or re-analyses anything, and does nothing to a finished sync.

If the "dead" worker was merely slow and reports in later, its write is rejected because the sync is already closed, its next heartbeat stops it, and its own lock release cannot free a newer sync's lock. (Without `pcntl` a worker cannot enforce the 900 s job timeout, so a job that is alive but silent for 1,020 s can be closed this way; run workers on Linux with `pcntl`.) The sweep never blocks the screen or button it runs inside: if it fails it logs `signals.connector_sync_sweep_failed` and the connector list, Sync Now and scheduled dispatch carry on, with nothing shown as recovered that was not. Website syncs have a real total time limit: `SIGNALS_INGESTION_URL_TIMEOUT` (12 s) for one fetch including its redirects, and `SIGNALS_INGESTION_URL_OPERATION_TIMEOUT` (30 s) for robots.txt plus the page; a timeout ends the sync as `failed` ("The website did not respond in time."). Every social sync (YouTube, LinkedIn, Facebook, Instagram) reads at most `SIGNALS_CONNECTOR_MAX_FETCH_PAGES` (10) pages. The sweep runs from the scheduler every minute, when the connectors list is loaded, and before a Sync Now is accepted. Then run Sync Now again (or let the schedule do it).

### Status of this in the live environment

Not verified. The only evidence on file is the 3 November 2026 read-only check above (no scheduled task of any module had ever run on the live database). Whether cron/Task Scheduler, the `signals` worker and a supervisor are now running in staging or production needs someone with access to that infrastructure. This repository's tests use in-memory SQLite, a fake queue and mocked HTTP, and prove the code's behaviour only.

## Not verified

Whether any other host (an application server separate from the database host) runs the clock and records elsewhere; the production queue driver; how `signals:research` behaves under `QUEUE_CONNECTION=sync`.
