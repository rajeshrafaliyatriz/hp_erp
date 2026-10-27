# Running the CRM automation for ONE tenant (`crm:automation-tick`)

The platform scheduler (`routes/console.php`) runs `events:react` and `gtm:tick` for **every** tenant. On a shared database that is too broad
for hand testing, and nothing runs them on a developer machine at all. `crm:automation-tick` does the same work through the **same** engine,
event-delivery ledger and cadence code, restricted to one tenant. It is not a second scheduler and it does not replace the platform one.

```
php artisan crm:automation-tick --tenant=6 --dry-run     # report what is waiting; change nothing
php artisan crm:automation-tick --tenant=6               # one pass
php artisan crm:automation-tick --tenant=6 --after-event=10437     # ignore every event up to and including #10437
php artisan crm:automation-tick --tenant=6 --steps=events          # only deliver events (retries,followups,sweeps,events)
```

## One pass, in order
1. retry this tenant's workflow runs that failed transiently and are due;
2. run this tenant's follow-up cadences that are due (paused ones that can resume too);
3. the weekly Opportunity and overdue-Invoice checks (they record events, idempotent per week);
4. deliver this tenant's undelivered workflow events, repeating up to `--rounds` (default 5) so an event one workflow causes - a reply that was
   just classified - reaches the next workflow in the same pass (reply -> classify -> propose meeting times -> approval run).

## What it never does
* enable email sending (`CRM_EMAIL_SENDING_ENABLED` is read, never written; if it is **ON** the follow-up step is skipped unless `--allow-sending`);
* activate a workflow, approve a run, send an invitation, or touch another tenant (every query is filtered by the tenant; proven in `crmtick`);
* deliver an event recorded before a workflow was activated to that workflow (`activated_at <= recorded_at` is still enforced by the engine;
  such an event is consumed as `skipped`, exactly as `events:react` does);
* run notifications (`NotificationDispatcher` has its own global ledger and is left to `events:react`).

## Safe to repeat / run twice
* Delivery is idempotent: the event-delivery ledger plus `UNIQUE (workflow_id, event_id)`; a failed event is ledgered and not re-fired.
* Cadence attempts are claimed atomically; sweeps use weekly idempotency keys.
* A per-tenant lock (`Cache::lock("crm-automation-tick:<tenant>")`, 10 min) stops two passes overlapping: the second prints
  "already running" and exits 0.
* Exit codes: `0` ok / nothing to do / already running; `1` a step failed (details printed); `2` invalid arguments (nothing processed).

## Looping it (optional, NOT started by anything in the repo)
`php artisan schedule:work` would run the **global** schedule. To loop only this tenant, run the command yourself, e.g. every 5 minutes from
Task Scheduler / cron. Start with `--dry-run`, and pass `--after-event=<latest event id>` if you do not want it to look at older events.

## Meeting proposals (3e / 3f)
See `app/Domain/Crm/Leads/LeadMeeting.php`. `3e` (reply classified as `meeting_requested`) reads the lead owner's real calendar and writes a note,
a task and a DRAFT email with free times; `3f` (needs administrator approval) puts a `[Tentative]` event on the owner's calendar. Neither sends an
invitation; there is no outbound calendar channel and the Google Calendar integration (Nango) is not configured. Set `CRM_MEETING_TIMEZONE`
(e.g. `Asia/Kolkata`) so business hours are read in your time zone; the default is the application time zone (UTC here).

Tests: `php tests/Integration/Gtm/http.php crmtick` and `php tests/Integration/Gtm/http.php crmmeeting`.
