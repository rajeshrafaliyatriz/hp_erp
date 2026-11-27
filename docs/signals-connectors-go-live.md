# Signals connectors: what is verified, and the local / live go-live checklists

Everything here is about **Signals → Ingestion Engine → Connectors**: Facebook Page, Instagram Business, YouTube, LinkedIn Organization, Public Web Page, and the four AI sources (Claude, ChatGPT / GPT, DeepSeek, Gemini).

> **Local and live are separate environments with their own database, credentials and worker.** A passing local test proves nothing about live. Nothing in this repository connects to either database; every migration below needs the owner's explicit go and a verified backup first.

## 1. What each connector has actually been verified against

"Mocked" = the provider's answers were simulated in an automated test (in-memory sqlite, every external request blocked). **No connector below has been tested against its real provider with real credentials from this work.** A mocked test proves what G2G does with each kind of answer; it does not prove that a provider accepts your app.

| Connector | Connect | Sync / import | Verified how | Blocked / needs |
|---|---|---|---|---|
| Facebook Page | OAuth (Meta) with page selection, duplicate-page refusal | posts + comments (comments need Meta App Review permission `pages_read_user_content`) | Mocked: OAuth start/callback/state/scopes, page choice, expiry → reconnect, sync lifecycle, pagination bounds, dedupe | Meta app credentials + App Review for comments; real token |
| Instagram Business | OAuth (Meta), account selection, duplicate-account refusal | own media; Business Discovery of listed usernames; comments need `instagram_manage_comments` (App Review) | Mocked as above; discovery mocked only | Meta app + Business/Creator account + App Review |
| YouTube | OAuth (Google), channel discovery, duplicate-channel refusal, token refresh | own videos, Shorts, comments, keyword search (search spends API quota) | Mocked: exchange, declined scope, refresh, revoked token, outage, quota | Google OAuth client; consent-screen verification for outside users; real channel |
| LinkedIn Organization | OAuth (LinkedIn) + organization id, duplicate-organisation refusal | organization posts, **only after LinkedIn approves the app (partner access)** | Mocked: refresh, rotated token | **Blocked by LinkedIn approval** until granted |
| Public Web Page | none (a public URL; SSRF-checked); duplicate-page refusal | one page, change-detected | Mocked fetch with a safe-fetcher stand-in; deadline and robots handling | none beyond a reachable public page |
| Claude / ChatGPT / DeepSeek / Gemini | **secret token** (not OAuth: no vendor lets G2G read a chat or task result) | **delivery** by the scheduled task, or a pasted output; Sync / Sync All import it through the existing quality gate | Mocked end-to-end through the real HTTP layer, the real database queue and `queue:work` (in memory), the real importer and gate; Contacted / Convert to opportunity / Turn into lead verified on a delivered row | The sending side (see section 4). **Not tested against a real scheduled task.** |

**Never shown as Connected unless the backend confirmed it:** a connector becomes `connected` only after a completed token exchange (and, for Meta, a chosen page/account). A cancelled, refused, mismatched, duplicate or failed sign-in leaves it `error` / `permission_required` with the reason, and stores no token.

## 2. Local and live: the parity checklist

Do each line on **both** environments. The right-hand column says how to check without exposing a secret.

| # | Item | Local | Live | How to verify (never print a secret) |
|---|---|---|---|---|
| 1 | `APP_URL` is this environment's own backend address (https on live) | `http://localhost:8000` style | `https://hp.triz.co.in` | `php artisan tinker --execute="dump(config('app.url'));"` |
| 2 | Frontend `NEXT_PUBLIC_API_BASE_URL_DEV` / `_PROD` point at this environment's backend | dev value | prod value | open the browser Network tab: calls go to the right host |
| 3 | `SIGNALS_CONNECTORS_FRONTEND_URL` (where OAuth returns the person) | local frontend address | the live frontend address (not `localhost`) | Connectors screen shows no "localhost redirect in production" warning |
| 4 | OAuth redirect URIs registered at Google, LinkedIn and Meta **match exactly** `YOUTUBE_OAUTH_REDIRECT_URI`, `LINKEDIN_OAUTH_REDIRECT_URI`, `META_OAUTH_REDIRECT_URI` for this environment | local URIs | live URIs | Connectors screen → provider readiness lists the exact URI; compare in each provider console |
| 5 | Provider credentials set **per environment**: `YOUTUBE_OAUTH_CLIENT_ID/_SECRET`, `LINKEDIN_CLIENT_ID/_SECRET`, `META_APP_ID/_SECRET` (+ `META_LOGIN_CONFIG_ID*` if used), or saved by an administrator in Connector Settings | its own | its own | readiness says "ready"; `grep -c "^META_APP_ID=" .env` prints a count only |
| 6 | `QUEUE_CONNECTION=database` (**exactly one such line in `.env`**; the first one wins) | database | database | `grep -n "^QUEUE_CONNECTION" .env` shows one line |
| 7 | A standing worker is running: `php artisan queue:work --queue=signals,default --timeout=900 --max-time=3600`, supervised so it restarts | needed to test Sync | **required** | `ps aux | grep queue:work`; after every deploy `php artisan queue:restart` |
| 8 | Scheduler: cron `* * * * * php artisan schedule:run` (runs scheduled connector syncs and recovery) | optional | required for scheduled sync | `php artisan schedule:list` |
| 9 | `jobs` table exists; `DB_QUEUE_RETRY_AFTER` (default 960) is **greater than** the 900 s job timeout | yes | yes | `php artisan migrate:status \| grep jobs` |
| 10 | Pending migrations known and applied **path by path** (section 5) | local DB | live DB (**MariaDB 10.1.48**: no JSON type, Compact row format) | `php artisan migrate:status \| grep -i pending` |
| 11 | `storage/` and `bootstrap/cache` writable by the web server user | yes | yes (`apache`) | create nothing; check ownership with `ls -ld storage` |
| 12 | CORS: the frontend's exact origin is allowed | `localhost:3000/3001` are built in | the live frontend origin is built in **or** set in `CORS_EXTRA_ORIGINS` (comma separated, full origin) | a login from the live frontend is not blocked |
| 13 | HTTPS end to end on live (the AI delivery secret travels in a header) | not needed | **required** | `curl -sI https://<host>/api/signals/ai-delivery` answers over TLS |
| 14 | Outbound network from the **worker** host to each provider (Google, LinkedIn, Meta Graph, Tavily if used) | yes | yes | provider calls in `storage/logs/laravel.log` show no connection errors |
| 15 | PHP / web server limits: web requests are short (OAuth, Sync, delivery only queue work); the long work runs in the worker (job timeout 900 s) | n/a | `max_execution_time` does **not** need raising | see section 3 |
| 16 | Search credits: `TAVILY_API_KEY`, `SIGNALS_SEARCH_DRIVER`; contact lookup limits `SIGNALS_CONTACT_RESEARCH`, `SIGNALS_CONTACT_DAILY_SEARCHES` (default 40/day for automatic lookups), `SIGNALS_CONTACT_MAX_SEARCHES` (6 per organisation) | off or small | sized to the plan | `php artisan tinker --execute="dump(config('signals_connectors.contact_research'));"` |

No host, tenant id, credential or key is written in code: the only built-in address is the **development default** for the frontend return URL, which a deployment must override (item 3); the readiness check warns when it has not.

## 3. How a request is split so web time limits do not matter

* **OAuth, Set up, Sync, Sync All, paste, delivery** each return within seconds: they write a row and queue a job.
* **The import / fetch / analysis** runs in the `signals` queue worker (job timeout 900 s, retry window 960 s, a stuck sync is recovered after about 17 minutes; an AI delivery stuck in `importing` is imported again after 30 minutes and given up on after 3 tries).
* **If no worker is running,** a queued sync is not "importing". After **one minute** both the AI cards and the social connector cards say that no Signals queue worker has picked it up and what to check. The server marks a sync that nobody picks up as failed after **10 minutes**. Start the worker, then press Sync.
* **The screen never waits for ever:** every connector request is stopped on screen after 45 seconds with a message (the request may still finish; refresh shows the truth). Each connector locks only itself.

## 4. AI sources: what can deliver automatically

G2G cannot read a private chat or a scheduled task's result from inside any vendor's app. The scheduled task must **send** its output, or a person pastes it (**Delivery details → Or paste an output**).

| Source | Automatic delivery from the in-app scheduled task? | What is required |
|---|---|---|
| Claude (Scholar Clone Morning Opportunity Feed) | Only if that task's environment can run `curl` to your G2G host. **Not verified.** Cloud routines default to a restricted network allowlist (add your domain under Custom network access); Cowork tasks allow all domains by default but blocks have been reported. | The final-step instruction in `docs/signals-ai-delivery.md`, the secret from **Set up**, and a probe from that environment that returns **401** from `POST /api/signals/ai-delivery` |
| ChatGPT / GPT | **Not documented as possible**: Tasks run in the app, and their event triggers are limited to specific apps. | Run the prompt through the OpenAI API from your own scheduler (cron, Zapier, Make) and POST the result, **or** paste it |
| DeepSeek | **No scheduling or webhooks found** for the chat app. | Run the prompt through the DeepSeek API from your own scheduler and POST, **or** paste |
| Gemini | **Not documented as possible** for in-app scheduled actions (Gemini API webhooks are a separate developer feature). | Run the prompt through the Gemini API (Apps Script / Cloud Scheduler) and POST, **or** paste |

Never claim automatic delivery for a vendor until a real run has delivered once and the card shows it.

## 5. Pending migrations and exact commands (none has been run by this work)

Check first: `php artisan migrate:status | grep -i pending`. Only run the ones that are listed pending, **one at a time**, after a verified backup and the owner's go:

```
php artisan migrate --path=database/migrations/2026_12_29_100000_create_g2g_ai_deliveries_table.php --force
```

(The earlier connector migrations `2026_12_20_100000_add_sync_report_and_monitoring_columns` and `2026_12_28_100000_create_g2g_signal_contacts_table` are additive too; run them the same way if they show pending on an environment.) Do **not** use plain `php artisan migrate` on live when other teams' migrations are pending: it runs all of them.

After migrating: `php artisan config:clear`, `php artisan queue:restart`, confirm exactly one worker, refresh the Connectors screen (no "not set up" message).

Rollback of the new table: `php artisan migrate:rollback --path=database/migrations/2026_12_29_100000_create_g2g_ai_deliveries_table.php`.

## 6. Local go-live checklist (development)

1. `.env`: set the local `APP_URL`, `SIGNALS_CONNECTORS_FRONTEND_URL`, and **your own test credentials** for any provider you want to try (never the live ones).
2. Register the **local** redirect URIs in each provider console (or use provider test users).
3. Migrate the **local** database (`php artisan migrate`); `QUEUE_CONNECTION=database`; run `php artisan queue:work --queue=signals,default`.
4. Set up an AI source from the Connectors screen and `POST` a small valid payload with `curl`; confirm the signal appears, then press Contacted.
5. Run the automated suites (`php artisan test`, `npm test` in `g2gf`).

## 7. Live go-live checklist (production)

1. **Approvals you give:** a verified backup; permission to run each pending migration (section 5); permission to restart the worker.
2. Deploy the backend and the frontend; `php artisan config:clear`; `php artisan queue:restart`.
3. Confirm items 1 to 16 of section 2 for **live** values (especially 3, 4, 6, 7, 12, 13).
4. Supervise the worker (supervisord / systemd, `autorestart`, `stopwaitsecs=960`), one process.
5. Connect **one** social connector (start with Public Web Page, which needs no OAuth), press **Sync Now**, and confirm in this order: the sync row says completed, counts are real, the signals appear under the right selectors, the evidence link opens.
6. Set up the Claude source, store the secret in the task's secret store, probe the network from the task's environment (expect 401), authorise the extra final step, and watch the first delivery arrive.
7. Decide `SIGNALS_CONTACT_RESEARCH` and the Tavily plan before new signals start triggering searches.
