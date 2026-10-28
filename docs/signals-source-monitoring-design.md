# Signals: direct source monitoring — design for a later phase

Status: **design only. Nothing in this document is implemented.** It records what the current system does, what it does not do, and the
smallest safe steps toward real source monitoring, with acceptance criteria. No network request was made to write it.

## 1. What exists today (verified in code)

| Capability | Where | Behaviour |
|---|---|---|
| Keyword web search | `Opportunities/TavilySearchProvider` (Brave driver exists, off) | Each run sends its queries to Tavily; result URLs are whatever Tavily returns. `include_domains` restricts a query to the listed hosts. |
| Page read of search hits | `OpportunityResearcher::enrich` | First `signals.opportunities.fetch_pages` (8) results are fetched through `SafeUrlFetcher` after a `robots.txt` check. The rest stay snippets. |
| Official school site | `Scholar/OfficialSiteDiscovery` | For target accounts with a **verified** website: home page, `sitemap.xml`, and up to `official_site.max_pages` same-host pages whose address or link text suggests news / careers / tenders. Recheck window 12 h. |
| User documents and URLs | `Ingestion/IngestionService` | Read once when a person adds them. |
| Structured file import | `signals:import-market` | Manual. |

## 2. What it is not

* **Not monitoring.** No source is revisited on a schedule *as a source*. A site appears in a run only if Tavily returns it for that run's
  query, or it is a verified target-account site.
* **A `domains` list on a query is a search filter**, not a parser. `gem.gov.in` in `include_domains` means "ask Tavily for pages it has
  indexed on that host"; it does not read the portal's tender list.
* No RSS/Atom, no news API, no portal-specific adapter, no careers-page crawler outside target accounts.

Reports and UI text must therefore say "found by search" and never "monitored".

## 3. Proposed phases (smallest useful first)

### Phase A — record retrieval status per source (no new provider)
Persist, for every page a run touches, how it was obtained: `search_snippet | page_fetched | blocked_by_robots | fetch_failed | not_attempted`,
plus HTTP status class and fetch date. Today only `page_fetched` (bool) exists on `g2g_research_sources`.
*Needs a migration (one nullable column) — requires approval and the isolated environment first.*

Acceptance: a run's source list shows, per URL, whether the page text was actually read; a test with mocked HTTP covers each status.

### Phase B — feed adapters for sources that publish one (RSS/Atom)
A `FeedSource` configuration per tenant (URL, label, product line) read through `SafeUrlFetcher` (public IP, ≤3 redirects, size cap, ports
80/443, `robots.txt`). Items newer than the run window become `sources` for the existing extraction/validation path; nothing bypasses the
verbatim-quote check. Feed items carry their own published date (provenance `feed`).

Acceptance: mocked-feed tests for dated and undated items, a malformed feed, an oversize feed, a redirect to a private address (refused), and
`robots.txt` disallow. Items already seen (by URL key and content hash) are not re-extracted.

### Phase C — tender portal adapters, one portal at a time
Only where a portal exposes a stable public listing or feed. Each adapter is its own class with fixtures captured from the portal's public
pages, parses reference number, buyer, title, closing date, and feeds `trigger_type = tender` with `reference_no` (which the importer already
uses to dedupe). **No CAPTCHA, login or rate-limit evasion**; a portal that needs any of these is not supported.

Acceptance: fixture-based parser tests; the closing date is taken only from the page; reference-number dedup test; per-portal request budget
and delay in config.

### Phase D — careers-page watch for target accounts
Extend `OfficialSiteDiscovery` with a stored list of known careers URLs per account, re-read on a schedule, with change detection (content
hash) so an unchanged page produces no extraction call.

## 4. Cost and approval gates

* No new paid provider or API without a written cost estimate and explicit approval.
* Each phase is verified first with mocked HTTP in the isolated in-memory test environment; a live trial needs a separately approved,
  budgeted single run.
* Per-source request budgets and the existing run time budget apply to every adapter.

## 5. Observability required by every phase

Per run (already logged as `signals.run_summary`): queries planned / executed / skipped (with reason) / failed (by kind), child-product
coverage, sources returned / fetched / screened out / blocked by robots, rejections by reason, importer outcome. Phase A makes the per-source
part persistent and queryable.
