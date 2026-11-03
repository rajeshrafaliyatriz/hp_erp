# Track S11a: pipeline safety, loss reasons, product link and forecast record, BACKEND (brief for a build agent)

Read `COMMON-RULES.md` first, then `../slices/S11-pipeline-and-forecast.md` (verified starting facts, decisions, database, contract, acceptance checks: it is the specification), `../PLAN.md` section 7 S11, `../DB-RULES.md`, `../DECISIONS.md`, `../00-PROGRESS.md`. Written 4 Nov 2026 (about 04:45 IST) so a replacement can continue from the files after a usage limit.

## Goal

Make saving an opportunity or a deal immune to the other system's sync failing (defect D17), give an opportunity its product and a coded loss reason, prove that every path that changes a stage leaves a history row, and give the forecast a daily record, exactly as the slice file says. Nothing existing changes shape.

## Raw material (read it yourself; re-verify every starting fact in the slice file before you build on it)

- `app/Http/Controllers/Api/Crm/CrmOpportunityController.php` (store ~line 200-230, update ~282, stage move ~337, `writeStageHistory`, `resource()`), `app/Domain/Crm/Sales/OpportunityGtmSyncBridge.php`, `OpportunityEvents.php`, `OpportunitySweep.php`, `app/Http/Controllers/Api/Gtm/DealController.php` (the reverse sync, lines ~200, 239, 295, 322), `app/Domain/Crm/Leads/LeadConverter.php` (conversion creates an opportunity: does it write a history row?), anything else that writes `crm_opportunities.sales_stage` (`grep -rn "sales_stage" app`).
- The forecast: `database/migrations/2026_10_11_100000_gtm_forecasting*`, `app/Domain/Gtm/Forecast*` and the GTM forecast controller and screen (`/gtm/forecast`): what they already store and compute.
- Existing tests around opportunities (`grep -rln "crm_opportunities" tests`) and their world builders: extend them.
- The product resolver and visibility helpers the Leads track used (`app/Domain/Crm/Leads/LeadLinks.php::productVisible`, `app/Domain/ProductMaster/*`) so an opportunity validates its product the same way a lead does.
- Real data (read-only, dev connection `mysql`): `SHOW CREATE TABLE crm_opportunities, crm_opportunity_stage_history`; row counts per organisation; how many opportunities have a free-text `close_reason` and what the distinct values are (the backfill maps only exact matches).

## Tasks, in order (small saved increments; log each with the command and result in the slice work log)

1. Re-verify the starting facts; list what exists vs what you must add; write the done / half done / broken list if a previous agent left files.
2. D17: a `syncSafely` (or equivalent) around BOTH directions of the sync at every call site; catch `Throwable`, log with ids and the error message, never rethrow, never swallow a database transaction state silently (read how the controller uses transactions); test with the bridge forced to throw (create, update, stage move, and the GTM deal controller's three call sites): the save answers as before, the row and the history are written, the failure is logged. Add `opportunities:resync-gtm` (dry run by default, `--apply`, per organisation) that re-runs the sync for opportunities whose linked deal is out of step, and prove a re-run changes nothing.
3. Stage history: a test per path that changes `sales_stage` (find every path in task 1; the plan names kanban move, edit, convert, GTM sync); fix any path that does not write a row (smallest change, with the reason in the log).
4. Migration for `crm_opportunities.product_master_id` and `loss_reason_code` (`2026_12_11_13xxxx_...`, guarded, indexed with the organisation, `down()` tested, one file at a time with `php artisan migrate --path=<file>`; save `SHOW COLUMNS` before and after under `../_evidence/s11/`); `config/crm_sales.php` with the reason codes and the (empty by default) required-fields rule; validators and presenter additions per the contract (a test pins the old presenter keys); cross-organisation product is 422.
5. `opportunities:backfill-links` (dry run by default) per the slice file; run it on dev dry, apply only if the dry run is sane, re-run shows 0 changes; save the output.
6. Forecast: read what the GTM tables keep, write the decision into the slice log WITH the evidence; add the snapshot table, command (dry run default) and read endpoint only if the decision says new storage is needed.
7. Mutation proofs (COMMON-RULES section 4) for each safeguard: the catch around each sync call site, the no-rethrow, the history row per path, the product visibility check, the reason-code validation, the required-fields switch (on and off), the organisation filter on every route touched, the backfill's never-overwrite rule.

## Do not

Touch g2gv0, the Signals / Ingestion / Partner files, the Leads domain (`app/Domain/Crm/Leads/*` is committed and finished: read it, call its public helpers, do not edit it), `app/Domain/Facts/*`, `app/Domain/OrgAccounts/*`, `app/Domain/AgentGateway/*`, `app/Domain/Ask/*` (S8a is building it right now: never stage or edit its uncommitted files). No push, no commit (the caller commits), no live database, no message of any kind, no server. Migration names must not collide with S8a's `2026_12_11_12*`. The reports-engine files belong to another session.

## Report (COMMON-RULES section 7)

Files added or changed, design as built and every deviation with its reason, tests and mutations with the test that killed each, evidence paths, open defects with file:line, DB-CHANGELOG row text (placeholder numbers: the caller renumbers), what you could not verify, what the caller must do next. Keep `../slices/S11-pipeline-and-forecast.md` current (append-only work log).
