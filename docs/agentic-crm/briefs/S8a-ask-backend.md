# Track S8a: Ask, BACKEND (brief for a build agent)

Read `COMMON-RULES.md` first, then `../slices/S08-ask.md` (the decisions, the goal, the database, the CONTRACT and the acceptance checks: it is the specification), `../slices/S07-agent-gateway-thin.md` and `../slices/S07b-autonomy-and-waiting-queue.md` (how the gateway, its audit, its cost accounting and its tests work: you reuse them), `../DB-RULES.md`, `../DECISIONS.md`, `../00-PROGRESS.md`. Written 4 Nov 2026 (about 03:20 IST) so a replacement can continue from the files after a usage limit.

## Goal

The two-step bounded Ask pipeline of the slice file behind `POST /api/agent/ask`, with conversations stored per user, read-only whitelisted tools run with the caller's rights and tenant, citations validated against what was really retrieved, and every question audited and costed. No write tool exists, no action is ever taken.

## Raw material (read it yourself; re-verify every starting fact before building on it)

- The Organisation agent: `app/Domain/AgentGateway/*` (`OrgAgentService` for the run lifecycle, `AgentModel` for the model call and its schema validation, `OrgAgentPrompt` for the untrusted-data boundary and the "use only the data, never invent" rules, `OrgAgentContextBuilder` for how a fact gets a `{type,id,label,url}` ref, `AgentRunCost` for rupees, `AgentAccess` for rights, `AgentAudit`, `Gateway` for citation validation) and `tests/Feature/Agent*` + `tests/Support/BuildsAgentWorld.php` (the fake model and the world builder: extend them, do not copy).
- The old chat: `app/Http/Controllers/Api/Crm/CrmAgentController.php::aiChat` and `buildChatContext` (what it does today; you do NOT change that endpoint), `routes/api.php` line ~4046.
- The tenant and rights facts of the existing record screens: how `CrmLeadController`, `CrmContactController`, `CrmOrganizationController`, `CrmOpportunityController` scope reads (tenant column; leads have a NULLABLE `sub_institute_id`), and which menu rows gate each (`MenuRight`, `AgentAccess::ORGANIZATIONS`, the Contacts, Leads and Opportunities rows). Use read-only SQL through the same scoping, never a controller call that writes.
- Real data (read-only, dev connection `mysql`): `SHOW CREATE TABLE g2g_agent_runs`; rows per tenant 6 in each searched table; how many signals exist and their statuses.

## Tasks, in order (small saved increments; log each with the command and result in the slice work log)

1. Re-verify the starting facts; list what exists vs what you must add.
2. Migrations (`2026_12_11_12xxxx_...`, one file each, guarded, `down()` tested) for `g2g_agent_conversations` and `g2g_agent_messages`; run each on dev with `php artisan migrate --path=<file>` only; save `SHOW CREATE TABLE` before and after under `../_evidence/s8/`.
3. The read tools (a small class per tool, a registry that lists exactly five, args validated, results capped, every row `{type,id,label,url}`, the caller's right checked per record type, a tenant filter on every query). Tests per tool: tenant isolation, the right check, the cap, the `no rows` answer.
4. The planner step (model call 1: JSON, schema-validated, a whitelist of tool names, at most 3 calls, args validated again server-side; anything else is dropped) and the answerer step (model call 2: only the retrieved rows are given, the same untrusted-data boundary as `OrgAgentPrompt`, the "say nothing found rather than guess" rule, "write in the user's words, short"). Citation validation: a citation not in the retrieved rows is removed; a claim that rested only on it makes the answer `refused` or is withheld (decide from the data, document it).
5. The controller, routes (`routes/agent_gateway.php`, extend `AgentGatewayTenantSweepTest`), conversation list and read (owner-only: another user's id and another tenant's id are the same 404), the per-user daily cap (config in `config/agent_actions.php` or a new `config/ask.php`; default 30), the "unavailable" answer when the model is missing or fails, one `g2g_agent_runs` row per question with agent_key `ask`, tokens, cost in rupees, tool calls, and the audit rows the gateway already writes.
6. Mutation proofs (COMMON-RULES section 4) for each safeguard: the tenant filter on each tool, the right check on each record type, the owner-only conversation read, the whitelist (an unknown tool is dropped), the cap of 3 calls, the citation validation (a fake model that invents a record), the daily cap, "no write tool exists" (a test that lists the registry and scans the Ask folder for `insert(`, `update(`, `delete(`, `Http::`, `Mail::`), the injection case (a retrieved note that tries to give orders).
7. One REAL question through the real model on dev (`AGENT_AI_MODULE` as the Organisation agent uses it) with a script under `D:\dev\agentic-crm-research\scripts\g2g\` that asks three questions about tenant 6 data and prints each answer next to the rows it should rest on, so the caller can check every sentence. This spends about two model calls per question; do it once at the end, not in a loop.

## Do not

Touch g2gv0, the Signals / Ingestion / Partner files, the old `aiChat` endpoint, the Leads files (`app/Domain/Crm/Leads/*`, lead controllers: track S6a owns them until the caller commits it), `app/Domain/Facts/*`, `app/Domain/OrgAccounts/*`; add any write tool; add a send or delete anything. No push, no commit (the caller commits), no live database, no message of any kind, no server. Migration names must not collide with S6a's `2026_12_11_10*` or S7b's `2026_12_11_11*`. The reports-engine files belong to another session.

## Report (COMMON-RULES section 7)

Files added or changed, design as built and every deviation with its reason, tests and mutations with the test that killed each, evidence paths, open defects with file:line, DB-CHANGELOG row text (use placeholder numbers, the caller renumbers), what you could not verify, what the caller must do next. Keep `../slices/S08-ask.md` current (append-only work log).
