# Product-driven Signals

Which product a run is for decides everything it uses. Nothing is borrowed from another product.

## Where a product's configuration lives

| What | Stored in | Notes |
|---|---|---|
| The product and its capabilities, use cases, readiness, price | `g2g_product_offers`, grouped by `parent_product` (`Scholar K-12`, `EB`, `G2G`) | Edited in Product Portfolio. A price is shown only if one was saved. |
| Who buys it, where, on which events, the searches, the parts | `g2g_icp_profiles` (`product_line`: `scholar`, `eb`, `g2g`), one active per product | Admin API `/api/signals/icp/*`. ScholarClone falls back to the built-in `scholar_k12` definition until it has its own saved ICP; a saved ICP wins setting by setting. |
| Accounts and contacts | `g2g_target_accounts`, scoped by the ICP's `pack` key | A product only ever sees its own pack. |
| Company profile | `g2g_product_profiles` (one per organisation) | Describes the selling company, not a product. Not used to research a product. |
| Which key means which product | `config/signals_products.php` | Maps `scholarclone` / `enterprise_brain` / `g2g` to portfolio name, ICP product line and the stored `business_fit` value. No profile data lives here. |

`ProductCatalog` reads these and returns a `ProductContext` (ICP pack, profile as the prompts read it, parts). A product that is not configured raises `ProductNotConfigured` with the list of what is missing. There is no fallback to another product.

## How the product reaches the pipeline

1. The screen's selected product is sent as `product` on `POST /signals/research/run`, `POST /signals/ingestion/upload|url`, `GET /signals/research/status`, `GET /signals/opportunities`.
2. The API refuses an unknown product (422 `unknown_product`) and an unconfigured one (422 `product_not_configured`, with `missing`) **before** any search, AI call or file is stored.
3. `RunOpportunityResearchJob` carries `productKey` to `OpportunityResearcher::run`, which resolves the context again, writes `product_key` on the run, uses that ICP's searches, accounts and prompt, and stamps each candidate with the product's `business_fit`.
4. School-specific stages (sales pull, school screening, the follow-up search writer) run only for the ScholarClone context.
5. `MarketImporter` keeps one record per product: for products other than ScholarClone the product is part of the fingerprint, and same-event matching only looks at the same product's records.
6. A document or link keeps its `product_key`; its signals are produced with that product's ICP.

## What is stored

- `g2g_company_opportunities.business_fit` (already existed): the product of the record. The product filter uses it, never a title or keyword. `NULL` = unassigned (made before products were recorded), listed under "All products" and "Unassigned".
- `g2g_research_runs.product_key`, `g2g_ingestion_sources.product_key` (migration `2026_12_01_090000`, additive, nullable).
- `php artisan signals:backfill-product [--apply]` fills `product_key` on old runs / documents only when every opportunity they produced is stored for the same product. Dry run by default; it never overwrites a value.

## Scheduled runs

`SIGNALS_PRODUCTS` (default `scholarclone`) lists the products the daily schedule runs. `signals:research --product=enterprise_brain` runs one explicitly. A product that is not configured is recorded as a skipped run with the reason.

## Editing a product's ICP (Product Portfolio -> Ideal Customer Profile)

Administrators configure each product's ICP in the app. The API is addressed by product, never by profile id:

| Call | Effect |
|---|---|
| `GET /api/signals/icp/products/{product}` | The editor state: stored settings, status, version, versions kept, readiness (`ready` / `needs_setup` / `invalid`), the next step. A product with no ICP starts empty; ScholarClone with none saved shows its built-in definition and says so. |
| `POST .../validate` | Server validation of the form; nothing is saved. Returns the problems and anything that would block activation. |
| `PUT .../{product}` | Saves. Edits the product's active ICP (new version, old one kept), else its latest draft, else creates one. `activate: true` also activates it. |
| `PATCH .../{product}/status` | `active` / `draft` / `archived`. Activation re-checks readiness on the server. |

Rules enforced on the server: administrators only; tenant from the token; `product_line` is stamped from the product (a client value is ignored; the generic `PUT /icp/profiles/{id}` refuses to move an ICP to another product); at most one active ICP per product line (activating archives the previous one, history kept); only the settings the editor manages are taken from the request, and any other stored setting (scoring, tiers) is preserved; saving starts no research and calls no provider.

Fields the editor manages: ICP name, ideal customer, description, qualification guidance, customer types, regions, priority cities, product parts, business needs and buying-intent words (signals), searches, exclusions, competitors, vendor words, qualification criteria, and per-run search counts.

## Research-date history (Company Opportunities)

`GET /api/signals/research/dates?product=<key>` lists the days on which the product has a **successful** research run, newest first. Rule (the backend's existing definition of a finished run, as in `last_successful_run`): status `success` or `partial`. Failed, skipped, timed-out and running runs never make a date; a successful run that found nothing still does. Dates are `report_date`, the day the run started in `SIGNALS_TIMEZONE`. Runs with no product (made before products were recorded) belong to no product and are not offered. The endpoint only reads.

`GET /api/signals/opportunities?product=<key>&run_date=YYYY-MM-DD` returns the opportunities saved by that product's successful runs of that day (one row per opportunity, so two runs on one day never duplicate). It reads stored rows and starts nothing. A signal that a later run *updates with new evidence* is attached to that later run and shows under its day.

## Why "Signal generation failed" happened, and what is guarded now

On MariaDB 10.1 (the live host) `Schema::hasColumn()` fails with "Unknown column 'generation_expression'". The research run called it in `storeSources` after the searches had been made, and the catch-all turned it into the generic message. `App\Support\ColumnExists` (SHOW COLUMNS) replaces every such call; a test makes `Schema::hasColumn/getColumnListing` throw and runs a whole research, and a scan test fails if any Signals code or new migration uses them again. A database failure is now reported as `database_error` with the SQLSTATE and the driver's short reason (never SQL, host or values).

## Manual only

The 15-minute `signals:research` schedule is registered only when `SIGNALS_SCHEDULED_RESEARCH=true` (default false). Research otherwise starts only from Generate Signals.

## ScholarClone's definition

A saved ScholarClone ICP that was only *copied* from the built-in definition by `signals:icp-seed` (version 1, no author) no longer replaces that definition: the built-in one (25 searches, LinkedIn, forums, job boards) applies until a person saves their own settings. The editor shows and saves from the definition that actually runs.

## Data Connectors in the Ingestion Engine

Data Connectors supports public website/article URLs and the official YouTube Data API, LinkedIn Posts API, Facebook Pages Graph API, and Instagram Graph API for professional accounts. Social connections use server-side OAuth; access and refresh tokens are encrypted in the connector row and are never returned by the API. Disconnect clears stored credentials. The frontend receives only platform/account metadata and status.

Platform access is limited to the authorized channel, LinkedIn organization, Page, or linked professional Instagram account. API access is contingent on the app's provider-side enrollment, review, granted permissions, account role, and any quota limits:

| Platform | Read operation | Required access |
|---|---|---|
| YouTube | Data API v3 `channels.list` (`mine=true`), then paginated `playlistItems.list` for the authorized channel's uploads playlist | OAuth `youtube.readonly`; YouTube Data API enabled. The adapter does not search arbitrary channels. Default project quota is 10,000 units/day; each `channels.list` and `playlistItems.list` call costs 1 unit. |
| LinkedIn | `GET https://api.linkedin.com/rest/posts?author={encoded organization URN}` with `start`/`count` pagination | LinkedIn API access granting `r_organization_social`; the author must be an organization where the member has an eligible Page role. Personal/member-post access is not requested. |
| Facebook Pages | Graph API `/{page-id}/feed` with cursor pagination; only published items authored by that Page are accepted | Page token obtained for a Page the user can access; `pages_show_list` and `pages_read_engagement`; Meta App Review/Advanced Access may be required. |
| Instagram | Graph API `/{ig-user-id}/media` with cursor pagination | Professional account linked to an accessible Facebook Page; `instagram_basic`, `pages_show_list`, and `pages_read_engagement`; Meta App Review/Advanced Access may be required. |

Adapters retrieve text fields only (YouTube title/description, LinkedIn commentary, Facebook Page post message, Instagram caption); they do not download media, scrape linked pages, retrieve comments, or publish content. Results are capped by `SIGNALS_CONNECTOR_MAX_ITEMS` per sync (default 25). LinkedIn uses `publishedAt` when present; the other providers use their documented content timestamp fields. Missing publication times remain null, and missing Facebook/Instagram permalinks are skipped rather than replaced with a guessed source URL. Google OAuth refreshes YouTube access tokens when possible; LinkedIn requires reauthorization after expiry, and invalid/expired Meta page tokens require reconnecting the account.

Connector content is stored with its platform, original URL, external content ID, provider publication timestamp (only when supplied), retrieval time, tenant, product, optional sub-product offer, customer model, optional validated region scope, connector, and sync-run reference. The panel accepts an optional offer ID and up to five `Country | State | City` regions separated by semicolons; the API validates those region selections. A tenant/connector/external-ID unique key prevents repeated retrieval from creating another source. New items are analyzed by the existing `IngestionAnalyzer` and `OpportunityResearcher::leadsFromDocument` → `QualificationGate` → `MarketImporter` flow, preserving that context on the resulting opportunity. Items without usable text, verifiable evidence, or an ICP-qualified opportunity do not become qualified signals. The All Signals view remains the existing opportunity feed; connectors do not create a parallel signal store.

Connector configuration and sync history are tenant-scoped in `g2g_data_connectors` and `g2g_data_connector_syncs`. The base tables are created by migration `2026_11_04_120000_create_g2g_data_connector_tables.php`; OAuth, account metadata, schedule and provenance columns are added by `2026_11_04_140000_extend_g2g_data_connectors_for_social_sync.php`. These tables intentionally use tenant-scoped application queries rather than foreign keys. A unique tenant/connector/external-ID index protects repeated social fetches; external IDs longer than 180 characters are rejected to preserve uniqueness without exceeding legacy InnoDB index limits. Sync history records fetched, processed, created, duplicate, skipped and failed counts, plus errors and timestamps. A connector schedule is off until explicitly enabled in the panel. Intervals are 15 minutes–7 days and use an IANA timezone; manual Sync Now is independent of the schedule toggle. Rolling back the extension refuses to proceed if any connector row has a null URL, so the rollback cannot partially remove columns while restoring the original non-null URL constraint.

Migration verification has used the project's isolated in-memory SQLite tests only. No MariaDB server was available for an isolated DDL run. `App\Support\ColumnExists` uses `SHOW COLUMNS` rather than Laravel schema column-listing calls that request `generation_expression`, and the unique index is sized for the legacy 767-byte InnoDB key ceiling. This mitigates specific MariaDB 10.1 incompatibilities but does not certify the database: Laravel 12 officially supports MariaDB 10.3+, so a MariaDB 10.1 host is below the framework's supported baseline. SQLite does not prove MariaDB's `ALTER TABLE ... CHANGE`, index metadata, or rollback DDL behavior. Upgrade to a supported MariaDB version or validate both migrations on a disposable MariaDB instance with the deployed Laravel/DBAL versions before applying them to a valuable database.

### OAuth/provider configuration

Set these server environment values, then run `php artisan config:cache` (and restart application/queue processes as part of the deployment):

- `YOUTUBE_OAUTH_CLIENT_ID`, `YOUTUBE_OAUTH_CLIENT_SECRET`, `YOUTUBE_OAUTH_REDIRECT_URI`
- `LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET`, `LINKEDIN_OAUTH_REDIRECT_URI`, `LINKEDIN_API_VERSION` (default `202610`)
- `META_APP_ID`, `META_APP_SECRET`, `META_OAUTH_REDIRECT_URI`, `META_GRAPH_API_VERSION` (default `v26.0`)
- `SIGNALS_CONNECTORS_FRONTEND_URL` (the frontend Ingestion page to return to after authorization)
- Optional: `SIGNALS_CONNECTOR_MAX_ITEMS` (default 25), `SIGNALS_CONNECTOR_SCHEDULE_ENABLED` (default true)

Register each callback URI with its provider. The default callback paths are `/api/signals/ingestion/connectors/oauth/youtube/callback`, `/api/signals/ingestion/connectors/oauth/linkedin/callback`, and `/api/signals/ingestion/connectors/oauth/meta/callback`. Use a stable Laravel `APP_KEY` for encrypted credentials; rotating it requires reconnecting provider accounts. For LinkedIn, enter the numeric organization ID before connecting. For Facebook/Instagram, authorize first, then select one of the returned Pages or linked professional accounts. Missing app configuration, permissions, expired tokens and rate limits are shown as distinct connector states; a connector is not presented as Connected until account authorization is complete.

Both manual and scheduled sync require the connector to be enabled and connected. Disabling or disconnecting a connector prevents either path. Manual Sync Now does not require the per-connector schedule toggle; `SIGNALS_CONNECTOR_SCHEDULE_ENABLED` gates scheduled dispatch globally.

### Scheduler and queue operations

The Laravel scheduler is registered in `routes/console.php` and checks due connectors once per minute. Configure the host to run `php artisan schedule:run` every minute (cron on Linux or a per-minute Windows Task Scheduler task). Scheduled work dispatches `SyncDataConnectorJob` onto the configured Signals queue (`SIGNALS_QUEUE`, default `signals`); run a worker such as `php artisan queue:work --queue=signals --tries=4 --timeout=900`. Keep the scheduler and worker running under the deployment's process manager. For multiple application hosts, use a shared cache store that supports atomic locks so `onOneServer` and per-connector overlap prevention work across hosts. This feature never installs, supervises or reconfigures a scheduler or a standing worker. The one process it can start itself is the optional, best-effort **short-lived worker** that manual **Sync Now** (and Generate Signals / URL analysis) asks for through `QueueWorkerLauncher` where the host allows it. That helper is a convenience, not a replacement for the supervised worker: it is unsupervised, it stops as soon as the queue is empty, so it never serves a **delayed retry** (a rate-limit or outage back-off), it is lost on a restart or deploy, and whether it started is not reported. Scheduled syncs start no worker at all. Staging and production therefore still need the supervised `signals` worker and the per-minute scheduler described here.

### Data connector operations (what is verified, and what is not)

**Verified from source code and by local isolated tests** (`tests/Feature/ConnectorOperationsReadinessTest.php`, `tests/Feature/OpportunityIngestionTest.php`; in-memory SQLite, `QUEUE_CONNECTION=sync`, mocked HTTP):

| Item | Fact |
|---|---|
| Scheduled command | `signals:connectors:sync-scheduled`, registered at `routes/console.php`, `everyMinute()`, `withoutOverlapping(2)`, `onOneServer()`, recorded in the task ledger as `signals.connectors.sync_scheduled` |
| What it selects | up to 100 connectors per tick that are enabled, scheduled, `connected` or `rate_limited`, due (`next_sync_at <= now`) and with no `queued`/`running` sync, oldest first. `SIGNALS_CONNECTOR_SCHEDULE_ENABLED=false` stops it dispatching |
| Job and queue | `SyncDataConnectorJob`, queue `config('signals.queue')` = `SIGNALS_QUEUE`, default `signals`; 4 tries, 900 s timeout, retry back-off 60/300/900 s |
| Queue safety margin | the **active** queue connection's `retry_after` must stay above the 900 s job timeout, otherwise a still-running sync is handed to a second worker. Supported and validated: `database` (default 960 s, `DB_QUEUE_RETRY_AFTER`). `redis` and `beanstalkd` are read from their own connection (their shipped default is 90 s, which is **unsafe** for this job: raise it above 900), and an unsafe value is logged as `signals.connector_queue_retry_after_unsafe`. `sync` has no `retry_after` and is not for production. Other drivers (e.g. `sqs`, whose visibility timeout lives on the queue) are not validated by this app: the stale threshold then uses only the job timeout, and the administrator must set the visibility timeout above 900 s |
| Worker command | `php artisan queue:work --queue=signals --tries=4 --timeout=900`. A bare `php artisan queue:work` listens only to the `default` queue and **never picks up connector syncs** |

**Requires staging infrastructure verification** (nothing in the repository can prove these; no process-manager file for the scheduler or the worker is committed, only prose in `docs/agentic-crm/OPS-SCHEDULER.md`):

1. Something runs `php artisan schedule:run` every minute (cron, Windows Task Scheduler, or `schedule:work` under a supervisor). Evidence: new rows in `g2g_platform_task_runs` for `signals.connectors.sync_scheduled`.
2. A supervised worker is running for the `signals` queue under the same environment and code version as the web application, and is restarted on deploy. Evidence: a queued test sync moves from `queued` to `running` to `completed` within a minute.
3. `QUEUE_CONNECTION` is not `sync` and the cache store supports atomic locks (needed by `onOneServer` and the per-connector sync and token-refresh locks).
4. Real provider sign-in and sync per `docs/signals-connector-admin-setup.md`, section 8.

**Requires production administrator action:** items 1 to 3 above on the production host, plus the provider credentials and approvals. The state of the live scheduler and worker is **not known** from this repository: `OPS-SCHEDULER.md` recorded that no scheduled task had ever run on the live database when it was written (4 Nov 2026), and that is the last evidence available here.

**Sync lifecycle and recovery (implemented; verified by local isolated tests only):**

- Manual **Sync Now** answers `202` with status `queued` and a message that says it stays queued until a worker picks it up. It never reports a queued sync as done. Where the queue is not `sync`, it also asks for a short-lived worker through `QueueWorkerLauncher`, the same helper Generate Signals and URL analysis use; that is a convenience, and a standing worker is still required for scheduled syncs and delayed retries. The launcher spawns at most one worker per queue per 5 seconds (a burst of clicks does not pile up processes) and logs `signals.queue_worker_launch` with the outcome (`requested`, `skipped_recent`, `not_allowed`, `failed`). `requested` means a start was attempted: the process is detached, so whether it actually booted cannot be observed from the web request.
- A worker claims a sync with an atomic `queued -> running` update, so two workers holding the same job cannot both run it, and a job retried after its sync was closed exits without ingesting or analysing anything. Every closing write is a compare-and-set on the stored status, so a slow job that reports in after a recovery cannot overwrite it.
- `DataConnectorSyncReaper` closes syncs that can no longer finish: `running` with no write for `max(job timeout, retry_after of the active queue connection) + 60` seconds (1,020 with the shipped database queue, 900 and 960), and `queued` with no worker for 10 minutes (plus the retry delay, up to 15 minutes, for a sync waiting to retry). It runs from the scheduler every minute, when the connectors list is loaded, and before Sync Now is accepted. It only closes: nothing is started, retried, ingested or re-analysed, history is kept, and the reason is written to the sync's `error_message` and the connector's `last_error`.
- The recovery sweep is housekeeping and never blocks the operation it runs inside: if it throws, `sweepSafely` logs `signals.connector_sync_sweep_failed` (with tenant, exception class and message) and the connector list, Sync Now and scheduled dispatch carry on. Nothing is shown as recovered when it was not; a sync that is genuinely still pending then gets the normal "already running" answer.
- Lock ownership: a running job records the owner token of its per-connector lock under its own sync id. The sweep frees a lost sync's lock only by restoring that token (an owner-checked release), so it can never free the lock of a newer sync; a late job's own `finally` release is owner-checked too. With no token on record, nothing is released and the lock simply expires (30 minutes).
- Zombie jobs: each heartbeat is a conditional write on `status = running`. If the sync was closed under a job (recovered, or finished by a retry), the next heartbeat stops the job: it writes nothing further to the sync or the connector, and an in-progress AI analysis row is closed as `sync_closed`. A heartbeat can never reopen a closed sync.
- Heartbeat checkpoints (what actually exists): the claim (`queued -> running`); after the provider fetch (social) or website fetch; before each social item is stored (throttled to one write per 10 s); before each item's analysis; and, inside the analysis, before each AI chunk, before each provider attempt (`StructuredAi`), before the news lookup and before the lead extraction (throttled). There is **no** heartbeat inside a single provider request, a single website request, or a single `AiModelClient::complete` call. Those are bounded by their own timeouts (below). A timeout is not retried or rotated, and credential failover moves on only after a fast rejection (quota, rate limit, bad key), so the realistic silent stretch is one AI call of at most 90 s; only a chain of up to 20 slow-but-not-timed-out credential attempts could approach the stale threshold.
- Website fetch deadline: the website connector now reads pages through Guzzle's cURL handler, where the request timeout is a real total (connect, headers and body), and the DNS pin (`CURLOPT_RESOLVE`) is honoured. `fetch()`'s timeout (`SIGNALS_INGESTION_URL_TIMEOUT`, 12 s) is shared by every redirect hop, and robots.txt plus the page plus their redirects share `SIGNALS_INGESTION_URL_OPERATION_TIMEOUT` (30 s). The earlier `stream` mode used PHP's stream handler, where the timeout was only per-read and the DNS pin was silently ignored. The system DNS lookup (`gethostbynamel`/`dns_get_record`) runs before the request and PHP cannot interrupt it: it is bounded only by the host resolver's own settings (`timeout`/`attempts` in `resolv.conf`) and, on a worker with `pcntl`, by the 900 s job timeout. What the app does guarantee: the lookup counts against the operation deadline (if it used the budget up, nothing is requested and the sync fails as a timeout), a resolver that throws or finds nothing is a DNS failure, every returned address must be public, and nothing falls back to an unpinned request. A timeout ends the sync as `failed` with "The website did not respond in time."
- Every social connector (YouTube, LinkedIn, Facebook, Instagram) reads at most `SIGNALS_CONNECTOR_MAX_FETCH_PAGES` (default 10; 0 or blank also means 10, never unlimited) pages per sync, in addition to `SIGNALS_CONNECTOR_MAX_ITEMS`, and stops early on an empty page or a repeated cursor/token. A provider error on any page fails the fetch (a rate limit stays a rate limit, with its `Retry-After`); a partly read feed is never reported as a complete one.
- Full commands, requirements and status meanings: `docs/agentic-crm/OPS-SCHEDULER.md`, section "The `signals` queue worker, and data connector syncs".

### Company Opportunities: the Region also sets the sub-product's ICP Region

When Generate Signals is pressed for **one sub-product** with **a Region chosen**, the backend (`SubProductIcpRegionSync`, called from `POST /api/signals/research/run`) saves those places as the `regions` of that sub-product's ICP **for the chosen customer type (B2B, B2C or B2G)**. The ICP change and the run record are saved in one transaction: if either fails, neither is kept.

- **Identity.** In this application the matching ICP is: organisation (`sub_institute_id`) + product (`product_key`) + sub-product (portfolio offer, `offer_id`) + customer model. A product has no ICP of its own, and "Whole Product" is not an ICP identity. The ICP is stored in `g2g_product_offers.icp_config` (per model) and in `g2g_icp_profiles` (key `sub_<offer>[_<model>]`, unique per organisation); both are written. Nothing is matched by display name.
- **Existing ICP:** only `regions` is replaced (a new version, the old one kept, with a note). Every other setting is untouched. The same places again, in any order or capitalisation, change nothing.
- **No ICP yet:** one is created from what research already builds for that sub-product at run time, made valid as a saved ICP without adding anything the offer does not say: triggers are only the ones the offer itself lists (`trigger_signals`), each with its own words as the phrase. A sub-product that lists no trigger signals, or whose ICP would not pass the normal ICP validation, gets **no** ICP: the request answers 422 `icp_region_sync_failed` with the reasons, nothing is written and no run starts. (Previously such a sub-product ran on the unsaved run-time fallback; it now needs its ICP, or Whole Product.)
- **Never touched:** Whole Product (any customer type), All Regions (no place chosen), another organisation's or another product's offer, and shared catalogue offers (organisation-less): their map would be shared by every organisation.
- **Response:** `data.icp_sync` = `{status: created | updated | unchanged | skipped, reason, icp, message}`. The screen shows a message only for `created` and `updated`.
- The Region is stored by name (country / state / city) as `RegionScope` validates it; the application has no region master with ids. A selection is saved as its most specific place (the city, else the state, else the country), as a run already names it.

### Region: Country > State / Province > City, with codes

The Region control on Company Opportunities has three searchable, dependent dropdowns: **Country**, **State / Province / Region**, **City**. A new selection starts on India; a place already saved is never replaced by India. Changing the country clears the state and city; changing the state clears the city.

- **Data.** A local extract of the Countries States Cities Database (250 countries, 5,327 states / provinces, 156,169 cities), in `resources/data/locations/` (ODbL-1.0: see `NOTICE.md` there, including the share-alike question to confirm before the data is exposed outside the organisation). No external service is called at run time. It is community data: a missing place is a gap in the source. Some countries are modelled differently from what a user may expect (for example the United Kingdom lists local authorities as "states", and London is filed under Westminster); 21 territories have no states at all, and the screen says so instead of inventing any.
- **Endpoints** (signed-in users; the data is the same for every organisation):
  - `GET /api/signals/locations/countries[?q=]` returns `{default_country_code, countries: [{code, iso3, name, emoji, has_states}], data_version}`.
  - `GET /api/signals/locations/states?country=<code or name>[&q=]` returns `{country, states: [{code, name, type}], has_states, label, has_cities, message}`; 422 `unknown_country`.
  - `GET /api/signals/locations/cities?country=&state=[&q=&limit=]` returns `{country_code, state_code, cities: [{id, name}], total, truncated, message}`. Searched on the server, starts-with first, 50 by default and never more than 500; 422 `unknown_country`, `state_required`, `unknown_state`.
  - `POST /api/signals/locations/validate` with `{country, state, city, country_code, state_code, city_id}` returns the place with its codes, or 422 `invalid_region` with the reasons. Saves nothing.
  - `GET /api/signals/locations` (names only) still works and now lists every country and the real states and cities.
- **Region payload.** Every endpoint that takes `regions` (Generate Signals, status, dates, list) accepts up to five places `{country, state?, city?, country_code?, state_code?, city_id?}`. The backend resolves and validates each one: a state must belong to its country and a city to its state (by the database's codes and ids when given, by name otherwise), else 422 `invalid_region` with a reason such as "state: 'Texas' is not a state, province or region of India." A country-only place is valid, and so is a city without a state only for a country the data lists no states for. A place the data does not know (an older saved region, or one added with "add custom location") is kept exactly as typed, flagged `verified: false`, and never converted to another country. The seed places the control shipped with before this are still accepted.
- **What is stored.** A run and an opportunity keep `region_scope` exactly as before: canonical names `[{"country","state","city"}]`, because records are matched by that text and this change must not orphan saved scopes (so `USA` is now stored and matched as `United States`). The structured form with codes is returned by the API, saved in the browser with the selection, and saved in the sub-product's ICP as `locations` (`country`, `country_code`, `state`, `state_code`, `city`, `city_id`, `verified`, `source`) next to `regions`, the plain names research searches with.
- **What generation does with it.** The country biases the web search provider (one country, or none when places span several). The state or city (the most specific place) is added to each search query as a quoted term, and a candidate qualifies only if its source text itself names the requested city or state. The search provider is **not** asked to filter by state or city: that targeting is query text plus the source check, not a provider geo-filter.
