# Track S6a: Leads, BACKEND (brief for a build agent)

Read `COMMON-RULES.md` first, then `../slices/S06-leads.md` (the goal, the verified starting facts, the database, the CONTRACT and the acceptance checks: it is the specification), `../PLAN.md` section 7 S6, `../DB-RULES.md`, `../DECISIONS.md`, `../00-PROGRESS.md`. Written 4 Nov 2026 (02:40 IST) so a replacement can continue from the files after a usage limit.

## Goal

Make every way a lead is born go through ONE factory with a collision-proof number, and give a lead its product, its organisation account and its evidence, exactly as the contract in the slice file says. Nothing existing changes shape; fields and filters are only added.

## Raw material (read it yourself; re-verify every starting fact in the slice file before you build on it)

- `app/Domain/Crm/Leads/LeadFactory.php`, `LeadConverter.php`, `LeadScorer.php`, `LeadActivity.php`; `app/Http/Controllers/Api/Crm/CrmLeadController.php` (index, show, store, import, convert, `resource()` presenter at line ~644), `PublicLeadCaptureController.php`, `CrmSignalInboxController.php` (the direct insert near line 300), `app/Http/Controllers/Api/Signals/OpportunityController.php` (`promoteToCrm`, line ~916).
- `app/Domain/ProductMaster/*` (the resolver), `app/Domain/OrgAccounts/*` (identity table `g2g_org_accounts`), `app/Domain/Facts/*` (`ConsentGate`, `FactRepoint`, `ConsentRecorder`), `app/Domain/AgentGateway/*` (how a note and a task are written: reuse that writer for the structured note, do not add a third note path).
- The existing tests around leads (`grep -rln "LeadFactory\|crm_leads" tests`): extend them, do not duplicate fixtures; read the helpers they use.
- Real data (read-only, dev connection `mysql`): `SHOW CREATE TABLE crm_leads`; counts of leads per tenant, how many have `source_company_opportunity_id`, how many have `lead_no` in each shape (the number format the current `nextLeadNo` produces); the highest number per tenant.

## Tasks, in order (small saved increments; tick each in the slice work log with the command and result)

1. Re-verify the starting facts; write the done / half done / broken list if a previous agent left files.
2. Migration 1: `g2g_number_sequences` and the `LeadNumber` claim class (atomic on MySQL/MariaDB, a transaction on sqlite, seeded from the tenant's highest number the first time). Replace `nextLeadNo` with it. Keep the number FORMAT identical to today's. Tests: sequential numbers, seeding from existing rows, two interleaved claims never equal, a tenant never sees another tenant's counter.
3. Migration 2: `crm_leads` columns and the guarded `MODIFY` of `description`. Run it on dev with `php artisan migrate --path=<file>` only, save `SHOW COLUMNS` before and after under `../_evidence/s6/`, test `down()`.
4. Route the Signal Inbox direct insert and `promoteToCrm` through `LeadFactory`; fix the opportunity insert of the signal inbox (non-existent columns `opportunity_name`, `account_id`, missing NOT NULL `name` and `opportunity_no`: read the real columns first). Tests prove each path now produces number, score, event.
5. `promoteToCrm` sets `product_master_id` and `org_account_id`, and writes one structured note (quote, URL, date, fit) through the shared note writer, once per signal.
6. `LeadConverter`: no placeholder contact when no person is known (decide the behaviour from the real code: convert the organisation and opportunity only, and say so in the response), and facts follow the lead (`FactRepoint`, lead to contact).
7. Presenter, filters and `show` additions exactly as the contract says; a tenant sweep over every lead id route and the `product_id` filter (read the route list yourself; the S4a `FactsTenantSweepTest` is the model).
8. `leads:backfill-links` (dry run by default). Run it on dev dry, save the summary, run it with `--apply` on dev only if the dry run is sane, run it again and show 0 changes.
9. Mutation proofs for every new safeguard (COMMON-RULES section 4): the sequence claim, the tenant filter on the sequence, the signal promotion's single note, the placeholder-contact guard, the filters' tenant scope.

## Do not

Touch `components/` or anything in g2gv0, the Signals screens, Ingestion Engine or Partner Network files, `app/Domain/Facts/*` or `app/Domain/OrgAccounts/*` beyond READING them (call their public methods; if one needs a change, say so in the report). No push, no commit (the caller commits), no live database, no message of any kind, no sidebar entry, no server (the caller verifies in a browser). The reports-engine files and `docs/reports-engine/PLAN.md` belong to another session.

## Report (COMMON-RULES section 7)

Files added or changed, design as built and every deviation with its reason, tests and mutation results with the test that killed each, evidence paths, open defects with file:line, DB-CHANGELOG row text for both migrations and the backfill (rows continue after 36), what you could not verify, what the caller must do next. Keep `../slices/S06-leads.md` current (append-only work log).
