# S15 P0.3 - read-only measurements on the DEV database (13 Nov 2026)

Method: `php artisan tinker --execute=...` with SELECT only, connection `mysql`, host 202.47.117.220 (dev). Nothing was written. Tag: **verified** (query output read), unless noted.

## Master rows (`g2g_product_master`) by tenant / kind / status
| tenant | kind | status | rows |
|---|---|---|---|
| 0 (shared) | product (the 5 lines) | active | 5 |
| 1 | sub_product | active | 25 |
| 6 | item (CRM only) | active | 9 |
| 6 | sub_product (offer) | active | 25 |

No master row holds both `offer_row_id` and `crm_product_id` (tenant 6: 25 with an offer, 9 with a CRM row, 0 with both).

## `crm_products` (all rows are tenant 6)
| type | active | in the Recycle Bin (deleted_at set) | rows |
|---|---|---|---|
| product | 1 | no | 7 |
| product | 1 | yes | 2 |
| service | 1 | no | 2 |
| service | 1 | yes | 1 |

So 9 live CRM rows (ids 63-71, matching the 9 `item` master rows) and 3 binned legacy rows (ids 6-8: "Enterprise License", "Onboarding Support", "ZZ UI Widget"). The earlier "3 strays with no line" are already in the Bin; only the 9 live rows need pairing. `g2g_product_offers`: tenant 1 = 25, tenant 6 = 25.

## Rows that reference a product (usage) and orphans
| table | rows with a product | orphans (point at a missing `crm_products` row) |
|---|---|---|
| crm_quote_line_items | 33 | 0 |
| crm_sales_order_line_items | 16 | 0 |
| crm_invoice_line_items | 16 | 0 |
| crm_opportunity_products | 20 | 0 |
| crm_price_book_items | 27 | 0 |
| crm_purchase_order_line_items, crm_tickets, crm_assets, crm_faqs, crm_product_bundle_items | 0 each | 0 |

These counts are the "before" snapshot for the P2 checker (`product-master:check`): they must be identical after the unify step.

## Menu rows (dev ids)
468 Products `/module/crm/sales/products` (status 1, sort 40) - 469 Services `/module/crm/sales/services` (1, 41) - 471 Price Books (1, 42) - 489 Market Signals `/module/crm/signals` (1, sort 11) with children 490 Company Opportunities `/signals`, 491 Ingestion Engine, 492 Product Portfolio `/portfolio`, 493 Partner Network, 501 Signal Inbox - 506 Signal Review `/signal-review` (status 1, parent 199, sort 12). All have `status = 1`.

## Rights on 468 / 469 / 492 / 489 / 490 / 506 (can_view, add, edit, delete; raw `sub_institute_id` stamp)
- **Admin-type profiles** (1, 4, 7, 10, 13, 16 [tenant 6], 19, 25, 28, 31, 114, 146, 149): 468 and 469 = 1111, and 489/490/492 = 1111 (profile 16 has only view on 489). **Only profile 16 has 506.**
- **HR-type profiles** (2, 5, 8, 11, 14, 18 [tenant 6], 21, 27, 29, 33, 116, 148, 151, and 41, 47, 53, 59, 65, 71, 77, 89, 95, 101): 489/490/492 = 1110, **no 468/469/506**.
- **Third type** (39, 45, 51, 57, 63, 69, 75, 87, 93, 99): 489/490/492 = 1010, no 468/469.
- **No profile on dev holds 468 or 469 without also holding 489 and 492.**

## What this means for the plan
1. The Sell section (prices, cost, commission, stock, bundle, history) keyed on the existing 468/469 rights separates exactly the admin-type profiles from HR-type and the third type: nobody who can see the Portfolio today gains access to prices.
2. The retargeted sidebar entry has no "door lost" case on dev (every Products holder also has Market Signals). The rights audit command must still check live.
3. Rights rows exist with several stamp formats (`'6'`, `'1000010'`); never scope a grant or a revoke on `sub_institute_id`.
4. The unify step has 9 live CRM rows to pair with the 25 tenant-6 Sub-products; tenant 1 has 25 Sub-products and no CRM rows (nothing to unify there).
