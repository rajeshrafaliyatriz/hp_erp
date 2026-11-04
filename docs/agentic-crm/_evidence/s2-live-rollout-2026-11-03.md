# S2 — database rollout on live (3 Nov 2026)

**Authorisation:** the owner's explicit go, 3 Nov 2026: "yes roll out i give you the permissions" (in answer to the question that listed the four database changes, the backfills, the grant, and deploying backend and front end together). Scope done here: the database part (four migrations, two backfills). **Not done and why:** the product backfill (needs slice 1's `g2g_product_master`, absent on live), the menu grant (held until the front end with the screen is deployed), and the code deployment (no pipeline I can run; see the end).

Live = connection `live`, MariaDB 10.1.48, host 128.199.17.97, database `hp_erp`. Every step below is **verified** (I ran it and read the output).

## Before any change (read-only)

- Nothing from S2 existed on live: no `g2g_signal_reviews`, no new columns on `g2g_company_opportunities` or `g2g_research_runs`, no `/signal-review` menu row; `g2g_product_master` absent (slice 1 not rolled out); `g2g_signal_products` present (old shape, 0 rows).
- `migrations` table: main's `2026_12_07_090000` and `…100000` present (batches 402, 404); newest batch 404; none of the S2 files.
- Schema diff dev vs live (`s2_diff.php`): the **only** differences were the S2 columns "only on dev"; nothing "only on live", no type differences.
- Menu: parent "Market Signals" (`/module/crm/signals`, id 476) present; four sibling leaves; no `/signal-review`. Organisation 6 has an Admin profile (id 16, `role_key` administrator).
- Organisation 6 had **74** signals (the design-gate measurement said 73: one was added since, so the expected split below is 16 / 9 / 49, not 16 / 8 / 49).
- Safety of deploying S2 code before slice 1: `FeedFilters::productNames` returns nothing when `g2g_product_master` is missing (and every signal has `product_master_id` NULL), and the stamping and run-product code is wrapped in try/catch, so the original Signals screen's feed (which shares `presentMany`) cannot break on live.

## Rehearsal on live's MariaDB 10.1 (scratch tables only) — 16 of 16

`s2_live_probe.php` created `zz_s2_probe_reviews` (the raw DDL of migration 1), `zz_s2_probe_opps` and `zz_s2_probe_runs` (`CREATE TABLE … LIKE` the real tables), applied the same column/index operations as migrations 2 and 3, tested the `SHOW INDEX … WHERE Key_name = 'literal'` check and `ColumnExists::has` on 10.1, tested `down()`, then dropped all three. The script refuses to run if the connection is not the expected host or a scratch table already exists. Result: 16 of 16 checks passed, no `zz_s2_probe` table remains (also confirmed after the rollout).

## Backup

`g2g_company_opportunities`, all 85 rows (organisation 0: 10, 1: 1, 6: 74), saved to `D:\dev\agentic-crm-research\live-backups\g2g_company_opportunities-before-s2-2026-11-03.json` (outside the repo: it is business data) with a SHA-256 of every column except the two the backfills write: `50ed18a6c7d648d4d001a925a5702754096d18868f92825aaf70b5a1750f6971`.

## The four migrations, one file at a time, each verified before the next

| # | File | Live result |
|---|---|---|
| 1 | `2026_12_08_090000_create_g2g_signal_reviews_table` | DONE, batch 405, 101 ms. `SHOW CREATE TABLE` identical to dev (12 columns, one index, no unique key, InnoDB, utf8mb4, `created_at` without ON UPDATE) |
| 2 | `2026_12_08_090100_add_review_and_product_columns_to_g2g_company_opportunities` | DONE, batch 406, 1 s. `product_master_id` bigint unsigned NULL, `date_status` varchar(20) NULL, indexes `g2g_opp_tenant_fit_review`, `_discovered`, `_event_date`, `_pm` present; rows still 85 |
| 3 | `2026_12_08_090200_add_cost_and_product_columns_to_g2g_research_runs` | DONE, batch 407, 913 ms. Six columns, all NULL on all 76 existing runs |
| 4 | `2026_12_08_090300_create_signal_review_menu_row` | DONE, batch 408, 346 ms. Row **488**, "Signal Review", `/signal-review`, level 3, under "Market Signals" (476), sort 5; **0** rights rows |

## The backfills (organisation 6 only), dry run, apply, re-run

- `signals:backfill-date-status --tenant=6 --database=live`: dry run 16 event_date_stated / 9 source_date_only / 49 undated (74 signals; 24 carry the stored assessment note); applied the same; second run: 74 left alone, 0 written.
- `signals:backfill-reviews --tenant=6 --database=live`: dry run 5 signals with a decision (dismissed 1, follow_up 4), 5 history rows to create; applied; second run: 5 left alone, 0 created.
- Summaries: `s2-backfill-date-status-LIVE-{apply,rerun}-2026-11-03.txt`, `s2-backfill-reviews-LIVE-{apply,rerun}-2026-11-03.txt`.

## Independent checks on live after the rollout (SQL, not the commands' output)

- SHA-256 of every column except `date_status` and `product_master_id`, all 85 rows: **identical** to the backup's (`50ed18a6…6971`). Nothing else changed in any existing row.
- `date_status` by value {16, 9, 49}; **0** rows whose status disagrees with their own `event_date` / `source_published_at`.
- Other organisations' rows with a `date_status` or `product_master_id`: **0**.
- History rows: exactly the 5 (`via backfill`, organisation 6); decided signals without a history row: **0**; history rows whose `to_status` differs from the signal's status: **0**.
- The feed's own date SQL on live (`s2_feed_sql_probe.php`): total 74; event_date_stated 16, source_date_only 9, undated 49; freshness last_30_days 11, last_90_days 23, older 2, undated 49; 23 + 2 + 49 = 74.

## Not done, and what is needed

1. **Code deployment.** There is no deploy pipeline in either repository and none that I can run or see (earlier finding: push is not deploy). `milan1` (front end) is 5 commits ahead of `origin/main` and 24 behind; `Milan-2` (backend) contains everything from this work. Until the new backend and front end are deployed, the database changes are inert: additive and unused (the old code ignores the new columns and table).
2. **The grant** (`signals:grant-review --tenant=6 --database=live --apply`): run it after the front end with the screen is deployed, otherwise the Admin would see a menu entry for a page that does not exist yet.
3. **Slice 1 on live** (Product Master table, menu row, grant, backfill) is a separate rollout. Until then the Product facet on the new screen offers "All products" only, and the product backfill cannot run.
4. **Not verified on live:** the new screen and the new endpoints (not deployed); a decision made on live; concurrent deciders on MariaDB 10.1; the researcher's cost capture on a real live run.

## Rollback

Schema: each migration's tested `down()`, one file at a time in reverse (menu row deletes its rights; runs drop six columns; signals drop four indexes then two columns; history table dropped), all rehearsed in the scratch run for the signals and runs tables. Data: `UPDATE g2g_company_opportunities SET date_status = NULL WHERE sub_institute_id = 6`; `DELETE FROM g2g_signal_reviews WHERE via = 'backfill' AND sub_institute_id = 6`. The backup file holds the table as it was.
