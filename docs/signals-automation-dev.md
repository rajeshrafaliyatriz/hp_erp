# Signals automation: what actually runs, and a minimal dev-only plan

Verified on the dev machine (`APP_ENV=local`, dev DB `202.47.117.220`) on 14 Oct 2026. Nothing below was enabled; it is a plan.

## What is really running

| Piece | Finding |
|---|---|
| Scheduler | **Nothing runs it.** No `schedule:work` process, no Windows scheduled task for `artisan`. `signals:scheduler:last_tick` has never been written. `php artisan schedule:list` does show `signals:research` (every 15 minutes), so the schedule is registered; it simply never fires. |
| Daily research | All research runs so far were **manual** (UI click, or a command typed by a person). |
| Queue worker | None running. The UI endpoint (`POST /signals/research/run`) queues a job, then `ensureQueueWorkerRunning()` spawns `php artisan queue:work --queue=signals,default --stop-when-empty` with **the first `php` on PATH** (XAMPP 8.2 on this PC; the API itself runs on Herd PHP 8.4). That is why a UI run can work without a standing worker. |
| `documents` queue | 17 `ProcessDocumentPipelineJob` rows have been pending since 7-9 Oct: **no worker ever listens to the `documents` queue** (the spawn only drains `signals,default`). Unrelated to Signals; not touched. |
| 2 failed jobs | `RunOpportunityResearchJob`, 10 Oct, `MaxAttemptsExceededException`. Cause: the database queue's `retry_after` was 90 s while a run takes 30 to 120 s, so the queue re-released a job that was still working and the second attempt hit `tries = 1`. `config/queue.php` now has `retry_after = 960` (above the job's 900 s timeout), so this should not recur. The two rows are historical. A third failure that day was an invalid Gemini key (run #5). |
| A real queued run | Run #70 and #71 (14 Oct): dispatched like the endpoint does, drained by `queue:work --queue=signals --stop-when-empty`. Stages preparing, searching, collecting, analyzing, completed in 28 to 33 s. Rows, sources and rejections persisted; no failed job; nothing left queued. |

## Minimal dev-only plan (not enabled)

1. **Scheduled research needs one process**: `php artisan schedule:work` (or a Task Scheduler entry running `php artisan schedule:run` every minute). The scheduled `signals:research` runs **in that process** (it calls the researcher directly, no queue worker needed).
   It acts only for organisations in `SIGNALS_TENANTS` (empty falls back to organisations that have target accounts, i.e. tenant 6), once per day after `SIGNALS_RESEARCH_TIME` (10:00, `Asia/Kolkata`), and never twice in a day (`report_date` + `trigger = scheduled` check, a per-tenant lock, and importer de-duplication).
2. **UI-triggered runs need a worker**: `php artisan queue:work --queue=signals,default --tries=1 --timeout=900` (queue name `signals`, from `SIGNALS_QUEUE`). Use one PHP binary for both the API and the worker.
3. **Environment**: `QUEUE_CONNECTION=database`, `DB_QUEUE_RETRY_AFTER` (960, must exceed the job timeout), `SIGNALS_SEARCH_DRIVER=tavily` + `TAVILY_API_KEY`, an AI provider key (`AI_PROVIDER=deepseek`, `DEEPSEEK_API_KEY`), `SIGNALS_TENANTS`.
4. **Cost, per scheduled day** (defaults): up to 8 searches (5 catalogue + 2 named schools + 1 to 2 follow-ups), about 2 to 3 AI calls, and up to 6 page reads per verified school (max 3 schools). Roughly 240 searches a month against a 1,000-search Tavily plan. Search results are cached 12 h and official pages 12 h, so a repeated run does not re-pay for the same query or page.
5. **Failure handling**: a job has `tries = 1` and `timeout = 900`; if it dies, `failed()` marks its run `failed`. A run is `partial` when a search failed or a verified school site could not be read, never a clean `success`. A run still `running` after 15 minutes is closed as `timed_out` by the next run. A failed run is retried by running it again: it is safe (de-duplicated).
6. **Observe**: the run row (`g2g_research_runs`: status, stage, stage_message, counts, error_code), `GET /api/signals/research/status`, `php artisan signals:diagnose`, rejections in `g2g_signal_import_rejections` (with reason codes), the audit trail in `g2g_opportunity_events`, and `signals:recheck-dates` for stored signals.

**Do not enable the schedule until** you have decided the tenant list, accepted the monthly search cost, and want automatic daily runs. Enabling it is one command in one terminal; stopping it is closing that terminal.
