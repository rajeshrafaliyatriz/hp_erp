# S2 — Signals front door

**Status:** **BUILT and verified on dev, 3 Nov 2026 (tasks 0 to 13 of 15 done). Not pushed, not on live.** The owner answered the six gate questions (`DECISIONS.md`); `S02-contract.md` refines this file (two entry points for decisions, the `via` column, a fourth index, the cost class named `RunCost`, flat `related_type`/`related_id` instead of `meter`, nine reasons instead of eight). Waiting on: the owner's review of the finished screen, and the owner's explicit "go" for task 14 (live rollout). Evidence: `_evidence/s2-design-gate-checks-2026-11-03.md`, `s2-tasks1-3-merge-and-dev-schema-2026-11-03.md`, `s2-tasks4-9-dev-backfills-and-menu-2026-11-03.md`, `s2-tracks-review-2026-11-03.md`, `s2-browser-walkthrough-2026-11-03.md`.

## Goal

A person can open Signals, see only the signals worth a look, see **why** each one is there (the quote, the source, how old it really is, which product it fits), decide what to do with it, and have that decision **kept, with a reason**. The system then knows which signals people trust, what each research run cost, and whether runs are healthy.

## What is wrong today (all checked, none assumed)

1. Deciding on a signal **overwrites** the previous decision. Two buttons exist ("Contacted", "Not relevant"); neither asks for a reason or a note. `review_notes` is empty on every row on both hosts. No event is written (0 `signals.*` events on both hosts).
2. Most signals cannot be told apart by age: **78% of live signals have no event date** (16 of 73), and only 7% were ever reviewed (5 of 73).
3. A run's cost is not stored anywhere. Signals AI calls carry no link to the run (`related_type` empty on every Signals usage row). Runs are all manual; the scheduler has never run a signals task.
4. The signal does not point at the Product Master. Placement today rests on three loose fields (`business_fit`, `product_key`, `relevant_offer_id`), and 22 of the 73 live signals have no `business_fit`.
5. The feed filters on columns that have no index.

## Scope

**In:** a new review screen beside the old one; a decision history table; reason codes; events; the Product Master link on signals and runs; a trustworthy date status; run cost and health; the missing indexes.
**Out:** sending anything; changing how signals are found (that is S5 and the existing researcher); agent tools (S8); moving data out of `crm_signal_inbox` (empty); deleting or hiding the old Signals screens (owner decides later); touching the scheduler settings on any server (ops, owner arranges).

## Precondition (task 1): bring the Signals code up to date

Live is ahead of this branch on Signals (finding F-S2-1). `origin/main` is 18 commits ahead on exactly the files S2 touches, and two migrations that already ran on live are in no branch I can see. **Before any S2 code:** the owner decides how `Milan-2` meets `origin/main` (merge or rebase; shared history, so not mine to choose alone), and someone supplies `2026_12_07_090000_add_scope_to_g2g_company_opportunities` and `2026_12_07_100000_add_uniqueness_to_signal_opportunities`. Until then every S2 migration is written to work **with or without** the scope columns.

## Database changes (additive only; every file guarded; names sort after `2026_12_07_100000`, so they start at `2026_12_08_`)

1. **NEW `g2g_signal_reviews`** — decision history. `id` BIGINT UNSIGNED, `sub_institute_id` BIGINT UNSIGNED NOT NULL, `opportunity_id` BIGINT UNSIGNED, `decision` varchar(12) (`reviewed`, `dismissed`, `follow_up`, `reopened`), `reason_code` varchar(30) NULL, `note` text NULL, `from_status` varchar(12), `to_status` varchar(12), `decided_by` BIGINT UNSIGNED, `decided_at` datetime, `created_at` datetime. Index `(sub_institute_id, opportunity_id, decided_at)`. No foreign key, `utf8mb4_unicode_ci`.
2. **ALTER `g2g_company_opportunities`** — add `product_master_id` BIGINT UNSIGNED NULL; `date_status` varchar(20) NULL (`event_date_stated`, `source_date_only`, `undated`); three indexes `(sub_institute_id, business_fit, review_status)`, `(sub_institute_id, first_discovered_at)`, `(sub_institute_id, event_date)`. Each added only if absent. Table has 84 rows on live, so index cost is nil. The existing `review_status`, `review_notes`, `reviewed_by`, `reviewed_at` columns stay and keep showing the **latest** decision, so the old screens keep working untouched.
3. **ALTER `g2g_research_runs`** — add `product_master_id` BIGINT UNSIGNED NULL; `ai_input_tokens` INT UNSIGNED NULL; `ai_output_tokens` INT UNSIGNED NULL; `ai_cost_usd` DECIMAL(10,6) NULL; `search_requests` SMALLINT UNSIGNED NULL; `search_credits` DECIMAL(10,2) NULL. `stage_stats` is **not** added here: it already exists on live and its migration file (`2026_12_03_090000_…`) is already in the branch; it is simply run on dev (task 2).
4. **DATA, three separate dry-run-default commands** (never inside a migration):
   - `signals:backfill-date-status`: from the **columns** (not the JSON, which exists on only 24 of 73 live rows, F-S2-2). Cross-checks against `assessment.evidence.date_status` on the rows that have it and prints disagreements. Never overwrites a non-empty value.
   - `signals:backfill-reviews`: one history row per already-reviewed signal (5 on live for organisation 6, 3 on dev), `reason_code` and `note` empty (honestly unknown), idempotent on `(opportunity_id, decided_at, decision)`.
   - `signals:backfill-product`: sets `product_master_id` through the S1 resolver in this order: `relevant_offer_id`, `product_key`, `business_fit`. Unresolved rows stay empty and are counted. Never name-matches.
5. **Reason codes** live in `config/signals.php`, not a table: `not_a_buyer`, `already_customer`, `stale`, `wrong_product`, `duplicate`, `source_unreliable`, `no_budget`, `other`. Owner confirms (question 3). Dismiss requires one; "Contacted" and "Reviewed" take an optional note.
6. **Events**: `signals.opportunity.reviewed`, `signals.opportunity.dismissed`, `signals.opportunity.contacted`, `signals.opportunity.reopened`, `signals.opportunity.promoted`, registered in `EventCatalogue::SHIPPED` with `AuditLogProjector` (same as `crm.lead.created`, F-S2-8). Idempotency key `signals.opportunity.<decision>:{tenant}:{opportunity}:{reviewRowId}`. A small `SignalAudit::record` catches the repeated-key exception like `GtmAudit` does.
7. **MENU**: one new leaf under the existing Signals parent (`/module/crm/signals`), label "Signal Review", matched by `access_link`, **Administrator only** to begin with (owner widens it in the rights screen after counting the audience; never grant the parent). The *decision* right stays the existing `platformright:/signals,edit`, so a person who can decide today can decide from the new screen and nobody gains power (question 1).

Rollback: the migrations' `down()` removes only what `up()` added; the three data commands only fill new columns or the new table, so rollback is nulling those columns or truncating `g2g_signal_reviews`.

## Backend

- `SignalReviewService` (new): one transaction writes the history row, updates the signal's latest-decision columns and records the event. `act` calls it. `act` also starts validating `reason_code` (required for `dismiss`, must be in the config list) and `notes` (max 1000), refuses a decision that does not change anything (dismissing a dismissed signal), and keeps its 404-on-wrong-tenant behaviour. Today `act` saves the row twice and has no state check (source, lines 552-585).
- `GET /api/signals/opportunities/{id}/reviews` (history) and the existing `index` gets filters `product_master_id`, `date_status`, `freshness` (`last_30_days`, `last_90_days`, `older`, `undated`) and a sort that uses event date, else source date, else first seen. The old `index` behaviour is unchanged when the new filters are absent.
- `promoteToCrm` also writes a history row and an event, and passes `product_master_id` to the lead only when S6 adds the column (not in S2).
- `StructuredAi` passes `'meter' => ['module' => …, 'institute' => …, 'related_type' => 'g2g_research_run', 'related_id' => <run id>]`. The run id reaches it through a small `RunContext` (same idea as the existing `RunBudget`), so no call site changes signature.
- `RunAccounting` fills the cost columns when a run finishes: AI tokens and cost summed from `ai_usage_events` for that run; search counts from the run's own counters (read in task 6, F-S2-7).
- Run screens read products from the Product Master `options` endpoint.

## Frontend (g2gv0, on the S1 scaffolds; `components/ui` untouched)

New screen "Signal Review" (mock-up: `s2-mockup.html`, in the plan artifact):

- **List** (ListPage + URL-synced filters): facets Product, Status (New / Contacted / Reviewed / Dismissed), Freshness, Fit; search; sort. Each row: title, organisation, product chip, **date badge that says what kind of date it is** ("Event 12 Oct 2026", "Published 3 Oct 2026", "Date unknown"), fit, status.
- **Drawer** (RecordDrawer): Why it matters; **Evidence** (verbatim quote, source link, how the date was found, fit and score); **History** (every decision, who, when, reason, note); decision buttons.
- **Decision dialog**: Not relevant needs a reason from the list; Contacted and Reviewed take an optional note; Reopen after a dismissal.
- **Runs tab**: run history with trigger, status, duration, products, signals found, tokens, cost, and the failed/skipped reason. Every run shows "manual" until the scheduler is on.
- States designed and tested: loading, empty, filtered-empty, error with retry, saving, conflict (someone else decided first → shows their decision).
- The old `ScholarSignalsView` and its buttons are **not changed**; they keep calling `act` without a reason, which stays accepted for `review` and `follow-up` (a dismissal without a reason from the old screen is stored with `reason_code` = `other` and a note "decided on the original screen" so history is never blank).

## Agent hook

None in S2. The "why it matters" text already exists on each signal; S8 adds read-only `list_signals` and `explain_signal`. The history table and events are what let the agent layer see which signals people act on.

## Owner questions for this gate

1. **Where does it live, and who may decide?** Recommend: a new leaf "Signal Review" beside the existing ones, Administrator-only to start, the old Company Opportunities screen untouched; the right to *decide* stays the existing `/signals` edit right. Alternative: replace the existing screen in place (riskier; breaks the "build beside, retire later" rule).
2. **Branch state.** May I merge `origin/main` into `Milan-2` as the first S2 task (18 commits, Signals files), and can you or Harshit supply the two migration files that exist only on live?
3. **Reason codes.** Are these eight right: not a buyer, already a customer, too old, wrong product, duplicate, unreliable source, no budget, other?
4. **Undated signals.** 78% of live signals have no event date. Recommend: show them, badge them "Date unknown", and default the Freshness facet to "all" so nothing disappears. Alternative: hide undated signals by default.
5. **The scheduler.** S2 shows run health but does not switch the scheduler on. Turning it on (`SIGNALS_SCHEDULED_RESEARCH=true`, the server cron, a queue worker) spends search and AI quota on its own; do you want that arranged separately, and when?
6. **Signal Inbox.** `origin/main` already hides its menu row and the table is empty on both hosts. Recommend: leave it exactly as is and move nothing (default).

## Acceptance checks

1. Every decision writes one history row and one event in one transaction; a forced failure of the event leaves no history row and no changed signal.
2. Dismiss without a reason is refused (422, field-keyed); an unknown reason is refused; an unchanged decision is refused with a clear message.
3. History is append-only: a second decision never edits the first row.
4. `date_status` backfill: every non-sample row classified; counts for organisation 6 on live equal 16 event-dated, 8 source-dated only, 49 undated (derived from the columns, measured 3 Nov); the assessment cross-check disagreements are printed and explained.
5. `signals:backfill-reviews` creates exactly 5 rows on live for organisation 6; a second run creates none.
6. `product_master_id`: counts by resolution path are printed; unresolved rows stay empty; a second run changes nothing.
7. After a run, the cost columns are filled and equal the sum of the matching `ai_usage_events` rows; a run with no AI use stores zeros, not NULL.
8. Cross-tenant read and write return 404; a user without the `/signals` edit right gets 403 on every decision endpoint.
9. The old screen's buttons still work, and their decisions appear in the new History.
10. `EventCatalogue::assertInvariants()` returns no errors.
11. Browser walk-through on dev with screenshots, light mode, including the empty, error and conflict states.
12. Schema compared dev vs live with `SHOW COLUMNS` / `SHOW INDEX` for the three tables before and after; existing Signals tests stay green; `git diff --stat components/ui/` empty.

## Tasks

| # | Task | Done-check | Status |
|---|---|---|---|
| 0 | Re-verify agent-read claims, measure both hosts read-only | `_evidence/s2-design-gate-checks-2026-11-03.md` | **done 2026-11-03** |
| 1 | Owner approves the mock-up and answers questions 1 to 6; branch brought up to date; two missing migration files found | decisions logged in `DECISIONS.md` | **done 2026-11-03** (merge `4fe2886b`; the two files were on `origin/main`) |
| 2 | Run the existing `stage_stats` migration on dev; schema diff dev vs live saved | `SHOW COLUMNS` equal for the Signals tables apart from the live-only scope columns | **done 2026-11-03** (five main-branch migrations on dev; dev now equals live apart from the new S2 columns) |
| 3 | Migrations for `g2g_signal_reviews`, the two ALTERs, the indexes; model; tests running the real `up()` and `down()` | PHPUnit green; applied on dev one file at a time | **done 2026-11-03** (22 tests; 3 mutations caught; applied on dev; `f5ceb313`) |
| 4 | Date-status backfill command; explain the 16 vs 3 `event_date` mismatch | dry run printed, then applied on dev, re-run changes nothing | **done 2026-11-03.** Dry run, apply and re-run on dev: 19 / 2 / 27, second run writes nothing; independent SQL check 0 mismatches. The "16 vs 3" gap is two populations: the stored assessment note exists on only 9 dev and 24 live rows and **all agree with the columns** (0 disagreements on dev and, read-only, on live); the columns classify all 73. `_evidence/s2-tasks4-9-dev-backfills-and-menu-2026-11-03.md` |
| 5 | `SignalReviewService`, `act` validation, events, `SignalAudit`, `EventCatalogue` entries | API tests including rollback, 403, 404, 422; mutation sweep | **done 2026-11-03.** `SignalReviewService`, `SignalAudit`, 5 event types, strict `POST /decisions`, lenient `act`, `GET /reviews`, promote history. 50 API tests, 10 + 3 controller mutations caught. `_evidence/s2-tracks-review-2026-11-03.md` |
| 6 | `RunContext` and `meter` in `StructuredAi`; `RunAccounting` | usage rows carry the run id; cost columns equal the sums | **done 2026-11-03.** `RunContext` + `RunCost` (renamed from RunAccounting, which already exists); attribution uses the flat `related_type`/`related_id` keys `AiModelClient` really forwards (the contract's `meter` was wrong). 29 tests, 6 mutations caught. **Dollar cost is NULL everywhere today** (no model prices configured); tokens are recorded |
| 7 | Reviews backfill and Product backfill commands | dry run, apply on dev, re-run empty | **done 2026-11-03.** Dry run, apply and re-run on dev: 3 history rows, 40 products set (all by `business_fit`), 8 left empty by design; independent SQL check clean |
| 8 | Index and filter work in `index`; history endpoint | query tests; old behaviour unchanged without new filters | **done 2026-11-03.** `FeedFilters` (product, date status, freshness, `sort=recent`, `date_info`, product names) + `GET /reviews`; the default request is byte-for-byte the old one (proved against the pre-edit code). The date SQL ran on MariaDB 10.11 (dev) and 10.1.48 (live, read-only) with identical counts to the gate measurements |
| 9 | Menu leaf migration; read `ProductProfileService::isDue()` and decide with the owner whether to wire or leave | row present, admin only | **done 2026-11-03.** Menu row 506 on dev, `signals:grant-review` to the Admin profile only; independent check: one rights row, none above the leaf. `ProductProfileService::isDue()` left alone: the scheduler is not part of S2 (owner answer 5) |
| 10 | Front end: service, types, list, facets, URL state | tsc clean, unit tests | **done 2026-11-03.** Service, types, list, facets, URL state; 185 front-end tests (shared with 11 and 12), `tsc` and eslint clean on these files, `components/ui` untouched |
| 11 | Drawer: evidence, history, decision dialog, states | jsdom tests | **done 2026-11-03.** Drawer, evidence, history, decision dialog, all states; browser-checked in run 2 |
| 12 | Runs tab | renders cost and health | **done 2026-11-03.** Runs panel; seen in a real browser: tokens, cost, search requests, dashes for unknown, the "started by hand" line |
| 13 | Browser walk-through and log | screenshots in `D:\dev\agentic-crm-research\evidence\` | **done 2026-11-03.** 71 of 71 checks (run 2) + 7 of 7 phone checks, dev database, tenant-6 admin; 7 genuine decisions = 7 events; cleaned back to baseline. Not covered: a 403 for a user without the edit right. `_evidence/s2-browser-walkthrough-2026-11-03.md` |
| 14 | Live rollout | **only after the owner's "go"**; schema compare, scratch probe, one file at a time | waiting |

## Risks

- Live is in active use (last run 2 Nov 15:33): a migration there must be quick and non-locking. All changes are small ADD COLUMN / ADD INDEX on an 84-row table.
- The old screens write `review_status` directly; keeping those columns as "latest decision" is what makes the new history safe to add without touching them.
- The researcher code is being changed on `origin/main` by another developer. Merge first, build second, or S2 will collide with it.
- Mixing up `Follow-up` (stored value) and "Contacted" (what the old screen shows): the new screen uses the same words as the old one.

## Old screen: keep / hide / retire

Default **keep** (old Company Opportunities / Market Signals screen). Decided by the owner on: ______

## Work log (append-only)

- **2026-11-03** — Design gate opened. Measured dev and live read-only (`s2_measure.php`, `s2_diff.php`), re-verified D11, D13, D14, D15, D16 (corrected), the event-registration rules, the menu branch and the front-end route. Found live is ahead of the branch on Signals (F-S2-1) and that the date backfill must come from columns (F-S2-2). Wrote this file; mock-up next. **Not verified:** the two live-only migration files, the production scheduler, search-credit source.
- **2026-11-03 (build day)** — Owner answered the six questions (`DECISIONS.md`). Task 1: fetched and merged `origin/main` (`4fe2886b`, one identical conflict in `DealController.php`); the two "live-only" migrations were on main all along. Task 2: five main-branch migrations run on dev one at a time; dev now equals live apart from the new S2 columns. Task 3: three S2 migrations + `DateStatus` + reasons config + tests (`f5ceb313`), applied on dev, `SHOW CREATE TABLE`/`SHOW INDEX` checked. Wrote `S02-contract.md` and the shared TypeScript types, then started six parallel tracks (A decision service and API, B feed filters, C run cost, D backfills and menu, E1 list and drawer, E2 dialog/history/runs) on disjoint files. **Found:** `RunAccounting` already exists with another purpose (cost class renamed `RunCost`); my own `DateStatus` test caught `Carbon::parse('next week')` accepting relative words (fixed to a strict `YYYY-MM-DD` check). **Slip:** a shell heredoc with backticks garbled one doc edit (the known trap); the file was restored from git and redone with the Edit tool. **Not verified:** nothing of S2 has run on live.
- **2026-11-03 (build day, later)** — Tasks 4 to 13 done. Six tracks built in parallel to the contract and I reviewed each (re-ran its tests, read its core code, spot-checked claims): D backfills/menu/grant (`af597035`), C run cost (`cdeb877e`), my importer stamps (`c16d099c`), A+B decision API and feed filters (`9df3024a`), front end (g2gv0 `1f01739`, polish `c853599`). Combined PHP batch 567 tests OK; front end 190 OK; every track proved its tests can fail (mutations). Dev: backfills applied (19/2/27 date status, 3 history rows, 40 products; second runs write nothing; independent SQL clean), menu row 506 + Admin-only leaf grant, then the real-browser walk-through: 71/71 + 7/7 phone, events 7 for 7 decisions, cleaned to baseline. **Corrections to the contract/plan:** the contract's `meter` option is not read by `AiModelClient` (flat `related_type`/`related_id` are); `RunAccounting` was taken (`RunCost`); the "two live-only migrations" were on `origin/main`; the "16 vs 3" date gap is two populations (assessment note on 24 live rows, all agreeing with the columns). **Found:** dollar cost is NULL on every AI usage row on both hosts (no model prices). **Slips:** one shell heredoc with backticks garbled a doc (restored, redone); my walk-through script's first version read lists before they refetched (fixed, re-run). **Not verified:** a 403 for a user without the edit right in a browser; anything on live; concurrent deciders on MariaDB.
