# Evidence depth: replay, pilot, change report and recommendation

Date: 2026-11-21. Work copy: `g2g-backups/work-evidence-depth` (own Git history). Original `g2gb` untouched since the work copy was made. Nothing merged, deployed or migrated. Evidence-depth flag is OFF by default and was never switched on anywhere except in the unit tests.
Tags: **measured**, **verified-code**, **limitation**.

## 1. Offline replay (stored fields only; no network; read-only)
Production data, 124 analysed signals (16 demo fixtures excluded), maturity derived with `EvidenceMaturity::fromStored`.

| Level | Signals | Meaning |
|---|---|---|
| E0 | 63 | snippet only |
| E1 | 20 | page read, not corroborated |
| E2 | 2 | corroborated (both from one registry import) |
| E3 | 7 | primary source confirmed |
| not assessed | 32 | imports and manual entries, never graded |

Web-research pipeline (85): E0 54, E1 20, E3 4, not assessed 7. Qualified label by level: E0 0 of 54, E1 0 of 20, E3 4 of 4. **E1 and E0 were both "Monitoring" in the old view; they are now distinguishable.** The 54 E0 signals sit on: social 20, web 19, government 10, pdf 4, job board 1.
Sources (4,674): web pages read 48%, government 17%, social 7%, job boards 8%, PDFs 0% (never attempted).

## 2. Pilot (development database only)
- **Database:** dev host `202.47.117.220`, database `hp_erp`, **tenant 6**. The work copy's `.env` has no production connection settings (`db3` and `live` hosts are empty), and the script refuses to run unless only the dev host is reachable. Session was `READ ONLY`. **No production access of any kind; no database writes at all** (results went to a JSON file).
- **No outreach, scheduler, search provider or AI call.** Only public page requests, robots.txt honoured, SSRF protections and host rate limits of the production fetcher, 1.5 s between candidates.
- **Candidates:** all 40 ICP-fit signals with a cited URL in dev tenant 6. One real request per candidate; strategies A (today) and B (flag: PDFs allowed) replayed on the same response.
- **Not requested (platform terms or pipeline policy):** linkedin 5, facebook/instagram 4.

### Results
| Measure | Result |
|---|---|
| Page-read rate, all 40 | 27.5% (11) |
| Excluding social (not requested) = 31 eligible | **35.5%** |
| Also excluding 6 dev rows that cite `127.0.0.1` (test fixtures) = 25 | 44.0% |
| Also excluding hosts whose robots.txt forbids our bot (10 refusals) = 15 | **73.3%** (11 of 15) |
| Strategy B vs A | identical: **no PDF was ever reached** (every government PDF host refused us at robots.txt first) |
| E-level stored, dev | E0 16, E1 16, E3 8 |
| E-level after re-fetch | E1 11, E0 29. Of the 16 stored E0, **5 (31%)** rose to E1; 4 are social, 4 robots, 2 HTTP 403, 1 no usable text |
| Quote verification | **0 of 4** candidates with a stored quote verified (their pages were robots-blocked). The other 36 have no stored verbatim quote, so nobody can be promoted above E1 here |
| Qualification re-derived | all 40 stay hypothesis; the old E3s fall to E0/E1 because their pages could not be re-read and no quote is stored. **This is a re-verification limit, not a regression** |
| Cost | provider cost **0** (no search, no AI, no tokens); 12 page downloads, 2.1 MB; median 0.46 s, p90 6.0 s, total 70.8 s |
| Zero-signal runs (dev history, not changed by this pilot) | 59 of 80 success runs (74%) |
| Human review agreement and reason codes | **not run**: needs reviewers; no review sheet was produced |

### Fetch outcomes by source type (strategy B)
| Type | n | read | other outcomes |
|---|---|---|---|
| web | 23 | 10 | robots 9 (6 are the `127.0.0.1` fixtures), HTTP 403 2, timeout 1, no usable text 1 |
| government | 7 | 1 | **robots 6** (saras.cbse.gov.in x4, tenderdetail.com x2) |
| job board | 1 | 0 | robots 1 (shine.com) |
| social | 9 | 0 | skipped by pipeline 4, not requested (terms) 5 |

### Exceptions: where page reading is not feasible
- **Robots.txt refusals (10, genuine):** tripurastarnews.com, hcltech.com, saras.cbse.gov.in, getmereferred.com, shine.com, tenderdetail.com. We honour these; a fetch change cannot fix them.
- **Social platforms (9):** login walls or terms.
- **HTTP 403 (2):** deccanchronicle.com, edujobsindia.com (bot blocking).
- **Dev data quality:** 6 candidates cite `127.0.0.1`; fixtures stored as real signals (the same problem as the 16 production demo fixtures).

## 3. What changed (file by file; `git diff 11c7350 HEAD`)
| File | Change |
|---|---|
| `app/Domain/Signals/Market/EvidenceMaturity.php` | NEW. Pure E0 to E3 derivation, reasons, missing steps, contradictions (cap at E1), `fromStored()` for history. Reasoning threshold matches `EvidenceGrader` (15 characters). |
| `app/Domain/Signals/Market/SourceIndependence.php` | NEW. Registrable-domain grouping, syndicated-copy detection, source type. |
| `app/Domain/Signals/Market/EvidenceItems.php` | NEW. Traceable evidence items (URL, type, domain, dates, fetch status and failure, passage only if the page was read, claim, maturity, reliability, independence). |
| `app/Domain/Signals/Sources/FetchRanker.php` | NEW. Orders first-pass reads (flag on only). |
| `app/Domain/Signals/Opportunities/OpportunityResearcher.php` | MODIFIED. (1) `assessment['evidence_maturity']` and `['evidence_items']` annotations. (2) Per-source `fetch_status`/`fetch_failure` and per-host outcome counts (`noteFetch`), always on, diagnostics only. (3) Flag on: ranked first pass, PDF reading (`pdfText`), `fetchCitedPages()` between the model's answer and quote verification. With the flag off the fetch calls, order and budget are unchanged. |
| `app/Domain/Signals/Run/RunAccounting.php` | MODIFIED. `fetch_by_host` and `cited_pages` added to `stage_stats.pages` only when recorded. |
| `config/signals.php` | MODIFIED. `evidence_depth` block, `enabled` default false. |
| `phpunit.xml`, `tests/bootstrap-workcopy.php` | Work-copy only: tests load this copy's own code (the shared `vendor/` junction otherwise loads the original app). Do not carry into `g2gb`. |
| `scripts/evidence-pilot.php` | NEW. The dev-only pilot. |
| `tests/Unit/Signals/EvidenceMaturityTest.php` | NEW, 28 tests: levels, guardrails, failure statuses, grader agreement, independence, items. |
| `tests/Unit/Signals/EvidenceDepthFetchTest.php` | NEW, 18 tests: ranking, flag off and on, PDF, each failure category by host, robots, social, host cap, cited-page fetch and budget. |

**No migration, no change to claim_level, qualification, confidence, priority or fit.**

## 4. Test status
- Signals unit suite, work copy: **335 tests**, 3 failures, all pre-existing.
- Researcher feature tests (10 files): identical results on the clean baseline and the work copy.
- **Pre-existing failures (documented separately, none caused by this work; each reproduces on the unmodified original code):**
  1. `SocialDataConnectorAdapterTest::test_youtube_reads_only_the_authorized_channel_uploads_and_uses_video_publication_time` (`Undefined array key "id"`)
  2. `SourceQualityTest::test_gujarat_aimed_queries_name_gujarat_cities_and_no_longer_say_maharashtra`
  3. `VerifyIsolationAndAccountingTest::test_source_text_no_other_http_call_site_exists_in_the_research_path`
  4. `ChildProductDiscoveryTest::test_a_sub_product_run_that_is_not_ready_stores_nothing_and_says_why` (feature, found now)
  5. `ResearchRunProgressTest::test_looking_at_a_past_date_or_the_history_never_starts_research` (feature, found now)
- **Correction to my earlier report:** the earlier "baseline without my changes" run had loaded the original `g2gb` code through the shared `vendor` junction, so it was not isolated. I fixed the autoload and re-ran; failures 1 to 3 reproduce on genuinely unmodified code.

## 5. Recommendation: should qualification change?
**Not yet. Keep the current rule.**
1. The label is already E3-only: only a verified primary-source page produces "New Opportunity / Relevant Requirement Found". E0 and E1 cannot qualify today. Making "E2/E3 required" would change nothing observable.
2. The pilot cannot test the part that would change behaviour. Quote verification worked for 0 of 4 candidates, and 36 have no stored quote. A rule on corroboration needs a run that produces quotes (a full run with search and AI, which costs provider credits and was not approved).
3. The binding constraint is access, not the rule: 10 of 25 eligible candidates refuse our bot, and no strategy can change that.
4. The stricter rule you described (trigger, fit and recorded need) belongs in the gate, but it needs candidates graded with maturity first.

**Recommended next steps, in order:**
- Show E0/E1/E2/E3 in the review screen (annotation is already stored), and require E1 or higher before a signal is promoted to a lead. This is reversible and doesn't alter the label.
- Run one full dev research run with the flag on (needs approval for search and AI spend) to measure cited-page uplift, quote verification, and E2/E3 yield. Then decide on the qualification rule.
- For government and tender sources, use official feeds, data partnerships or manual entry, not fetch tweaks: robots refusals are a policy limit we respect.
- Remove fixtures from real signal tables (127.0.0.1 in dev, 16 demo rows in production) so they stop distorting metrics.

## 6. Limits
- The pilot re-reads cited pages; it does not run search or the model, so it measures fetch feasibility and maturity, not new candidate yield or the model's quote quality.
- Strategy B only differs from A for PDFs, and none were reachable; the PDF path is verified by unit test, not by a real PDF.
- `FetchRanker` ordering and the cited-page step are verified by tests, not by a full pipeline run.
- Strategy A is replayed from B's single response (a PDF means "non_html"), not fetched twice.
- 40 candidates in one tenant; per-type counts are small.
- Human review agreement is pending.
