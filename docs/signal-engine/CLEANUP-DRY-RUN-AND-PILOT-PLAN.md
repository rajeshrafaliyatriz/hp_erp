# Cleanup dry run, fixture verification and dev pilot plan

Date: 2026-11-21. Read-only work only. **No SQL below has been executed. No row was changed, marked, merged or deleted.** Live was read through the read-only harness (SELECT/SHOW only, session READ ONLY). Credentials never printed.
Tags: **measured** (queried), **inferred** (reasoned from stored data), **not checked**.

## 0. Two corrections to what I told you earlier
1. **"22 internal notes" was wrong.** The 22 tenant-6 rows with `import_source IS NULL` are two groups: **16 ingestion-derived data-quality notes** (`signal_kind='public_reporting'`, `ingestion_source_id` 6 to 9, `research_run_id=0`, ids 1-5, 19-24, 26-30) and **6 early research results** (`signal_kind` opportunity/insight, research runs 4 and 9, no source URL, no import label: ids 6, 7, 8, 9, 12, 13). Only the 16 are notes.
2. **My earlier "hpbrain" dependent counts were invalid.** `hpbrain_cases.signal_id` is a UUID (`varchar(36)`); I compared it to integer ids and MySQL cast the UUID's leading digits to a number, producing false matches. **There is no demonstrated link from `hpbrain_*` to these rows.** All other dependent counts below are exact-id joins.

## A. Fixture verification (live, ids 47-62, all tenant 6, `import_source='demo_fixture_oct13_14_2026'`, `is_sample=0`)
**Headline (measured):** these are not synthetic. Every one has a research run (43-55), a stored research source row for its URL, and pipeline events (`created`, `evidence_downgraded`, some `corroborated`/`date_corrected`). **All opportunities produced by runs 43 to 55 (16 rows) carry the label, with no exception, and runs 43-55 span exactly 13-14 Oct, the batch dates.** That pattern fits "real results from 13 research runs, labelled afterwards", not "typed-in demo records". Nothing stored says who applied the label or why. **I could not establish that any of the 16 is test data.** Source validity below is from stored fetch results; I made **no new web requests** (not checked live).

| ID | Company | Source (host) | Evidence stored | Verdict |
|---|---|---|---|---|
| 47 | Shree Swaminarayan Gurukul | instagram.com/popular/... | snippet only, page not read, 1 secondary source | **Uncertain**. The URL path `/popular/` is not a normal Instagram permalink; unverified |
| 48 | Eklavya Global School | edujobsindia.com job ad | snippet only; same URL in 4 runs (44, 46, 47, 49) | Genuine pipeline output, weak evidence |
| 49 | New Era Senior Secondary | tribuneindia.com | page read, published 2026-10-05, 2 independent domains, inference | **Genuine, moderate** (E2-type) |
| **50** | Phoenix International School | saras.cbse.gov.in (CBSE) | page read in 2 runs, primary source, `confirmed`, review `follow_up` | **Genuine, strong** (E3-type). Note: this host now refuses our bot at robots.txt, so it cannot be re-read today |
| 51 | Anand Niketan Bhadaj | instagram.com/p/... | snippet only | Genuine output, weak evidence |
| 52 | Anand Niketan Maninagar | in.linkedin.com/company | page read, single secondary | Genuine, weak |
| 53 | Hillwoods School Gandhinagar | getmereferred.com job ad | snippet only (host refuses our bot) | Genuine output, weak evidence |
| 54 | Sant Sai English Medium | schooljobsinindia.com | page read, single secondary | Genuine, weak |
| **55** | Narayana E-Techno School | saras.cbse.gov.in | page read in 2 runs, primary, `confirmed` | **Genuine, strong** (same caveat as 50) |
| 56 | Rukmani Birla Modern High | schooljobsinindia.com | page read, single secondary | Genuine, weak |
| 57 | Sarvajanik Vidyalaya Daman | freejobalert.com | snippet only; closing date 15 Oct 2026 has passed | Genuine output, weak and likely expired |
| 58 | Fountainhead Schools | linkedin.com/posts | page read, dated 2026-02-16 (structured data), reviewed by user 28 and `dismissed` | Genuine; **already dismissed by a person** |
| 59 | Tapovan Vidya Vihar | shine.com job ad | page read, dated 2026-09-26, reviewed by user 28, `follow_up` | Genuine, weak |
| 60 | The Radiant World School | in.linkedin.com/company | page read, dated 2026-10-13 | Genuine, weak |
| **61** | Podar Education Complex | podar.org (home page) | page read in 2 runs, primary, `confirmed` | **Genuine, strong**, but a home page is a thin citation for "new schools" |
| **62** | Podar Education Complex | podar.org/newschool20272028.html | page read, primary, `confirmed`, more specific than 61 | **Genuine, strong** |

**Counts (measured):** verdict "genuine" 15, "uncertain" 1 (47), "fixture" 0. Evidence: strong 4 (50, 55, 61, 62), moderate 1 (49), weak 11.
**IDs 50, 55, 61, 62** are four of the live tenant's few primary-source, page-read, `confirmed` records. They are also 4 of the 8 tenant-6 "New Opportunity" labels. If the batch label is wrongly treated as "fixture" and excluded, live loses half its confirmed signals.
**Limitation:** rows 47-57 have an empty `assessment` JSON and no stored verbatim quote, so "quote verified" in the evidence note cannot be re-checked offline. 50, 55, 61 and 62 do have `confirmed_facts`.
**Open question for you (cannot be answered from data):** who applied `demo_fixture_oct13_14_2026` and why? Possibly "records shown in a demo". If so they are real, and the label is only misleading.

### Related records of the 16 (measured)
| Table | Rows |
|---|---|
| `g2g_opportunity_events` | 38 |
| `g2g_signal_reviews` | 3 (ids 50, 58, 59; all `via=backfill`; 58 and 59 decided by user 28) |
| `g2g_opportunity_matches` (type `opportunity`) | 11 (ids 47, 48, 49, 50, 51, 55: 1+1+6+1+1+1) |
| `crm_leads` | 0 |
| `g2g_companies` (all used only by these rows; 2 have an alias each) | 15 (49 and 61/62 share company 49) |
| `g2g_research_sources` for runs 43-55 | 270 rows (19 cited). **Shared by the whole run, not owned by the signal.** |

## B. The 22 unlabelled rows: classification plan (nothing moved)
There is **already a marker for the 16 notes**: `signal_kind='public_reporting'`. No data change is needed to exclude them from market-signal reporting.
| Group | Rows | Rule (read-only) | Dependents |
|---|---|---|---|
| B1. Data-quality notes from ingested files | 16 (ids 1-5, 19-24, 26-30) | `import_source IS NULL AND signal_kind='public_reporting'` | none (0 events, 0 reviews, 0 matches, 0 leads) |
| B2. Legacy research results without URL | 6 (ids 6, 7, 8, 9, 12, 13) | `import_source IS NULL AND signal_kind<>'public_reporting'` | none |

**Plan, least invasive first (needs your choice; none executed):**
1. **No-write (recommended now):** exclude by the rules above in every report. Zero risk, fully reversible.
2. **Label-only update (later, with approval):** set `import_source` to `legacy:ingestion-note` (B1) and `legacy:early-research-no-url` (B2). Reversible by restoring the NULL (original values recorded in the backup).
3. **Schema change (only if you want a real column, separate approval):** add `record_class`. Not proposed now.
Preserved in every option: id, original text, timestamps, company link. B2 should **not** be treated as notes: they are real-looking market items that simply have no source URL.

## C. Cleanup dry run
Nothing below runs without your approval. Scenario S1 is my recommendation.

### C1. Exact rows
| Set | DB | IDs | Rows |
|---|---|---|---|
| Fixtures (batch label) | live | 47-62 | 16 |
| Localhost-source | dev | 32-37 (`http://127.0.0.1:8000/api/signals/ingestion/sources/10`, import `ingestion:ScholarClone_School_Networks_sanitized.txt`, ingestion source 10 exists) | 6 |
| Notes B1 | live | 1,2,3,4,5,19,20,21,22,23,24,26,27,28,29,30 | 16 |
| Legacy B2 | live | 6,7,8,9,12,13 | 6 |
| Duplicate-URL groups | live | see C5 | 7 groups, 14 rows |

### C2. Scenarios for the 16 fixtures
Each statement is guarded by `import_source` and an exact id list, and must report the expected row count before commit.
```sql
-- S1 (recommended, only if you confirm they are genuine): correct the label. Expected: 16 rows.
UPDATE g2g_company_opportunities
   SET import_source = 'web-research'
 WHERE sub_institute_id = 6
   AND import_source = 'demo_fixture_oct13_14_2026'
   AND id BETWEEN 47 AND 62;
-- S2 (only if you confirm they are test data): mark as sample so views exclude them. Expected: 16 rows.
UPDATE g2g_company_opportunities
   SET is_sample = 1
 WHERE sub_institute_id = 6
   AND import_source = 'demo_fixture_oct13_14_2026'
   AND id BETWEEN 47 AND 62;
-- S3 (hard delete, only on explicit instruction). Order: dependents first. Expected: events 38, reviews 3, matches 11, opportunities 16.
DELETE FROM g2g_opportunity_events  WHERE opportunity_id BETWEEN 47 AND 62;
DELETE FROM g2g_signal_reviews      WHERE opportunity_id BETWEEN 47 AND 62;
DELETE FROM g2g_opportunity_matches WHERE signal_type = 'opportunity' AND signal_id BETWEEN 47 AND 62;
DELETE FROM g2g_company_opportunities
 WHERE sub_institute_id = 6 AND import_source = 'demo_fixture_oct13_14_2026' AND id BETWEEN 47 AND 62;
-- S3 leaves 15 companies (ids 35-49) and 270 research_sources rows from runs 43-55 behind. Do NOT delete the sources: they belong to the runs.
```
**Dev 32-37 (recommended: change nothing and exclude by rule).** If you want the URL cleared: `UPDATE g2g_company_opportunities SET source_url=NULL WHERE id BETWEEN 32 AND 37 AND source_url LIKE 'http://127.0.0.1%'` (expected 6). If you want them removed: events 12 and matches 6 first, then the 6 rows (companies 30-35 are used by no one else).

### C3. Expected before/after (live, S1 / S2 / S3)
| Measure | Before | S1 | S2 | S3 |
|---|---|---|---|---|
| `g2g_company_opportunities` rows | 140 | 140 | 140 | 124 |
| rows with `is_sample=1` | 0 | 0 | 16 | 0 |
| web-research rows, tenant 6 | 77 | 93 | 77 | 77 |
| events / reviews / matches (fixtures) | 38 / 3 / 11 | same | same | 0 / 0 / 0 |
| tenant-6 "New Opportunity" labels in metrics | 8 | 8 | 4 | 4 |

### C4. Backup and export plan (before any of the above)
1. Use `mysqldump --single-transaction --no-create-info --where=...` (read-only on the source) or a SELECT-to-file export from the harness, for: `g2g_company_opportunities` ids above; `g2g_opportunity_events`, `g2g_signal_reviews`, `g2g_opportunity_matches` for those ids; `g2g_companies` and `g2g_company_aliases` for the 15 company ids; `g2g_research_sources` for runs 43-55 (reference only). Keep a row-count manifest per file and a SHA-256 of each file.
2. Store in `g2g-backups/` under a dated folder, never inside the app tree. Do a full-table snapshot of `g2g_company_opportunities` as well (140 rows, small).
3. Confirm the backup restores into a scratch copy on **dev** before touching live.

### C5. Rollback
- **Updates (S1, S2, B2 labels):** the backup holds the previous value; rollback is `UPDATE ... SET import_source = <old> / is_sample = 0 WHERE id IN (...)` using the manifest. Verified by counting rows.
- **Deletes (S3):** re-insert from the export with original ids (explicit id column) in the reverse order: companies, opportunities, then events, reviews, matches. Ids are not reused unless the table is truncated.
- Run each scenario inside one transaction with an expected-count check; roll back on any mismatch. **MariaDB 10.1 on live is the version to test on, not 10.11 on dev.**

### C6. Relationship risks (measured)
- **No database foreign keys exist** on these tables, so nothing cascades and nothing prevents orphans. Every dependent must be removed by hand.
- Logical dependents: events (`opportunity_id`), reviews (`opportunity_id`), matches (`signal_id` + `signal_type`), `crm_leads.source_company_opportunity_id`, `crm_*opportunity*` tables, `task_management_projects.crm_opportunity_id`. Only events, reviews and matches have rows for these ids.
- `g2g_opportunity_matches.signal_id` is shared by types `opportunity` (312) and `ingestion_finding` (2, signal ids 139 and 142). Always filter on `signal_type`, or an id could hit the wrong table.
- **The 3 reviews on 50, 58, 59 are human decisions** (58 and 59 by user 28); deleting them loses audit history.
- `hpbrain_*` links are **not established** (see correction 2).
- **Run-level data:** runs 43-55 hold 270 sources, shared; the run counters (`opportunities_qualified` etc.) would stay unchanged after any deletion.

### C7. Duplicate-URL groups (live, tenant 6, 7 groups, 14 rows; nothing changed)
| URL | Rows | Assessment |
|---|---|---|
| bankofbaroda.bank.in tender | 108, 109 (both manual, same company BOB, minutes apart) | **True duplicate** |
| kpmg.com press release | 105 (run 88), 146 (run 150), same company | **True duplicate** (repeat detection across runs) |
| instagram.com/p/DbLXTLqCEWt | 122 (CACTUS), 163 (Cactus Communications) | **Likely duplicate** (company name variants) |
| mahapreit.in tender PDF | 112, 119 (two company names for one entity) | **Likely duplicate** |
| builtinpune.in | 136 Nihilent, 137 Exusia | Not duplicates: one article names two companies |
| govinsider.asia | 130 SC Cyber, 131 Ngee Ann | Not duplicates |
| linkedin.com/pulse | 154 SLA, 155 URA | Not duplicates |
**Lesson for dedupe:** one URL can legitimately support several companies. A duplicate rule must be company + URL (or event), not URL alone. Only 4 of the 7 groups (8 rows) look like real duplicates.

### C8. Revised cohort definitions (supersedes my earlier proposal)
- **Cohort C (reference benchmark):** tenant 6, `import_source='web-research'`, has URL = **77** rows. Unchanged.
- **Cohort C+ (if you confirm the 16 are genuine):** C plus ids 47-62 = **93** rows. All are web-research output.
- **Excluded from market metrics:** B1 notes (16). B2 legacy (6) reported separately as "no source URL". Tenants 0 and 1 (11) separate.
- Every report states the cohort name and its denominator.

## D. Full dev research pilot: plan and cost estimate (NOT STARTED)
### D1. What exists
- Dev runs use **Tavily** search and **DeepSeek** (`deepseek-chat`) from the existing dev configuration. The work copy's `.env` has no production connection settings.
- **The researcher always writes**: `ResearchRun`, sources, opportunities, events and `ai_usage_events`. **There is no dry-run mode.** "No database writes unless approved" therefore cannot be met by simply running it. Options are in D4.

### D2. Cost estimate (measured on live and dev run history; no new calls made)
- Dev average run: 4.8 queries, 21.7 sources, 56 s. Live average (same pipeline, `deepseek-flash`): 10.3 queries, 9.9 search requests, 35 sources, 2.1 AI calls, **22,842 input and 1,086 output tokens, about INR 0.78 per run (max INR 1.43)** over 75 runs with a recorded cost.
- Dev's `ai_usage_events` have **no price set**, so dev AI cost is recorded as unpriced (113 `deepseek-chat` calls, 988k input tokens, cost NULL). I would need to **price DeepSeek at the live rate** (about INR 0.78 per run) to report an actual cost.
- Search: runs do not record Tavily credits (NULL). Estimate **about 10 search requests per run**; at Tavily's basic price the cost depends on your plan. **I have not verified your per-credit price. Please confirm.** At a typical pay-as-you-go rate of about USD 0.008 per credit (not verified), 10 requests is about USD 0.08 per run.
- Evidence-depth adds **no provider calls**: cited-page fetches are public page requests (cap 6 per run).

| Item | Per run | 6 runs (proposed) |
|---|---|---|
| Search requests | about 10 (max 14) | about 60 (cap 84) |
| AI calls | about 2 to 3 | about 18 (cap 30) |
| AI tokens | about 23k in / 1.1k out | about 140k in / 7k out (cap 300k in) |
| AI cost (at live rate) | about INR 0.8 | about INR 5 (cap INR 15) |
| Search cost (assumed rate) | about USD 0.08 | about USD 0.50 (cap USD 0.70) |
| Page requests | up to 12 + 6 cited | up to 108 |
| **Total estimate** | | **about USD 0.55; hard cap USD 1.00 (about INR 85)** |

### D3. Proposed limits (hard)
- Dev only; tenant 6; existing dev ICP and product config; flag `SIGNALS_EVIDENCE_DEPTH=true` set only in the process environment for the run, never in a committed file. Production stays off.
- **6 runs maximum.** Per run: `max_queries` 14, `max_sources` 50, `max_opportunities` 10, `cited_page_budget` 6, `fetch_pages` 12, `run_budget_seconds` 600.
- **Hard stop** when cumulative search requests exceed 84, AI calls exceed 30, AI input tokens exceed 300,000, or elapsed cost exceeds USD 1.00. Enforced by the harness before each run and by the existing `RunBudget` within a run.
- Robots and SSRF rules unchanged. No emails, messages, outreach or scheduler.
- **No live database connection at all** (work copy's `.env` has none; the script refuses to start if one is reachable).
- Measure: fetch success, cited-page read rate, E0-E3 distribution, quote verification, host-specific failures, provider calls, actual cost.
- **No before/after claim.** Cohort C (77 live rows, 70.1% E0, 24.7% E1, 5.2% E3) is a reference. Different tenant data, dates and queries make a direct comparison invalid; I would only compare like-for-like by running the same run twice (flag off, flag on) on the same date, and say so.

### D4. The write question (decision needed)
| Option | What it means |
|---|---|
| **P1 (recommended)** | Allow writes to the **dev** database only (the researcher's normal rows: run, sources, opportunities, usage events) for these 6 runs, tagged `product_key` and a run label so they are easy to find. Dev data are test data; 6 rows of 84 runs. |
| P2 | Build a no-persist harness that stubs the repositories and returns the candidates and diagnostics in a file. About a day of work, with risk of drift from real behaviour. |
| P3 | Run inside a dev transaction and roll back. Still a write while it runs, locks tables, and ai_usage_events would also be rolled back, losing the cost record. Not recommended. |

## E. What I need from you before anything runs
1. Confirm the fixtures: genuine (S1) or test data (S2), and who applied the label.
2. Choose B plan option 1, 2 or 3 for the 22 rows.
3. Approve, change or reject the pilot limits, plus your Tavily per-credit price and whether to price DeepSeek at the live rate.
4. Choose P1, P2 or P3.
Then I execute nothing until you say go.
