# S6: Leads (backend track S6a, front-end track S6b)

Plan: `../PLAN.md` section 7, S6. Written 4 Nov 2026 (02:35 IST). Status: **briefs written, tracks starting**. Dev only; live is never touched.

## Goal

Every lead, however it is born, gets a number that cannot collide, a score, an event, a product and an organisation account where they are known, and the evidence it came from; the existing Leads screen shows them. A lead never loses an opt-out when it becomes a contact (S4a already carries consent on conversion: `LeadConverter` line ~102, verified) and no placeholder contact is made when no person is known.

## Verified starting facts (4 Nov, read in source)

- `LeadFactory::create` (app/Domain/Crm/Leads/LeadFactory.php) is the number, score and event path; its callers are `CrmLeadController` (store line 194, import line 437), `PublicLeadCaptureController` (line 108) and `Signals\OpportunityController::promoteToCrm` (line 916). `nextLeadNo` is at line 46 (global maximum scan, no lock: defect D8).
- `CrmSignalInboxController` line ~301 still inserts a lead directly (`last_name = 'Signal Lead'`), skipping the factory (defect D3). On `origin/main` the Signal Inbox route is mapped to the team's Market Signals view, so the old inbox UI is gone but the endpoints exist.
- Consent carry-over on conversion exists (S4a). The lead reply processor already writes opt-outs through `ConsentRecorder`.

## Database (additive; DB-RULES in full; new files sort after `2026_12_11_090200_...`, name them `2026_12_11_10xxxx_...`)

- NEW `g2g_number_sequences`: `seq_key` varchar(60) (tenant-qualified key, e.g. `lead:6`), `next_value` BIGINT UNSIGNED, `updated_at`; primary key on `seq_key`. Atomic increment: on MySQL/MariaDB `UPDATE ... SET next_value = LAST_INSERT_ID(next_value + 1)`, on sqlite (tests) a transaction with a conditional update; the first use of a key seeds it from the highest existing number of that tenant.
- ALTER `crm_leads`: `product_master_id` BIGINT UNSIGNED NULL and `org_account_id` BIGINT UNSIGNED NULL, each with an index `(sub_institute_id, <column>)`; raw guarded `MODIFY` of `description` from TINYTEXT to TEXT (defect D9). Check `SHOW COLUMNS` on dev first; `crm_leads.sub_institute_id` is nullable (older rows), so every new query filters it explicitly.
- DATA: command `leads:backfill-links` (dry run by default, `--apply`, per tenant, summary to `_evidence`): sets `product_master_id` from `business_fit` through `ProductCatalog` / the Product Master resolver for leads that have `source_company_opportunity_id`, and `org_account_id` from the converted or linked organisation via the identity table. Never overwrites a non-empty value. A re-run changes nothing.

## Contract (S6a builds it, S6b reads it; additive fields only, nothing existing changes)

- `GET /api/crm/leads` and `GET /api/crm/leads/{id}`: each lead gains `product: {id, key, name} | null`, `organisation_account: {id, name} | null`, `source_signal_id: int | null`. `show` also gains `source_signal: {id, title, source_url, event_date, business_fit} | null` and `consent: {email: {allowed, reason}, sms: {allowed, reason}} | null` (through `ConsentGate`, entity type `lead`).
- List filters (query string): `product_id` (int; `0` or empty = all), `consent` (`allowed` or `blocked` for email), `has_signal` (`1`).
- The Evidence tab reads the EXISTING `GET /api/facts/entity/lead/{id}` (S4a) plus `source_signal`; no new endpoint.
- Product options for the filter: the EXISTING `GET /api/product-master/options` (S1).

## Agent hook (later, S6c)

`list_leads`, `get_lead` (read), `add_note` and `add_task` on a lead (autonomous per the owner default), `propose_lead_update` (approval), through the same gateway the Organisation agent uses once S7 generalises the subject. Not in this round.

## Acceptance

1. Two creates racing for the same tenant never produce the same number (a test that interleaves the claim, and one that runs the claim from two connections where the driver allows).
2. Every creation path (store, import, public capture, signal promotion, the signal inbox) yields a number, a score and a `crm.lead.created` event; the direct insert in `CrmSignalInboxController` is gone.
3. `promoteToCrm` sets `product_master_id` and `org_account_id` when they resolve, and writes ONE structured note (the quote, URL, date, fit) through the shared note path; a second promote of the same signal does not duplicate the note.
4. Conversion without a known person creates no placeholder contact whose last name is the company name; conversion with a person copies consent (already true) and facts (new: `FactRepoint` for `lead` to `contact`).
5. `description` accepts 2,000 characters without truncation on dev (verified with SHOW COLUMNS), and the validator caps the length at the new limit.
6. Cross-tenant: reading or filtering another tenant's lead by id or by `product_id` returns 404 or nothing; the wrong role returns 403.
7. The Leads screen shows a Product column and filter, a Consent chip, an Evidence tab and a source-signal link; no sidebar entry is added.

## Work log (append only)

- 2026-11-04 02:35: briefs written (`../briefs/S6a-leads-backend.md`, `../briefs/S6b-leads-frontend.md`); nothing built yet.
