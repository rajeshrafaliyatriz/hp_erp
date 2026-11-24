# S15 follow-up (24 Nov 2026): products are private to an organisation, every Product and Sub-product can be deleted or moved, every Product and Sub-product can be chosen

The owner's words (24 Nov): "all main product and sub product should be deleteble or move under the other products; if user delete main product its sub products should be deleteble"; "all products and sub products should be selecteble, not sold and sold both"; and, about the built-in shared Products: "i login has tenant 7 where i see my tenant 6's main products are visible, it is the architecture gap, other organizations' products should not be shown to each other, please add this feature and fill the gap".

His answers to my questions: Delete a Product that has Sub-products = ask each time (move its Sub-products to another Product, or delete them together with it). Movable = one or several Sub-products. Selectable in: lead / campaign / opportunity forms; tickets, assets, FAQs, bundles; quote, order, invoice and price-book lines.

This file is the one contract the three tracks build against. Nothing here is built yet. A track changes only the files it owns (section 6).

## 1. Facts read in source on 24 Nov (dev database, SELECT only)

- `g2g_product_master`: tenant 0 holds 6 Products (shared, built-in); tenant 1 holds 25 Sub-products whose `parent_id` points at those shared Products; tenant 6 holds 41 rows (32 Sub-products, 9 items) and no Product of its own. 14 organisations exist (ids 1-10, 13-16).
- Rows that point at a shared Product come from tenant 6 only: crm_leads 99, crm_opportunities 20, crm_campaigns 3, gtm_outreach_messages 3, g2g_company_opportunities 59, g2g_research_runs 25.
- `g2g_product_offers`: tenant 1 has 25, tenant 6 has 32, none with a NULL tenant on dev. `g2g_signal_products` (custom Products): 1 row, tenant 6.
- Sharing is implemented in ONE scope plus a handful of hand-written copies: `ProductMaster::scopeVisibleTo` (own tenant + `SHARED = 0`), `ProductOffer::scopeForTenant` (own + NULL), and `[tenant, ProductMaster::SHARED]` arrays in `ProductListQuery` (lines 77, 230), `ProductPicker` (50), `ProductLines` (228), `ProductMasterSync` (415, 649), `ProductMatchSuggester` (293), `ProductMasterBackfill` (90, 102, 110, 206), `ProductIntegrity` (361-405), `ProductWriter` (63, 874: "a shared row is read only"), `ProductMasterController` (the `shared` flag), plus `ProductCatalog::definitionsFor()` which gives every organisation the four code-defined built-ins (`config/signals_products.php`: scholarclone, enterprise_brain, g2g, fiber_valley; the master also has `he` and `bundle`).
- DB-RULES item 4 says shared rows use tenant 0. This change retires that for product rows only; every other table keeps its own convention.

## 2. Decisions (the owner's, plus the defaults I chose and will report)

| # | Decision | Source |
|---|---|---|
| D-1 | A Product, a Sub-product, an item, an About (offer) and a custom Product belong to ONE organisation and are visible to that organisation only. No tenant-0 or NULL-tenant product row is visible to anybody. | owner |
| D-2 | The code-defined built-in Products belong to the organisations named in `config('product_isolation.builtin_owner_tenants')` (env `G2G_BUILTIN_PRODUCTS_TENANTS`, comma list, default `6`). Any other organisation sees only its own Products. | owner's words imply tenant 6; the list lets him change it without code |
| D-3 | A one-off data command re-stamps the existing rows (section 3). Dry run by default. Dev only until he says "go" for live. | DB-RULES item 8 |
| D-4 | Deleting a Product asks each time: "Move its Sub-products to another Product" or "Delete them together with it". | owner |
| D-5 | Deleting is all-or-nothing in one transaction. Anything in use blocks it with a plain sentence that names the number and the place (see 4.1), and the dialog then offers Archive (never loses data) or Move. | my default |
| D-6 | Move works on one or several Sub-products (and items) at once, from the Sub-product page, from the list (rows ticked) and from the Product page. | owner |
| D-7 | Every Product and Sub-product is OFFERED (listed) in every picker, sold or not. Where the thing stored is a master row id (lead, campaign, opportunity forms) a not-sold row is chosen like any other. Where the thing stored is the catalogue id (`crm_products.id`: quote, order, invoice, price-book lines; also tickets, assets, FAQs, bundles if their column is the catalogue id) a not-sold row is shown with the tag "Not sold in CRM" and cannot be chosen, because it has no price and no catalogue row to store. The track reports which consumer is which; it never invents a column. | owner asked "selectable"; the line-item reading is mine and is reported as an open point |
| D-8 | History: a move writes one `product.moved` event for each moved Sub-product (its own History tab shows "Moved from A to B"); a delete writes `product.removed` against the removed id (it is gone from the screens, but the event store keeps it). Events go through the existing recorder; nothing writes `g2g_audit_log` directly. | owner asked for working History |
| D-9 | The 23 Nov refusal "a Product with Sub-products cannot be deleted" (409 `product_has_sub_products`) is replaced by D-4. | supersedes DECISIONS D-9 |

## 3. Track A: isolation (hp_erp, backend only)

Rules:
- `ProductMaster::scopeVisibleTo($tenant)` returns the organisation's own rows only. `ProductMaster::SHARED` stays defined (the checker uses it to find leftovers).
- `ProductOffer::scopeForTenant($tenant)` returns the organisation's own rows only (a `null` tenant keeps meaning "no organisation": `whereNull`).
- Every hand-written `[tenant, SHARED]` copy listed in section 1 becomes `[tenant]`, except `ProductPicker.php` (track B owns that file and applies the same rule).
- `ProductCatalog::definitionsFor($tenant)` includes a built-in key only for an organisation in `product_isolation.builtin_owner_tenants`. A tenant of 0 or null (code paths with no organisation) keeps today's behaviour. `isBuiltIn()` and `definitions()` themselves are unchanged. Find every other reader of the built-ins (`grep "definitions()\|isBuiltIn("`; `BackfillSignalProducts`, `ProductMasterBackfill`, `ProductLines`, `ProductIcpController`) and decide each one in the track's report: the backfill creates the built-in Products for the OWNER organisations only, never tenant 0.
- A shared row can no longer exist, so the "shared, read only" guards in `ProductWriter` (63, 874) become unreachable for new data; keep them only if a test needs them, otherwise remove with their tests, and keep the `shared` field in the API answers (always `false`) so the front end does not change.
- New command `php artisan product-master:isolate-tenants {--owner=} {--apply} {--rollback=}`. Dry run prints, per organisation, what it would do and writes nothing. `--apply` makes a before-image file in `storage/app/product-isolation/` first (every row it will touch, as JSON, including the pointer rows it repoints), then runs one transaction per organisation. Steps: (1) each tenant-0 Product row gets `sub_institute_id = owner`; if the owner already has a Product with the same `product_key`, the owner's row wins: its children and pointers are moved to it and the tenant-0 row is deleted. (2) For every OTHER organisation that has Sub-products whose `parent_id` is a tenant-0 Product, create that organisation's own Product (same key, name, signal_key, portfolio_name, active) once, repoint `parent_id` and every pointer from that organisation through the existing `ProductPointers` registry. (3) A NULL-tenant offer gets the owner. (4) Anything it cannot place is listed and left alone. It never touches `crm_products` (Sell is already per organisation), and refuses to run when the connection is not `mysql` or sqlite in tests unless `--connection=` is given explicitly. `--rollback=<file>` restores the before-image exactly. Idempotent: a second run reports "nothing to do".
- `product-master:check` gains one rule: a tenant-0 master row, or a NULL-tenant offer, is a problem named `not_isolated` (count and ids). After the command it is 0.
- Signals check: read what `MatchingEngine`, `IcpPack`, `SignalsTenantOverview`, `ProductIcpService`, `ResearchRunScope`, `FeedFilters` do for an organisation that no longer has the built-ins, and list every behaviour change in the report (tenant 1 is the one real case: its offers have `parent_product` of the built-ins). Nothing in Signals may throw for an organisation with zero built-ins.
- Tests: a two-organisation test per reader (list, facets, options, picker input, lines, resolver, matching suggester, integrity, offers list, Portfolio list) proving organisation B never sees organisation A's Product, Sub-product, offer or custom Product, and that the owner still sees its own; command tests (dry run writes nothing, apply stamps, copies, repoints, rollback restores, second run is a no-op); the checker rule. Existing tests that assumed sharing are rewritten on purpose and listed in a test map in the report. Mutation proofs: every safeguard (the scope, each hand-written copy, the offer scope, the built-in visibility, the command's owner-wins branch, the pointer repoint) gets a mutation that a test kills.

## 4. Track B: delete, move, pickers (hp_erp, backend only)

All new routes live in `routes/product_master.php`, behind the same middleware as the archive route. Rights: the same gate as archive for the record's kind (administrators for a Product; a person who may edit that section for a Sub-product), checked per record, never trusted from the body. Every id is looked up with `ProductMaster::visibleTo($tenant)` so another organisation's id answers 404. Answers use the existing envelope `{status, message, data}`; the HTTP codes are 200, 403, 404, 409, 422.

### 4.1 Preview: `GET /api/product-master/{id}/removal`

```json
{ "status": 1, "data": {
  "id": 12, "kind": "product", "name": "ScholarClone",
  "canDelete": false,
  "blockedBy": [ { "code": "sold_on_documents", "count": 3, "message": "3 quotes or orders hold it." } ],
  "subProducts": [ { "id": 31, "name": "Fee module", "kind": "sub_product", "canDelete": true, "blockedBy": [] } ],
  "targets": [ { "id": 7, "name": "Enterprise Brain" } ]
} }
```
`blockedBy` codes (each with a plain `message` a non-technical person reads): `sold_on_documents` (a quote, order, invoice, purchase order or price book holds the Sell row; counted by `ProductUseCount`), `records_point_at_it` (leads, opportunities, campaigns, tickets, assets, FAQs, bundles, outreach point at it; counted by the `ProductPointers` registry), `offer_in_use` (an ICP lists the About), `has_sub_products_blocked` (only on a Product: at least one Sub-product is blocked; the Sub-product rows say which). `targets` are the organisation's other ACTIVE Products (kind `product`), never the record itself; for a Sub-product they are the Products it may move to; for a Product they are the Products its Sub-products may move to.

### 4.2 Move: `POST /api/product-master/move`

Body `{ "subProductIds": [31, 32], "toProductId": 7 }`. Each id must be a Sub-product or item of this organisation; the target an active Product of this organisation (422 `invalid_target` otherwise; 422 `nothing_to_move` for an empty list; 404 for an id not visible). One transaction: `parent_id` changes and the About's `parent_product` is written so the two agree (the I5 invariant the `product-master:check` already tests; reuse the path `ProductWriter` uses when the form changes the parent, do not write the two columns by hand). The pointers of a Sub-product (leads, campaigns, opportunities) are on the Sub-product's own id and do not change. Moving to the Product it is already under is a no-op reported in `unchanged`. Answer `{ "status": 1, "data": { "moved": [31], "unchanged": [32], "toProductId": 7 } }`. One `product.moved` event per moved row with `{from, to}` names in the payload.

### 4.3 Remove: `POST /api/product-master/{id}/remove`

Body for a Product: `{ "subProducts": "move", "toProductId": 7 }` or `{ "subProducts": "delete" }`; for a Sub-product or item an empty body. A Product that has Sub-products and no `subProducts` choice answers 422 `choose_sub_products`. One transaction; all or nothing: if anything is blocked (4.1) the answer is 409 `{ "status": 0, "code": "blocked", "message": "...", "data": { "blockedBy": [...], "subProducts": [...] } }` and nothing changed. What "delete" removes, per row: the master row, its About (offer), its ICP rows keyed to that offer, and sends its Sell row to the Recycle Bin (soft delete, the existing "Delete from Sell in CRM" path) ONLY when no document holds it; a Product also removes its own custom-Product row (`g2g_signal_products`); for a built-in Product it writes the organisation's `deleted` override so `definitionsFor()` stops listing it. Answer `{ "status": 1, "data": { "removed": [12, 31], "moved": [32], "toProductId": 7 } }`. One `product.removed` event per removed row. Old routes (`DELETE /signals/icp/products/{key}`, `DELETE /portfolio/offers/{id}`) keep working and keep their guards; their 409 `product_has_sub_products` is removed per D-9.

### 4.4 Pickers

- `GET /api/product-master/picker` gets `?scope=all` (default stays `sold`: nothing that calls it today changes). With `scope=all` it also returns active Sub-products and items that have no catalogue row, a paused row or no price; every row gains `"sold": true|false` and `"state": "sold"|"paused"|"not_sold"`, and `crmProductId` is `null` when there is no catalogue row. A row in the Recycle Bin is never offered. Rights: a caller who may not view Sell sees the same rows with `unitPrice` null, as today. Isolation rule (D-1) applied inside the file.
- `GET /api/product-master/lines` gets `?withSubProducts=1`: every active Product and, indented under it, its active Sub-products and items. Each row `{ "id", "key", "name", "kind": "product"|"sub_product"|"item", "parentName": string|null }`; the default answer is unchanged.
- The forms that store a Product id today (lead, campaign, opportunity) must accept a Sub-product's master id too: read what `CrmLeadController`, the campaign and opportunity controllers validate and store, and extend the validation to "an active Product, Sub-product or item of this organisation" if it checks `kind = product`. The Product facet already matches a Product's Sub-products (`ProductFacet`); a lead pointing at a Sub-product must show under the facet of its Product and of itself.
- Report, for each of the roughly 20 consumers of `SubProductPicker` and the four level-one picker callers, what id it stores (master id, catalogue id, or text), so D-7 is applied exactly and not guessed.
- Tests per section, a mutation per safeguard (rights, tenant lookup, transaction rollback on a blocked child, parent_product agreement, soft-delete only when no document holds it, the move no-op, `scope=all` not leaking into the default).

## 5. Track C: front end (g2gv0, branch `milan1`, no backend)

Built against the shapes in 4.1 to 4.4 with the services' fetch-level tests pinning address, method and body (the pattern of `lib/product-picker/picker-service.test.ts`).

- Services: `productRecordService.removalPreview(id)`, `.remove(id, body)`, `.move(body)` in `services/product-master/`, and the two new picker parameters on `productPickerService`.
- Sub-product page: a "Move to another Product" action and a "Delete" action (next to Archive). Delete opens a confirmation that names the blockers when the preview says it cannot (with "Archive instead" as the button). Move opens a dialog with the Product list from `targets`.
- Product page: a "Delete product" action opens the ask-each-time dialog: the Sub-products listed (blocked ones marked), two choices (Move them to another Product [a select] / Delete them together with it), the confirm button disabled until a valid choice, and the blocked case shown as plain sentences with "Archive instead". After success go to the list.
- List (`PortfolioList`): with rows ticked, a bulk "Move to another Product" (Sub-products and items only; the action is hidden when a Product row is ticked), and a per-row "Delete" in the row menu for a person with the right.
- Pickers: `SubProductPicker` gets a `scope` prop (default `sold`, unchanged for every caller that does not opt in). The consumers D-7 puts on "catalogue id" show not-sold rows with the tag "Not sold in CRM", unselectable; the consumers that store a master id offer every row. The level-one lead / campaign / opportunity box becomes a grouped list: Product, then its Sub-products and items indented, "Not sold" tag where `sold` is false; selecting a Sub-product stores its master id. The lead facet and the forms show the chosen Sub-product with its Product ("Fee module, under ScholarClone").
- Words: Product, Sub-product, "Not sold in CRM". Never Catalogue, Offer, Line, Item as a noun in a visible string (the vocabulary scan enforces it). `components/ui` and `components/shell` are not edited.
- Gates: `node node_modules/typescript/bin/tsc --noEmit -p .` (0), `node node_modules/eslint/bin/eslint.js --max-warnings=0 <changed files>`, the whole suite `node --import tsx --test` (note the baseline count first: 3,758 on 24 Nov), a mutation proof for each new rule (blocked sentence shown, Move hidden for a Product row, scope default unchanged, not-sold unselectable where D-7 says, selecting a Sub-product stores its master id).

## 6. File ownership (no track edits another track's file; a needed change there goes in the report)

- Track A: `app/Domain/ProductMaster/*` except `ProductPicker.php`; `app/Domain/Portfolio/ProductOffer.php`; `app/Domain/Signals/**`; `app/Console/Commands/*Product*`, `BackfillSignalProducts.php`; `app/Http/Controllers/Api/ProductMaster/ProductMasterController.php`; `config/product_isolation.php` (new); tests for those; the evidence under `docs/agentic-crm/_evidence/s15/isolation-2026-11-24/`.
- Track B: `app/Domain/ProductMaster/ProductPicker.php`; new `app/Domain/ProductMaster/ProductRemoval.php`, `ProductMover.php`; new `app/Http/Controllers/Api/ProductMaster/ProductRemovalController.php`; `routes/product_master.php`; `app/Http/Controllers/Api/Signals/ProductIcpController.php` (D-9 only); `app/Http/Controllers/Api/Portfolio/PortfolioController.php` (D-9 only if it mentions Sub-products); the lead, campaign and opportunity controllers' product-id validation; tests for those. A new file in `app/Domain/ProductMaster/` is fine; an edit to an existing one that is not listed is not (report it).
- Track C: g2gv0 only.
- Me: the contract, the runbook (`S15-CUTOVER-RUNBOOK.md`), `DB-CHANGELOG.md`, `00-PROGRESS.md`, every commit, the dev data run, the browser verification.

## 7. Rules for every track (same as the whole S15 effort)

- Never the live database or server (db3, 128.199.17.97). Dev database (202.47.117.220) SELECT only; a test that needs a write uses the in-memory sqlite. Never send a message, email or SMS. Never print a secret or token.
- Temp files only in the scratchpad `C:\Users\MILAN\AppData\Local\Temp\claude\c--Users-MILAN-Downloads-hp-erp\7e204d2f-9146-4ff7-82c9-07d5c31d4568\scratchpad\`.
- One phpunit process at a time, only through `bash D:/g2g-wpa1-work/phpunit-lock.sh <files>` (use `--colors=never`). Never `npx` in g2gv0.
- Do NOT commit, push, create branches or run `git add`: I review every hand-back, re-run it, mutate it, and commit by explicit path. Do not start agents. Preserve each file's existing line endings (many are CRLF).
- MariaDB 10.1 safe SQL only (no JSON functions, no window functions, no CTEs, no `SKIP LOCKED`); lengths validated in code.
- Evidence is plain text under `docs/agentic-crm/_evidence/s15/`; no screenshots or binaries in hp_erp.
- Report: what changed (files), each decision applied, the test map (every existing test that changed and why), the mutation table (mutation, which test killed it), every command you ran with its result, and every point you were unsure of. Tag claims verified / read in source / inferred / unverified.
