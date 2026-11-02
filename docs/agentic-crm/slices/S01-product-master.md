# S1 — Product Master

**Status:** not started; waits on S0 and on the owner's approval of the S1 mock-up (design gate). **Rough size:** 6–8 sessions.

## Goal

One product record that every other branch (signal, campaign, lead, outreach, forecast) can point at. A thin index over the four existing stores. It is not a merge, so quotes, orders and invoices keep working.

## Why this is first

`crm_products`, `g2g_product_offers`, `g2g_signal_products` (plus `config/signals_products.php`) and `g2g_product_profiles` are four product stores with no link between them. GTM has no product model. Promoting a signal to a lead copies no product. Ten tables hold real foreign keys to `crm_products`. Source: Explore and Plan reports of 2026-11-02 (`agent-read`; re-verify in task 1).

## Database changes

- **NEW `g2g_product_master`:** `id`, `sub_institute_id` BIGINT UNSIGNED NOT NULL (0 = shared), `product_key` varchar(50), `kind` varchar(10) (`family` or `product`), `parent_id` NULL, `name` varchar(191), `status` varchar(10), `origin` varchar(20), `signal_key` varchar(20) NULL, `portfolio_name` varchar(50) NULL (the exact `parent_product` string), `offer_row_id` NULL, `crm_product_id` NULL, `created_by`, `updated_by`, timestamps. Unique per tenant on `product_key`, `signal_key`, `offer_row_id`, `crm_product_id`. No foreign keys, no JSON. One row can hold both an offer pointer and a CRM-item pointer.
- **FIX D12 (changed by the 2026-11-02 baseline):** `g2g_signal_products` **has run on live** (0 rows; `sub_institute_id int unsigned`, `created_by int unsigned`, collation `utf8_general_ci`, Compact row format) and is **missing on dev**. The migration file is recorded as run on live, so it must not be edited. Add a new corrective migration (own timestamp, sorting after `2026_12_06_100000_…`) that (a) creates the table correctly (BIGINT UNSIGNED, `utf8mb4_unicode_ci`) where it is missing (dev), and (b) where it exists with the old shape and 0 rows (live), `MODIFY`s the two integer columns to BIGINT UNSIGNED and converts the table to `utf8mb4 COLLATE utf8mb4_unicode_ci`. Re-count rows immediately before the live run and refuse to convert if the count is not 0. Log it in `DB-CHANGELOG.md`. The original file then no-ops on dev because the table exists.
- **DATA:** `product-master:backfill --tenant --database --apply` (dry run by default). **Baseline sizes (2026-11-02):** `crm_products` 0 rows on both hosts; `g2g_product_offers` 21 on live (all tenant 6) and 50 on dev (25 for tenant 1, 25 for tenant 6); no shared (NULL-tenant) offers and no duplicate `offer_id` on either host; `g2g_signal_products` 0 rows. So the live backfill is the three built-in families, the taxonomy categories and 21 offers. Order: shared families from `config/signals_products.php` and `config/g2g_taxonomy.php`; own lines from `g2g_signal_products`; offers from `g2g_product_offers` (NULL tenant becomes 0; duplicate `offer_id`s and odd spellings reported, not merged); every `crm_products` row as its own product. No name matching.
- **Alias resolver (no table):** `ProductCatalog::normalise`, then `signal_key`, then `portfolio_name`. `scholarclone`, `scholar`, `Scholar K-12`, `scholar_k12` reach one row; `multiple` and unknown return null.
- **MENU:** leaf row `/module/crm/product-master` under root 199, matched by `access_link`; Administrator only.
- Later slices add one nullable `product_master_id` to `crm_leads` (S6), `crm_campaigns` and `gtm_campaigns` (S9), `gtm_playbooks` and `gtm_outreach_messages` (S10), `crm_opportunities` (S11), `g2g_company_opportunities` and `g2g_research_runs` (S2). **S1 adds none of them.**

## Backend

New `ProductMaster` domain: resolver, backfill command, controller, `routes/product_master.php` (group with `api.token`). Endpoints: list, options, `{id}`, `{id}/usage`, create, `PUT {id}`, archive, restore. Rights through `platformright`; creating a product line needs administrator; the offer section needs an in-controller twin of `ModuleGate::signals('/portfolio','add','write')`; the sellable section checks `MenuRight` per item type. Unchanged: `/api/crm/products*`, `/api/portfolio/*`, `/api/signals/icp/products*`, all line-item tables. Key existing files: `app/Domain/Signals/Products/ProductCatalog.php`, `app/Http/Controllers/Api/Portfolio/PortfolioController.php`, `app/Http/Controllers/Api/Crm/CrmProductController.php`.

## Frontend

Shared building blocks in `g2gv0/components/shared/scaffolds/` (no edits to `components/ui`): `list-page` + `use-list-query`, `detail-page` + `page-tabs`, `form-layout` + `form-field`, `record-drawer` (on `ui/sheet`, dirty-close guard), `use-confirm` (replaces `window.confirm`, on the existing `ui/confirm-dialog`). One drawer form: Identity, About, Sell in CRM, Ideal customer. Entry: `hooks/content-map-crm.ts`. Old forms (`create-product-modal.tsx`, `product-form-dialog.tsx`, `new-product-dialog.tsx`) stay.

## Tasks

| # | Task | Done-check | Status |
|---|---|---|---|
| 1 | Measure both hosts: `crm_products`, offers, NULL-tenant offers, duplicates, whether `g2g_signal_products` exists on live. Owner approves the migration run | numbers logged | **measuring done by the S0 baseline (2026-11-02)**; owner approval of the migration run still needed |
| 2 | Migration and model on dev, then live, **including the corrective `g2g_signal_products` migration** (see Database changes) | `information_schema` identical on both; the live conversion refused unless the table has 0 rows | not started |
| 3 | Resolver and alias tests | tests green | not started |
| 4 | Backfill command, dry run | a re-run changes nothing | not started |
| 5 | Menu row and read API | 403 and 404 tests pass; row is admin-only | not started |
| 6 | Write API | rollback, 403, 409, 422 tests pass; existing CustomProductApi, ProductPortfolioIcp, ProductCatalogBuiltIn tests stay green | not started |
| 7 | Backfill applied on dev, then live | counts match the sources | not started |
| 8 | Scaffolds: list page, query hook, confirm | unit tests | not started |
| 9 | Scaffolds: detail page, tabs, form, drawer | jsdom tests | not started |
| 10 | Service, types, routes, content-map entry, list screen | renders for an admin; typecheck clean | not started |
| 11 | Detail tabs: Overview, Sell, Signals, Usage | | not started |
| 12 | Drawer form and archive through confirm | | not started |
| 13 | Browser script and log entry | create a line, a sub-product, a sellable item; all appear in Portfolio, Signals and CRM Products; a non-admin sees no line option; tenant B reading tenant A's id gets 404 | not started |

## Risks

- Ids are global, so every by-id read must filter by tenant (404 on mismatch).
- A CRM product merge repoints only `crm_opportunity_products` and `crm_quote_line_items`, so a merge can orphan `crm_product_id`. Add an orphan report.
- `offer_id` is not unique in the database; NULL-tenant offers are readable by all tenants but read-only.

## Acceptance

All 13 task checks pass; PHPUnit and the existing suites stay green; `tsc` and eslint clean on touched files; `git diff --stat components/ui/` empty; migrations applied and compared on both hosts; owner signs off the mock-up and the finished screen.

## Agent hook

None in this slice. S8 adds read-only `search_products`.

## Old screen decision

Old product screens: keep (default). Decided by the owner on: ______.

## Work log

(empty — S1 starts after S0 and the design gate)
