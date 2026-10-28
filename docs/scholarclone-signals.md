# ScholarClone Signals: audit, design and operation

Scope: ScholarClone only. Backend `g2gb`, frontend `g2gf`. Nothing else was modified.

## 1. Audit: the path from "Run Research" to a card

**Before** (branch `harshit`)

1. UI "Run research" -> `POST /signals/research/run` -> queued `RunOpportunityResearchJob` -> `OpportunityResearcher::run`.
2. Needed a saved *product profile* (any product, any tenant); queries came from `ProductProfileService` (a product keyword + a generic trigger) unless a tenant happened to be listed in `SIGNALS_ICP_SCHOLAR_TENANTS`.
3. Tavily news search, one AI call scoring itself on seven 1-5 dimensions, `QualificationGate`, `MarketImporter` (the only writer), then `MatchingEngine` against the whole 3-product offer catalogue.
4. Documents/links ran a *separate* analyser (summary, entities, "insights"), then optionally the same lead step.
5. A legacy per-department HR signal generator, ICP admin CRUD, partner/portfolio tabs and market-import endpoints sat on the same screens.

**Root causes found (with evidence in `signals-audit-report.md` and the code)**

| Symptom | Root cause |
|---|---|
| Nothing ever reached "act now" | Hot/warm depended on the model's own seven scores and on claim level "confirmed", which a news snippet can never reach. 13 of 14 stored leads were "hypothesis". |
| Irrelevant / off-product signals | Generic product-profile queries; three products in the catalogue; a "scholar" ICP only for tenants listed in an env var. |
| Weak discovery | News-only search, exact-keyword queries, no LinkedIn / job-board / concept searches, no follow-up from what was found. |
| "Data analysis, not signals" for files | Ingestion produced summaries, entities and findings of kinds like *insight* and *gap*. |
| Intent overstated / unexplained | No buying-intent concept at all; motivation, need, urgency and confidence were folded into one score. |
| Duplicates | Fingerprint included the URL; undated or reworded repeats created new rows. |
| Fabrication surface | The model chose its own intent and score; only the main quote was verified. |

Not broken (kept): tenant isolation from the token, SSRF-safe fetching with robots.txt, verbatim-quote verification, the single-writer importer, vendor/competitor handling, run history, provider/credential resolution (`StructuredAi` -> `AiModelClient`).

## 2. Improved pipeline

```
searches (catalogue 25 + <=2 named schools + <=2 model follow-ups, all budgeted and cached)
 -> pages fetched (SSRF/robots rules unchanged), pages that try to instruct the model are withheld
 -> ONE AI call: candidates with indicators, sales pull, product part (all with quotes)
 -> per candidate, in code:
      quote verbatim in a cited source that names the school   (else rejected)
      stale dated source                                          (else rejected)
      real ScholarClone part + the fact it solves                 (else rejected)
      buying intent = f(verified indicators)                      (never the model's word)
      sales pull kept as "observed" only with a verbatim quote    (else "inferred")
 -> QualificationGate: ICP disqualifiers, vendor/competitor -> context, configurable intent floor, SignalStrength
 -> MarketImporter: dedupe (event identity, tender ref, near-duplicate wording for undated), corroboration
 -> card with the assessments kept separate
```

### Separate assessments (`g2g_company_opportunities.assessment`, `.buying_intent`)

| Concept | Where decided | Notes |
|---|---|---|
| Source reliability | `SourceScreen::reliability` | by source type: official / job post / social / third party |
| Evidence | `OpportunityResearcher::candidate` | quote verified, page vs snippet, sources naming the school, stated vs inferred, date status |
| Buying intent | `BuyingIntent::assess` | explicit / strong / moderate / weak / unconfirmed, from verified indicators only |
| Sales pull | `SalesPull::validate` | 36 categories (`config/sales_pull.php`), <=3 per signal, observed vs inferred |
| Product fit | candidate + `ScholarClone::parts()` | must name a real part and the fact it solves |
| Opportunity strength / urgency | `SignalStrength` | hot / warm / watch; hot needs intent >= `strong` (configurable) and a dated source |
| Next action | model text, checked by plain-language rules | pressure categories are flagged in the prompt |

Intent levels: **explicit** one purchase indicator (open tender, vendor selection, demo/proposal request, procurement notice); **strong** two or more indicators incl. a project one; **moderate** one project indicator (system-role hiring, implementation project, stated problem, partner search); **weak** only context (expansion, new leader, academic hiring); **unconfirmed** none verified. Motivation never changes the level.

## 3. Configuration

| Setting | Default | Meaning |
|---|---|---|
| `SIGNALS_SEARCH_DRIVER` + `TAVILY_API_KEY` | none | required for any research |
| `SIGNALS_TENANTS` | empty | organisations for the daily run (empty = those with target schools) |
| `SIGNALS_RESEARCH_TIME` / `SIGNALS_TIMEZONE` | 10:00 / Asia/Kolkata | daily run |
| `SIGNALS_RESEARCH_MAX_QUERIES` | 8 | searches per run |
| `SIGNALS_FOLLOWUP_QUERIES` | 2 | model-proposed follow-ups, one round, 0 = off |
| `SIGNALS_QUERY_CACHE_HOURS` | 12 | a search already run is not paid for again |
| `SIGNALS_MAX_SOURCE_AGE_DAYS` | 120 | older dated sources are stale |
| `SIGNALS_MIN_INTENT_FOR_FEED` | unconfirmed | floor to enter the feed |
| `SIGNALS_MIN_INTENT_FOR_HOT` | strong | lowest intent that can be hot |
| `SIGNALS_DEDUPE_SIMILARITY` | 0.6 | wording overlap for undated near-duplicates |

Run `php artisan migrate --path=database/migrations/2026_11_29_090000_add_assessment_to_g2g_company_opportunities.php` (additive, nullable, longText so it works on MariaDB 10.1). Do not run a bare `migrate` on live (see `docs/signals-deployment.md`).

## 4. Evaluation

`tests/Fixtures/scholar_eval/cases.json` (17 hand-written cases) is run end-to-end through the real pipeline by `IcpPackResearchTest::test_evaluation_*`, which also emulates the previous pipeline's guards on the same inputs and writes `storage/app/scholar_eval_report.json`. **The fixtures and model answers are synthetic and the search/AI are mocked: this measures the guards, not live recall or live model quality.**

## 5. Not covered / limits

* LinkedIn and job boards are reached only through the search API's index; nothing logs in or scrapes. Coverage depends on what the provider indexes.
* Corroboration is counted from the sources found in the same run; there is no extra search to corroborate a single-source claim.
* The 36 categories, thresholds and indicator tiers are starting points to calibrate on real outcomes (reviewed / dismissed / won), not statistics.
* Reviewer outcomes are stored (`review_status`) but not yet fed back into scoring.
