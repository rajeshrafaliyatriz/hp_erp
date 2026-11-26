# Remove Purchase Orders and Vendors from the CRM workflow: what is there today (read-only, 26 Nov 2026)

Nothing was changed. Every number came from a SELECT or a search of the code, run on dev (`mysql`, 202.47.117.220) and on live (`live` = db3, 128.199.17.97). The two hosts are IDENTICAL for everything below. Probe scripts: scratchpad `po1.php` to `po4.php`.

## The data footprint (verified, same on dev and db3)

| what | rows |
|---|---|
| `crm_purchase_orders`, `crm_purchase_order_line_items`, `crm_purchase_order_status_history`, `crm_vendors`, `crm_vendor_contacts` | 0 each |
| menu rows 452 Vendors (`/module/crm/sales/vendors`) and 454 Purchase Orders (`/module/crm/sales/purchase-orders`), parent 446, status 1, no children | 2 (+ 14 rights rows each = 28) |
| `crm_picklist_values` with module `purchase_orders` (the PO stage list) | 5 |
| `reports` with primary module `crm.purchase_orders`: ids 33, 34 (tenant 6) and 61, 62 (tenant 1); no schedules, no shares; folders 15 and 27 "Purchase Order Reports" | 4 reports, 2 folders |
| any column anywhere that points at a vendor or a purchase order (information_schema scan, non-LMS tables) | only the five tables above and the free-text `crm_products.vendor` (empty) |
| custom field values, saved views, comments, email links, consent rows for these modules | none |

No vendor report exists (the seeder dropped "Vendors Email Report"). Nothing is lost by removing the tables: they never held a row on either host.

## Traps found (read in source)

1. `lms_vendors`, the routes `/lms/governance/vendors*`, `use-governance.ts`, the LMS `Vendor` type: a DIFFERENT feature (training suppliers). Must stay.
2. `crm_products.vendor` (free-text on a product) and the portfolio product form's vendor field: a field of the product, not the Vendors module. Stays.
3. A plain search for "vendor" also hits the Composer `vendor/` directory and AI model "vendors" (`ModelManager.tsx`, `packages/ai-intelligence-core`, `lib/intelligence/model-price.ts`), "vendor_phrases", a signal type "vendor_selection". None of these is the CRM module.
4. Shared registries that name the PO and vendor tables and have scan tests that read `information_schema`: `ContactReferences`, `OrganisationReferences`, `ProductPointers` / `ProductUsage` / `ProductRemoval` / `ProductUseCount` / `ProductMatchSuggester` / `ProductMasterCheckCommand`. Removing the tables without editing these breaks the scans.
5. Seeded reports: `SystemReportSeeds` would put the four reports back on the next seed unless its Purchase Order block goes too; `CrmReportModules` defines the `crm.purchase_orders` and `crm.vendors` report modules.
6. Menu rows with status 0 lose their rights on the next Role and Permissions save, so the rows are deleted, not hidden (same as the GTM removal).

## Tags

verified = SELECT run today. read in source = grep or file read today.
