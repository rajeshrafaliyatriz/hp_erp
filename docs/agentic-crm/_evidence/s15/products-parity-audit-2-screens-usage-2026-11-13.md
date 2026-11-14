# Products parity audit, slice 2: screen features of the old module (B) and usage on dev (F)

Track S15-AUD, slice 2 of the audit named in `Docs/agentic-crm/briefs/S15-AUD-products-parity-audit.md`. Started 13 Nov 2026 (safe stop), resumed and finished 14 Nov 2026. Read only; this is the only file I wrote. Slice 1 (A, C, H) is `products-parity-audit-1-fields-endpoints-2026-11-13.md`; D and E belong to other auditors.

**STATUS: COMPLETE for sections B and F, with one row and a short list of files marked INCOMPLETE (section 0 and the end).** Every status below is mine (OK, GAP or DECISION); where slice 1 or the plan already said the same I say so.

## 0. What was read, and how claims are tagged

| Item | Value |
|---|---|
| Plan | `slices/S15-product-portfolio-merge.md` all 371 lines, including the audit addendum (D-1 to D-12, lines 329-334) and the P2-C and P2-D logs. `DECISIONS.md` entry 2026-11-13. Slice 1: summary, A3, A4 and C. |
| Mock-up | `mockup-product-portfolio.html` read in full (280 lines, the version on disk on 14 Nov: it has Add product for administrators, all About fields and the corrected unit-price caption). View numbers: 1 Sidebar, 2 The list, 3 Add / edit, 4 A sub-product page, 5 Possible matches. |
| Old components read in full | `components/domain/crm/product-catalog-list-view.tsx` (327 lines), `product-detail-page.tsx` (427), `create-product-modal.tsx` (327), `service-list-view.tsx` (7), `product-links-panels.tsx` (163), `crm-recycle-bin-link.tsx`. |
| Old components skimmed (control lists by grep, headings and handlers) | `product-lines-view.tsx`, `product-line-record-page.tsx`, `recycle-bin-view.tsx`, `crm-saved-views.tsx`, `product-lines-model.ts` (`detailTabs`), `components/domain/portfolio/portfolio-view.tsx`, `product-form-dialog.tsx`, `new-product-dialog.tsx`, `product-detail-view.tsx`, `product-profile-panel.tsx`, `product-icp-section.tsx`, `product-setup-steps.tsx`, `app/module/crm/sales/products` and `services` (file list and error handling only). All under `C:\Users\MILAN\Downloads\g2gv0`. |
| **INCOMPLETE: not opened** | `product-master-form.tsx` (294 lines, the S1 drawer), `product-master-model.ts`, `product-signals-icp-tab.tsx`, `product-usage-tab.tsx`, `product-master-parts.tsx`, `product-line-columns.tsx`, `crm-duplicates-dialog.tsx`, `crm-import-dialog.tsx`, `crm-bulk-action-bar.tsx`, `crm-history-tab.tsx`, `lib/product-form.ts`, the body of the Portfolio detail dialog (`portfolio-view.tsx:440-640`) and the field-by-field body of `product-icp-section.tsx`. Their behaviour is taken from how the parents call them. No browser was used: nothing was seen rendered. |
| Not read | `briefs/S15-P2C-writer-v2.md`, `briefs/S15-P2D-unify-command.md`, `00-PROGRESS.md`, `SESSION-RULES.md`, `DB-RULES.md`. |
| Database | dev only: connection `mysql`, host 202.47.117.220, database `hp_erp` (read back from the connection config in the first query). SELECT and SHOW only, counts only, no personal data, one `php artisan tinker --execute` call at a time. `live` never touched. Queries are listed in the appendix. |
| Repos | hp_erp `Milan-2` and g2gv0 `milan1`; I ran no git command and no PHPUnit. Other tracks' uncommitted files may change. |

Tags: **[V]** verified, I ran it on dev. **[S]** read in source by me, file:line. **[M]** read in the mock-up (view number). **[P]** stated by the plan or by slice 1; I did not re-check it. **[I]** inferred.

## 1. Summary

| Section | Rows | OK | GAP | DECISION | INCOMPLETE |
|---|---|---|---|---|---|
| B1 old Catalogue list, Services mirror | 19 | 12 | 4 | 3 | 0 |
| B2 old detail page | 11 | 9 | 2 | 0 | 0 |
| B3 create and edit flows | 7 | 5 | 2 | 0 | 0 |
| B4 product-line view and page (the S1 screens) | 7 | 4 | 2 | 0 | 1 |
| B5 the Portfolio's own features | 17 | 12 | 4 | 1 | 0 |
| **B total** | **61** | **42** | **14** | **4** | **1** |
| F usage census lines (all run by me, section F) | 16 | n/a (measured) | | | 0 |

The 14 GAP rows are **11 distinct findings** (G-S2-1 to G-S2-11); the 4 DECISION rows are 4 decisions (DS-1 to DS-4). The census (F) also answers the plan's open decision D-7 (saved views): see DS-2.

**What the screens hold up under.** Every control of the old Catalogue list has a home except bulk select, the Category and Qty columns and the Recycle Bin entry. Import, export, duplicates and merge are in the mock-up's More menu [M view 2]. Every About field of today's offer dialog (15 inputs) is now in the form, which has 16 rows [M view 3, lines 164-179]. The Details, Bundle, Usage, History, Signals & ICP and Custom Fields tabs all have a decided home. The old list has **no filter at all** except search [S `product-catalog-list-view.tsx:211-217`], so the new filters are an addition, not a loss.

**What the plan and the mock-up miss (top, one line each; sizes S/M/L; detail at the end):**
1. G-S2-9 (M) The old Portfolio Product page carries 4 setup steps, a "Customer and buyer summary" and a "Review opportunities" link; the new Product page (Overview, Sub-products, Signals & ICP) names none of them.
2. G-S2-1 (S) Bulk select, reassign and delete have no control in the new list (the mock-up has no checkbox column and More lists only Import, Export, Find duplicates).
3. G-S2-3 (S) The new list has no Recycle Bin entry, yet D-10 now sends "Delete from catalogue" into it (3 items sit in the Bin on dev and 3 quote lines depend on them).
4. G-S2-7 (S) No Status (Active, Archived, All) filter in the list: an archived record could not be found or restored. The S1 lines list has it [S `product-lines-view.tsx:23-26,167`].
5. G-S2-4 (S) The Details tab in the mock-up shows 8 of the 15 fields the old tab reads, and does not say which hide for a Service.
6. G-S2-2 (S) The Category and Qty In Stock columns have no home (7 of 9 live items carry a quantity).
7. G-S2-8 (S) 5 shared Products exist on dev and are read-only for an organisation; the mock-up has no Shared mark.
8. G-S2-6 (S) Mock-up view 3 still shows "Sold through CRM" plus "Active", not the one switch of D-1.
9. G-S2-5 (S) The product image has no place on the page.
10. G-S2-10 (S) The old address `/portfolio/{key}` has no redirect in P3.7.
11. G-S2-11 (S) Mock-up view 3 names the ICP tabs wrongly.

---

## B. Screen features of the old module

Plan steps are those of section 5 of the slice file (P2.5 read API, P2-E1 and P2-E2 the new safety and read steps of the 13 Nov addendum, P3.3 list, P3.4 form, P3.5 pages, P3.6 matches, P3.7 redirects, 4.1 sidebar). "§10" is the acceptance run of plan section 10. Line numbers are in g2gv0 unless stated.

### B1. The old Catalogue list and the Services mirror (`product-catalog-list-view.tsx`)

| # | Item | Evidence | Used on dev? | Old home | NEW HOME (mock-up view; plan step) | Proof planned | Status |
|---|---|---|---|---|---|---|---|
| B1.1 | Columns | [S] `:226-239`: checkbox, Name, Category, Unit Price, Qty In Stock (products only, `:237`), Active, plus Product line / Signals / ICP only when some item has a line (`:138-139`) | [V] 9 live items: category 9, qty in stock 7, price 9, cost 9 | Catalogue list | [M view 2] columns are Name, Type, SKU, Price, Sold in CRM, Readiness, Signals, ICP. **Category and Qty In Stock are not there** and Active is only implied by Sold in CRM | none stated | **GAP (S)** G-S2-2: add Category and Qty In Stock (or write down that they move to the page only) in P3.3 |
| B1.2 | Filters | [S] none: the whole file has one search box and the Saved views button (`:211-217`) | n/a | Catalogue list | [M view 2] Product, Type, Sold in CRM, Readiness (all new) | none stated | OK (a superset; see B4.2 for Status) |
| B1.3 | Search | [S] `:214` "Search name, SKU, or number…", server side `search` (`:105`) | n/a | Catalogue list | [M view 2] "Search products…"; P2.5 and P3.3 | none stated | OK (the new search must keep matching SKU and product number; add to the P3.3 test) |
| B1.4 | Sort | [S] four sortable heads: name, category, unit_price, qty_in_stock; default name ascending (`:27, :86-87, :234-237`) | n/a | Catalogue list | the plan's list names no sort and the mock-up shows none [M view 2] | none stated | **DECISION (S)** DS-1: grouped by Product then name only, or keep sort by name and price inside a group |
| B1.5 | Paging | [S] `PAGE_SIZE = 20`, Previous / Next, "Page x of y", total count (`:40, :276-283`) | n/a | Catalogue list | paged flat list (plan 2.5, 3.3); with grouping the plan must say what a page counts (Sub-products or groups) | seeded 5,000-row test (plan section 9) | OK |
| B1.6 | Saved views | [S] `:216`; `crm-saved-views.tsx:27-134` dropdown with Save current and Remove; conditions `{search, sortKey, sortAsc}`; module `products` or `services` | [V] **0 rows** in `crm_saved_views` (all modules) | list toolbar | none in the plan's list or steps; slice 1 D-7 "decide after the usage census" | none | **DECISION (S)** DS-2 (the census answers it: see F1) |
| B1.7 | Bulk delete | [S] `:154-167` confirm "moves them to the Recycle Bin"; bar `:317-324` | [V] 3 items are in the Bin, so a delete happened by some path (F5, F14) | list bar | plan section 4 says "bulk actions carried over", but [M view 2] has **no checkbox column and no bar**; More lists only Import, Export, Find duplicates | none stated | **GAP (S)** G-S2-1: checkbox column and the shared bar in P3.3; label it "Delete from catalogue" (D-10) |
| B1.8 | Bulk reassign | [S] `:169-181`, people from `useAssignableEmployees` (`:152`) | not measurable (no audit rows, F14) | list bar | same | none stated | **GAP (S)** G-S2-1 (same finding) |
| B1.9 | CSV import | [S] `:306-315`; template of 11 columns `:35-38` (Name, SKU, Category, Unit Price, Cost Price, Currency, Vendor, Qty In Stock, Reorder Level, Description, Assigned To user id); no type, line, tax or GL | not measurable (no import log, F10) | list toolbar | More menu, both import buttons [M view 2; DECISIONS design-gate row]; P3.3. Imported rows have no Product, so they land in the "No product yet" group (plan section 4) | `ProductMasterSyncTest`, `ProductWritePathDefectsTest` [P] | OK |
| B1.10 | CSV export | [S] `:195` a link to `productsExportUrl` carrying only the search text | not measurable | list toolbar | More menu [M view 2]; P3.3 | no test names it [P slice 1 G-8] | OK |
| B1.11 | Find duplicates | [S] `:200-203`, dialog `:295-304` | not measurable | list toolbar | More menu "Find duplicates" [M view 2]; the pair review is a separate flow (view 5, P3.6) | no test names it [P] | OK |
| B1.12 | Merge | [S] `:300` through `CrmDuplicatesDialog`; route repoints the 12 pointers (`ProductRepointer`) | not measurable | duplicates dialog | same More menu entry; P2.7, P3.6 | `ProductMergeRepointTest` [P] | OK |
| B1.13 | Recycle Bin link and restore | [S] a "Recycle Bin" button in the header (`:194`; component `crm-recycle-bin-link.tsx`, shared by all 4 CRM lists); the Bin lists products and services, Restore (`recycle-bin-view.tsx:22-23, :89`), permanent delete with a confirm (`:106`) | [V] 3 binned items on tenant 6 (2 products, 1 service); 3 quote lines depend on them | list header | [M view 2] **no Recycle Bin entry**; D-10 sends "Delete from catalogue" into the Bin, so an administrator needs the link | `ProductMasterSyncTest`, `ProductMirrorTest` pin the restore [P] | **GAP (S)** G-S2-3: put "Recycle Bin" in the More menu (P3.3). The permanent-delete guard is slice 1 G-1 (P2-E2) |
| B1.14 | Empty, loading and error states | [S] loading row (`:243-247`), empty text "No products yet. Click Add Product" (`:248-253`), error banner (`:220`), notice banner (`:219`); the hint that points at the other view (`:142`) is obsolete | n/a | list | plan §10 "empty, error and 403 states" | the §10 browser run | OK |
| B1.15 | Services mirror | [S] `service-list-view.tsx:5-7` is a 3-line wrapper of the same component with `itemType="service"`; no Qty column for services (`:237`) | [V] 2 live services and 1 binned, tenant 6 | Services screen | the same list with a Type filter [M view 2]; the Services sidebar entry only for a person with the Services right and no Products right [M view 1; plan default a, step 4.1]; old addresses redirect (P3.7) | link-scan and redirect tests (plan section 9) | OK |
| B1.16 | Category picklist | [S] the list only READS the values (`:125-127`); the admin screen is `crm-picklist-admin.tsx` at `app/module/crm/master-fields/page.tsx`, module `products`, field `category`, shared by Products and Services (`CrmPicklistController.php:41-55`) | [V] 7 values (Hardware, Software, Subscription, Consulting, Support, Training, Other), all active; **the 9 live items hold 8 distinct categories and only "Training" is in the list** | CRM master fields screen | the admin screen is untouched (OK). The Category select of the new form reads the same list | none stated | **DECISION (S)** DS-3: the form's Category select has no option for 8 of 9 stored values [S `create-product-modal.tsx:219-223`]; free text with suggestions, or keep a list and backfill it. How the shared Select shows an unknown value was not checked |
| B1.17 | Catalogue / Product lines switch | [S] `scopeSwitch` prop `:61-69, :191` | n/a | Products screen | one list of Products with their Sub-products (plan section 4); old addresses redirect (P3.7) | link-scan and redirect tests | OK |
| B1.18 | Open a record | [S] a row click goes to the detail page (`:255`) | n/a | list | a Sub-product row opens the Sub-product page [M view 2, 4]; P3.5 | §10 | OK |
| B1.19 | Add button | [S] "Add Product" or "Add Service" (`:204-207`) | n/a | list header | "Add sub-product", and "Add product" for administrators [M view 2]; a service is a Sub-product of type Service | §10 | OK |

### B2. The old detail page (`product-detail-page.tsx`)

| # | Item | Evidence | Used on dev? | Old home | NEW HOME (mock-up view; plan step) | Proof planned | Status |
|---|---|---|---|---|---|---|---|
| B2.1 | Details tab | [S] `:272-316`: SKU (products only), Category, GL Account, Unit Price, Cost Price, Commission Rate, Default Tax Rate, Status, Usage Unit, Vendor, Qty In Stock, Qty In Demand, Reorder Level, Weight (the last four for products only), Description. Header line shows category and product number (`:246`) | [V] price, cost and SKU set on 9 of 9 live items; the 2 services also carry a SKU that the old screens hide | detail page | [M view 4] Details card shows SKU, Unit price, Cost price, Commission %, Tax rate and GL, Stock and Reorder, Assigned to, the switch. **Missing: Category, Usage unit, Vendor, Qty in demand, Weight, Description on quotes, Product no, Currency** | slice 1 E4 "form parity" covers the form, not this read page [P] | **GAP (S)** G-S2-4: build the tab from the 15 old read fields, hide the product-only ones for a Service (see B3.4) |
| B2.2 | Bundle tab | [S] `:318-380`: products only (`:48`), Add Component dialog with a product picker capped at 100 (`:61`) and quantity min 0.01, remove with no confirm (`:371-374`), "Components Total" tile (`:350-358`), empty state "Not a bundle yet" | [V] 0 bundle rows | detail page | Bundle tab keyed by `sell.crmProductId` [M view 4]; P3.5 | no test names the 3 bundle routes [P slice 1 G-8] | OK |
| B2.3 | History tab | [S] `:384` and `getProductHistory` (`:157`) | [V] **1 audit row** for `crm_products` (`crm.product.updated`) and none for offers or master rows, so the tab is nearly empty on dev | detail page | History tab including About edits [M view 4]; route in P2-E1 (slice 1 G-2) | `ProductWritePathDefectsTest` pins the HR 403 [P] | OK |
| B2.4 | Usage tab | [S] `product-links-panels.tsx:132-163` (`ProductUsagePanel`: signals, quotes, price books) | [V] quotes: 3 live items (2 products, 1 service) have 10 quotes each, the 3 binned items 1 each (33 quote lines); price-book items 27 in 3 books over 9 products; order lines 16 and invoice lines 16 (3 products each); opportunity products 20 (1 product) | detail page | Usage tab "quotes, orders, invoices, price books and signals" [M view 4]; the read is widened in P2-E1 (slice 1 G-3) | `ProductMasterApiTest` [P] | OK |
| B2.5 | Signals & ICP tab | [S] `product-links-panels.tsx:31-105`: record, usage, and "Edit sub-product details" that opens the S1 drawer without its Sell section (`showSell={false}`, `:93`); a rename follows into the title (`onRenamed`) | ICP: 1 profile, 1 version [P slice 1, not re-run]; the Sub-product records behind it: 25 + 25 [V] | detail page, only when the item has a master row (`product-lines-model.ts:140-149`) | Signals & ICP tab [M view 4]; the edit opens the unified form (P3.4) | §10 | OK |
| B2.6 | Custom Fields | [S] `:386-406` always mounted and hidden, tab shown only when fields exist (`:139, :394`), own Save button | [V] **0 definitions and 0 values** for any product or service table | detail page | conditional tab, only when a CRM row exists (D-4) [M view 4] | none stated | OK |
| B2.7 | Image | [S] preview and camera overlay in the header (`:218-242`), upload through `uploadProductImage` (`:185`); not in the create modal | [V] **0 of 12** items have an image | detail page header | [M view 3] "Weight · Image" in the form only; [M view 4] header has no picture; the Sell read has no `imageUrl` yet [P P2-C owed] | none stated | **GAP (S)** G-S2-5: keep the picture and its replace control on the page and add `imageUrl` to the Sell read (P2-C, P3.5) |
| B2.8 | Edit | [S] one "Edit" button (`:250-253`) opening `CreateProductModal` | n/a | detail page | Edit opens the three-section form [M view 3]; P3.4 | §10 | OK |
| B2.9 | Delete one record | [S] **there is no delete control** on the page, only Edit (`:249-254`); deleting is possible only through bulk delete in the list | [V] 3 binned items | none for one record | D-10: "Delete from catalogue" (administrators) moves the CRM row to the Bin and the Sub-product stays as Not sold; "Archive" hides the whole record (P3.5). [M view 4] shows no action buttons; build them from D-10 | none stated | OK |
| B2.10 | Page header | [S] back arrow, image, name, "category · product no" (`:213-247`) | n/a | detail page | breadcrumb "Product Portfolio › Product ›" and chips [M view 4] | §10 | OK |
| B2.11 | Not-found and error | [S] `app/module/crm/sales/products/[id]/page.tsx:93-96` shows the error and "Back to Products" | n/a | page | unknown or other-tenant ids give 404 (plan §10) | §10 and redirect tests | OK |

### B3. Create and edit flows (`create-product-modal.tsx`)

| # | Item | Evidence | Used on dev? | Old home | NEW HOME (mock-up view; plan step) | Proof planned | Status |
|---|---|---|---|---|---|---|---|
| B3.1 | Create a catalogue item | [S] `:99-145`; required: Name and Assigned To (`:100-103`); a product line is required when lines exist (`:115-118`); 18 fields (`:158-313`) | [V] 9 of 9 live items are assigned | modal | the three-section form [M view 3]; assigned to is required and defaulted to the caller by the writer (D-2); P3.4 | `ProductWriterV2Test` [P] | OK |
| B3.2 | Edit a catalogue item | [S] same modal seeded from the item (`:75-95`) | n/a | modal | same form; the type of a row never changes (writer rule) | `ProductWriterV2Test` [P] | OK |
| B3.3 | "+ Create New Line" inside the form | [S] `:170-176, :190-195`; a non-administrator now gets a 403 (P2-B1, plan log) | n/a | modal | "Add product" for administrators [M view 2]; the form's Product select lists existing Products | `ProductWritePathDefectsTest` [P] | OK |
| B3.4 | Service field rules | [S] for a Service the form shows Category in the line's place (`:198-207`), no SKU (`:210`), and no Vendor, Qty, Weight (`:277`); the detail page hides the same (`product-detail-page.tsx:276, :300-308`) | [V] 2 live services | modal and page | [M view 3] lists every Sell field with no rule for Type = Service; the writer already drops stock for a service (`CatalogueFields::applyServiceRules`, [P]) | none stated | **GAP (S)** G-S2-4 (same finding as B2.1): write the hide rules into P3.4 and P3.5 |
| B3.5 | Active and "sold" switches | [S] one "Active" switch (`:303-306`) | [V] all 12 items are active, also the 3 binned | modal | D-1: ONE switch "Offered on new documents" [M view 4 Details card]. **[M view 3] still shows "Sold through CRM [On]" and a separate "Active" in "Assigned to · Active"** | writer returns 422 when the two contradict [P] | **GAP (S)** G-S2-6: fix view 3 and build one control with that label |
| B3.6 | Description | [S] `:310-313` | n/a | modal | "Description on quotes" (plan default c) [M view 3] | none stated | OK |
| B3.7 | Save feedback | [S] `onSaved(message)` shows a green notice and reloads (`:138, list :288`) | n/a | modal | "One Save writes everything... a clear status for each section" [M view 3 note] | §10 | OK |

### B4. The product-line view and page (the S1 screens, `product-lines-view.tsx`, `product-line-record-page.tsx`)

| # | Item | Evidence | Used on dev? | Old home | NEW HOME (mock-up view; plan step) | Proof planned | Status |
|---|---|---|---|---|---|---|---|
| B4.1 | Product lines list | [S] search "Search name, ID or key…" (`product-lines-view.tsx:164`), expandable Products with Sub-products (`:301`), columns Name, ID, Readiness, Sold in CRM, Signals, ICP (`:214-219`), badges Archived / Shared / Sold, "New…" menu (`:136`) | [V] master rows: 5 shared Products, 25 + 25 Sub-products, 9 items | Products screen, Product lines switch | the one list [M view 2]; the S1 route shape stays until P4.3 | `ProductMasterApiTest` [P] | OK |
| B4.2 | Status filter (Active, Archived, All) | [S] `product-lines-view.tsx:23-26, :167`, kept in the address | [V] 0 archived master rows | Product lines list | [M view 2] filter bar has Product, Type, Sold in CRM, Readiness only; plan section 4 lists "Status" but the picture lacks it | none stated | **GAP (S)** G-S2-7: add Status (default Active) to the list so an archived record can be found and restored |
| B4.3 | Shared mark and read-only rule | [S] `SharedBadge`; a shared row has no Edit, Archive or Restore (`product-line-record-page.tsx:144-148`) | [V] **5 shared Products** (organisation 0) on dev | list and record page | none in the mock-up or the plan | none stated | **GAP (S)** G-S2-8: show Shared and hide the actions, in P3.3 and P3.5 |
| B4.4 | Record page actions | [S] Edit, Archive with a confirm (`:169-173, :215-217`), Restore, "Open catalogue page" (`:214`), "Sell in CRM" on a Not-sold Sub-product (`:344-351`) | n/a | record page | Edit, Archive, Restore; "Delete from catalogue" per D-10; the Sell switch replaces "Sell in CRM"; the catalogue link goes (one page). Archiving a Product with active Sub-products is refused (D-9) | none stated | OK |
| B4.5 | Record page tabs | [S] by kind: Overview, Signals & ICP, Sell in CRM, Usage (`:23, :189-192`) | n/a | record page | Sub-product: Overview, Details, Bundle, Usage, History, Signals & ICP (+ Custom Fields). Product: Overview, Sub-products, Signals & ICP [M view 4; DECISIONS tabs row] | §10 | OK |
| B4.6 | Sub-products table on a Product | [S] Name, Key, Status and "Add sub-product" (`:290-308`) | [V] 25 per organisation | Product record page | Product page "Sub-products" tab [M view 4 note]; P3.5 | §10 | OK |
| B4.7 | `product-master-form.tsx` (the S1 drawer) | not opened | n/a | S1 | replaced by the three-section form (P3.4); P2-C open item (f): the old drawer resumes a paused item [P] | none | **INCOMPLETE** (read the file and check each control against view 3) |

### B5. The Portfolio's own features (`components/domain/portfolio/*`, `app/portfolio`)

| # | Item | Evidence | Used on dev? | Old home | NEW HOME (mock-up view; plan step) | Proof planned | Status |
|---|---|---|---|---|---|---|---|
| B5.1 | Portfolio list | [S] a "Select Product" dropdown shows ONE Product's offers at a time (`portfolio-view.tsx:276-296`), a Refresh action (`:318`), offer cards with readiness (`:383`) | [V] 50 offers (25 + 25) | Market Signals tab 3 | the one grouped list with no Signals buttons [M view 2; DECISIONS]; Refresh is not in the picture (low) | §10 | OK |
| B5.2 | Sync Excel | [S] `:113-127`, menu item `:322-325` shown when `canManage`, result line "Import complete: n imported, n updated, n skipped" (`:119-121, :330-337`) | [V] 50 offers and none user-edited (F12), so they came from an import or sync [I] | Portfolio menu | More menu, both import buttons [M view 5 question 2, approved] | `ProductMasterSyncTest:1113` [P] | OK |
| B5.3 | Readiness confirmation (administrators) | [S] `:587-611`, dialog needs a note of 5 characters (`:212-214`), "Remove confirmation" (`:605-608`) | [V] **0 of 50 confirmed** | offer detail dialog | stays an administrator action on the Sub-product Overview (P3.5) [P slice 1]; not drawn in [M view 4] | no test names it [P slice 1 G-8] | OK |
| B5.4 | Readiness log | [S] `:613-` | [V] 0 log rows | offer detail dialog | the same Overview card (P3.5) | no test names it [P] | OK |
| B5.5 | Offer dialog fields | [S] `product-form-dialog.tsx:341-430`: Category (the Product), Status, Product ID, Name, Description, Features and capabilities, Customer problems solved, Target customers, Buying events, Benefits and proof, Pricing model, Typical deal size, Deployment, Delivery owner, Notes; next ID suggested from the category (`:224`) | [V] **0 of 50** offers saved through the API (`is_user_edited` 0) | Portfolio drawer | [M view 3] About card now holds all of them (16 rows, `mockup:163-180`) plus Implementation effort and the new Often sold together | `ProductPortfolioIcpTest`, `CustomProductApiTest` [P] | OK (slice 1 G-4 is closed by the mock-up; the ID suggestion and the older problem codes at `:374-379` must be carried in P3.4) |
| B5.6 | Partial-save outcome | [S] invalid ICP stops with "Nothing was saved" (`:291`); a partly saved offer shows a Close button (`:503`) | n/a | dialog | "Image, custom fields and bundle items save right after, with a clear status for each section" [M view 3 note] | §10 | OK |
| B5.7 | ICP in the dialog | [S] three model tabs (`:440-447`) and the ICP fields (`product-icp-section.tsx`) | [V] 1 profile, 1 version | dialog | "3 Ideal customer" section, same payload (plan section 4) [M view 3] | `ProductPortfolioIcpTest`, `SubProductIcpOwnershipTest` [P] | OK (field-by-field read of the ICP section is INCOMPLETE) |
| B5.8 | Delete an offer (hard) | [S] confirm then `DELETE` (`portfolio-view.tsx:131-142`, menu `:437`) | not counted | offer card menu | D-8: the unified page has no "remove About"; the old route refuses 409 while the master row holds a CRM pointer; Archive instead (P2-E2) | none stated | OK (decided) |
| B5.9 | Delete one of your own Products | [S] admin only, `p.custom`, confirm (`:144-152, :297`) | [V] `g2g_signal_products` 1 row, status deleted | Product selector | D-9: refused while the Product has Sub-products; the UI offers Archive only (P2-E2) | none stated | OK (decided) |
| B5.10 | New product dialog | [S] one name field (`new-product-dialog.tsx:58-86`), administrators only (`portfolio-view.tsx:302`) | n/a | Portfolio header | "Add product (administrators)" [M view 2, closes slice 1 G-5] | `CustomProductApiTest` [P] | OK |
| B5.11 | Product page setup steps | [S] `product-setup-steps.tsx:14-37`: Offers, Sub-product ICPs, Target accounts (optional), Generate signals, each with its real state; drawn on `product-detail-view.tsx:146` | n/a | `/portfolio/{key}` Overview | **none**: the Product page is three tabs with no content named [M view 4 note] | none stated | **GAP (M)** G-S2-9: put the four steps on the Product Overview |
| B5.12 | "Review opportunities" link | [S] header button to `/signals?product=` (`product-detail-view.tsx:109-110`), "Browse the full portfolio" in More (`:119`) | n/a | Product page | the owner's "no Signals buttons" ruling was about the list page [DECISIONS]; a per-product link is not mentioned | none stated | **DECISION (S)** DS-4: keep one plain link to Company Opportunities for that Product on the Product page, or drop it |
| B5.13 | Customer and buyer summary | [S] `product-profile-panel.tsx:32-34` (panel built from the Product's offers) | n/a | Product Overview | not named | none stated | **GAP (S)** G-S2-9 (same finding): Product Overview |
| B5.14 | Address `/portfolio/{key}` | [S] `app/portfolio/[product]/page.tsx` renders `ProductDetailView` | n/a | Portfolio | the new pages live at `/portfolio/products/{masterId}` (plan 3.5); P3.7 names only crm-id addresses; Market Signals and Ask links may carry a key | link-scan test | **GAP (S)** G-S2-10: redirect `/portfolio/{key}` to the Product page |
| B5.15 | ICP tab labels | [S] today's tabs are "B2B Customer Acquisition", "B2G ICP" and "B2B Partner Acquisition" (keys b2b, b2g, b2c, `product-form-dialog.tsx:443-446`) | n/a | dialog | [M view 3] says "(Individual, B2C, B2G) exactly as in today's Portfolio form": **wrong labels** | none stated | **GAP (S)** G-S2-11: fix the mock-up text and keep the real labels |
| B5.16 | Offers list on the Product page | [S] `ProductOffersList`, tab "Offers" with a note that offers are edited in the Product Portfolio (`product-detail-view.tsx:153-155`) | [V] 25 per organisation | Product page | the "Sub-products" tab [M view 4 note]; P3.5 | §10 | OK |
| B5.17 | ICP status badge | [S] `icp-status-badge.tsx` | n/a | list and offer cards | the "ICP" column (Active, None) [M view 2] | none stated | OK |

---

## F. Usage on dev (SELECT and SHOW only; every line run by me on 14 Nov, dev host 202.47.117.220)

All numbers are **[V]**. Tenant 6 is the only organisation with catalogue rows. The queries are in the appendix.

| # | Census line | Result | What it decides |
|---|---|---|---|
| F1 | Saved views for modules `products` and `services` (`crm_saved_views`) | **0 rows**, all modules | DS-2: nothing to migrate on dev; saved views are not used here |
| F2 | Custom field definitions and values for products or services (`tblcustom_fields`, `g2g_custom_field_values`) | 0 and 0 (table names matching product or service) | B2.6: the conditional tab is the right size |
| F3 | `products.category` picklist (`crm_picklist_values`) | 7 values, all active; no separate `services` rows (shared); the 9 live items use 8 distinct categories and only "Training" is in the list | DS-3 |
| F4 | Audit rows by event for entity `crm_products` (`g2g_audit_log`); product events in `g2g_event` | **1 row**, `crm.product.updated`; 0 rows for `g2g_product_offers` and `g2g_product_master`; no `product.*` or `portfolio.*` events | B2.3: the History tab will be almost empty; nothing logs create, delete, merge or import |
| F5 | Recycle Bin rows for products and services | 3 (2 products, 1 service), tenant 6; all three still flagged active; 3 quote lines (one each) depend on them | B1.13, G-S2-3 |
| F6 | Report definitions that mention products (`reports`) | 39 reports; 2 with `primary_module = crm.products`; 3 whose definition text contains "product"; the module key is `crm.products`, not `products` | E: reports read `crm_products`; not a screen question |
| F7 | Price-book items (`crm_price_book_items`) | 27 rows in 3 price books over 9 products | B2.4 |
| F8 | Bundle rows (`crm_product_bundle_items`) | 0 | B2.2: unused on dev |
| F9 | Quotes per product (`crm_quote_line_items`) | 33 lines over 6 products: 63, 65 and 70 (a service) 10 distinct quotes each; the binned 6, 7, 8 one each | B1.13, B2.4: a binned product must still show on an old quote |
| F10 | Imports | no product import log table: tables matching `%import%` are `g2g_signal_import_rejections` (19 rows, Signals), `hpbrain_import_jobs`, `hpbrain_import_logs`, `import_table_fields` (0 rows for `crm_product`); the CRM import writes no audit row | B1.9, B5.2: use cannot be measured |
| F11 | Document lines and other pointers (12 pointer tables) | quote 33, sales order 16 (3 products), invoice 16 (3), purchase order 0, opportunity products 20 (1 product), price-book 27, campaigns 3 (3 products), tickets 0, assets 0, FAQs 0, bundles 0 | the order of work: quotes, orders, invoices, price books and opportunities carry the real use |
| F12 | Portfolio features touched by a person | 50 offers; `is_user_edited` 0; `readiness_confirmed` 0; `g2g_offer_readiness_log` 0 | B5.3 to B5.5: the offer dialog and readiness confirmation have no use on dev; the offers came from an import or sync [I] |
| F13 | Pair review and claims | `g2g_product_match_decisions` 0; `g2g_product_claims` 0; `g2g_signal_products` 1 (status deleted) | none (new or unused) |
| F14 | Merge, bulk assign and bulk delete use | not measurable: no audit rows for them (F4); the 3 binned items show a delete happened by some path [I] | B1.7, B1.8, B1.12 |
| F15 | Catalogue item mix (`crm_products`) | tenant 6 only: live 7 products + 2 services; binned 2 products + 1 service; all active; 9 live with a Product line; 0 images; all 12 assigned; live items: price 9, cost 9, SKU 9, qty 7 | B1.1, B2.1, B2.7 |
| F16 | Master rows (`g2g_product_master`) | 5 shared Products (organisation 0, active); organisation 1: 25 Sub-products; organisation 6: 25 Sub-products and 9 items | B4.3 |

**Order of work from use:** the Sell side (Details, Usage, pickers, price books) carries all the real data (33 quote lines, 27 price-book items, 20 opportunity products), so it comes first. Bundles, custom fields, saved views, images, readiness confirmation and the pair-review decisions have no dev usage: nothing is dropped for that, they are built after the list and the pages.

---

## Gaps and decisions (sized; plan step to add it to)

### Gaps: 11 distinct findings behind the 14 GAP rows

| ID | Rows | What is missing | Recommended home | Size | Add to |
|---|---|---|---|---|---|
| G-S2-9 | B5.11, B5.13 | The Product page: setup steps (Offers, Sub-product ICPs, Target accounts, Generate signals) and the Customer and buyer summary | Product Overview tab | M | P3.5 |
| G-S2-1 | B1.7, B1.8 | Bulk select, reassign, delete | checkbox column and the shared `CrmBulkActionBar` in the list; the delete is "Delete from catalogue" (D-10) | S | P3.3 |
| G-S2-3 | B1.13 | Recycle Bin entry | More menu (and keep the admin guard of slice 1 G-1) | S | P3.3 |
| G-S2-7 | B4.2 | Status filter (Active, Archived, All) | list filter, default Active | S | P3.3 |
| G-S2-4 | B2.1, B3.4 | Details tab shows 8 of 15 old read fields; no Service rules | build from the 15 read fields; hide SKU, Vendor, Stock, Demand, Reorder, Weight for a Service | S | P3.4, P3.5 |
| G-S2-2 | B1.1 | Category and Qty In Stock columns | list columns, or state that they move to the page | S | P3.3 |
| G-S2-8 | B4.3 | Shared Products (5 on dev) are read-only | Shared badge, hide Edit, Archive, Restore | S | P3.3, P3.5 |
| G-S2-6 | B3.5 | mock-up view 3 still has "Sold through CRM" and "Active" | one switch "Offered on new documents" in the form too | S | mock-up, P3.4 |
| G-S2-5 | B2.7 | Image on the page | header picture with replace; `imageUrl` in the Sell read | S | P2-C (owed), P3.5 |
| G-S2-10 | B5.14 | `/portfolio/{key}` addresses | redirect to the Product page | S | P3.7 |
| G-S2-11 | B5.15 | mock-up view 3 names the ICP tabs wrongly | write the real labels | S | mock-up |

### Decisions: 4

| ID | Row | Question | My recommendation |
|---|---|---|---|
| DS-1 | B1.4 | Sort in the new list | Groups ordered by Product name, Sub-products by name; keep a sort on name and price inside the group only if the owner asks. The old sort has no recorded use (0 saved views). |
| DS-2 | B1.6 (D-7) | Saved views | `crm_saved_views` has 0 rows on dev (F1). Leave them out of P3.3, keep the route and table, add them after the soak if wanted. Live was not checked (not allowed here), so count live before removing anything. |
| DS-3 | B1.16 | Category: picklist or free text | 8 of 9 stored categories are not in the 7-value list. Use free text with the list as suggestions, or backfill the list from the stored values; keep the master-fields admin screen as it is. |
| DS-4 | B5.12 | "Review opportunities" link on the Product page | Keep one plain link to Company Opportunities for that Product (the owner's ruling removed the three Signals buttons from the list page, not this link). |

## What in the plan or the mock-up is wrong (checked on 14 Nov)

1. Plan section 2, "a catalogue item has tabs Details, Bundle, History (+ Signals & ICP and Usage since 3 Nov)": **true but incomplete**; Bundle exists only for products, and Custom Fields is a fifth conditional tab [S `product-lines-model.ts:140-149`]. "The Portfolio's own product page has only Overview + Offers": **true** [S `product-detail-view.tsx:22-25`].
2. Plan section 4 lists "Status" among the list filters; **the mock-up bar has none** (G-S2-7). The old Catalogue list had **no filters at all** [S].
3. Plan section 4 "bulk actions ... carried over": **no control for it in the mock-up** (G-S2-1).
4. Mock-up view 3: the ICP tabs are named "(Individual, B2C, B2G) exactly as in today's Portfolio form"; today's labels are different (G-S2-11); it still shows two switches (G-S2-6).
5. Slice 1 C1-5 said `crmService.deleteProduct` has no caller: **confirmed**, the detail page has no delete control at all (B2.9).
6. The plan says the Usage and History tabs "had no route behind them" and folded that into P2-E1: consistent with what I read (B2.3, B2.4).

## Could not verify / INCOMPLETE

- No browser: nothing was seen rendered; the mock-up was read as HTML text.
- The files listed as not opened in section 0, above all `product-master-form.tsx` (B4.7 INCOMPLETE), the Portfolio detail dialog body and the ICP section's fields.
- Live: not touched, so saved views, custom fields and the Bin on the live host are unknown (DS-2).
- How the shared `Select` shows a value that is not in its options (DS-3).
- No usage can be measured for import, export, duplicates, merge and bulk actions (F10, F14): the CRM writes no audit row for them.

## Appendix: the dev queries (SELECT and SHOW only, connection `mysql`)

1. `SHOW COLUMNS FROM crm_saved_views`; `SELECT module, COUNT(*) FROM crm_saved_views GROUP BY module` (empty).
2. `SHOW COLUMNS` of `tblcustom_fields`, `g2g_custom_field_values`, `crm_picklist_values`, `crm_products`, `g2g_audit_log`, `reports`; `SHOW TABLES LIKE` `%audit%`, `%import%`, `%report%`.
3. `g2g_audit_log` by `entity_type` for `crm_products`, `g2g_product_offers`, `g2g_product_master` and by `type` for `crm_products`; `tblcustom_fields` and `g2g_custom_field_values` filtered on `%product%` or `%service%`; `crm_picklist_values` for modules `products` and `services`, with labels; `crm_products` grouped by `item_type`, organisation and deleted or not, with counts of active, line, image and assigned, and the categories in use.
4. `reports` by `primary_module`, and counts whose `definition` contains "product".
5. For each of the 12 pointer tables of `ProductPointers::toCrmProduct()`: rows and distinct products where the pointer is not null.
6. Quotes per product: `crm_quote_line_items` joined to `crm_products`, grouped by product with distinct quotes; `crm_price_book_items` books and items; `g2g_product_offers` count, `is_user_edited`, `readiness_confirmed`; row counts of `g2g_offer_readiness_log`, `g2g_product_match_decisions`, `g2g_product_claims`, `g2g_signal_products`.
7. `g2g_event` for types like `crm.product%`, `product.%`, `portfolio.%`; `g2g_signal_import_rejections` count; `import_table_fields` for `crm_product`.
8. `g2g_product_master` by organisation, kind and status; counts of non-null price, cost, SKU and quantity on live `crm_products`.
