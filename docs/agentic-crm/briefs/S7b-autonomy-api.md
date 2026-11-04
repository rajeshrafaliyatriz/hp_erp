# Track S7b-api: autonomy settings and the waiting queue, BACKEND (brief for a build agent)

Read `COMMON-RULES.md` first, then `../slices/S07b-autonomy-and-waiting-queue.md` (the decision, the starting facts, the CONTRACT and the acceptance checks: it is the specification), `../slices/S07-agent-gateway-thin.md` (what exists and how it was verified), `../DB-RULES.md`, `../DECISIONS.md`, `../00-PROGRESS.md`. Written 4 Nov 2026 (02:35 IST) so a replacement can continue from the files after a usage limit.

## Goal

Build the four endpoints in the contract, on top of the existing gateway, with no second apply path and no new action type. The front-end track S7c builds against the contract at the same time or right after.

## Raw material (read it yourself; re-verify every starting fact before building on it)

`app/Domain/AgentGateway/*` (`AutonomyPolicy`, `AgentAccess`, `AgentAudit`, `AgentEvents`, `ProposalDecisions`, `ProposalApplier`, `AgentPresenter`, `ToolRegistry`), `app/Http/Controllers/Api/AgentGatewayController.php` (or wherever `routes/agent_gateway.php` points), `config/agent_actions.php`, `app/Services/Events/EventCatalogue.php` (how the agent events are registered), `tests/Feature/Agent*` (especially `AgentGatewayTenantSweepTest`, `AgentApplierClaimTest`, the autonomy and kill-switch tests: extend them, reuse their helpers), `database/migrations/2026_12_10_09*` (the agent tables), `D:\dev\agentic-crm-research\scripts\g2g\agent_autonomy_check.php` (how an override is set by hand today). How an administrator is recognised: read `app/Domain/Subjects/*` or wherever `SubjectAuthority` lives, and how `ModuleGate` / `platformright` treat the profile; never read `role_key` raw (it is NULL on the app host).

Real data (read-only, dev connection `mysql`): `SHOW CREATE TABLE g2g_agent_autonomy, g2g_agent_proposals, g2g_agent_tool_calls`; rows per tenant 6; which columns could carry "who changed it" for `last_changed` (if `g2g_agent_autonomy` lacks `updated_by` / `updated_at`, add them in a guarded additive migration `2026_12_11_11xxxx_...`, and say so in the report).

## Tasks, in order (small saved increments; log each with the command and result in the slice work log)

1. Re-verify the starting facts; list what exists vs what you must add.
2. A presenter for the autonomy state (actions with labels, descriptions, counts for the last 30 days, last change) and `GET /api/agent/autonomy`. Test: counts match rows you insert; another tenant's rows never count.
3. `PUT /api/agent/autonomy/{actionKey}` and the kill-switch route: validation, administrator check, upsert / remove-when-default, audit, event (`agent.autonomy.changed`, registered in the catalogue with its tests), the whole contract's refusals. Test end to end with the fake model that a changed level changes what a run does.
4. `GET /api/agent/proposals` with the tenant filter, pagination, the plain-words summary, the removed-organisation case. Decisions go through the existing accept / dismiss routes only.
5. Route file and `AgentGatewayTenantSweepTest` coverage; rights middleware on every new route (`platformright` on the run-history row, edit for changes), the administrator check inside the controller.
6. Mutation proofs (COMMON-RULES section 4) for each new safeguard: the tenant filter on counts and on the queue, the administrator check, "a key outside the config is refused", "equal to default removes the override", the kill switch row scope (never row 0), the audit write, the event write.

## Do not

Touch g2gv0, the Signals / Ingestion / Partner files, `app/Domain/Crm/Leads/*` or lead controllers (another track, S6a, owns them right now), `app/Domain/Facts/*`, `app/Domain/OrgAccounts/*`; add a tool or an action type; add a send or delete anything. No push, no commit (the caller commits), no live database, no message of any kind, no server. Migration file names must not collide with S6a's (`2026_12_11_10xxxx`): use `2026_12_11_11xxxx`. The reports-engine files belong to another session.

## Report (COMMON-RULES section 7)

Files added or changed, design as built and deviations with reasons, tests and mutations with the test that killed each, evidence paths, open defects with file:line, DB-CHANGELOG row text (rows continue after S6a's; use a placeholder number, the caller renumbers), what you could not verify, what the caller must do next. Keep `../slices/S07b-autonomy-and-waiting-queue.md` current (append-only work log).
