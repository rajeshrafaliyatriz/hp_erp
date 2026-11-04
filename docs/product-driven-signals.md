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

The Laravel scheduler is registered in `routes/console.php` and checks due connectors once per minute. Configure the host to run `php artisan schedule:run` every minute (cron on Linux or a per-minute Windows Task Scheduler task). Scheduled work dispatches `SyncDataConnectorJob` onto the configured Signals queue (`SIGNALS_QUEUE`, default `signals`); run a worker such as `php artisan queue:work --queue=signals --tries=4 --timeout=900`. Keep the scheduler and worker running under the deployment's process manager. For multiple application hosts, use a shared cache store that supports atomic locks so `onOneServer` and per-connector overlap prevention work across hosts. No worker, scheduler, or server configuration is started or changed automatically by this feature.
