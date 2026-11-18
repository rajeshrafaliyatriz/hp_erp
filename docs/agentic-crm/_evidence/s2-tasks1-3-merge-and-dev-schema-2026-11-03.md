# S2 tasks 1 to 3 — branch brought up to date, dev schema levelled, S2 schema applied on dev (3 Nov 2026)

Everything below ran on the **dev** database (connection `mysql`, 202.47.117.220, MariaDB 10.11.9). Nothing ran on live except the read-only `s2_diff.php`.

## Task 1 — `origin/main` merged into `Milan-2` (verified)

- `git fetch origin` then `git merge origin/main --no-commit --no-ff`: 22 incoming commits (Harshit, 30 Oct to 2 Nov), 48 files changed on main, 45 on `Milan-2`, **one file changed on both: `app/Http/Controllers/Api/Gtm/DealController.php`**.
- That conflict was my S0 hotfix of the D1 parse error against main's own fix of the same lines. Both removed the same stray block. Main's copy also fixed `to_stage`/`probability` and added a comment. Resolved to main's copy; `git diff origin/main -- DealController.php` is empty; `php -l` clean on it and on **every** PHP file the merge brought from main (no output = no errors).
- Merge commit `4fe2886b`. `git rev-list --left-right --count Milan-2...origin/main` = `22 0` (22 ahead, 0 behind).
- **The two "live-only" migrations were on `origin/main` all along** (`2026_12_07_090000_add_scope_to_g2g_company_opportunities`, `2026_12_07_100000_add_uniqueness_to_signal_opportunities`). The 3 Nov design gate had not fetched since 2 Nov; the earlier "in no branch" finding was true of the local branches only. F-S2-1 stays right that live was ahead of `Milan-2`, wrong that the files were unobtainable. Question 2 of the gate is therefore closed by the merge itself.

## Task 2 — dev levelled with live on the merged Signals schema (verified)

Dev had 8 pending Signals migrations that live already had. Before (read-only diff, `s2_diff.php`):

- `g2g_company_opportunities` only on live: `product_key, offer_id, customer_model, icp_profile_id, icp_version, region_scope`.
- `g2g_research_runs` only on live: `stage_stats, icp_version, customer_model, offer_id, region_scope, scope_key`; `stage_message` varchar(255) on dev vs text on live.

Run on dev, one file at a time with `--path=`, each reported DONE: `2026_10_31_090000_add_research_run_configuration_scope`, `2026_12_02_090000_widen_stage_message_on_g2g_research_runs`, `2026_12_03_090000_add_stage_stats_to_g2g_research_runs`, `2026_12_07_090000_add_scope_to_g2g_company_opportunities` (its backfill copies a run's scope to signals only where the run has a customer model; the column was just added empty, so it changed no row), `2026_12_07_100000_add_uniqueness_to_signal_opportunities` (checks every table for duplicates first; none found; added `g2g_opps_tenant_fingerprint_uq` and `g2g_opp_matches_signal_offer_uq`).

**Deliberately not run on dev:** `2026_12_06_110000_remove_signal_inbox_menu_row` (the owner said to leave the Signal Inbox exactly as it is), `2026_12_05_090000_create_g2g_signal_products_table` (S1's `ensure_…` migration already made that table correctly), `2026_10_27_200000_add_icp_config_to_g2g_product_offers_table` (not a Signals-feed dependency; pending on dev before this work).

## Task 3 — the S2 schema, applied on dev one file at a time (verified)

`2026_12_08_090000_create_g2g_signal_reviews_table` (212 ms), `…090100_add_review_and_product_columns_to_g2g_company_opportunities` (1 s), `…090200_add_cost_and_product_columns_to_g2g_research_runs` (2 s). Output of `s2_verify.php` (SHOW CREATE TABLE / SHOW COLUMNS / SHOW INDEX):

```
g2g_signal_reviews: id, sub_institute_id (bigint unsigned NOT NULL), opportunity_id, decision varchar(12), reason_code varchar(30) NULL,
  note text NULL, from_status varchar(12), to_status varchar(12), via varchar(16) DEFAULT 'signal_review', decided_by NULL,
  decided_at datetime NOT NULL, created_at timestamp NULL DEFAULT NULL   (no ON UPDATE clause)
  KEY g2g_sr_tenant_opp_decided (sub_institute_id, opportunity_id, decided_at)   ENGINE=InnoDB utf8mb4_unicode_ci
g2g_company_opportunities: product_master_id bigint(20) unsigned NULL; date_status varchar(20) NULL
  new indexes: g2g_opp_tenant_fit_review, g2g_opp_tenant_discovered, g2g_opp_tenant_event_date, g2g_opp_tenant_pm
g2g_research_runs: product_master_id, ai_input_tokens int unsigned, ai_output_tokens int unsigned, ai_cost_usd decimal(10,6),
  search_requests smallint unsigned, search_credits decimal(10,2)   (all NULL)
rows: signals=48 (unchanged), reviews=0
```

After (read-only diff dev vs live): the **only** differences are the new S2 columns, "only on dev": `g2g_company_opportunities` → `product_master_id, date_status`; `g2g_research_runs` → `product_master_id, ai_input_tokens, ai_output_tokens, ai_cost_usd, search_requests, search_credits`. Nothing "only on live", no type differences.

## Tests (verified)

`SignalReviewSchemaTest` (8) and `DateStatusTest` (14 cases incl. two added after my own test caught `Carbon::parse('next week')` accepting relative words): 22 tests, 116 assertions, green. Three mutations of the migrations (down() not dropping indexes; runs `up()` without the column guard; opportunities `up()` without the index guard) were each caught by a named test, files restored and byte-compared.

## Corrections to earlier statements

- **F-S2-1 wording:** the two migration files exist on `origin/main` and `origin/harshit`; they were missing only from the local clones.
- **Name:** `App\Domain\Signals\Run\RunAccounting` already exists (stage counts: queries, pages, candidates). The cost class is `RunCost`.

## Not verified

Nothing of this has run on live. The live `migrations` table was not re-read after the 3 Nov check; the live rollout compare (task 14) repeats it.
