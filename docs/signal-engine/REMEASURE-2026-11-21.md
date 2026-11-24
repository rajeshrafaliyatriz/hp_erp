# Read-only re-measurement of Sales & Marketing signals, 2026-11-21

Production database (`db3`, MariaDB 10.1.48). Every query was SELECT or SHOW, sent through a harness that rejects any other statement and sets `SET SESSION TRANSACTION READ ONLY` (confirmed: `@@tx_read_only = 1`). No writes, no scheduler change, no external calls. Credentials were not printed. Scripts: scratchpad `ro.php`, `m1.php` to `m5.php`.

## Scope
- Tables: `g2g_company_opportunities` (140 rows), `g2g_research_sources` (4,674), `g2g_research_runs` (154), `g2g_signal_reviews` (9).
- Signals first discovered 2026-10-02 to 2026-11-20. 129 are tenant 6, 10 are tenant 0, 1 is tenant 1.
- **Analysed: 124.** Excluded 16 rows with `import_source = demo_fixture_oct13_14_2026`, which are demo data stored with `is_sample = 0`.

## Definitions
- **Dated** = `event_date` or `source_published_at` is not null. **Event date stated** = `event_date` not null.
- **Age** = 2026-11-21 minus that date. **Expired** = `expires_at` in the past (only 67 signals have one).
- **"Qualified label"** = `qualification` in (Relevant Requirement Found, New Opportunity). This is a proxy. There are no meeting or deal outcomes in the data.
- **Page read** = `fetch_level = page_text`. **Confirmed** = `claim_level = confirmed`.
- Fit = `assessment.fit.total` and `.band` (exists for 63 signals only).
- Source class from the URL host: social (linkedin, instagram, facebook, twitter, youtube), gov/tender, jobs, other web.

## Measured
| Measure | Result |
|---|---|
| Any date | 50 of 124 (40%); event date stated 24 (19%); no date 74 (60%) |
| Same-cohort comparison with baseline (first discovered before 2 Nov, n=68) | no event or source date 67.6%; `date_status = undated` 52.9%. The baseline's "75% no date" definition was not recorded, so it is not strictly comparable |
| Age of dated signals (n=50) | median 48 days, p90 75, max 197; 15 are 30 days old or less |
| Expiry | 60 active, 7 expired, 57 have no expiry set |
| Duplicates | 0 repeated stored fingerprints; 2 company+trigger groups (4 signals); 98 distinct companies in 124 |
| Product fit | `business_fit` null for 32 (26%); fit band High 3, Medium 55, Low 5, none 61; fit total median 49 (39 to 68) |
| Evidence | claim: hypothesis 77, inference 8, confirmed 7, none 32. Fetch: snippet only 63, page read 29, none 32 |
| Evidence class | strong 14, weak 31, needs further research 25, none 54 |
| Confidence | Low 79, High 23, Medium 12, blank 10 |
| Actionability | recommended action present 87%; named stakeholder only 16% |
| Priority | High or Medium 39.5% |
| Review | 2.4% of analysed signals reviewed; `g2g_signal_reviews`: 4 promoted, 4 follow-up, 1 dismissed; no reason code recorded in any row |
| Runs | 154: success 124, failed 19, skipped 9, partial 2. **99 of 154 runs (64%) produced zero signals** |

### Controlled comparison: web-research pipeline only (n=85)
The unfiltered view showed undated signals looking better (28% vs 6% "qualified"). That was a confound: 22 signals with no `import_source` and 32 with no URL are a different population (imported, no page read). Within the web-research pipeline:

| Cohort | n | qualified label | page read |
|---|---|---|---|
| dated | 38 | 5.3% | 50% |
| undated | 47 | 4.3% | 10.6% |
| page read | 24 | 16.7% | 100% |
| snippet only | 54 | **0%** | 0% |
| gov/tender source | 11 | 9.1% | 9.1% |
| other web | 44 | 6.8% | 47.7% |
| social | 23 | **0%** | 8.7% |

All 4 qualified signals in this pipeline were page-read and confirmed. Dated vs undated barely differs (2 of 38 vs 2 of 47).

### Source level (4,674 retrieved sources)
| Class | n | page fetched | has published date |
|---|---|---|---|
| other web | 3,166 | 42.8% | 49.9% |
| social | 1,017 (22%) | 7.7% | 7.1% |
| gov/tender | 371 | 10.2% | 15.6% |
| jobs | 120 | 37.5% | 36.7% |

Overall 32% fetched; 2.2% cited.

## Not measured, and limitations
- No outcome data (meetings, opportunities, revenue), so "useful" is a proxy built from the system's own labels.
- Only 4 positives in the controlled cohort; differences of a few points are noise.
- Priority is driven by date in the scoring (dated 68% High or Medium vs undated 4% within web-research), so that gap reflects the scoring rule, not signal quality.
- Whether a page read is *required* for the qualified label (a rule) or merely correlated is **inferred, not verified in code**.
- Why gov/tender and social pages are rarely fetched (robots, login walls, blocking) is **not measured**.
- Dev database not measured. Single snapshot, mostly one tenant.
- `SchoolTriggers` detected a trigger in only 34 of 124 signals' stored text, mostly tenders; it has not been validated against outcomes.

## Conclusion (evidence-based)
The data does **not** show that missing dates are the biggest problem. Dates drive priority ordering but did not change the quality label. The largest measured gap is evidence depth: 54 of 85 pipeline signals rest on a search snippet and none of those got past "hypothesis".

## Failing tests (pre-existing)
The same 3 fail on a baseline copy without my changes (282 tests): the YouTube connector test (`Undefined array key "id"`), the Gujarat query-wording test (the generated query lacks "Gujarat" or Gandhinagar), and the HTTP call-site whitelist test (it expects 3 files, but 5 new social connector files now make HTTP calls).
