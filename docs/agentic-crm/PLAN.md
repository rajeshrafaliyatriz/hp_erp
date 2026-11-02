# Agentic CRM: slice-by-slice build plan (v2, with the database design)

> **Snapshot** of the plan as approved on 2026-11-02, before the S0 baseline. It is kept unchanged except for the "Addendum" at the end. Later changes are recorded in `00-PROGRESS.md`, `DECISIONS.md`, `DB-RULES.md` and the slice files. **Where they differ, those files win.** Known differences already: D12 (the own-product table has run on live), D20 and D21 (new defects), section 4 rule 4 (database rules, now in `DB-RULES.md`).

## 1. Context

The architecture page is finished (https://claude.ai/artifact/1ZoowKicrqvTLrunZwE2aK). Its seven-phase roadmap is too coarse for work this big. The owner asked for three things:

1. **A log rule.** Every session reads and updates a local progress log, so nothing depends on memory or a summary.
2. **A start point and branch order.** Start with the product, then follow the branches: campaign to lead, product to lead, lead to contact and organisation, playbook, outreach, chat, agents.
3. **A full redesign, one feature at a time**, with the database changes (add, alter, update) planned just as carefully as the screens.

This version adds the database design per slice, from a read of the migrations, models, controllers and screens (2 Nov 2026). Facts below are tagged:

- **verified**: I read the source line myself.
- **agent-read**: a read-only subagent reported it. Re-verify at the slice start (rule 3 below).
- **unverified**: needs a live database or server check (slice 0 measures these).

Nothing has been run against any database. Migration files describe the intended schema; the live schema can differ (known trap).

## 2. Decisions recorded (owner answers, 2 Nov 2026)

| Question | Answer | What it means |
|---|---|---|
| Pilot organisation | Tenant 6 | New screens are granted to tenant 6 only until the owner says otherwise. |
| Monthly budget | 10,000 credits now; open-source tools to be found later | Provider and unit unconfirmed (assumed: shared by Tavily and Context.dev; model cost separate). Built as a per-tenant cap setting (`g2g_usage_budgets`), not a constant. |
| Who runs the new service | Everything stays in hp_erp, g2gv0, db3 and the dev database | **No separate TypeScript service and no new database.** Agent layer is PHP in hp_erp; screens and chat are g2gv0. |
| Actions without a person | Default none, except adding notes and tasks; an admin can change this | Autonomy is an admin-editable setting per organisation and action (slice 7). I recommend a hard code ceiling on sending and deleting; the owner confirms in slice 7. |
| Which screens retire | Decided one by one, later | New screens are built beside the old ones. Nothing is hidden or removed until the owner records a decision for that screen. |

**About "option B":** it was the first page's old design, "add a separate agent service next to G2G". Your answer rules it out. The two pages also used letters differently (page 1: A side by side, B add the brain, C replace; architecture page: A extend, B open-source base, C build new, D hybrid). Fix in slice 0: drop the letters on page 1, say "hybrid" on both, and replace the separate service with the PHP agent layer.

## 3. The working system (slice 0): rules that stop context loss

**Files in `hp_erp/Docs/agentic-crm/`** (same convention as `sales-marketing-merge/00-PROGRESS.md`):

- `00-PROGRESS.md`: **read first.** Slice checklist table, one-line "Next action", open owner decisions, known traps, newest-first log (`Built`, `Verified (how)`, `Not verified`, `Next`).
- `DECISIONS.md`: section 2 above plus every later decision with date and decider.
- `DB-CHANGELOG.md`: one row per migration or data update: file, tables and columns, dev applied (date), live applied (date), verification query and evidence path, rollback note, who approved.
- `DB-RULES.md`: section 4 below, finalised in slice 0 after reading the real helper classes.
- `FEATURE-LEDGER.md`: one row per feature: status, files touched, tests, evidence path, owner sign-off.
- `slices/S##-name.md`: goal, scope in and out, design link, **database changes**, API changes, agent hook with autonomy level, numbered acceptance checks, task list with status, append-only dated work log.
- `_evidence/`: command output, query results, screenshots. This session's schema findings and defect register are saved here in slice 0 (appendix A and section 5), so later sessions do not redo the reading.
- `SESSION-RULES.md`: the protocol below. A one-line pointer goes in `hp_erp/CLAUDE.local.md` (excluded via `.git/info/exclude`, so teammates' sessions are unaffected). A feedback memory is saved too.

**Session protocol:**

1. **Start.** Read `00-PROGRESS.md`, then the current slice file; say in three lines what the next task is. After any context compaction, re-read both before anything else. Never rely on the summary.
2. **During.** One task at a time. After each task, append to the slice log: what was done, the command run, the result, the evidence path. Tag every claim `verified`, `read in source`, `inferred` or `unverified`.
3. **Verify first.** Re-check any earlier claim, including subagent reports, against the code or database before building on it. Spot-check at least two claims from every subagent report.
4. **Done means evidence.** A task is done only when its check ran and the output is saved. If a check fails twice, stop, log it, and report it.
5. **End.** Update `00-PROGRESS.md`: checklist row, log entry, next action, new traps. The last chat message gives a one-line status with a rough percent, for example "Slice 2: 5 of 12 tasks; overall 1 of 15 slices".
6. **Commits.** Small commits per task, only the files that task touched. Never push unless asked. A second session shares this git identity, so confirm authorship from the diff. Logs are committed with the code they describe.
7. **Design gate.** Each slice starts with a one-page brief and a light-mode mock-up (published privately). The owner approves or changes it before code.
8. **Retire line.** Each slice ends with "Old screen: keep / hide / retire, decided by the owner on <date>". Default: keep.

## 4. Database change protocol (applies to every slice)

1. **Additive only.** Expand, then migrate data, then (much later, its own slice) contract. Never drop, rename or retype a column existing screens use. Never edit a migration that has run on any host.
2. **Name files to sort after the newest existing migration**, not by today's date. The newest I saw is `2026_12_06_100000_…`, which is later than the calendar date. Check with a directory listing before naming.
3. **One migration file per change set**, one file at a time. Never run `migrate --database=live` unscoped (live is "far behind"); always `--path=<file>`.
4. **MariaDB 10.1 rules** (agent-read plus repo comments; slice 0 confirms against the helper classes):
   - No `json()` columns: use `longText` and cast in the model. (Some Dec-2026 tables already use `json()`, so they cannot exist on live.)
   - No bound-parameter `SHOW COLUMNS … LIKE ?`: use the repo's `ColumnExists::has` helper.
   - No `->change()` (no doctrine/dbal): use raw `ALTER TABLE … MODIFY`, guarded by an `information_schema` check.
   - `strict=false`: over-long values are silently truncated, so validators must enforce lengths.
   - Indexed or unique `varchar` over 191 characters needs a prefix index (767-byte limit with utf8mb4).
   - Avoid a first `useCurrent()` timestamp column (ON UPDATE hazard seen before); check the `Extra` column after creating.
   - Ids and tenant columns are `BIGINT UNSIGNED`. (`g2g_signal_products` uses `INT UNSIGNED`; fixed in slice 1.)
5. **Tenant column:** `sub_institute_id BIGINT UNSIGNED NOT NULL`. Shared rows use `0`, never NULL (legacy NULLs exist in `crm_leads`, `gtm_playbooks`, `crm_knowledge_sources`). Every unique index includes the tenant. Every by-id read filters by tenant and returns 404 on mismatch.
6. **No foreign keys on new tables**, loose pointer columns instead (repo convention). A read-only `integrity:check` command lists orphaned pointers.
7. **Polymorphic type values on new tables are lowercase singular** (`lead`, `contact`, `organization`, `opportunity`). Existing tables mix UPPERCASE (calendar events, document and email links) and lowercase; a small mapping helper translates.
8. **Guarded and idempotent:** each migration checks table, column or index existence first. `down()` removes only what `up()` created.
9. **Data updates are never inside schema migrations.** A separate artisan command: dry run by default, `--apply` to write, one transaction per tenant, insert-ignore on unique keys, never overwrites a non-empty value, prints counts, saves a summary to `_evidence/`, and a re-run changes nothing.
10. **Order per change:**
    1. Write migration, model and tests on dev.
    2. Run on dev (single file).
    3. Compare `SHOW CREATE TABLE` and `SHOW COLUMNS` with the expectation; save output.
    4. Run tests.
    5. Owner says "go" for live.
    6. Before live: schema diff of the affected tables dev vs live, check the `migrations` table, and dump any table a backfill will write to.
    7. Run on live, verify with the same queries.
    8. Log in `DB-CHANGELOG.md`.
11. **Rollback:** schema through `down()` (tested on dev). Data: backfills write only into new columns or tables, so rollback means nulling the new column or truncating the new table (documented per slice).
12. **Menu and rights:** a migration adds leaf rows matched by `access_link`, grants the Administrator profile only; others are granted by the owner in the rights UI after the audience is counted. Never grant an ancestor row.
13. **Optional rehearsal (owner decides):** restore a schema-only dump of live into a disposable MariaDB 10.1 container on D:, and run each migration there first, so 10.1 syntax errors show up before live. Needs Docker and the owner's OK for a read-only schema dump (both unverified).

## 5. Defect register: found while reading, each assigned to a slice

| # | Defect | Evidence | Status | Fixed in |
|---|---|---|---|---|
| D1 | `DealController::convertToCrm` has a duplicated, half-deleted insert block, so the file does not parse; every `/api/gtm/deals*` route that loads it fails | `app/Http/Controllers/Api/Gtm/DealController.php` lines 397–407 (stray array after `]);`) | **verified** | S0 hotfix (delete lines 398–407, then `php -l`) |
| D2 | Account 360 selects `opportunity_name`, `close_date`, `campaign_name`; real columns are `name`, `closing_date`, `name`: SQL error | `CrmKnowledgeGraphController.php` lines 66 and 87 | **verified** | S3 (new endpoint, old one left alone) |
| D3 | Signal Inbox "promote to opportunity" inserts non-existent `opportunity_name` and `account_id` and omits NOT NULL `name` and `opportunity_no`. "Promote to lead" inserts directly (`last_name='Signal Lead'`), skipping `LeadFactory` (no lead number, score or event) | `CrmSignalInboxController.php` lines 300–332 | **verified** | S6 |
| D4 | The three CRM AI agents read columns that do not exist and query by id with no tenant filter | `Domain/Crm/Ai/*` | agent-read | S7, S12 |
| D5 | Mapped CRM screens Signal Inbox, Automation Rules and Agent Run History are seed-data demos calling URLs without `/crm`; the real components exist but are not mapped | `hooks/content-map-crm.ts` | agent-read | S2 (inbox), S12 |
| D6 | CRM SMS sending has no feature flag, consent or cap: any tenant with a gateway row and an edit right can send | `CrmSmsController` | agent-read | S0 (master switch), S10 |
| D7 | Only `email_opt_out` is enforced. `consent_status`, `suppressed_*` and the table `crm_consent_preferences` are never written or read; GTM suppressions are a separate list; lead conversion drops consent; merge can lose an opt-out | CRM mailers, `LeadConverter` | agent-read | S4, S10 |
| D8 | `LeadFactory::nextLeadNo()` scans for the global maximum with no lock, so concurrent creates can collide | `LeadFactory` | agent-read | S6 |
| D9 | `crm_leads.description` is TINYTEXT (255 bytes); contact and organisation `email` is varchar(100) while validation allows 191; `strict=false` truncates silently | migrations | agent-read | S4, S6 |
| D10 | `crm_organizations` unique `(tenant, name)` counts soft-deleted rows; merge repoints only a few of ~20 referencing tables | migrations, `HasCrmMerge` | agent-read | S3 |
| D11 | `crm_signal_inbox.signal_key` is unique across all tenants and the dedupe ignores tenant | migration, controller | agent-read | S2 decision |
| D12 | `g2g_signal_products` migration is unrun, uses `INT UNSIGNED` tenant and utf8 charset | migration header | agent-read | S1 |
| D13 | Signal review overwrites the previous decision, stores no reason or history, and the UI never sends notes; nothing under Signals emits an event | `OpportunityController@act`, FE callers | agent-read | S2 |
| D14 | `signals:research` is scheduled only if `SIGNALS_SCHEDULED_RESEARCH=true` (default false); production has never run the scheduler (docs); per-tenant schedule columns are ignored | `routes/console.php`, docs | agent-read | S2 plus ops |
| D15 | Signals AI usage rows carry no run id; runs store no cost; the `stage_stats` migration is unrun | `StructuredAi`, migrations | agent-read | S2 |
| D16 | Tables created with `json()` (`crm_agent_runs`, `crm_automation_rules`, `crm_signal_inbox`, `crm_signal_topics`, `agentic_*`) cannot exist on live 10.1 | repo comments, memory | agent-read | rule in section 4; S12 |
| D17 | Saving an opportunity runs the GTM sync inline with no try/catch | `OpportunityGtmSyncBridge` | agent-read | S11 |
| D18 | Two campaign UIs; `/gtm/deals` is mapped to the CRM list but rendered by its own page; three visual dialects | FE inventory | agent-read | S9, S11 |
| D19 | `gtm_outreach_messages` has no index on `provider_message_id` or `(status, scheduled_for)` although code filters on both | migrations | agent-read | S10 |

## 6. The map and the order

```
Product ──► Signal run ──► Signal ──► review ──► Lead ◄── Campaign
                              │                    │
                              ▼                    ▼
                        Organisation ──► Contact + evidence ──► Playbook ──► Outreach ──► Reply
                                                              │
                                                       Opportunity ──► Forecast ──► Quote/Order/Invoice (paperwork stays)
                         Ask (chat) and Agents sit across every box, through one gateway
```

New screens live in four workspaces plus Settings, as on the architecture page:

| # | Slice | Workspace | New or changed tables (database footprint) | Rough sessions* |
|---|---|---|---|---|
| 0 | Working system, baseline, safety | none | none (read-only measuring) | 1–2 |
| 1 | **Product Master** | Settings › Products | `g2g_product_master` (new) | 6–8 |
| 2 | Signals front door | Today › Signals | `g2g_signal_reviews` (new); alter `g2g_company_opportunities`, `g2g_research_runs` | 4–5 |
| 3 | Organisation 360 | Accounts › Organisations | `g2g_org_accounts` (new); alter `crm_organizations` | 3–4 |
| 4 | Contact facts ledger and consent | Accounts › Contacts | `g2g_entity_facts`, `g2g_entity_fact_evidence` (new); alter `crm_contacts`, `crm_organizations`; adopt `crm_consent_preferences` | 3–4 |
| 5 | Contact and organisation discovery | inside Account and Contact pages | `g2g_discovery_runs`, `g2g_usage_budgets` (new) | 4–6 |
| 6 | Leads | Pipeline › Leads | alter `crm_leads`; `g2g_number_sequences` (new) | 4 |
| 7 | Agent gateway and autonomy | Settings › Agents; Today › Proposals | `g2g_agent_runs`, `g2g_agent_tool_calls`, `g2g_agent_proposals`, `g2g_agent_autonomy` (new); alter 4 comment tables | 4–5 |
| 8 | Ask (chat) | Ask | `g2g_agent_conversations`, `g2g_agent_messages` (new) | 3–4 |
| 9 | Campaigns to leads | Pipeline › Campaigns | alter `crm_campaigns`, `gtm_campaigns`, `crm_leads` | 3 |
| 10 | Playbooks and outreach | Today › Drafts and Replies; Settings › Playbooks | `g2g_outreach_gate_checks`, `g2g_product_claims` (new); alter `gtm_playbooks`, `gtm_outreach_messages` | 5–6 |
| 11 | Pipeline and forecast capture | Pipeline › Deals | alter `crm_opportunities`; `g2g_forecast_snapshots` (new, or reuse GTM forecasting tables) | 4–5 |
| 12 | Agent builder, triggers, run history | Settings › Agents | `g2g_agent_defs` (new); new workflow action | 4 |
| 13 | Paperwork re-skin | Pipeline (quotes to invoices), Support | none expected | many |
| 14 | Retire old screens | all | none | n/a |

\*Rough, unmeasured guesses of working sessions, not dates. About 50–60 sessions in total: this is the real size of the work. Table names are proposals, confirmed at each design gate; new shared tables use the `g2g_` prefix like the platform tables.

From slice 7 on, every slice includes the agent tools for its own branch as a standard task.

## 7. Slice specifications

Each slice gets its final detail at its own design gate, using what earlier slices taught us. Slices 0 to 6 are specified in full; 7 to 12 are specified down to their database changes and acceptance checks.

### S0 · Working system, baseline, safety (1–2 sessions)

- **Database:** none changed. Read-only baseline only.
- **Tasks:**
  1. **Publish the updated artifact first** (owner's request): add a "Build plan" page (rules, slice map, per-slice database changes, defect register, decisions, open items), fix the option letters, replace the separate-service wording with the PHP agent layer, keep light mode, same link.
  2. Create `Docs/agentic-crm/` with the files in section 3, `CLAUDE.local.md`, the feedback memory, and save appendix A and section 5 into `_evidence/`.
  3. Read `ColumnExists`, `LegacyMariaDbSchemaGrammar` and two recent safe migrations; finish `DB-RULES.md` (which helpers are safe on 10.1).
  4. **Read-only baseline on dev and live:**
     - Table presence and `SHOW COLUMNS` for every table named in appendix A.
     - The `migrations` table diff between hosts.
     - Row counts excluding soft-deleted, for tenant 6 and in total.
     - Menu rows by `access_link` on both hosts.
     - Whether `g2g_signal_products`, `crm_agent_runs`, `crm_consent_preferences` and the other `json()` tables exist on live.
     - Recent `g2g_platform_task_runs` rows (is the scheduler running at all?).
     - Per-tenant `school_setup.outbound_email_enabled`, and counts of SMS gateway rows (counts only, never keys).
  5. D1 hotfix (delete the stray lines), `php -l` over `app/` to list any other parse errors, one commit.
  6. D6: add a CRM SMS master switch, default off, with a test. One commit.
  7. Owner answers the three blocking questions in section 10.
- **Done when:** files exist; baseline evidence saved; D1 verified by `php -l`; the artifact shows the new page.

### S1 · Product Master (6–8 sessions)

Data model: a thin canonical table that points at the existing stores. It is not a merge. (Design from the Plan agent; re-verified in task 1.)

- **Database:**
  - **NEW `g2g_product_master`:** `id`, `sub_institute_id` NOT NULL (0 = shared), `product_key` varchar(50), `kind` varchar(10) (`family` or `product`), `parent_id` NULL, `name` varchar(191), `status` varchar(10), `origin` varchar(20), `signal_key` varchar(20) NULL, `portfolio_name` varchar(50) NULL (the exact `parent_product` string), `offer_row_id` NULL, `crm_product_id` NULL, `created_by`, `updated_by`, timestamps. Unique per tenant on `product_key`, `signal_key`, `offer_row_id`, `crm_product_id`. One row can hold both an offer pointer and a CRM-item pointer, so a sellable sub-product is one record.
  - **Existing stores untouched:** content stays in `g2g_product_offers` (what it is), `crm_products` (price, SKU; ten tables hold real foreign keys to it, so never add FKs to or from it), `g2g_icp_profiles` (ICP).
  - **Fix D12 before running anything:** `g2g_signal_products` becomes `BIGINT UNSIGNED` tenant, utf8mb4. Edit the file in place only after the `migrations` table proves it has run nowhere; record it in `DB-CHANGELOG.md`.
  - **DATA:** `product-master:backfill --tenant --database --apply` (dry run by default). Order: shared families from `config/signals_products.php` plus `config/g2g_taxonomy.php`; custom lines from `g2g_signal_products`; offers from `g2g_product_offers` (NULL tenant becomes 0; duplicate `offer_id`s and odd spellings reported, not merged); every `crm_products` row as its own product. No name matching.
  - **Alias resolver (no table):** `ProductCatalog::normalise`, then `signal_key`, then `portfolio_name`. So `scholarclone`, `scholar`, `Scholar K-12` and `scholar_k12` reach one row. `multiple` and unknown values return null.
  - **MENU:** leaf row `/module/crm/product-master` under 199, found by `access_link`; Administrator only.
  - **Later slices add** one nullable `product_master_id` to `crm_leads` (S6), `crm_campaigns` and `gtm_campaigns` (S9), `gtm_playbooks` and `gtm_outreach_messages` (S10), `crm_opportunities` (S11), `g2g_company_opportunities` and `g2g_research_runs` (S2). **Slice 1 adds none of these.**
- **Backend:** new `ProductMaster` domain (resolver, backfill command, controller, `routes/product_master.php`). Endpoints: list, options, `{id}`, `{id}/usage`, create, update, archive, restore. Rights through `platformright`; creating a product line needs administrator; the offer section needs an in-controller twin of `ModuleGate::signals('/portfolio','add','write')`; the sellable section checks `MenuRight` per item type. Unchanged: `/api/crm/products*`, `/api/portfolio/*`, `/api/signals/icp/products*`, all line-item tables.
- **Frontend:** scaffolds in `g2gv0/components/shared/scaffolds/` (no edits to `components/ui`): `list-page` with `use-list-query` (URL-synced search, sort, page), `detail-page` with `page-tabs`, `form-layout` with `form-field`, `record-drawer` (on `ui/sheet`, dirty-close guard), `use-confirm` (replaces `window.confirm`, built on the existing `ui/confirm-dialog`).
  - **The one form** is a drawer, also reused on the detail page. Sections: Identity (name, line key, Product ID, line, status), About (what it does, who needs it, buying events, readiness), Sell in CRM (type, SKU, prices, tax, GL, commission, stock), Ideal customer (status, links to the existing ICP editor). One server validator returns field-path 422 errors mirroring the existing rules. One transaction writes in order: line, offer, CRM item, master row. A non-admin creating a line gets 403 with zero writes.
  - **Old forms** (`create-product-modal.tsx`, `product-form-dialog.tsx`, `new-product-dialog.tsx`) stay until the owner decides.
- **Agent hook:** none yet; slice 8 adds read-only `search_products`.
- **Tasks (one session each, with done-check):**
  1. Measure both hosts: `crm_products`, offers, NULL-tenant offers, duplicates; whether `g2g_signal_products` exists on live. Owner approves the migration run. Numbers logged.
  2. Migration and model on dev, then live. `information_schema` identical on both.
  3. Resolver and alias tests pass.
  4. Backfill command, dry run. A re-run changes nothing.
  5. Menu row and read API. 403 and 404 tests pass; the row is admin-only.
  6. Write API. Rollback, 403, 409, 422 tests pass; existing CustomProductApi, ProductPortfolioIcp and ProductCatalogBuiltIn tests stay green.
  7. Backfill apply, dev then live. Counts match the sources.
  8. Scaffolds: list page, query hook, confirm. Unit tests.
  9. Scaffolds: detail page, tabs, form, drawer. jsdom tests.
  10. Service, types, routes, content-map entry, list screen. Renders for an admin; typecheck clean.
  11. Detail tabs: Overview, Sell, Signals, Usage.
  12. Drawer form and archive through confirm.
  13. Playwright script and log entry: create a line, a sub-product and a sellable item and check they appear in Portfolio, Signals and CRM Products; a non-admin sees no line option; tenant B reading tenant A's id gets 404.
- **Risks:** ids are global, so every by-id read must filter by tenant. A CRM product merge can orphan `crm_product_id` (the merge repoints only two tables today), so S1 adds an orphan report. `offer_id` is not unique in the database.

### S2 · Signals front door (4–5 sessions)

Facts that shape it (measured earlier, live): 75% of signals have no date, 8% were ever reviewed, all 72 runs were manual, runs store no cost.

- **Database:**
  - **NEW `g2g_signal_reviews`** (decision history, so nothing is overwritten): `id`, `sub_institute_id`, `opportunity_id`, `decision` varchar(12) (`reviewed`, `dismissed`, `follow_up`, `reopened`), `reason_code` varchar(30) NULL, `note` text NULL, `from_status` varchar(12), `to_status` varchar(12), `decided_by`, `decided_at`, `created_at`. Index `(sub_institute_id, opportunity_id, decided_at)`.
  - **DATA:** backfill one history row for each already-reviewed opportunity (6 on live today) from `review_status`, `reviewed_by`, `reviewed_at`, `review_notes`.
  - **ALTER `g2g_company_opportunities`** (70 columns already): add `product_master_id` BIGINT UNSIGNED NULL; add `date_status` varchar(20) NULL (`event_date_stated`, `source_date_only`, `undated`), backfilled in PHP from `assessment.evidence.date_status` because 10.1 cannot query JSON; add indexes `(sub_institute_id, business_fit, review_status)`, `(sub_institute_id, first_discovered_at)`, `(sub_institute_id, event_date)` (the feed filters on these and none is indexed today).
  - **ALTER `g2g_research_runs`:** run the prepared `stage_stats` migration; add `product_master_id`; add cost columns `ai_input_tokens` int unsigned, `ai_output_tokens` int unsigned, `ai_cost_usd` decimal(10,6), `search_requests` smallint unsigned, `search_credits` decimal(10,2), all NULL. They are filled at run completion. `StructuredAi` starts passing `related_type='g2g_research_run'` and `related_id=<run id>` into `ai_usage_events`, so usage joins to runs (D15).
  - **Reason codes** live in `config/signals.php` (not a table): not_a_buyer, already_customer, stale, wrong_product, duplicate, source_unreliable, no_budget, other. The owner confirms the list at the gate. Dismiss requires one.
  - **EVENTS:** `OpportunityController@act` and `promoteToCrm` emit through `EventRecorder` with idempotency keys like `signals.opportunity.reviewed:{tenant}:{id}:{reviewRowId}`; register the types in `EventCatalogue::SHIPPED`. Nothing under Signals emits events today.
  - **MENU:** leaf row for Today › Signals, Administrator only first.
  - **Scheduler (ops, not code):** `SIGNALS_SCHEDULED_RESEARCH=true`, the production cron and a queue worker. Verify `QUEUE_CONNECTION` on live (an older doc says `sync`). Wire `ProductProfileService::isDue()` into the command, or retire those columns; owner decides.
- **Backend:** `SignalReviewService` writes the decision, history row and event in one transaction; `act` accepts and validates `reason_code` and `notes`; `RunAccounting` fills the cost columns; run screens read products from the Product Master options endpoint.
- **Frontend:** new **Today › Signals** screen on the scaffolds. List with facets (product, status, freshness, fit), a drawer with the evidence panel (verbatim quote, source URL, date and how it was found, fit and score), decision buttons with a reason dialog, run history with cost and health. The old `company-opportunities.tsx` and `signal-list.tsx` stay.
- **Decision for the owner:** what happens to the CRM Signal Inbox (`crm_signal_inbox`: a third signals store, currently a seed-data demo, with a cross-tenant dedupe bug D11). Options: leave, hide, or retire. Default: map the real component or hide the demo; no data moved.
- **Agent hook (suggest level):** the signal's "why it matters" text already exists; S8 adds read-only `list_signals` and `explain_signal`.
- **Acceptance:** every decision writes a history row and an event; dismissing without a reason is refused; `date_status` backfill matches the measured 18 dated of 71; three scheduled runs appear in `g2g_platform_task_runs` with cost columns filled; cross-tenant 404; browser walk-through; the reviewed share is measured again after two weeks of use (target set by the owner).

### S3 · Organisation 360 (3–4 sessions)

Today an organisation is three unlinked tables: `crm_organizations`, `gtm_accounts` (FK to the CRM row, loose `company_id` to `g2g_companies`) and `g2g_companies`.

- **Database:**
  - **NEW `g2g_org_accounts`** (thin identity, same pattern as the Product Master): `id`, `sub_institute_id` NOT NULL, `name`, `normalized_name`, `domain`, `lifecycle` varchar(12) (`discovered`, `target`, `engaged`, `customer`, `archived`), `g2g_company_id`, `gtm_account_id`, `crm_organization_id` (all NULL, loose), `owner_user_id`, `created_by`, timestamps, `deleted_at`. Unique per tenant on each of the three pointers.
  - **DATA:** backfill links only on an exact domain match or an exact normalised-name match; fuzzy matches (85% or more, as `BuyerResolver` already does) become suggestions in a "possible duplicates" list for a person to confirm.
  - **ALTER `crm_organizations`:** widen `email` and `secondary_email` to varchar(191) with a raw guarded `MODIFY` (D9). Fix D10 in code: on soft delete, a renamed marker frees the name, or a create restores the deleted row (owner chooses at the gate).
  - **Merge fix (D10):** extend the repoint closures in `HasCrmMerge` to every table that points at an organisation: comments, email and document links, calendar events, opportunity contacts, follow-ups, consent, relationships, knowledge sources, opportunities, quotes, orders, invoices, tickets, assets, service contracts, `gtm_accounts.crm_organization_id`, projects. A test lists the referencing tables from `information_schema` and fails if one is not covered.
  - **MENU:** leaf row Accounts › Organisations.
- **Backend:** new `/api/org-accounts/*` (list, detail, `{id}/360`, link suggestions, confirm link). The old Account 360 endpoint (D2) is left alone.
- **Frontend:** Accounts workspace. List page; detail with tabs Overview, Signals, Contacts, Deals, Activity (notes, tasks, emails), Documents; Evidence tab arrives with S4.
- **Agent hook:** read-only `get_organisation` and `search_organisations`; notes and tasks arrive with S7.
- **Acceptance:** identity counts for tenant 6 equal the three sources; no duplicate pointers; suggestions list reviewed by the owner; cross-tenant 404; merge test covers every referencing table.

### S4 · Contact facts ledger and consent (3–4 sessions)

The evidence and identity rules come from Comp AI (MIT; fixed weights, noisy-OR score, bands, human value always wins, dismissed never re-offered), ported to PHP with tests. Comp AI verifies a person it already has; it does not discover people.

- **Database:**
  - **NEW `g2g_entity_facts`:** `id`, `sub_institute_id`, `entity_type` varchar(12) (`contact`, `organization`, `lead`), `entity_id`, `field` varchar(30), `value` varchar(1000), `value_hash` char(40), `value_kind` varchar(12) NULL (`personal` or `role`), `status` varchar(12) (`proposed`, `applied`, `dismissed`, `superseded`), `band` varchar(10) (`verified`, `probable`, `possible`), `score` decimal(4,3), `method` varchar(30), `source_url` varchar(1000), `proposed_by` varchar(40), `decided_by` NULL, `decided_at` NULL, `supersedes_id` NULL, timestamps. Unique `(sub_institute_id, entity_type, entity_id, field, value_hash)`, so a value exists once and a dismissed one cannot be re-offered. Index `(sub_institute_id, status, created_at)` for the suggestion queue.
  - **NEW `g2g_entity_fact_evidence`:** `id`, `sub_institute_id`, `fact_id`, `kind` varchar(40), `weight` decimal(3,2), `source_url` varchar(1000), `quote` varchar(1000) NULL, `retrieved_at`, `created_at`. Weights live in `config/facts.php`, not in the database, so they are reviewable and testable.
  - **ALTER `crm_contacts`:** widen `email` and `secondary_email` to 191 (D9); add `linkedin_url` varchar(300) NULL. Same `linkedin_url` on `crm_organizations`. Other social links stay ledger-only until a screen needs them.
  - **Consent (D7):** adopt `crm_consent_preferences` (exists in a migration, unused; run it on live if absent). Add `ConsentGate::allows(entity, channel)`, which reads `email_opt_out`, `crm_consent_preferences`, `gtm_suppressions` and `gtm_contacts.status`; unsubscribe, complaint and bounce handlers write through so both systems agree. Lead conversion copies consent to the contact. A one-time reconciliation command, dry run first.
  - **Write-through:** an applied fact writes the target column when one exists (email, phone, website, linkedin_url); otherwise it stays in the ledger.
- **Backend:** `Domain/Facts`: `EvidenceScorer`, `IdentityMatcher`, `SocialUrl`, `FactWriter`, `SuggestionQueue`; PHPUnit tests built from the same cases as the TypeScript originals (read the originals; do not copy blindly).
- **Frontend:** Contacts workspace (list and detail on the scaffolds), a Suggestions queue (accept or dismiss, evidence expandable), and the Evidence tab on Organisation 360.
- **Agent hook (propose level):** `propose_fact` writes a `proposed` row. Auto-applying a verified fact to a blank field is **off** by default (owner default: no autonomous actions except notes and tasks); an admin can enable it in S7.
- **Acceptance:** golden tests pass; a human-entered value is never overwritten; a dismissed value is never re-offered; the supersede chain is correct; consent gate refuses the right cases; cross-tenant 404.

### S5 · Contact and organisation discovery (4–6 sessions)

**G2G finds no contacts today** (no code extracts a name, email, phone or social link; the only person-like output is a role string). This is a new capability, so it starts with research.

- **5a Research (no code):** hand-collect real contacts for about 20 target schools; test each source type on that answer key and record precision, recall, cost and legal notes: school website staff and contact pages, tender notices, CBSE SARAS and other directories, Context.dev, Tavily, open-source page extractors. **The answer key stays outside the repository** (personal data); only counts and results are logged. The owner picks sources from the numbers.
- **5b Build:**
  - **NEW `g2g_discovery_runs`:** `id`, `sub_institute_id`, `entity_type`, `entity_id`, `source_key`, `status`, `started_at`, `finished_at`, `requests`, `credits` decimal(10,2), `cost_usd` decimal(10,6), `facts_proposed`, `error_code` varchar(40), `error_message` varchar(500), `created_by`, timestamps.
  - **NEW `g2g_usage_budgets`:** `id`, `sub_institute_id`, `provider` varchar(30), `period` varchar(7) (`YYYY-MM`), `cap` decimal(12,2), `used` decimal(12,2), `unit` varchar(12), `updated_at`. Unique `(sub_institute_id, provider, period)`. The first row for tenant 6 is the 10,000 credits; unit and provider are confirmed by the owner. Charge before each call; a missing key or exhausted cap reports "unavailable", not failure.
  - **Provider keys:** per-tenant, in the existing encrypted `g2g_integration_credentials`.
  - Source adapters implement one `ContactSource` interface and **only propose facts into the S4 ledger**; they never write contact fields.
- **Rules:** role mailboxes by default; a consent basis recorded per contact; no LinkedIn fetching (a static test asserts no request to linkedin.com); outbound fetches use the existing `SafeUrlFetcher` (SSRF and robots checks).
- **Acceptance:** precision and recall on the 20-school key, three runs for variance; budget cap enforced in a test; every proposal carries evidence with a URL.

### S6 · Leads (4 sessions)

- **Database:**
  - **ALTER `crm_leads`** (note: its `sub_institute_id` is nullable, unlike the other core tables): add `product_master_id` BIGINT UNSIGNED NULL and `org_account_id` NULL, each indexed with `sub_institute_id`; raw `MODIFY` of `description` from TINYTEXT to TEXT (D9). Signal evidence is not copied into a new table: the lead keeps `source_company_opportunity_id`, and promotion adds one structured note (quote, URL, date, fit) through the shared note service from S7's design.
  - **NEW `g2g_number_sequences`:** `key` varchar(30) PK, `next_value` BIGINT UNSIGNED. An atomic `UPDATE … SET next_value = LAST_INSERT_ID(next_value + 1)` replaces `nextLeadNo()` (D8).
  - **DATA:** backfill `product_master_id` for existing signal-born leads from `business_fit` through the resolver, dry run first. Currently 5 leads on live.
- **Backend:** every creation path goes through `LeadFactory` (four call sites plus the Signal Inbox direct insert, D3). `promoteToCrm` sets product and organisation. `LeadConverter` copies consent and facts to the new contact, and does not create a placeholder contact (last name = company name) when no person is known.
- **Frontend:** Pipeline › Leads on the scaffolds. List facets: product, source, score, status, owner, consent. Detail tabs: Overview, Evidence (signal plus facts), Contacts (suggestions), Activity, Convert. Old lead screens stay.
- **Agent hook:** `list_leads` and `get_lead` (read); `add_note` and `add_task` (autonomous, owner default); `propose_lead_update` (approval).
- **Acceptance:** all creation paths produce a number, score and `crm.lead.created` event; a concurrency test for lead numbers; conversion copies consent; no placeholder contact; D3 fixed with tests.

### S7 · Agent gateway and autonomy settings (4–5 sessions)

The PHP AI client is single-shot (`AiModelClient::complete()`), so this slice adds the tool-calling loop. No send or delete tool is ever registered.

- **Database:**
  - **NEW `g2g_agent_runs`:** `id`, `sub_institute_id`, `user_id` (acting for), `agent_key`, `trigger` varchar(20), `status` varchar(16), `started_at`, `finished_at`, `model` varchar(80), `input_tokens`, `output_tokens`, `cost_usd` decimal(10,6), `tool_calls` smallint, `error_code`, `error_message` varchar(500), `correlation_id` char(36), timestamps.
  - **NEW `g2g_agent_tool_calls`:** `id`, `sub_institute_id`, `run_id`, `seq`, `tool` varchar(60), `args_hash` char(40), `args` longtext (redacted), `outcome` varchar(12) (`applied`, `proposed`, `denied`, `error`), `deny_reason` varchar(60), `proposal_id` NULL, `result_ref` varchar(80) NULL (table:id), `latency_ms`, `created_at`.
  - **NEW `g2g_agent_proposals`** (the approval queue): `id`, `sub_institute_id`, `run_id`, `tool`, `entity_type`, `entity_id`, `action` varchar(40), `payload` longtext, `status` varchar(12) (`pending`, `approved`, `rejected`, `expired`, `applied`, `failed`), `decided_by`, `decided_at`, `decision_note`, `applied_at`, `error`, timestamps.
  - **NEW `g2g_agent_autonomy`:** `id`, `sub_institute_id`, `action_key` varchar(60), `level` varchar(12) (`off`, `suggest`, `draft`, `approval`, `alone`), `updated_by`, `updated_at`. Unique `(sub_institute_id, action_key)`. Defaults live in `config/agent_actions.php`. A row with `action_key='*'` and level `off` is the per-tenant kill switch.
  - **ALTER the four comment tables** (`crm_lead_comments`, `crm_contact_comments`, `crm_organization_comments`, `crm_opportunity_comments`): add `created_via` varchar(12) NULL (`user`, `agent`, `automation`) so the screen can badge agent notes. Tasks are calendar events in a shared table; their audit trail is `g2g_agent_tool_calls.result_ref`, with no new columns on `task_management_calendar_events`. Confirm at the gate.
  - **EVENTS:** `agent.run.started`, `agent.run.finished`, `agent.proposal.created`, `agent.proposal.decided`, `agent.action.applied`.
- **Backend:**
  - `AiModelClient::completeWithTools()` (DeepSeek uses the OpenAI-compatible tool-call format; Gemini uses function declarations).
  - `ToolRegistry` and `Gateway` with seven checks: run token, rights through `MenuRight::can`, taint (a run that read outside text cannot act), policy from `g2g_agent_autonomy`, input validation, budget, decision (apply, propose or deny).
  - **Shared write services:** `RecordNote` for the four entity types (there is no shared note helper today; the HTTP trait needs an edit right, `LeadActivity::note` is lead-only with a direct insert) and `RecordTask`, generalising `LeadActivity::taskFor` (it works for the five CRM types, adds a duplicate guard, and takes `syear` from the academic year table). Both check tenant and existence, and set `created_by` to the acting user.
- **Frontend:** Settings › Agents (admin only): actions and levels, with counters for approved, rejected and edited proposals as the evidence for raising a level; Today › Proposals queue; run history viewer.
- **Default action catalogue:**

| Action | Default level |
|---|---|
| `add_note`, `add_task` | **alone** (owner default) |
| `propose_fact` | suggest |
| `apply_fact` to a blank field (verified only) | approval (admin can raise) |
| `update_field`, `change_stage`, `create_lead`, `create_organization`, `create_contact` | approval |
| `run_research` (spends credits, within the cap) | approval |
| `draft_outreach` | draft |
| `submit_outreach_for_approval` | approval |
| `send_message`, `delete`, `merge` | **no tool exists**; admin cannot enable (owner may overrule, recorded in `DECISIONS.md`) |

- **Acceptance:** every tool call writes an audit row; denial tests for wrong tenant, missing right, tainted run, exhausted budget and kill switch; notes and tasks run unattended only when the setting is `alone`; changing a setting is itself audited; the registry contains no send tool (a test lists it).

### S8 · Ask, the chat (3–4 sessions)

- **Database:** NEW `g2g_agent_conversations` (`id`, tenant, `user_id`, `title`, timestamps, `deleted_at`) and `g2g_agent_messages` (`id`, `conversation_id`, `role`, `content` longtext, `run_id` NULL, `created_at`). Reuse of the existing `ai_conversation_turns` is considered at the gate.
- **Backend:** read-only tools `search_records`, `get_record`, `list_signals`, `list_products`, `pipeline_summary`; then proposals through the S7 gateway. This replaces `CrmAgentController::aiChat` and the `crm-chat-widget.tsx` mounted on every CRM page (kept until the owner decides).
- **Frontend:** the Ask panel. A single-threaded local server is a known trap: the first version polls rather than streams.
- **Acceptance:** answers cite record links; cross-tenant questions are refused; "no data" is stated as such; token cap enforced.

### S9 · Campaigns to leads (3 sessions)

- **Database:** ALTER `crm_campaigns` add `product_master_id`; ALTER `gtm_campaigns` add `product_master_id` and `crm_campaign_id` (bridge); ALTER `crm_leads` add `campaign_id` (first-touch attribution; today only a free-text `campaign_text`). DATA: backfill `product_master_id` from the loose `crm_campaigns.product_id` through the master's `crm_product_id`; backfill `crm_leads.campaign_id` from an exact `campaign_text` match, ambiguous ones reported.
- **Decision (default):** one Campaigns screen over `crm_campaigns`, with the audience builder reusing `gtm_segments` and signals. Membership stays in `crm_campaign_targets` and `gtm_campaign_members`, joined in the screen, not merged.
- **Frontend:** unified list and detail with the funnel from `crm_campaign_targets` timestamps (sent, opened, replied, meeting, converted). Both old campaign screens stay.
- **Agent hook:** `suggest_audience` (suggest), `create_campaign_draft` (approval). Sending stays off.

### S10 · Playbooks and outreach (5–6 sessions)

- **Database:**
  - **NEW `g2g_outreach_gate_checks`:** `id`, tenant, `message_id`, `message_table` varchar(30), `check_key` varchar(40), `passed` tinyint, `detail` varchar(300), `checked_at`. Index `(sub_institute_id, message_id)`.
  - **NEW `g2g_product_claims`:** `id`, tenant, `product_master_id`, `claim` varchar(500), `source_url`, `status`, `approved_by`, `approved_at`. A draft may state only approved claims.
  - **ALTER `gtm_playbooks`** (tenant rows only) and `gtm_outreach_messages`: add `product_master_id`. Add indexes on `gtm_outreach_messages (provider_message_id(191))` and `(status, scheduled_for)` (D19; prefix because the column is varchar(255)).
  - **DATA:** a one-time reconciliation of CRM `email_opt_out` against GTM suppressions, dry run first.
- **Backend:** one `OutreachGate` (banned phrases and length, at most one allowed link, no unresolved placeholders, claims in the approved list, consent and suppression through `ConsentGate`, sender checks, unsubscribe present) called by **both** mailers (`OutreachMailer` and `CrmOutreachMailer`). The GTM outreach state machine (approved hash, caps, bounce handling) is the engine; the CRM approvals screen is a ledger that never gates sending and is retired only by the owner's decision. Sending stays off: master flags default false, mail goes to a sandbox mailbox only.
- **Frontend:** Today › Drafts to approve (gate panel, as in the architecture mock-up 2), Today › Replies, playbook library with versions, suppression list.
- **Agent hook:** `draft_outreach` (draft), `submit_outreach_for_approval` (approval). No send tool.

### S11 · Pipeline and forecast capture (4–5 sessions)

- **Database:** ALTER `crm_opportunities`: add `product_master_id`; add `loss_reason_code` varchar(30) NULL (the existing `close_reason` is free text). Forecast: read the existing GTM forecasting tables (`2026_10_11_100000_gtm_forecasting`, columns not yet read) before deciding to reuse them or add `g2g_forecast_snapshots` (daily open, weighted and committed amounts per owner). Stage-history writes are verified on every path (kanban, edit, convert). A required amount and close date to enter Proposal is a config rule.
- **Backend:** move the inline GTM sync (D17) out of the save path, or wrap it in try/catch with logging. D1 must already be fixed (S0).
- **Frontend:** unified Deals and Opportunities screen per `02-FRONTEND-UNIFIED-COMPONENTS-SPEC.md` (summary tiles, board and table toggle, drawer), plus the forecast panel.
- **Agent hook:** `list_deals`, `explain_forecast_change` (read); `propose_stage_change` (approval). The agent never edits amounts, dates or the committed number.

### S12 · Agent builder, triggers, run history (4 sessions)

- **Database:** NEW `g2g_agent_defs` (`id`, tenant, `key`, `name`, `status` (`draft`, `deployed`, `paused`), `when_text`, `find_text`, `then_text`, `scope_text`, `instructions` longtext, `trigger_type`, `trigger_config` longtext, `created_by`, `deployed_by`, `deployed_at`, timestamps).
- **Engine (default):** reuse the existing `gtm_workflows` engine (idempotent per workflow and event, retries, approvals, 24 lead-automation presets) by adding a `run_agent` action, instead of building a second trigger system. Event triggers use a new reactor registered in `EventCatalogue` and `ReactEvents::REACTORS`. The five GTM agents and `crm_automation_rules` (CRUD only, no executor, `json()` columns) are converted or retired by the owner's decision.
- **Frontend:** agent builder (a sentence produces a draft: When, Find, Then, Scope), Deploy approval, Stop, and a real run history replacing the demo screen (D5).

### S13 · Paperwork re-skin (many small sessions)
Quotes, sales and purchase orders, invoices, vendors, services, price books, tickets, assets, service contracts, FAQ, email templates and SMS log, one screen per sub-slice on the scaffolds, in the order the owner picks. No database change expected.

### S14 · Retire old screens
Only as the owner decides, screen by screen, each with its own soak period recorded in `DECISIONS.md`.

## 8. Cross-cutting rules

- **Menus and rights:** every new screen is a leaf row under 199 matched by `access_link`, Administrator first. Before any grant, count the audience; never grant an ancestor (a past grant on an ancestor nearly exposed a report to about 2,000 people).
- **Events:** new types are registered in `EventCatalogue::SHIPPED`; idempotency keys follow `type:{tenant}:{entity id}[:{sub id}]`. `g2g_event_delivery` has no tenant column; tenancy comes from the join.
- **Scheduler and queue:** slices 2, 5 and 7 need the production cron and a queue worker. Live ran `QUEUE_CONNECTION=sync` at one point (older doc). Slice 0 checks, and the owner arranges it.
- **Budgets:** model cost is read from `ai_usage_events`; search and enrichment credits from `g2g_usage_budgets`.
- **Safety:** sending stays off everywhere (email flags false, SMS switch off); outbound mail goes only to a sandbox mailbox.

## 9. Known traps (full list in the memory index; copied into `00-PROGRESS.md`)

- Live MariaDB 10.1.48: no JSON, no SKIP LOCKED; compare `SHOW COLUMNS` on both hosts; a migration is not a schema; diff the `migrations` table.
- Menu rows by `access_link`; leaf-only grants; `sub_institute_id` can be text, NULL or CSV; `role_key` is NULL on the app host; `tbluser.status` is 1/0.
- Run probes one at a time (`artisan serve` is single-threaded).
- g2gv0: call eslint and tsc as `node node_modules/<tool>/bin/...`; browser tests need two localStorage keys plus a cookie and `localhost`, not `127.0.0.1`; an unmemoized `getLaravelContext()` in an effect dependency loops silently; dropdowns inside dialogs need the wheel-scroll workaround.
- My assertion patterns are wrong more often than the code; check that a passing test can also fail.
- No agent worktrees; C: is nearly full, so heavy work goes on D:.

## 10. Owner questions

Only three block slices 0 and 1:

1. What unit and provider do the 10,000 credits cover (Tavily, Context.dev, both)?
2. May I run a read-only schema dump of live, and is Docker available, for the optional MariaDB 10.1 rehearsal?
3. OK to fix D1 (the deals controller parse error) and add the SMS master switch (D6) in slice 0?

Every other question is raised at its own design gate: dismiss-reason list, Signal Inbox fate (S2); identity table vs bridge columns (S3); allowed discovery sources and the GeM terms question (S5); the send and delete ceiling (S7); the canonical campaign table (S9).

## 11. Verification

- **Per task:** the task's own check, run and saved to `_evidence/`.
- **Per slice, before it is called done:**
  - PHPUnit for new backend code, plus the existing suites it touches.
  - `tsc` and eslint on touched files, `npm test`.
  - `git diff --stat components/ui/` is empty.
  - Cross-tenant read returns 404 and the wrong role returns 403.
  - Migrations applied on both hosts, with `information_schema` compared; backfill re-run changes nothing.
  - A browser walk-through on dev with screenshots.
  - The owner signs off on the mock-up and on the finished screen.
- **Live rollout:** only after an explicit owner "go", recorded in the slice file and `DB-CHANGELOG.md`.

## 12. Not verified yet (logged as open items in slice 0)

- The unit and provider of "10,000 credits".
- Whether `g2g_signal_products` and the `json()` tables exist on live; live row counts of `crm_products` and `g2g_product_offers`.
- The live menu layout (the unify migration's notes say it ran on dev).
- Whether the production scheduler and a queue worker run.
- Whether the D1 parse error is what is deployed, and whether other files have parse errors (an `origin/main` parse error in `SkillMatrixController` is already in memory).
- The 24 versus 22 lead-automation workflow count, and the GTM forecasting table columns.
- Every `agent-read` item in section 5 until re-read at its slice.

## 13. First actions after approval (slice 0, in this order)

1. Publish the updated artifact (Build plan page, corrected option letters, PHP agent layer instead of a separate service, light mode, same link).
2. Create `Docs/agentic-crm/` with the files in section 3, `CLAUDE.local.md`, the feedback memory, and save appendix A and section 5 into `_evidence/`.
3. Finish `DB-RULES.md` after reading the helper classes; run the read-only baseline on both hosts; log it.
4. D1 hotfix and the SMS switch, if the owner agrees.
5. Stop and show the slice 1 mock-up for approval before any slice 1 code.

## Appendix A: schema facts used by this plan (read in migrations 2 Nov 2026; live may differ)

**Signals**
- `g2g_company_opportunities`: 70 columns; tenant `BIGINT UNSIGNED NOT NULL`; `review_status` varchar(12) default `New`, `review_notes`, `reviewed_by`, `reviewed_at`; `pipeline_status` default `NEW` (never changed); dates `event_date`, `source_published_at`, `observed_at`, `expires_at`; `business_fit` varchar(20); `relevant_offer_id` varchar(50); `likely_stakeholder` varchar(191); `assessment` and `sources` longtext JSON; `fingerprint` char(64); `is_sample`. Indexes: `(tenant, fingerprint)` non-unique, `(tenant, review_status, priority)`, run, company, `expires_at`, pipeline, `buying_intent`. No index on `business_fit`, `feed_section`, `first_discovered_at`, `event_date`.
- `g2g_research_runs`: `trigger`, `status`, `stage`, counts, `search_provider`, `ai_provider`, `ai_model`, `error_code`, `product_key`, `icp_profile_id`; no cost columns; `updated_at` doubles as the heartbeat; `stage_stats` prepared, not run.
- `g2g_research_sources`: `url` varchar(2000), `published_at`, `retrieved_at` (first `useCurrent()` timestamp, ON UPDATE hazard to check), `page_fetched`, `cited`, date provenance columns.
- `g2g_companies`: no unique key on name or domain (dedupe in `BuyerResolver`); `g2g_company_aliases` unique `(tenant, normalized_alias)`.
- `g2g_target_accounts`: unique `(tenant, pack, account_key)`; `contact_name` only; phone and email deliberately not copied.
- `g2g_opportunity_matches`: no unique key; written with `updateOrCreate`.
- `g2g_event`: unique `uq_event_uuid` and `uq_event_idem (tenant, idempotency_key)`; payload declared `json` but live holds LONGTEXT; `EventRecorder` is the only writer; `GtmAudit` catches the duplicate-key exception. `g2g_event_delivery`: no tenant column; unique `(event_id, consumer)`.
- AI: `ai_usage_events` (tokens, `estimated_cost_usd`, `related_type`, `related_id`; Signals leaves the last two empty), `ai_usage_quotas`, `ai_api_keys` (plain text key), `g2g_integration_credentials` (encrypted, unique `(tenant, provider_key)`), `g2g_platform_task_runs` (every scheduled tick).

**CRM records**
- `crm_leads`: **tenant nullable**; `lead_no` unique globally; `description` TINYTEXT; `converted_organization_id`, `converted_contact_id` (FKs, SET NULL), `converted_opportunity_id`, `source_company_opportunity_id` (loose); consent columns never written; `lead_score`.
- `crm_contacts`: `email` varchar(100); consent columns; `organization_id` FK.
- `crm_organizations`: unique `account_no` and unique `(tenant, name)` including soft-deleted rows; `email` varchar(100); no social columns; about 20 tables point at it.
- `crm_opportunities`: `opportunity_no` unique; `name`, `organization_id`, `sales_stage`, `amount`, `closing_date`; no contact column (junction table `crm_opportunity_contacts`); `crm_opportunity_stage_history` has no tenant column.
- `gtm_accounts`: unique `(tenant, domain)` including soft-deleted; `crm_organization_id` FK; `company_id` loose. `gtm_contacts`: `full_name`, `email`, `phone`, `linkedin_url`, `status`; never written with `source='research'`.
- Notes: `crm_{lead,contact,organization,opportunity,ticket}_comments` (parent FK cascade, tenant, `comment`, `created_by`). Tasks: `task_management_calendar_events` (`linked_type` UPPERCASE `LEAD|CONTACT|ORGANIZATION|OPPORTUNITY|TICKET`, `linked_id`, `status` default `Planned`, `syear` NOT NULL, no index on `(linked_type, linked_id)`).
- Existing write paths: HTTP `POST /api/crm/{entity}/{id}/comments` (needs edit right); `LeadActivity::note` (lead only, direct insert); `LeadActivity::taskFor` (five CRM types) over `CalendarEventWriter::create` (no rights check).

**Campaigns, outreach, agents**
- `crm_campaigns`: `campaign_no` unique globally; `product_id` loose. `crm_campaign_targets`: lowercase `target_type`; touchpoint timestamps. `gtm_campaigns` and `gtm_campaign_members`: segment-based.
- `gtm_playbooks`: tenant NULL means platform default; unique `(tenant, slug)` (NULLs distinct). `gtm_outreach_messages`: state machine, `approved_hash`, attempts, outcome; missing the two indexes in D19. `gtm_suppressions` (unique `(tenant, kind, value)`).
- `crm_outreach_approvals`: a ledger only. `crm_emails`, `crm_email_links`, `crm_sms_log`. `crm_consent_preferences`: unused.
- `agentic_agent_runs` (GTM agents), `crm_agent_runs` (`json()`, may be missing on live), `gtm_analyses` (every model call). `gtm_workflows`, `gtm_workflow_runs` (unique `(workflow_id, event_id)`), `gtm_workflow_run_steps`.
- Sending flags, all default false: `G2G_NOTIFY_EMAIL`, `gtm.outreach.sending_enabled`, `gtm.outreach.scheduler_enabled`, `crm.email_sending_enabled`; `MAIL_MAILER=log`. SMS has no flag (D6).

## Addendum (2026-11-02, after the S0 baseline): what changed

Measured read-only on dev and live (`_evidence/s0-baseline-2026-11-02/FINDINGS.md`):

- **D12 changed.** `g2g_signal_products` has run on live (0 rows; `sub_institute_id int unsigned`, utf8) and is missing on dev. It cannot be edited in place. S1 adds a corrective migration (create it correctly where missing; convert it on live only if it still has 0 rows).
- **S1 backfill is small.** `crm_products` has 0 rows on both hosts. Live has 21 offers (all tenant 6), no shared offers, no duplicate `offer_id`.
- **D20 (new).** `ColumnExists::has()` throws on live (the bound `SHOW COLUMNS ... LIKE ?` form fails on MariaDB 10.1). Fixed in code, not deployed.
- **D21 (new).** `SkillMatrixController.php` did not parse (stray unscoped line from the 6 Oct merge; same error on `origin/main`). Fixed in code, not deployed. D1 also fixed.
- **S11.** `gtm_forecast_snapshots` already exists on both hosts (unique on tenant, date, period, currency; no per-owner column), so the forecast slice reuses it and adds an owner dimension only if the owner asks.
- **S2.** 32 of 72 live signals have no `business_fit`, so their product cannot be derived by the resolver. Live `g2g_company_opportunities` has 76 columns (dev 70).
- **S0 baseline facts for the database rules.** Live is non-strict, Compact row format, `explicit_defaults_for_timestamp=0`; dev is strict, Dynamic. Raw DDL must state utf8mb4 explicitly (dev's database default is latin1).
- **D6 is real but narrow.** One SMS gateway row exists on each host, not for tenant 6. A default-off switch would stop that tenant's SMS, so it needs the owner's decision.
- **D14 evidence.** `g2g_platform_task_runs` has 2 rows per host, all from September 2026.
