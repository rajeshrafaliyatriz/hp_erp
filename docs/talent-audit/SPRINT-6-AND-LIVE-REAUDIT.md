# Sprint 6 + live lifecycle re-audit

Two things in one pass: delete the dead v1 generation, then drive one full hire-to-review
lifecycle through **tenant 6 on the 128.199.17.97 host**, role by role, and fix what broke.

---

## Part 1 — Sprint 6: delete what is dead

`tsc` **7 → 4**, exactly as the plan predicted. Route count **1864 → 1815** (49 v1 routes gone).
Backend boots, all six live talent endpoints still answer 200, build clean, eslint 101, `components/ui`
untouched.

**Frontend deleted:** `offboarding-center-v1.tsx`, `hooks/use-offboarding.ts`,
`services/talent/offboarding-service.ts`, `types/talent-offboarding.ts` (a closed cluster that only
referenced itself), the three mock-data files (`mobility-data`, `offboarding-data`,
`performance-data`), the dead `talentService` block and its `/candidates`,`/onboarding-tasks`,
`/performance-reviews` calls (which pointed at routes Laravel does not have), and `mockWorkflows`.
**Kept**, as the plan flagged: the `recruitmentService`/`talentDashboardService` barrel, the
`Workflow` type, and the two comments were rewritten rather than left pointing at deleted code.

**Backend deleted:** the v1 route block (`routes/api.php`) **between** the two LIVE sub-blocks —
`/talent/dashboard*` above and `/talent/admin/workflows*` below both survive — the nine `Api\Talent`
v1 controllers, `test_offboarding.php`, `app/Http/Controllers/talent_management/**`, and the dangling
`use` lines. **Kept:** `AdminWorkflowController`, `TalentDashboardController` and the shared
`ResolvesTalentContext` trait, all still used.

The `OffboardingCaseFactory` comment that described the v1 controller in the present tense was
corrected to past — it no longer exists.

---

## Part 2 — one lifecycle, on the 128.199 host, every role

The brief's test: *a person can be hired, enrolled, added to the directory, given a role, rated, and
each of Admin, HR, Employee and Candidate completes their own part without anyone retyping a name.*
Run against tenant 6 on 128.199.17.97, which had **never** completed a lifecycle — 0 acceptances, 0
journeys, 0 exit cases before this.

| Act | Role | Result |
|---|---|---|
| Post a job | **HR** | Created posting 352, live on the careers page |
| See the careers page, apply | **Candidate** (no login) | Application 962/963 created with a résumé; retained in `talent_candidates` with consent |
| Move through the pipeline | **HR** | shortlist → interview → offer 136, emailed to the tenant-6 address |
| Answer the offer by magic link | **Candidate** (no login) | Accepted; **became employee 385 in the directory**, 21 → 22 |
| Onboard, confirm probation | **Admin / HR** | Journey created at `offer_accepted`; confirmed → `tbluser` probation window mirrored |
| Terminate a second hire | **HR** | Exit case opened in one transaction, 8 clearance tasks + 4 documents seeded |
| Launch a cycle and rate | **HR** | Cycle launched, review rated (self 3.8 / manager 4.1 / overall 4.0); out-of-range 99 → 422 |

The severed joint the whole remediation was about — candidate → employee — **works on live**: nobody
retyped a name at any step.

Every artifact created for this was removed afterwards; the host is back to its baseline (21 users,
22 applications, 0 journeys/cases/acceptances), plus the item-8 demo data, which stays.

---

## Four findings, found by running it, all fixed and re-verified on live

**F-67 — a cross-tenant write, executed (HIGH).** `talent_jobapplicationcontroller::update()`
resolved the caller's tenant and then loaded the row with an unfiltered `find($id)`. A tenant-1
admin PUT a tenant-6 application and got 200 — and because `sub_institute_id` was reassigned from the
body, the row was **renamed and moved into tenant 1**. Restored immediately from the app-DB copy.
Fixed: the lookup is tenant-scoped (foreign row → 404), `sub_institute_id` is no longer reassigned,
and `updated_by` comes from the token in both `update()` and `updateStatus()`. The identical attack
now returns 404 with the row untouched; the owner's own HR still updates it.

**F-68 — the whole v2 create surface was dead on MariaDB 10.1 (HIGH).** A model with `$guarded` and
no `$fillable` makes Eloquent introspect columns via `getColumnListing()`, whose query selects
`generation_expression` — a column that arrived in MariaDB 10.2. The 128.199 host runs 10.1.48, so
every `Model::create()` in onboarding/offboarding/performance threw a 500 there. **This is why that
host had zero journeys.** The app's own default host is 10.11 and never hit it. Fixed with one shared
trait, `SkipsGuardableColumnCheck`, on all 17 affected models: it overrides `isGuardableColumn()` to
return true, which is a no-op for every real column and skips only the query 10.1 cannot answer. The
whole live lifecycle then completed.

**F-69 — offer creation refused an allowlisted tenant, and a status update blanked a posting
(HIGH).** `TalentOfferController::store()` gated on the *global* `MailGate::allowed()` and returned
503 if it was off — so tenant 6, which is on the allowlist, could not receive an offer even though
the offer row had already saved. Separately, `talent_jobpostingcontroller::update()` wrote every
column unconditionally, so a status-only PUT emptied posting 216 (I hit this by accident and restored
it). Both fixed: the offer path uses `allowedForTenant()` and keeps the offer whether or not the mail
sends (mirroring `candidateLink()`), and the posting update writes only the fields the request
carries. Re-verified on live: the offer sends for tenant 6, and a status-only update preserves every
other field.

---

## Gates

```
backend   php -l clean on every changed file · app boots · routes 1815
frontend  tsc 4 (Sprint 6 target) · build exit 0, seen · eslint 101 · components/ui empty
live      lifecycle completed end to end on 128.199.17.97; host restored to baseline
          F-67 / F-68 / F-69 each re-verified against the live host after the fix
```

## What this leaves for a full re-audit verdict

The four-role loop now runs on the live host, and the four findings above are closed. The module's
standing RED items from the original audit that are **not** touched here still stand — see the
findings register. F-67 and F-68 in particular are the kind that would have kept a real deployment on
MariaDB 10.1 from ever completing a hire, and both were invisible until the lifecycle was actually
run against that host rather than the app's default one.