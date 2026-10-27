# Daily-yield measurement plan (read-only, prepared, not run)

No historical result is stated anywhere in this repository. The numbers come only from running the command below on an environment you have approved for measurement.

## What is measured
Per day (the application's timezone, `SIGNALS_TIMEZONE`) and per product, for one organisation:

| Measure | Source | Notes |
|---|---|---|
| Runs started / completed / partial / failed / skipped | `g2g_research_runs.status` by `report_date`, `product_key` | failed and skipped are never "completed" |
| Run completion (completed / completed_no_qualified / incomplete) | `stage_stats.completion` | needs the `stage_stats` column; absent runs are reported as "without stage stats" |
| Qualified (as reported) and re-observed | `stage_stats.outcome` (new, updated, unchanged_existing) | re-observations are shown separately and never count toward the target |
| **Unique new qualified signals** | `g2g_company_opportunities.first_discovered_at` (day), `business_fit` -> product, samples excluded | the only number compared with the target |
| Rejection reasons | `stage_stats.zero_reasons`, `stage_stats.rejection_detail` | counts only |
| Failures and limits | `stage_stats.ai`, `queries.failed`, `pages.failed_by_category`, `pages.robots_disallowed`, `limits` | |
| Evidence quality of the unique new signals | `assessment.evidence_class`, `claim_level` | |
| Source type / region / trigger yield | not in the report yet | needs `assessment.source_reliability`, `location` and `trigger_type` grouped; add only when asked |

## How to run (approved isolated or reporting database only)
```
php artisan signals:yield-report --tenant=<id> --from=2026-10-20 --to=2026-10-27 --target=10
php artisan signals:yield-report --tenant=<id> --from=... --to=... --product=scholarclone --json
```
The command issues SELECTs only (a test asserts that it contains no write, no provider call, no queue dispatch). It prints one line per day and product: unique new / target, MET or not met, run counts, re-observed, and the biggest recorded reason for a shortfall. A figure no run recorded is shown as unrecorded, never as zero.

## Reading the result
- "MET" means at least `--target` unique new signals were first stored that day for that product. It says nothing about quality: read the evidence mix too.
- A shortfall line names the recorded cause: no run, no run completed, run(s) did not finish (AI failure, time or page limit, failed searches), mostly already known, a rejection reason, or "no run recorded a specific reason".
- Do not average a few days and call it a guarantee: the target depends on how much new public evidence exists for that product and region.

## Report format (what to hand back)
`date | product | runs ok/partial/failed | unique new / target | MET? | re-observed | top rejection reasons | AI/query/page failures | evidence mix | shortfall`
