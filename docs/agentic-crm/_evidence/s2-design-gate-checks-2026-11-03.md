# S2 design-gate checks, 3 Nov 2026

Every plan claim about Signals that the S2 slice depends on, re-checked against source and the two databases before any design was drawn (SESSION-RULES step 3). Read-only throughout: `SET SESSION TRANSACTION READ ONLY` on every connection, counts and column names only, no signal text printed.

Tools (outside the repo, in `D:\dev\agentic-crm-research\scripts\g2g\`): `s2_measure.php`, `s2_diff.php`. Run one at a time. Hosts: **dev** = connection `mysql` (202.47.117.220, database `hp_erp`, MariaDB 10.11.9); **live** = connection `live` (MariaDB 10.1.48).

Tags: **verified** = ran it and read the output; **read in source** = read the line myself; **inferred**; **unverified**.

## 1. The numbers (organisation 6, non-sample rows), verified

| | dev | live |
|---|---|---|
| Signals in `g2g_company_opportunities` (all organisations) | 48 (all organisation 6) | 84 (org 6: 73, org 0: 10, org 1: 1) |
| With an `event_date` | 19 of 48 (60% have none) | 16 of 73 (**78% have none**) |
| With a `source_published_at` | 21 | 24 |
| Ever reviewed (`review_status` not `New`) | 3 (2 Dismissed, 1 Follow-up) = 6% | 5, all Follow-up = **7%** (the one Dismissed on live belongs to another organisation) |
| `review_notes` filled in | 0 | 0 |
| Runs in `g2g_research_runs` (organisation 6) | 84 | 74 (44 success, 19 failed, 9 skipped, 2 partial) |
| Run trigger | all `manual` | all `manual` |
| Last run | 30 Oct 2026 | **2 Nov 2026 15:33** (live is in active use) |
| `ai_usage_events` rows, organisation 6 | 551 | 195 |
| Signals usage rows with `related_type` empty | 116 of 116 | 114 of 114 |
| `g2g_event` rows whose type starts `signals.` | 0 | 0 |
| `g2g_platform_task_runs` rows | 2 (events:project, events:react) | 2 (readiness.recompute, leave.escalate); **none for signals** |
| `crm_signal_inbox` rows | 0 | 0 |

Three-way date classification from the columns (verified, `q3`): organisation 6 on live = **16 event-dated, 8 source-dated only, 49 undated** (73); on dev = 19 / 2 / 27 (48). All organisations on live: 16 / 9 / 59 (84).

Plan said 75% undated, 8% reviewed, 72 manual runs: still right in shape (the data moved since the baseline; live now has more signals and more runs). The plan's earlier "6 reviewed on live" is now 5 for organisation 6.

Product placement on live, organisation 6 (73 signals): `business_fit` = scholar 39, g2g 9, eb 3, empty 22; `product_key` (scope column) = g2g 9, scholarclone 4, empty 60; `relevant_offer_id` set on 11. So the Product Master backfill must try, in order: `relevant_offer_id` (through the master's offer pointer), `product_key`, `business_fit`; the remainder stays empty and is counted.

`feed_section` on live: watchlist 58, immediate_action 12, market_intelligence 2, competitor_intelligence 1.

## 2. Findings that change the plan

**F-S2-1. Dev and live have drifted, in the direction nobody expected: live is ahead on Signals.** Verified by `SHOW COLUMNS` and the `migrations` table on both hosts.

- Live-only columns on `g2g_company_opportunities`: `product_key`, `offer_id`, `customer_model`, `icp_profile_id`, `icp_version`, `region_scope`. Live-only on `g2g_research_runs`: `stage_stats`, `icp_version`, `customer_model`, `offer_id`, `region_scope`, `scope_key`; `stage_message` is TEXT on live and varchar(255) on dev.
- Live-only indexes: UNIQUE `g2g_opps_tenant_fingerprint_uq (sub_institute_id, fingerprint)`, `g2g_opp_scope (sub_institute_id, product_key, customer_model)`, `g2g_research_runs_scope_key_index`.
- Live-only migration rows: `2026_10_31_090000_add_research_run_configuration_scope` (batch 403), `2026_12_02_090000_widen_stage_message_on_g2g_research_runs` (379), `2026_12_03_090000_add_stage_stats_to_g2g_research_runs` (388), `2026_12_07_090000_add_scope_to_g2g_company_opportunities` (402), `2026_12_07_100000_add_uniqueness_to_signal_opportunities` (404).
- Where the files are: `2026_10_31_090000_…` is on `origin/main` and `origin/harshit` (commit `8053ce87`, Harshit) but **not on `Milan-2`**. `2026_12_03_090000_add_stage_stats_…` is on this branch but not run on dev. **`2026_12_07_090000_add_scope_…` and `2026_12_07_100000_add_uniqueness_…` are in no branch of this clone** (pickaxe `git log --all -S` on their index names finds only my own S0 baseline document). They were run on live from a checkout that is not in git here.
- `origin/main` is 18 commits ahead of `Milan-2` and `Milan-2` is 20 ahead of `main`; the 18 include Signals changes (`ResearchRunScope.php`, `ProductCatalog.php`, `QueryExpander.php`, `OpportunityController.php` +83 lines, `config/signals.php`).
- Consequence: S2 must not be built on this branch's Signals code as it stands. Its first task is to bring the Signals code up to date and to locate the two missing migration files. Every S2 migration is guarded (`Schema::hasColumn` / index check), names itself to sort after `2026_12_07_100000`, and never assumes the scope columns exist.

**F-S2-2. The plan's "75% have a date" backfill source covers only a third of the rows.** `assessment` is filled on 24 of 73 live signals (9 of 48 on dev); every one of them mentions `date_status` (live: `event_date_stated` 3, `source_date_only` 7, `undated` 14). The other 49 have no assessment at all. So `date_status` is derived from the columns (`event_date` set → `event_date_stated`; else `source_published_at` set → `source_date_only`; else `undated`), which works for every row without reading JSON. The assessment value is used only as a cross-check on the 24, and any disagreement is reported, not overwritten. (Live has 16 rows with `event_date` but only 3 assessments say `event_date_stated`: that mismatch is itself a finding for the owner, to be explained in task 3.)

**F-S2-3. Signals already has a menu branch and it is not new.** Read in source: `2026_11_29_080200_add_signals_menu_branch.php` creates parent "Signals" (`/module/crm/signals`, under root 199) with leaves `/signals` (Company Opportunities), `/ingestion`, `/portfolio`, `/partners`, granted to administrator, hr_manager, hr_executive and reporting_manager. In g2gv0 both `/module/crm/signals` and `/signals` render `MarketSignalsView` → `SignalsPageContent` → `ScholarSignalsView` (689 lines). The plan's "Today › Signals" menu row therefore becomes a new leaf under the existing Signals parent, found by `access_link`, administrator first (same pattern as the S1 Product Master row).

**F-S2-4. Signal Inbox: the decision was largely taken on `origin/main`.** Read in source: `2026_12_06_110000_remove_signal_inbox_menu_row.php` (Harshit, on `origin/main`) deactivates the Signal Inbox menu row (status 0) and points Market Signals at `/signals`. `crm_signal_inbox` is empty on both hosts. D11 confirmed in source: `signal_key varchar(64)->unique()` (migration line 32), no tenant in the index. D16 needs a correction: `crm_signal_inbox` **does exist on live** (25 columns, 0 rows) although the migration (which uses `json()` for `tags`, line 61) has no row in live's `migrations` table. So a `json()` table can exist on live if it was created some other way; I have not checked how, and S2 does not depend on it.

**F-S2-5. D13 confirmed three ways.** (a) Source: `OpportunityController::act` writes `review_status`, `review_notes`, `reviewed_by`, `reviewed_at` onto the signal row (overwrite). (b) Front end: `scholar-signals-view.tsx` lines 668-669 show two buttons, "Contacted" (action `follow-up`) and "Not relevant" (`dismiss`); neither collects a reason or note. (c) Data: `review_notes` is empty on every row on both hosts; zero `signals.*` events on both hosts.

**F-S2-6. D14 confirmed.** `routes/console.php:273` guards the schedule with `config('signals.scheduled_research', false)`; the comment says research is started by a person pressing Generate Signals. `g2g_platform_task_runs` has two rows per host and none is a signals task. (Whether `ProductProfileService::isDue()` exists and is unused is **unverified**; read at task 9.)

**F-S2-7. D15 confirmed.** `StructuredAi::json()` (lines 42-48) calls `AiModelClient::complete()` with no `meter` option; other modules pass `'meter' => ['module' => …, 'institute' => …, 'related_type' => …, 'related_id' => …]` (for example `AssessmentScoringService.php:195`). Data agrees: every Signals usage row has an empty `related_type`. There are no cost columns on `g2g_research_runs` on either host. `stage_stats` exists on live and not on dev (F-S2-1).

**F-S2-8. How an event must be registered.** Read in source: `EventCatalogue::SHIPPED` is `event => [consumer => P|R]` and `assertInvariants()` fails an event with no consumer ("a slower log"). `AuditLogProjector::handles()` returns true for every type, so an event registered with `'AuditLogProjector' => self::PROJECTOR` passes (the pattern `crm.lead.created` uses, line 317). `EventRecorder::record($type, $tenant, $entityType, $entityId, $actorId, $payload, $metadata, $idempotencyKey, …)` throws on a repeated `(tenant, idempotency_key)`; `GtmAudit::record` shows the catch-and-return-existing-id pattern. S2 adds a small `SignalAudit::record` doing the same.

## 3. Not verified

- How the two live-only migrations are written (files not available). Their columns and indexes are verified from the database, not from their code.
- Whether the production scheduler or a queue worker runs, and `QUEUE_CONNECTION` on the app host.
- Where search-credit counts are available per run (RunBudget counters are the likely source; read at task 6).
- The reason-code list (owner decides).
