# Products parity audit, slice 1: fields (A), endpoints (C) and the permanent guard (H)

Track S15-AUD, slice 1 of the audit named in `Docs/agentic-crm/briefs/S15-AUD-products-parity-audit.md`. Other auditors do B, D, E, F. Read only: no file of either repository was written, and this is the only file added (scratch lists went to the session scratchpad, outside both repos). 13 Nov 2026.

**Status: complete for slice 1 (sections A1 to A4, C and H). The summary in section 1 is the place to start.**

## 0. What was read, and how claims are tagged

| Item | Value |
|---|---|
| hp_erp | branch `Milan-2`, HEAD `ccfe17ce` (`docs(agentic-crm): progress after P1-C ...`). The working tree is NOT clean: other tracks (P2-C writer, P2-D pair review) have uncommitted files. I read them as they stood on disk and say so where it matters (`CatalogueFields.php`, `ProductWriter.php`, `CrmProductController.php`, `routes/product_master.php`, `ProductMasterController.php`, `CatalogueFieldsScanTest.php` are all uncommitted and may change under this audit). |
| g2gv0 | branch `milan1`, HEAD `3d8680b8` (`refactor(signals): the old Signal Review screen is deleted ...`), tree clean. |
| Database | dev only, connection `mysql`, host 202.47.117.220, SELECT / SHOW / information_schema only. Tenant 6 is the only tenant with catalogue rows (12 rows: 9 live, 3 in the Recycle Bin). Live was never touched. |
| Routes | `php artisan route:list --json` on the working tree (the P2-C and P2-D routes are in it). |
| Built from | the migrations, `information_schema`, the controllers, the routes and the components first; the plan (`slices/S15-product-portfolio-merge.md`, `DECISIONS.md` 2026-11-13) only afterwards, to compare. |

Tags: **[V]** verified (I ran it or counted it), **[S]** read in source (file:line), **[I]** inferred.

Abbreviations used in the tables:
- **W-CRM** = `CrmProductController` `store()` (:175-250), `update()` (:252-332), `import()` (:722-800), all through `payload()` (:943-947) and `CatalogueFields::payload` (`CatalogueFields.php:126-162`).
- **W-PW** = `ProductWriter::insertCatalogue` and `ProductWriter::applySell`, through `CatalogueFields::fromSell` (`CatalogueFields.php:196-214`). `ProductWriter.php` is cited by METHOD, not by line: P2-C was still editing it while this audit ran (`insertCatalogue` moved from line 739 to 751 between two reads).
- **W-SYNC** = `ProductMasterSync::mirror` (`ProductMasterSync.php:468-481`, writes `name` and `product_master_id` of the catalogue row).
- **R-RES** = `CrmProductController::resource` (:950-980), the API shape of `GET /api/crm/products`. **R-SELL** = `CatalogueFields::toSell` (`CatalogueFields.php:232-247`) via `ProductMasterController::sellFor` (:365-367), the Sell block of the one-record read.
- **P2.4 / P2.5 / P3.4 / P3.5 / P3.7** = plan steps (`S15-product-portfolio-merge.md` section 5): writer v2, read API, three-section form, Sub-product page, redirects.

## 1. Summary

Counts are rows of the tables below (a finding that appears in two sections is counted in both; the distinct findings are listed at the end as G-1 to G-8 and D-1 to D-12).

| Section | Items | OK | GAP | DECISION |
|---|---|---|---|---|
| A1 columns of `crm_products` | 31 | 26 | 3 | 2 |
| A2 forms, imports, exports, API shapes, types, pickers | 41 | 35 | 3 | 3 |
| A3 Portfolio side (offers, ICP, Signals products, master rows) | 39 | 36 | 1 | 2 |
| A4 related records (bundle items, price books, custom fields, image) | 7 | 6 | 0 | 1 |
| **A total** | **118** | **103** | **7** | **8** |
| C endpoints (16 `crm/products`, 12 `product-master`, 12 `portfolio`, 4 `signals`, 7 generic, 4 planned) | 55 | 36 | 13 | 6 |
| H permanent guards specified (H1 field guard, H2 route guard) | 2 | 2 | 0 | 0 |
| **All three sections** | **175** | **141** | **20** | **14** |

The 20 GAP rows are **8 distinct findings**; the 14 DECISION rows are 9 distinct decisions (3 more are listed with no table row).

**What parity holds up under:** all 31 columns of `crm_products` match the migrations and each has a home (26 clean); all 29 offer columns have a home; all 16 `crm/products` routes, 12 `product-master` routes and 12 `portfolio` routes are accounted for; the writer, the pointer registry and the pair review (uncommitted on disk) already carry the Sell and About fields.

**Top gaps (one line each; detail and size at the end):**
1. G-1 (M) The Recycle Bin's "Delete permanently" can still hard-delete a catalogue item that 11 tables point at (cascade or null); the plan's "the CRM row is never deleted" is true only of the pair merge. 3 quote lines on dev depend on the 3 binned items.
2. G-2 (M) The History tab has no route: the writer files audit lines under 3 entity types, the one history route reads 1, and an About-only record has no CRM id.
3. G-3 (M) `GET /product-master/{id}/usage` returns signals, ICP, price books and quotes only; the mock-up Usage tab promises orders and invoices.
4. G-4 (S) The approved mock-up's About card omits six fields of today's Portfolio form; the form must be built from the 13 writer keys, not the picture.
5. G-5 (S) No "Add product" (level 1) entry exists in the mock-up or the list design, though the dialog exists today and the writer supports it.
6. G-6 (S) `CatalogueFields::limits()` (uncommitted) allows 255 characters for `glAccount` and `usageUnit`, the columns hold 191: a 500 instead of a 422; no range checks on the decimals.
7. G-7 (S) The Sell read (`toSell`) has no `imageUrl`.
8. G-8 (S each) Nine old routes have no test that names them (bundle items x3, image upload, export, duplicates, bulk assign, readiness confirm and log); P3 retargets their callers.

**Wrong or overstated in the plan** (detail at the end): "bundles with is renamed Often sold together" (no screen has it today, so it is new); the mock-up About card is "from today's Portfolio form" but is missing six of its fields; "ProductLines::create is the one place a Product is written" (the Signals create route still uses `ProductCatalog::createCustom`); "CRM row never deleted" (not true of the Recycle Bin); the mock-up promises a Usage and History the code cannot serve; the plan does not mention that the `icp_config` migration is not applied on dev (the code guards it, so nothing breaks). **Checked and true:** `GET /api/product-master/lines` exists, the Usage tab does read quotes, the "Sync Excel" button exists.

**Could not verify:** anything on the live host; whether the uncommitted tests pass (no PHPUnit run: it writes cache files); URLs built from variables; any screen in a browser.

---

## A. Fields

### A1. Every column of `crm_products` (31 on dev = 31 in the migrations)

Sources compared [V]: `information_schema.COLUMNS` on dev gives 31 columns; the migrations give the same 31: `2026_11_24_080200_create_crm_products_table.php` (25: id, legacy_id, item_type, product_no, name, sku, category, description, unit_price, cost_price, currency, tax_rate_id, is_active, vendor, qty_in_stock, reorder_level, weight, assigned_to, created_by, updated_by, deleted_by, sub_institute_id, created_at, updated_at, deleted_at), `..._081400_add_phase6_fields...` (commission_rate, usage_unit, qty_in_demand, gl_account), `..._081600_add_image...` (image), `2026_12_11_160000_add_product_master_id_to_crm_products.php` (product_master_id). **No column exists on one side only.** Types differ from the migration text in one way only: `$table->string('gl_account')` and `('usage_unit')` have no length, and `Schema::defaultStringLength(191)` (`app/Providers/AppServiceProvider.php:43`) makes them `varchar(191)` on dev [V]; they could be 255 on a host built before that line (unverified, live not read).

"Used on dev" counts the 9 live rows of tenant 6 (7 products, 2 services) [V].

| # | Column (dev type) | Evidence: writers and readers | Used on dev | Old home (label) | NEW HOME | Delivered by / proof | Status |
|---|---|---|---|---|---|---|---|
| 1 | `id` bigint PK | W: database. R: the 12 pointer columns (`ProductPointers::toCrmProduct`), `g2g_product_master.crm_product_id`, R-RES :955 `id`, R-SELL `crmProductId`, every picker value | 9 | URL `/module/crm/sales/products/{id}` | system; address `/by-crm/{id}` | P2.5 `/by-crm/{id}` (not built yet, see C), P3.7 redirects; `ProductPointersScanTest` | OK |
| 2 | `legacy_id` bigint NULL, indexed | No writer and no reader found: `grep legacy_id app/ routes/ resources/` hits only OTHER CRM tables; not in R-RES, export, TS type [V] | 0 | none | system allow-list (`CatalogueFields::systemColumns`, :71) | P2.4; `CatalogueFieldsScanTest` | OK (not carried, reason written) |
| 3 | `item_type` enum(product,service) NOT NULL | W-CRM :230 (never changes: :317 `unset`), W-PW `insertCatalogue`. R-RES :955; per-type right `TYPE_LINKS` :60-63; Recycle Bin type filter `CrmRecycleBinController.php:42-43`; picker hint `quote-line-item-table.tsx:79` | 7 product, 2 service | which screen (Products / Services) and `itemType` request key | Sell `type` Product or Service; immutable (`ProductWriter::typeOf` refuses a change); "Services" becomes a Type filter | P2.4 done on disk, P3.3 list filter; `ProductWriterV2Test` | OK |
| 4 | `product_no` varchar(100) NOT NULL, UNIQUE (global, not per tenant) | W: `CatalogueFields::newProductNo` (:183-186) from :231, :779 and writer `insertCatalogue`. R-RES :955; header subtitle `product-detail-page.tsx:246`; list search (:102 and `product-catalog-list-view.tsx:214`); duplicates sub-label (:302); R-SELL `productNo`. NOT in `EXPORT_COLUMNS` (:66-73) | 9 | "Item number" / page subtitle | system, read-only on Details | P3.5 | OK |
| 5 | `name` varchar(191) NOT NULL | W-CRM (rule: required, at most 191 characters, `CatalogueFields::rules` :93), W-SYNC (rename mirrored in one transaction), the record's own name in W-PW. R: list, pickers (label), duplicates `LOWER(TRIM(name))` (:462), export, `ProductMatchSuggester`, `QuoteBuilder:37-47` | 9 | "Product Name *" / "Service Name *" (`create-product-modal.tsx:161`) | the record's one Name (master row owns it; offer and CRM rows mirror) | P2.4, `ProductMirrorTest` (committed, P2-B2) | OK |
| 6 | `sku` varchar(100) | W-CRM, W-PW. R-RES, list search (:101), duplicates "Same SKU" (:463), export, import header `sku`, Details (products only, `product-detail-page.tsx:276`), pair suggester (SKU vs offer id) | 9 | "Catalogue SKU" (shown for products only, `create-product-modal.tsx:210`) | Sell `sku` (`limits()` `max:100` = column) | P2.4 / P3.4 | OK |
| 7 | `category` varchar(100) | W-CRM, W-PW. Picklist `products.category` (`CrmPicklistController.php:55`), list sort and a `category` request filter with no UI (:106-108), report field `crm.products` (`CrmReportModules.php:367`) | 9 (8 distinct) | "Category" select | Sell `category` (picklist stays) | P2.4 / P3.4. Wording trap: the OLD Portfolio form calls the product GROUP "Category" (`product-form-dialog.tsx:341`, `parent_product`); the unified form must say Product there | OK |
| 8 | `gl_account` varchar(191) | W-CRM, W-PW. R-RES :959, Details, S1 read `product-line-record-page.tsx:366`. **`CatalogueFields::limits()` says `max:255` (as read, uncommitted) against a 191-character column [V]**: a 192-255 character value passes the writer's check and then fails in the database (strict mode is on: `STRICT_TRANS_TABLES` [V]) as a 500, not a 422. The CRM controller never validated it either | 9 | "GL Account" | Sell `glAccount` | P2.4: set `max:191` | **GAP (S)** |
| 9 | `description` text | W-CRM, W-PW (alias `descriptionOnQuotes`, `CatalogueFields.php:53`). R-RES, Details "Description" card (:312-314), pair suggester | 9 | "Description" | Sell `descriptionOnQuotes` ("Description on quotes"); the offer's `what_it_does` stays a separate field (plan default c) | P2.4 / P3.4 | OK |
| 10 | `unit_price` decimal(15,2) | W-CRM (rule: a number or empty), W-PW (alias `basePrice` for the S1 drawer). R-RES, list + sort (:110), line pickers copy it (`quote-line-item-table.tsx:99`, `sales-order-line-item-table.tsx:94`, `purchase-order-line-item-table.tsx:85`), price-book base price (`CrmPriceBookController.php:278`), `QuoteBuilder:37-47`, bundle total, report | 9 | "Unit Price" | Sell `unitPrice` (`basePrice` kept as alias) | P2.4 / P3.4; mock-up view 4 captions the value "(from the price book)": in code it is the catalogue's own base price and the price book only overrides it on a quote (`quote-line-item-table.tsx:95-99`); the S1 page already says "Base price" (`product-line-record-page.tsx:367`) | OK (wording note for P3.5) |
| 11 | `cost_price` decimal(15,2) | W-CRM, W-PW. R-RES, R-SELL (gated by `ProductAccess::canViewCatalogue`), report field `crm.products` (`CrmReportModules.php:373`) so the report builder is another reader to gate; history diff leaked it until P2-B1 D4 | 9 | "Cost Price" | Sell `costPrice`, Products/Services right only (HR never) | P2.4 `ProductWriterV2Test` (HR gets no Sell block), P3 per-role browser run | OK |
| 12 | `commission_rate` decimal(5,2) | W-CRM (rule: a number from 0 to 100), W-PW. R-RES :963, Details; not in the report module | 0 | "Commission Rate %" | Sell `commissionRate`, same gate as cost | P2.4 | OK |
| 13 | `currency` varchar(10) | W-CRM, W-PW (`max:10`). R: Details, list price, `QuoteBuilder` (mixed-currency check :14) | 9 (1 value) | "Currency" free text ("USD or INR") | Sell `currency` | P2.4 | OK |
| 14 | `tax_rate_id` bigint, FK to `crm_tax_rates`, `nullOnDelete` | W-CRM (`rejectUnknownTaxRate` :808-820), W-PW (`ProductWriter::checkTaxRate`). R-RES :965 plus joined `taxRateName`, line pickers copy it (`quote-line-item-table.tsx:100`), `QuoteBuilder` | 9 (1 value) | "Default Tax Rate" select | Sell `taxRateId`; the name comes from `meta.taxRates` | P2.4 / P3.4 | OK |
| 15 | `is_active` tinyint(1) default 1 | W-CRM (null on create becomes true, `CatalogueFields.php:147-149`), W-PW. R: list "Active" column (`product-catalog-list-view.tsx:268`), Details Status, **`QuoteBuilder:37-47` refuses an inactive item** [S]; the three line-item pickers do NOT filter on it [S: no `isActive` in them] | 9 active | "Active" switch | Sell `isActive` AND `enabled=false` (Paused) both write `is_active = 0` (plan P2-C rule 2); the mock-up shows both a "Sold through CRM" switch and an "Active" switch | P2.4, P3.4 | **DECISION (S)**: two controls for one stored state; keep one or define the difference |
| 16 | `vendor` varchar(191) | W-CRM, W-PW; nulled for a service (`CatalogueFields::PRODUCT_ONLY`, :45). Free text, NOT a link to the Vendors module (`crm_vendors`) | 0 | "Vendor" (products only) | Sell `vendor` | P2.4 | OK |
| 17 | `qty_in_stock` decimal(15,2) | W-CRM, W-PW, nulled for a service. R: list column and sort (`product-catalog-list-view.tsx:237,267`; sortable :110), Details, import header `qty in stock`, report | 7 | "Qty In Stock" | Sell `qtyInStock` | P2.4 / P3.3 | OK |
| 18 | `qty_in_demand` decimal(12,2) | W-CRM, W-PW, nulled for a service. R: Details; in the export, not in the import template | 0 | "Qty In Demand" | Sell `qtyInDemand` | P2.4 | OK |
| 19 | `reorder_level` int unsigned | W-CRM, W-PW, nulled for a service. R: Details, import header `reorder level`, report | 7 | "Reorder Level" | Sell `reorderLevel` | P2.4 | OK |
| 20 | `weight` decimal(10,3) | W-CRM, W-PW (NOT nulled for a service; the old form just hides it, `create-product-modal.tsx:296`). R: Details (products only), export, not in the import map | 0 | "Weight (kg)" | Sell `weight` | P2.4 | OK |
| 21 | `image` varchar(191) (file name only) | W: `CrmProductController::uploadImage` (:642-686) stores `public/hp_crm_product/{id}_{random}.{ext}` on the `digitalocean` disk and sets `image`, `updated_by`; no audit line, no mirror. R: `imageUrl()` (:868-881) and `product-detail-page.tsx:219-233` (header picture and camera chip). **R-SELL has no image URL** (`CatalogueFields.php:239-246`) [S]; the plan says image is a side call keyed by the CRM id | 0 | the header picture on the product page | Sell image, kept as the separate upload call (`CatalogueFields::systemColumns` :80) | P2.5 (`/{id}/sell` must return `imageUrl`) and P3.5 | **GAP (S)**: add `imageUrl` to the Sell read |
| 22 | `usage_unit` varchar(191) | W-CRM, W-PW. R: Details. **`limits()` `max:255` against 191** [V] (same defect as `gl_account`) | 9 | "Usage Unit" | Sell `usageUnit` | P2.4: set `max:191` | **GAP (S)** (same fix as row 8) |
| 23 | `assigned_to` bigint, indexed | W-CRM: REQUIRED (`CatalogueFields::rules` :94, CSV import row rule `CrmProductController.php:756`); W-PW: DEFAULTS to the caller when absent (`ProductWriter::insertCatalogue`); bulk reassign `bulkAssign` (:404-436). R: report assignee join, export shows the user id not a name | 9 (1 person) | "Assigned To *" (required; `create-product-modal.tsx:100`) | Sell `assignedTo` | P2.4 / P3.4 | **DECISION (S)**: the two doors disagree (required vs defaulted) |
| 24 | `created_by` | W: store :233, import :781, writer `insertCatalogue`. R-RES :976 | 9 | (shown nowhere) | system | none needed | OK |
| 25 | `updated_by` | W: update :323, uploadImage :680, writer `applySell`. Never set on dev | 0 | none | system | none needed | OK |
| 26 | `deleted_by` | W: destroy :354, `bulkDeleteRows`; cleared by the Recycle Bin restore | 3 of 3 binned | none | system | none needed | OK |
| 27 | `sub_institute_id` NOT NULL | every read and write is scoped by it (`ownRow` :859-866); taken from the token, never the body | 9 | none | system | none needed | OK |
| 28 | `created_at` | W: :234, writer `insertCatalogue`. Sort key `created_at` exists in the type and in `sortableColumns` (:110) but no column header offers it | 9 | none | system | none needed | OK |
| 29 | `updated_at` | W: every write | 9 | none | system | none needed | OK |
| 30 | `deleted_at` | the Recycle Bin mark: `destroy`, `bulkDelete`, `CrmRecycleBinController::restore` (:107-148, the update is :140) and `forceDelete` (:150-); every read has `whereNull('deleted_at')`. State `binned` in `CatalogueFields::state` (:217-224) | 3 binned | Recycle Bin screen | state "In the Recycle Bin"; Sell switched on again restores the SAME row (plan rule 2) | P2.4 `ProductWriterV2Test` | OK (see C-14 for the permanent delete) |
| 31 | `product_master_id` bigint NULL, index `crm_products_tenant_product` | W-CRM when sent (`store` :225-229, `update` :314-316), W-SYNC, W-PW (`insertCatalogue`, from the record's Product). R-RES :979 `productMasterId`; form seed (`create-product-modal.tsx:89`); pair merger keeps it (it means the Product, never repointed) | 9 (all under Product 1) | "Product Line *" select (products only; `+ Create New Line`, admin only since P2-B1) | the Product (level 1) select at the top of the form | P2.4 / P3.4; checker invariant I1 | OK |

Counts A1: **31 items, 26 OK, 3 GAP (rows 8, 21, 22), 2 DECISION (rows 15, 23).** [V]

**A1 appendix: every other file that names a column.** Method [V]: a word grep of each snake_case name over the 38 hp_erp files (`app`, `database/seeders`, `config`, `routes`) that mention `crm_products`, `CatalogueFields` or `CrmProduct`, and of each camelCase name over the 57 non-test g2gv0 files that mention `CrmProduct`, the CRM product service calls or `product-master`; generic words (`description`, `weight`, `category`) also hit files that mean another table, and I left those out by hand. `CatalogueFields.php` and `CrmProductController.php` name every column and are not repeated; in g2gv0 `types/crm.ts`, `create-product-modal.tsx` and `product-detail-page.tsx` name nearly every field and are not repeated. The 12 pointer tables and the Ask, GTM and Signals readers belong to auditor E.

| Column | Other hp_erp files | Other g2gv0 files |
|---|---|---|
| `sku` | `ProductMasterController` (`crmProductFor`), `ProductMatchSuggester`, `CrmReportModules` (`crm.products`), `Tenant6CrmLifecycleSeeder` | `product-catalog-list-view` (import header), `product-line-record-page`, `product-master-form` and `-model`, `services/product-master/types` |
| `category` | `CrmPicklistController`, `ProductMatchSuggester`, `CrmReportModules`, the seeder | `product-catalog-list-view`, `product-lines-model`, `app/module/crm/sales/products/[id]/page.tsx` and `.../services/[id]/page.tsx` (picklist) |
| `gl_account` | `ProductMasterController`, the seeder | `product-line-record-page`, `product-master-form` and `-model`, `services/product-master/types` |
| `description` | `ProductMatchSuggester`, the seeder | the product pages only |
| `unit_price` | `ProductMasterController` (`crmProductFor`, `usage`), `CrmPriceBookController:275-278`, `QuoteBuilder:37-47`, `ProductMatchSuggester`, `CrmReportModules`, the seeder | the three line tables (`quote-`, `sales-order-`, `purchase-order-line-item-table`) and the quote, sales order, purchase order, invoice and price-book detail pages, `product-catalog-list-view`, `product-line-record-page`, `product-master-model` |
| `cost_price` | `CrmReportModules`, the seeder | `product-catalog-list-view` (import header) |
| `commission_rate`, `qty_in_demand`, `weight`, `image` | none | none (`image` is read as `imageUrl` by `product-detail-page` only) |
| `currency` | `ProductMasterController`, `QuoteBuilder`, `ProductMatchSuggester`, the seeder | the line tables and detail pages, `product-usage-tab`, `product-master-form` and `-model` |
| `tax_rate_id` | `ProductMasterController`, `QuoteBuilder`, the seeder | the line tables and detail pages, `product-line-record-page`, `product-master-form` and `-model`, `services/product-master` |
| `is_active` | `ProductMasterController`, `ProductWriter::applySell`, `QuoteBuilder`, `CrmReportModules`, the seeder | `product-catalog-list-view` (the Active column) |
| `vendor`, `qty_in_stock`, `reorder_level` | `CrmReportModules` (and `SystemReportSeeds` for `qty_in_stock`: the "Products by Category" system report), the seeder | `product-catalog-list-view` (import headers; `qtyInStock` also the column and the sort) |
| `usage_unit` | the seeder | none |
| `assigned_to` | `ProductWriter`, `CrmReportModules` (assignee join), `HasCrmBulkActions` (bulk assign), the seeder | `product-catalog-list-view` (bulk reassign, import header) |
| `product_no` | `ProductMasterController`, `ProductMasterSync`, `ProductMasterBackfill`, `ProductMatchSuggester`, `CrmReportModules` | `product-catalog-list-view`, `product-line-record-page` |
| `item_type` | `ListProductsTool`, `ProductLinks`, `CrmOpportunityController:674-684`, `CrmPriceBookController:275-278`, `CrmRecycleBinController` (`TYPES`), `CrmReportModules`, `ProductMatchSuggester` | the line tables (picker hint), `opportunity-detail-page`, `price-book-detail-page`, `product-lines-model`, `product-list-view` |
| `product_master_id` | the pointer registry (`ProductPointers`) and every class of the P2 tracks | `create-lead-modal`, `company-opportunities` (Signals), `lib/opportunities/model` |
| `legacy_id`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`, `sub_institute_id`, `id` | system columns, see the rows above | none |

### A2. Every surface that shows or sends a catalogue field

**A2.1 The crosswalk, field by surface** (Y = yes, p = products only, hidden for a service, r = required, `-` = absent). Sources: modal `components/domain/crm/create-product-modal.tsx`; Details `product-detail-page.tsx:272-316`; list `product-catalog-list-view.tsx:224-271`; CSV in = `IMPORT_HEADER_MAP` (`product-catalog-list-view.tsx:29-33`) plus the server row rule (`CrmProductController.php:754-759`); CSV out = `EXPORT_COLUMNS` (:66-73); API = R-RES; TS = `CrmProduct` / `CrmProductPayload` (`types/crm.ts:864-927`); S1 drawer = `product-master-form.tsx` + `SellForm` (`product-master-model.ts:12-20`); Writer = P2-C contract (`CatalogueFields.php`, as read).

| Field (request key) | Modal | Details | List | CSV in | CSV out | API | TS | S1 drawer | Writer Sell | Status |
|---|---|---|---|---|---|---|---|---|---|---|
| `name` | Y r | title | Y | Y r | Y | Y | Y | Y r | the record's own | OK |
| `productMasterId` (Product line) | p, r when lines exist (:115) | - | extra columns when lines exist | row rule only, no header | - | Y | Y | Y (`productId`) | the record's own (`productId`) | OK |
| `sku` | p | p | search only | Y | Y | Y | Y | Y | Y | OK |
| `category` | Y | Y | Y | Y | Y | Y | Y | - | Y | OK |
| `glAccount` | Y | Y | - | **-** | Y | Y | Y | Y | Y | OK |
| `description` | Y | Y | - | Y | Y | Y | Y | - | Y (alias `descriptionOnQuotes`) | OK |
| `unitPrice` | Y | Y | Y | Y | Y | Y | Y | Y (as `basePrice`) | Y | OK |
| `costPrice` | Y | Y | - | Y | Y | Y | Y | - | Y (gated) | OK |
| `commissionRate` | Y | Y | - | **-** | Y | Y | Y | - | Y (gated) | OK |
| `currency` | Y | Y | with price | Y | Y | Y | Y | Y | Y | OK |
| `taxRateId` | Y | Y (name) | - | **-** | **-** | Y (+name) | Y | Y | Y | OK |
| `isActive` | Y | Y | Y | **-** | Y | Y | Y | **-** (the drawer has only its "Sell this" switch) | Y | DECISION (A1 row 15) |
| `vendor` | p | p | - | Y | Y | Y | Y | - | Y | OK |
| `qtyInStock` | p | p | p | Y | Y | Y | Y | - | Y | OK |
| `qtyInDemand` | p | p | - | **-** | Y | Y | Y | - | Y | OK |
| `reorderLevel` | p | p | - | Y | Y | Y | Y | - | Y | OK |
| `weight` | p | p | - | **-** | Y | Y | Y | - | Y | OK |
| `usageUnit` | Y | Y | - | **-** | Y | Y | Y | - | Y | OK |
| `assignedTo` | Y r | **- (never shown after save)** | - | Y r | Y (user id) | Y | Y | - | Y (defaults to the caller) | DECISION (A1 row 23) |
| `itemType` | the screen | header word | the screen | request key | file name | Y | Y | Y (`sell.type`) | Y (`type`) | OK |
| `productNo` | - | header | search | - | **-** | Y | Y | - | read only (`toSell`) | OK |
| `image` / `imageUrl` | - (upload chip on the page, `:226-241`) | header picture | - | - | - | Y (`imageUrl`) | Y | - | **not in the Sell read** | GAP (A1 row 21) |
| `taxRateName` | - | Y | - | - | - | Y (joined) | Y | - | no (id only) | OK (read from `meta.taxRates`) |
| `createdBy`, `createdAt`, `updatedAt` | - | - | `createdAt` sort only | - | - | Y | Y | - | `toSell` omits them | OK |
| `masterId`, `masterKind`, `masterStatus`, `productLine`, `offerId`, `signalsFound`, `icp` (read-only link fields added to every item by `ProductLinks::forCatalogueItems`, `CrmProductController.php:133, :172`; `ProductLinks::NO_LINK` when the item has no record behind it) | `productLine.id` seeds the Product select (`create-product-modal.tsx:89`) | `masterId` and `masterKind` decide the tabs (`detailTabs`, `product-detail-page.tsx:139`) | the Product line, Signals and ICP columns when any item is linked (`LineColumnCells`) | - | - | Y | Y (optional) | - | not needed: the record IS the link | OK (they become the list's own columns) |

Reading the crosswalk [V from the files above]: the old CSV template carries 11 headers (Name, SKU, Category, Unit Price, Cost Price, Currency, Vendor, Qty In Stock, Reorder Level, Description, Assigned To) and the export writes 17, so **an exported file cannot be imported back whole**: the 8 server-accepted fields the template lacks are glAccount, commissionRate, taxRateId, isActive, qtyInDemand, weight, usageUnit, productMasterId. The export also leaves out the item number, the tax rate, the picture and the Product. This is the old behaviour, not a merge loss, and the plan keeps both buttons under More; it is listed in the decisions (D-6).

**A2.2 The forms and dialogs that write product fields, and where each goes**

| # | Form | File | Writes through | Fields it sends | NEW HOME | Delivered by | Status |
|---|---|---|---|---|---|---|---|
| 1 | CRM Products and Services modal | `create-product-modal.tsx` | `POST /api/crm/products`, `PUT /api/crm/products/{id}` (`crmService.createProduct/updateProduct`, `services/crm/index.ts:531-535`) | the 19 keys of `CatalogueFields::MAP` plus `productLineName` (new line by name) and `itemType` | the "Sell in CRM" section of the one form | P3.4 | OK |
| 2 | S1 Product Master drawer | `product-master-form.tsx` + `product-master-model.ts` | `POST/PUT /api/product-master` | kind, name, key (Product only), productId, offerId, readiness, about.whatItDoes, about.needsSolved, and 6 Sell fields (type, sku, glAccount, taxRateId, basePrice, currency) | the one form (it already speaks the writer's contract) | P3.4 | OK |
| 3 | Portfolio product form | `product-form-dialog.tsx` + `lib/product-form.ts` (:22-67, :195-215) | `POST/PUT /api/portfolio/offers` | offer_id (create only), name, parent_product, readiness_status, what_it_does, modules_components, primary_segments, needs_solved (free text lines plus kept legacy N-codes), trigger_signals, proof_points, pricing_model, typical_deal_band, deployment_model, implementation_effort (in the payload, **no input in the dialog**), delivery_owner, notes, plus `icp_configs` | the About and Ideal customer sections | P3.4 | **GAP (S)**, see A3.2 (the mock-up About card) |
| 4 | New product (level 1) dialog | `components/domain/portfolio/new-product-dialog.tsx` | `POST /api/signals/icp/products` (`services/signals/product-icp.ts:132`) | name (`label`), key | admin-only "Add product" (writer `kind=product`, `ProductLines::create`) | P3.4 | **GAP (S)**: view 2 and view 3 of the approved mock-up have only "Add sub-product"; nothing offers "Add product" |
| 5 | Add bundle component | `product-detail-page.tsx:49-108` | `POST /api/crm/products/{id}/bundle-items` | componentProductId, quantity (`min 0.01`) | Bundle tab | P3.5 | OK |
| 6 | Image upload chip | `product-detail-page.tsx:181-194, :226-241` | `POST /api/crm/products/{id}/image` | one image file (`image`, max 5120 KB) | the Sell section / page header, side call | P3.5 | OK (needs `imageUrl`, A1 row 21) |
| 7 | Custom fields section | `product-detail-page.tsx:196-207, :386-406` | `POST /api/platform/fields/values/crm_products/{id}` | one value per field definition of `crm_products` (0 definitions on dev [V]) | conditional tab, keyed by the CRM id | P3.5 | DECISION (S), A4 row 3 |
| 8 | Duplicates and merge dialog | `CrmDuplicatesDialog` from `product-catalog-list-view.tsx:295-304` | `GET /api/crm/products/duplicates`, `POST /api/crm/products/merge` | survivorId, duplicateIds | duplicates stay under More; the pair review is the second merge | P3.3 / P3.6 | OK |
| 9 | Bulk bar | `product-catalog-list-view.tsx:317-324` | `POST /api/crm/products/bulk/assign`, `/bulk/delete` | ids, assignedTo | list bulk actions | P3.3 | OK |
| 10 | Pair review (new) | not built yet | `GET /api/product-master/matches`, `POST /api/product-master/matches/decide` | itemMasterId, survivorMasterId, decision (same/different), nameKept | "Possible matches" | P3.6 | OK (backend on disk, uncommitted) |

**A2.3 The pickers and lists the forms read**

| Source | Used by | Gate | NEW HOME | Status |
|---|---|---|---|---|
| picklist `products.category` (`GET /api/crm/picklist-values?module=products`; admin edit `PUT /api/crm/picklist-values/{id}` needs the Products row, `CrmPicklistController.php:73`) | modal category, report field | read: any token; edit: Products row 468 | Sell category, unchanged | OK |
| tax rates (`crmService.getTaxRates`, and `meta.taxRates` from `GET /api/product-master/meta`) | modal, Details, S1 drawer | meta gives them only to a person who can write the catalogue (`ProductMasterController.php:162`) | `meta.taxRates` | OK |
| Product lines (`GET /api/product-master/lines`, `ProductMasterController.php:118-142`) **verified to exist** | modal "Product Line", S1 drawer | the Products row `view` right only (`routes/product_master.php:27-29`): a Services-only person or HR cannot read it | the Product select; reads re-gated in P2.5 | OK (planned, P2.5) |
| assignable employees (`useAssignableEmployees`, `lead-employees.tsx`) | modal "Assigned To" | shared CRM helper | Sell assignedTo | OK |
| readiness list (`config g2g_taxonomy.readiness_statuses`, in `meta.readinessStatuses` and `GET /api/portfolio/taxonomy`) | Portfolio form, writer | writer is STRICTER: `ProductWriter::checkReadiness` refuses any value not in the list, while the Portfolio PUT accepts any string up to 100 characters; dev has 0 off-list values [V], and the old dialog keeps an off-list value as an extra option (`product-form-dialog.tsx:351`) | About readiness | OK (note) |
| Portfolio taxonomy (needs, segments, signals, deployment models, deal bands, parent products) | Portfolio form | `GET /api/portfolio/taxonomy` | About pick lists | OK |

Counts A2 (rows of the three tables above): **41 items: crosswalk 25 rows (22 OK, 2 DECISION and 1 GAP that repeat A1 rows 15, 23, 21), forms 10 rows (7 OK, 2 GAP: rows 3 and 4, 1 DECISION: row 7, the custom fields of A4), pickers 6 rows (6 OK). So 35 OK, 3 GAP, 3 DECISION; the findings that are NEW here (not already an A1 or A4 row) are the 2 GAP rows: the Portfolio form's fields missing from the mock-up (G-4) and the missing "Add product" entry (G-5).**

### A3. The Portfolio side: offers, ICP, Signals products, master rows

**A3.1 `g2g_product_offers`: 29 columns in the migrations, 28 on dev.** Migrations: `2026_09_30_220000_create_g2g_portfolio_and_partners_tables.php` (26), `2026_10_08_120000_add_readiness_confirmation...` (`readiness_confirmed_by`, `readiness_confirmed_at`), `2026_10_27_200000_add_icp_config_to_g2g_product_offers_table.php` (`icp_config`). **`icp_config` is in the migrations and NOT on dev [V]**: the migration is not in the `migrations` table on dev and `information_schema` has no such column on any table. The code guards it (`OfferIcpSync.php:91` `ColumnExists::has`), so nothing breaks; the ICP then lives only in `g2g_icp_profiles` and `g2g_icp_profile_versions`. The plan and the P2-A work log do not mention it. 50 rows on dev: 25 for tenant 1 and 25 for tenant 6, none shared (NULL tenant) [V]; 0 confirmed, 0 user-edited, 18 partner-sellable [V].

Writer contract = 13 About keys (`ProductWriter::ABOUT_MAP`, :46-51) + name + group + readiness = the plan's "16 fields". `offer_id` is the 17th column and is the identity.

| # | Column | Evidence (writers / readers) | Used on dev | Old home (label) | NEW HOME | Delivered by | Status |
|---|---|---|---|---|---|---|---|
| 1 | `id` | W: DB. R: master `offer_row_id`, `PortfolioController` show/list | 50 | URL, row id | system | none | OK |
| 2 | `sub_institute_id` NULL = shared | W: `PortfolioController::store:333`, writer `offerAttributes`. R: `forTenant` scope | 25+25, 0 shared | none | system (a shared offer can never carry Sell, plan rule 8) | P2.4 | OK |
| 3 | `offer_id` varchar(50) | W: store (:334, unique across tenant plus shared, 409 `:328-330`), writer `assertOfferIdFree`, Excel import | 50 | "Product ID" (create only, `product-form-dialog.tsx:355`) | About `offerId` (immutable once About exists) | P2.4 | OK |
| 4 | `name` | W: store, update, writer, mirror. R: everywhere | 50 | "Name" | the record's one Name (mirrored) | P2.4 | OK |
| 5 | `parent_product` varchar(50) | W: store/update (must be one of the tenant's portfolio groups, :312, :429); writer sets it from the Product's `portfolio_name` (`offerAttributes:704`); mirror keeps it (`ProductMasterSync::mirror`). R: list group, `MatchingEngine` | 50 | "Category" (`pf-category`) | the Product select (level 1); never typed | P2.4 / P3.4 | OK |
| 6 | `modules_components` | W: store/update, `ABOUT_MAP`. R: Portfolio detail, `ProductMatchSuggester` | 50 | "Features and capabilities" | About `modulesComponents` | P3.4 | OK |
| 7 | `what_it_does` | same | 50 | "Description" | About `whatItDoes` ("What it does") | P3.4 | OK |
| 8 | `needs_solved` longtext list | W: free-text lines plus legacy N-codes (`lib/product-form.ts:196-199`); each entry max 400 characters, 60 entries (`PortfolioController.php:290-291`). R: matching | 50 | "Customer problems solved" | About `needsSolved[]` | P3.4 | OK |
| 9 | `primary_segments` list | W: entries max 160 (:292-293). R: matching | 50 | "Target customers" | About `primarySegments[]` | P3.4 | OK |
| 10 | `trigger_signals` list | W: same rules. R: matching, ICP | 50 | "Buying events" | About `triggerSignals[]` | P3.4 | OK |
| 11 | `deployment_model` varchar(100) | W/R as above | 50 | "Deployment" | About `deploymentModel` | P3.4 | OK |
| 12 | `readiness_status` varchar(100) | W: store/update; writer (stricter list check). R: list badge, `partner_sellable` | 50 (all in the list) | "Status" | About `readiness` | P3.4 | OK |
| 13 | `readiness_confirmed` | W: ONLY `PortfolioController::confirmReadiness` (admin, audited, log table `g2g_offer_readiness_log`); the Excel import reads the sheet's "Yes" (`PortfolioImportService.php:192`) but writes `false` (`:87`), and the writer forces false on create and never changes it | 0 | "Confirmed" badge and admin button | stays an admin action in the Portfolio route (`POST /portfolio/offers/{id}/readiness`) | P3.5 | OK |
| 14 | `typical_deal_band` varchar(50) | W/R as above | 50 (placeholder "To fill") | "Typical deal size" | About `typicalDealBand` | P3.4 | OK |
| 15 | `pricing_model` varchar(191) | same | 50 | "Pricing model" | About `pricingModel` | P3.4 | OK |
| 16 | `implementation_effort` varchar(100) | W: store/update/Excel; `lib/product-form.ts` carries it but **the dialog has no input** (`grep implementation` in `product-form-dialog.tsx` finds nothing [V]); R: Portfolio detail `portfolio-view.tsx:570`, profile panel :120 | 50 | read-only text | About `implementationEffort` (an input is new) | P3.4 | OK (note: first time it is editable) |
| 17 | `delivery_owner` varchar(100) | W/R | 50 | "Delivery owner" | About `deliveryOwner` | P3.4 | OK |
| 18 | `bundles_with` list of offer ids | W: API store/update (an optional array, no element check), Excel import, writer (`oftenSoldTogether`, no element check). R: `PortfolioController::show:151-157` (`whereIn('offer_id')`). **No screen edits or shows it today** (no component reads `bundles_with` or `bundled_offer_details` [V: grep over app/components/lib/services finds only the TS types]) | 50 | none | About `oftenSoldTogether[]` ("Often sold together") | P3.4 | **DECISION (S)**: the plan calls it a rename of an existing control; the control does not exist. Decide the value (offer ids, as the API reads them) and validate that each id is one of the tenant's offers |
| 19 | `proof_points` text | W/R | 50 | "Benefits and proof" | About `proofPoints` | P3.4 | OK |
| 20 | `partner_sellable` | derived: `ProductOffer::boot` computes it from `readiness_status` on save (`ProductOffer.php:30-35`, `TaxonomyService::isPartnerSellable`); R: Partner Network matching, badges | 18 | badge | system (derived); the writer saves through the model, so it is kept | P2.4 | OK |
| 21 | `notes` | W/R | 50 | "Notes" | About `notes` | P3.4 | OK |
| 22 | `is_user_edited` | W: every API save sets true (`store:354`, `update:452`, writer `offerAttributes` and `updateRecord`); R: the Excel import skips a user-edited row (`PortfolioImportService.php:47`) | 0 | none | system | none | OK |
| 23-26 | `created_by`, `updated_by`, `created_at`, `updated_at` | W: store/update, writer | 50 | none | system; About edits get an audit line from the writer (`ProductWriteAudit`) | P2.4 | OK |
| 27-28 | `readiness_confirmed_by`, `readiness_confirmed_at` | W: `confirmReadiness` only | 0 | confirmed-by line | system | P3.5 | OK |
| 29 | `icp_config` longtext (**absent on dev**) | W: `OfferIcpSync.php:91-92` only when the column exists. R: `SubProductIcp.php:81`, `PortfolioController::show` (via `SubProductIcp::allConfigsFor`) | n/a | none | system (a cache of the ICP map) | P2.4 | OK (note: unverified on live: the column may exist there) |

**A3.2 The mock-up and the About section.** The approved mock-up (view 3, card "1 About (from today's Portfolio form)") shows 10 rows: Product, ID, Name, Readiness, What it does, Problems it solves, Target customers, Buying events, "Pricing model · deal size", "Often sold together". **It leaves out six fields of today's Portfolio form (five editable and one read-only): Features and capabilities (`modules_components`), Benefits and proof (`proof_points`), Deployment (`deployment_model`), Delivery owner, Notes, and the read-only Implementation effort** [V: compare `mockup-product-portfolio.html:163-172` with `product-form-dialog.tsx:341-429`]. The backend contract already carries all 13 (`ABOUT_MAP`), so nothing is lost in P2; the risk is that P3.4 builds the form from the mock-up. **GAP (S)**, proposed guard in H3.

**A3.3 Ideal customer (ICP).**

| Store | Columns on dev | Writers / readers | NEW HOME | Status |
|---|---|---|---|---|
| `g2g_icp_profiles` (13 columns: id, sub_institute_id, key, name, product_line, status, is_default, version, config longtext, created_by, updated_by, created_at, updated_at; 1 row [V]) | the per-model ICP: `config` holds label, industry_label (ideal customer), pitch, guidance, buyer_types, regions, priority_cities, competitors, disqualifiers, must_have, triggers, queries, source, freshness, exclusions, customer_model | W: `OfferIcpSync::sync` (`app/Domain/Portfolio/OfferIcpSync.php`, called from `PortfolioController::store:361`, `update:459` and from the writer); `IcpProfileController` (`/api/signals/icp/profiles*`, admin only); R: Signals matching, `SubProductIcp` | "Ideal customer" section, the same three model tabs and the same payload (`icp_configs`), unchanged | OK |
| `g2g_icp_profile_versions` (9 columns; 1 row) | history of every save | W: `IcpProfileController`, `OfferIcpSync` | unchanged, shown on Signals & ICP | OK |
| offer `icp_config` | see A3.1 row 29 | | | OK |

**A3.4 Signals products (level 1) and the master row.**

| Store | Columns | Home | Status |
|---|---|---|---|
| `g2g_signal_products` (11 columns on dev: id, sub_institute_id, key varchar(20), label varchar(50), business_fit varchar(20), icp_product_line varchar(20), portfolio_names text, status, created_by, created_at, updated_at; 1 row, status `deleted` [V]) | a custom Product (level 1) | the Product's own record: `ProductLines::create` writes this row and the master row together (P2-B1, committed); key, label (50), `business_fit`, `icp_product_line` are system columns that Signals reads. The old `POST /api/signals/icp/products` (`ProductIcpController::create`) still creates through `ProductCatalog::createCustom` and then mirrors, so two code paths create a Product, both under `CustomProductRules` | **DECISION (S)** D-5: keep both or delegate |
| `g2g_product_master` (16 columns = the migration's 16 [V]: id, sub_institute_id, product_key, kind, parent_id, name, status, origin, signal_key, portfolio_name, offer_row_id, crm_product_id, created_by, updated_by, created_at, updated_at; 64 rows) | THE record | is the unified record | OK |
| `g2g_product_claims` (12 columns, 0 rows) | outreach claims keyed by `product_master_id` | outside the form; repointed by the pair merge (E) | OK (E) |
| `g2g_offer_readiness_log` (10 columns, 0 rows) | the log of readiness confirmations | system, shown on the Overview | OK |
| `g2g_product_match_decisions` (new, P2-D) | decisions | system | OK |
| `g2g_product_profiles` (21 columns, 1 row, ONE ROW PER ORGANISATION [S: `OpportunityController.php:58, :108`, `Portfolio/ProductController.php:80-95` "the organisation's own profile, kept apart from any product"]) | the company description for research (product_name, description, problems_solved, features, target_industries, target_company_types, target_company_size, target_markets, ideal_customer_profile, keywords, excluded, competitors, research_enabled, research_frequency, schedule_time, recency_days) | NOT a product record, so no home in the product form is needed; it stays in Company Opportunities "Advanced tools" and the department-management dialog. **The plan, the P0 measurements and the checker never mention it.** | OK (note) |

Counts A3: **offers 29 rows (28 OK, 1 DECISION: `bundles_with`, D-3), the mock-up About card 1 GAP (G-4), ICP 3 rows OK, Signals-side 6 rows (5 OK, 1 DECISION: the second Product create path, D-5). Total A3 items: 39; OK 36; GAP 1; DECISION 2.** [V counts of the rows above]

### A4. Fields of related records the Products screens edit

| # | Related record | What the old screens edit | Evidence | Used on dev | NEW HOME | Delivered by / proof | Status |
|---|---|---|---|---|---|---|---|
| 1 | Bundle items `crm_product_bundle_items` (columns: id, parent_product_id, component_product_id, quantity, created_at; unique `(parent_product_id, component_product_id)` [V]; **no `sub_institute_id` and no `deleted_at`**: the tenant is checked through the two products) | add a component (componentProductId, quantity, `min 0.01`, products only as PARENT, one level, no self, no nesting), remove one | `CrmProductController::bundleItems/addBundleItem/removeBundleItem` (:518-638), `product-detail-page.tsx:141-152`; no audit line is written for add or remove; both columns are registered pointers | 0 rows | Bundle tab of the Sub-product page, keyed by `sell.crmProductId` | P3.5; `ProductRepointer` already handles a merge | OK |
| 2 | Price-book items `crm_price_book_items` (id, price_book_id, product_id, list_price, timestamps; unique `(price_book_id, product_id)`) | **no Products screen edits them**: only `price-book-detail-page.tsx` does (`CrmPriceBookController` add/update/remove item); the Products pages only READ them in Usage (`ProductMasterController::usage:292-298`) | the item routes `api/crm/price-books/{id}/items*` are written only from `price-book-detail-page.tsx:54,111,126` and read also by `quote-detail-page.tsx:129` (price overrides) [V] | 27 rows | Usage tab shows them; editing stays in Price Books | P3.5 | OK |
| 3 | Custom field values `g2g_custom_field_values` (id, sub_institute_id, field_id, record_table, record_id, value, updated_by, timestamps) and definitions `tblcustom_fields` | values of the fields defined for `crm_products` | `product-detail-page.tsx:196-207`; `POST /api/platform/fields/values/crm_products/{id}`; allow-listed in `config/platform_services.php:579` and `CustomFieldValueController.php:135` | **0 definitions and 0 values** for `crm_products` on dev [V] (the 2 definitions in the table are deleted fields of other tables) | conditional tab; the record id is the CRM id | P3.5 | **DECISION (S)**: a Sub-product with only an About has no CRM row, so it has no `record_id`; say that the tab exists only when Sell exists, or key the fields to the master id |
| 4 | The image file `public/hp_crm_product/{id}_{random}.{ext}` on the `digitalocean` disk (the column `image` holds the name) | upload only; there is no way to remove or replace-by-delete a picture, a new upload only adds a new file and the old one stays on the disk | `uploadImage:642-686` | 0 | side call keyed by the CRM id | P3.5 | OK (note: old files are never deleted) |
| 5 | Product-line link `crm_products.product_master_id` | the "Product Line" select and "+ Create New Line" | A1 row 31 | 9 | the Product select | P3.4 | OK |
| 6 | Tax rates `crm_tax_rates` (the products screens read them, `CrmTaxRateController` edits them) | read only | `ProductMasterController::meta:162-166` | 1 row | unchanged | none | OK |
| 7 | Opportunity products `crm_opportunity_products` (id, opportunity_id, product_id, quantity, created_at; no unique key) | edited from the opportunity page, not from a Products screen | `CrmOpportunityController::products/addProduct/removeProduct` | 20 rows | Opportunity screens (E) | P3.2 | OK (E) |

Counts A4: **7 items, 6 OK, 1 DECISION (custom fields), 0 GAP.**

---

## C. Endpoints that touch products

Built from `php artisan route:list --json` on the working tree (a copy is in the audit scratch folder, not in the repo) and the controllers. The Services screens have NO routes of their own: they call the Products routes with `itemType=service` [V: no `services` URI exists]. Callers are listed as front-end `file:line`; "tests" are the PHP test files that name the path or the action (a grep over `tests/`, so a test that reaches a route by another way would not show: I say "none names it" and not "untested"); a route can have more callers than listed only if a caller builds its URL from a variable [I].

Gates: the `crm/products*` routes carry only the `api` middleware and every handler asks `canOnType(item type, action)` itself (`CrmProductController.php:829-842`, `MenuRight` on the Products row 468 or the Services row 469) [S]. The `product-master*` routes carry `api.token` and, for the reads, `platformright:/module/crm/sales/products,view`; the two writes carry the token only since P2-C (uncommitted) [V: route list]. The `portfolio/*` reads carry the token only; the writes carry `RequireProfile admin,hr` [V].

### C1. `crm/products*` (16 routes, one controller, `CrmProductController`)

| # | Route (handler:line) | Callers (front end) | Tests naming it | NEW HOME | Delivered by | Status |
|---|---|---|---|---|---|---|
| 1 | `GET /api/crm/products` (index :75-143; right = view of `itemType`; `per_page` is clamped to 100, :93) | `crmService.getProducts` (`services/crm/index.ts:516`): **11 files, 20 calls**: list `product-catalog-list-view.tsx:104`, bundle dialog `product-detail-page.tsx:61`, pickers in `quote-detail-page.tsx:116-117`, `sales-order-detail-page.tsx:126-127`, `purchase-order-detail-page.tsx:117-118`, `invoice-detail-page.tsx:166-167`, `opportunity-detail-page.tsx:134-135`, `price-book-detail-page.tsx:36-37`, `create-ticket-modal.tsx:50-51`, `create-asset-modal.tsx:39-40`, `create-faq-modal.tsx:40-41` | `CrmFullLifecycleGoldenPathTest`, `CrmProductProductMasterLinkTest`, `ProductMasterSyncTest`, `ProductWritePathDefectsTest`, `ProductWriterV2Test` | kept as is; every document picker moves to `GET /product-master/picker` (P3.2, one consumer per commit). Until then the pickers ask for 200 and the server gives 100, per type, with no paging [S] | P3.2 | OK |
| 2 | `POST /api/crm/products` (store :175-250) | `create-product-modal.tsx:137` | golden path, `CrmProductProductMasterLinkTest`, `ProductWritePathDefectsTest`, `ProductMirrorTest` | kept (P2-B1 fixes in); it stays a second door to the same columns through `CatalogueFields`; "delegated to the writer, or never" is the plan's own wording | P2.4 | OK |
| 3 | `GET /api/crm/products/{id}` (show :145-173) | `app/module/crm/sales/products/[id]/page.tsx:49`, `.../services/[id]/page.tsx:39` | same set | kept; the old addresses redirect to the Sub-product page through `/by-crm/{id}` | P3.7 | OK |
| 4 | `PUT /api/crm/products/{id}` (update :252-332) | `create-product-modal.tsx:136` | same set | kept | P2.4 | OK |
| 5 | `DELETE /api/crm/products/{id}` (destroy :334-365, to the Recycle Bin) | **none**: `crmService.deleteProduct` has no caller; the `deleteProduct` seen in `portfolio-view.tsx:149` is the Signals product delete [V] | `ProductMasterSyncTest` (calls it at :367, :373, :458, :651, :1018) | kept; where a person moves ONE item to the bin is a screen question (auditor B); the plan says Sell off is "Paused, never delete" | P3.3 | **DECISION (S)**: bin one record from the unified page, or only through bulk delete |
| 6 | `POST /api/crm/products/bulk/delete` (:372-402) | `product-catalog-list-view.tsx:158` | `ProductMasterSyncTest` | kept (list bulk action) | P3.3 | OK |
| 7 | `POST /api/crm/products/bulk/assign` (:404-436) | `product-catalog-list-view.tsx:172` | **none names it for products** [V] | kept | P3.3 | **GAP (S)**: no test |
| 8 | `GET /api/crm/products/duplicates` (:438-467; same name or same SKU, per item type) | `product-catalog-list-view.tsx:299` | **none** [V] | kept under More | P3.3 | **GAP (S)**: no test |
| 9 | `POST /api/crm/products/merge` (:469-514; `ProductRepointer` repoints the 12 pointers) | `product-catalog-list-view.tsx:300` via `CrmDuplicatesDialog` | `ProductMergeRepointTest`, `ProductMasterSyncTest` | kept; the pair review is a second, separate merge | P2.7 / P3.6 | OK (P2-B2 note: the 10 `product_master_id` columns that point at a binned item's master row are not repointed; nothing is stranded on dev) |
| 10 | `GET /api/crm/products/export` (:688-719; 17 columns) | `crmService.productsExportUrl` (`:556`) from `product-catalog-list-view.tsx:195` (a link, `CrmExportButton`) | **none** [V] | kept under More | P3.3 | **GAP (S)**: no test |
| 11 | `POST /api/crm/products/import` (:721-800; max 500 rows, `assignedTo` required) | `crmService.importProducts` (`:559`) from `product-catalog-list-view.tsx:313` | `ProductMasterSyncTest`, `ProductWritePathDefectsTest` | kept under More | P3.3 | OK |
| 12 | `GET /api/crm/products/{id}/bundle-items` (:518-555) | `product-detail-page.tsx:145` | **none** [V] | kept, keyed by `sell.crmProductId` | P3.5 | **GAP (S)**: no test |
| 13 | `POST /api/crm/products/{id}/bundle-items` (:557-615) | `product-detail-page.tsx:71` | **none** | kept | P3.5 | **GAP (S)**: no test |
| 14 | `DELETE /api/crm/products/{id}/bundle-items/{rowId}` (:617-638) | `product-detail-page.tsx:174` | **none** | kept | P3.5 | **GAP (S)**: no test |
| 15 | `GET /api/crm/products/{id}/history` (`HasCrmAuditTrail::history` :129; right = view of the type, `CrmProductController::mayReadHistory` :937; reads ONE entity type, `crm_products`, for ONE id, the CRM id) | `crmService.getProductHistory` (`:1400`) from `product-detail-page.tsx:157` | `ProductWritePathDefectsTest` | the History tab. **Not enough**: the writer files its audit lines under THREE entity types (`crm_products`, `g2g_product_offers`, `g2g_product_master`, `ProductWriteAudit.php:14-16` as read) and the approved mock-up says History "now includes About edits" (view 4); no route reads the other two (a grep of `app/` and `routes/` finds no reader of those entity types outside the writer, so the class comment's "the Portfolio and Product Master histories each find theirs" has no code behind it [V]), and an About-only record has no CRM id | P2.5 (not in the plan's list) | **GAP (M)**: a history route for the unified record |
| 16 | `POST /api/crm/products/{id}/image` (:642-686) | `crmService.uploadProductImage` (`:577`) from `product-detail-page.tsx:185` | **none** [V] | kept, side call | P3.5 | **GAP (S)**: no test; also needs the Sell read to carry `imageUrl` (A1 row 21) |

### C2. `product-master*` (12 routes, `ProductMasterController` and `ProductMatchController`)

| # | Route (handler:line) | Gate (as on disk) | Callers (front end) | Tests naming it | NEW HOME | Delivered by | Status |
|---|---|---|---|---|---|---|---|
| 1 | `GET /api/product-master` (index :32-95) | Products row, view | `service.list` in `product-lines-view.tsx:30,45` | `ProductMasterApiTest`, `ProductMasterTest` | replaced by the paged flat list; the old shape stays until the S1 components go (P4.3) | P2.5 | OK |
| 2 | `POST /api/product-master` (store :206-214, `ProductWriter::create`) | token only; the writer gates by section | `service.create` in `product-master-form.tsx:46,58`, `product-lines-view.tsx` | `ProductMasterWriteApiTest`, `ProductWriterV2Test` | THE write path of the unified screen | P2.4 (on disk, uncommitted) | OK |
| 3 | `PUT /api/product-master/{id}` (update :216-228) | token only | `service.update` (same files) | same | same | P2.4 | OK |
| 4 | `GET /api/product-master/lines` (:118-142) **verified to exist** | Products row, view | `crmService.getProductLines` (`services/crm/index.ts:513`) from `create-product-modal.tsx:58` (`productMasterService.lines` itself has no caller) | `CrmProductProductMasterLinkTest` (the only file that names this path) | the Product select; a Services-only person and HR cannot read it today | P2.5 re-gate | OK (planned) |
| 5 | `GET /api/product-master/options` (:96-115) | Products row, view | `productMasterService.options` from `create-campaign-modal.tsx:52`, `create-lead-modal.tsx:56`, `lead-list-view.tsx:168`, `opportunity-form-options.ts:22`, `services/signal-review/runs-service.ts:107`, `signal-review-service.ts:80` | `ProductMasterApiTest`, `ProductMasterSyncTest`, `ProductMasterWriteApiTest` | the Product pickers of leads, campaigns, opportunities and Signals; a person with no Products row gets a 403 for the list today [S: `routes/product_master.php:27-31`] (auditor E) | P2.5 re-gate | OK (planned) |
| 6 | `GET /api/product-master/meta` (:149-186) | Products row, view | `service.meta` in the S1 screens | `ProductMasterApiTest`, `ProductMasterWriteApiTest` | kept: `can`, `readinessStatuses`, `taxRates` | P2.5 | OK |
| 7 | `GET /api/product-master/{id}` (show :189-203) | Products row, view | `service.get` in `product-line-record-page.tsx:34`, `product-links-panels.tsx:14` | `ProductMasterApiTest` | the Sub-product and Product pages | P2.5 | OK |
| 8 | `POST /api/product-master/{id}/archive` | Products row, delete | `service.archive` in `product-line-record-page.tsx` | `ProductMasterApiTest`, `ProductMasterWriteApiTest`, `ProductWriterV2Test` | kept | P3.5 | OK |
| 9 | `POST /api/product-master/{id}/restore` | Products row, edit | `service.restore` (same) | `ProductMasterApiTest`, `ProductMasterSyncTest`, `ProductMasterWriteApiTest`, `ProductMirrorTest`, `ProductWriterV2Test` | kept | P3.5 | OK |
| 10 | `GET /api/product-master/{id}/usage` (:265-315) | Products row, view | `service.usage` in `product-line-record-page.tsx:246`, `product-links-panels.tsx:132` | `ProductMasterApiTest`, `ProductMasterSyncTest` | the Usage tab. **Plan claim "the Usage tab reads quotes" is TRUE** (`:299-302`, distinct quotes through `crm_quote_line_items`), but it reads ONLY signals, ICP state, price books and quotes: no sales orders, invoices, purchase orders, opportunity lines, tickets, assets, FAQs or bundles, while the mock-up (view 4) says "which quotes, orders, invoices, price books and signals" | P2.5 / P3.5 | **GAP (M)**: extend `usage` to the pointer tables the page promises |
| 11 | `GET /api/product-master/matches` (`ProductMatchController::index` :31) | Products row view AND administrator | none yet (P3.6) | `ProductMatchApiTest` | "Possible matches" | P3.6 | OK |
| 12 | `POST /api/product-master/matches/decide` (:49) | Products row edit AND administrator | none yet | `ProductMatchApiTest` | the same | P3.6 | OK |

### C3. `portfolio/*` routes that carry product data (12 routes; the Partner Network routes are not products and are left out)

| # | Route (handler:line) | Gate | Callers (front end) | Tests naming it | NEW HOME | Delivered by | Status |
|---|---|---|---|---|---|---|---|
| 1 | `GET /api/portfolio/offers` (index :71) | token | `portfolioService.getOffers` from `portfolio-view.tsx:83,87`, `product-form-dialog.tsx:232` (next ID) | `ProductPortfolioIcpTest`, `RestoredModulesApiTest`, `CustomProductApiTest` | kept (Signals and Partner matching read it); the list screen moves to the unified list | P3.3 | OK |
| 2 | `POST /api/portfolio/offers` (store :271) | admin, hr | `product-form-dialog.tsx:305` | `CustomProductApiTest`, `ProductPortfolioIcpTest`, `ProductMasterSyncTest` | kept for the old drawer until P3; the plan says delegation "last, or never" | P2.4 | OK |
| 3 | `GET /api/portfolio/offers/{id}` (show :134; accepts the row id or the offer id) | token | `portfolio-view.tsx:189,202`, `product-profile-panel.tsx:98` | `ProductPortfolioIcpTest` | kept; the Sub-product page reads `product-master/{id}` | P3.5 | OK |
| 4 | `PUT /api/portfolio/offers/{id}` (update :377) | admin, hr | `product-form-dialog.tsx:305` | `ProductPortfolioIcpTest` | kept | P2.4 | OK |
| 5 | `DELETE /api/portfolio/offers/{id}` (destroy :474-528; HARD delete of the offer and of its ICP profiles after an in-use check) | admin, hr | `portfolio-view.tsx:135` | `ProductMasterSyncTest` | the one-record rule is "no way back, no remove-About" (P2-C semantics 1) but this route removes an About; `ProductMasterSync` then turns a sold Sub-product into an item. Say what the unified page's Delete does | P3.5 | **DECISION (M)** |
| 6 | `POST /api/portfolio/offers/{id}/readiness` (`confirmReadiness` :193) | admin only (two stacked `RequireProfile`) | `portfolioService.setReadiness` (`portfolio-service.ts:95`) from `portfolio-view.tsx:219` | **none names it** [V] | kept as the audited admin action on the Sub-product Overview | P3.5 | **GAP (S)**: no test |
| 7 | `GET /api/portfolio/offers/{id}/readiness-log` (`readinessLog` :249) | admin, hr | `getReadinessLog` from `portfolio-view.tsx:177` | **none names it** [V] | kept | P3.5 | **GAP (S)**: no test |
| 8 | `POST /api/portfolio/import` (import :533; Excel sheet "Product Portfolio" rows 5 to 29, 18 columns, through Python openpyxl by `shell_exec`) | admin, hr | `portfolioService.triggerImport` (`:114`) from `portfolio-view.tsx:118`, menu item "Sync Excel" (`:322-325`, shown when `canManage`) **verified to exist** | `ProductMasterSyncTest:1113` | kept under More | P3.3 | OK (auditor D covers the pre-connection) |
| 9 | `GET /api/portfolio/stats` (stats :42) | token | **none**: `portfolioService.getPortfolioStats` has no caller [V] | `RestoredModulesApiTest` | kept (dead) | none | OK |
| 10 | `GET /api/portfolio/taxonomy` (:26) | token | `getTaxonomy` from `portfolio-view.tsx:82`, `product-detail-view.tsx:57`, `partners-view.tsx:123` | `CustomProductApiTest`, `ProductMatchingTest` | kept: About pick lists | P3.4 | OK |
| 11 | `GET /api/portfolio/products` (`Portfolio\ProductController::index`) | token | `portfolioService.getProducts` from `signals/product-selector.tsx:54` | five test files | kept (Signals) | none | OK |
| 12 | `GET /api/portfolio/products/{key}` (`show`) | token | `getResearchContext` from `signals/research-context-notice.tsx:21` | same | kept (Signals) | none | OK |

### C4. `signals/*` routes that create, delete or describe a product

| # | Route (handler:line) | Gate | Callers (front end) | Tests naming it | NEW HOME | Delivered by | Status |
|---|---|---|---|---|---|---|---|
| 1 | `POST /api/signals/icp/products` (`ProductIcpController::create` :27; name 120 characters in the controller, `CustomProductRules` then holds it to 2-50) | admin, throttle 10/min | `productIcpService.createProduct` (`services/signals/product-icp.ts:132`) from `new-product-dialog.tsx:43` | `CustomProductApiTest`, `ProductMasterSyncTest`, `ProductPortfolioIcpTest`, `SubProductIcpOwnershipTest` | the writer's `kind=product` is the new single door (`ProductLines::create`); this route still creates through `ProductCatalog::createCustom` and mirrors afterwards, so there are two creators with the same rules | P3.4 | **DECISION (S)** D-5: keep both, or delegate this route to `ProductLines::create` |
| 2 | `DELETE /api/signals/icp/products/{product}` (`destroy` :62; deletes the product row, its ICPs and their versions from the database) | admin, throttle | `productIcpService.deleteProduct` (`:136`) from `portfolio-view.tsx:149` | `CustomProductApiTest` (:174-176: 403 for a non-admin, 422 for a built-in product, 404 for an unknown key) | needs a home on the unified Product page (button "Delete product"); the owner said nothing is deleted from the Products tables, this deletes `g2g_signal_products` and ICP rows | P3.5 | **DECISION (S)** (with C3-5) |
| 3 | `GET/POST /api/signals/icp/profiles`, `GET/PUT /{id}`, `GET /{id}/accounts`, `GET /{id}/preview`, `PATCH /{id}/status`, `GET /templates`, `POST /validate` (9 routes, `IcpProfileController`, admin) | admin | the Signals ICP screens | `IcpProfileApiTest`, `ProductDrivenDiscoveryTest`, `SubProductIcpOwnershipTest` | unchanged: the ICP rows these edit are the ones `OfferIcpSync` writes from the one form | none | OK (E) |
| 4 | `GET/PUT /api/signals/product-profile` (company profile, one row per organisation; not a product) | admin, hr (, manager) | `components/domain/signals/advanced-tools.tsx`, `product-profile-dialog.tsx` | `ProductDrivenDiscoveryTest`, `RestoredModulesApiTest` | unchanged | none | OK |

### C5. Generic routes that are keyed by the module `products` or `services` (or the table `crm_products`)

| # | Route family | How products enter | Callers | Tests naming it | NEW HOME | Status |
|---|---|---|---|---|---|---|
| 1 | `GET /api/crm/recycle-bin` (`CrmRecycleBinController::index` :62) | types `products` and `services` (`TYPES` :42-43), right = the type's row, `delete` | `recycle-bin-view.tsx:72` (labels `:22-23`) | none | stays; a binned catalogue item is the "In the Recycle Bin" Sell state | OK |
| 2 | `POST /api/crm/recycle-bin/{type}/{id}/restore` (:107-148) | restores the row then calls `ProductMasterSync` (`:140-146`) | `recycle-bin-view.tsx:93` | `ProductMasterSyncTest`, `ProductMirrorTest` | stays, and Sell switched on again restores the same row (plan rule 2) | OK |
| 3 | `DELETE /api/crm/recycle-bin/{type}/{id}` (`forceDelete` :150-) | **a permanent delete of a catalogue item cascades or nulls the 11 pointer tables**: foreign keys `cascadeOnDelete` on `crm_opportunity_products`, `crm_product_bundle_items` (both columns) and `crm_price_book_items`, `nullOnDelete` on quote, sales order, purchase order and invoice lines, assets, tickets and FAQs [S: the migrations, `grep foreign`]; the master row keeps its `crm_product_id` until `ProductMasterSync` runs. On dev 3 quote lines point at the 3 binned items (ids 6 to 8), so a permanent delete there would null those 3 lines [V]; no master row holds a binned id [V] | `recycle-bin-view.tsx:110` | `ProductMasterSyncTest` (:374, :1093) pins only the master row's result ("deleted for good: the item row stays archived, as history") for an item no document uses; nothing tests a delete of an item that documents reference [V] | **the plan says the CRM row is "never deleted" because 11 tables point at it; this route can delete it and nobody in the plan covers it** | **GAP (M)**: refuse a permanent delete while any of the 12 pointers (`ProductPointers::toCrmProduct`) holds the id, or hide the button for these two types |
| 4 | `GET/POST/DELETE /api/crm/saved-views` (`CrmSavedViewController`; `module` may be `products` or `services`, `:33, :46-47`) | conditions `{search, sortKey, sortAsc}` | `crm-saved-views.tsx:43,62,76` from `product-catalog-list-view.tsx:216` | none (any module) | stays; the unified list must keep a module key, or a saved view of Products becomes unreachable (usage counts: auditor F) | DECISION (S) D-7 |
| 5 | `GET/POST/PUT /api/crm/picklist-values*` (module `products`, field `category`; `CrmPicklistController::FIELD_KEYS` :55) | the Category list for Products AND Services | `product-catalog-list-view.tsx:125`, `products/[id]/page.tsx:64`, `services/[id]/page.tsx:54` | none | stays | OK |
| 6 | `GET/POST /api/platform/fields/values/crm_products/{id}` and the field definitions `api/platform/fields*` (`CustomFieldValueController`; allow-list `config/platform_services.php:579`, `CustomFieldValueController.php:135`) | `recordTable = crm_products`, `recordId` = the CRM id | `product-detail-page.tsx:196-207, :386-406` | none for products | stays; keyed by the CRM id (A4 row 3) | DECISION (S) (A4) |
| 7 | `GET/POST/PUT /api/crm/tax-rates*` (`CrmTaxRateController`) | `crm_products.tax_rate_id` | `getTaxRates` from the list, the page and the forms | none | stays | OK |

### C6. Routes the plan promises that do not exist yet [V: absent from the route list]

| Planned route (plan section 5, P2.5) | State | Status |
|---|---|---|
| `GET /api/product-master/{id}/sell` | absent; the Sell block is returned today only inside the write answers (`ProductMasterController::record` with a viewer, :343-348) | planned (P2.5) |
| `GET /api/product-master/by-crm/{id}` | absent; needed by the old addresses (`/products/{crmId}`, saved Ask answers) | planned (P2.5) |
| `GET /api/product-master/picker` | absent; the 11 consumers still use `GET /crm/products` | planned (P2.5) |
| a history route for the unified record | **not in the plan** (see C1 row 15) | GAP (M) |

### C7. PHP classes and commands that run the same code (callers other than a screen)

`ProductMasterSync::throughCatalogueItems` is called from `CrmProductController` store, update, destroy, bulkDelete, merge, import and from `CrmRecycleBinController` restore and forceDelete; `ProductMasterSync::throughOffer` from `PortfolioController` store, update, destroy; `ProductMasterSync::throughCustomProduct` from `ProductIcpController`. Commands (auditor D owns the verdict): `product-master:backfill`, `:repair`, `:check`, `:unify` (uncommitted), `portfolio:export-offers`, `portfolio:import-offers` (JSON, per tenant), `g2g:import-portfolio` (the same service as `POST /portfolio/import`).

Counts C, tallied row by row from the tables above [V]:
- C1: OK = rows 1, 2, 3, 4, 6, 9, 11 = 7; DECISION = row 5 = 1; GAP = rows 7, 8, 10, 12, 13, 14, 15, 16 = 8. Total 16.
- C2: OK = 11 (rows 1 to 9, 11, 12); GAP = row 10 = 1. Total 12.
- C3: OK = rows 1, 2, 3, 4, 8, 9, 10, 11, 12 = 9; DECISION = row 5 = 1; GAP = rows 6, 7 = 2. Total 12.
- C4: OK = rows 3, 4 = 2; DECISION = rows 1, 2 = 2. Total 4.
- C5: OK = rows 1, 2, 5, 7 = 4; GAP = row 3 = 1; DECISION = rows 4, 6 = 2. Total 7.
- C6: planned (counted OK) = 3; GAP = 1. Total 4.
- **C total: 55 items, OK 36, GAP 13, DECISION 6.** The 13 GAP rows are 12 distinct findings: the history route is listed twice (C1 row 15 and C6 row 4). Of the 12: 9 are "no test names this route" (C1 rows 7, 8, 10, 12, 13, 14, 16 and C3 rows 6, 7), 2 are read features the unified page needs (the history route and the wider `usage`), 1 is the permanent delete of a catalogue item from the Recycle Bin.

---

## H. The permanent guard (specification only; this audit builds nothing)

Two scan tests, in the family that already exists (`ProductPointersScanTest`, `ProductRepointerScanTest`, `ProductMergerScanTest`, `OrganisationReferencesScanTest`, `SignalReviewAddressGoneTest`): they read files and the router, need no database, run in the default PHPUnit suites and take milliseconds. Each must ship with the mutation proof COMMON-RULES section 4 asks for (break it, a named test fails, restore byte for byte, `cmp`; prove the baseline green first).

### H1. The field guard: every `crm_products` column is in the Sell field map or in an allow-list with a reason

| Question | Answer |
|---|---|
| Where the map lives | `App\Domain\ProductMaster\CatalogueFields`: `MAP` (19 request keys to columns), `TYPE_COLUMN` (`item_type`), `systemColumns()` (11 columns, each with a written reason of 30 characters or more), `SELL_ALIASES`, `PRODUCT_ONLY`, and `rules()` / `limits()` for the validation. P2-C extracted it (task 1); both `CrmProductController::payload` and `ProductWriter::insertCatalogue` read it. |
| What the test reads | `database/migrations/*.php` as text with every comment removed (`token_get_all`). It keeps the migrations that work on `crm_products` (literal `Schema::create` / `Schema::table`, `CREATE` / `ALTER TABLE`, or `const TABLE`) and collects the columns they define (Blueprint calls, `timestamps()`, `softDeletes()`, a raw `ADD COLUMN`, a `const COLUMN`). It reads the map class by calling it. No database, so it cannot see a column that exists on a host and in no migration (see E3). |
| How it fails | A column that a migration defines on `crm_products` and that is neither mapped nor allow-listed: the message names the column and the migration file and says "map it (both doors then read and write it) or explain why no form touches it". Also: a mapped or allow-listed column that no migration defines (a typo); a column both mapped and allow-listed; a reason shorter than 30 characters; two request keys for one column; the controller or the writer no longer using `CatalogueFields::` or keeping a private copy of the map; the hand-written 31-name anchor (`KNOWN`) differing from what the scan finds, so the scan cannot pass by finding nothing. |
| Where it goes | `tests/Unit/CatalogueFieldsScanTest.php`, plain `PHPUnit\Framework\TestCase`; add it to the "suites to run at the end" of P2-C and every later track (P3.x, P5). |
| State on disk at the time of reading | **Already written by P2-C and uncommitted**: 9 tests (`test_every_column_of_crm_products_is_in_the_field_map_or_explained` and 8 more, 320 lines [S]). I did not run it (running PHPUnit writes cache files, and this audit may write one file only). It covers the brief's test 1 in full. |

**Four extensions the in-flight test lacks, each tied to a defect this audit found** (decision D-11: add them or say why not):

| # | Extension | Reads | Fails when | Defect it would have caught today |
|---|---|---|---|---|
| E1 | Read parity | `CatalogueFields::toSell()` called with a row that has every mapped column set to a marker | a `MAP` key, or an allow-listed column that is meant to be shown (`image` as `imageUrl`, `product_no`, `id`, `item_type`, `deleted_at`), is missing from the Sell read | `image`: `toSell()` has no `imageUrl` (A1 row 21) |
| E2 | Rules against columns | the `max:N` of every string rule in `rules()` and `limits()` against the declared length in the migration (an explicit `string('x', N)` or the default 191, read from `Schema::defaultStringLength` in `AppServiceProvider.php:43`), and a `min`/`max` for every decimal and unsigned column | a rule that allows more than the column holds | **`glAccount` and `usageUnit` allow 255 for a `varchar(191)` [V]**; also no range on `unitPrice`, `costPrice` (decimal 15,2), `qtyInStock` (15,2), `qtyInDemand` (12,2), `weight` (10,3) or `reorderLevel` (unsigned, no `min:0`), so an out-of-range number is a database error under strict mode, not a 422 [V rule list against `information_schema`] |
| E3 | Host against migrations | not PHPUnit: a warning W3 in `ProductIntegrity` / `product-master:check` that compares `information_schema.COLUMNS` of `crm_products`, `g2g_product_offers`, `g2g_product_master`, `g2g_signal_products` with the migration list | a column on the host that no migration defines, or a migration column the host lacks (exit 0 with a warning, like W1 and W2) | `g2g_product_offers.icp_config` is in a migration and absent on dev [V] |
| E4 | Form parity (front end) | a `node --test` in `g2gv0/lib/product-master/` holding a hand-written list of the 19 Sell keys and the 13 About keys, and reading the new form's source (P3.4) | a key with no input in the form | the approved mock-up's About card omits six fields of today's Portfolio form (A3.2) |

### H2. The route guard: every route under `api/crm/products` is in a route map with its new home or "kept as is"

| Question | Answer |
|---|---|
| Where the map lives | a new support class `Tests\Support\ProductRouteMap` (`tests/Support/ProductRouteMap.php`, test-only data, no production code: the plan's status is not application behaviour). One constant, keyed by `"METHOD uri"`, for example `'GET api/crm/products/{id}/bundle-items'`. Each entry: `home` (`kept` / `delegated` / `replaced` / `redirected` / `removed`), `handler` (`CrmProductController@bundleItems`), `step` (a plan step, `P3.5`), `why` (30 characters or more), `tests` (the test files that name the path or the action), and for `replaced` the route that replaces it (`replacedBy`) with a `status` of `live` or `planned`. |
| What the test reads | the router, as `LeadsTenantSweepTest::routes()` does: `app('router')->getRoutes()->getRoutes()` filtered to `uri() === 'api/crm/products'` or a URI that starts with `api/crm/products/` (16 today, C1), with the HEAD and OPTIONS methods removed; and the map. The families C2 to C5 should go into the SAME map (`api/product-master`, `api/portfolio/offers`, `api/portfolio/import`, `api/signals/icp/products`, and the generic routes keyed by module): the brief asks for `crm/products`, the mechanism is the same, and the owner's request covers all of them. |
| How it fails | (1) a live route that is not in the map: "route X is new, say where it goes"; (2) a map entry with no live route (renamed or removed without the map, or a typo); (3) a `home` that is not in the allowed set, or a `why` under 30 characters, or a `step` that is not in the plan's step list; (4) a `tests` entry that is not a file under `tests/` that contains the route's path or the handler's method name (so "tested by" cannot rot); (5) `replaced` with `status = live` and no such route in the router, or `status = planned` while the route already exists (a stale plan). A route with no test today (9 in C) is entered with `tests => []` and a `debt` note; the test PRINTS the debt. |
| The handover test | `test_nothing_is_planned_and_no_debt_remains` is skipped unless the environment variable `PARITY_FINAL=1` is set; P5 and the last run before any push set it. It fails while any entry is `planned` or carries `debt`. So today's absent routes (`/{id}/sell`, `/by-crm/{id}`, `/picker`, the history route, C6) and the 9 untested routes cannot be forgotten, and cannot block ordinary work either. |
| Where it goes | `tests/Feature/ProductRouteMapScanTest.php` (it boots the application for the router and uses no database), listed in the P2.5, P3.x and P5 suites. |
| Front-end counterpart | `g2gv0/lib/product-routes.test.ts` in the style of `lib/signal-review-address-gone.test.ts`: it reads `services/crm/index.ts`, `services/product-master/product-master-service.ts`, `services/portfolio/portfolio-service.ts` and fails when a function whose path starts with `/crm/products` is not named in a TS map with its new home, and (after P3.2) when any file other than the one `ProductPicker` calls `crmService.getProducts`. |
| Mutations the builder must show | add a route under the prefix; remove a map entry; set `home` to junk; shorten `why`; point `tests` at a missing file; point it at a file that does not name the route; set `replacedBy` to a route that does not exist; set `status = planned` for a live route. Each must fail a named test. |

### H3. Recommended third guard: the About side has the same risk

`ProductWriter::ABOUT_MAP` (13 keys, private to a `final` class) and the offer columns have no scan. A3 shows why one is needed: `partner_sellable`, `readiness_confirmed(_by, _at)`, `is_user_edited` and `icp_config` are outside the contract and only comments explain them, and the one column added after the table was made (`icp_config`) is the one that diverges between the migrations and dev. Spec: move `ABOUT_MAP` and a `systemOfferColumns()` allow-list (each with a reason) into a class `OfferFields` next to `CatalogueFields`, and add `tests/Unit/OfferFieldsScanTest.php` that reads the migrations that work on `g2g_product_offers` exactly as H1 does. Counted as a recommendation (D-11), not as one of the brief's two tests.

Counts H: **2 required guards specified (H1, H2): 2 OK; 1 recommended guard (H3) and the four H1 extensions are one DECISION (D-11).**

---

## Gaps and decisions found in this slice (A, C, H)

Sizes are S (an hour or two), M (a day), L (more). "Plan step" is where I would add it.

### Gaps: 8 distinct findings, behind 20 GAP rows of the tables

| ID | What | Size | Plan step | Where in this file |
|---|---|---|---|---|
| G-1 | The Recycle Bin's "Delete permanently" can hard-delete a catalogue item that 11 tables point at (cascade on opportunity lines, bundle items, price-book items; null on quote, order, invoice and purchase lines, tickets, assets, FAQs); the plan says the CRM row is never deleted. On dev 3 quote lines depend on the 3 binned items. Refuse it while any of the 12 pointers holds the id, or hide it for `products` and `services` | M | P2.4 or P2.5, test in `ProductMergeRepointTest`'s style | C5 row 3 |
| G-2 | No history route for the unified record: the writer files audit lines under 3 entity types, `GET /crm/products/{id}/history` reads one, and an About-only record has no CRM id. The mock-up promises "History now includes About edits" | M | P2.5 (add `GET /product-master/{id}/history`, Sell lines only for a caller who may view Sell) | C1 row 15, C6 |
| G-3 | `GET /product-master/{id}/usage` reads signals, ICP, price books and quotes only; the mock-up Usage tab promises orders and invoices, and the pair review already counts nine tables | M | P2.5 | C2 row 10 |
| G-4 | The approved mock-up's About card omits six fields of today's Portfolio form (Features and capabilities, Benefits and proof, Deployment, Delivery owner, Notes, Implementation effort). The writer already carries all 13; the risk is P3.4 building from the picture | S | P3.4, with H1 extension E4 | A3.2 |
| G-5 | No "Add product" (level 1) entry exists in the mock-up or the plan's list, though `new-product-dialog.tsx` has it today and the writer takes `kind=product` (administrator) | S | P3.4 | A2.2 row 4 |
| G-6 | `CatalogueFields::limits()` allows 255 characters for `glAccount` and `usageUnit`; the columns hold 191 (`Schema::defaultStringLength(191)`). A 192-255 character value is a 500, not a 422. No ranges on the decimal and unsigned columns either | S | P2.4 (fix in the uncommitted class) and H1 E2 | A1 rows 8, 22; H1 |
| G-7 | The Sell read (`CatalogueFields::toSell`) has no `imageUrl`; the page header shows the picture today | S | P2.5 `/{id}/sell` | A1 row 21 |
| G-8 | Nine routes of the old module have no test that names them: bundle items (3), image upload, CSV export, duplicates, bulk assign, readiness confirm and readiness log. P3 will retarget their callers | S each, M together | one smoke test each before P3, and the H2 map | C1, C3 |

How the 20 GAP rows map to the 8 findings [V, counted from the tables]: G-1 1 row (C5-3); G-2 2 rows (C1-15, C6-4); G-3 1 row (C2-10); G-4 2 rows (A2.2 form row 3, A3.2); G-5 1 row (A2.2 form row 4); G-6 2 rows (A1 rows 8, 22); G-7 2 rows (A1 row 21, the `image` line of the A2.1 crosswalk); G-8 9 rows (C1 rows 7, 8, 10, 12, 13, 14, 16 and C3 rows 6, 7). 1+2+1+2+1+2+2+9 = 20.

### Decisions (for the owner or the plan author)

| ID | Question | Why it matters | Where |
|---|---|---|---|
| D-1 | Two switches write one state: "Sold through CRM" off and "Active" off both set `is_active = 0` (plan P2-C rule 2 and the mock-up). Keep one, or say what the second means (for example "offered on new documents") | a person can set a state the other switch contradicts | A1 row 15 |
| D-2 | `assigned_to`: required in the CRM form, controller and CSV import; defaulted to the caller by the one-record writer | the same field has two rules; "Assigned to" is also never shown after save | A1 row 23 |
| D-3 | `bundles_with` has no screen today (only the API and the Excel import) and the plan calls "Often sold together" a rename. Decide the stored value (offer ids, as `PortfolioController::show` reads them) and check each id exists | the writer stores any string up to 50 characters | A3.1 row 18 |
| D-4 | Custom fields are keyed by the CRM id (`record_table = crm_products`): a Sub-product with an About and no Sell has no id. Tab only when Sell exists, or key them to the master id | 0 definitions on dev, so nothing to migrate yet | A4 row 3 |
| D-5 | Two code paths create a Product (level 1): `ProductLines::create` (the writer, the CRM inline line) and `ProductCatalog::createCustom` (`POST /signals/icp/products`). Same rules, two places | the plan and the P2-B1 log say "the one place" | C4 row 1 |
| D-6 | CSV: the import template has 11 headers, the server accepts 8 more fields, the export writes 17 and leaves out the item number, tax rate, picture and Product. An exported file cannot be imported back whole | pre-existing; the plan keeps both buttons | A2.1 |
| D-7 | Saved views are keyed by module `products` or `services`; the unified list needs one key and a rule for the old views | views of the old Services module become unreachable otherwise (usage counts: auditor F) | C5 row 4 |
| D-8 | `DELETE /portfolio/offers/{id}` hard-deletes an About and its ICPs and turns a sold Sub-product into a catalogue item, against the rule "no remove-About" | the unified page needs a defined Delete | C3 row 5 |
| D-9 | `DELETE /signals/icp/products/{key}` deletes a Product, its ICPs and their versions from the database; the owner said no table content is deleted | needs a button and a rule on the Product page | C4 row 2 |
| D-10 | `DELETE /crm/products/{id}` has no caller in the UI; where does one record go to the bin from the unified page (the plan says Sell off is "Paused, never delete")? | the Recycle Bin and bulk delete still exist | C1 row 5 |
| D-11 | Adopt the four H1 extensions and the H3 About guard, or say why not | the in-flight guard would have missed G-6 and G-7 | H |
| D-12 | Wording: mock-up view 4 captions the unit price "(from the price book)"; the stored value is the catalogue's own base price and a price book only overrides it on a quote (the S1 drawer says so, `product-master-form.tsx:281`) | a person could believe the field is read-only | A1 row 10 |

---

## What in the plan or the earlier reports is wrong, or could not be verified

**Checked and TRUE [V]:** `GET /api/product-master/lines` exists (`ProductMasterController.php:118`, route `routes/product_master.php:29`); the Usage tab does read quotes (`ProductMasterController.php:299-302`); the Excel sync button exists (`portfolio-view.tsx:322-325`, "Sync Excel", in an overflow menu, shown to `canManage`); `crm_products` has 12 pointer columns registered and the 31 columns on dev equal the migrations; the controllers' claimed defects (history without a right check, line before the add right, the 100-row cap) are as the plan describes (the first two are fixed by P2-B1).

**Wrong or overstated in the plan [V]:**
1. "The Portfolio's 'bundles with' is renamed 'Often sold together'" (plan 3d, mock-up): no screen shows or edits `bundles_with` today; it is an API and Excel field. The control is new (D-3).
2. The mock-up's About card says "(from today's Portfolio form)" and lists 10 rows; today's form has six more fields (G-4).
3. "`ProductLines::create` is THE one place a Product is written" (P2-B1 work log): `POST /signals/icp/products` still creates through `ProductCatalog::createCustom` (`ProductIcpController.php:37`); true only for the writer and the CRM inline line (D-5).
4. "Never deleted: the CRM row (11 tables point at it)" (plan section 6, P2-D): true of the pair merge, false of the Recycle Bin's permanent delete (G-1).
5. The Usage tab as drawn in the mock-up ("quotes, orders, invoices, price books and signals") is more than `usage()` returns (G-3).
6. "History: now includes About edits" (mock-up) has no route behind it (G-2).
7. The plan, the P0 measurements and the P2-A registry do not mention that the migration `2026_10_27_200000_add_icp_config...` is not applied on dev (`g2g_product_offers.icp_config` absent, A3.1 row 29). The code tolerates it; nothing else is affected.

**Not wrong, but missing from the plan:** `g2g_product_profiles` is one row per organisation (the company profile, not a product record), so it needs no home (A3.4); no front-end caller exists for `DELETE /crm/products/{id}` or for `GET /portfolio/stats`.

**Could not verify:**
- Anything on the `live` host (column lengths, whether `icp_config` exists there, MariaDB 10.1 behaviour): never read, as ordered.
- Whether `CatalogueFieldsScanTest` and the other uncommitted P2-C and P2-D tests pass: I ran no PHPUnit (it writes cache files and I may write one file). I read their source.
- A caller that builds its URL from a variable would not show in my greps ([I] for "no other callers").
- No browser run: what a screen shows at 390 px or per role is auditor B's work and the P5 acceptance.
- The uncommitted files (`CatalogueFields.php`, `ProductWriter.php`, `CrmProductController.php`, `routes/product_master.php`, `ProductMasterController.php`, `CatalogueFieldsScanTest.php`, the P2-D files) may have changed after I read them; line numbers into them are as read on 13 Nov 2026.

