# Sales & Marketing Unification — Decisions

This file records *why*, so later phases don't re-litigate what's already been decided. If you're starting a new session on this effort, read `00-PROGRESS.md` for status, then this file for reasoning.

## The merge, in one paragraph

CRM, "GTM & Revenue", and "Signals" all describe the same business process (prospect → revenue) but were built independently: three "Account" tables (`crm_organizations`, `gtm_accounts`, `g2g_companies`), three "Opportunity" tables (`crm_opportunities`, `gtm_deals`, `g2g_company_opportunities`), two "Campaign" tables, two incompatible permission systems, and a Department-Detail "Signals" tab that actually renders an unrelated org-wide sales-lead feed (ignoring the department it's opened for). The goal is one working "Sales & Marketing" module in the UI: Marketing → Sales → GTM Agents → Signals → Support.

## Strategy: federation, not a database merge

Two full implementation designs were produced and evaluated before committing:

1. **Full unification** — literally merge `gtm_accounts`→`crm_organizations`, `gtm_deals`→`crm_opportunities`, `gtm_campaigns`→`crm_campaigns` via backfill/dual-write/cutover, drop the GTM tables once migrated.
2. **Federation** — keep all three table sets exactly as they are; unify navigation, unify permissions, wire the cross-module links that already exist in schema but were never implemented, fix the Department Signals tab, de-duplicate naming/UX at the presentation layer only.

**Decision: federation.** Reasons:

- GTM's workflow engine, forecast snapshots, and health scoring are **live, scheduled, production automation** (`gtm:tick`, every 5 minutes via `routes/console.php`). A table merge means rewriting every query in 5 AI agent classes (`app/Domain/Gtm/Agents/*`), the workflow engine, and the forecast scheduler, behind a backfill → dual-write → cutover sequence against production data. Real risk for a live revenue system, on a codebase already known (from prior work on this project) to have dev/live drift history.
- The "GTM Deals and CRM Opportunities are kept fully separate by design" comment, verified verbatim in `database/migrations/2026_11_24_080300_create_crm_opportunities_table.php` and `2026_11_24_081100_add_crm_organization_id_to_gtm_accounts_table.php`, is a dated, deliberate decision by engineers who considered and rejected the merge — not stale boilerplate. Overriding it needs a better reason than surface similarity.
- This is the standard pattern (strangler-fig / expand-contract) for consolidating live systems: unify the experience and orchestration first. If a deeper data merge is ever still wanted once the unified system has run and proven itself, it becomes dramatically lower-risk at that point, because the promotion/bridge flows built in Phase 3/4 already produce the id-mapping data a future backfill would need. This plan doesn't foreclose that path — it just doesn't take on that risk now, unasked.
- Every phase ships value independently and is reversible on its own.

**User explicitly confirmed this call** after being asked to choose between the two designs and responding "think by yourself from all points and future side also then decide by yourself" — i.e. delegated the strategic call, taken here with the reasoning above.

## Engine A (orphaned per-department HR signal engine): stays in Org/HR

Signals is actually two unrelated engines sharing a name:
- **Engine A** — `g2g_signal_runs`/`g2g_signals`, per-department (`department_id`-scoped), AI-generated HR/org-health insight (staffing gaps, attrition risk) from aggregate counts. Fully built on the backend. **Zero frontend consumer anywhere** — `g2gv0/services/signals/index.ts`'s `signalsService` has no callers in the entire frontend repo.
- **Engine B** — "Company Opportunity Intelligence", org-wide AI-discovered B2B sales leads (`g2g_company_opportunities`, `g2g_companies`, `g2g_research_runs`, ingestion). This is what the Department Detail "Signals" tab actually renders today, via `signals-hub.tsx`, which receives a `department` prop and never reads it.

**User explicitly decided:** Engine A is an HR/org-health feature, not a sales one — fix it in place (wire its orphaned frontend service to its own correctly-scoped tab on Department Detail), do **not** fold it into Sales & Marketing. Engine B is the part that's actually sales-shaped and moves into the new Sales & Marketing → Signals branch.

## Stage/vocabulary mappings (for when Phase 3's bridge needs them)

`gtm_deals.stage` (6 values: discovery/qualification/proposal/negotiation/won/lost) has no direct equivalent needed for federation (we're not merging the table), but the one bridge write path (Phase 3: convert a won deal to a CRM Opportunity) needs to pick the right `crm_opportunities.sales_stage` picklist value for "won" — **confirm the exact seeded string from `crm_picklist_values` at implementation time** (seeded in `database/migrations/2026_11_24_081200_seed_crm_sales_picklist_values.php`), don't assume "Closed Won" is the literal stored string without checking.

## Access baseline (recorded Phase 0, 2026-10-15 — the regression floor for Phase 2)

`App\Support\RoleKey::ALIASES` (verified in `app/Support/RoleKey.php`):
```
admin     => administrator
hr        => hr_manager, hr_executive
manager   => hr_manager, reporting_manager
executive => executive
```

Today's effective access by `role_key`:

| Gate (today) | Allowed role_keys |
|---|---|
| GTM read (`profile:admin,executive`) | administrator, executive |
| GTM write (`profile:admin`) | administrator |
| Signals read/review (`profile:admin,hr,manager`) | administrator, hr_manager, hr_executive, reporting_manager |
| Signals write/run (`profile:admin,hr`) | administrator, hr_manager, hr_executive |

Any profile row in `tbluserprofilemaster` with one of these `role_key` values, across every `sub_institute_id` (tenant), currently has the corresponding access. Phase 2's seed migrations must grant the Phase-1 menu rows to at least these same role_keys (same or wider — never narrower) before flipping the `platformright:`/`menuright:` switch on.

## Menu id facts (recorded Phase 0, 2026-10-15)

- `tblmenumaster_g2g` id **199** = `menu_name "CRM"`, `access_link "/module/crm"` — **identical on dev (`mysql`, host 202.47.117.220) and live (host 128.199.17.97)**. Safe to reuse the existing guard (`menu_name==='CRM' && access_link==='/module/crm'`) from `2026_11_20_085000_reactivate_crm_marketing_menu_rights.php` in every Phase 1/2 migration.
- GTM root row (`access_link='/gtm'`) has **different ids between dev and live**: dev `id=441`, live `id=438`. Confirms, independently, the "dev/live disagree on ids" note already in GTM's own migration comments. **Every Phase 1/2 migration must resolve this row by `access_link`, never by a hardcoded numeric id.**

## What this plan deliberately does NOT do

- Does not merge `gtm_accounts`/`gtm_deals`/`gtm_campaigns` into their CRM counterparts.
- Does not touch `gtm:tick`, `gtm_workflows`, outreach sending, or CRM's order-to-cash chain, at any phase.
- Does not fold Engine A into Sales & Marketing.

If a future session is tempted to revisit any of the above, re-read this file first — these were considered, not defaulted.

## Gap-closure scope tiering (decided 2026-10-16)

The user's ask broadened from "is the merge correct" to "customers should be able to run their *entire* sales & marketing lifecycle here with no complaints" — fix gaps, add missing features, perfect the design, backend, and database. A 3-agent gap analysis against an industry-standard (HubSpot/Salesforce/Pipedrive/Zoho/Outreach-class) benchmark surfaced a long list of real gaps, split into three tiers by what closing them requires:

- **Tier 0 — already built, switched off pending credentials**: GTM outreach sending (needs real SMTP), Signals web research (needs a Tavily/Brave key). Not code work — an infra/config decision for whoever administers this tenant.
- **Tier A — buildable now, inside the existing architecture, no new vendor**: web-to-lead capture, lead scoring + assignment routing, win/loss reasons, SLA timers, Invoice PDF/send/payment-status, list-based campaign sending.
- **Tier B/C — needs a new paid vendor or business decision**: payment gateway, e-signature, CPQ-grade pricing, enrichment vendor, SMS/WhatsApp, a customer self-service portal, conversation intelligence.

**Decision: proceed with Tier A now, in phased waves, same cadence as the merge itself (one wave, verified and logged, before starting the next). Tier B/C is deliberately not started** — building a fake or stubbed payment/e-signature/enrichment integration to look "done" would be worse than leaving the gap visible, and the actual choice of vendor (which gateway, whose API key, what it costs) is the user's to make, not an assumption to bake into the architecture. Tier 0 is flagged plainly each time it's relevant but is not something code can fix — it needs real credentials in `.env`.

This mirrors the original federation-vs-merge call: make the call that doesn't foreclose a bigger future decision, ship the part that's unambiguously correct now, and name the part that genuinely needs the user rather than guessing at it.

## Federation decision reaffirmed, not re-litigated (2026-10-19)

A deep re-verification audit (see `00-PROGRESS.md`'s 2026-10-19 entry) found two real UX gaps — missing breadcrumbs on 16 GTM/Signals/Ingestion/Portfolio/Partners pages, and a stale "CRM" breadcrumb label on 22 CRM detail pages — and, separately, that production's scheduler has essentially never run (2 total ledger rows, both 3+ weeks stale, from a dev laptop). The user explicitly asked, before any fix work started, whether this changed the federation-vs-full-merge call, framing it as a deliberate challenge to re-examine the original decision with fresh evidence rather than assume it still holds.

It was re-examined, not assumed:

1. **The dormant-scheduler fact is new** — the original decision's lead argument ("GTM's live, scheduled, production automation" — see the Strategy section above) is measurably weaker today than when it was made, since that automation is not currently running in production at all.
2. **Everything else is unchanged or stronger.** `git blame` on the deliberate-separation comment (`database/migrations/2026_11_24_080300_create_crm_opportunities_table.php:18-22`) traces it to commit `daed9056d`, authored by the same person who asked this question, in an earlier session of this same effort — not a different team's constraint to second-guess. A fresh static-analysis count (grep across both repos, not estimated) puts the real blast radius of a 9-table merge at **69 backend files, 40 migrations, 51 frontend files found by literal string search alone — ~173 once Eloquent-model-only call sites (`GtmAccount::`, `CompanyOpportunity::`, etc., invisible to grep) are counted** — higher than the original decision's own estimate of "5 AI agent classes" (the real count is 6, plus workflow/forecast/monitoring layers the original note didn't enumerate). Industry precedent (general knowledge, not from this codebase) also holds: a CRM, a sales-engagement tool, and an intent/signals tool are essentially never merged into one schema in real deployments, even inside bundled commercial suites — "system of record + sync" is the standard pattern, which is what federation (plus the Phase 3/4 bridge columns) already is.
3. **The existing bridge columns still point one direction only** — `gtm_accounts.crm_organization_id`, `crm_opportunities.converted_from_gtm_deal_id`, `crm_leads.source_company_opportunity_id` promote GTM/Signals data *into* CRM's unmodified shape; there is no TODO or scaffolding anywhere suggesting a two-way-sync or shared-table target was ever planned.

**Decision: federation is reaffirmed.** A full data-model merge of the 9 `crm_*`/`gtm_*`/`g2g_*` tables remains the wrong move — not because it's hard, but because the measured cost (~173 files, 6 AI agent classes, a live-scheduled workflow/forecast engine, and a blast radius that can't even be fully enumerated by tooling) is disproportionate to what it would buy over a *deepened* federation, and it matches no known real-world precedent for this category of system. Scope for this pass was the two UX fixes only (detailed in `00-PROGRESS.md`); the merge question is considered re-examined with evidence, not silently carried forward by default. If a future session is tempted to re-ask "should we just merge the tables now," read this entry first — it was asked and answered twice on record, not once by default and once by inertia.
