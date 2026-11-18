# S0 baseline — findings (2026-11-02)

Produced by read-only scripts (each sets `SET SESSION TRANSACTION READ ONLY`, then only SELECT and SHOW), run from `C:\Users\MILAN\Downloads\hp_erp` with PHP 8.2.12:

- `D:\dev\agentic-crm-research\scripts\g2g\s0_baseline.php` → `mysql.json`, `live.json` (full results), then `s0_summarize.cjs` → `summary.txt` (side-by-side comparison).
- `s0_followup.php` (shape of `g2g_signal_products` and `gtm_forecast_snapshots`) and `s0_columnexists_test.php` (the real `App\Support\ColumnExists` helper).
- Connections: `mysql` = dev (202.47.117.220), `live` = production database (128.199.17.97; `db3` is a second name for the same host and database). Counts and names only: no keys, no message text, no personal data.

All facts below are **verified** (I ran the queries and read the output) unless marked otherwise.

## Servers

| | dev (`mysql`) | live (`live` / `db3`) |
|---|---|---|
| Version | MariaDB 10.11.9 | MariaDB 10.1.48 |
| Session `sql_mode` | STRICT_TRANS_TABLES, NO_ZERO_IN_DATE, NO_ZERO_DATE, ERROR_FOR_DIVISION_BY_ZERO, NO_ENGINE_SUBSTITUTION | NO_ENGINE_SUBSTITUTION only (not strict) |
| `explicit_defaults_for_timestamp` | 1 | 0 |
| Database default charset | latin1 / latin1_swedish_ci (tables are utf8mb4_unicode_ci) | utf8mb4 / utf8mb4_unicode_ci |
| InnoDB large prefix | variable does not exist | `innodb_large_prefix=1`, file format Barracuda, but sampled tables use **Compact** row format, so the 767-byte index limit applies |
| Tables / migrations recorded | 649 / 714 | 581 / 580 |

Consequences: dev is strict and live is not, so a statement can pass on live (silently truncating) and fail on dev. Raw `CREATE TABLE` must state `CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` explicitly (dev's database default is latin1). Keep indexed varchar at 191 characters or less.

## Column-check forms (settles the `ColumnExists::has` conflict)

| Form | dev | live |
|---|---|---|
| Bound `SHOW COLUMNS … LIKE ?` | works | **fails: SQLSTATE 42000, error 1064** |
| Literal `SHOW COLUMNS … LIKE 'col'` | works | works |
| Bound `information_schema.COLUMNS` select | works | works |
| `Schema::hasTable` | works | works |
| `Schema::hasColumn` | works | works (the `LegacyMariaDbSchemaGrammar` patch is active) |
| **`App\Support\ColumnExists::has()` (the real helper)** | works | **throws `QueryException` 1064** |
| `App\Support\ColumnExists::all()` | works | works |

`ColumnExists::has` has 19 call sites in 11 files under `app/` (Signals researcher, Portfolio and ICP controllers, several console commands). Some are unguarded, for example `OpportunityResearcher.php` lines 1019, 1021 and 2097, which run while storing research sources and checking `stage_stats`. If production runs this code, those paths fail on live. Whether the deployed backend is older than branch `Milan-2` is unknown. **Deploying this branch to production before fixing the helper risks breaking Signals runs and the Portfolio and ICP endpoints.** Logged as D20.

## Schema state differs between the hosts

- Live is *ahead* of dev for Signals: `g2g_company_opportunities` has 76 columns on live and 70 on dev (live only: `product_key`, `offer_id`, `customer_model`, `icp_profile_id`, `icp_version`, `region_scope`); `g2g_research_runs` 33 vs 27 (live only: `stage_stats`, `icp_version`, `customer_model`, `offer_id`, `region_scope`, `scope_key`; `stage_message` is TEXT on live, varchar(255) on dev); `g2g_product_offers` has `icp_config` on live only.
- Migrations on disk dated 2026_11_* and 2026_12_*: 121 files. Recorded on dev but not live: `2026_11_26_100000_add_delivery_tracking_to_ai_report_deliveries`, `2026_12_03_100000_create_crm_signal_inbox_table`, `2026_12_03_100100_create_crm_signal_topics_table`, `2026_12_03_120000_create_crm_agent_runs_table`, `2026_12_03_120100_create_crm_automation_rules_table`. Recorded on live but not dev: `2026_12_02_090000_widen_stage_message_on_g2g_research_runs`, `2026_12_03_090000_add_stage_stats_to_g2g_research_runs`, `2026_12_05_090000_create_g2g_signal_products_table`.
- `crm_signal_inbox`, `crm_signal_topics` and `crm_automation_rules` exist on live (created some other way, since their migrations are not recorded); **`crm_agent_runs` is missing on live** (it exists on dev). The menu row `/module/crm/marketing/agent-runs` is active on both hosts (id 486 on live).
- **`g2g_signal_products` has run on live** (0 rows; `sub_institute_id INT UNSIGNED`, `created_by INT UNSIGNED`, collation `utf8_general_ci`, row format Compact, three unique keys) and is **missing on dev**. So the D12 plan changes: the migration file cannot be edited in place; a new corrective migration is needed (see S1).
- `gtm_forecast_snapshots` exists on both hosts: `sub_institute_id`, `snapshot_date`, `period` char(7), `currency` char(3), `deal_count`, `pipeline_value`, `best_case_value`, `commit_value`, `weighted_value`, `created_at`; unique on (tenant, snapshot_date, period, currency); 0 rows on live, 1 on dev. It has no per-owner column.
- Differences are tabulated per table in `summary.txt`.

## Data sizes (rows not soft-deleted; tenant 6 in brackets; live unless noted)

- Signals: `g2g_company_opportunities` 72 (61), by `business_fit`: scholar 37, eb 3, **NULL 32**; `g2g_research_runs` 72 (70); `g2g_research_sources` 1,541 (1,477); `g2g_companies` 50 (49); `g2g_ingestion_findings` 101; `g2g_icp_profiles` 39; `g2g_event` 1,465 (1,315).
- Products: **`crm_products` has 0 rows on both hosts**; `g2g_product_offers`: live 21 (all tenant 6), dev 50 (25 for tenant 1, 25 for tenant 6); **no shared (NULL-tenant) offers on either host; no duplicate `offer_id` within a tenant on either host**; `g2g_signal_products` 0 rows on live.
- CRM on live: leads 5 (3), contacts 2 (1), organisations 2 (1), opportunities 1 (0), campaigns 0, signal inbox 0, automation rules 1, emails 1, comments 2, consent preferences 0. GTM on live: accounts 7, contacts 1, deals 0, workflows 0 (dev has 22), playbooks 19, analyses 20.
- Plain counts overstate people (earlier finding): soft-deleted rows were excluded where the table has `deleted_at`.

## Scheduler and sending

- `g2g_platform_task_runs` holds only 2 rows on each host, all from September 2026 (dev: `events:project`, `events:react` on 2026-09-24; live: `readiness.recompute`, `leave.escalate` on 2026-09-28), and none for `signals.research`, `gtm:tick` or `events:*` on live. Consistent with a scheduler that is not running (D14); not proof, because only tracked tasks write here.
- `school_setup.outbound_email_enabled = 1` for one tenant on each host (13 tenants on live, 16 on dev). Email also needs the env master flags, which I cannot read from here (open question for the owner: the values of `G2G_NOTIFY_EMAIL`, `CRM_EMAIL_SENDING_ENABLED`, `GTM_OUTREACH_SENDING_ENABLED` and `GTM_OUTREACH_SCHEDULER_ENABLED` on the production server).
- **One SMS gateway row exists on each host** in `sms_api_details` (not for tenant 6; `g2g_integration_credentials` holds no SMS provider rows). D6 is therefore real for that one tenant: a user there with edit right on the SMS Notifier can send real SMS.

## Menu rows (by `access_link`)

Both hosts carry the same set of CRM, GTM, Signals, Portfolio, Partners and Ingestion rows with different numeric ids (e.g. GTM root 441 dev / 438 live; `/module/crm/marketing/leads` is 201 on both). Hidden (status 0) on both: `/gtm`, `/gtm/deals`, `/gtm/reports`, `/module/crm/marketing`, `/module/crm/marketing/campaigns`, `/module/crm/marketing/outreach-approvals`, `/module/crm/sales`. **`/module/crm/support` (the Support parent) is status 1 on dev and status 0 on live**, while its children are status 1; worth a look before any Support work (S13). `/module/crm/marketing/signal-inbox` hangs under the Signals root on dev (parent 489) but under the hidden marketing row on live (parent 200).

## Not measured

- Environment variables on the production server (mail and SMS flags, queue driver, scheduler cron).
- Whether the deployed backend matches branch `Milan-2`.
- Row counts for tenants other than 6 beyond totals.
- The `gtm_forecast_snapshots` usage in code.
