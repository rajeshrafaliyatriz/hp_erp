# Evidence depth: root-cause analysis, proposed changes, test plan, pilot plan

Date: 2026-11-21. Status: **analysis and proposal only. No code, schema or data was changed in this step.**
Tags: **verified-code** = read in source this session. **measured** = read-only SELECT on production (`db3`). **hypothesis** = plausible, not measured.
Read-only data sources: `g2g_company_opportunities`, `g2g_research_sources` (4,674 rows), `g2g_research_runs.stage_stats` (88 of 154 runs carry it; the other 66 pre-date the diagnostics).

## 1. The lifecycle, as the code actually runs it

`OpportunityResearcher::run` (verified-code):

1. **Search** (Tavily/Brave, registry sources, name searches). Results are kept or dropped by age and screening. Measured: 4,444 returned, 955 dropped as too old, 305 screened out, 3,082 kept.
2. **`enrich()`** (line 467) reads page text for sources **in search-result order**, up to a cap (`signals.opportunities.fetch_pages`, config default 12). This happens **before** the model has chosen anything.
3. **`followDiscoveredLinks()`** follows up to 2 links from discovered listing pages.
4. **Model extraction** returns candidate opportunities, each with a quote and a cited source.
5. **`validate()`** checks the quote against the source text, the school's name, region, staleness, product fit, and exclusions.
6. **`QualificationGate`** applies the intent floor, the tender-expiry rule, the card-completeness rules and the fit floor (`min_fit` 25). **`EvidenceGrader`** derives `claim_level`.
7. **`MarketImporter`** writes the row. `qualification` and `confidence` are looked up from `claim_level`.

**There is no step that fetches the page a candidate cites.** If the model cites a source that was not among the first N results, the signal stays on a snippet.

## 2. What makes a signal "qualified" (verified-code)

- `MarketImporter::qualificationFor`: `confirmed` gives "Relevant Requirement Found" (tender types) or "New Opportunity"; `inference` gives "Needs Verification"; `hypothesis` gives "Monitoring".
- `confidence` is also a lookup from `claim_level` (confirmed High, inference Medium, hypothesis Low). **So label and confidence are the same variable, not two measurements.** My earlier "confidence High = 100% qualified" was circular.
- `EvidenceGrader::grade`:
  - `quote_verified` is false means **hypothesis**. In the researcher, `quote_verified` is set equal to `page_fetched`, so **a snippet-only signal cannot be better than hypothesis, by rule.**
  - **confirmed** = quote verified AND the source is **primary** (the buyer's own site, or a gov/edu domain whose name contains the buyer) AND the page was read.
  - **inference** = quote verified AND reasoning recorded AND (primary OR at least 2 independent domains).
  - everything else is hypothesis.
- So page reading is **necessary but not sufficient**. The harder bar is the **primary-source** requirement: a news article read in full can never be "confirmed".
- The fit floor and intent floor exist in the gate, but the **label itself does not read fit or "why this creates a need"**.

Consequence: guardrails C1 ("E0 must stay hypothesis") and C2 ("page reading alone must not guarantee qualification") **already hold structurally**. What is missing is that this is invisible: hypothesis hides whether the evidence was a snippet (E0) or a read page (E1).

## 3. Why only 28% of web-research signals were page-read

| Cause | Evidence | Tag |
|---|---|---|
| Fetch budget is spent on the first N search results, **before** candidates exist | cap reached in **69 of 88** runs; only **36 of 102 cited sources** were fetched (35%) | verified-code + measured |
| No fetch of the cited page after candidate selection | no such step in `run()` | verified-code |
| Social hosts skipped by design | `skip_hosts`: instagram, facebook, x, twitter, reddit, quora; 340 skips; facebook and instagram 0% fetched | verified-code + measured |
| LinkedIn is **not** skip-listed, so it is attempted | linkedin.com 284 sources, 23% fetched | verified-code + measured |
| Robots.txt disallows our bot | **306** pages blocked | measured |
| HTTP 403 (bot or login wall) | **267** failures | measured |
| Non-HTML (mostly PDFs) rejected by the fetcher | **156** failures; `enrich()` passes no `extraTypes`, only `DirectSourceReader` allows `application/pdf` | verified-code + measured |
| Timeouts and 404/429/5xx | 5 + 12 + 3 + 1 | measured |
| `page_fetched` only set when extracted text is longer than the snippet; the diagnostics count every successful request | code lines 934 vs 954 | verified-code (so "pages.fetched 1,149" overstates reads) |

Total page failures **780** against 1,149 fetched. By host class: third-party 314, official 138, job posts 18, social 4.

## 4. Source categories need different strategies

Per-host fetch rate (measured, all runs):

| Class | Examples | Fetched | Reading |
|---|---|---|---|
| School and news sites | school sites 90%+, TOI 78%, careers360 85% | high | Fetch works. The problem is the cap and ordering. |
| Government and tender | gem.gov.in, bidplus, eprocure, cbse.gov.in, pib.gov.in, ugc.gov.in: **0%**; saras.cbse.gov.in 26% | very low | Likely robots, 403 and PDFs. **Cause per host not measured** (diagnostics store class, not host). |
| Social | facebook, instagram, reddit 0% by design; linkedin 23% | low | Not feasible by plain fetch. Use only as a lead to the official site. |
| Job boards | indeed 0%, shiksha 0% | low | Login or bot walls (hypothesis). |

One strategy for all classes is wrong: widen the cap for readable classes, add PDF handling for gov/tender, and never try to read social pages.

## 5. Why 64% of runs returned zero signals

154 runs: success 124, failed 19, skipped 9, partial 2. Zero-signal: 73 of the success runs, 16 failed, 9 skipped, 1 partial.

- **25 of the 99 are not research outcomes at all** (measured): 9 skipped (5 "no active ICP for ScholarClone", 4 "a run is already in progress") and 16 failed runs.
- **Failures (19, measured):** 6 generic "Signal generation failed", **5 database error "Data too long for column `stage_message`"** (a real defect: a text field overflowing; when it happened is not measured), 3 AI provider unavailable, 1 usage limit, 1 model not available, 2 closed as stale, 1 stopped by an administrator.
- **The other 74 (73 success and 1 partial) are search-and-filter outcomes.** Per-source drop reasons (measured, summed over the 88 runs that have diagnostics; I did not split zero-signal runs by diagnostics availability): **no candidate proposed 2,007**, results too old 642, **pages unreadable 460**, off-topic 216, excluded industry 12, quote not in source 9.
- Candidate funnel: 69 proposed, 404 rejected before the gate, 2 rejected by the gate. Rejection detail: institution not named in source 40, excluded industry 32, stale 14, product fit unsupported 6 or missing 5.

The largest single drop is "no candidate proposed". Whether that is correct filtering or lost opportunities cannot be told without human review (not measured).

## 6. Proposed minimal changes (no schema change in phase 1)

Files and intent. Nothing here has been implemented.

**B. Evidence maturity**
1. NEW `app/Domain/Signals/Market/EvidenceMaturity.php` (pure class). Derives E0 to E3 from `fetch_level`, `claim_level`, `quote_verified`, corroborating-domain count, primary flag and reasoning:
   - **E0** snippet only (not page read).
   - **E1** page read and extracted, claim not yet corroborated: today's hypothesis with a read page.
   - **E2** corroborated: today's inference (reasoning plus primary or at least 2 independent domains).
   - **E3** confirmed: primary source, page read, quote verified.
   - It returns the level, the reasons, and any contradicting evidence it is given.
2. `OpportunityResearcher` (near the existing `why_now` annotation): write `assessment['evidence_maturity']`. **Annotation only; it does not change claim_level, qualification, priority or fit.** Stored in the existing JSON, so no migration.

**C. Guardrails**
3. Keep `EvidenceGrader` as the single source of truth. Add a test asserting E0 can never map to inference or confirmed.
4. Add to the label rule (a behaviour change, so needs your approval): qualification requires E2 or E3 **and** a stated trigger **and** the fit floor **and** a recorded "why this creates a need". Until approved, qualification stays as it is.

**D. Evidence item structure**
5. Phase 1 (no schema): write an `assessment['evidence_items']` list. Each item has url, source class, domain, published date, fetch status and category, fetch timestamp, extracted passage, claim supported, maturity, reliability, and `independent` (different registrable domain from the other items).
6. Phase 2 (**needs your approval, migration**): add queryable columns on `g2g_research_sources` (`fetch_status`, `fetch_failure_category`, `source_class`, `reliability`) and a nullable `evidence_maturity` on `g2g_company_opportunities`. Not run, only proposed.

**A/E. Fetch depth (behaviour change, flagged and off by default)**
7. **Fetch the cited page after candidate selection**: for each candidate whose source is snippet-only, fetch that URL once (same SSRF and robots rules, same budget accounting), re-run the quote check, and then grade.
8. **Rank before fetching**: order the first-pass reads by readable class, ICP relevance and an official domain, not search order.
9. **PDF support in `enrich()`** for gov/tender notices (reuse the `DirectSourceReader` extractor), size-capped.
10. **Per-host fetch diagnostics**: record host (capped), outcome and category in `stage_stats`, so robots vs 403 vs PDF can be told apart per host.
11. Do not attempt reads of hosts already classed unreadable (add linkedin to the skip class only after the pilot confirms it).
12. Out of scope here but logged: the `stage_message` overflow defect.

## 7. Test plan (to be written with the implementation)

Pure unit tests, no database or network, consistent with the existing suite:
- **Maturity mapping:** snippet-only gives E0 even with reasoning and 3 domains; page-read secondary single source gives E1; inference gives E2; primary page-read verified gives E3; contradictory evidence preserved and listed.
- **Guardrails:** E0 never produces confirmed or inference; E1 alone never produces a qualified label; maturity never alters priority, confidence, fit or qualification.
- **Fetch failures:** each category (robots, 403, 404, 429, 5xx, timeout, connection, non-HTML, social-skip) yields the right fetch status, never counts as a page read, and keeps the snippet.
- **Source independence:** same registrable domain or syndicated copy counts as one; two different domains count as two; subdomains of one site are not independent.
- **Cited-page fetch:** fetched at most once per candidate, honours budget and robots, a failure leaves E0 with the reason recorded.
- Keep the 3 known baseline failures documented and out of the gate (YouTube connector `Undefined array key "id"`, Gujarat query wording, HTTP call-site whitelist).

## 8. Pilot and measurement plan

Not run yet. External requests and database writes need your approval.

- **Phase 0, offline replay (no network, no writes):** recompute E0 to E3 for the existing 124 signals and 4,674 sources from stored fields. Gives the baseline E-distribution and qualification rate by level. Can run now.
- **Phase 1, controlled pilot (needs approval):** run on the **dev** database (not production), with a fixed set of 40 ICP-fit candidates (sampled across school sites, news, gov/tender, social, job boards), comparing current behaviour with the cited-page fetch, rank-before-fetch and PDF changes switched on one at a time. Public pages only, robots honoured, normal rate limits, no outreach.
- **Metrics:** page-read rate of eligible ICP-fit candidates (target at least 60%, initial operational goal, not a qualification guarantee); fetch success rate by source class; E0/E1/E2/E3 distribution; qualification rate by level; cost (provider calls, tokens) and wall time per candidate; zero-signal run rate with the 25 non-research runs separated out; reviewer agreement and reason codes on a blind sample of 30.
- **Eligible** = ICP-fit candidate on a host class that is readable by policy. **Exceptions are reported, not hidden:** social posts, hosts that disallow the bot in robots.txt, login walls, scanned-image PDFs.

## 9. Decisions needed before any code

1. Approve phase 1 changes (items 1 to 3, 5 and 10 are annotation or diagnostics only).
2. Approve the qualification behaviour change (item 4) now, or after the pilot.
3. Approve the cited-page fetch, rank-before-fetch and PDF support (items 7 to 9), behind a config flag off by default.
4. Approve the phase 2 schema proposal (item 6), later.
5. Approve running the pilot on dev with external page requests.
6. **No git repository exists**, so a branch is not possible. Proposed alternative: work in the backed-up copy under `g2g-backups`, or run `git init` in `g2gb` (your call), and keep the existing backups.

## 10. Limits of this analysis

- Per-host cause of failure (robots vs 403 vs PDF) is not stored per host, so for gov/tender hosts it is a hypothesis.
- 66 of 154 runs have no diagnostics.
- "Useful" is still a proxy: no meeting or deal outcomes exist, and the human review data is 9 rows with no reason codes.
- Fetch rates are measured on what the pipeline attempted, so they do not show what a different fetch strategy would achieve. The pilot tests that.
