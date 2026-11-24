# Signal engine: deployment checklist (nothing here has been done)

Status 2026-11-21. Everything below lives in `g2g-backups/work-evidence-depth` (commit `52b45df` and earlier). **Nothing is merged into `g2gb`, nothing is deployed, no migration exists, and no live data was changed.** Every new behaviour is OFF by default. This list is for the owner's separate approval.

## 1. What would carry into g2gb (app code only)
Create a branch off `harshit` in `g2gb` and copy ONLY these. Do not copy `phpunit.xml`, `tests/bootstrap-workcopy.php` or `.phpunit.result.cache` (work-copy test plumbing).

| File | Kind | Effect when deployed |
|---|---|---|
| `app/Domain/Signals/Market/EvidenceMaturity.php`, `EvidenceItems.php`, `SourceIndependence.php` | new | Pure E0-E3 logic and evidence items. |
| `app/Domain/Signals/Sources/FetchRanker.php` | new | Pure ordering, used only when the evidence-depth flag is on. |
| `app/Domain/Signals/Market/MarketCohort.php`, `SignalDeduplicator.php`, `MarketReport.php`, `TargetCandidates.php` | new | Pure helpers. Nothing calls them except the two read-only commands. |
| `app/Domain/Signals/Sources/GovSourceRegistry.php`, `config/signals_gov_sources.php` | new | Register of 26 government/tender sources. Used only when the gov flag is on. |
| `app/Console/Commands/SignalsMarketReport.php`, `SignalsTargetCandidates.php` | new | Read-only commands (SELECT only). Run by hand, never scheduled. |
| `app/Domain/Signals/Opportunities/OpportunityResearcher.php` | modified | (a) always: annotations `assessment.evidence_maturity`, `evidence_items`, `independent_sources`, `why_now`, and per-host fetch outcomes in diagnostics (additive); (b) flag `SIGNALS_EVIDENCE_DEPTH`: ranked first pass, PDF reading, cited-page fetch; (c) flag `SIGNALS_GOV_SOURCES`: government landing pages for B2G runs of non-school products. |
| `app/Domain/Signals/Run/RunAccounting.php` | modified | Adds `pages.fetch_by_host`, `pages.cited_pages`, `gov_sources` to `stage_stats` only when recorded. No existing key changes. |
| `config/signals.php` | modified | Adds `evidence_depth` and `gov_sources` blocks, both default off. |

**Added after the first version (2026-11-21, later):**

| File | Kind | Effect |
|---|---|---|
| `app/Domain/Signals/Market/SignalGrade.php`, `GenericName.php`, `SourceKind.php`, `VerificationPlanner.php` | new | Pure. `assessment.signal_grade` = ready / verify / lead / reject with reasons. Annotation only. |
| `app/Domain/Signals/Sources/Adapters/*` (MarketItem, PlatformPolicy, FeedReader, AbstractApiAdapter, YouTubeSearchAdapter, RedditSearchAdapter, GoogleCseAdapter, MarketSourceHub) | new | Official APIs and public feeds. Social hosts are lead hints only; never fetched. `AbstractApiAdapter` is the only new file that makes HTTP calls. |
| `config/signals_market_sources.php` | new | All flags OFF. Credentials only from env. |
| `OpportunityResearcher.php` | modified | Adds the `signal_grade` annotation, and `marketSourcesIfEnabled()` (flag `SIGNALS_MARKET_SOURCES`). |
| `RunAccounting.php` | modified | `stage_stats.signal_grades` and `stage_stats.market_sources`, only when recorded. |

Extra flags (all default OFF): `SIGNALS_MARKET_SOURCES`, `SIGNALS_YOUTUBE`, `SIGNALS_REDDIT`, `SIGNALS_GOOGLE_CSE`. Credentials the owner must set in the environment to use them: `YOUTUBE_API_KEY`, `REDDIT_CLIENT_ID`, `REDDIT_CLIENT_SECRET`, `GOOGLE_CSE_KEY`, `GOOGLE_CSE_CX`. Feed URLs go in `config/signals_market_sources.php` `feeds`.

**Operations prerequisite found on live (2026-11-21): the queue worker was not running.** Every connector sync ended "This sync stopped responding (the queue worker was most likely stopped or lost)" and 8 jobs were waiting. Research runs and connector syncs both run through the queue; make sure a worker is running before and after deployment.

Not changed anywhere: frontend (`g2gf`), routes, API field names, `claim_level`, `qualification`, `confidence`, priority, ICP fit, database schema.

## 2. Flags (all default OFF; production stays off until you approve)
| Env | Default | Meaning |
|---|---|---|
| `SIGNALS_EVIDENCE_DEPTH` | false | ranked fetch order, PDFs, cited-page fetch |
| `SIGNALS_EVIDENCE_CITED_PAGES` | 6 | cited pages per run (only when the flag is on) |
| `SIGNALS_GOV_SOURCES` | false | read government landing pages in B2G runs |
| `SIGNALS_GOV_SOURCES_PER_RUN` | 3 | sources per run (only when the flag is on) |

## 3. Before deploying
1. Back up: `php artisan signals:backup-tables` (existing, read-only) and keep the files.
2. In `g2gb`, `git status` is not clean (earlier uncommitted work: `SchoolTriggers`, `docs/signal-engine/`). Decide whether to commit or move it first. The `why_now` annotation in this tree already includes that change.
3. Run the signals suites in `g2gb`: `php vendor/bin/phpunit tests/Unit/Signals` and the 10 researcher feature files. Expected failures are only the 5 that exist on unmodified code (see section 6).
4. Run the frontend's existing tests unchanged in `g2gf` (`node --import tsx --test "lib/**/*.test.ts"`): 274 of the signal ones passed on 2026-11-21.
5. Run `php artisan list | grep signals:` and confirm `signals:market-report` and `signals:target-candidates` appear (they do when this tree's code is autoloaded).

## 4. First live steps (only after approval, one at a time)
1. Deploy with every flag OFF. Confirm a normal research run behaves as before (same `stage_stats` keys; the frontend run notice reads them unguarded, see `FrontendContractTest`).
2. Run `signals:market-report --tenant=6 --format=json` (read-only) and compare with `docs/signal-engine/PILOT-AND-CHANGE-REPORT.md`.
3. Enable `SIGNALS_EVIDENCE_DEPTH` for ONE run, check the run's `stage_stats.pages`, then decide.
4. Enable `SIGNALS_GOV_SOURCES` for one B2G run of G2G or Enterprise Brain, check `stage_stats.gov_sources`.

## India first (default ON; added 2026-11-21)
- With no region requested, a research run is now aimed at **India** (`signals.region.default`, env `SIGNALS_DEFAULT_REGION`). Searches get India terms, and a candidate whose own text names another country and never India, an Indian state or an Indian city is rejected as `outside_region` (`SIGNALS_REJECT_NAMED_FOREIGN`, default true).
- A request that names a region always wins. The run still STORES only what was requested, so signals already found keep their identity and are not found again as new ones.
- **Turn it off:** set `SIGNALS_DEFAULT_REGION=none` (back to All Regions) and/or `SIGNALS_REJECT_NAMED_FOREIGN=false`. No data to undo.
- Files: `app/Domain/Signals/Support/DefaultRegion.php`, `ForeignCountryHint.php`; wiring in `OpportunityResearcher.php`; `config/signals.php` `region` block. `signals:target-candidates` also leaves out foreign names unless `--all-regions`.
- Test changed on purpose: `ChildProductDiscoveryTest::test_a_run_for_one_sub_product_uses_its_own_icp_searches...` now expects the sub-product's search to be sent aimed at India.
- **Behaviour change to expect:** the first runs after deployment will return fewer or different candidates than before, because foreign buyers are no longer proposed.

## Verification step, two bug fixes and real-run results (added 2026-11-21, later)
- **Verification step** (flag `SIGNALS_VERIFICATION`, default OFF; caps `SIGNALS_VERIFICATION_MAX_SEARCHES`=4, `SIGNALS_VERIFICATION_MAX_PAGES`=6): a candidate graded `verify` is checked against a second, independent, readable source and its `evidence_maturity` / `signal_grade` are recomputed. It never changes claim_level, qualification, confidence, priority or fit. Files: `Market/Corroboration.php`, `OpportunityResearcher::verifyCandidates()`, `stage_stats.verification`.
- **BUG FIXED (important):** the `why_now` annotation added in the first phase reused `$eventDate` for a string, so every candidate with a detected school trigger crashed the whole run ("Call to a member function toDateString() on string"). It is fixed in this tree (`$whyNowDate`) with `WhyNowRegressionTest`. **Anyone running the earlier uncommitted version of `OpportunityResearcher.php` has this crash.**
- **Directory and listicle pages** (themanifest.com, clutch.co, `/top-10-...`, `/companies/`, `/list-of-...`) are now rejected by the grade (`SourceKind::isDirectoryOrListicle`), after three signals came from one directory page.
- **Real dev runs, all flags on (India first, evidence depth, verification, gov sources for B2G):** G2G partners 3 signals then 0 on rerun; ScholarClone Government crashed (bug above), then 3 signals (2 lead, 1 verify); Enterprise Brain Government 0 signals (3 government landing pages read). Nothing graded `ready`. The limit is source access: cited pages read 0 in every run (HTTP 403 / robots.txt), and verification's two page reads were both blocked by robots.txt.
- **Dev-only test data to remove when you finish testing (dev database `hp_erp` on the dev host, tenant 6):** `g2g_icp_profiles` ids 2-15 (copied from live), `g2g_product_offers` ids 54-60 (copied from live), research runs 99-106 with their sources, signals 132-139 and their events and matches, and `ai_usage_events` for those runs. Nothing was written to live.
- Nothing in `g2gb` is committed. `app/Providers/AppServiceProvider.php` and `app/Support/PersonalAccessTokenResolver.php` differ from the work copy because of your own Intelligence changes; they were not touched.

## Qualification rule and on-screen grade (added 2026-11-21, last; both OFF by default)
- `SIGNALS_QUALIFICATION_BY_GRADE` (config `signals.qualification_rule.by_grade`, min fit `SIGNALS_QUALIFICATION_MIN_FIT`=45): the label also needs a **ready** grade, **E2 or E3** evidence (a page read alone, E1, never qualifies) and an ICP fit at or above the minimum. Confidence stays as it was (from claim_level), so qualification is no longer the same lookup as confidence. Off: the old claim_level lookup, exactly. File: `Market/QualificationRule.php`, wired in `MarketImporter::qualificationFor()`.
- `SIGNALS_SURFACE_GRADE`: what the grade says is still missing joins `assessment.unverified`, the list your screens already show as "Not established". No UI change.
- **Incident caught by a feature test:** my first version defined `signals.qualification.min_fit` (45). The existing `QualificationGate` reads that same path (default 25), so it would have tightened the gate even with the switch off. Renamed to `qualification_rule`; `ConfigKeyCollisionTest` now guards it. If you copied an earlier `config/signals.php`, make sure it has no top-level `qualification` key.

## Final quality rules and fixes from the last real runs (2026-11-22)
- **Bug fixed:** a source title longer than the 500-character `source_title` column failed the INSERT and the WHOLE run (run 120, "Data too long for column 'source_title'"). Titles are now cut to 480 characters (`LongTitleRegressionTest`).
- **Directory and list pages** are rejected by the grade, now including `builtin.com`, `builtinpune.in`, `/top-...-companies`, `...-companies-in-india`, `...-to-watch-in-...`: four Enterprise Brain "signals" had come from such pages.
- **Competitor rule:** a candidate whose own text calls it a competitor ("already sells a competing school ERP") is a disqualifier hit, so it grades `reject`.
- **`SIGNALS_DROP_REJECTED_GRADE` (default OFF):** when on, a candidate graded `reject` is not stored at all (counted as `graded_reject`). Recommended once you have watched a few runs.
- **Recommended order to switch on in dev:** evidence depth, verification, government sources, `SIGNALS_QUALIFICATION_BY_GRADE`, `SIGNALS_SURFACE_GRADE`, `SIGNALS_DROP_REJECTED_GRADE`. Rotation (`SIGNALS_ROTATE_REPEAT_RUNS`) is already on by default and makes repeated runs on one day use different searches.
- **Dev test data to remove** (dev database only, tenant 6): ICP profiles ids 2-22 and product offers 54-60 (copied from live); research runs 99-123 with their sources; signals 132-150 with events and matches; `ai_usage_events` for those runs. Run 117 was cut off and closed as failed. Nothing was written to live.

## 5. Rollback
- Flags: set the env value back to false (immediate; no data to undo).
- Code: revert the branch; no migration, so nothing to roll back in the database.
- Data written by flagged runs is ordinary signal rows plus additive `assessment` keys the UI ignores.

## 6. Known test failures (pre-existing, reproduce on unmodified code)
1. `SocialDataConnectorAdapterTest::test_youtube_reads_only_the_authorized_channel_uploads_and_uses_video_publication_time` (Undefined array key "id")
2. `SourceQualityTest::test_gujarat_aimed_queries_name_gujarat_cities_and_no_longer_say_maharashtra`
3. `VerifyIsolationAndAccountingTest::test_source_text_no_other_http_call_site_exists_in_the_research_path` (whitelist expects 3 files; 5 social connectors exist)
4. `ChildProductDiscoveryTest::test_a_sub_product_run_that_is_not_ready_stores_nothing_and_says_why` (feature)
5. `ResearchRunProgressTest::test_looking_at_a_past_date_or_the_history_never_starts_research` (feature)

Latest signals unit suite: 557 tests, failures 1-3 only (4 and 5 are feature tests).

## 7. NOT done, and what that means
- **Qualification is unchanged** (a lookup from `claim_level`). Not decided; see the pilot report.
- **Cited-page fetching, quote verification and PDF reading are proven by unit tests only.** The one dev pilot pair found zero candidates (no target accounts for the product), so these were not measured on real candidates.
- **Government sources:** landing pages only. The tender tables on official portals are probably dynamic; 16 of 26 sources refuse our bot or need a feed. Nothing proves a real tender is found yet.
- **No target accounts for Enterprise Brain or G2G.** `signals:target-candidates` proposes a list for review; `signals:load-accounts` (existing) is the loader.
- **Live cleanup not done:** the 16 `demo_fixture` rows (`signals:demo-fixture` copied local 13-14 Oct signals into live), the 16 ingestion notes, 6 no-URL rows and 4 real duplicate pairs are still counted in live totals.
- **Review-screen display of E0-E3:** not built (frontend frozen; needs your approval of any UI change).
- **Tavily credits and dev AI cost are not recorded by runs** (null), so run cost shown to users is incomplete.
