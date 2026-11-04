# Rollout runbook: from "works on dev" to "the team uses it on G2G" (written 5 Nov 2026, about 09:30 IST)

**Nothing in this file has been run. Every step that touches live (the production database, a push, a deploy, a menu or rights row) waits for the owner's explicit word, step by step.** The dev database (202.47.117.220, MariaDB 10.11) accepts everything; live (128.199.17.97, MariaDB **10.1.48**) is older and stricter, which is why steps 3 and 4 exist. Rules: `DB-RULES.md` section 4 and section 10 (order per change), `DB-CHANGELOG.md` (one row per change, rows 1 to 50 and later).

## 0. What is being released (commits, all on `Milan-2` (hp_erp) and `milan1` (g2gv0), none pushed)

Everything from S1 (Product Master inside Products) to S11a (pipeline safety), the Agent Run History tabs, the Ask chat, the leads, facts, campaigns and (when verified) the outreach gate and campaign screens. The team's `origin/main` is already merged into both branches (4 Nov), so a push is a fast-forward of our work onto theirs plus whatever they added since: re-check with `git fetch` and a trial merge (`git merge-tree`) the day of the push.

## 1. Preconditions (owner)

1. The word "go" for each stage below (a "go" for one stage is not a go for the next).
2. A backup of the live database and of the two code directories on the server before anything runs (`mysqldump` of the tables a step touches, at least; the `migrations` table always).
3. A maintenance window or a quiet hour: the migrations are additive (new tables, new nullable columns, indexes), none drops or retypes a column the old screens use, but an index build locks a table briefly.
4. Out-of-band facts only the server can tell (open since S0): `QUEUE_CONNECTION`, the scheduler cron (`schedule:run` is NOT active on live today: the daily forecast record and `signals:research` do nothing until it is), the sending flags (`G2G_NOTIFY_EMAIL`, `gtm.outreach.sending_enabled`, `crm.email_sending_enabled` must stay false), and whether the deployed backend already has commit `02d4d896` (the `ColumnExists` fix) and the `LegacyMariaDbSchemaGrammar` (on `origin/main`).

## 2. Order of the whole release

1. **Code first, migrations second** (the migrations use `Schema::hasColumn`, which MariaDB 10.1 can only run through `App\Database\Schema\LegacyMariaDbSchemaGrammar`, registered in `AppServiceProvider`; `MariaDb101SafetyTest` pins that it stays registered). Deploy the backend commit, then the frontend. The new code tolerates a host that has not run the migrations yet (every new field is optional; the product filter on leads then answers 422), so a gap between the two is safe, but do not leave one.
2. The fix branch for the byte-order mark in `routes/api.php` (`fix/api-routes-byte-order-mark`, `b4a3d51c`) is NOT deployed and downloads on production are broken until it is: check the first bytes of any API answer with `curl` (`3c21` is right, `efbbbf` is the defect) and say so before anything else is blamed.
3. Migrations, one file at a time, in the order below, each with the scratch-table probe first.
4. Backfills (dry run first), in the order below.
5. Menu rows and rights (leaf rows only, never an ancestor; count the audience first).
6. Verify (read-only), log in `DB-CHANGELOG.md`, then the next stage.

## 3. The migrations (candidates: confirm against live's own `migrations` table, read-only, at the time of the go)

The dev database cannot say which of these live lacks (batch numbers differ per host). S2's four (2026_12_08_090000 to 090300) are ALREADY on live (3 Nov). `tests/Unit/MariaDb101SafetyTest.php` scans every migration from 2026_12_09 on and the PHP of the new domains for what 10.1 cannot run (JSON columns and functions, `->change()`, bound `SHOW ... LIKE ?`, generated columns, expression defaults, `SKIP LOCKED`, window functions, CTEs): it passes, so none of the files below uses them. The ones before 2026_12_09 were rehearsed on 10.1 on 3 Nov (S2) or are the team's.

| Stage | Migration | What it adds |
|---|---|---|
| S1 | `2026_12_05_090000_create_g2g_signal_products_table`, `2026_12_07_090000_create_g2g_product_master_table`, `2026_12_07_090100_ensure_g2g_signal_products_table` | custom product lines, the Product Master |
| S2 add-ons | `2026_12_08_100000_add_currency_to_ai_models`, `2026_12_08_100100_add_cost_columns_to_ai_usage_events`, `2026_12_08_100200_add_ai_cost_to_g2g_research_runs` | rupee prices |
| S3 | `2026_12_09_090000_create_g2g_org_accounts_tables`, `2026_12_09_090100_widen_crm_email_columns_to_191` | Organisation 360 identities, wider email columns |
| S7 | `2026_12_10_090000` to `090300` (agent runs, proposals, tool calls, autonomy), `090400` (`created_via` on notes), `120000_move_signal_review_menu_row_to_the_crm_root` | the agent, a menu row move |
| S4 | `2026_12_11_090000` to `090200` | facts ledger, evidence, `linkedin_url` |
| S6 | `2026_12_11_100000`, `100100` | lead number sequence, product and account on leads, `description` to TEXT |
| S8 | `2026_12_11_120000`, `120100` | Ask conversations and messages |
| S11 | `2026_12_11_130000`, `130100` | product and loss reason on opportunities, the forecast record |
| S9 | `2026_12_11_140000` to `140200` | campaign product and links, `campaign_id` on leads |
| S10 (after its proofs) | `2026_12_11_150000` to `150400` | outreach gate checks, product claims, product link columns, delivery indexes |

Per file: (a) probe on db3 with `zz_probe_` scratch tables made by `CREATE TABLE ... LIKE` and the same ALTER or CREATE (the S2 method, `_evidence/s2-live-rollout-2026-11-03.md`), then drop the scratch tables; (b) `php artisan migrate --path=<that file>` (never an unscoped `migrate`: live is far behind); (c) `SHOW CREATE TABLE` against the dev output saved under `_evidence/`; (d) a row in `DB-CHANGELOG.md`. Rollback is each file's tested `down()` in reverse order.

## 4. Backfills (all dry run first, `--apply` after the owner has seen the counts; a re-run changes nothing)

In order: `product-master:backfill`, `org-accounts:backfill`, `signals:backfill-date-status`, `signals:backfill-reviews`, `signals:backfill-product-master`, `leads:backfill-links`, `campaigns:backfill-links`, `opportunities:backfill-links`, then `consent:reconcile` (dry run, read the report: it aligns the CRM opt-out flags, the preference rows and the suppression lists, and it never opts anyone IN) and one `opportunities:forecast-snapshot --all --apply`. Live data is small for tenant 6 (about 6 leads); the dry-run counts are the evidence.

## 5. Who may use it (rights)

Tenant 6 has 23 active users: 2 Admin, 18 Employee, 1 HR, 1 Executive, 1 Auditor; only 3 have ever logged in. Only the Admin profile holds CRM rights today. Grants are leaf rows only (Leads 201, Contacts 451, Organizations 452, Opportunities and Deals 466, Quotes 467, Products 468, Sales Orders 473, Invoices 475, Reports 487, Agent Run History 503, Signal Review 506, Market Signals 489 and its four tabs), per profile, in the Role and Permissions screen, after the owner names the profile (a new "Sales" profile is safer than granting the 18-person Employee profile). The CRM Campaigns row (453) and Outreach Approvals (499) are HIDDEN (`status` 0) for everyone: unhiding is a menu change, the owner's call. New abilities (facts, evidence, the chat, the two run-history tabs, the product and funnel) live inside existing screens and need no new row.

## 6. Verify after each stage (read-only on live)

The same `SHOW CREATE TABLE` and row counts as dev; the API answers `GET /api/crm/leads`, `/api/agent/autonomy`, `/api/facts/queue`, `/api/crm/campaigns` for a tenant-6 Admin token with status 200 and the new keys; first bytes of an answer `3c21`; no new line in `storage/logs/laravel.log` with the word "Column not found". The day-in-the-life script (`D:\dev\agentic-crm-research\scripts\browser\daily-jobs-5nov.cjs`) writes marked rows and is for DEV only, never live.

## 7. Rollback

Schema: each file's `down()`, reverse order. Data: the rollback column of each `DB-CHANGELOG.md` row (backfills write only NULL columns). Code: redeploy the previous commit; the new tables stay unused. Behaviour: the agent kill switch (Agent Run History, Autonomy), `AskRights::ASK_RIGHT`, the config switches in `config/crm_sales.php` and `config/outreach_gate.php`, and every sending flag false.

## 8. Not done and not claimed

Nothing has run on MariaDB 10.1 (live) except S2's four migrations. The 10.1 scan is static (it finds constructs, not every behaviour); the scratch-table probe in step 3(a) is the real test. The push, the deploy and the live steps are not done; there is no deploy pipeline I can run.
