# S1 design-gate checks — 2026-11-02

Read-only queries on live (`SET SESSION TRANSACTION READ ONLY`, SELECT only) and source reading, run to answer the owner's "check by yourself" and to design the Product Master from real data. Scripts: `D:\dev\agentic-crm-research\scripts\g2g\s1_followup.php`, `s1_followup2.php`, `s1_offers.php`. Counts, ids, flags and business names only: no keys, hosts, credentials or personal data. All **verified** (queries run, output read) unless marked. Live is being edited by the owner while this runs, so counts are point-in-time.

## The 32 live signals with `business_fit` empty (`g2g_company_opportunities`, `is_sample = 0`)

- All 32 were first discovered in October 2026 and all are still `review_status = 'New'`. Tenants: 10 are tenant 0 (shared), 22 are tenant 6.
- Their research runs all have `product_key` NULL (runs before the product key existed), and 16 have an ingestion source, also with NULL `product_key`.
- **None carries a product hint**: no `sub_product`, `business_fit` or `product_fit` key in `assessment` for any of the 32 (4 of the 47 populated rows have `sub_product`).
- **11** (tenant 6) have `relevant_offer_id = BUN-01` (offer "SME Capability + Intelligence Bundle", parent product `Bundle`); all are `signal_kind = public_reporting`, `product_name` NULL.
- **11** (tenant 6) have no offer and a company-level product text: "TRIZ Innovation — AI, Enterprise Intelligence & Digital Solutions" on 6 and "Enterprise Solution" on 5 (the counts agree: 21 NULL `product_name` rows = 11 BUN-01 + 10 tenant-0 rows; 11 tenant-6 no-offer rows carry the two texts). Kinds: public_reporting 5, opportunity 4, insight 2.
- **10** (tenant 0) have no kind, no offer and no product text.
- **Why they are empty (verified in source):** `QualificationGate::BUSINESS_FIT = ['eb','scholar','g2g','multiple']` and `OpportunityController.php:451` validates `nullable|in:eb,scholar,g2g,multiple`, so the Bundle and HE products cannot be stored in `business_fit` at all.
- **Decision (assistant, delegated by the owner):** S2's `product_master_id` backfill assigns product by this order: (1) `business_fit` through the alias resolver, (2) `relevant_offer_id` to the offer's product, (3) otherwise NULL = "no product yet". That places 40 (37 scholar + 3 eb, all organisations) + 11 (BUN-01) = 51 of 72; the other 21 stay NULL and appear under a "No product yet" filter for a reviewer. No guessing from the company-level text.

## Live Product Portfolio (tenant 6): 22 offers (21 at baseline time; one was added the same morning)

| Product (`parent_product`) | Count | Sub-products (offer_id, name, readiness) |
|---|---|---|
| Scholar K-12 | 6 | P1 Scholar One, P2 Scholar Core, P4 Scholar CCB, P5 Scholar Insight, P6 Scholar Compass, SCH-11 Scholar Learn: all "Live – with client" |
| EB (Enterprise Brain) | 6 | **EB** "AI-powered Enterprise Intelligence Platform" (Live – with client; created 2026-11-02 12:45; its offer_id equals the product key), EB-01 Enterprise Brain Intelligence Layer (Proven on real client data (pilot)), EB-02 Department & Person Intelligence (In development), EB-03 Data Ingestion Engine, EB-05 KASBA Scoring & Gap Analysis (Built – no production client), EB-04 Knowledge & Memory + AI Assistant (In development) |
| G2G | 7 | G2G-01 G2G Core, G2G-02 G2G Capability, G2G-03 G2G Learn, G2G-04 G2G Work, G2G-05 G2G Talent, G2G-06 G2G insight intelligence, G2G-07 G2G One: all "Live – with client", edited 2026-11-01 to 11-02 |
| Bundle | 2 | BUN-01 SME Capability + Intelligence Bundle, SCH-08 School Bundle: Proposal / concept |
| HE | 1 | HE-01 Higher Education ERP: Deferred |

No duplicate `offer_id` within a tenant; no shared (NULL-tenant) offers. Three ID schemes are in use (`P#`, `SCH-##`, `G2G-##`/`EB-##`), P3 is absent, and `EB` is both a product key and an offer id. S1's backfill reports these and renames nothing.

## Prices (owner: a quote is priced from a price book; price books connect to products and services)

- **Verified (source comment, `CrmPriceBookController.php` lines 17–23):** a quote may select one price book; a newly added line item takes the price from that book's `crm_price_book_items` row for the product, falling back to the product's base `crm_products.unit_price` when the book has no row for it (done in `CrmQuoteController::saveLineItems()`). Price books list products and services (`crm_products.item_type`).
- **Verified (live counts):** `crm_price_books` 0, `crm_price_book_items` 0, `crm_products` 0, `crm_quotes` 0. The pricing side is empty, so there is no legacy data to fit.
- **Verified (source):** `QuoteBuilder` (the lead-automation "prepare quote" action) reads `p.unit_price` directly and ignores price books (`QuoteBuilder.php` lines 39, 43, 71). Noted for S11 and S13; not a defect today because no quotes or products exist.
- **Design consequence:** the Product Master shows prices read-only on a Prices tab (price books that contain the item, with the price in each) and keeps a base price as the fallback; prices are edited in Price Books.

## Email, SMS and WhatsApp (owner: email master set up; SMS and WhatsApp not confirmed)

- Live and dev agree: `school_setup.outbound_email_enabled = 1` only for tenant 6 ("Scholar Clone").
- `smtp_details` on live: tenant 1 and tenant 6 each have one profile, module `default` (no CRM-specific or Sales-Outreach-specific profile yet). This matches the owner's screenshot (Settings › Email & SMS: "Main mailbox", Organisation default, Configured; SMS "Not set up").
- `sms_api_details`: one active row on each host, for **tenant 3 ("Healthcare")**, not tenant 6.
- WhatsApp: no sender found in CRM or GTM code (subagent report; a `ReportMobileSender` exists for report delivery).
- Not measured: the production `.env` flags (`G2G_NOTIFY_EMAIL`, `CRM_EMAIL_SENDING_ENABLED`, `GTM_OUTREACH_SENDING_ENABLED`, `GTM_OUTREACH_SCHEDULER_ENABLED`), the queue driver, the scheduler cron.
- **Decision (assistant, delegated): D6** needs no master switch now. SMS is already opt-in per organisation through its gateway row (one organisation has one). The agent layer has no SMS tool (S7), and consent and caps are added in S10 before any outreach can use SMS.
