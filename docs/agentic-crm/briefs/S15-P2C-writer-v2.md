# Track S15-P2C: the one-record writer (hp_erp, backend)

Read `COMMON-RULES.md` first, then `../DECISIONS.md` (entry 2026-11-13), `../slices/S15-product-portfolio-merge.md` (plan sections 3, 4 and 5 P2.4, and EVERY work log and caller note in it: P2-A, P2-B1, P2-B2 say what exists now) and `../_evidence/s15/p0-measurements-2026-11-13.md`. Written 13 Nov 2026. Start only after P2-B2 is committed: run `git log -5` and `git status` and read what B1 and B2 changed (`ProductLines`, `ProductPointers`, `ProductIntegrity`, `OfferIcpSync`, the mirror method B2 added, `CrmProductController`).

## Goal
A Sub-product (level 2) is ONE record. It may hold an **About** (the Portfolio offer row), a **Sell** section (the CRM catalogue row) and its **ICPs**, each optional, all on the same `g2g_product_master` row (`offer_row_id` AND `crm_product_id` on one row). One call writes whatever sections it carries, in ONE database transaction, and a failure anywhere leaves nothing behind. This is the only write path the unified screen (P3) will call. The old S1 drawer (`product-master-form.tsx`) and the old endpoints keep working unchanged until P3 ships.

## Contract (request, `POST /api/product-master` and `PUT /api/product-master/{id}`)
```
kind: 'sub_product' | 'product' | 'item'   (item = Sell only, kept for the old drawer; the new screen sends sub_product)
name, productId (the level-1 Product, a visible kind-product row), offerId (required when About is present on create; immutable once About exists)
readiness (required when About is present)
about: { whatItDoes, modulesComponents, deploymentModel, proofPoints, notes, needsSolved[], primarySegments[], triggerSignals[],
         pricingModel, typicalDealBand, implementationEffort, deliveryOwner, oftenSoldTogether[] }      -> the 16 g2g_product_offers columns
icp: the same payload PortfolioController sends as icp_configs / icp_config                              -> OfferIcpSync::sync (P2-A)
sell: { enabled, type 'product'|'service', sku, category, glAccount, taxRateId, unitPrice (alias basePrice), costPrice, currency, commissionRate,
        vendor, usageUnit, qtyInStock, qtyInDemand, reorderLevel, weight, assignedTo, isActive, descriptionOnQuotes }
```
The writer today takes 8 of the 16 About fields (`ABOUT_MAP`), no ICP, and 6 of about 19 sell fields (`sellInput`: type, sku, glAccount, taxRateId, basePrice, currency). Read `PortfolioController::store` for the exact offer rules (offer_id unique across the tenant and shared offers, readiness never confirmed here, `bundles_with`, lengths) and `CrmProductController::payload`/`store` for the exact catalogue rules; the writer must enforce the SAME rules (extract them: see Task 1). `basePrice` stays accepted as an alias of `unitPrice` (the old drawer sends it). `oftenSoldTogether` is the new name of `bundles_with` (the Bundle tab means a priced composition, `crm_product_bundle_items`, and is NOT written here).

## Semantics (the owner's decisions of 13 Nov, `DECISIONS.md`)
1. **At least one section** must be present on create (About, or Sell with `enabled`); with neither the answer is 422. Sell-only creates a master row of kind `item`; adding About to an `item` later flips it to `sub_product` (same `product_key`, `offerId` and `readiness` required then); there is no way back (no "remove About").
2. **Sell states: not sold, sold, paused, in the Recycle Bin.** `sell.enabled = true` on a row with no CRM pointer creates the catalogue row, EXCEPT when the master row still points at a binned CRM row: then that row is restored (clear `deleted_at`), never a second one inserted. `sell.enabled = false` on a sold row means Paused (`is_active = 0`), never a delete (11 tables point at the row); the old 409 "not supported yet" goes away. `sell.isActive` sets the flag explicitly. A paused or binned row is not offered to new documents; old documents still show it.
3. **One name, one parent, mirrored.** Use the mirror method P2-B2 added so the master row, the offer row and the CRM row change name and Product together, in the same transaction, from this door as from the old ones. `crm_products.product_master_id` stays "the Product (level 1) id".
4. **Rights by section, not by route.** About and ICP need `ProductAccess::canWriteOffers` (add on create, edit on update); Sell needs `canWriteCatalogue` for its type; a new Product (level 1) needs an administrator (`ProductLines`); each refusal is a 403 naming the section and writes NOTHING, also when another section of the same request would have been allowed (assert row counts). Change `routes/product_master.php` so the write routes need only the signed-in token (`api.token`) and the writer decides; keep reads as they are (P2.5 re-gates reads). A person with no right on any section present gets 403, not 422.
5. **A response that does not leak.** The response carries `sell` only when the caller may view the Sell section (the Products/Services right for that type); HR gets the About, never prices, cost or commission.
6. **Audit.** Every store changed writes its audit line: the CRM row through the existing `recordCrmAudit` pattern, the offer row and the master row too (About edits are not audited today). Events through `EventRecorder` AFTER the commit. Failures in the optional steps (events) never roll back the write; failures in any store do.
7. **Atomic.** Force a failure in each store in turn (a test hook or a failing ICP payload) and assert the other stores are unchanged. Image, custom fields and bundle items stay separate calls keyed by the CRM id; the response returns `sell.crmProductId` for them.
8. **Readiness** is never confirmed here (unchanged: `readiness_confirmed = false`; confirmation stays the admin action in `PortfolioController`). Shared rows (organisation 0: the built-in Products, offers with a NULL tenant) can never carry Sell: refuse with 422.

## Tasks (in this order, saved in small increments)
1. Extract the catalogue field map and rules out of `CrmProductController::payload`/`store`/`update` into a class in `app/Domain/ProductMaster/` (for example `CatalogueFields`) used by BOTH the controller and the writer, with NO behaviour change for the controller (its suites stay as they are).
2. Extend `ProductWriter` (create and update for the three kinds) to the contract. Keep the public method names the controller calls.
3. Re-gate the write routes (rule 4) and keep `ProductMasterController` responses compatible with the old drawer (every key it reads today stays).
4. Tests: new `tests/Feature/ProductWriterV2Test.php` (do not edit the old write test except where a rule changed on purpose, and say so): every combination (About only, Sell only, both, ICP, update of each section, item to sub_product flip, offer_id immutable), the four sell states including restore from the Bin without a second row, pause and resume, HR (About only, no Sell data in the response) and a sales-like profile (Sell only), shared rows refused, a refusal of one section writes nothing for all, atomicity per store, mirrors, audit rows, `ProductIntegrity::check()` clean after every write.
5. **Mutations** (prove the baseline green first; break, a named test fails, restore byte for byte, `cmp`; mutate the GUARDS too, not only the happy path): skip the restore of a binned row; skip a section's right check; write the sections outside the transaction; return `sell` to a caller without the right; flip the item to sub_product without the key staying; allow `offerId` to change; drop the audit line of the offer; drop the mirror; accept a shared row for Sell.

## Suites to run at the end (one process)
`ProductWriterV2Test`, `ProductMasterWriteApiTest`, `ProductMasterApiTest`, `ProductMasterTest`, `ProductMasterSyncTest`, `CrmProductProductMasterLinkTest`, `ProductWritePathDefectsTest`, `CustomProductApiTest`, `ProductPortfolioIcpTest`, `SubProductIcpOwnershipTest`, `RestoredModulesApiTest`, `CrmFullLifecycleGoldenPathTest`, `ProductIntegrityTest`, `ProductPointersScanTest`, `OfferIcpSyncTest`, `MariaDb101SafetyTest`.

## Do not
Edit migrations, menu rows, any front-end file, `phpunit.xml`; touch the live connection; add a table or a column (none is needed); commit or push; start a server. MariaDB 10.1 rules (`MariaDb101SafetyTest`). Use Write/Edit (no heredocs).

## Report (COMMON-RULES section 7) plus
A table of every rule 1 to 8 with the test that pins it and the mutation that killed it; the final request/response shape as built (a short example of each); open items for P2.5 and P3. Append a "P2-C" log to `../slices/S15-product-portfolio-merge.md`; evidence under `../_evidence/s15/p2c-*`.
