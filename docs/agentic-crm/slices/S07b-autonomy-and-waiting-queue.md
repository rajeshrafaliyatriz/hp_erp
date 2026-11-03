# S7b: Autonomy settings and the tenant-wide waiting queue (backend track S7b-api, front-end track S7c)

Plan: `../PLAN.md` section 7, S7 (Settings > Agents: actions and levels with counters; Today > Proposals queue). Thin slice already built: `S07-agent-gateway-thin.md`. Written 4 Nov 2026 (02:30 IST). Status: **brief written, backend track starting**. Dev only.

## Decision (made by the assistant under the owner's "decide yourself" instruction; reversible, UI only)

The owner's rule is "no duplicate screens". The plan's two new screens (Settings > Agents, Today > Proposals) are therefore folded into the EXISTING **Agent Run History** screen (`/module/crm/marketing/agent-runs`) as tabs: **Runs** (as it is), **Waiting for you** (every proposal nobody has decided, across organisations, with accept / dismiss), **Autonomy** (administrators only). No new menu row. Door: if the owner wants a separate Settings entry, it is a move of one component, not a rebuild.

## Why (the user's labour)

Today a proposal can only be decided from inside one organisation's page, and the level of an action can only be changed by writing a database row (`agent_autonomy_check.php`). A person who wants to clear the day's proposals has to open organisations one by one; an administrator who wants to let the agent act alone more (or less) has no screen and no evidence for the choice.

## Starting facts (4 Nov, read in source)

- Routes: `routes/agent_gateway.php` (prefix `api/agent`): organisation state, run, `POST /proposals/{id}/accept|dismiss` (EDIT on Organizations), `GET /runs`, `GET /runs/{id}` (VIEW on the run-history row). `AgentGatewayTenantSweepTest` walks this file's routes and fails for an id route without a tenant filter or rights middleware.
- Levels and defaults: `config/agent_actions.php` (`actions`: add_note alone, add_task alone, confirm_link approval; `levels`: off, suggest, draft, approval, alone). Overrides: table `g2g_agent_autonomy` (`action_key`, `level`, tenant; `action_key = '*'` and level `off` is the organisation's kill switch; organisation 0 switches the agent off for everybody). `AutonomyPolicy` reads it.
- The decision code: `ProposalDecisions`, `ProposalApplier` (exactly-once claim), `AgentAudit`, `AgentEvents`.

## Contract (S7b-api builds it; S7c reads it; additive, nothing existing changes shape)

All routes `api` + `api.token`. Rights: reading = VIEW on the run-history row; changing a level or the kill switch = EDIT on the run-history row AND an administrator of the tenant (read how `AgentAccess` and the platform services decide "administrator"; never read `role_key` raw, see `SubjectAuthority`); deciding a proposal = EDIT on Organizations, as the existing accept / dismiss.

- `GET /api/agent/autonomy` -> `{status:1, data:{kill_switch:{on:bool, scope:'organisation'|'platform'|null}, actions:[{key, label, description, default, level, overridden:bool, levels:[...], counts:{proposed, applied, accepted, dismissed, failed}, last_changed:{at, by_name}|null}], can:{change:bool}}}`. `counts` are the last 30 days, from `g2g_agent_tool_calls` and `g2g_agent_proposals` (the evidence for raising or lowering a level). Labels and descriptions are plain English from the config.
- `PUT /api/agent/autonomy/{actionKey}` body `{level, reason?: string<=300}`: upserts the override (setting a level equal to the default removes the override), audited (`AgentAudit`) and recorded as an event `agent.autonomy.changed` (register it in `EventCatalogue::SHIPPED` the way the other agent events are), answers with the same action object. 404 for a key that is not in the config; 422 for a level not in `levels`; 403 for a non-administrator. There is NO way to create an action: a key outside `actions` is refused.
- `PUT /api/agent/autonomy-kill-switch` body `{on: bool, reason?}`: writes or removes the `*` row for the tenant (never the platform row 0: that one is not changeable from a screen), audited and recorded the same way.
- `GET /api/agent/proposals?status=pending&page=&per_page<=50`: the tenant's proposals, newest first, each `{id, run_id, action, label, summary (plain words, built from the payload by the presenter that already exists), organisation:{id, name, href_hint}, evidence:[...as the organisation panel shows], created_at, can:{decide:bool}}`; `data:{rows, pagination}`. A proposal whose organisation was removed is shown as such, not dropped silently.

## Acceptance

1. A level changed through the API is what `AutonomyPolicy` then applies (an end-to-end test: set `add_note` to `approval`, run the agent service with the fake model, see a pending proposal instead of an applied note; set it back, see the default).
2. Every change writes an audit row and an event; the wrong tenant's key, a non-administrator, an unknown key and an unknown level are refused with the right status; the kill switch stops a run and a decision on a new run.
3. The queue lists only the caller's tenant; deciding a proposal from the queue uses the existing accept / dismiss (no second apply path) and is exactly-once under a race.
4. `AgentGatewayTenantSweepTest` still passes and covers the new id route(s).
5. Front end (S7c): the three tabs on Agent Run History, the queue clears without opening organisations, the Autonomy tab shows counters beside each level and a plain-English warning on "alone"; administrator-only; light and dark; 390 px.

## Work log (append only)

- 2026-11-04 02:30: slice file and brief written.
