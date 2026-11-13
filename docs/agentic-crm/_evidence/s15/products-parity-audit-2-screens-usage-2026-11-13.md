# Products parity audit, slice 2: screen features of the old module (B) and usage on dev (F)

Track S15-AUD, slice 2 of the audit named in `Docs/agentic-crm/briefs/S15-AUD-products-parity-audit.md`. 13 Nov 2026. Read only; this is the only file I wrote. Slice 1 (A, C, H) is `products-parity-audit-1-fields-endpoints-2026-11-13.md`; sections D and E belong to other agents.

**STATUS: INCOMPLETE (SAFE STOP).** The owner asked for a safe stop while I was still in my reading phase. I opened no screen component, did not open the mock-up and ran no database query. Sections B and F below are therefore the CHECKLIST TO RESUME FROM, filled with what the plan and slice 1 already say (each such fact is tagged [C]), not findings. **No row below is marked OK, GAP or DECISION by me.** Every section says what is left to read.

## 0. What was read, and how claims are tagged

| Source | How much I read |
|---|---|
| `briefs/COMMON-RULES.md`, `briefs/S15-AUD-products-parity-audit.md` | all |
| `DECISIONS.md` | lines 1-30 (the entry 2026-11-13 is lines 7-22) |
| `slices/S15-product-portfolio-merge.md` | all 318 lines: the plan (sections 1 to 13) and the work logs P0, P1-A, P1-B, P1-A2, P2-A, P2-B1, P2-B2, P1-C and P2-D up to its marker |
| slice 1 (`products-parity-audit-1-fields-endpoints-2026-11-13.md`) | lines 1-60 (summary), 189-258 (A3, A4), 262-364 (C). NOT read: A1 and A2 (61-188), H (368-410), its gaps and decisions list (411-470) |
| **NOT read** | the mock-up `mockup-product-portfolio.html`; every component, page and service file the brief names for section B; `briefs/S15-P2C-writer-v2.md`; `briefs/S15-P2D-unify-command.md`; `00-PROGRESS.md`, `SESSION-RULES.md`, `DB-RULES.md` (the project pointer asks for them; the coordinator's brief did not list them) |
| Database | not touched: no query was run, not even a SHOW |
| Source repos | no source file of hp_erp or g2gv0 was opened and no git command was run. hp_erp HEAD at the start of the session was `ccfe17ce` (from the session snapshot); g2gv0 HEAD `3d8680b8` (from slice 1) |

Tags: **[V]** verified, I ran it (none yet). **[S]** read in source by me, file:line (none yet). **[C]** carried: stated by slice 1 or by a plan work log, which say they verified it; I did NOT re-run or re-read it, and a file name without a directory is as slice 1 writes it. **[I]** inferred by me from the plan text; the code was not read.

Status words: **TO DO** = not audited by me. A bracket after it, such as `[C: GAP (S)]`, is the verdict slice 1 already gave. Those verdicts are already inside slice 1's own counts and must not be added to them.

## 1. Summary (counts so far)

| Section | Rows listed | Audited by me ([V] or [S]) | OK | GAP | DECISION | Rows that already carry a slice 1 verdict [C] | Rows still to do |
|---|---|---|---|---|---|---|---|
| B screen features of the old module | 47 | 0 | 0 | 0 | 0 | 22 (4 OK, 13 GAP, 5 DECISION) | 47 |
| F usage on dev (SELECT only) | 15 | 0 | 0 | 0 | 0 | 9 rows carry a number measured by another track, 6 carry nothing | 15 |
| **Total** | **62** | **0** | **0** | **0** | **0** | | **62** |

What this file can be used for now:
1. A resume checklist: each B row names the component or route to read and the slice 1 or plan fact already known; each F row names the census line to run.
2. The numbers other tracks already measured on dev, to be re-run, not trusted.
3. It is NOT a verdict on parity. A TO DO row must never be read as OK.

**Provisional observations, all [I] (from comparing the plan's text with the brief's list of screen features; to be confirmed against the components and the mock-up):**
1. Saved views have no home in the plan: I saw them in neither the list of section 4 nor any step of section 5 when reading the slice file end to end. The coordinator's note calls them an open item, and slice 1 D-7 already says the unified list must keep a module key. See B1.6.
2. The plan's list (section 4) names filters, search and paging but no sort, while the old saved views store a sort key and direction [C]. See B1.4.
3. The Recycle Bin link and the Category picklist admin are not named in the plan's list; both exist in the old screens [C]. See B1.13 and B1.16.
4. Two decisions the coordinator relayed are not in the slice file or in the DECISIONS entry I read: "Delete from catalogue moves the CRM row to the Recycle Bin (administrators) and Archive hides the whole record", and the switch label "Offered on new documents" (the plan says a "Sell in CRM" switch that means Paused when off). They may live in the mock-up or in the P2-C brief, which I did not read. If they do not, the plan needs them written down. See B2.9 and B5.6.
5. From the numbers carried in F: bundle rows, custom field definitions, readiness confirmations and readiness-log rows are all 0 on dev, and none of the 50 offers has been saved through the API (every API save sets `is_user_edited`, per slice 1, and all 50 are 0). So the Portfolio's offer dialog has no dev usage and the offers came from an import or sync. How the 12 catalogue items were created is not known to me. Nothing is dropped for being unused; this only orders the work.

---

## B. Screen features of the old module (INCOMPLETE: 0 of 47 rows audited)

**Left to read for B (everything):** `product-catalog-list-view.tsx`, `product-detail-page.tsx`, `create-product-modal.tsx`, `service-list-view*`, `product-lines-view.tsx`, `product-line-record-page.tsx`, `product-master-form.tsx`, `product-links-panels.tsx`, `recycle-bin-view.tsx`, `crm-saved-views.tsx` (directories to be confirmed with Glob), the pages under `app/module/crm/sales/products` and `.../services`, all of `components/domain/portfolio/*` (including `portfolio-view.tsx`, `product-form-dialog.tsx`, `new-product-dialog.tsx`, `product-profile-panel.tsx`, `product-detail-view.tsx`), and the mock-up. The checklist below comes from slice 1 and the plan, so it can MISS a control that only a full read of the components would show: add rows for anything found.

Mock-up views: slice 1 and DECISIONS number them 2 = the list, 3 = the form, 4 = the Sub-product page. I did not open the mock-up, so those numbers are theirs. "Plan step" means a step of section 5 of the slice file (P2.4 writer, P2.5 read API, P3.3 list, P3.4 form, P3.5 pages, P3.6 possible matches, P3.7 redirects, 4.1 sidebar).

### B1. The old Catalogue list and the Services mirror (INCOMPLETE: 0 of 17 audited)

| # | Item | Evidence [C] (file:line as slice 1 writes it) | Used on dev? | Old home | NEW HOME (plan step; mock-up view) | Proof planned | Status |
|---|---|---|---|---|---|---|---|
| B1.1 | Columns | not read | n/a (a control leaves no row) | Catalogue list | the list, view 2; P3.3 | none stated | TO DO |
| B1.2 | Filters | not read | n/a | Catalogue list | Product, Type, Sold in CRM, Readiness, Status (plan section 4); view 2; P3.3 | none stated | TO DO: compare with the old filters |
| B1.3 | Search | not read | n/a | Catalogue list | search (plan section 4); `GET /picker` for the pickers (P2.5); P3.3 | none stated | TO DO |
| B1.4 | Sort | not read; the old saved views hold `sortKey` and `sortAsc` (slice 1 C5-4) | n/a | Catalogue list | the plan's list names no sort [I]; view 2 not opened | none stated | TO DO |
| B1.5 | Paging | the server clamps `per_page` to 100, `CrmProductController.php:93`; the pickers ask for 200 (slice 1 C1-1) | n/a | Catalogue list | paged flat list (plan section 4; P2.5, P3.3) | seeded 5,000-row test (plan section 9) | TO DO |
| B1.6 | Saved views | `product-catalog-list-view.tsx:216` to `crm-saved-views.tsx:43,62,76`; `GET/POST/DELETE /api/crm/saved-views`, module `products` or `services` (`CrmSavedViewController.php:33, :46-47`); conditions `{search, sortKey, sortAsc}` (slice 1 C5-4) | TO RUN (F1) | Catalogue list and Services list | no home in the plan's list or steps [I]; slice 1 D-7: keep a module key | none stated; no test names any saved-view route (slice 1) | TO DO [C: DECISION (S), D-7] |
| B1.7 | Bulk delete | `product-catalog-list-view.tsx:158`; `POST crm/products/bulk/delete` (`CrmProductController.php:372-402`) | TO RUN (F14) | list toolbar | bulk actions carried over (plan section 4), More menu as relayed (mock-up not opened); P3.3; items go to the Bin | `ProductMasterSyncTest` (slice 1) | TO DO [C: OK] |
| B1.8 | Bulk assign | `product-catalog-list-view.tsx:172`; `POST crm/products/bulk/assign` (`:404-436`) | TO RUN (F14) | list toolbar | same; P3.3 | no test names it (slice 1) | TO DO [C: GAP (S), no test] |
| B1.9 | CSV import | `product-catalog-list-view.tsx:313`; `crmService.importProducts` (`services/crm/index.ts:559`); `import()` `:721-800`, max 500 rows, `assignedTo` required | TO RUN (F10) | list toolbar | More menu (DECISIONS design-gate row: both import buttons under More); P3.3 | `ProductMasterSyncTest`, `ProductWritePathDefectsTest` (slice 1) | TO DO [C: OK] |
| B1.10 | CSV export | `product-catalog-list-view.tsx:195` (`CrmExportButton`, a link to `productsExportUrl`, `services/crm/index.ts:556`); `export` `:688-719`, 17 columns | not countable (a download leaves no row unless audited: F4) | list toolbar | More menu; P3.3 | no test names it (slice 1) | TO DO [C: GAP (S), no test] |
| B1.11 | Duplicates | `product-catalog-list-view.tsx:299`; `GET crm/products/duplicates` (`:438-467`, same name or same SKU, per item type) | TO RUN (F14) | list toolbar | More menu; P3.3; the pair review is a separate flow (P3.6) | no test names it (slice 1) | TO DO [C: GAP (S), no test] |
| B1.12 | Merge | `product-catalog-list-view.tsx:300` through `CrmDuplicatesDialog`; `POST crm/products/merge` (`:469-514`, `ProductRepointer` repoints the 12 pointers) | TO RUN (F14) | the duplicates dialog | More menu; P2.7, P3.3, P3.6 | `ProductMergeRepointTest`, `ProductMasterSyncTest` (slice 1) | TO DO [C: OK] |
| B1.13 | Recycle Bin link and restore | `recycle-bin-view.tsx:72,93,110` (labels `:22-23`); `CrmRecycleBinController` index `:62`, restore `:107-148`, force delete `:150` (slice 1 C5-1 to C5-3); the link from the old list is not read | TO RUN (F5); 3 items in the Bin on tenant 6, ids 6 to 8 [C] | Recycle Bin screen | the Bin stays; a binned item is the "In the Recycle Bin" Sell state (plan section 3 default e); a link from the new list is not named [I] | `ProductMasterSyncTest` and `ProductMirrorTest` pin the restore; nothing tests a permanent delete of an item that documents use (slice 1) | TO DO [C: GAP (M), a permanent delete cascades or nulls 11 tables; restore OK] |
| B1.14 | Empty and error states | not read | n/a | Catalogue list | "empty, error and 403 states" are in the acceptance run (plan section 10) | real-browser run as Admin, HR and a sales profile (plan section 10) | TO DO |
| B1.15 | Services mirror | Services have no routes of their own: they call `crm/products` with `itemType=service` (slice 1, intro of section C); `service-list-view` not read | TO RUN (F15); 2 live services on tenant 6 [C] | Services screen | the same list with a Type filter; a Services sidebar entry only for people who hold the Services right but not Products (plan section 3 default a; step 4.1) | none stated | TO DO |
| B1.16 | Category picklist admin | `product-catalog-list-view.tsx:125`, `products/[id]/page.tsx:64`, `services/[id]/page.tsx:54`; `GET/POST/PUT /api/crm/picklist-values*`, module `products`, field `category`, `CrmPicklistController::FIELD_KEYS` `:55`, shared by Products and Services (slice 1 C5-5) | TO RUN (F3) | list and detail pages | Category is a Sell field of the form (P3.4); where a person adds or edits a category value is not named [I] | none stated | TO DO |
| B1.17 | The Catalogue / Product lines switch | plan section 2 says the screen has the switch; `product-lines-view.tsx` not read | n/a | `/module/crm/sales/products` | one list of Products with their Sub-products (plan section 4); old addresses redirect (P3.7) | link-scan and redirect tests (plan section 9) | TO DO |

### B2. The old detail page (`product-detail-page.tsx`) (INCOMPLETE: 0 of 10 audited)

| # | Item | Evidence [C] | Used on dev? | Old home | NEW HOME (plan step; mock-up view) | Proof planned | Status |
|---|---|---|---|---|---|---|---|
| B2.1 | Details tab | not read | n/a | detail page | Sub-product page, Details tab (plan section 4, DECISIONS tabs row); view 4; P3.5 | none stated | TO DO |
| B2.2 | Bundle tab: add and remove a component | `product-detail-page.tsx:71,141-152,174`; `bundle-items` GET, POST, DELETE (`CrmProductController.php:518-638`); no audit line is written (slice 1 A4-1) | 0 bundle rows [C] (F8) | detail page | Bundle tab keyed by `sell.crmProductId`; P3.5 | no test names the 3 routes (slice 1) | TO DO [C: GAP (S), no test] |
| B2.3 | History tab | `product-detail-page.tsx:157`; `GET crm/products/{id}/history` reads one entity type for the CRM id (slice 1 C1-15) | TO RUN (F4) | detail page | History tab, view 4 ("now includes About edits"); P2.5, P3.5 | `ProductWritePathDefectsTest` pins the HR 403 (slice 1) | TO DO [C: GAP (M), no route for the unified record] |
| B2.4 | Usage tab | `GET product-master/{id}/usage` (`ProductMasterController.php:265-315`) reads signals, ICP state, price books and quotes only (slice 1 C2-10); `product-line-record-page.tsx:246`, `product-links-panels.tsx:132` | TO RUN (F7, F9, F11) | detail page since 3 Nov (plan section 2) | Usage tab, view 4 ("which quotes, orders, invoices, price books and signals"); P2.5, P3.5 | `ProductMasterApiTest`, `ProductMasterSyncTest` (slice 1) | TO DO [C: GAP (M)] |
| B2.5 | Signals & ICP tab | "since 3 Nov" (plan section 2); component not read | n/a | detail page | Signals & ICP tab; P3.5 | none stated | TO DO |
| B2.6 | Custom Fields | `product-detail-page.tsx:196-207, :386-406`; `POST /api/platform/fields/values/crm_products/{id}` (slice 1 A4-3, C5-6) | 0 definitions and 0 values for `crm_products` [C] (F2) | detail page | conditional "Custom Fields" tab when a CRM row exists; P3.5 | none stated | TO DO [C: DECISION (S)] |
| B2.7 | Image upload | `product-detail-page.tsx:185`; `POST crm/products/{id}/image` (`:642-686`) | not counted | detail page | a side call in the Sell section; P3.5 | no test names it (slice 1) | TO DO [C: GAP (S), no test; the Sell read has no `imageUrl`] |
| B2.8 | Edit | opens `create-product-modal.tsx`, `PUT crm/products/{id}` (`create-product-modal.tsx:136`) | n/a | detail page | Edit opens the three-section form; P3.4 | none stated | TO DO |
| B2.9 | Delete one record | `crmService.deleteProduct` has no caller: the old screens bin only through bulk delete (slice 1 C1-5) | TO RUN (F5, F14) | none for one record | as relayed by the coordinator: Delete from catalogue moves the CRM row to the Recycle Bin (administrators), Archive hides the whole record; not in the plan text I read [I] | none stated | TO DO [C: DECISION (S), now answered as relayed; check it also covers the hard delete of an offer (B5.6) and of a Signals product (B5.7)] |
| B2.10 | Other header actions (status, assigned to, anything else) | not read | n/a | detail page | not named | none stated | TO DO |

### B3. Create and edit flows (INCOMPLETE: 0 of 4 audited)

| # | Item | Evidence [C] | Used on dev? | Old home | NEW HOME (plan step; mock-up view) | Proof planned | Status |
|---|---|---|---|---|---|---|---|
| B3.1 | Create a catalogue item | `create-product-modal.tsx:137`; `POST crm/products` (`CrmProductController.php:175-250`) | TO RUN (F4, F15) | create modal | the three-section form: About, Sell in CRM, Ideal customer; P3.4; view 3 | `CrmFullLifecycleGoldenPathTest` and others (slice 1) | TO DO (the field-level cover is slice 1 A2) |
| B3.2 | Edit a catalogue item | `create-product-modal.tsx:136`; `PUT crm/products/{id}` (`:252-332`) | same | create modal | same | same | TO DO |
| B3.3 | "+ Create New Line" inside the form | `create-product-modal.tsx:58` (`getProductLines`); a non-administrator now gets a 403 (P2-B1, plan log) | TO RUN (F15) | create modal | the Product select; an "Add product" entry for administrators: slice 1 says the mock-up has none | `ProductWritePathDefectsTest` (plan log) | TO DO [C: GAP (S), G-5] |
| B3.4 | Services create and edit | the Services screens call the Products routes with `itemType=service` (slice 1); whether they use the same modal is not read; a service stores its line since P2-B1 (plan log) | TO RUN (F15) | Services screens | Type = Service on the one form; P3.4 | `CrmProductProductMasterLinkTest` (plan log) | TO DO |

### B4. The product-line view and page (the S1 "Product Master" components) (INCOMPLETE: 0 of 4 audited)

| # | Item | Evidence [C] | Used on dev? | Old home | NEW HOME (plan step; mock-up view) | Proof planned | Status |
|---|---|---|---|---|---|---|---|
| B4.1 | Product lines list | `product-lines-view.tsx:30,45` (`service.list` to `GET /api/product-master`) | n/a | Product lines switch of `/module/crm/sales/products` | the one list; the old route shape stays until P4.3 | `ProductMasterApiTest`, `ProductMasterTest` (slice 1) | TO DO |
| B4.2 | Product line and Sub-product record page | `product-line-record-page.tsx:34` (`service.get`), `:246` (usage); archive and restore calls (slice 1 C2-8, C2-9) | TO RUN (F5) | S1 record page | Product page (Overview, Sub-products, Signals & ICP) and Sub-product page; route `/portfolio/products/{masterId}` (plan step 3.5) | none stated | TO DO |
| B4.3 | Product line form | `product-master-form.tsx:46,58` (create, update) | n/a | S1 form | the one form; P3.4 | `ProductMasterWriteApiTest`, `ProductWriterV2Test` (slice 1) | TO DO |
| B4.4 | Links panels (signals, ICP, usage) | `product-links-panels.tsx:14,132` | n/a | S1 panels | Usage and Signals & ICP tabs; P3.5 | none stated | TO DO |

### B5. The Portfolio's own features (`components/domain/portfolio/*`) (INCOMPLETE: 0 of 12 audited)

| # | Item | Evidence [C] | Used on dev? | Old home | NEW HOME (plan step; mock-up view) | Proof planned | Status |
|---|---|---|---|---|---|---|---|
| B5.1 | Portfolio list grouped by Product | `portfolio-view.tsx:83,87` (`getOffers`) | 50 offers on dev [C] | Market Signals tab 3 | the unified list; P3.3; view 2, no Signals buttons (DECISIONS design-gate row) | none stated | TO DO |
| B5.2 | Sync Excel | `portfolio-view.tsx:118`, menu item `:322-325` shown when `canManage`; `POST /api/portfolio/import` (sheet "Product Portfolio" rows 5 to 29, 18 columns, Python openpyxl by `shell_exec`) | TO RUN (F10) | Portfolio toolbar menu | More menu, both import buttons (DECISIONS design-gate row); P3.3 | `ProductMasterSyncTest:1113` (slice 1) | TO DO [C: OK] |
| B5.3 | Readiness confirmation (admin) | `portfolio-view.tsx:219`; `POST portfolio/offers/{id}/readiness` (`confirmReadiness :193`) | 0 confirmed [C] (F12) | Portfolio offer page | stays an admin action on the Sub-product Overview; P3.5 | no test names it (slice 1) | TO DO [C: GAP (S), no test] |
| B5.4 | Readiness log | `portfolio-view.tsx:177`; `GET portfolio/offers/{id}/readiness-log` (`:249`) | 0 rows [C] (F12) | Portfolio offer page | kept; P3.5 | no test names it (slice 1) | TO DO [C: GAP (S), no test] |
| B5.5 | Offer dialog (create and edit an offer) | `product-form-dialog.tsx:232` (next id), `:305` (POST and PUT), `:341-429` (fields) | 0 of 50 offers saved through the API [C] (F12) | Portfolio drawer | the About section of the form (view 3); P3.4 | `ProductPortfolioIcpTest`, `CustomProductApiTest` (slice 1) | TO DO [C: GAP (S), G-4: the mock-up About card omits six fields of the dialog] |
| B5.6 | Delete an offer (hard) | `portfolio-view.tsx:135`; `DELETE portfolio/offers/{id}` (`:474-528`) | not counted | Portfolio drawer | see B2.9: Archive, as relayed; P3.5 | `ProductMasterSyncTest` (slice 1) | TO DO [C: DECISION (M)] |
| B5.7 | Delete a Product (Signals product, level 1) | `portfolio-view.tsx:149`; `DELETE signals/icp/products/{product}` (`ProductIcpController::destroy :62`) | `g2g_signal_products` holds 1 row, status `deleted` [C] | Portfolio | the Product page; not named in the plan | `CustomProductApiTest:174-176` (slice 1) | TO DO [C: DECISION (S)] |
| B5.8 | New product dialog (level 1) | `new-product-dialog.tsx:43`; `POST signals/icp/products` (`ProductIcpController::create :27`) | `g2g_signal_products` holds 1 row [C] | Portfolio | no entry in the mock-up or the list design (slice 1) | `CustomProductApiTest` and others (slice 1) | TO DO [C: GAP (S), G-5; D-5 asks whether two creators stay] |
| B5.9 | ICP tabs (three customer models) | not read; payload `icp_configs` (slice 1 A3.3) | `g2g_icp_profiles` 1 row, 1 version [C] | Portfolio offer page | "Ideal customer" section and "Signals & ICP" tab (plan section 4) | `ProductPortfolioIcpTest`, `SubProductIcpOwnershipTest` (slice 1) | TO DO |
| B5.10 | Setup steps (named in the brief) | not identified: I do not yet know which control the brief means | n/a | Portfolio | not named | none stated | TO DO |
| B5.11 | Product profile panel | `product-profile-panel.tsx:98`; not read | `g2g_product_profiles` 1 row, one per organisation [C] | Portfolio | not a product record (slice 1 A3.4); stays in Company Opportunities Advanced tools | none stated | TO DO |
| B5.12 | Portfolio product page (Overview and Offers) | `product-detail-view.tsx:57` (`getTaxonomy`); plan section 2: "only Overview + Offers" | n/a | Portfolio product page | Product page: Overview, Sub-products, Signals & ICP (plan section 4) | none stated | TO DO |

Row count of B, tallied from the tables: B1 17 + B2 10 + B3 4 + B4 4 + B5 12 = 47. Rows carrying a slice 1 verdict: B1.6 to B1.13 (8), B2.2, B2.3, B2.4, B2.6, B2.7, B2.9 (6), B3.3 (1), B5.2 to B5.8 (7) = 22: OK 4 (B1.7, B1.9, B1.12, B5.2), GAP 13 (B1.8, B1.10, B1.11, B1.13, B2.2, B2.3, B2.4, B2.7, B3.3, B5.3, B5.4, B5.5, B5.8), DECISION 5 (B1.6, B2.6, B2.9, B5.6, B5.7).

---

## F. Usage on dev, SELECT only (INCOMPLETE: 0 of 15 census lines run by me)

**Left to do for F (everything):** run each line below on the dev connection `mysql` only (host 202.47.117.220, never `live`), SELECT or SHOW only, counts only, no personal data, one `php artisan tinker --execute='...'` call per query, no heredocs. For the lines whose table I have not seen named, find the table first from the controller or model named in the row. The "Carried number" column is what ANOTHER track measured on dev and wrote in the plan log or in slice 1: it is a lead, not my result.

| # | Census line | Where to look | Carried number [C] | Run by me | Feeds | Status |
|---|---|---|---|---|---|---|
| F1 | Saved views for modules `products` and `services` | the table behind `CrmSavedViewController` (module values at `:33, :46-47`, slice 1): count per module and per user, no names | none | no | B1.6 | TO RUN |
| F2 | Custom field definitions for products and services | `tblcustom_fields` and `g2g_custom_field_values` (slice 1 A4-3): definitions and values for record table `crm_products`, and look for a `services` key | 0 definitions and 0 values for `crm_products` (slice 1 A4-3; the 2 definitions in the table are deleted fields of other tables); services not checked | no | B2.6 | TO RUN (services; re-run products) |
| F3 | `products.category` picklist values | the table behind `CrmPicklistController` (`FIELD_KEYS` `:55`, slice 1): module `products`, field `category`, count and the value names (names only) | none | no | B1.16 | TO RUN |
| F4 | Audit rows by event for entity `crm_products` | the audit table written by `HasCrmAuditTrail` and `EventRecorder`: group by event (for example `crm.product.updated`), count only | none | no | B2.3, B1.10, B3.1 | TO RUN |
| F5 | Recycle Bin rows for products and services | `crm_products` rows in the Bin, split by item type | 3 items in the Bin on tenant 6, ids 6 to 8 (plan P0 and P2-A logs); the split by type is not known | no | B1.13, B2.9, B4.2 | TO RUN |
| F6 | Report definitions that mention products | the reports engine's definition table and `CrmReportModules`: search the stored definitions for the products module or `crm_products`, count only | none | no | B (and E): which stored reports read products | TO RUN |
| F7 | Price-book items | `crm_price_book_items` | 27 (the P2-A snapshot in the plan log, all organisations) | no | B2.4 | TO RUN (confirm) |
| F8 | Bundle rows | `crm_product_bundle_items` | 0 (slice 1 A4-1) | no | B2.2 | TO RUN (confirm) |
| F9 | Quotes per product | `crm_quote_line_items` grouped by product, distinct quotes | 34 quote lines referencing 6 products, 3 of them the binned items 6 to 8 (P2-A log): a quote picker must still show a binned product on an old document | no | B2.4 | TO RUN (distinct quotes per product) |
| F10 | Imports | any import log table; the CRM import (`import()` `:721-800`), the Portfolio Excel import (`POST /api/portfolio/import`), `g2g:import-portfolio`; the table may not exist | none | no | B1.9, B5.2 | TO RUN (find the table first) |
| F11 | Order, invoice and opportunity lines | sales order lines, invoice lines, `crm_opportunity_products` | 16, 16, 20 (P2-A snapshot) | no | B2.4 | TO RUN (confirm) |
| F12 | Portfolio features touched by a person | `g2g_product_offers.is_user_edited` and `readiness_confirmed`; `g2g_offer_readiness_log` | 0 of 50 offers edited, 0 confirmed, 0 log rows (slice 1 A3-1) | no | B5.3, B5.4, B5.5 | TO RUN (confirm) |
| F13 | Pair-review decisions | `g2g_product_match_decisions` | 0 rows (new table, P2-D log) | no | B1.11, B1.12 context | TO RUN (confirm) |
| F14 | Merge, bulk assign and bulk delete use | the audit events of F4 for merge, assign and delete; the `deleted_at` rows of F5 | none | no | B1.7, B1.8, B1.11, B1.12, B2.9 | TO RUN |
| F15 | Catalogue item mix | `crm_products` by item type, live or in the Bin, with or without a line | 9 live items (7 products, 2 services) and 3 in the Bin; only tenant 6 has catalogue rows (P2-D log, slice 1 section 0) | no | B1.15, B3.1 to B3.4 | TO RUN (confirm) |

Row count of F: 15; a carried number exists for F2, F5, F7, F8, F9, F11, F12, F13, F15 (9) and for none of F1, F3, F4, F6, F10, F14 (6).

---

## Gaps and decisions so far (INCOMPLETE: none of these is a finding of mine)

**None of the lines below is verified by me.** The first group repeats slice 1's verdicts that touch a screen feature (already counted in slice 1); the second group is my inference from the plan text.

Carried from slice 1 (to be placed on the right screen row at resume): the History route for the unified record (B2.3, M); Usage reads only signals, ICP, price books and quotes (B2.4, M); the Recycle Bin's permanent delete of an item that 11 tables point at (B1.13, M); the mock-up About card omits six fields of today's offer dialog (B5.5, S); no "Add product" entry for level 1 (B3.3 and B5.8, S); bulk assign, export, duplicates, bundle items, image upload, readiness confirmation and log have no test that names them (S each); decisions open in slice 1: saved views (B1.6), custom fields keyed to the CRM id (B2.6), deleting one record, an offer or a Signals product (B2.9, B5.6, B5.7).

My inferences [I] to confirm or drop at resume:
1. Saved views have no home in the plan (B1.6). Recommended home to propose: a module key on the unified list, as slice 1 D-7 says; size S to M depending on whether old saved views of Products and Services are migrated. Depends on F1.
2. Sort, the Recycle Bin link and the Category picklist admin are not named in the plan's list (B1.4, B1.13, B1.16). Check the components and the mock-up before calling any of them a gap.
3. "Delete from catalogue / Archive" and the "Offered on new documents" label are relayed decisions that I did not find in the slice file or the DECISIONS entry (B2.9, B5.6). If they are only in the mock-up or the P2-C brief, write them into the plan.

## What in the plan is wrong

Nothing found by me yet, because I compared nothing against code. The plan claims I would check first: (a) section 2, "a catalogue item has tabs Details, Bundle, History (+ Signals & ICP and Usage since 3 Nov)"; (b) section 2, "the Portfolio's own product page has only Overview + Offers"; (c) section 4, "bulk actions, import/export, duplicates/merge carried over from the old Catalogue" against the real toolbar of `product-catalog-list-view.tsx`.

## Could not verify (everything in B and F)

No component was read, the mock-up was not opened, no database query was run, and the P2-C and P2-D briefs were not read. Every number in F is carried from another track; every file:line in B is carried from slice 1 with no directory confirmed. The uncommitted P2-C and P2-D files on disk may change under any audit of the endpoints.

## Resume order

1. Open the mock-up (views 2, 3 and 4) and write down the exact labels: the Offered on new documents switch, Delete from catalogue, Archive, the More menu entries, the list columns and filters.
2. Read the components listed at the top of section B in full; add a row for every control that is not in the checklist.
3. Run the 15 census lines of F one at a time and fill the numbers.
4. Replace every TO DO with OK, GAP or DECISION (new home, plan step, proof), then write the top gaps with sizes S, M or L and the final counts at the top.
