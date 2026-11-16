# Data connectors: staging deployment and validation guide

Written for the engineer or administrator who sets up and validates a staging environment. Nothing here has been run on staging yet: the repository's tests use in-memory SQLite, mocked provider traffic and a local test web server. Every "expected" value below comes from the code and configuration as committed, not from an observed staging run. Provider sign-in steps are in `docs/signals-connector-admin-setup.md`; operations detail is in `docs/agentic-crm/OPS-SCHEDULER.md`.

## 1. What staging must provide

| Requirement | Why | Check |
|---|---|---|
| Linux host | the short-lived worker helper and `pcntl` job timeouts are only verified in design for Linux; Windows is not verified | `uname -a` |
| PHP 8.2 or newer with the `curl` and `pcntl` extensions | the website fetch needs the cURL handler (timeout and DNS pin); without `pcntl` a worker cannot enforce the 900 s job timeout, so a live but silent job can be closed as lost | `php -m | grep -E "curl|pcntl"` |
| A supervised, persistent `signals` queue worker | scheduled syncs, delayed retries and recovery of waiting work need it; the short-lived worker Sync Now may start is only a convenience | see section 2 |
| The scheduler run every minute | queues due scheduled syncs and closes lost ones | see section 3 |
| `QUEUE_CONNECTION=database` (validated). `redis` or `beanstalkd` only after their own `retry_after` is set above 900 | otherwise the queue re-delivers a sync that is still running; the app logs `signals.connector_queue_retry_after_unsafe` | `php artisan tinker --execute="dump(App\Domain\Signals\Ingestion\DataConnectorSyncReaper::queueDiagnostics());"` must show `safe => true` |
| A cache store with atomic locks (`database`, `redis`, `memcached`) | per-connector sync lock, token-refresh lock, scheduler `onOneServer` | `CACHE_STORE` |
| `APP_URL` is the public HTTPS origin, `APP_KEY` is stable | OAuth callback URLs derive from `APP_URL`; rotating `APP_KEY` makes stored tokens unreadable | `php artisan config:show app.url` |
| Provider app credentials in the host's secret store, not in the repository | never put them in `.env.example` or chat | admin guide |
| The worker is restarted on every deploy | a running worker keeps the old code | `php artisan queue:restart` |

Unsupported or unvalidated: `QUEUE_CONNECTION=sync` (the request itself runs the sync), `sqs` and other drivers whose visibility timeout the app cannot read (only the job timeout is used for recovery; set the visibility timeout above 900 s yourself), Windows workers.

## 2. The worker

The sync job runs on the queue named by `SIGNALS_QUEUE` (default `signals`; `config/signals.php`), with 4 tries, a 900 s timeout and back-off 60/300/900 s (`app/Jobs/SyncDataConnectorJob.php`). The command that matches it:

```bash
php artisan queue:work --queue=signals --tries=4 --timeout=900
```

Run it under a supervisor (systemd, Supervisor) with automatic restart. A bare `php artisan queue:work` only serves the `default` queue and never picks up connector syncs. If `SIGNALS_QUEUE` is changed, change `--queue` with it.

## 3. The scheduler

`php artisan schedule:list` shows `signals:connectors:sync-scheduled` every minute (`routes/console.php`; `withoutOverlapping(2)`, `onOneServer()`). Run the clock every minute:

```cron
* * * * * cd /path/to/g2gb && php artisan schedule:run >> /dev/null 2>&1
```

It first closes lost syncs (even with `SIGNALS_CONNECTOR_SCHEDULE_ENABLED=false`), then queues up to 100 due connectors.

## 4. Timings used below (from the code)

| Quantity | Value | Source |
|---|---|---|
| A sync still `queued` is closed as failed after | 600 s (first attempt); 600 s + up to 900 s when it carries a retry message | `DataConnectorSyncReaper::QUEUED_GRACE_SECONDS`, `MAX_RETRY_DELAY_SECONDS` |
| A `running` sync with no write is closed after | `max(900, retry_after of the active queue) + 60` = 1,020 s on the database queue (`DB_QUEUE_RETRY_AFTER` 960) | `runningThresholdSeconds()` |
| The sweep runs | every minute from the scheduler, on every connectors-list load, and before every Sync Now | |
| Heartbeat throttle | at most one write per 10 s (forced at the checkpoints listed in OPS-SCHEDULER.md) | `SyncDataConnectorJob` |
| Website fetch | 12 s for one fetch including its redirects; 30 s for robots.txt plus the page | `SIGNALS_INGESTION_URL_TIMEOUT`, `SIGNALS_INGESTION_URL_OPERATION_TIMEOUT` |
| Page cap | 10 provider pages per sync | `SIGNALS_CONNECTOR_MAX_FETCH_PAGES` |
| Items per sync | 25 | `SIGNALS_CONNECTOR_MAX_ITEMS` |

Detection happens on the next sweep after a threshold passes, so add up to a minute.

## 5. Validation scenarios

Use one test tenant and a test product. Read-only SQL for every scenario: `select id, connector_id, status, records_fetched, records_processed, records_failed, error_message, updated_at from g2g_data_connector_syncs order by id desc limit 10;`

| # | Scenario | How | Expected | Not proven locally |
|---|---|---|---|---|
| 1 | Normal website sync | Add a public page, Sync Now with the worker running | `queued` then `running` then `completed`; a source and analysis exist; the connector shows Connected | real network, real AI provider |
| 2 | Deliberately slow website | Point a connector at a page that drips its body (a test host you control, not a third-party site) | `failed` within about 12 s: "The website did not respond in time." Nothing ingested | DNS resolver behaviour on the host |
| 3 | Sync Now with the worker stopped | Stop the worker, press Sync Now | `queued` (the button reads Queued and is disabled). About 10 to 11 minutes later: `failed` with the worker command in `error_message`. Starting the worker and pressing Sync Now again works | whether the short-lived helper starts on this host: check the log for `signals.queue_worker_launch` |
| 4 | Worker killed mid-sync | Start a sync on a slow source, `kill -9` the worker | After about 17 to 18 minutes: `failed` (or `partial` if items were processed) with "stopped responding"; connector `last_error` set; the connector is not blocked; its lock is freed | `pcntl` timeout behaviour |
| 5 | Repeated recovery sweep | Run `php artisan signals:connectors:sync-scheduled` three times after scenario 4 | The second and third runs change nothing | true parallel sweeps |
| 6 | Retry after a transient failure | Make the provider return 5xx once | `queued` with a retry message, then a second attempt after the back-off (60 s first) | |
| 7 | Rate limit | Provider answers 429 with `Retry-After` | `queued` with "rate limit" text, connector `rate_limited`, retried after `Retry-After` (at most 900 s); after 4 attempts `failed` | real provider quota |
| 8 | Expired token | Revoke the app's access at the provider, then sync | `failed`, connector `token_expired`, message tells the user to reconnect. No retry | |
| 9 | Provider reconnect | Reconnect from the card | Connector returns to Connected; the next sync works | OAuth with real credentials |
| 10 | Duplicate content | Sync the same unchanged page twice; sync a social account twice | Website: second sync `skipped` ("has not changed"), no new analysis. Social: items counted as duplicates, none re-ingested | |
| 11 | Cross-tenant access | Call a connector id from another tenant's session | 404; nothing changes | |
| 12 | Scheduled sync | Enable a schedule with a short interval | A sync is queued when `next_sync_at` passes, with no click | cron/supervisor of the host |
| 13 | Partial provider response | A provider that fails on page 2 | The whole fetch fails honestly (no partial success reported); nothing ingested for that run | |
| 14 | Recovery then a new sync | After scenario 3 or 4, press Sync Now | Accepted (202); a new row, the old row kept | |

## 6. What to record

For each scenario: date, who ran it, the environment, the observed rows, and the `signals.*` log lines (`connector_sync_recovered`, `connector_sync_sweep_failed`, `connector_sync_abandoned`, `queue_worker_launch`, `connector_queue_retry_after_unsafe`). Do not paste tokens, authorization headers or provider responses into tickets.

## 7. Provider readiness before real authorization

| Provider | Code ready | Needs from the provider | Real OAuth tested | Real sync tested |
|---|---|---|---|---|
| Website | yes (local tests, local misbehaving server) | nothing | n/a | no |
| YouTube | yes (mocked) | Google Cloud project, YouTube Data API v3 enabled, OAuth client; scope `youtube.readonly` | no | no |
| LinkedIn | yes (mocked) | Approved Community Management API access and `r_organization_social`; numeric organization id | no | no |
| Facebook Page | yes (mocked) | Meta app with `pages_show_list`, `pages_read_engagement`; admin or editor role on the Page; Meta may require app review for users without a role on the app | no | no |
| Instagram | yes (mocked) | Instagram professional account linked to an accessible Facebook Page; `instagram_basic` plus the Page permissions | no | no |

The approval requirements are taken from the repository's provider catalog and have not been re-checked against the providers' current documentation in this pass.
