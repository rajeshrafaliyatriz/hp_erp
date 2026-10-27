# Signals: lifecycle, limits, capacity and daily-yield measurement

Facts below come from the source (config and code) as of this change. Nothing here is a measurement: no database was read and no research was run. Numbers marked *theoretical* are ceilings from configuration, not results.

## 1. Lifecycle of one run

1. **Start**: `POST /signals/research/run` (Generate Signals) or `signals:research`. The product key is validated against the organisation's products (`ProductCatalog::definitionsFor`); an unknown or not-ready product is refused with 422 before any search.
2. **ICP resolution** (`ProductCatalog::icp()` -> `resolve()`), used by planning, the AI prompt, qualification and scoring alike:
   - no active saved ICP for the product line -> the built-in definition (`config/signals_icp.php`, ScholarClone: `scholar_k12`);
   - an active saved ICP that is an untouched seed copy (version 1, no author) -> the built-in definition;
   - any other active saved ICP -> built-in definition with the saved top-level keys laid over it (saved wins per key).
   - Another product's ICP is never used; a product with no usable ICP is "not configured" and does not run.
   - `ScholarCloneIcpProposal` is **not** read at run time. It only becomes active if an administrator saves it in the ICP editor.
3. **Planning** (`IcpResearchPlanner`): catalogue queries (rotating window of `per_run.catalogue`), child-product queries, named-account queries, registry queries; cut to `max_queries` (+ child queries); name searches and follow-ups are added after.
4. **Search + fetch**: search provider results (max `results_per_query`), de-duplicated by URL, capped at `max_sources`; at most `fetch_pages` pages are read in full; social hosts are never fetched; every fetch goes through `SafeUrlFetcher` (SSRF/redirect/size checks) and `RobotsPolicy`.
5. **Extraction**: one main AI call over all sources (+ one follow-up, up to `card_rewrite_rounds` rewrites). The model must return a verbatim `evidence_quote`; the researcher rejects a candidate whose quote is not in a cited source that also names the institution.
6. **Qualification**: `QualificationGate` (identity, date, source URL, ICP disqualifier, evidence grade via `EvidenceGrader`, freshness, score bar) -> `MarketImporter` (de-duplication by fingerprint and same-event matching).
7. **Annotation** (does not qualify or reject): `ProductSignalRules` capability tier, `IcpScorer` account facts, `MatchingEngine` offer matches, ICP comparison (`GET /signals/opportunities/{id}/icp-comparison`).
8. **Accounting**: `RunAccounting` writes stage statistics on the run; the UI reads them (`describeFinishedRun`).

## 2. Where the limits are configured

| Limit | Key (default) | File |
|---|---|---|
| Queries per run (catalogue+accounts+...) | `signals.opportunities.max_queries` (14) | `config/signals.php` |
| Catalogue / account searches | `per_run` (ScholarClone 9 / 3) | `config/signals_icp.php` |
| Results per query | `results_per_query` (6) | `config/signals.php` |
| Sources kept | `max_sources` (40) | same |
| Pages read in full | `fetch_pages` (8) | same |
| Candidates kept | `max_opportunities` (15) | same |
| Child-product queries | `discovery.child_product_queries` (2) | same |
| Name searches / registry queries / follow-ups | `name_search_slots` (2), `registry_queries` (2), `followup_queries` (2) | same |
| AI attempts / timeout | `ai_attempts` (2), `ai_timeout` (90 s) | same |
| Run time | `run_budget_seconds` (600) | same |
| Query cache | `query_cache_hours` (4) | same |
| Source age ceiling / undated keep | `sales_pull.qualification.max_age_days` (120), `undated_keep_days` (30) | `config/sales_pull.php` |

`max_signals_per_run` and `dedupe_window_days` exist in config but nothing reads them. None of the limits above was changed by this work.

## 3. Theoretical capacity per run (current configuration)

- Searches: 9 catalogue + 3 account + up to 2 child, then up to 2 name + 2 registry + up to 2 follow-up = about 20 maximum.
- Raw results: 20 x 6 = 120, de-duplicated and capped to 40 sources (official/direct pages can displace search results).
- Pages read in full: at most 8.
- AI: one main call over all sources, one follow-up, up to two rewrites = 4 logical calls (up to 8 counting the one retry allowed by `ai_attempts`).
- Candidates: at most 15 enter the gate. Output is therefore **at most 15 per run, before** the gate, product-fit and de-duplication remove some.

A target of 10-15 *qualified, unique* signals per day therefore sits at or above the ceiling of a single run and depends on several runs per day and on how much of what they find is new. Whether it is reachable is **unknown until measured**; nothing here supports claiming it.

Bottlenecks (in order): the 15-candidate cap and single main AI call; only 8 full-page reads (the rest is snippet evidence); the quality bar (verbatim quote + named institution + fresh date); de-duplication of re-discovered signals.

Options needing approval (none applied): more runs per day; a second AI pass; a higher `fetch_pages` or `max_opportunities`; more registry/direct sources; a paid search provider. Each raises cost and request volume.

## 4. Metrics that decide whether the target was met (per day, per product)

- runs attempted / completed / partial / failed / skipped
- qualified (new vs updated vs unchanged-existing) and **unique new signals**
- rejections by detailed reason (quote not found, institution not named in source, evidence missing, stale, product fit, excluded, duplicate, source off-topic/injection)
- searches planned / attempted / failed / empty / skipped-by-reason
- pages attempted / failed by category / robots-disallowed / skipped; page-cap and time-limit reached
- AI calls failed (main, follow-up, rewrite)
- evidence class distribution and identity confidence of stored signals
- region and source-type split where the data carries it

Daily report shape: `date | product | runs (ok/partial/failed) | new qualified | target 10-15 met? | biggest reason for shortfall (top 3 rejection reasons or incomplete-run cause)`. "Run did not complete" (AI failure, time or page budget) is reported separately from "completed with no qualified signals".

## 5. Safe measurement plan (needs your approval first)

Read-only queries against the **isolated verification database only**: counts from `g2g_research_runs` (stage_stats) and `g2g_company_opportunities` grouped by `report_date`, `business_fit`, `claim_level`, `review_status`. No writes, no migrations, no provider calls.

## 6. Safe verification (no database, no network)

- Backend: `php vendor/bin/phpunit` on `tests/Unit` (skip `tests/Unit/Signals/VerifyIsolationAndAccountingTest.php`, which makes HTTP calls without a fake). `tests/Feature` builds tables from migrations on in-memory SQLite and was not run.
- Frontend: `npm test`, `npx tsc --noEmit`, `npx eslint <changed files>`, `npx next build`.

## 7. Approvals still required

- Run the prepared migration `2026_12_05_090000_create_g2g_signal_products_table.php` (custom products) on an isolated database, then run `tests/Feature/CustomProductApiTest.php` there.
- Extend `QualificationGate::BUSINESS_FIT` and the three opportunity-API lists so custom products can generate signals, then set `signals.custom_products.generate`.
- Save the proposed ScholarClone ICP through the editor (choose a per-run catalogue value; the built-in 9 can be kept unchanged).
- Any increase of the limits in section 2; any real research run; the read-only yield measurement in section 5.

## 8. Evidence, robots, rotation and accounting (what changed)

- **Primary source** (`EvidenceGrader::isPrimary` / `primaryReason`): a source is primary for a buyer only if its host is the buyer's own website (or a subdomain), or the buyer's distinctive name is a whole label of the host's registrable domain. A government or education domain alone no longer counts; ambiguous or generic-only names are "not primary". `SourceScreen::reliability` still labels an official publisher host (e.g. a procurement portal) as a reliable *kind* of source; that label does not change any claim level.
- **Robots** (`RobotsPolicy::decision`): no robots file (404/410/other 4xx) = allowed; 5xx, 429, timeout, DNS or unreadable = **not allowed this time, `robots_unavailable`, retried later** (cached 60 s per object, never permanently); `Disallow` rules support `*`, `$`, longest match with Allow winning ties, UA groups. `HostRateLimiter` is wired into `SafeUrlFetcher::fetch` (waits before each request to the same host; `signals.ingestion.min_host_interval_ms`, default 300 ms, 0 = off, 0 in phpunit). It only slows requests: no request, page or retry is added.
- **Rotation** (`IcpPack::catalogueForRun`, `IcpResearchPlanner`): the catalogue is a circle; run index = days since 1970 x `rotation_runs_per_day` (default 1) + attempt; consecutive attempts tile it with no gap or overlap. The planner now counts today's success, partial **and failed** runs as attempts. Registry-source selection now moves on by the same `IcpResearchPlanner::attemptsToday` count, so repeat or retried runs on one day pick different registry sources.
- **Rejection detail**: the external code `quote_not_in_source` is unchanged; counts of `quote_not_found`, `quote_not_found_snippet_only` and `institution_not_named_in_source` are in `stage_stats.rejection_detail`.
- **Run completion**: `stage_stats.completion` is `completed`, `completed_no_qualified` or `incomplete` (AI failure, time limit, page cap, skipped searches, all searches failed) with `completion_reasons`; the UI says "did not finish" instead of "nothing qualified" for an incomplete run. Reaching the page cap is recorded under `limits` only; it does not make a run "incomplete" (more than 8 sources is normal).

## 9. What a run actually consumes (verified in source)

`GET /portfolio/products/{key}` now returns `research_context` (built by `ResearchContextReport`, read-only), shown in Company Opportunities before Generate: product, ICP name/version/source, regions, and every product/ICP field with whether it is saved, plus warnings. Nothing is borrowed from another product.

| Saved field | Consumed by |
|---|---|
| Offer description, features, problems, target customers, buying events, proof | `ProductCatalog::childProfiles()` -> research prompt (each clipped to 400 chars, at most 12 offers) and `ProductQueryBuilder` (child-product searches); `CapabilityMapping`/`MatchingEngine` for fit |
| Pricing, deal size, deployment, delivery owner, notes | stored and shown; **not** sent to the research prompt (not needed to find a buyer) |
| ICP `industry_label`, `pitch`, `guidance`, `buyer_types`, `regions` | prompt (`IcpPack::promptBlock`), screening (`looksLikeVendor`, buyer types), searches |
| ICP `parts`, `triggers` (+ decay), `queries`, `per_run` | `IcpResearchPlanner` / `IcpPack::catalogueForRun`, freshness (`decayDays`), prompt |
| ICP `must_have`, `disqualifiers`, `manual_flags`, `criteria`, `evidence_points` | `IcpScorer`, `QualificationGate` (`icp_disqualifier`), scoring |
| Company profile (`g2g_product_profiles`) | **not** used for research (it describes the selling company); social/LinkedIn is a search source kind, not a saved profile field |

Source kinds actually coded: search provider results (Tavily/Brave via `WebSearchProvider`), official-site discovery (`OfficialSiteDiscovery`), registry and direct sources (`SourceRegistry`, `DirectSourceReader`; feeds/listings), uploaded documents/URLs (Ingestion Engine). LinkedIn and social pages are only reachable as *search-index snippets* (`domains: ['linkedin.com']`): they are never fetched or logged into, so a social result is a lead, not verified evidence. Nothing here was run against a live provider.

Quotation verification: the model's `evidence_quote` must appear verbatim in a cited source that also names the institution (`quote_not_in_source` otherwise). It is stored as **verified only if that source was a fetched page**; a quote found only in a search snippet is stored `quote_verified=false` (claim level `hypothesis`, lower strength, shown as "not verified on the page").

## 10. Local-to-live release checklist (nothing below has been executed)

1. Deploy `g2gb` and `g2gf` from the same commits (both repos). Live must run the same `ProductCatalog`, `EvidenceGrader`, `RobotsPolicy`, `QualificationGate`, `ResearchContextReport` code, otherwise local and live qualify differently.
2. Compare config keys (names only, never values) between environments: `SIGNALS_RESEARCH_MAX_QUERIES`, `..._RESULTS_PER_QUERY`, `..._MAX_SOURCES`, `..._FETCH_PAGES`, `..._MAX_OPPORTUNITIES`, `SIGNALS_MIN_HOST_INTERVAL_MS`, `SIGNALS_GUJARATI_QUERIES`, `SIGNALS_AI_ATTEMPTS`, search/AI provider keys present. Note phpunit pins its own values; live reads its own environment.
3. Migrations, in order, **only after approval and on an isolated copy first**: `2026_12_05_090000_create_g2g_signal_products_table` (custom products). Earlier signals migrations (`stage_stats`, `product_key`, `assessment`, ICP tables) must already be applied on live; without `stage_stats` the run breakdown is "unavailable".
4. ICP state per tenant: confirm which ICP is **active** for each product line. A live tenant with no saved ScholarClone ICP, or only a seed copy, runs the built-in `scholar_k12` (9 catalogue searches); a saved ICP wins per key. The proposal (`ScholarCloneIcpProposal`) is applied only by an admin saving it in the editor.
5. Custom products: after migration verification set `signals.custom_products.generate=true` for that environment only when approved.
6. Smoke checks without generating: `/portfolio/products/<key>` shows `research_context`; the Product page loads; Company Opportunities shows the "Will use" line; no 404 on `/portfolio/<key>`.
7. First live generation only with explicit approval, one product, watching `stage_stats.completion`, `rejection_detail` and the per-host interval.
8. Restart the Next.js process after deploy (a stale dev/prod process kept serving old routes during this work).

## 11. Daily target: current state

Measured yield: none (no database or provider access was authorised). Capacity ceiling per run is in section 3. The target of more than 10 unique qualified signals per day has **not** been demonstrated; the change that makes evidence stricter (snippet-only quotes no longer count as verified) will, if anything, lower the count of strongly-rated signals until more pages are read.
