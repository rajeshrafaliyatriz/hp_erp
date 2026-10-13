# Signals market pipeline: deploying to live (MariaDB 10.1.48)

Written for whoever runs the deploy. **Nothing here has been run against live.** The migrations were
run against a copy of the real `hp_erp` database on MariaDB 10.4 (not 10.1.48). Section 6 lists every
SQL feature that could behave differently on 10.1, with what is verified and what is not.

## 0. Before you start

1. **Take a full backup of the live database** and confirm you can restore it.
2. `php artisan migrate:status` on live. These must already say `Ran` (they did on 2026-10-05):
   `2026_09_30_120000_create_g2g_signals_tables`, `..._150000_create_g2g_opportunity_and_ingestion_tables`,
   `..._180000_`, `..._200000_`, `..._220000_create_g2g_portfolio_and_partners_tables`, `..._230000_`,
   `2026_10_01_130000_add_stage_to_g2g_research_runs`.
3. Live also has **other pending migrations** (at least `2026_09_19_120000_create_ai_intelligence_tables`,
   `2026_09_21_150000_create_ai_usage_tables`, and some `2026_09_30_12xxxx` role/rights ones). **Do not run a
   plain `php artisan migrate`.** Run only the three files below, by path.
4. The database user needs `CREATE`, `ALTER`, `INDEX`, `DROP` (the last only for rollback). Not verified for the live user.

## 1. Migrations that will run, in this order

| # | File | What it does | Risk |
|---|---|---|---|
| 1 | `2026_10_08_100000_extend_g2g_signals_for_market_import` | Adds ~35 nullable columns to `g2g_company_opportunities`, 2 to `g2g_companies`, 6 to `g2g_opportunity_matches`, `intent` to `g2g_ingestion_sources`; makes `research_run_id` nullable; creates `g2g_company_aliases`, `g2g_opportunity_events`, `g2g_signal_scan_log`, `g2g_signal_import_rejections`. | ALTER on small tables (a copy on 10.1: seconds). Additive; existing rows untouched. |
| 2 | `2026_10_08_110000_protect_first_discovered_at_on_opportunities` | Removes `ON UPDATE current_timestamp()` from `first_discovered_at`. | One ALTER; values preserved. |
| 3 | `2026_10_08_120000_add_readiness_confirmation_to_g2g_product_offers` | Adds `readiness_confirmed_by/at` (nullable) to `g2g_product_offers`; creates `g2g_offer_readiness_log`. | Additive. Confirms nothing. |

```
php artisan migrate --force --path=database/migrations/2026_10_08_100000_extend_g2g_signals_for_market_import.php
php artisan migrate --force --path=database/migrations/2026_10_08_110000_protect_first_discovered_at_on_opportunities.php
php artisan migrate --force --path=database/migrations/2026_10_08_120000_add_readiness_confirmation_to_g2g_product_offers.php
```

All three are guarded (`hasTable` / `SHOW COLUMNS` / `SHOW INDEX`) and safe to run twice.

## 2. Rehearse on a restored copy first (do not skip)

`--pretend` is **not** a rehearsal: the guards read the database, and pretend mode does not execute reads.

1. Restore the backup into a scratch database on a MariaDB **10.1.x** server (same major version as live if
   you can: that is the whole point). Never the live database.
2. Point a throwaway `.env` at it and run the three commands above, in order. Expect `DONE` x3.
3. Run each command a second time. Expect `DONE` again, no errors (idempotent).
4. Check the result:
   ```sql
   SHOW COLUMNS FROM g2g_company_opportunities LIKE 'research_run_id';          -- Null = YES
   SHOW COLUMNS FROM g2g_company_opportunities LIKE 'first_discovered_at';      -- Extra must NOT contain "on update"
   SELECT COUNT(*) FROM g2g_company_opportunities;                              -- unchanged from before
   SELECT SUM(readiness_confirmed) FROM g2g_product_offers;                     -- unchanged (0 today)
   SELECT @@SESSION.sql_mode;                                                   -- same as before you started
   ```
5. Import the sample (writes sample rows only; remove them afterwards):
   `php artisan signals:import-market docs/samples/market-signals.sample.json --tenant=<id>`
   Expect `2 accepted, 1 rejected`. Delete the rows where `is_sample = 1` afterwards.
6. Prove `first_discovered_at` survives a real change:
   ```sql
   UPDATE g2g_company_opportunities SET review_status='Reviewed' WHERE id=<one id>;
   SELECT first_discovered_at FROM g2g_company_opportunities WHERE id=<same id>;  -- must be unchanged
   ```
   (set `review_status` back afterwards).

If any step errors, stop and send the error text. Do not proceed to live.

## 3. Deploy order

1. Backup (section 0).
2. **Migrations first** (section 1). They are additive, so the *currently deployed* code keeps working.
   The new code reads the new columns, so deploying code before the migrations would make the opportunities
   list fail.
3. Backend code: `composer install --no-dev -o`, then `php artisan config:clear && php artisan config:cache`,
   `php artisan route:clear`, restart the queue worker if there is one (live runs `sync`, so none today).
4. Frontend: build with the production API base URL and deploy (`npm ci && npm run build`).
5. Verify (section 4).

## 4. Verify on live

* `GET /api/signals/opportunities` returns the existing researched rows (`data[].matched_offers` is `[]`).
* The Providers dialog opens; **Run Research Now** still works.
* Product Portfolio: an administrator sees **Confirm readiness**; HR and others do not. Leave all offers as they are.
* Ingestion engine: the **Foundation / Market signals** switch appears. Use **Check only (dry run)** on the sample.
* No offer's `readiness_confirmed` changed: `SELECT COUNT(*) FROM g2g_product_offers WHERE readiness_confirmed=1;`

## 5. Rollback

* **Code**: revert the commits; safe at any time. Old code ignores the new columns and tables.
* **Schema**: leaving it in place is the recommended rollback. Each migration's `down()` removes only the *new
  tables* (`g2g_company_aliases`, `g2g_opportunity_events`, `g2g_signal_scan_log`, `g2g_signal_import_rejections`,
  `g2g_offer_readiness_log`) and deliberately leaves the added columns (dropping them destroys imported data and
  the record of who confirmed what):
  ```
  php artisan migrate:rollback --path=database/migrations/2026_10_08_120000_add_readiness_confirmation_to_g2g_product_offers.php
  php artisan migrate:rollback --path=database/migrations/2026_10_08_100000_extend_g2g_signals_for_market_import.php
  ```
  (`rollback` by path only works if the migrations are recorded in the `migrations` table, which `migrate --path` does.)
  Migration 2 has nothing to undo; do not re-add `ON UPDATE` (it is the bug).
* **Data**: only if no import has run, the added columns can be dropped by hand. Otherwise restore the backup,
  which loses everything written since.

## 6. SQL features the new migrations and importer use: 10.1.48 vs the 10.4 they were tested on

| Feature | Where | Status |
|---|---|---|
| `SHOW COLUMNS FROM t LIKE ?` | migrations (column guard) | Standard on every version. Verified 10.4; expected 10.1 (the repo already uses it for the same reason). |
| `SHOW INDEX FROM t WHERE Key_name = ?` | migration 1 | Standard. Verified 10.4; expected 10.1. |
| `SET SESSION sql_mode = '<literal>'` via `DB::unprepared` | all three migrations | Avoids a prepared `SET`. Verified 10.4; expected 10.1. The `mysql` connection also has emulated prepares on. |
| `Schema::hasTable()` | all | information_schema.tables; the repo documents it works on 10.1. |
| `ALTER TABLE ... ADD COLUMN` (several in one statement), `DEFAULT 'NEW'`, `DEFAULT 0` on a populated table | migration 1, 3 | Standard. Verified 10.4. **Not verified on 10.1.** |
| `MODIFY research_run_id BIGINT UNSIGNED NULL` (Laravel `->change()`) | migration 1 | Standard. Verified 10.4; not verified on 10.1. |
| `MODIFY first_discovered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` | migration 2 | Verified 10.4. **Uncertain on 10.1**: the old "first TIMESTAMP gets auto ON UPDATE" rule is exactly what is being removed, and 10.1 applies it by default; an explicit default should suppress it. Check step 2.4 in the rehearsal. |
| Legacy zero-date default `last_verified_at DEFAULT '0000-00-00 00:00:00'` and strict mode | migrations 1-3 | Handled by relaxing `NO_ZERO_DATE` for the migration's session only. Which `sql_mode` the live connection really runs is **unknown**; the migration works with either. |
| `longText` instead of `JSON`; `dateTime`; `decimal(5,2)`; `unsignedTinyInteger` | schema | No JSON type (10.1 has none). Standard types. |
| Unique `(sub_institute_id, normalized_alias)` with `varchar(191)` utf8mb4 | `g2g_company_aliases` | 764 bytes per column, under the 767-byte limit that applies on 10.1 without `innodb_large_prefix`. The existing tables use the same width. Not verified on 10.1. |
| `ORDER BY (o.expires_at IS NULL) ASC, o.expires_at, o.score_total DESC` | feed query | Standard MySQL. Verified on sqlite and 10.4 data. |
| `LIKE '%"N01"%'` on a longText column | need-code filter | Standard. |
| `first_discovered_at = first_discovered_at` in an UPDATE | importer | Standard; verified 10.4 with the `ON UPDATE` clause present and absent. |
| No JSON functions, CTEs, window functions, generated columns, `RETURNING`, `CHECK` | everything | None are used. |

**Not verifiable from here:** the live user's privileges, the live `sql_mode`, `innodb_*` settings, the exact
10.1 behaviour of the two "uncertain" rows, lock/ALTER time on the real table sizes, and anything on a fresh
empty database (the migrations were tested chained on sqlite and on top of the real schema, not from nothing).

## 7. Environment variables (all optional, safe defaults)

`SIGNALS_MARKET_IMPORT_MAX_RECORDS` (500), `SIGNALS_MARKET_IMPORT_MAX_KB` (5120), `SIGNALS_MATCH_CODE_BOOST` (25),
`SIGNALS_MATCH_SEGMENT_BONUS` (15), `SIGNALS_MATCH_STORE_MIN_SCORE` (30); from the earlier failover work:
`GEMINI_API_KEYS`, `GEMINI_API_KEY_n`, `AI_FAILOVER_*`, `AI_EXCLUSIVE_MODULES`, `DEEPSEEK_DISABLE_THINKING`.
All are in `.env.example`. After changing any, run `php artisan config:clear`.

## 8. Production gotchas to check

* Uploads: the market file limit is 5 MB. `upload_max_filesize` and `post_max_size` in PHP, and `client_max_body_size`
  in nginx, must allow at least that (the Ingestion limit is already 15 MB).
* Live runs `QUEUE_CONNECTION=sync`: research and analysis run inside the web request. The import itself is
  synchronous and fast (500 records per request).
* Dates are stored as given by the server timezone; the daily scan schedule is `Asia/Kolkata` (`config/signals.php`).
* `storage/` and `bootstrap/cache` must be writable by the web user (unchanged).
* Case-sensitive paths: no new path is built from user input; new files use the existing casing.
