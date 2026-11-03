# S9: Campaigns to leads (backend track S9a, then a small front-end track S9b)

Plan: `../PLAN.md` section 7, S9. Written 4 Nov 2026 (about 05:00 IST). Status: **brief written, track S9a starting**. Dev only; live is never touched.

## Verified starting facts (4 Nov, read from the dev schema by the caller; the plan's agent-read claims, re-verified)

- `crm_campaigns` (31 columns): `campaign_no` varchar(100), `product_id` BIGINT UNSIGNED (a loose pointer, no foreign key), budget and response counters, `sub_institute_id`, soft delete. No `product_master_id`.
- `crm_campaign_targets` (13 columns): `campaign_id`, lowercase `target_type` varchar(20), `target_id`, `response_status`, and the touchpoint timestamps `sent_at`, `opened_at`, `replied_at`, `meeting_booked_at`, `opted_out_at`, `converted_at`. **No tenant column**: a target is tenant-scoped only through its campaign, so every read joins `crm_campaigns` and filters its `sub_institute_id`.
- `gtm_campaigns` (15 columns): `kind`, `status`, `goal`, `industry_pack_slug`, `segment_id`, owner, dates, soft delete; `gtm_campaign_members` (`campaign_id`, `account_id`, `contact_id`, `status`). No link to a CRM campaign and no product.
- `crm_leads` has no `campaign_id` (only a free-text `campaign_text`, which the plan says exists: 0 rows carry it on dev).
- Dev holds 0 campaigns, 0 GTM campaigns and 6 leads (all tenant 6): every backfill is a no-op on dev and must be proven with test fixtures and a dry-run report, not with dev data.

## Goal

A campaign knows its product; a lead knows the campaign that first touched it; a CRM campaign and a GTM campaign that are the same campaign can be linked; and a campaign shows its funnel (sent, opened, replied, meeting, converted, opted out) from the timestamps it already keeps. Nothing existing changes shape; no new menu entry; the two existing campaign screens stay.

## Decisions (made by the assistant under the owner's "decide yourself" instruction; reversible)

1. **One campaign table is the main one: `crm_campaigns`** (the plan's default), with `gtm_campaigns` linked to it by a nullable bridge `crm_campaign_id`, never merged. Membership stays in `crm_campaign_targets` and `gtm_campaign_members`, joined for the funnel, not copied.
2. **First-touch attribution**: `crm_leads.campaign_id` records the campaign a lead first came from; it is set at creation (when the caller names one) and by the backfill; it is never overwritten by a later touch.
3. **No sending, ever**: nothing in this slice sends or schedules a message; the agent hooks of the plan (`suggest_audience`, `create_campaign_draft`) are NOT in this round.

## Database (additive; DB-RULES in full; new migration files sort after `2026_12_11_130000_...`: name them `2026_12_11_14xxxx_...`; one file at a time with `php artisan migrate --path=<file>`)

- ALTER `crm_campaigns`: `product_master_id` BIGINT UNSIGNED NULL, index `(sub_institute_id, product_master_id)`.
- ALTER `gtm_campaigns`: `product_master_id` BIGINT UNSIGNED NULL and `crm_campaign_id` BIGINT UNSIGNED NULL, each indexed with the organisation.
- ALTER `crm_leads`: `campaign_id` BIGINT UNSIGNED NULL, index `(sub_institute_id, campaign_id)`. (`crm_leads.sub_institute_id` is nullable: every new query filters it explicitly.)
- DATA (dry run by default, `--apply`, per organisation, a re-run changes nothing, never overwrites a non-empty value): `campaigns:backfill-links` sets `crm_campaigns.product_master_id` from the loose `product_id` through the Product Master's `crm_product_id`; `crm_leads.campaign_id` from an EXACT, case-insensitive `campaign_text` = campaign name match within the organisation (an ambiguous name, two campaigns with the same name, is reported and not linked).
- Guarded, idempotent, `down()` tested.

## Contract (additive; existing keys unchanged, a test pins them)

- `GET /api/crm/campaigns` and `/{id}` (the existing CRM campaign routes): each campaign gains `product: {id, key, name}|null`; `show` also gains `funnel: {targets, sent, opened, replied, meetings, converted, opted_out}` (counts of targets with each timestamp set, joined through the campaign's own organisation), `gtm_campaign: {id, name, status}|null`, and `leads: {count}` (leads whose `campaign_id` is this campaign).
- Write validators on the existing campaign store and update accept `product_master_id` (visible to the organisation, 422 otherwise) and, on the GTM side, `crm_campaign_id` (the organisation's own CRM campaign, 422 otherwise).
- The lead store, import and public-capture paths accept an optional `campaign_id` (the organisation's own campaign, 422 otherwise) and set it through `LeadFactory`; the lead presenter gains `campaign: {id, name}|null` (additive). `LeadFactory` is committed and finished: extend it only by an additive optional attribute and say so.

## Acceptance

1. Cross-organisation: another organisation's campaign id is a 404 on every route touched; the funnel of campaign A never counts targets of campaign B or another organisation (targets have no tenant column: a test with a stray target pointing at another organisation's campaign id).
2. Funnel counts equal a hand count over fixture targets (each timestamp, a target with several, a target with none, an opted-out one).
3. Attribution: a lead made with a `campaign_id` of another organisation is 422; a lead's `campaign_id` is never overwritten by a later request that names a different one.
4. The backfill's dry run, apply and re-run, with an ambiguous name reported and not linked, an existing value never overwritten, another organisation's rows never read.
5. A host without the new columns (the old schema): the existing campaign and lead routes still answer exactly as before (a test on the old schema).
6. Front end (S9b, a later small track): the existing CRM campaign screens show the product and the funnel; the lead page and list show the campaign; nothing new in the sidebar.

## Work log (append only)

- 2026-11-04 about 05:00: slice file written.
