# Agentic CRM — Database rules

**Status:** confirmed against the code and against both databases on 2026-11-02 (S0 tasks 3 and 4). Evidence: `_evidence/s0-baseline-2026-11-02/FINDINGS.md`, `summary.txt`, `mysql.json`, `live.json`.

| | dev (`mysql` connection) | live (`live` = `db3`) |
|---|---|---|
| Host, database | 202.47.117.220, `hp_erp` | 128.199.17.97, `hp_erp` |
| Version | MariaDB 10.11.9 | MariaDB 10.1.48 |
| Session `sql_mode` | strict (STRICT_TRANS_TABLES, NO_ZERO_DATE, NO_ZERO_IN_DATE, …) | NO_ENGINE_SUBSTITUTION only (not strict) |
| `explicit_defaults_for_timestamp` | 1 | 0 |
| Row format of sampled tables | Dynamic | Compact (767-byte index limit applies) |

Because dev is strict and live is not, a statement can pass on live and fail on dev, or silently truncate on live. Test on dev under strict mode, and validate lengths in code.

## Always

1. **Additive only.** Expand, then move data, and remove much later in its own slice. Never drop, rename or retype a column an existing screen uses. Never edit a migration that has run on any host.
2. **Name files to sort after the newest existing migration**, not by today's date. The newest is `2026_12_06_100000_unify_sales_marketing_pipeline_lifecycle`, later than the calendar date. Several files share a timestamp (three start `2026_12_03_090000_`), so give new files a unique timestamp. List `database/migrations` before naming.
3. **One migration file per change set, run one at a time.** Never run `migrate --database=live` unscoped; always `--path=<file>`. The two hosts have different recorded migrations (8 differ among the 121 files dated 2026_11_ and 2026_12_), so check the `migrations` table of each host before assuming a file is pending or done.
4. **Tenant column** `sub_institute_id` is `BIGINT UNSIGNED NOT NULL`. Shared rows use `0`, never NULL (legacy NULLs exist in `crm_leads`, `gtm_playbooks`, `crm_knowledge_sources`, `crm_signal_inbox`). Every unique index includes the tenant. A read by id for another tenant returns 404.
5. **No foreign keys on new tables.** Use loose pointer columns, plus a read-only `integrity:check` that lists orphans.
6. **Type values on new tables are lowercase singular** (`lead`, `contact`, `organization`, `opportunity`). Old tables mix UPPERCASE (calendar events, `crm_email_links`, `document_library_links`) and lowercase (`crm_campaign_targets`, `crm_sms_log`, `crm_consent_preferences`); use a small mapping helper.
7. **Guarded and repeatable.** Each migration checks table, column or index existence first. `down()` removes only what `up()` created (widening migrations may leave `down()` empty, as `2026_12_02_090000` does).
8. **Data updates are never inside schema migrations.** A separate artisan command: dry run by default, `--apply` to write, one transaction per tenant, insert-ignore on unique keys, never overwrites a filled value, prints counts, saves a summary to `_evidence/`, and a re-run changes nothing.

## Checking whether a table or column exists (VERIFIED on both hosts, 2026-11-02)

| Form | dev | live |
|---|---|---|
| Literal `SHOW COLUMNS FROM \`t\` LIKE 'col'` | works | works |
| Bound `SELECT … FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?` | works | works |
| `Schema::hasTable()` | works | works |
| `Schema::hasColumn()` (needs the `LegacyMariaDbSchemaGrammar` patch, installed by `AppServiceProvider::useLegacySafeSchemaGrammar`, lines 122–137) | works | works |
| Bound `SHOW COLUMNS … LIKE ?` | works | **fails, error 1064** |
| `App\Support\ColumnExists::has()` (it uses the bound form, `ColumnExists.php:24`) | works | **throws `QueryException` 1064** |
| `App\Support\ColumnExists::all()` | works | works |

**Status of D20:** `ColumnExists::has()` is fixed in code (commit `02d4d896`: quoted literal, exact-name match) but **not deployed**, and a migration run from another checkout or an older deploy still has the bad helper. So in migrations keep using the literal form, the `information_schema` form or `Schema::hasColumn` rather than `ColumnExists::has()`. Four older migrations already say the bound `SHOW … LIKE ?` form fails on live; they were right.

## Changing and creating (read in code; behaviours checked where noted)

- **Raw DDL for mysql/mariadb, Blueprint for other drivers.** The repo pattern (`2026_12_02_090000`, `2026_12_05_090000`) branches on `DB::connection()->getDriverName()`: raw `ALTER TABLE … MODIFY` or `CREATE TABLE` for `mysql`/`mariadb`, the portable Schema call for sqlite (the PHPUnit database), so tests still build the table. `->change()` is not relied on.
- **Charset in raw DDL:** state `DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` explicitly. Dev's database default is latin1 (VERIFIED), and the unrun-then-run `g2g_signal_products` migration used `utf8`, which is why that live table is `utf8_general_ci` (VERIFIED). Sibling tables on both hosts are `utf8mb4_unicode_ci`.
- **No JSON column type** on live: use `longText` and cast in the model. Tables created with `->json()` cannot be created on live (`crm_agent_runs` is MISSING on live: VERIFIED). `crm_signal_inbox`, `crm_signal_topics` and `crm_automation_rules` do exist on live although their migrations are not recorded there (VERIFIED).
- `Schema::defaultStringLength(191)` is set (agent-read): a bare `string()` is varchar(191). Live tables use the Compact row format (VERIFIED on samples), so an indexed or unique varchar over 191 characters needs a prefix index (767-byte limit with utf8mb4).
- **First `NOT NULL DEFAULT CURRENT_TIMESTAMP` column of a table:** MySQL and MariaDB add `ON UPDATE current_timestamp()` to it when `explicit_defaults_for_timestamp = OFF`, so any UPDATE rewrites it. Live has the setting OFF (VERIFIED). `g2g_company_opportunities.first_discovered_at` is already fixed on live (its `Extra` is empty: VERIFIED). Use nullable timestamps with explicit values, or check the `Extra` column after creating.
- **Legacy zero dates:** `2026_10_08_110000` documents that `g2g_company_opportunities.last_verified_at` once had `DEFAULT '0000-00-00 00:00:00'`. Live now shows `CURRENT_TIMESTAMP` for it (VERIFIED). On dev (strict, NO_ZERO_DATE), any ALTER that meets a zero-date default fails; the pattern in that migration relaxes `NO_ZERO_DATE`/`NO_ZERO_IN_DATE` for the session and restores it in a `finally`, validating the mode string against `^[A-Z_,]*$` and setting it as a literal.
- `strict=false` on live: over-long values are silently truncated, so validators must enforce lengths.
- No SKIP LOCKED: queue work uses the Laravel database queue.

## Order for every change

1. Write migration, model and tests on dev. Run the single file on dev.
2. Compare `SHOW CREATE TABLE` and `SHOW COLUMNS` with the expectation; save the output to `_evidence/`. Run the tests.
3. Owner says "go" for live.
4. Before live: schema diff of the affected tables dev vs live, check the `migrations` table of each host, dump any table a data update will write to.
5. Run on live, verify with the same queries, log a row in `DB-CHANGELOG.md`.

Rollback: schema through `down()` (tested on dev); data updates write only into new columns or tables, so rollback means nulling the column or truncating the table.

## The two hosts are not copies of each other

Live is ahead of dev for Signals (live `g2g_company_opportunities` has 76 columns, dev 70; live has `g2g_signal_products`, dev does not), and dev is ahead for CRM tables (dev has `crm_agent_runs`, live does not). A guarded migration must therefore be safe on a host that already has the column or table, and its check must run on each host separately. Read `FINDINGS.md` for the list.

## Menu and rights

A migration adds leaf rows under menu root 199 (`/module/crm`, shown as "Sales & Marketing") matched by `access_link`, and grants the Administrator profile only. Numeric menu ids differ between hosts (GTM root: 441 on dev, 438 on live; VERIFIED). Other profiles are granted by the owner in the rights UI after the audience is counted. Never grant an ancestor row. Rights precedence (`app/Support/MenuRight.php`): individual deny, group deny, individual allow, group allow, role default, deny.

## Optional rehearsal (owner decides; unverified)

Restore a schema-only dump of live into a disposable MariaDB 10.1 container on D: and run each migration there first. Needs Docker and the owner's OK for a read-only schema export. The Compact row format and non-strict mode on live are exactly the conditions this would reproduce.
