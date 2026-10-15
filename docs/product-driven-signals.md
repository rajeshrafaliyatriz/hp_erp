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
