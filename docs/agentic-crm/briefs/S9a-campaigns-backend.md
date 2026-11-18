# Track S9a: Campaigns to leads, BACKEND (brief for a build agent)

Read `COMMON-RULES.md` first, then `../slices/S09-campaigns-to-leads.md` (verified starting facts, decisions, database, contract, acceptance checks: it is the specification), `../PLAN.md` section 7 S9, `../DB-RULES.md`, `../DECISIONS.md`, `../00-PROGRESS.md`. Written 4 Nov 2026 (about 05:05 IST) so a replacement can continue from the files after a usage limit.

## Goal

Exactly the contract of the slice file: product on a campaign, first-touch campaign on a lead, a CRM-to-GTM campaign bridge, and a funnel from the touchpoint timestamps; additive only.

## Raw material (read it yourself; re-verify every starting fact before you build on it)

- The CRM campaign controller and presenter (`grep -rn "crm_campaigns" app`), `crm_campaign_targets` writers (who sets the timestamps today: the outreach mailers, `CrmOutreachMailer`, the reply processors: read them to know what a timestamp means), the GTM campaign controller and model, `app/Domain/Crm/Leads/LeadFactory.php` and `LeadLinks.php` (committed: how a lead validates its product and account the same way you will validate a campaign), `app/Domain/ProductMaster/*` (visibility helper), `PublicLeadCaptureController` and the lead import path (where a `campaign_id` would arrive).
- The tenant facts: `crm_campaign_targets` has NO tenant column; `crm_leads.sub_institute_id` is nullable; polymorphic `target_type` values are lowercase.
- Existing tests around campaigns and leads (`grep -rln "crm_campaigns\|crm_campaign_targets" tests`): extend their world builders, do not copy fixtures.
- Real data (read-only, dev connection `mysql`): `SHOW CREATE TABLE crm_campaigns, crm_campaign_targets, gtm_campaigns, crm_leads`; dev has no campaign rows, so the proofs use fixtures.

## Tasks, in order (small saved increments; log each with the command and result in the slice work log)

1. Re-verify the starting facts; list what exists vs what you must add.
2. Migrations (three files, `2026_12_11_14xxxx_...`, guarded, indexed, `down()` tested, run on dev one at a time; save `SHOW COLUMNS` before and after under `../_evidence/s9/`).
3. The funnel (a small class, one query shape, joined through the campaign's organisation; the stray-target test is mandatory), the presenter additions, `show` additions, validators with the cross-organisation 422s; a test pins every old key.
4. Lead attribution: the additive optional `campaign_id` through `LeadFactory` (never overwrites), the three creation paths, the presenter key `campaign`.
5. `campaigns:backfill-links` (dry run by default) with its full rules; prove dry run, apply and re-run on fixtures; run it dry on dev and save the (empty) report.
6. A test on the old schema (no new columns): the existing routes answer as before.
7. Mutation proofs (COMMON-RULES section 4): the organisation join of the funnel, each cross-organisation 422, never-overwrite on a lead's campaign, the ambiguous-name rule, the backfill's never-overwrite, the old-schema tolerance.

## Do not

Touch g2gv0, the Signals / Ingestion / Partner files, `app/Domain/Facts/*`, `app/Domain/OrgAccounts/*`, `app/Domain/AgentGateway/*`, `app/Domain/Ask/*` (S8a is building it right now) and the opportunity files (S11a is building them right now: `CrmOpportunityController`, `OpportunityGtmSyncBridge`, `DealController`, migrations `2026_12_11_13*`). Edit `app/Domain/Crm/Leads/*` only by the additive optional attribute in `LeadFactory` and say so. No push, no commit (the caller commits), no live database, no message of any kind, no server, **no sending or scheduling of anything**. Migration names must not collide with S8a's `2026_12_11_12*` or S11a's `2026_12_11_13*`. The reports-engine files belong to another session.

## Report (COMMON-RULES section 7)

Files added or changed, design as built and every deviation with its reason, tests and mutations with the test that killed each, evidence paths, open defects with file:line, DB-CHANGELOG row text (placeholder numbers: the caller renumbers), what you could not verify, what the caller must do next. Keep `../slices/S09-campaigns-to-leads.md` current (append-only work log).
