# S7 (thin) — The Organisation agent: the first visible agent

**Status:** built and tested on dev; continuation of a track that was cut off by the session limit (3 Nov 2026, 20:40 IST). Dev database only. **Nothing is on live, nothing is committed by this track, nothing is pushed.** Brief: `../briefs/Z-organisation-agent.md`. Rules: `../briefs/COMMON-RULES.md`. Plan section: `../PLAN.md` S7. Evidence: `../_evidence/s7-agent-gateway/`.

## Goal

The owner: no agentic feature is visible yet; "I like Comp AI ... I want the G2G agentic CRM"; the whole point is to reduce the user's labour. Comp AI's pattern: an agent does the work, cites its evidence, a person accepts or dismisses, everything is audited. This slice is a thin but correct slice of S7 (agent gateway): a user opens an organisation, presses **Run agent** and gets (1) a short evidence-cited brief ("why now") and (2) proposals (add a note, add a follow-up task, confirm a probable link), each citing the records that justify it, which the user accepts or dismisses. What the autonomy settings allow applies at once and is marked "Agent". Every run is recorded and shown in the EXISTING **Agent Run History** screen (menu 503, `/module/crm/marketing/agent-runs`), replacing its seed-data demo (defect D5). It is NOT a new sidebar entry: the panel lives on the existing Organization detail page (track X mounts it) and in the existing Agent Run History screen.

## Stock-take at the start of this continuation (3 Nov, 20:40)

Tags: **verified** = I ran or read it myself this session; **read in source**; **inferred**; **unverified**.

### Done and working (verified)
- The five migrations are applied on dev: batches 560 to 564, all four `g2g_agent_*` tables existed and were EMPTY, `crm_organization_comments.created_via` existed with no value set (`_evidence/s7-agent-gateway/dev-show-create-2026-11-03.txt`, and a fresh read-only probe at 20:36).
- The domain layer in `app/Domain/AgentGateway/*` (24 files, 1,700 lines), `app/Domain/Crm/Records/{RecordNote,RecordTask,RecordNotFound}`, `AgentGatewayController`, `routes/agent_gateway.php` (registered in `bootstrap/app.php`), `config/agent_actions.php`, the three agent events in `EventCatalogue` (diff read: only this track's lines). I read every one of these files in full.
- Tests: `AgentContextTest` (10), `AgentGatewayTest` (23), `AgentRunServiceTest` (20) passed; `AgentGatewayApiTest` 13 of 17. One run of the four files: 70 tests, 618 assertions, 4 failures, 1 min 4 s.
- On dev, tenant 6: the AI configuration for module `gtm` is deepseek / `deepseek-flash` with a key present and a rupee price (0.0288 / 0.1152 per 1,000 tokens, `ai_models` id 12); `AgentModel::readiness(6)` returned null. The Admin profile (16) of tenant 6 already has view/add/edit/delete on Organizations (menu 452 and 483) and on Agent Run History (menu 503).

### Half done
- No route-sweep test (`routes/agent_gateway.php` and `AgentGatewayApiTest` both promised an `AgentGatewayTenantSweepTest` that did not exist); no test of the migrations' `down()`; no command, no front end, no slice file.
- `ConfirmLinkTool` and the context builder were tested only against fakes of `OrgLinker` / `OrgResolver` (the other track's classes).

### Broken (verified, with the cause; full proof in `_evidence/s7-agent-gateway/api-test-failures-diagnosis-2026-11-03.md`)
1. `OrganisationReferencesScanTest` failed on ONE column: `g2g_agent_proposals.entity_type` (the type column beside `entity_id`, the CRM organisation a note or task proposal was made for; informational, never used to find a record).
2. `AgentGatewayApiTest` rights matrix: a TEST bug (every user of the matrix acted on the same proposal; the correct 409 of a dismiss after an accept was expected to be 200).
3. `AgentGatewayApiTest::test_a_refused_request_changes_nothing`: a TEST bug (`assertSame` on arrays of `stdClass` compares identity).
4. Two tests on `proposal_counts`: a PRODUCT nit (key order); the presenter now writes one shape.

## Design as built

**A fixed pipeline, no free-form tool loop.** `OrgAgentService::run()` in order:
1. Checks that need no model, in ONE transaction that locks the organisation's identity row: the identity is the caller's (else 404), the agent is not switched off (kill switch), no run is under way (a run still `running` after `stale_run_minutes` = 5 is taken to have died), the day's cap (`daily_run_cap` = 10 runs per identity per day) is not spent. Then the run row is inserted as `running`.
2. `AgentModel::readiness()`: a driveable provider, a key AND a price in `ai_models` for the model (the organisation's row, else the platform's). Otherwise the run is `unavailable` with the reason and nothing else happens (the model is never called).
3. `OrgAgentContextBuilder` -> `AgentContext`: a bounded (14,000 characters; 8 signals, 5 deals, 5 notes, 5 tasks, 5 link suggestions), tenant-scoped, READ-ONLY set of facts built from the Organisation 360 (`OrgAccountPresenter::threeSixty`) plus the signal quote and source address, the notes and the open tasks. Every fact carries a ref `{type, id, label, url}`; a fact dropped for size is not a ref the model may cite. Demonstration signals (`is_sample`) are never shown. Every read of the 360 is tolerant of a missing key (`data_get`) and `AgentContextTest` names every key it relies on, so a change on track X's side fails there by name.
4. `OrgAgentPrompt`: facts as one JSON object per line inside a block with a RANDOM boundary (JSON escapes quotes and line breaks, so a fact cannot start a line that looks like another fact), the system message says plainly the block is data and lists the only three actions. Rules added after reading real data: a Dismissed signal is not fresh news and is not a reason for a proposal on its own.
5. ONE model call through `AgentModel` -> `AiModelClient::complete` (module `gtm`, `json => true`, temperature 0.2, `max_tokens` 1200, timeout 90 s, call filed under `related_type = g2g_agent_run` / `related_id = run id` so `AiUsageMeter` prices it in rupees from the rate card). The run's cost is read back from the metering rows (`AgentRunCost`): NULL (never 0) when a call that used tokens has no price or the calls were priced in two currencies.
6. The brief is kept only when it cites at least one record and every record it cites was in the context.
7. `Gateway` judges each proposal (at most 5; up to 20 more are audited as `over_limit`): the tool is one of three (`add_note`, `add_task`, `confirm_link`; anything else is `unknown_tool`); no steering field (organisation, assignee, recipient, channel...) and a `target`, if given, is the subject; the tool's own rules (text plain and capped, a real due date within 90 days, a `pair_key` that is one of the context's suggestions); the evidence (at least one, at most 8, EVERY one in the context: a proposal citing anything else is thrown away whole; the label and address stored are the context's, not the model's); the policy; never twice (same subject + action + payload hash, whatever became of the first: the unique key `g2g_ap_never_reoffer` is the mechanism, the check only gives a clear reason); at most 10 waiting per organisation.
8. Policy (`AutonomyPolicy`, defaults in `config/agent_actions.php`, overrides in `g2g_agent_autonomy`): `add_note` and `add_task` = `alone`, `confirm_link` = `approval`; a row `action_key='*'` at `off` is that organisation's kill switch (organisation 0 = everybody); it fails closed (an action that is not a tool, or a level that is not a level, is `off`). `alone` applies through `ProposalApplier` at once with `applied_via='policy'`; the others wait as `pending`.
9. `ProposalApplier` is the ONE path for both an alone action and a person's Accept: it re-reads the kill switch and the action's level, CLAIMS the proposal with a conditional `UPDATE ... WHERE status='pending'` in the same transaction as the write and its events (two clicks write once; `AgentApplierClaimTest` runs the race), re-derives the write's target from the subject identity, and writes through `RecordNote` / `RecordTask` (the shared write services: tenant and existence checked, `created_by` = the person the agent acts for, `created_via='agent'` on notes, the text prefixed `[Agent] `) or `OrgLinker::confirm`. A write the system refuses (a clash, a record that has gone, the calendar) becomes a `failed` proposal with the reason; anything unexpected rolls back and leaves the proposal `pending`. Dismiss stores an optional reason and the key stays in the unique index.
10. Everything leaves a row: `g2g_agent_tool_calls` for every read, brief and proposal with its verdict (a refusal is a row, with its reason), `g2g_event` rows `agent.run.finished`, `agent.proposal.decided`, `agent.action.applied` (idempotency keys carry the run or proposal id).

**Safety.** Nothing in the agent layer can send, call out, delete or merge: the registry holds three tools, a test lists it (no name contains send, email, sms, delete or merge) and another reads the agent's source for mail, HTTP and delete calls; no setting creates a tool. Model output never chooses an action outside the whitelist, a tenant, a target other than the subject, an assignee (a task is always for the person who ran the agent) or a link pair other than a suggestion. Signal text and anything web-derived is untrusted data (delimited, JSON-escaped, stored and rendered as plain text; the screens render it as text and make a link only from a path inside the application).

**API** (`routes/agent_gateway.php`, `api` + `api.token`, `platformright` with no administrator bypass): `GET /api/agent/organisations/resolve?crm_organization_id=` (view on Organizations), `GET /api/agent/organisations/{id}` (view), `POST .../{id}/run`, `POST /api/agent/proposals/{id}/accept`, `.../dismiss` (edit), `GET /api/agent/runs` (+ `status`, `date_from`, `date_to`, `page`, `per_page`<=50; with a tenant-wide `summary`) and `GET /api/agent/runs/{id}` (view on Agent Run History). Every by-id read filters by tenant and answers another tenant's id with the same 404 as one that never existed; `AgentGatewayTenantSweepTest` READS the routes from the router and fails for any id route that does not, that has no token or screen right, whose write needs only view, or whose id is not numeric.

**Command** `agent:organisation-run --tenant= --org-account= [--user=] [--database=]`: the same service, for development databases (`live` is refused), prints the brief, every proposal with what became of it, the trail, the model, tokens and cost; exit 0 only when the run completed.

**Front end (g2gv0, new files only)**
- `services/agent/{types,api-types,errors,agent-service}.ts`: the contract types, the `AgentApi` interface (components take it, so tests hand them a fake), `AgentApiError` (keeps the body's `code` and, for a 409, the proposal as it now stands), the service (the identity of a CRM organisation is found through X's `POST /api/org-accounts/for-crm/{id}`, which also makes the identity when there is none).
- `lib/agent/`: `model.ts` (every word and rule of the screens, pure and tested), `fixtures.ts`, `fake-service.ts`, `focus.ts`, `a11y.ts` (axe-core over the fake DOM), `assertions.ts` (`absent` / `same`: DOM assertions that fail in words, because `assert.equal(element, ...)` runs the test process out of memory when it fails) and 123 tests (below).
- `components/domain/crm/org-agent-panel.tsx` (+ `org-agent-parts.tsx`): **`OrgAgentPanel`**. Run agent (disabled while it runs, with the reason beside it when it may not run; runs today of the cap; last run time and cost in rupees), the brief with its evidence, "Waiting for you" proposals (action, plain-text summary, why, evidence, **Accept: add the note / add the task / link the records** and **Dismiss** with an optional reason in a small form), "What the agent has done" (applied items marked **Agent**; failed ones with the reason; dismissed ones with the reason given), the sentence on what the agent may do alone. States: opening, error with Retry, forbidden, not found, never run, unavailable, switched off, view-only. One thing in flight at a time (every button waits); a late answer for an organisation that is no longer open is dropped; after each action the panel is read again and the focus goes to the message; Escape closes the reason form and returns the focus to the Dismiss button. A link that merged this identity into another tells the page which (`onOrganisationChanged`) instead of reading a removed identity.
- `components/domain/crm/agent-runs-view.tsx` (rewritten) + `agent-run-detail.tsx` (new): Agent Run History on real data: the header, four stat cards (from the server's tenant-wide summary), status and day filters (`Started from` / `Started until`, an end before the start is said and not asked for), the table (run, organisation, status in words with the duration, proposals, model and cost, started), paging, a run's dialog (facts, brief and evidence, every proposal with what became of it, the audit trail with refusals in words). The old demonstration rows, the success rate fixed at 100% and the fake "Run Deal Coach" button are gone. `agent-run-history.tsx` / `agent-run-list-view.tsx` (the old `/crm-agents` list) are not mounted by anything and were left alone; the content map needed no change (the screen is still `AgentRunsView`).

**Mount point for track X** (`organization-detail-page.tsx`):
```tsx
import { OrgAgentPanel } from '@/components/domain/crm/org-agent-panel'
<OrgAgentPanel orgAccountId={identityId} onRecordsChanged={reloadNotesAndTasks} onOrganisationChanged={(id) => openIdentity(id)} />
// or, when only the CRM organisation is known (the identity is found, and made, through POST /api/org-accounts/for-crm/{id}):
<OrgAgentPanel crmOrganizationId={crmId} onRecordsChanged={reloadNotesAndTasks} />
```
It fetches its own data and needs no other prop.

## Deviations from the brief, with the reason

1. **Three events, not the plan's five**: `agent.run.started` and `agent.proposal.created` are the run and proposal rows themselves (the brief also said three). Recorded here for the owner.
2. **The agent API has its own read-only `GET /api/agent/organisations/resolve`**, but the front end resolves through X's `POST /api/org-accounts/for-crm/{id}` as the brief asked (it makes the identity when there is none, which the agent needs).
3. **`agent-run-history.tsx` and `agent-run-list-view.tsx` were not changed**: nothing mounts them (the content map maps the screen to `AgentRunsView`, whose demo was the problem), and they read the legacy `/crm-agents/runs`.
4. **The history screen has no free-text search** (the demo searched its own seed rows; the API has none).
5. **Evidence links are plain `<a href>`**, not `next/link` (the panel must not need a router): a full page load to the record. `org_account` and `link_suggestion` refs are text (the page the person is on; a question on it), because the 360 identity page's address is track X's to decide.
6. **`max_output_tokens` (1200) is a request, not a ceiling**: `AiModelClient` lets an administrator's saved per-key ceiling win (`AiModelClient.php:118-121`), so the token cap per run holds only while no ceiling is saved. Not changed (not my class); noted.

## Review findings (every backend file read against the design; what I changed and why)

1. **Race between "is a run under way?" and the insert** (two presses at once could both pass): the checks and the insert are now one transaction that locks the identity row (`OrgAgentService.php:55`). The lock holds on MariaDB only; sqlite has none, so the tests prove the checks and that they run inside the transaction, not the race. **Verified on the dev MariaDB 10.11** (item 11 below); not on 10.1.
2. **A calendar refusal made the WHOLE run `failed` after an earlier note had been applied** (`RecordTask` threw a bare `RuntimeException`, which `Gateway` did not catch). New `RecordRefused`; the task becomes a `failed` proposal with the calendar's own sentence and the run goes on. (A database failure is also a `RuntimeException`: only `RecordRefused` is turned into a sentence, so a raw SQL message can never reach a person.)
3. **Any other unexpected failure while applying ONE proposal also failed the whole run**: `Gateway::handle` now logs it, audits `internal_error` and goes on; the transaction already rolled the write back, so the proposal stays `pending` for a person.
4. **The context builder trusted the 360's exact shape** (track X is changing it): every read is tolerant (`data_get`), a constructor seam allows a thin 360 in tests, and `AgentContextTest` names every key it relies on.
5. **`proposal_counts` had no fixed shape** (see above): one shape, one order.
6. **The prompt** told the model nothing about dismissed signals; the first real run's data was a single Dismissed signal. A rule was added; the real run's brief says "no fresh news to act on".
7. **Runs and proposals did not follow a merged identity** (decision below).
8. Read and found sound, no change: tenant filters on every query (controller, presenter, applier, decisions, builder, audit); STEERING_FIELDS and the subject-only target; evidence labels and addresses taken from the context; plain-text clean-up; the unique key and the early duplicate check; the exactly-once claim; the kill switch read again at accept time; the registry; the policy failing closed; idempotent events (a unique index, with the run or proposal id in each key); the migrations (additive, guarded, utf8mb4 stated, no JSON, no foreign keys, explicit NULL defaults on timestamps).
9. **Found by the mutation sweep (`_evidence/s7-agent-gateway/mutations-2026-11-03.md`), then closed:** eight safeguards that no test exercised (the organisation check inside `RecordNote` and `RecordTask`; accept re-reading the level of ONE action; the `confirm_link` guard that a pair must still be the subject's; four lookups by organisation in the presenter and the panel's pending list, which only matter when a pointer was ever set into another organisation's rows) now have tests (`CrmRecordsTest`, two in `AgentRunServiceTest`, one in `AgentGatewayApiTest`, one in `AgentGatewayTenantSweepTest`); a failing `assert.equal(element, ...)` in the front-end tests crashed the process out of memory instead of naming the test (`lib/agent/assertions.ts`).
10. **Front end, found by reading:** a refused accept or dismissal used to read the panel again only when the server happened to return the proposal; it now always does, so a panel never keeps offering something the server has just refused (an action switched off meanwhile). The panel starts clean when the page moves to another organisation and drops a result that arrives for the one before.
11. **The row lock on the run start was checked on the real MariaDB** (two processes; `run-start-lock-dev-2026-11-03.md`): the second press waited 5.5 s, saw the first one's run and was refused `already_running`.
12. **Observations, not changed:** (a) `OrgResolver::suggestions($tenantId, PHP_INT_MAX)` computes every pair of the tenant on each run (3.7 s of the 11.7 s on dev for tenant 6; the 360 screen pays the same); (b) a proposal that cites only the organisation itself passes the evidence check (the ref is real); (c) a note is never compared with notes people typed, only with earlier proposals; (d) the agent can read signals through the Organizations right (the 360 shows the same data to the same people).

## Decision: runs and proposals follow a merged identity

**Problem.** A run and a proposal are about an Organisation 360 identity (`subject_id` = `g2g_org_accounts.id`). When two identities become one the older survives and the other is soft-deleted (`OrgLinker::merge`; `OrganisationMerger::identity()`). The agent's own `confirm_link` is the main way that happens, so left alone the agent would orphan its own data: the removed identity's PENDING proposals would be invisible (the panel lists by the survivor's id) and impossible to accept (the applier finds no subject).

**Decision (assistant, under the owner's standing instruction; door: two-way, it only moves pointers).** They follow. `AgentSubjects::repoint($tenant, $from, $to)`: every run moves; a proposal moves unless the survivor already holds the same one (action + payload hash: the unique key would refuse), in which case it stays where it was as history and, if it was waiting, is closed as `dismissed` with a note saying why. Tenant-scoped, idempotent, never throws (a merge must not fail because of this bookkeeping), a no-op on a host without the tables. It is called from `ConfirmLinkTool::apply` (when the survivor is not this subject) and from `OrganisationMerger::identity()` (the one additive line, 3 lines with the `use`). **Still to do on track X's side:** `OrgLinker::merge()` (`app/Domain/OrgAccounts/OrgLinker.php:166`, right after `$loser->delete();`) needs the same call so that a person's "Same organisation" button also takes the agent's history along: `\App\Domain\AgentGateway\AgentSubjects::repoint($loser->sub_institute_id, (int) $loser->id, (int) $survivor->id);` (I may not edit that file; this slice does not depend on it for the agent's own path).
`g2g_agent_proposals.entity_type/entity_id` (the CRM organisation a proposal was made for) is NOT moved by a CRM merge and is allow-listed in the scan test with that reason: it is informational and never used to find a record.

## The plan's S7 acceptance checks, and where this thin slice stands

| Plan check | Status |
|---|---|
| Every tool call writes an audit row | met: `g2g_agent_tool_calls` has a row for every read, the brief and every proposal with its verdict, a refusal included (`AgentRunServiceTest`, `AgentGatewayTest`) |
| Denial tests for wrong tenant, missing right, kill switch | met: `AgentGatewayTenantSweepTest` (every id route, read from the router), the rights matrix in `AgentGatewayApiTest`, the kill-switch tests in `AgentGatewayTest` / `AgentRunServiceTest` |
| ...for a tainted run and an exhausted budget | tainted run: not applicable in this design (no tool loop: the model is given no tools and its answer can only PROPOSE the three whitelisted actions, which the gateway judges); budget: the per-organisation daily cap is enforced and tested, the token cap is a request (see deviation 6) |
| Notes and tasks run unattended only when the setting is `alone` | met: the policy matrix test (`off` refuses, `approval` waits, `alone` applies) |
| Changing a setting is itself audited | NOT in this slice: there is no settings screen yet; `g2g_agent_autonomy` rows are written by an administrator's SQL until the Settings > Agents screen of the full S7 |
| The registry contains no send tool (a test lists it) | met: `AgentGatewayTest::test_the_registry_has_exactly_three_tools_and_none_can_send_delete_or_merge`, plus a source scan for mail, HTTP and delete calls |

## Tasks

| # | Task | Status |
|---|---|---|
| 1 | Stock-take, this file | done |
| 2 | The 4 API test failures | done: 2 test bugs fixed, presenter counts given a fixed shape; 17 of 17 |
| 3 | The scan test; the decision on merges | done: allow-list entry; `AgentSubjects` hooked into `OrganisationMerger` and `ConfirmLinkTool`; 8 tests; `OrgLinker::merge` needs the same call (track X) |
| 4 | Review of every backend file | done: findings above |
| 5 | Route-sweep test, migration up/down tests, claim race test | done: `AgentGatewayTenantSweepTest` (6), `AgentMigrationsTest` (12), `AgentApplierClaimTest` (2) |
| 6 | Mutation proofs for every safeguard | done: 86 safeguards broken on purpose (62 backend, 24 front end), 86 killed after 8 test gaps the first runs showed were closed; every file restored byte for byte: `_evidence/s7-agent-gateway/mutations-2026-11-03.md` |
| 7 | `agent:organisation-run` and ONE real run on dev (tenant 6) | done: `_evidence/s7-agent-gateway/real-run-dev-2026-11-03.md` (INR 0.075; 7 command tests) |
| 8 | Front end: `OrgAgentPanel`, `services/agent`, `lib/agent` | done |
| 9 | Front end: the Agent Run History screen on real data | done |
| 10 | Evidence, DB-CHANGELOG rows, report | done |

## What a person should check in a browser (the caller runs the combined check; I started no server)

1. Sign in as the tenant-6 administrator. Open **Sales & Marketing > Agent Run History** (`/module/crm/marketing/agent-runs`): run 1 (Tapovan Vidya Vihar, Completed, `Command line`, deepseek-flash, ₹0.075, 1 applied) is listed; no demonstration rows; Trace opens a dialog with the brief, the task marked Agent and the four-step trail; Escape closes it and the focus returns to Trace; the status filter and the two date fields narrow the list.
2. On the Organization detail page of **Tapovan Vidya Vihar** (CRM organisation 135, identity 1) once track X mounts the panel: the last run's brief and evidence links, "What the agent has done" with the task marked **Agent**; the task also shows on the organisation's Schedule tab (due 17 Nov).
3. Press **Run agent** on an organisation that has signals and a CRM record (not Tapovan's, whose only signal is dismissed): the button says Running, then a message says what the agent did and left; waiting proposals appear with **Accept** and **Dismiss**; Accept a note (it appears on the Notes tab starting `[Agent] `); Dismiss one with a reason (it is not offered again on the next run).
4. As a user with only view on Organizations: the panel reads, Run agent is disabled and says why.

## DB-CHANGELOG rows (migrations applied on dev on 3 Nov 2026 by the previous agent; nothing on live)

| # | Slice | Migration or command | Tables and columns | Applied on dev | Applied on live | Check and evidence | Rollback | Approved by |
|---|---|---|---|---|---|---|---|---|
| S7-1 | S7 | `2026_12_10_090000_create_g2g_agent_runs_table` | NEW `g2g_agent_runs` (24 columns: one row per run: agent, subject, trigger, status, model, tokens, cost + currency, brief + evidence as text, correlation id; 3 indexes, no unique key) | 2026-11-03, batch 560 | not yet (needs the owner's go + scratch-table probe) | `SHOW CREATE TABLE`: `_evidence/s7-agent-gateway/dev-show-create-2026-11-03.txt` | `down()` drops the table (tested) | dev: no go needed |
| S7-2 | S7 | `2026_12_10_090100_create_g2g_agent_proposals_table` | NEW `g2g_agent_proposals` (22 columns, one row per action; UNIQUE (tenant, subject_type, subject_id, action, payload_hash) is "never offered twice"; 2 indexes) | 2026-11-03, batch 561 | not yet | same | `down()` drops the table (tested) | dev: no go needed |
| S7-3 | S7 | `2026_12_10_090200_create_g2g_agent_tool_calls_table` | NEW `g2g_agent_tool_calls` (13 columns, the append-only audit of every read and action with its verdict; 1 index) | 2026-11-03, batch 562 | not yet | same | `down()` drops the table (tested) | dev: no go needed |
| S7-4 | S7 | `2026_12_10_090300_create_g2g_agent_autonomy_table` | NEW `g2g_agent_autonomy` (6 columns; UNIQUE (tenant, action_key); a row `*` at `off` is the kill switch) | 2026-11-03, batch 563 | not yet | same | `down()` drops the table (tested) | dev: no go needed |
| S7-5 | S7 | `2026_12_10_090400_add_created_via_to_crm_organization_comments` | `crm_organization_comments` gains `created_via` VARCHAR(12) NULL after `created_by` (every existing note stays NULL = a person) | 2026-11-03, batch 564 | not yet | same | `down()` drops the column (tested; notes stay) | dev: no go needed |
| S7-6 | S7 | data: `php artisan agent:organisation-run --tenant=6 --org-account=1` (dev) | 1 run, 1 proposal, 4 tool calls, 1 `ai_usage_events` row (id 827, INR 0.074995), 2 `g2g_event` rows (11057, 11058) and ONE task (`task_management_calendar_events` id 470, a follow-up on CRM organisation 135 due 17 Nov: the only row outside the agent tables) | 2026-11-03 20:52 IST | NOT run: the command refuses the `live` connection | `_evidence/s7-agent-gateway/real-run-dev-2026-11-03.md` | delete those rows by id (the task is the one real record) | dev: no go needed |

## Work log (append-only)

### 2026-11-03 20:40 — stock-take
Read the two briefs, the progress file, decisions, DB rules and the S7 plan; read every file of the previous agent's work; ran the four agent test files once and the scan test; read-only probes of dev; the g2gv0 tree was clean and `tsc` showed exactly the 14 known errors.

### 2026-11-03 20:40 to 21:00 — backend green and complete
Diagnosed the five failures (`_evidence/.../api-test-failures-diagnosis-2026-11-03.md`); fixed them; `AgentSubjects` and its three doors; review fixes 1 to 6; `AgentGatewayTenantSweepTest`, `AgentMigrationsTest`, the context contract tests, three run-service tests (calendar refusal, unexpected failure, one transaction); `agent:organisation-run` and its 7 tests.

### 2026-11-03 20:52 — one real run
Run 1 on tenant 6, identity 1: completed, 11.7 s, INR 0.075, one task applied alone. Evidence file above.

### 2026-11-03 21:00 to 21:40 — front end
Service, model, panel, history screen, dialog; 123 front-end tests; eslint 0 findings on 19 files; `tsc` has no error in any of my files (at the end 7 errors, all in other files and all in the baseline's list). The disk filled up at about 21:10 (another process; one write of mine failed with ENOSPC and was redone; every file of mine was checked complete afterwards).

### 2026-11-03 21:15 to 22:45 — mutation sweeps, gaps closed, final runs
First sweep: 74 safeguards broken on purpose, 70 killed, 4 survived (P10, P11, P21, P53): tests added (`CrmRecordsTest`, three in `AgentRunServiceTest` / `AgentGatewayApiTest`). Second PHP sweep (12 more, incl. P55 to P62): 4 survived (P57 to P60, the presenter lookups by organisation): one test with pointers planted into another organisation's rows added to `AgentGatewayTenantSweepTest`. Two front-end mutations were "killed" by a crash (a failing `assert.equal(element, element)` ran the process out of memory); every DOM assertion now fails in words (`lib/agent/assertions.ts`) and T08, T09, T19, T22 were killed by named tests on re-run. The row lock on the run start was proved on the real MariaDB (`run-start-lock-dev-2026-11-03.md`). Panel hardening found while writing the tests: starts clean for another organisation, drops a late result for the one before, reads again after any refusal. Final runs: 17 PHPUnit files (the 10 of this track, 125 tests, and 7 neighbours) 206 tests green; 123 front-end tests green; eslint 0 findings; `tsc` none in my files.

### Left on dev (documented, not removed)
Run 1, proposal 1, tool calls 1 to 4, `ai_usage_events` 827, events 11057 and 11058, and **task 470** (a follow-up for CRM organisation 135, due 17 Nov, written as the real proof). The lock probe's stand-in run (id 2) was deleted.
