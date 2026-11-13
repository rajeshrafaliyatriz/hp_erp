# Plan: one Product Portfolio (Products merged into it) and Signal Review inside Market Signals

13 Nov 2026. Nothing has been changed yet: this session only read code, read the dev database (SELECT only) and asked three research agents plus one planning agent. The earlier agentic-CRM plan is kept in the repo at `docs/agentic-crm/PLAN.md` (with its later change rows), so replacing this file loses nothing.

## 1. Your four questions, answered (then your prompt, rewritten)

**1. What a top professional would do that you did not ask for**
- Treat it as a data merge, not a screen move. Write one table: every product field, where it lives today, and ONE owner. Today name, parent and active status are written by two code paths and the last write wins. Add a checker that fails when two tables disagree.
- Measure before changing: who has which right, what points at products, how many real rows exist.
- Keep every old address working (redirects), including links already saved inside old Ask answers. Remove old code only after a soak period.
- Never merge two products because the names look alike. A person confirms each pair; it is logged and can be undone.
- Protect prices: HR can open the Portfolio but not Products. The merged screen must not show them cost or commission.
- Test the words with a person. Your problem is "I am confused", so the test is whether a new user finds the right place.

**2. The rawest material** (not our notes or slice logs)
- The dev database: every column of `crm_products`, `g2g_product_offers`, `g2g_product_master`; the 22 columns elsewhere that point at products (12 at `crm_products.id`, 10 at `product_master_id`); the real tenant-6 rows (12 CRM products, 25 portfolio products, 34 master rows, none joined).
- The menu and rights tables (`tblmenumaster_g2g`, `tblgroupwise_rights_g2g`): the real audience of each screen.
- The screens as a browser draws them, with the network calls each makes.
- The source of the two controllers and the writer, and the git history of those files.
- Your own words and screenshots.

**3. Methods and tools**
- A field table (crosswalk) with one owner per field; a pointer registry and a read-only checker command `product-master:check` with before/after counts and sums of quote, order and invoice lines.
- Commands that only report unless told `--apply`; PHPUnit and `node --import tsx --test`; every new rule proved by a mutation (break it, a named test fails, restore byte for byte); tsc, eslint, a link-scan test.
- Playwright + axe (accessibility scanner) at 390 px and desktop as Admin, HR and a sales profile.
- A light-mode mock-up that you approve before screens are built (our own rule, SESSION-RULES step 7).

**4. What other than you or me shows it is right**
- The database: the checker finds zero orphans on all 22 pointer columns, and quote/order/invoice line counts and sums are identical before and after.
- The existing quote, order, invoice, product and signal suites pass unchanged (or are rewritten on purpose, never deleted to turn green).
- axe finds no new accessibility failure; a real-browser run per role with screenshots; a link-scan test finds no live link to a removed address.
- One person from your team does 6 tasks on the new screen while we watch, and we count where they hesitate.

**Your prompt, rewritten** (use it for the next session):
> Unify, don't link. One Product Portfolio (Product → Sub-product) is the only place to create, find and open a product; Signal Review lives inside Company Opportunities. Start from the dev database, the menu/rights tables and the screens in a real browser, not from our notes. First show me: the field table (one owner per field), everything that points at products or at the screens being removed, a before/after table of who can see what, and a report of CRM items vs portfolio products with suggested pairs. After I confirm, build in phases that can each ship: Signal Review into Company Opportunities; one writer + checker + pair review; the unified screens and one shared product picker; then retire the old screens by redirect. Keep every table, delete nothing, never touch live, never push. Prove it with the database checker (0 orphans, line totals unchanged), the existing suites unchanged, mutation proofs, a link-scan, a Playwright+axe run as Admin/HR/sales at 390 px, and a 6-task test with a person from my team.

## 2. Context: what I found (all verified in source or on dev)

- **Signal Review and Company Opportunities show the same signals** (`g2g_company_opportunities`) through the same backend routes, already gated by the `/signals` row. Review adds: reasons + history for every decision, fit score, filters kept in the URL, server counts, CSV export, run health and cost. Company Opportunities adds the richer research detail. So the merge adds no new right. Row 506 is read only by `SignalsGrantReviewCommand`.
- **Products today has two views and two page types.** The Products screen (`/module/crm/sales/products`) has a Catalogue | Product lines switch; a catalogue item has tabs Details, Bundle, History (+ Signals & ICP and Usage since 3 Nov); the Portfolio's own product page has only Overview + Offers. A product can be created in **four** ways (`POST /signals/icp/products`, `/portfolio/offers`, `/crm/products`, `/product-master`).
- **Dev data (tenant 6):** 12 `crm_products`, 25 portfolio offers, 34 master rows; **no master row holds both** an offer and a CRM item. The 9 CRM items (for example "Scholar One ERP - Gujarat Edition") and the 7 ScholarClone offers (for example "Scholar School ERP") are different cuts of the same line. Three CRM products have no line; "ZZ UI Widget" looks like test junk.
- **What must keep working:** 12 columns in 11 tables point at `crm_products.id` (quote, sales order, purchase order and invoice lines, price-book items, opportunity products, tickets, assets, FAQs, bundle items, campaigns); 10 columns hold `product_master_id` (leads, opportunities, campaigns, GTM tables, signals, claims). `crm_products.product_master_id` means "the Product (level 1) id" and stays that way.
- **The backend already has the idea of one record:** `ProductWriter::createSubProduct` writes one master row holding `offer_row_id` AND `crm_product_id` in one transaction. But it covers only about half the fields (6 of ~19 sell fields, 8 of 16 About fields, no ICP), and the other three write paths bypass it and call a sync that swallows errors.
- **Defects on the paths we will touch:** `CrmProductController::store` creates the product line before the add-right check (:206 vs :215); `GET products/{id}/history` has no right check and its diffs show cost and commission; line ids are not visibility-checked on import; signal keys of 50 characters go into a 20-character column; services lose their line on every edit (:233-241); `merge` repoints only 2 of the 11 tables.
- **Hiding a menu row destroys its rights (verified):** `tblmenumasterG2gController` lists only `status = 1` rows (:172-182, :274-284), ignores rights for any other row, then deletes ALL of the profile's rights and re-inserts the valid ones (:335-338). So the first Role & Permissions save wipes the rights of any hidden row. This likely also explains why the "hidden" Campaigns (453) and Outreach Approvals (499) rows had no rights (inferred, not checked). **Rows 468, 469 and 506 therefore stay `status = 1`; the sidebar is changed in code** (as `lib/signals-nav.ts` already does for the Signals tabs and Signal Inbox).
- **The freeze was yours** (SESSION-RULES:19, 3 Nov: four-tab design frozen, rows 490-493, 501, 506 "never remove, hide or rename"; revisit "at last if needed"). This request lifts it for exactly these two merges. Rows 490-493 and 501 stay untouched.
- **State of the repos:** you already pushed on 4 Nov (PRs #544-#547: S1 to S11 are in `origin/main`; the docs saying "nothing pushed" are stale). Another session worked on 5-6 Nov (product-master sync, `crm_products.product_master_id`, a sidebar reorder migration, a tenant-6 seeder). Both working trees are clean. S1 (Product Master) is **not on live**.

## 3. Decisions

**Your answers (13 Nov)**
1. Words: the Portfolio's own: **Product** (level 1, for example ScholarClone) and **Sub-product** (level 2, for example Scholar School ERP; a service is a Sub-product of type Service). The words Catalogue, Product line, Offer and Item are retired from screens. Lead, campaign and opportunity forms say "Product" (they point at level 1); pickers in quotes, orders and invoices say "Sub-product".
2. Duplicates: you confirm matches one by one. Nothing merges by name.
3. Signal Review goes inside Company Opportunities; the four tabs stay.
4. Sidebar: an entry where Products was, plus tab 3 inside Market Signals.

**Defaults I set (tell me to change any)**
- a. Services share the same list (Type filter). A "Services" sidebar entry shows only for people who hold the Services right but not Products.
- b. Rows 468/469/506 are not touched in the database (see section 2). Row 506 is also retired in data (`status 0`) only after a soak, because its rights mean nothing after the merge.
- c. Two descriptions stay, labelled differently: "What it does" (internal, Overview) and "Description on quotes" (Details). No sync between them.
- d. The Portfolio's "bundles with" is renamed "Often sold together"; the Bundle tab means a priced composition (`crm_product_bundle_items`).
- e. "Sell in CRM" off means Paused (`is_active = 0`), never delete. Turning it on again restores a binned row instead of creating a second one.
- f. When a pair is confirmed, the Portfolio name survives (you can choose the catalogue name per pair).
- g. Custom Fields stays as a conditional tab that appears only when fields exist.
- h. Old addresses redirect forever (client redirects; any static redirect is 307 until you confirm).
- i. Dev only. No live database, no push, no message sent.

## 4. The end state

**What people see**
- **Sidebar:** "Product Portfolio" in the Sales group where Products was; Signal Review gone; Market Signals unchanged.
- **List (`/portfolio`):** Products with their Sub-products; filters (Product, Type, Sold in CRM, Readiness, Status); search; paged; bulk actions, import/export, duplicates/merge carried over from the old Catalogue; a "Possible matches" banner for admins; a "No product yet" group for strays.
- **One Add/Edit form** with three sections: **About** (the Portfolio's fields), **Sell in CRM** (a switch that reveals every former Products field: type, SKU, category, prices, cost, currency, tax, GL, commission, vendor, usage unit, stock, reorder, weight, image, assigned to, active, description on quotes), **Ideal customer** (the three ICPs).
- **Sub-product page tabs:** Overview | Details | Bundle | Usage | History | Signals & ICP (+ Custom Fields when any). **Product page:** Overview | Sub-products | Signals & ICP.
- **One `ProductPicker`** in every module that uses products: grouped by Product, search-as-you-type, shows SKU and price, only sold and active Sub-products for documents.
- **Company Opportunities** gains: All products, status/freshness/fit filters in the URL (so `?status=all&q=` deep links work), server counts, 20 per page option, the decision dialog (reasons, history, "someone decided first" message), Fit & score, confirm-then-once lead action, run health and cost, CSV export.

**What is underneath**
- `g2g_product_master.id` is THE product id. A Sub-product row may hold an About (offer row), a Sell (CRM row) and ICPs; each is optional, nothing is forced into pairs. The master row owns name and parent; the offer and CRM copies are mirrors written in the same transaction.
- One writer (`ProductWriter` v2) is the only thing the new screen calls. Old endpoints keep their shapes and get the defect fixes and a strict sync; full delegation comes last, or never.
- Rights by section, not by route. Reads of names and lines are open to any signed-in user of the tenant; About follows the Portfolio gate; Sell (prices, cost, commission, stock, bundle, history) needs the Products or Services right exactly as today, so HR never receives prices.
- **One new table:** `g2g_product_match_decisions` (tenant, item master id, survivor master id, same/different, score, short signals text, decided by/at; unique per pair; also the alias for a retired id). No new columns anywhere.

## 5. Phases (each ends in a safe stop; PROGRESS.md updated; nothing pushed)

Rough size: about 10-14 working sessions in total. Recommended start: P0 then P1, because Signal Review is your item 1, is independent of the rest and shows a result early.

**P0 Before code**
- 0.1 Record in `DECISIONS.md`: your four answers, the freeze lifted for these two merges, the first "retire" decisions, rows 468/469/506 kept. Fix the stale "nothing pushed" lines in PROGRESS.
- 0.2 Baseline: run every suite named in section 8 once (one phpunit at a time) and save the output. Confirm the two test fixes I restored on 4 Nov are in HEAD (`a546d56`).
- 0.3 Read-only dev measurements into `_evidence/`: master rows by tenant and kind, the pointer census, a rights audit of 468/469/492/489/490/506 per profile, binned items.
- 0.4 **Design gate:** a light-mode mock-up of the list, the form, the Sub-product page, the sidebar and the header for people without Signals access. You approve before P3. Open items: Services placement, that header, importers (CSV and the Portfolio's Excel sync).

**P1 Signal Review into Company Opportunities** (independent; ship first)
- 1.1 Move the shared helpers `lib/signal-review/` to `lib/signals/` (7 importers, 5 screen-kits, contrast list). Proof: tsc clean and the same test counts.
- 1.2 URL-state filters, All products, server counts, 20 per page. The pinned source-scan tests are rewritten deliberately (same strictness). Proof: a deep-link test for `?status=all&q=`.
- 1.3 Decision dialog replaces the three buttons; history and Fit & score in the details area; lead action confirm-then-once. Proof: the relocated tests plus a mutation (drop `expected_status`, the 409 test fails).
- 1.4 Run health and cost under Advanced tools, CSV export, the generate validations ported.
- 1.5 Redirects for `/signal-review` and `/module/crm/signal-review` to `/signals` keeping the query. Update the four link makers (`AskSignals.php:159`, `OrgAgentContextBuilder.php:328`, `lib/org-accounts/links.ts:22`, `lead-evidence-tab.tsx:102`) and re-pin `AskToolsTest:514`, `AgentGatewayTest:117`, `AgentRunServiceTest:89`.
- 1.6 Hide the Signal Review sidebar entry in code (like Signal Inbox). `SignalReviewMenuTest` and `SignalReviewMenuMoveTest` stay green untouched.
- 1.7 Delete the Signal Review screens and routes after a parity checklist. The API, the `g2g_signal_reviews` table and the row stay.
- 1.8 Real-browser acceptance.

**P2 Backend: one writer** (the old screens keep working throughout)
- 2.1 Pointer registry (12 + 10 columns) and `product-master:check`. Proof: one corrupt-fixture test per invariant.
- 2.2 Behaviour-preserving extractions (ICP sync out of `PortfolioController:551`, the catalogue payload). Existing suites unchanged.
- 2.3 Defect fixes, test first, one commit each (list in section 2) plus strict transactional sync for merged rows.
- 2.4 Writer v2: About 16 fields + ICP, Sell ~19 fields, the kind flip when About is added, four sell states (not sold, sold, paused, in the Bin), atomic mirrors, audit events (About edits are audited too), per-section gates; image, custom fields and bundle items as side calls with a status per section.
- 2.5 Read API: paged flat list, `/{id}`, `/{id}/sell`, `/by-crm/{id}`, `/picker`.
- 2.6 The one migration (after `2026_12_11_170000`, raw MariaDB-10.1-safe) and `product-master:unify`.
- 2.7 Dev run: dry run, your pair review, apply, checker before and after.

**P3 + 4.1 Unified screens (one release; the old screens stay until it ships)**
- 3.1 Service and types. 3.2 `ProductPicker`, one consumer per commit: quotes first (golden path), then sales order, purchase order, invoice, price book, opportunity lines, ticket, asset, FAQ, bundle, then lead, campaign, opportunity. 3.3 Paged list. 3.4 Three-section form. 3.5 Sub-product page, Product page, route `/portfolio/products/{masterId}` ("products" reserved in `CustomProductRules::RESERVED`).
- 3.6 "Possible matches" review. 3.7 Redirect modules (client side; old crm-id addresses use `/by-crm/{id}`), the 7 places that resolve the Products link, `ListProductsTool` links. 3.8 Deliberate test rewrites and contrast lists; a vocabulary scan fails if a new screen shows Catalogue, Offer, Item or Product line.
- 4.1 Sidebar retarget in code: Products becomes "Product Portfolio" at `/portfolio`; Services only when there is no Products; the `/portfolio` active-highlight owner fixed in `signals-nav.ts`.

**P4 After a soak you choose:** 4.2 `product-master:rights-audit` (read-only; optional `grant-portfolio`, OR-only, leaf-only, never scoped on `sub_institute_id`); 4.3 remove the old Catalogue and Lines components; 4.4 row 506 `status 0` in data.

**P5 Prove it and hand over:** link-scan test, database reconciliation, Playwright + axe, the 6-task walk, docs (PROGRESS, DECISIONS, FEATURE-LEDGER, DB-CHANGELOG, runbook), plan artifact v15, memory. **Live cutover is a separate runbook, only on your word:** deploy code, migrate, backfill, check, unify, then the front end.

## 6. The pair review (unify)

- **Dry run first.** Columns: Product, CRM id, product no, name, SKU, type, price, live or binned, usage counts (opportunities, quotes, orders, invoices, tickets, assets, FAQs, price books, bundles), candidate Sub-product, per-signal scores, margin, verdict (MATCH-HIGH, AMBIGUOUS, EDITION, SERVICE, NO-MATCH, STRAY, JUNK?), plus a table of Sub-products with no CRM match. JUNK is report-only; deletion goes through the normal Recycle Bin.
- **Signals (suggestions only, same tenant and same Product):** category inside the Sub-product name; content words after removing "scholar"/"scholarclone"; SKU against the Sub-product id; a service never matches a non-service; price against the deal band. Best score with a margin, else AMBIGUOUS. Name alone is unsafe: the shared brand word inflates every pair, two items share a category (editions), services collide with "implementation effort", and acronyms (LMS-PAL vs LMS & Digital Classroom) are missed.
- **Merge, one transaction per pair, under row locks:** validate both rows are the tenant's own; take a pointer census; free the unique `crm_product_id` slot (archive the item's master row as a husk with `origin = merged`, pointers NULL); set the survivor's `crm_product_id`; mirror name and Product to the offer and CRM rows; repoint the master-id pointers from the husk to the survivor (never `crm_products.product_master_id`); retire duplicate claims; write the decision row; run the checker inside the transaction; commit; emit the audit event.
- **Never deleted:** the CRM row (11 tables point at it), the offer row, the husk, any claim. A JSON snapshot of every touched row is saved and `--rollback` replays it. Re-running skips pairs already merged.

## 7. Rights and menu (no menu data changes in P1-P3)

- 468/469/506 keep `status = 1` and their rights. Role & Permissions keeps showing Products and Services; their meaning becomes "Sell data".
- No rights grant is needed for the design: reads open to the tenant, Sell uses the existing 468/469, About writes use the existing Portfolio gate (admin/hr while `SIGNALS_USE_MENU_RIGHTS` is off).
- `product-master:rights-audit` (read-only, per profile) flags: 468 without 489/492 (their door is the retargeted sidebar entry), HR holding 468 (prices visible; confirm that is intended). Measure on dev first; on live only after your go and after the code is deployed.
- Frame: `/portfolio` sits inside `SignalsFrame` with four always-visible tabs. For people without Signals access the header shows only "Product Portfolio" (to be confirmed at the design gate).

## 8. Tests that must stay green or be rewritten on purpose

- **PHP:** ProductMasterTest, ProductMasterApiTest, ProductMasterWriteApiTest, ProductMasterSyncTest, CrmProductProductMasterLinkTest, CrmFullLifecycleGoldenPathTest, CustomProductApiTest, ProductPortfolioIcpTest, SubProductIcpOwnershipTest, ProductMatchingTest, RestoredModulesApiTest, SalesOrderInvoiceReconciliationTest, OrganisationMergeTest, AskToolsTest, SignalReviewApiTest, SignalReviewMenuTest, SignalReviewMenuMoveTest, SignalFeedFiltersTest, MariaDb101SafetyTest.
- **Front end:** `lib/product-master/*`, `lib/signal-review/*` (moves to `lib/signals/`), the source-scan pins (`signals-screen`, `company-opportunities-wiring`, `product-portfolio`, `product-page`, `product-key`, `add-product-form`, `product-id-suggestion`), and the contrast scans (`product-master/contrast.test.ts` names 9 files; `signal-review/contrast.test.ts` expects 5 or more files).

## 9. Top risks and how each is proven

| Risk | Answer | Proof |
|---|---|---|
| Rights of a hidden row wiped by a save | Code-only fold, rows stay `status 1` | Feature test: save a role, rows 468/469 keep their rights; mutation: set `status 0`, test fails |
| Pointers lost or orphaned in a merge | Registry, census, checker inside the transaction, snapshot rollback | Skip one repoint, checker test fails |
| HR sees price, cost or history | `/sell` gate, history gate | Per-endpoint HR tests; remove a gate, named test fails |
| Writers drift (name, Product, active) | One owner, atomic mirrors | Checker after every legacy write; break a mirror, test fails |
| Old links break (Ask answers, bookmarks) | Redirects kept forever | Link-scan test; redirect tests (query kept, other tenant 404, binned row) |
| Signal Review ability lost | Parity checklist, tests relocated not rewritten | `SignalReviewApiTest` unchanged; 409 mutation |
| Live limits (MariaDB 10.1) | Raw guarded DDL, no JSON; nothing runs on live here | `MariaDb101SafetyTest` already scans `app/Domain/ProductMaster` |
| Scale (unpaged list, 100-row cap) | Paged list, search picker | Seeded 5,000-row test |
| Still confusing | Mock-up gate, vocabulary scan, 6-task walk | The walk itself |
| Parallel session / dirty tree | `git status` + `git log` before each phase, explicit staging, one phpunit at a time | Diff review; suites green before and after |

## 10. Real-browser acceptance (Admin, HR, sales profile; 390 px and desktop; axe; screenshots)

- **Navigation:** no Signal Review in the sidebar; "Product Portfolio" opens `/portfolio`; old addresses (`/signal-review?status=all&q=x`, a crm-id product, a line, services) land on the right page; unknown or other-tenant ids give 404.
- **Signals:** filters survive refresh and Back; a dismissal needs a reason; a stale decision shows the "someone decided first" text; history and fit show; a double-clicked lead action makes one lead; CSV downloads; Generate still works.
- **Create and edit:** About-only, Sell-only and both, with 3 ICPs; duplicate-id 409; rename and Product move mirror to all three tables; Pause removes the Sub-product from quote pickers but old quotes still show it; Sell on restores; restoring from the Bin makes no duplicate.
- **Rights:** Admin sees all tabs; HR sees no Sell tabs or prices; the sales profile gets Sell read or edit by its right; only admins create a Product.
- **Consumers:** the quote picker is grouped with SKU and price; quote, order and invoice totals equal the "before" snapshot; purchase order, price book, ticket, asset, FAQ, lead, campaign and opportunity pickers work.
- **Pair review:** confirm "same" shows both sets of tabs on one Sub-product and keeps quote history; "different" persists; a non-admin cannot confirm.
- **States and keyboard:** empty, error and 403 states; the keyboard path through dialogs and tabs.

## 11. Needs your word, and what is not in this plan

- **Your word:** push, deploy, anything on the live database (S1 is not there yet), rights grants, deleting menu rows, the end of the soak period.
- **Not touched:** Ingestion Engine, Partner Network, Signal Inbox, the Signals tab design beyond what P1 needs; sending of any message stays OFF.

## 12. Critical files and what to reuse

- **Backend (`hp_erp`):** `app/Domain/ProductMaster/{ProductWriter,ProductMasterSync,ProductAccess,ProductLinks,ProductResolver,ProductMasterRepair}.php`; `app/Http/Controllers/Api/ProductMaster/ProductMasterController.php`; `routes/product_master.php`; `app/Http/Controllers/Api/Crm/CrmProductController.php` (:206-241, :290-326, :892); `Api/Portfolio/PortfolioController.php` (:551 ICP sync); `Api/Signals/OpportunityController.php` (decisions, reviews); `app/Domain/Signals/Review/SignalReviewService.php`; `routes/api.php` (portfolio 3661, signals 3538-3567, CRM products 4298-4319); `app/Support/{MenuRight,ModuleGate}.php`; `HasCrmAuditTrail`, `HasCrmMerge`, `EventRecorder`, `ColumnExists`, `SignalsGrantReviewCommand` (pattern for the optional grant).
- **Front end (`g2gv0`):** `lib/signals-nav.ts`, `hooks/use-sidebar-navigation.ts`, `hooks/content-map-crm.ts`, `components/domain/signals/*`, `components/domain/portfolio/*`, `components/domain/crm/{product-*,create-product-modal,signal-*}.tsx`, `lib/signal-review/*`, `lib/product-master/*`, `services/{product-master,signal-review,portfolio,signals}/*`, `app/portfolio/**`.
- **Reuse:** the `tones.ts` + `screen-kit.ts` pattern and `lib/theme-contrast.ts`; the S3 "possible duplicates" pattern for the pair review; `scripts` for browser runs in `D:\dev\agentic-crm-research\scripts\browser\`.

## 13. Work log (append-only; newest last; every claim tagged verified / read in source / inferred / unverified)

### 13 Nov 2026 - P0 (before code)
- **0.1 done.** `DECISIONS.md` entry 2026-11-13, `SESSION-RULES.md` line 19 and `briefs/COMMON-RULES.md` section 2 now record the owner's answers and the lifted freeze. *verified*
- **0.2 baseline (HEAD hp_erp `4a5018ac`, g2gv0 `9b657ca`).** PHP, the 17 filters of section 8: 409 tests, 2 problems, both in `ProductMasterSyncTest` and both STALE tests, not new breakage: `test_editing_renames_the_catalogue_item_but_never_the_name_of_a_sub_product_that_uses_it` (expects 'Scholar Nine', gets 'Renamed in the catalogue') and `test_the_master_list_carries_icp_state_and_signals_per_sub_product_and_stops_calling_a_deleted_item_sold` (line 962, `soldInCrm` after a Recycle-Bin restore: the sub-product is missing from the list). Cause: the other session's commit `0826227a` (5 Nov) widened `ProductMasterSync` to `sub_product` rows; the test file was last changed on 3 Nov (`8a779ea1`). They pin the old "linked, not merged" rule; P2.3/2.4 rewrite them under the one-record rule (a rename is mirrored atomically). *verified (git history + failure text)*. Front end, whole suite: 3,107 tests, 2 failing, both in `lib/expandable-run-notice.test.tsx` (subtests 3 and 4, Harshit's `8941ec6` of 5 Nov; the test reads `components/domain/signals/*`, untouched by us). *verified*. `tsc` 0 errors.
- **0.3 measurements** saved: `_evidence/s15/p0-measurements-2026-11-13.md` (dev database, SELECT only). Key facts: 9 live CRM products + 3 already in the Recycle Bin; 25 Sub-products per tenant 1 and 6; nothing joined; no orphans on any line-item table; every profile that holds Products (468) also holds the Portfolio (492). *verified*
- **0.4 design gate:** not started (needed before P3 only).

### 13 Nov 2026 - P1.1 done (uncommitted at this line)
- `git mv lib/signal-review/* lib/signals/` (33 files, 7 non-test importers, 5 screen-kit `router-harness` imports, one comment). `tsc` 0 errors; subset of 1,708 tests passed; whole suite 3,107 with only the 2 baseline failures above. *verified*

### 13 Nov 2026 - P1-A (track S15-P1A, g2gv0 front end: Company Opportunities gets the Signal Review abilities) - work log
Append-only. Nothing below is committed or pushed (the caller commits). Tags: verified / read in source / inferred / unverified.

**Start of track (g2gv0 `milan1`, HEAD `cd12597`, tree clean).**
- Baseline before any change of mine: `tsc --noEmit` 0 errors (exit 0). The 22 test files that read the Signals components plus `lib/signals/*`: 632 tests, 630 pass, 2 fail (the known `lib/expandable-run-notice.test.tsx` subtests 3 and 4, not mine). *verified* (`_evidence/s15/p1a-baseline-2026-11-13.md`)
- Read in source, and these shape the design: (1) `CompanyOpportunities` and `SignalList` read no router today; `useListQuery` needs `useRouter/usePathname/useSearchParams`, and `app/signals/page.tsx` renders the screen as `children` of `GtgAppShell`, which only wraps the content-map path in `Suspense`: the screen therefore needs its own `Suspense` boundary. (2) `resolveSelection(…, allowAll=true)` makes the DEFAULT product "all", which would change the no-parameter behaviour: the "All products" choice needs a separate "start on" switch. (3) The Review sends no `expired`, so it hides expired signals; Company Opportunities sends `expired=include`. (4) The server `summary` is computed without the `review_status` and `priority` filters, so the four tiles stay meaningful when a status is chosen. (5) The Review's `SIGNAL_LIST_QUERY` has `status` default `new`; Company Opportunities must default to `all` ("no parameters = unchanged"). (6) `search` is validated `max:100` by the server; a longer `q` in a link is a 422 today. *read in source*
- **Increment 1 done (moves).** `git mv` to `components/domain/signals/`: `signal-decision-dialog.tsx`, `signal-history-list.tsx` (names kept), `signal-review-decision.ts` to `signal-decision.ts`, `signal-review-fit.tsx` to `signal-fit.tsx`, `signal-review-lead.tsx` to `signal-lead.tsx`, and `signal-review-parts.tsx` to `signal-parts.tsx` (the fit component needs `Fact` and `SignalProductChip`; the old path stays as a one-line re-export so the Review screen compiles until 1.7). The Review drawer's 5 imports and the two test imports were updated. `lib/signals/contrast.test.ts` now scans the 4 Review files plus the 5 shared files by name; that scan found a real offence in the moved history list (`text-destructive` on a tint of itself in the error state), fixed with the notice tone. `tsc` 0 errors, 185 tests of the moved/neighbour files pass. *verified*
- **Increment 2-4 done (logic, service, screen).** New `lib/signals/opportunity-query.ts` (address config with the Review's names plus `per`; request builder; `resolveProductScope`: key / `key::SUB` / number / `all` / none; the "only searches" rule) with 22 tests; `lib/signals/opportunity-row.ts` (row and counts moving with a decision); `services/signals/opportunities.ts` gained `freshness`, `productMasterId`, `sort=recent` and the response `filters.products/review_reasons`; `SignalList` rewritten around the address (four server tiles, Status / Freshness / Fit / Sort / Page size, Clear filters, `listScreenState`); `SignalCard` now uses the decision dialog, history, Fit & score and the confirm-then-once lead via `signal-card-actions.tsx`; `CompanyOpportunities` has All products and the address; `SignalsPageContent` wraps it in `Suspense` (it now calls `useSearchParams`). Whole-screen jsdom test `lib/signals/company-opportunities-screen.test.tsx`: 39 tests. *verified* (run output in the evidence files below)
- Pins that must be rewritten on purpose (both `allowAll: false` pins are in different files): `lib/company-opportunities-wiring.test.ts` (allowAll pin, the "Mark reviewed / Contacted / Not relevant" card pin), `lib/signals-screen.test.ts` (per-page and reset pins), `lib/sub-product-run.test.ts:73` (a third `allowAll: false` pin not named in the brief). Each will be listed with its reason in the final report. *read in source*

### 13 Nov 2026 - P1-B (every link retargeted, old addresses redirect, sidebar entry folded, two guards). Nothing committed or pushed; no server; dev only
Both trees were clean at the start (hp_erp `00e8c371`, g2gv0 `cd12597`). Full detail, commands and the mutation tables: `_evidence/s15/p1b-backend-links-and-guard-2026-11-13.md` and `_evidence/s15/p1b-frontend-links-redirects-sidebar-2026-11-13.md`; the before/after census: `_evidence/s15/p1b-census-*`.
- **1.5a done (hp_erp).** `AskSignals.php:159` and `OrgAgentContextBuilder.php:328` now write `/signals?status=all&q=` + the same `rawurlencode`. `AskToolsTest:514`, `AgentGatewayTest:117`, `AgentRunServiceTest:89` re-pinned from a prefix match to the whole address. `grep` of `app/ config/ routes/ database/seeders/ resources/` finds nothing else but the menu-row identity `SignalsGrantReviewCommand.php:27` (kept: row 506 is found by access_link). Passing: AskToolsTest 41, AgentGatewayTest 24, AgentRunServiceTest 25, AgentContextTest 19, AskPipelineTest 46, SignalReviewMenuTest 13 and SignalReviewMenuMoveTest 8 (untouched). *verified*
- **1.5b done (g2gv0).** `lib/org-accounts/links.ts` lost its separate Review lookup (`signalReview` is gone from `OrgLinks`); `signalReviewHref` follows the Signals link and writes `/signals?status=all&q=<title>`. The lead Evidence link and the Organization 360 title links therefore no longer depend on menu row 506. Their wording follows the destination ("Open this signal in Company Opportunities", "Decisions are made in Company Opportunities."). Nine test/fixture files re-pinned; `lib/ask/context.test.ts:26` named in the brief never held the old string. *verified*
- **1.5c done (g2gv0).** `app/signal-review/page.tsx` and `app/module/crm/signal-review/page.tsx` are async server pages that `redirect(signalsRedirectTarget(await searchParams))` (new `lib/signal-review-redirect.ts`): 307, type `replace`, query rebuilt with `encodeURIComponent` per key and value (byte-identical for the links we write, semantically identical for any other), repeated keys kept. `hooks/content-map-crm.ts` lost the `SignalReviewView` import and both entries. *verified* as functions; no HTTP request was made.
- **1.6 done (g2gv0).** `lib/signals-nav.ts` drops the Review row (leaf, either address or name) from whatever tree `collapseSignalsNav` returns, also when the person has no Signals row at all, and maps both addresses to the Market Signals entry in `owners`. `SIGNALS_TABS` untouched. Rows 468, 469 and 506 were not touched in the database; no migration was written. A test through the real `useSidebarNavigation` hook proves the highlight, so `hooks/use-sidebar-navigation.ts` needed no change. *verified*
- **1.5d done.** `hp_erp/tests/Unit/SignalReviewAddressGoneTest.php` (5 tests, scans `app config routes database/seeders resources`, one allowed line) and `g2gv0/lib/signal-review-address-gone.test.ts` (6 tests, scans `app components hooks lib services`, allowances per file and line). The front-end guard matches the address as a PATH, so the Review screen's own `services/signal-review/` imports and `signal-review-*` file names do not trip it. *verified*
- **Proof.** Front end: my 9 test files 248/248; whole `lib` suite 3,201 tests with only the 2 known `expandable-run-notice` failures; tsc 0 errors; eslint `--max-warnings=0` clean on the 20 files; `components/ui` diff empty. Mutations: 4 on the PHP side (P1-P4) and 13 on the front end (F1-F12 with F8b), every one caught by a named test and restored byte for byte (`cmp`, md5). Before/after census of the address outside the docs: live link makers and routes hp_erp 2 -> 0, g2gv0 8 -> 0; tests and fixtures pinning a link 3 -> 0 and 22 -> 0. *verified*
- **Left on purpose.** The names `signalReviewHref`, `data-signal-review-link`, `screen-kit`'s `signalReview` option (cosmetic; with step 1.7); `lib/signals/router-harness.ts:9` default `pathname` (Track A's folder, allow-listed in the guard); the three migrations, `SignalsGrantReviewCommand` and the two `SignalReviewMenu*Test` files (they name the menu ROW); comments that say "Signal Review" without an address.
- **Open / for the caller.** (1) The redirect is 307 until the owner confirms; the pages stay for ever. (2) `proxy.ts` drops the query when a signed-out person opens an old `/module/...` link (existing). (3) At step 1.7 remove the `lib/signals/router-harness.ts` allowance from the guard when that default goes (the guard prints a note when an allowance is no longer needed). (4) Run the whole PHP suite once; I ran only my files and the neighbours.
