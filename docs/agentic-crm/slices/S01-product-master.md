# S1 — Product Master

**Status:** **built and browser-checked on dev, 2026-11-02; waiting for the owner's review of the finished screen and for the go to roll out to live.** Design gate passed: the owner answered the four questions and said to continue; the mock-up was revised (artifact v7, `s1-mockup.html`; source in `D:\dev\agentic-crm-research\published-pages\`). Building is on the dev database only; every live change waits for the owner's "go" (after a scratch-table probe, see `DB-RULES.md`). **Rough size:** 6–8 sessions.

### Design-gate questions and the owner's answers

1. **Menu place: answered.** Under the CRM module, admin only, not under profile Settings: row `/module/crm/product-master` under root 199, label "Product Master", Administrator profile only.
2. **Words: answered.** "Sub-product" (no other preference). A top-level group is a **product**; `g2g_product_master.kind` is `product` or `sub_product`.
3. **The 32 live signals with no product: delegated, checked.** `_evidence/s1-design-gate-checks-2026-11-02.md`: 11 are placed by their matched offer (BUN-01) in S2, 11 carry only a company-level text and need a reviewer, 10 are old shared rows. Not touched in S1.
4. **Prices: answered.** A quote is priced from a price book, and price books connect to products and services. Prices are read-only on a Prices tab; the product keeps a base price as the fallback; prices are edited in Price Books. `crm_price_books`, `crm_products` and `crm_quotes` are all empty on live.

**Owner's answers recorded:** 2026-11-02 evening, `DECISIONS.md`.

## Goal

One product record that every other branch (signal, campaign, lead, outreach, forecast) can point at. A thin index over the four existing stores. It is not a merge, so quotes, orders and invoices keep working.

## Why this is first

`crm_products`, `g2g_product_offers`, `g2g_signal_products` (plus `config/signals_products.php`) and `g2g_product_profiles` are four product stores with no link between them. GTM has no product model. Promoting a signal to a lead copies no product. Ten tables hold real foreign keys to `crm_products`. Source: Explore and Plan reports of 2026-11-02 (`agent-read`; re-verify in task 1).

## Database changes

- **NEW `g2g_product_master`:** `id`, `sub_institute_id` BIGINT UNSIGNED NOT NULL (0 = shared), `product_key` varchar(50), `kind` varchar(12) (`product` for a top-level product, `sub_product` for an item inside it), `parent_id` NULL, `name` varchar(191), `status` varchar(10), `origin` varchar(20), `signal_key` varchar(20) NULL, `portfolio_name` varchar(50) NULL (the exact `parent_product` string), `offer_row_id` NULL, `crm_product_id` NULL, `created_by`, `updated_by`, timestamps. Unique per tenant on `product_key`, `signal_key`, `offer_row_id`, `crm_product_id`. No foreign keys, no JSON. One row can hold both an offer pointer and a CRM-item pointer.
- **FIX D12 (changed by the 2026-11-02 baseline):** `g2g_signal_products` **has run on live** (0 rows; `sub_institute_id int unsigned`, `created_by int unsigned`, collation `utf8_general_ci`, Compact row format) and is **missing on dev**. The migration file is recorded as run on live, so it must not be edited. Add a new corrective migration (own timestamp, sorting after `2026_12_06_100000_…`) that (a) creates the table correctly (BIGINT UNSIGNED, `utf8mb4_unicode_ci`) where it is missing (dev), and (b) where it exists with the old shape and 0 rows (live), `MODIFY`s the two integer columns to BIGINT UNSIGNED and converts the table to `utf8mb4 COLLATE utf8mb4_unicode_ci`. Re-count rows immediately before the live run and refuse to convert if the count is not 0. Log it in `DB-CHANGELOG.md`. The original file then no-ops on dev because the table exists.
- **DATA:** `product-master:backfill --tenant --database --apply` (dry run by default). **Baseline sizes (2026-11-02):** `crm_products` 0 rows on both hosts; `g2g_product_offers` 21 on live (all tenant 6) and 50 on dev (25 for tenant 1, 25 for tenant 6); no shared (NULL-tenant) offers and no duplicate `offer_id` on either host; `g2g_signal_products` 0 rows. So the live backfill is the three built-in families, the taxonomy categories and 21 offers. Order: shared families from `config/signals_products.php` and `config/g2g_taxonomy.php`; own lines from `g2g_signal_products`; offers from `g2g_product_offers` (NULL tenant becomes 0; duplicate `offer_id`s and odd spellings reported, not merged); every `crm_products` row as its own product. No name matching.
- **Alias resolver (no table):** `ProductCatalog::normalise`, then `signal_key`, then `portfolio_name`. `scholarclone`, `scholar`, `Scholar K-12`, `scholar_k12` reach one row; `multiple` and unknown return null.
- **MENU:** leaf row `/module/crm/product-master` under root 199 (Sales & Marketing), label "Product Master", matched by `access_link`; Administrator only (owner: under the CRM module, not profile Settings).
- Later slices add one nullable `product_master_id` to `crm_leads` (S6), `crm_campaigns` and `gtm_campaigns` (S9), `gtm_playbooks` and `gtm_outreach_messages` (S10), `crm_opportunities` (S11), `g2g_company_opportunities` and `g2g_research_runs` (S2). **S1 adds none of them.**

## Backend

New `ProductMaster` domain: resolver, backfill command, controller, `routes/product_master.php` (group with `api.token`). Endpoints: list, options, `{id}`, `{id}/usage`, create, `PUT {id}`, archive, restore. Rights through `platformright`; creating a product line needs administrator; the offer section needs an in-controller twin of `ModuleGate::signals('/portfolio','add','write')`; the sellable section checks `MenuRight` per item type. Unchanged: `/api/crm/products*`, `/api/portfolio/*`, `/api/signals/icp/products*`, all line-item tables. Key existing files: `app/Domain/Signals/Products/ProductCatalog.php`, `app/Http/Controllers/Api/Portfolio/PortfolioController.php`, `app/Http/Controllers/Api/Crm/CrmProductController.php`.

## Frontend

Shared building blocks in `g2gv0/components/shared/scaffolds/` (no edits to `components/ui`): `list-page` + `use-list-query`, `detail-page` + `page-tabs`, `form-layout` + `form-field`, `record-drawer` (on `ui/sheet`, dirty-close guard), `use-confirm` (replaces `window.confirm`, on the existing `ui/confirm-dialog`). One drawer form: Identity (name, product, sub-product ID, status), About (from the Portfolio), Sell in CRM (type, SKU, tax, GL account, base price), Ideal customer (link to the existing editor). A read-only **Prices** tab lists the price books that contain the item with the price in each (empty state today: no price books exist); prices are edited in Price Books. The list shows products and sub-products with readiness, "Sold in CRM" and signal counts per product. Entry: `hooks/content-map-crm.ts`. Old forms (`create-product-modal.tsx`, `product-form-dialog.tsx`, `new-product-dialog.tsx`) stay.

## Tasks

| # | Task | Done-check | Status |
|---|---|---|---|
| 1 | Measure both hosts: `crm_products`, offers, NULL-tenant offers, duplicates, whether `g2g_signal_products` exists on live. Owner approves the migration run | numbers logged | **measuring done by the S0 baseline (2026-11-02)**; owner approval of the migration run still needed |
| 2 | Migration and model on dev, then live, **including the corrective `g2g_signal_products` migration** (see Database changes) | `information_schema` identical on both; the live conversion refused unless the table has 0 rows | **dev done 2026-11-02** (commit `c8c80c73`; `SHOW CREATE TABLE` saved). Live waits for the owner's go + probe. Design change: the corrective migration only creates the table where it is missing; the live table (0 rows) is left as is. |
| 3 | Resolver and alias tests | tests green | **done 2026-11-02** (commit `2d12f2c4`; 14 tests, 3 mutations caught) |
| 4 | Backfill command, dry run | a re-run changes nothing | **done 2026-11-02** (commit `2d12f2c4`; dev dry run 5 + 25; live dry run 5 + 22) |
| 5 | Menu row and read API | 403 and 404 tests pass; row is admin-only | **dev done 2026-11-02** (commits `a0dc79a3`, `782748d8`; 11 API tests, 4 mutations caught; menu row id 505 and tenant 6 admin granted on dev). Live waits for the owner's go. |
| 6 | Write API | rollback, 403, 409, 422 tests pass; existing CustomProductApi, ProductPortfolioIcp, ProductCatalogBuiltIn tests stay green | **dev done 2026-11-02** (commits `66173abc`, `34aa2dab`; 29 tests; a 59-mutation sweep, 57 killed and 2 intentional survivors; no database change). `ProductPortfolioIcp` and `ProductCatalogBuiltIn` stay green; `CustomProductApiTest` was already failing (fixture), checked identical on HEAD. First real HTTP write happens in task 13. |
| 7 | Backfill applied on dev, then live | counts match the sources | **dev done 2026-11-02** (5 shared + 25 per organisation for 1 and 6; second run created nothing). Live waits for the owner's go. |
| 8 | Scaffolds: list page, query hook, confirm | unit tests | **done 2026-11-02** (g2gv0 `fed8775`; list-query rules as pure functions, `ListPage`, `ListSearch`, `useListQuery`, `useConfirm`) |
| 9 | Scaffolds: detail page, tabs, form, drawer | jsdom tests | **done 2026-11-02** (g2gv0 `fed8775`; `DetailPage`, `PageTabs`, `Field`, `FormSection`, `RecordDrawer`; 80 tests incl. the real Radix Sheet and AlertDialog; 119 mutations, 118 caught + 1 equivalent) |
| 10 | Service, types, routes, content-map entry, list screen | renders for an admin; typecheck clean | **done 2026-11-02** (g2gv0 `047c928`, `54bc01e`; `tsc` shows no new error, the 14 older ones are unrelated; renders for an administrator in a real browser) |
| 11 | Detail tabs: Overview, Sell, Signals, Usage | | **done 2026-11-02**, with a change: Overview, Sell in CRM, **Prices** (from the approved mock-up) and Usage (Signals folded into it); a catalogue item has no Overview |
| 12 | Drawer form and archive through confirm | | **done 2026-11-02** (one drawer for product, sub-product and item; archive asks first through `useConfirm`; restore) |
| 13 | Browser script and log entry | create a line, a sub-product, a sellable item; all appear in Portfolio, Signals and CRM Products; a non-admin sees no line option; tenant B reading tenant A's id gets 404 | **done on dev 2026-11-02, with two gaps** (`_evidence/s1-tasks8-13-frontend-2026-11-02.md`): 73 of 73 checks pass; the three kinds were created and all appear in Portfolio, CRM Products and the Signals product list; another organisation's id is a 404. **Not seen in a browser:** a non-administrator with the screen's right (covered by PHP and unit tests), and a no-rights 403 with a real token (the second token was refused). |

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

Append-only, newest last. The fuller entries are in `00-PROGRESS.md`; evidence paths are under `_evidence/`.

- **2026-11-02 task 1 (measuring):** done by the S0 baseline, `_evidence/s0-baseline-2026-11-02/` and `s1-design-gate-checks-2026-11-02.md`. Owner's go for any live run still outstanding.
- **2026-11-02 tasks 2, 3, 4, 7 (dev):** `g2g_product_master` and the `g2g_signal_products` guard migration run on dev; resolver, backfill command and 14 tests; backfill applied on dev (5 shared + 25 per organisation for 1 and 6, second run created nothing). Commits `c8c80c73`, `2d12f2c4`. Evidence: `_evidence/s1-product-master-dev-2026-11-02.md`.
- **2026-11-02 task 5 (dev):** menu row id 505, `product-master:grant`, read API, 11 tests; organisation 6's Admin granted on dev only. Commits `a0dc79a3`, `782748d8`. Evidence: `_evidence/s1-task5-menu-and-read-api-2026-11-02.md`.
- **2026-11-02 task 6 (code only):** write API (create product / sub-product / item, update, archive, restore), 8 routes, 29 tests, 59-mutation sweep (57 killed, 2 intentional survivors), real column sets read on both hosts. Commits `66173abc`, `34aa2dab`. Evidence: `_evidence/s1-task6-write-api-2026-11-02.md`. Not yet exercised over HTTP against the dev database (task 13).
- **2026-11-02 backend additions for the screen:** `GET /api/product-master/meta` and the offer's About text in a record (`879b4824`), then separate restore flags (`ad2dd429`). 36 write tests (251 assertions); 26 mutations of the new code caught after 3 test gaps were closed. 9 routes now.
- **2026-11-02 tasks 8 to 12 (g2gv0):** the scaffolds (`fed8775`), the screen (`047c928`) and the follow-ups from the browser check (`54bc01e`). 114 front-end tests; 175 mutations (174 caught, 1 equivalent). Nothing in `components/ui` changed.
- **2026-11-02 task 13:** the real-browser walk-through, run 7 of 7 green (73 checks), on the local Laravel and the dev database as a tenant-6 administrator. Test records were created through the UI and then removed by id, organisation, creator and name; counts match the baseline. Evidence: `_evidence/s1-tasks8-13-frontend-2026-11-02.md`; screenshots and the script are outside the repo in `D:\dev\agentic-crm-research\`.
