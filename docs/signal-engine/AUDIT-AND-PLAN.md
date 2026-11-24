# Signal Intelligence: audit of what exists, and a gap-based plan

Date: 2026-11-21. Basis: reading the repo (`app/Domain/Signals`, `docs/agentic-crm`) and the S0 baseline of 2026-11-02.
Not measured: any live or dev database today, production env flags, whether deployed code matches this branch.
Tags: **read** = I read it in source or docs. **baseline** = a number from the 2 Nov S0 evidence, now about 3 weeks old. **inferred** = my reasoning.

## 1. Main conclusion

The "Signal Intelligence Engine" proposed in the ChatGPT/Claude chat is **already about 60% built** for sales. Do not start a new engine. Fix the gaps in the one you have.

## 2. Chat proposal vs what exists

| Chat proposes | Already in repo (read) | Gap |
|---|---|---|
| Evidence per signal (source, date, quote) | `g2g_research_sources`, `EvidenceGrader`, evidence panel in S2 plan | Quote and date are often missing (see section 3) |
| Qualification tiers, not every school a lead | `ProductSignalRules` (qualified / review / informational / rejected), `QualificationGate` | Rules are fixed phrase lists, i.e. the keyword matching the chat warns about |
| ICP fit filter | `IcpScorer`, `IcpProfileValidator`, per-product ICP | 32 of 72 signals had no product fit (baseline) |
| Dedupe by event | `add_uniqueness_to_signal_opportunities`, `CompanyAlias` | Dedupe is by company, not by event; `crm_signal_inbox.signal_key` is unique across tenants (D11) |
| Review feedback with reasons | `g2g_signal_reviews`, `SignalReviewService`, reason codes (not_a_buyer, stale, duplicate, ...) | Only 8% of signals were ever reviewed (baseline) |
| Source registry | `Sources/SourceRegistry`, `SourceSelector`, `RobotsPolicy`, `SafeUrlFetcher` | Reliability is not scored or learned |
| Date handling, staleness | `SourceDates`, `SignalDateCorrection`, recheck commands | 75% of signals had no date (baseline) |
| Cost and yield tracking | `RunAccounting`, `signals:yield-report` | Runs stored no cost at baseline; slice S2 adds it |
| Outcome feedback (meeting, deal, revenue) | `LeadSignalEvidence`, promote-to-lead path | **Not closed.** Nothing links a signal to a meeting or deal and back to source quality |
| Scheduled generation | `signals:research`, off unless `SIGNALS_SCHEDULED_RESEARCH=true` | **All 72 runs were manual; scheduler never ran in production** (baseline) |
| Development, recruitment, product, finance signals | Nothing under `Domain/Signals` | Entirely new |
| Reactive / predictive / discovery modes | Reactive only | Predictive and internal-discovery are new |

## 3. Why sales signals are below expectations (ranked, with evidence)

1. **No "why now" date on most signals.** 75% undated (baseline). Without a date there is no recency, no urgency, no expiry. This alone makes signals feel stale. *Inferred* to be the biggest single cause.
2. **Detection is phrase matching.** `ProductSignalRules` marks "qualified" only when an exact phrase and an intent word such as "tender" co-occur. Real triggers (leadership change, campus expansion, new affiliation, teacher hiring surge) are mostly one weak "review" tier. *Read.*
3. **A third of signals cannot be tied to a product** (32 of 72 null `business_fit`), so they cannot be scored against an ICP or routed. *Baseline.*
4. **Nothing runs unattended.** Manual runs mean the volume and freshness depend on a person pressing a button. *Baseline; production flags not verified.*
5. **The review loop is barely used and has no outcome.** 8% reviewed, and the chain stops at "promoted to lead". The engine cannot learn which sources produce meetings. *Baseline + read.*
6. **Defects that corrupt the funnel:** promote-to-opportunity inserts non-existent columns (D3); `ColumnExists::has` throws on live MariaDB 10.1 and is called unguarded in `OpportunityResearcher` (D20). If production runs this code, research runs can fail. *Verified in S0.*

Counts above are 3 weeks old. **First action: re-run the baseline and `signals:yield-report` read-only** and replace these numbers.

## 3b. Status update (same day, after building)

- **D20 is already fixed in this branch** (`ColumnExists::has` now sends a quoted literal). Sections 3 and Step 1 overstated it. Whether *production* runs this branch is still unknown.
- **D3:** the `opportunity_name` insert no longer appears in `CrmSignalInboxController`; the direct lead insert with `last_name = 'Signal Lead'` (skipping `LeadFactory`) is still there.
- **Built (code only, no migration, no database touched):** `SchoolTriggers` (10-trigger taxonomy with expiry and action, event fingerprint, priority vs confidence score, undated cap) in `app/Domain/Signals/Products/SchoolTriggers.php`, tests in `tests/Unit/Signals/SchoolTriggersTest.php`, and an annotation `assessment['why_now']` written by `OpportunityResearcher` into the existing assessment JSON. It never qualifies or rejects a signal. Covers plan steps 2, 3 and the fingerprint half of step 4.
- **Not built:** unattended scheduling, outcome loop (step 5), dev and recruitment signals (steps 6-7). These need your decisions in section 5, and step 5 needs a migration.

## 4. Plan (fits your slice and design-gate process)

Each item is a slice that needs your design-gate approval before code. Order is by value over effort.

**Step 0 (1 day, read-only): re-measure.** Re-run S0 baseline and yield report. Label the 100 most recent signals by hand: useful / wrong ICP / weak evidence / stale / duplicate / no date. Output: a one-page table that confirms or changes section 3.

**Step 1: fix what blocks everything (D20, D3, D14).** Guard `ColumnExists::has`, fix the promote path, turn on the scheduler with a queue worker. Needs your go for live.

**Step 2: "why now" triggers.** Replace the single phrase table with an event taxonomy of 8 to 10 school triggers (new campus, new affiliation or upgrade, leadership change, teacher hiring surge, ERP/LMS tender, group formation, government programme, funding, event with school leaders). Each trigger gets: detection rule, required evidence fields (event date, quote, URL), expiry in days, and a suggested action. Extend `ProductSignalRules`; do not replace the tiers. Make "no event date" a hard `review` cap, not silently accepted.

**Step 3: confidence vs priority.** Store both. Keep the current tier as the gate; add priority (fit, trigger strength, recency, reachable contact) as a sort order only. Start with simple rubric weights and recalibrate from outcomes; the chat's 20/20/20/15/10/10/5 split is a guess and should not be treated as validated.

**Step 4: event-level dedupe.** Add an `event_fingerprint` (company + trigger + month) so four articles on one expansion collapse to one signal with four sources. Fix D11 (tenant in the dedupe key).

**Step 5: close the outcome loop.** Record signal -> contacted -> meeting -> opportunity on the lead, and report "qualified meetings per 100 signals" per source and per trigger. Feed source reliability back into `SourceSelector`. This is the part the chat is right about and the repo lacks.

**Step 6: development signals (new, internal, low risk).** Data is yours: GitHub PRs and CI, issue labels, time on repetitive tasks. Start with three measurable signals: PR open > N days, repeated bug class, repeated CRUD scaffolding. Output a weekly report (what slows us, what could help, what experiment to run) with a before/after measure. Needs an owner decision on which repos and whether to use the GitHub API (note: the GitHub MCP connection in this environment currently fails).

**Step 7: recruitment signals (later, consent-based only).** Applicants, referrals, candidate-submitted portfolios, mapped to your competency model. No scraping of private profiles; DPDP Act 2023 applies to candidate personal data. Not before steps 0-5 are working.

**Step 8: product, customer-success, finance, management.** Reuse the signal record and feedback. Defer until two verticals have shown value.

## 5. Decisions needed from you

1. Approve Step 0 (read-only measurement against dev and live) so the numbers are current.
2. Is the production scheduler/queue allowed to be switched on (Step 1)?
3. What is the authoritative signals table going forward: `g2g_company_opportunities` (the live design) vs the older `g2g_signals`, `crm_signal_inbox`? There are three stores today; the plan assumes `g2g_company_opportunities`.
4. For development signals: which repositories, and may we read GitHub?

## 6. What I did not do

I did not change code or touch any database. Your session rules require a design-gate approval per slice and an explicit go for live changes, and the numbers above need re-measuring first.
