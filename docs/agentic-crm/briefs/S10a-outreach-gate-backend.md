# Track S10a: the outreach gate, BACKEND (brief for a build agent)

Read `COMMON-RULES.md` first, then `../slices/S10-outreach-gate.md` (verified starting facts, decisions, database, contract, acceptance checks: it is the specification), `../PLAN.md` section 7 S10, `../DB-RULES.md`, `../DECISIONS.md`, `../00-PROGRESS.md`. Written 4 Nov 2026 (about 05:30 IST) so a replacement can continue from the files after a usage limit.

## Goal

Exactly the slice file: one deterministic `OutreachGate` that both mailers call as one more refusal step, recorded checks, approved product claims, the two product-link columns and the two D19 indexes. **Nothing in this track sends, schedules or enables sending**; a wrong move here is the most dangerous kind in the whole effort, so read this paragraph twice.

## Hard safety rules for this track (on top of COMMON-RULES)

- Never set, change or read-to-override a sending flag; never call a transport outside `Mail::fake()` in tests; never add a route that sends; never add a command that sends. A test (acceptance 5) scans the new code for `Mail::`, `Http::`, `Notification::`, `->send(`, `dispatch(` and flag writes and must pass.
- The mailers' existing behaviour with sending OFF must be byte-for-byte the same: run their existing tests before you change anything (record the counts), and again after.
- Dev database only, no real address ever used in a fixture (use `@example.test`).

## Raw material (read it yourself; re-verify every starting fact before you build on it)

- `app/Domain/Gtm/OutreachMailer.php` and `app/Services/Crm/CrmOutreachMailer.php` (the refusal ladders, where each sends), `app/Domain/Gtm/Outreach/*` (`Suppressions`, `SendFailure`, `OutreachDrafts`), `app/Http/Controllers/Api/Gtm/OutreachController.php` and the CRM outreach approvals controller (`grep -rn "outreach" routes/*.php`), the existing mailer tests (`grep -rln "OutreachMailer\|CrmOutreachMailer" tests`) and their world builders.
- `app/Domain/Facts/ConsentGate.php` (`allows(entityType, entityId, channel)`: how it reads consent; how a recipient maps to an entity type and id for a GTM message and for a CRM email), `config/gtm.php`, `config/crm.php`.
- The Product Master visibility helpers (`app/Domain/Crm/Leads/LeadLinks.php::productVisible`) for the product checks.
- Real data (read-only, dev connection `mysql`): `SHOW CREATE TABLE gtm_outreach_messages, gtm_playbooks, crm_emails`; row counts per organisation; which indexes exist.

## Tasks, in order (small saved increments; log each with the command and result in the slice work log)

1. Re-verify the starting facts; record the mailers' existing test counts as the baseline.
2. Migrations (`2026_12_11_15xxxx_...`, one file each; guarded; `down()` tested; run on dev one at a time; `SHOW CREATE TABLE` before and after under `../_evidence/s10/`): the two new tables; the product columns; the D19 indexes only where absent.
3. `config/outreach_gate.php` (banned phrases, length cap, placeholder patterns, the claim-marker list) and the `OutreachGate` class: a list of small check classes, each pure and unit-tested with failing and passing cases, a result object, the storage of a set per evaluation (`run_id`).
4. Consent and suppression checks through `ConsentGate` plus the GTM contact status and `gtm_suppressions` (map each recipient to its entity; a recipient that cannot be mapped FAILS CLOSED with a clear detail).
5. Wire both mailers: one call in each `send()` after the existing flag checks and before the transport; the `gate_blocked` refusal in each mailer's own shape; existing tests unchanged and green; new tests with the flags forced on and `Mail::fake()`.
6. The claims API and the gate preview endpoint with rights, tenant filters and the cross-organisation cases (read how the existing routes are gated; extend `AgentGatewayTenantSweepTest`-style sweeps for the new routes in a new test file).
7. Mutation proofs (COMMON-RULES section 4) for each check and each safeguard: each check's failing branch, the order (the gate after the flags, before the transport), the fail-closed mapping, the claim normalisation, the organisation filter on every route, the "newest set" rule, the static no-send scan (a mutation that adds a `Mail::` call must make it fail).

## Do not

Touch g2gv0, the Signals / Ingestion / Partner files, `app/Domain/Facts/*` (read it), `app/Domain/Crm/Leads/*`, `app/Domain/AgentGateway/*`, `app/Domain/Ask/*`, and the files of the tracks running now (S9a: `CrmCampaignController`, `Gtm/CampaignController`, `app/Domain/Campaigns/*`, migrations `2026_12_11_14*`; S11a: `CrmOpportunityController`, `Gtm/DealController`, `OpportunityGtmSyncBridge`, `app/Domain/Crm/Sales/*`, migrations `2026_12_11_13*`): their uncommitted files are in the working tree, never stage or edit them. No push, no commit (the caller commits), no live database, no message of any kind, no server, no sending. Migration names must not collide with `2026_12_11_13*` and `2026_12_11_14*`. The reports-engine files belong to another session.

## Report (COMMON-RULES section 7)

Files added or changed, design as built and every deviation with its reason, tests (with the mailers' before and after counts) and mutations with the test that killed each, evidence paths, open defects with file:line, DB-CHANGELOG row text (placeholder numbers: the caller renumbers), what you could not verify, what the caller must do next. Keep `../slices/S10-outreach-gate.md` current (append-only work log).
